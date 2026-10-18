<?php
/**
 * Integration tests: send_email, privacy of merge tags, retries.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Context;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Schema;
use PegworkAutomation\Steps\SendEmail;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests read the module's tables to prove what is stored.

/**
 * Email through the mock PHPMailer: recipients, text and HTML bodies, escaping, private values kept out of
 * the log, and retries that back off on the frozen clock and end in a logged failure.
 */
class AutomationEmailTest extends AutomationTestCase {

	/**
	 * Fresh mock mailer per test.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * Emails sent so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * A job created through the Records API.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string               $status Status.
	 * @return int Post ID.
	 */
	private function record( array $values, $status = 'pending' ) {
		$id = Records::create( 'job', $values + array( 'title' => 'A job' ), $status );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Everything the module stored about a run: the run row and its step rows, as JSON.
	 *
	 * @param int $run_id Run ID.
	 */
	private function stored( $run_id ) {
		global $wpdb;
		$runs_table  = Schema::runs();
		$steps_table = Schema::steps();
		$run         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
		$steps       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$steps_table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
		return wp_json_encode( array( $run, $steps ) );
	}

	/**
	 * Acceptance: the approval email goes to the contact address and carries the permalink, as plain text.
	 */
	public function test_approval_email_goes_to_the_contact_with_the_permalink() {
		$id = $this->record( array( 'contact_email' => 'owner@example.org' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->drain();

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'owner@example.org', $mails[0]['to'][0][0] );
		$this->assertSame( 'A job is live on Test Blog', $mails[0]['subject'] );
		$this->assertStringContainsString( get_permalink( $id ), $mails[0]['body'] );
		$this->assertStringContainsString( 'text/plain', $mails[0]['header'] );
		$this->assertSame( '', tests_retrieve_phpmailer_instance()->AltBody );

		$run   = $this->only_run( 'job_approved' );
		$steps = $this->steps( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame(
			array(
				'to'      => array( 'o***@example.org' ),
				'subject' => 'A job is live on Test Blog',
				'html'    => false,
			),
			$steps[1]['summary']
		);
		$this->assertStringNotContainsString( 'owner@example.org', $this->stored( $run['id'] ), 'The log masks the private address.' );
	}

	/**
	 * Without a contact address the default literal is used.
	 */
	public function test_default_recipient() {
		$id = $this->record( array() );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->drain();
		$this->assertSame( 'ops@example.org', $this->mails()[0]['to'][0][0] );
	}

	/**
	 * Acceptance: HTML bodies escape every value (esc_url for URLs), text bodies keep them as typed, taxonomy
	 * keys render term names, and private values reach only the email, never the log.
	 */
	public function test_html_email_escapes_values_and_keeps_private_values_out_of_the_log() {
		global $wpdb;
		$id = $this->record(
			array(
				'note'          => 'secret-note & "quoted"',
				'contact_email' => 'owner@example.org',
				'website'       => 'https://example.org/?a=1&b=2',
				'job_stage'     => array( 'open' ),
			),
			'publish'
		);
		// Stored values are untrusted: write markup past every sanitizer, as an import or another plugin could.
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Tom <script>alert(1)</script> & "Jerry"' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertTrue( Records::update( $id, array( 'urgent' => true ) ) );
		$this->drain();

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$mailer = tests_retrieve_phpmailer_instance();
		$html   = $mailer->Body; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$text   = $mailer->AltBody; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.

		$this->assertSame( 'owner@example.org', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'multipart/alternative', $mails[0]['header'] );
		$this->assertStringContainsString( 'Reply-To: desk@example.org', $mails[0]['header'] );
		$this->assertSame( 'Urgent: Tom <script>alert(1)</script> & "Jerry" (secret-note & "quoted")', $mails[0]['subject'] );

		$this->assertStringContainsString( '<p>Note: secret-note &amp; &quot;quoted&quot;</p>', $html );
		$this->assertStringContainsString( '<p>Stage: Open</p>', $html, 'Taxonomies render term names.' );
		$this->assertStringContainsString( '<a href="https://example.org/?a=1&#038;b=2">Tom &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Jerry&quot;</a>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( get_permalink( $id ) ) . '">View</a>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( "Note: secret-note & \"quoted\"\nStage: Open\nSite: https://example.org/?a=1&b=2", $text, 'The plain-text part keeps values as typed.' );

		$run   = $this->only_run( 'job_mail' );
		$steps = $this->steps( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'Urgent: Tom <script>alert(1)</script> & "Jerry" ([private])', $steps[0]['summary']['subject'] );
		$this->assertSame( array( 'o***@example.org' ), $steps[0]['summary']['to'] );
		$this->assertTrue( $steps[0]['summary']['html'] );

		$stored = $this->stored( $run['id'] );
		$this->assertStringNotContainsString( 'secret-note', $stored, 'No private value in the run or its steps.' );
		$this->assertStringNotContainsString( 'owner@example.org', $stored );
	}

	/**
	 * A recipient problem fails the step at once: retrying cannot help, and nothing is sent.
	 */
	public function test_missing_recipient_fails_without_retry() {
		$id = $this->record( array(), 'publish' );
		Records::update( $id, array( 'urgent' => true ) );
		$this->drain();

		$run = $this->only_run( 'job_mail' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'no_recipient: The recipient list rendered empty.', $run['error'] );
		$this->assertCount( 1, $this->steps( $run['id'] ) );
		$this->assertCount( 0, $this->mails() );
	}

	/**
	 * Acceptance: a failing mailer is retried twice, 15 minutes apart (the send_email default), then the run
	 * fails with the mailer's message, recipients masked. `retry` resumes it at the failed step only.
	 */
	public function test_failed_mail_retries_with_backoff_then_fails() {
		$fail = function () {
			do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed for owner@example.org.' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulates core's mail failure action.
			return false;
		};
		add_filter( 'pre_wp_mail', $fail );

		$id = $this->record( array( 'contact_email' => 'owner@example.org' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->drain();

		$run = $this->only_run( 'job_approved' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'notify', $run['current_step'] );
		$this->assertSame( '2026-11-02 09:15:00', $run['resume_at'] );
		$this->assertSame( 'retrying: mail_failed: SMTP connect() failed for o***@example.org.', $run['error'] );
		$this->assertSame( strtotime( '2026-11-02 09:15:00 UTC' ), $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) );

		$this->clock->advance( 'PT14M' );
		$this->drain();
		$this->assertNull( Engine::instance()->executor()->execute( $run['id'] ), 'Not before the backoff has passed.' );
		$this->assertSame( 'waiting', $this->run_row( $run['id'] )['status'] );

		$this->clock->advance( 'PT1M' );
		$this->drain();
		$this->assertSame( '2026-11-02 09:30:00', $this->run_row( $run['id'] )['resume_at'] );
		$this->clock->advance( 'PT15M' );
		$this->drain();

		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'mail_failed: SMTP connect() failed for o***@example.org. (after 3 attempts)', $run['error'] );
		$steps = $this->steps( $run['id'] );
		$this->assertSame( array( 'verify', 'notify', 'notify', 'notify' ), array_column( $steps, 'step_id' ) );
		$this->assertSame( array( 'ok', 'retrying', 'retrying', 'failed' ), array_column( $steps, 'status' ) );
		$this->assertSame( array( 1, 1, 2, 3 ), array_map( 'intval', array_column( $steps, 'attempt' ) ) );
		$this->assertSame( array( '2026-11-02 09:00:00', '2026-11-02 09:00:00', '2026-11-02 09:15:00', '2026-11-02 09:30:00' ), array_column( $steps, 'started_at' ) );
		$this->assertStringNotContainsString( 'owner@example.org', $this->stored( $run['id'] ) );

		// Mail works again: retry resumes at notify; verify does not run again.
		remove_filter( 'pre_wp_mail', $fail );
		$this->clock->advance( 'P1D' );
		$manual = new Manual();
		$this->assertTrue( $manual->retry( $run['id'] ) );
		$this->assertInstanceOf( WP_Error::class, $manual->retry( $run['id'] ), 'Only failed runs can be retried.' );
		$this->drain();

		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertNull( $run['error'] );
		$this->assertSame( array( 'ok', 'retrying', 'retrying', 'failed', 'ok' ), array_column( $this->steps( $run['id'] ), 'status' ) );
		$this->assertSame( 4, (int) $this->steps( $run['id'] )[4]['attempt'] );
		$this->assertCount( 1, $this->mails() );
	}

	/**
	 * A step's own retry policy: backoff list (the last entry repeats) and max; a schedule without
	 * for_each runs with no record.
	 */
	public function test_custom_retry_policy_on_a_schedule_without_record() {
		add_filter( 'pre_wp_mail', '__return_false' );

		$fired = Engine::instance()->schedules()->fire( 'job_digest' );
		$this->assertCount( 1, $fired['runs'] );
		$this->drain();

		$run_id = $fired['runs'][0];
		$this->assertNull( $this->run_row( $run_id )['record_id'] );
		$this->assertSame( 'schedule', $this->run_row( $run_id )['trigger_type'] );
		$resumes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$row       = $this->run_row( $run_id );
			$resumes[] = $row['resume_at'];
			$this->clock->set( $row['resume_at'] . ' UTC' );
			$this->drain();
		}
		$this->assertSame( array( '2026-11-02 09:05:00', '2026-11-02 10:05:00', '2026-11-02 11:05:00' ), $resumes );

		$run = $this->run_row( $run_id );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'mail_failed: wp_mail() returned false. (after 4 attempts)', $run['error'] );
		$this->assertSame( array( 'retrying', 'retrying', 'retrying', 'failed' ), array_column( $this->steps( $run_id ), 'status' ) );
		$this->assertSame( array( 'o***@example.org', 'd***@example.org' ), $this->steps( $run_id )[0]['summary']['to'] );
	}

	/**
	 * Recipients: fields, literals and options page fields; option tags come from the template only.
	 */
	public function test_recipients() {
		$id      = $this->record(
			array(
				'contact_email' => 'owner@example.org',
				'marker'        => '{option:job_settings.ops_email}',
			)
		);
		$context = new Context( $this->clock, Records::get( $id ) );
		$reads   = array();
		$step    = new SendEmail(
			function ( $page, $field ) use ( &$reads ) {
				$reads[] = "$page.$field";
				return 'Ops@Example.org, owner@example.org';
			}
		);

		$this->assertSame(
			array( 'owner@example.org', 'Ops@Example.org', 'lit@example.org' ),
			$step->recipients( '{{record.contact_email}}, {option:job_settings.ops_email}; lit@example.org', $context ),
			'Duplicates are dropped case-insensitively.'
		);
		$this->assertSame( array( 'job_settings.ops_email' ), $reads );

		$error = $step->recipients( '{{record.marker}}', $context );
		$this->assertSame( 'invalid_recipient', $error->get_error_code(), 'A field value is never read as an option tag.' );
		$this->assertSame( array( 'job_settings.ops_email' ), $reads );

		$this->assertSame( 'invalid_recipient', $step->recipients( 'not-an-address', $context )->get_error_code() );
		$this->assertSame( 'too_many_recipients', $step->recipients( 'a@x.org, b@x.org, c@x.org, d@x.org, e@x.org, f@x.org', $context )->get_error_code() );
		$this->assertSame( array( 'x@example.org' ), $step->recipients( '{{record.missing | default:"x@example.org"}}', $context ) );

		$empty = new SendEmail( fn() => '' );
		$this->assertSame( 'no_recipient', $empty->recipients( '{option:job_settings.ops_email}', $context )->get_error_code() );

		if ( ! function_exists( 'pegwork_get_option' ) ) {
			$this->assertSame( 'options_unavailable', ( new SendEmail() )->recipients( '{option:job_settings.ops_email}', $context )->get_error_code() );
		}
	}

	/**
	 * Masked addresses keep the first character and the domain.
	 */
	public function test_mask() {
		$this->assertSame( 'o***@example.org', SendEmail::mask( 'owner@example.org' ) );
		$this->assertSame( '***', SendEmail::mask( 'no-at-sign' ) );
	}
}
