<?php
/**
 * Integration tests: QA fixes in the forms module (failed confirmation mail, repeated confirm submission,
 * oversize POST, plain-text output, entries list queries).
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.NonceVerification -- The tests drive request globals and core's admin screen.

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\EntriesTestCase;
use PegworkForms\Cleanup;
use PegworkForms\Entries;
use PegworkForms\EntriesAdmin;
use PegworkForms\Export;
use PegworkForms\Notifications;
use PegworkForms\Renderer;
use PegworkForms\Submission;

require_once dirname( __DIR__, 2 ) . '/Support/EntriesTestCase.php';

/**
 * One test per QA finding (F1 to F6 of the forms section).
 */
class FormsQaFixesTest extends EntriesTestCase {

	/**
	 * Clears request globals.
	 */
	public function tear_down() {
		unset( $_SERVER['CONTENT_LENGTH'], $_GET['pegwork_sent'], $_GET['pegwork_again'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Valid fe_project values (a confirm form).
	 *
	 * @param array $overrides Values to change.
	 * @return array<string, mixed>
	 */
	private function project_values( $overrides = array() ) {
		return array_merge(
			array(
				'title'         => 'Seed library',
				'website'       => 'https://seeds.example.org',
				'fe_kind'       => 'tool',
				'contact_email' => 'owner@example.org',
				'consent'       => '1',
			),
			$overrides
		);
	}

	/**
	 * Makes every mail to an address fail.
	 *
	 * @param string $address Address.
	 */
	private function fail_mail_to( $address ) {
		add_filter(
			'pre_wp_mail',
			static fn( $short, $atts ) => in_array( $address, array_map( 'strtolower', (array) $atts['to'] ), true ) ? false : $short,
			10,
			2
		);
	}

	/**
	 * F1: a confirmation email that cannot be sent is not silent. The admin is told, the visitor is not
	 * promised an email, and the entry is kept out of the unconfirmed retention.
	 */
	public function test_f1_failed_confirmation_mail_alerts_the_admin_and_the_visitor() {
		$this->fail_mail_to( 'owner@example.org' );

		$result = $this->submit( $this->project_values(), 'fe_project' );

		$this->assertSame( 'confirm_mail_failed', $result['outcome'] );
		$this->assertSame( 'Seed library', $result['values']['title'], 'The form re-renders with what was typed.' );
		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( 'failed', $entry['meta']['confirm_mail'] );
		$this->assertSame( 'sent', $entry['meta']['confirm_alert'] );

		$mail = $this->sent_mail();
		$this->assertCount( 1, $mail, 'Only the admin alert went out.' );
		$this->assertSame( get_option( 'admin_email' ), $mail[0]['to'][0][0] );
		$this->assertStringContainsString( 'Confirmation email failed: Submit a project', $mail[0]['subject'] );
		$this->assertStringContainsString( 'pegwork-entries&entry=' . $result['entry_id'], $mail[0]['body'] );

		Submission::reset();
		$this->set_state( 'fe_project', $result );
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fe_project"} /-->' );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'could not send the confirmation email', $html );
		$this->assertStringNotContainsString( 'Check your email', $html );

		$this->assertSame( 0, Cleanup::run( self::NOW + 400 * DAY_IN_SECONDS ), 'Not removed as an abandoned unconfirmed entry.' );
		$this->assertNotNull( Entries::get( $result['entry_id'] ) );
	}

	/**
	 * F1: after a failed mail the visitor may send the same values again; it is not taken for a repeat.
	 */
	public function test_f1_send_again_after_a_failed_mail_is_not_a_duplicate() {
		$this->fail_mail_to( 'owner@example.org' );
		$first = $this->submit( $this->project_values(), 'fe_project' );
		$this->assertSame( 'confirm_mail_failed', $first['outcome'] );

		reset_phpmailer_instance();
		remove_all_filters( 'pre_wp_mail' );
		$second = $this->submit( $this->project_values(), 'fe_project' );

		$this->assertSame( 'sent', $second['outcome'] );
		$this->assertSame( 'unconfirmed', Entries::get( $second['entry_id'] )['status'] );
		$this->assertSame( 'sent', Entries::get( $second['entry_id'] )['meta']['confirm_mail'] );
	}

	/**
	 * F2: the same confirm form values again within the window says the email was already sent, not the
	 * plain "check your email" that suggests a new one went out.
	 */
	public function test_f2_repeat_of_a_confirm_submission_says_the_email_was_sent() {
		$first = $this->submit( $this->project_values(), 'fe_project' );
		$this->assertSame( 'sent', $first['outcome'] );
		$mails = count( $this->sent_mail() );

		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'contact',
				'post_content' => '<!-- wp:pegwork/form {"form":"fe_project"} /-->',
			)
		);
		$this->go_to( get_permalink( $page ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['pegwork_form']     = $this->control( 'fe_project', 30, array( 'token' => \PegworkForms\Token::create( 'fe_project', time() - 30 ) ) );
		$_POST['pegwork']          = $this->project_values();

		$location = $this->redirect_of( array( Submission::class, 'handle' ) );
		$this->assertStringContainsString( 'pegwork_sent=fe_project', $location );
		$this->assertStringContainsString( 'pegwork_again=1', $location );
		$this->assertCount( $mails, $this->sent_mail(), 'No second email.' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET['pegwork_sent']      = 'fe_project';
		$_GET['pegwork_again']     = '1';
		Renderer::reset();
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fe_project"} /-->' );
		$this->assertStringContainsString( 'confirmation email was sent a moment ago', $html );
		$this->assertStringContainsString( 'role="status"', $html );
	}

	/**
	 * F3: a POST over post_max_size reaches PHP as an empty body; the form with a file field says so.
	 */
	public function test_f3_oversize_post_shows_a_too_large_notice() {
		if ( 0 === Submission::max_post_bytes() ) {
			$this->markTestSkipped( 'post_max_size is unlimited.' );
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = (string) ( Submission::max_post_bytes() + 1024 );
		$_POST                     = array();
		$_FILES                    = array();

		Submission::handle();

		$this->assertTrue( Submission::too_large() );
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_upload"} /-->' );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'too large to send together, so nothing was sent. The limit is ' . size_format( Submission::max_post_bytes() ), $html );
		$this->assertStringContainsString( '<form class="pegwork-form__form"', $html, 'The visitor stays on the form.' );

		Renderer::reset();
		$this->assertStringNotContainsString( 'too large to send together', do_blocks( '<!-- wp:pegwork/form {"form":"fr_contact"} /-->' ), 'A form without a file field has nothing to be too large.' );
	}

	/**
	 * F3: a small POST with no data (not over the limit) is not reported as too large.
	 */
	public function test_f3_small_empty_post_is_not_too_large() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = '12';
		$_POST                     = array();
		Submission::handle();
		$this->assertFalse( Submission::too_large() );
	}

	/**
	 * F4: text with `<` and `&` reads as typed in mail bodies, subjects and the CSV, and is still escaped in HTML.
	 */
	public function test_f4_plain_text_outputs_decode_entities() {
		$result = $this->submit(
			array(
				'name'    => 'Kids <5 & Co',
				'email'   => 'ada@example.org',
				'message' => 'a < b & "c"',
				'consent' => '1',
			)
		);
		$this->assertSame( 'sent', $result['outcome'] );

		$admin = $this->sent_mail()[0];
		$this->assertSame( 'Contact: Kids <5 & Co', $admin['subject'] );
		$this->assertStringContainsString( 'Name: Kids <5 & Co', $admin['body'] );
		$this->assertStringContainsString( 'Message:', $admin['body'] );
		$this->assertStringContainsString( 'a < b & "c"', $admin['body'] );
		$this->assertStringNotContainsString( '&lt;', $admin['body'] );
		$this->assertStringNotContainsString( '&amp;', $admin['body'] );

		$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test stream.
		Export::write( $out, 'fr_contact', 'all' );
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test stream.
		$this->assertStringContainsString( 'Kids <5 & Co', $csv );
		$this->assertStringNotContainsString( '&lt;', $csv );

		set_current_screen( 'pegwork_page_' . Notifications::ENTRIES_PAGE );
		$_GET     = array( 'entry' => (string) $result['entry_id'] );
		$_REQUEST = $_GET;
		ob_start();
		EntriesAdmin::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Kids &lt;5 &amp; Co', $html, 'Escaped once for HTML.' );
		$this->assertStringNotContainsString( '&amp;lt;', $html );
	}

	/**
	 * F6: the Entries list reads the linked posts in one query, however many rows it shows.
	 */
	public function test_f6_entries_list_primes_post_caches() {
		global $wpdb;
		for ( $i = 1; $i <= 5; $i++ ) {
			$post_id = self::factory()->post->create( array( 'post_title' => 'Record ' . $i ) );
			$id      = $this->entry( array( 'fingerprint' => str_repeat( (string) $i, 64 ) ) );
			\PegworkForms\EntryState::set( $id, array( 'post_id' => $post_id ) );
			clean_post_cache( $post_id );
		}

		$selects = 0;
		add_filter(
			'query',
			static function ( $sql ) use ( &$selects, $wpdb ) {
				if ( preg_match( '/^\s*SELECT .* FROM `?' . preg_quote( $wpdb->posts, '/' ) . '`? WHERE/s', $sql ) ) {
					++$selects;
				}
				return $sql;
			}
		);
		set_current_screen( 'pegwork_page_' . Notifications::ENTRIES_PAGE );
		$_GET     = array();
		$_REQUEST = array();
		ob_start();
		EntriesAdmin::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Record 5', $html );
		$this->assertLessThanOrEqual( 1, $selects, 'One query for all five posts.' );
	}

	/**
	 * Puts a process() result in the renderer's state, as handle() does for a re-render.
	 *
	 * @param string $key    Form key.
	 * @param array  $result process() result.
	 */
	private function set_state( $key, $result ) {
		$state = new \ReflectionProperty( Submission::class, 'state' );
		$state->setValue( null, array( $key => $result ) );
	}
}
