<?php
/**
 * Turnstile: widget, siteverify outcomes, spam storage.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Entries;
use PegworkForms\Renderer;
use PegworkForms\Submission;
use PegworkForms\Turnstile;
use PegworkForms\Uploads;

/**
 * Runs with Cloudflare's published always-pass test keys (FormsTestCase). Siteverify is never called
 * for real: `pre_http_request` answers with Cloudflare's documented responses.
 */
class FormsTurnstileTest extends FormsTestCase {

	/**
	 * Token the always-pass test site key's widget produces.
	 */
	private const TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

	/**
	 * What the mocked siteverify answers: `pass`, `fail` (Cloudflare's answer for the always-fail
	 * secret `2x0000000000000000000000000000000AA`), `network`, `http500`, `garbage`, `bad_secret`.
	 *
	 * @var string
	 */
	private $siteverify = 'pass';

	/**
	 * Siteverify requests made: list of [ url, args ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private $requests = array();

	/**
	 * Mocks siteverify.
	 */
	public function set_up() {
		parent::set_up();
		$this->siteverify = 'pass';
		$this->requests   = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( Turnstile::VERIFY_URL !== $url ) {
					return $pre;
				}
				$this->requests[] = array( $url, $args );
				$json             = static fn( $body, $code = 200 ) => array(
					'headers'  => array(),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
				switch ( $this->siteverify ) {
					case 'network':
						return new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
					case 'http500':
						return $json( 'Server error', 500 );
					case 'garbage':
						return $json( '<html>Not JSON</html>' );
					case 'bad_secret':
						return $json(
							array(
								'success'     => false,
								'error-codes' => array( 'invalid-input-secret' ),
							)
						);
					case 'fail':
						return $json(
							array(
								'success'     => false,
								'error-codes' => array( 'invalid-input-response' ),
								'messages'    => array(),
								'metadata'    => array( 'result_with_testing_key' => true ),
							)
						);
					default:
						return $json(
							array(
								'success'      => true,
								'error-codes'  => array(),
								'challenge_ts' => '2026-10-18T12:00:00.000Z',
								'hostname'     => 'example.org',
								'metadata'     => array( 'result_with_testing_key' => true ),
							)
						);
				}
			},
			10,
			3
		);
	}

	/**
	 * Valid values for fr_turnstile.
	 */
	private function values() {
		return array(
			'note'  => 'Hello',
			'email' => 'ada@example.org',
		);
	}

	/**
	 * The widget renders at the end of the last step, posts its token as a control field, and loads
	 * Cloudflare's script; a noscript notice says the form needs JavaScript.
	 */
	public function test_widget_on_last_step_with_noscript_notice() {
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' );

		$this->assertStringContainsString( '<div class="cf-turnstile" data-sitekey="' . self::TURNSTILE_SITE_KEY . '" data-response-field-name="pegwork_form[captcha]"', $html );
		$this->assertMatchesRegularExpression( '#<legend class="pegwork-form__step-title">Last</legend>.*<div class="pegwork-form__captcha".*</fieldset><div class="pegwork-form__actions">#s', $html, 'Widget at the end of the last step.' );
		$this->assertSame( 1, substr_count( $html, 'cf-turnstile' ) );
		$this->assertStringContainsString( '<noscript><p class="pegwork-form__notice pegwork-form__notice--problem">This form needs JavaScript for its spam check.', $html );
		$this->assertLessThan( strpos( $html, '<form' ), strpos( $html, '<noscript>' ), 'The notice comes before the form, so nobody fills it in first.' );
		$this->assertTrue( wp_script_is( Turnstile::HANDLE, 'enqueued' ) );
		$this->assertSame( Turnstile::SCRIPT_URL, wp_scripts()->registered[ Turnstile::HANDLE ]->src );
		$this->assertNull( wp_scripts()->registered[ Turnstile::HANDLE ]->ver, 'No version query on Cloudflare\'s URL.' );
		$this->assertStringNotContainsString( self::TURNSTILE_SECRET_KEY, $html );
		$this->assertMatchesRegularExpression( '#<button type="submit" class="pegwork-form__submit wp-element-button" disabled>[^<]*</button><script[^>]*>\s*document\.currentScript\.previousElementSibling\.disabled=false;\s*</script>#', $html, 'Disabled until a script runs: without JavaScript it cannot be sent.' );

		$plain = do_blocks( '<!-- wp:pegwork/form {"form":"fr_contact"} /-->' );
		$this->assertStringNotContainsString( 'cf-turnstile', $plain );
		$this->assertStringNotContainsString( ' disabled>', $plain );
		$this->assertStringNotContainsString( '<noscript>', $plain );
	}

	/**
	 * Test-key pass: siteverify (wp_safe_remote_post, secret and token) says yes, the entry is received.
	 */
	public function test_pass_stores_received_entry() {
		$result = $this->submit( $this->values(), 'fr_turnstile', array( 'captcha' => self::TOKEN ) );

		$this->assertSame( 'sent', $result['outcome'] );
		$this->assertSame( 'received', Entries::get( $result['entry_id'] )['status'] );
		$this->assertCount( 1, $this->requests );
		$args = $this->requests[0][1];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertTrue( $args['reject_unsafe_urls'], 'wp_safe_remote_post().' );
		$this->assertSame(
			array(
				'secret'   => self::TURNSTILE_SECRET_KEY,
				'response' => self::TOKEN,
			),
			$args['body']
		);
		$this->assertCount( 1, $this->sent_mail() );
		$this->assertSame( 'form.submitted', $this->events[ count( $this->events ) - 1 ][0] );
		$this->assertArrayNotHasKey( 'captcha', Entries::get( $result['entry_id'] )['values'], 'The token is not stored.' );
	}

	/**
	 * Test-key fail: stored as spam (captcha_failed), the form re-renders with a message, no mail, no events.
	 */
	public function test_fail_stores_spam_and_rerenders() {
		$this->siteverify = 'fail';
		$result           = $this->submit( $this->values(), 'fr_turnstile', array( 'captcha' => self::TOKEN ) );

		$this->assertSame( 'captcha_failed', $result['outcome'] );
		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'spam', $entry['status'] );
		$this->assertSame( array( 'captcha_failed' ), $entry['meta']['spam'] );
		$this->assertSame( array(), $this->sent_mail() );
		$this->assertSame( array(), $this->form_events() );
		$this->assertSame( 'Hello', $result['values']['note'], 'Values are kept for the re-render.' );
	}

	/**
	 * No token field at all (the widget never ran: no JavaScript): a failure without calling Cloudflare,
	 * told honestly that the form needs JavaScript. An empty token (the widget ran but gave none) is a
	 * failed check as before.
	 */
	public function test_missing_token_fails_without_siteverify() {
		$result = $this->submit( $this->values(), 'fr_turnstile' );

		$this->assertSame( 'captcha_missing', $result['outcome'] );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( 1, $this->count_entries( 'spam' ) );
		$this->assertSame( array( 'captcha_missing' ), Entries::get( $result['entry_id'] )['meta']['spam'] );

		$empty = $this->submit( array( 'note' => 'Empty token' ) + $this->values(), 'fr_turnstile', array( 'captcha' => '' ) );
		$this->assertSame( 'captcha_failed', $empty['outcome'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Network error, an HTTP error, an unreadable answer, or a wrong secret: stored as spam with
	 * captcha_unverified (an admin can rescue it), the held message, no mail, no events.
	 */
	public function test_unverified_answers_hold_the_entry_as_spam() {
		foreach ( array( 'network', 'http500', 'garbage', 'bad_secret' ) as $index => $answer ) {
			$this->siteverify = $answer;
			$result           = $this->submit( array( 'note' => 'Held ' . $index ) + $this->values(), 'fr_turnstile', array( 'captcha' => self::TOKEN ) );

			$this->assertSame( 'held', $result['outcome'], $answer );
			$entry = Entries::get( $result['entry_id'] );
			$this->assertSame( 'spam', $entry['status'], $answer );
			$this->assertSame( array( 'captcha_unverified' ), $entry['meta']['spam'], $answer );
			$this->assertSame( 'Held ' . $index, $entry['values']['note'], $answer );
		}
		$this->assertSame( array(), $this->sent_mail() );
		$this->assertSame( array(), $this->form_events() );
	}

	/**
	 * A held entry keeps its uploaded file (so the rescued entry is complete); a failed one does not.
	 */
	public function test_held_entry_keeps_files_failed_does_not() {
		$this->siteverify = 'network';
		$result           = Submission::process( pegwork_registry()->form( 'fr_turnstile' ), $this->values(), $this->control( 'fr_turnstile', 30, array( 'captcha' => self::TOKEN ) ), self::NOW, 0, array( 'attachment' => $this->upload( 'notes.txt', "Plain notes\n" ) ) );
		$this->assertSame( 'held', $result['outcome'] );
		$value = Entries::get( $result['entry_id'] )['values']['attachment'];
		$this->assertNotNull( Uploads::absolute( $value ), 'File stored for the held entry.' );

		$this->siteverify = 'fail';
		$result           = Submission::process( pegwork_registry()->form( 'fr_turnstile' ), array( 'note' => 'Other' ) + $this->values(), $this->control( 'fr_turnstile', 30, array( 'captcha' => self::TOKEN ) ), self::NOW, 0, array( 'attachment' => $this->upload( 'notes.txt', "Plain notes\n" ) ) );
		$this->assertSame( 'captcha_failed', $result['outcome'] );
		$value = Entries::get( $result['entry_id'] )['values']['attachment'];
		$this->assertSame( 'notes.txt', $value['name'] );
		$this->assertFalse( Uploads::is_stored( $value ), 'No file kept for a failed check.' );
		$this->assertSame( array( 'attachment' => 'notes.txt' ), $result['files'], 'The re-render asks to choose the file again.' );
	}

	/**
	 * Honeypot spam is stored without calling Cloudflare; validation errors re-render without
	 * spending the token.
	 */
	public function test_cheaper_checks_run_first() {
		$control = array(
			'captcha' => self::TOKEN,
			'url'     => 'http://spam.example',
		);
		$result  = $this->submit( $this->values(), 'fr_turnstile', $control );
		$this->assertSame( 'spam', $result['outcome'] );
		$this->assertSame( array( 'honeypot' ), Entries::get( $result['entry_id'] )['meta']['spam'] );

		$result = $this->submit( array( 'note' => '' ) + $this->values(), 'fr_turnstile', array( 'captcha' => self::TOKEN ) );
		$this->assertSame( 'invalid', $result['outcome'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * The re-render after a failed check and the held state after the redirect show their messages.
	 */
	public function test_failed_and_held_messages() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'turnstile',
				'post_content' => '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->',
			)
		);
		$this->go_to( get_permalink( $page ) );
		$this->siteverify          = 'fail';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/turnstile/';
		$_POST['pegwork_form']     = array(
			'key'     => 'fr_turnstile',
			'token'   => \PegworkForms\Token::create( 'fr_turnstile', time() - 30 ),
			'url'     => '',
			'captcha' => self::TOKEN,
		);
		$_POST['pegwork']          = $this->values();
		Submission::handle();
		$this->assertSame( 'captcha_failed', Submission::state( 'fr_turnstile' )['outcome'] );
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' );
		$this->assertStringContainsString( 'role="alert" tabindex="-1" autofocus><p>The spam check did not pass.', $html );
		$this->assertStringContainsString( 'value="Hello"', $html );

		// A no-JavaScript POST (no token field) gets the honest message.
		\PegworkForms\Renderer::reset();
		unset( $_POST['pegwork_form']['captcha'] );
		$_POST['pegwork']['note'] = 'No script';
		Submission::handle();
		$this->assertSame( 'captcha_missing', Submission::state( 'fr_turnstile' )['outcome'] );
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' );
		$this->assertStringContainsString( 'role="alert" tabindex="-1" autofocus><p>This form needs JavaScript for its spam check.', $html );
		$_POST['pegwork_form']['captcha'] = self::TOKEN;

		$redirect = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect ) {
				$redirect = $location;
				throw new \RuntimeException( 'redirected' );
			}
		);
		$this->siteverify               = 'network';
		$_POST['pegwork']['note']       = 'Second try';
		$_POST['pegwork_form']['token'] = \PegworkForms\Token::create( 'fr_turnstile', time() - 30 );
		Submission::reset();
		try {
			Submission::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( 'http://example.org/turnstile/?pegwork_sent=fr_turnstile&pegwork_held=1', $redirect );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_POST['pegwork_form'], $_POST['pegwork'] );
		$_GET['pegwork_sent'] = 'fr_turnstile';
		$_GET['pegwork_held'] = '1';
		Submission::reset();
		Renderer::reset();
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' );
		$this->assertStringContainsString( 'role="status" tabindex="-1" autofocus><p>Your submission was saved, but our spam check could not be completed.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Keys never come from the registry: a definition cannot carry one, and exports hold only the flag.
	 */
	public function test_keys_only_from_constants() {
		$this->assertSame(
			array(
				'site'   => self::TURNSTILE_SITE_KEY,
				'secret' => self::TURNSTILE_SECRET_KEY,
			),
			Turnstile::keys()
		);
		$form = pegwork_registry()->form( 'fr_turnstile' );
		$this->assertSame( 'turnstile', $form['captcha'] );
		$this->assertStringNotContainsString( self::TURNSTILE_SECRET_KEY, (string) wp_json_encode( pegwork_registry()->forms() ) );
	}

	/**
	 * Form events emitted in this test.
	 *
	 * @return array<int, array>
	 */
	private function form_events() {
		return array_values( array_filter( $this->events, fn( $event ) => str_starts_with( $event[0], 'form.' ) ) );
	}
}
