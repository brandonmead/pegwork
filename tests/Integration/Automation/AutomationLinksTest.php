<?php
/**
 * Integration tests: signed links, the link handler and link runs.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Links\Handler;
use PegworkAutomation\Links\Key;
use PegworkAutomation\Links\Links;
use PegworkAutomation\Overrides;
use PegworkAutomation\Triggers;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Fixture tests/fixtures/valid/links: on publish, ticket_links emails links `confirmed` (confirm page,
 * success_url, expired_url), `instant` (no confirm, success_message) and `elsewhere` (a step aimed at another
 * record).
 */
class AutomationLinksTest extends AutomationTestCase {

	/**
	 * Registers the links fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/links';
	}

	/**
	 * Fresh mock mailer per test.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * A ticket record.
	 *
	 * @param string $status Post status.
	 * @param string $title  Title.
	 * @return int Post ID.
	 */
	private function ticket( $status = 'draft', $title = 'Leaky tap' ) {
		$id = Records::create(
			'ticket',
			array(
				'title'       => $title,
				'owner_email' => 'owner@example.org',
			),
			$status
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * A token for a link of ticket_links on a record, minted on the frozen clock.
	 *
	 * @param string $link Link name.
	 * @param int    $id   Record ID.
	 */
	private function token( $link, $id ) {
		$url = Links::url( 'ticket_links', $link, $id, $this->clock->now() );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query['pegwork_link'];
	}

	/**
	 * A request to the handler as an anonymous visitor.
	 *
	 * @param string $method Method.
	 * @param string $token  Token.
	 * @param string $nonce  Nonce.
	 * @return array<string, mixed>
	 */
	private function click( $method, $token, $nonce = '' ) {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$response = Handler::respond( $method, $token, $nonce, '198.51.100.7' );
		wp_set_current_user( $user );
		return $response;
	}

	/**
	 * The nonce the confirm page carries for a token, as an anonymous visitor.
	 *
	 * @param string $token Token.
	 */
	private function nonce( $token ) {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$nonce = wp_create_nonce( 'pegwork_link_' . hash( 'sha256', $token ) );
		wp_set_current_user( $user );
		return $nonce;
	}

	/**
	 * Link runs, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function link_runs() {
		return array_values( array_filter( $this->runs( 'ticket_links' ), fn( $run ) => 'link' === $run['trigger_type'] ) );
	}

	/**
	 * Emails carry links minted when they render: one token per link per run (text and HTML agree), the
	 * HTML escapes them, the key is generated once with autoload off, and the log masks links.
	 */
	public function test_email_links_are_minted_once_and_never_logged() {
		$this->assertSame( 'none', Key::source() );
		$id = $this->ticket();
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->drain();

		$this->assertSame( 'option', Key::source() );
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Key::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'no', 'off' ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$mail   = $mailer->mock_sent[0];
		preg_match_all( '/pegwork_link=([A-Za-z0-9_.-]+)/', (string) $mailer->AltBody, $text ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$this->assertCount( 2, $text[1] );
		[ $confirmed, $instant ] = $text[1];
		$this->assertNotSame( $confirmed, $instant );
		$this->assertStringContainsString( 'pegwork_link=' . $instant, $mail['subject'], 'The same instant link in subject and text.' );
		$this->assertStringContainsString( 'href="http://example.org/?pegwork_link=' . $confirmed . '"', $mail['body'], 'The same confirmed link in text and HTML.' );

		$run   = $this->only_run( 'ticket_links' );
		$steps = $this->steps( $run['id'] );
		$this->assertSame( 'Links for Leaky tap [link]', $steps[0]['summary']['subject'] );
		$this->assertStringNotContainsString( $instant, (string) wp_json_encode( array( $run, $steps ) ) );

		$this->assertSame( 'ready', Engine::instance()->links()->check( $confirmed )['state'] );
		$this->assertSame( $id, Engine::instance()->links()->check( $confirmed )['record']['id'] );
	}

	/**
	 * Without confirm, GET acts at once and shows success_message; HEAD never acts; the token then is done.
	 */
	public function test_instant_link_acts_on_get_but_not_on_head() {
		$id    = $this->ticket();
		$token = $this->token( 'instant', $id );

		$head = $this->click( 'HEAD', $token );
		$this->assertSame( 200, $head['status'] );
		$this->assertStringContainsString( '<h1>Please confirm</h1>', $head['body'] );
		$this->assertSame( '', $this->value( $id, 'marker' ) );

		$page = $this->click( 'GET', $token );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( '<h1>Thank you</h1>', $page['body'] );
		$this->assertStringContainsString( 'Done for Leaky tap.', $page['body'] );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow">', $page['body'] );
		$this->assertSame( 'clicked', $this->value( $id, 'marker' ) );

		$run = $this->link_runs()[0];
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 0, $run['depth'] );
		$this->assertSame( 'instant', $run['context']['link'] );
		$this->assertSame( 'link', $run['context']['trigger']['origin']['source'] );
		$this->assertSame( 'link:' . hash( 'sha256', $token ), $run['idempotency_key'] );
		$this->assertSame( array( 'mark' ), array_column( $this->steps( $run['id'] ), 'step_id' ) );

		$this->assertStringContainsString( '<h1>Already done</h1>', $this->click( 'GET', $token )['body'] );
		$this->assertCount( 1, $this->link_runs() );
	}

	/**
	 * With confirm, a POST without a valid nonce shows the button again (403, notice) and changes nothing;
	 * with one it acts and redirects to the success_url under the site. The button text renders the record.
	 */
	public function test_confirm_link_needs_the_form_nonce_and_redirects() {
		$id    = $this->ticket();
		$token = $this->token( 'confirmed', $id );

		$page = $this->click( 'GET', $token );
		$this->assertStringContainsString( '>Mark Leaky tap verified</button>', $page['body'] );
		$this->assertStringContainsString( 'name="pegwork_link" value="' . $token . '"', $page['body'] );
		$this->assertStringNotContainsString( 'owner@example.org', $page['body'] );

		$stale = $this->click( 'POST', $token, 'nonsense' );
		$this->assertSame( 403, $stale['status'] );
		$this->assertStringContainsString( 'role="alert"', $stale['body'] );
		$this->assertStringContainsString( '<form method="post"', $stale['body'] );
		$this->assertSame( '', $this->value( $id, 'verified' ) );
		$this->assertSame( array(), $this->link_runs() );

		$done = $this->click( 'POST', $token, $this->nonce( $token ) );
		$this->assertSame( 303, $done['status'] );
		$this->assertSame( home_url( '/thanks/' ), $done['location'] );
		$this->assertSame( '2026-11-02', $this->value( $id, 'verified' ) );
	}

	/**
	 * An expired token redirects to expired_url; a token of a disabled automation, a trashed record, a
	 * rotated key or a removed link is refused; nothing changes and no run starts.
	 */
	public function test_refused_tokens_change_nothing() {
		$id      = $this->ticket();
		$expired = $this->token( 'confirmed', $id );
		$this->clock->advance( 'P8D' );
		$page = $this->click( 'POST', $expired, $this->nonce( $expired ) );
		$this->assertSame( 303, $page['status'] );
		$this->assertSame( home_url( '/expired/' ), $page['location'] );

		$fresh = $this->token( 'instant', $id );
		Overrides::set( 'ticket_links', 'off' );
		$page = $this->click( 'GET', $fresh );
		$this->assertSame( 404, $page['status'] );
		$this->assertStringContainsString( '<h1>This link is no longer available</h1>', $page['body'] );
		Overrides::set( 'ticket_links', 'on' );

		$other = $this->ticket( 'draft', 'Broken window' );
		$token = $this->token( 'instant', $other );
		wp_trash_post( $other );
		$this->assertSame( 404, $this->click( 'GET', $token )['status'] );

		$this->assertSame( 'ready', Engine::instance()->links()->check( $fresh )['state'] );
		$this->assertTrue( Key::rotate() );
		$page = $this->click( 'GET', $fresh );
		$this->assertSame( 403, $page['status'], 'A rotated key invalidates every earlier link.' );

		$this->assertSame( '', $this->value( $id, 'marker' ) );
		$this->assertSame( '', $this->value( $id, 'verified' ) );
		$this->assertSame( array(), $this->link_runs() );
	}

	/**
	 * A link run updates only its own record: a step aimed elsewhere fails (`link_scope`, no retry), the
	 * visitor sees the error page, and the same token then shows it again without running.
	 */
	public function test_link_runs_only_touch_their_record() {
		$id     = $this->ticket();
		$target = (int) ( $id . '0' );
		$token  = $this->token( 'elsewhere', $id );

		$page = $this->click( 'GET', $token );
		$this->assertSame( 500, $page['status'] );
		$this->assertStringContainsString( '<h1>Something went wrong</h1>', $page['body'] );

		$run = $this->link_runs()[0];
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'link_scope: ', $run['error'] );
		$this->assertSame( array( $target ), array_column( array_column( $this->steps( $run['id'] ), 'summary' ), 'record_id' ) );

		$again = $this->click( 'GET', $token );
		$this->assertSame( 200, $again['status'] );
		$this->assertStringContainsString( '<h1>Something went wrong</h1>', $again['body'] );
		$this->assertCount( 1, $this->link_runs() );
	}

	/**
	 * A queued link run whose link left the definition fails `definition_changed` on wake-up.
	 */
	public function test_link_run_fails_when_its_link_is_gone() {
		$id  = $this->ticket();
		$run = Engine::instance()->runs()->insert(
			array(
				'automation'      => 'ticket_links',
				'trigger_type'    => 'link',
				'record_id'       => $id,
				'status'          => 'queued',
				'depth'           => 0,
				'definition_hash' => Triggers::hash( pegwork_registry()->automation( 'ticket_links' ) ),
				'context'         => array( 'link' => 'gone' ),
			)
		);
		$this->assertSame( 'failed', Engine::instance()->executor()->execute( $run ) );
		$this->assertSame( 'definition_changed: link "gone" is no longer in the automation.', $this->run_row( $run )['error'] );
	}

	/**
	 * More requests than the limit from one address get 429 with Retry-After, before any token check.
	 */
	public function test_rate_limit() {
		add_filter( 'pegwork_automation_link_rate_limit', fn() => 2 );
		$this->assertSame( 403, $this->click( 'GET', 'x' )['status'] );
		$this->assertSame( 403, $this->click( 'GET', 'x' )['status'] );
		$page = $this->click( 'GET', 'x' );
		$this->assertSame( 429, $page['status'] );
		$this->assertSame( '600', $page['headers']['Retry-After'] );
		$this->assertSame( 403, Handler::respond( 'GET', 'x', '', '203.0.113.9' )['status'], 'Other addresses are not affected.' );
	}
}
