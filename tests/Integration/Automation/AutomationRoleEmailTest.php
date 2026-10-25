<?php
/**
 * Integration tests: `{role:<slug>}` recipients of send_email (Automation v4 PR 6).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\RunLog;
use PegworkAutomation\Context;
use PegworkAutomation\DryRun;
use PegworkAutomation\Editor\BuilderEditor;
use PegworkAutomation\Manual;
use PegworkAutomation\Schema;
use PegworkAutomation\Steps\SendEmail;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests read the module's tables to prove what is stored.

/**
 * Role members are resolved at send time (staff roles only, at most 25), sent in Bcc in one wp_mail() call,
 * and never appear in summaries, step and run rows, the run log, previews, dry runs or the builder config
 * (docs/automation-v4-design.md sections 2.4, 3.5 and 3.7).
 */
class AutomationRoleEmailTest extends AutomationTestCase {

	/**
	 * Roles a test added (removed in tear_down: WP_Roles lives in memory, outside the transaction).
	 *
	 * @var string[]
	 */
	private $added_roles = array();

	/**
	 * Registers the v4 fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/v4';
	}

	/**
	 * Fresh mock mailer per test; the base case's editor gets a known address.
	 */
	public function set_up() {
		parent::set_up();
		wp_update_user(
			array(
				'ID'         => $this->editor,
				'user_email' => 'ed.one@example.org',
			)
		);
		reset_phpmailer_instance(); // After core's "email changed" notice.
	}

	/**
	 * Removes added roles.
	 */
	public function tear_down() {
		foreach ( $this->added_roles as $slug ) {
			remove_role( $slug );
		}
		parent::tear_down();
	}

	/**
	 * Adds a role for this test.
	 *
	 * @param string $slug      Slug.
	 * @param bool   $edit_posts Whether it holds edit_posts.
	 */
	private function role( $slug, $edit_posts ) {
		$caps = array( 'read' => true );
		if ( $edit_posts ) {
			$caps['edit_posts'] = true;
		}
		add_role( $slug, ucfirst( str_replace( '_', ' ', $slug ) ), $caps );
		$this->added_roles[] = $slug;
	}

	/**
	 * Users of a role with addresses `<prefix><n>@example.org`.
	 *
	 * @param string $role   Role.
	 * @param int    $count  How many.
	 * @param string $prefix Address prefix.
	 * @return string[] Addresses.
	 */
	private function members( $role, $count, $prefix ) {
		$addresses = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$address = $prefix . $i . '@example.org';
			self::factory()->user->create(
				array(
					'role'       => $role,
					'user_email' => $address,
				)
			);
			$addresses[] = $address;
		}
		return $addresses;
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
	 * Sends one email step outside a run.
	 *
	 * @param string $to `to` template.
	 * @return \PegworkAutomation\Steps\StepResult
	 */
	private function send( $to ) {
		return ( new SendEmail() )->run(
			array(
				'id'      => 'notify',
				'type'    => 'send_email',
				'to'      => $to,
				'subject' => 'Review',
				'text'    => 'Please review.',
			),
			new Context( $this->clock, null ),
			array()
		);
	}

	/**
	 * Bcc addresses of a sent mail.
	 *
	 * @param array<string, mixed> $mail Mock mail.
	 * @return string[]
	 */
	private static function bcc( array $mail ) {
		$bcc = array_map( fn( $pair ) => $pair[0], $mail['bcc'] );
		sort( $bcc );
		return $bcc;
	}

	/**
	 * Everything stored about a run (run row and step rows) as JSON.
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
	 * The run log list and the detail page of a run, as an administrator sees them.
	 *
	 * @param int $run_id Run ID.
	 */
	private function run_page( $run_id ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		try {
			RunLog::render_log();
			RunLog::render_run( (int) $run_id );
		} finally {
			$html = ob_get_clean();
		}
		wp_set_current_user( $this->editor );
		return $html;
	}

	/**
	 * Acceptance (worked example barn_weekly_note): one email, To the options address, Bcc every member of
	 * both roles deduplicated case-insensitively against To; members appear nowhere in the log.
	 */
	public function test_members_in_bcc_and_nowhere_in_the_log() {
		$this->role( 'barn_reviewer', true );
		$editors   = array_merge( array( 'ed.one@example.org' ), $this->members( 'editor', 2, 'ed.x' ) );
		$reviewers = $this->members( 'barn_reviewer', 1, 'rev' );
		$this->members( 'author', 1, 'auth' );
		$this->members( 'subscriber', 1, 'sub' );
		update_option( 'barn_settings', array( 'development_email' => 'ED.X1@example.org' ) );

		$started = ( new Manual() )->start( 'barn_weekly_note', null, $this->editor, 'cli' );
		$this->assertIsArray( $started, $started instanceof WP_Error ? $started->get_error_message() : '' );
		$this->drain();

		$mails = $this->mails();
		$this->assertCount( 1, $mails, 'One wp_mail() call.' );
		$this->assertSame( array( array( 'ED.X1@example.org', '' ) ), $mails[0]['to'] );
		$expected = array( 'ed.one@example.org', 'ed.x2@example.org', 'rev1@example.org' );
		$this->assertSame( $expected, self::bcc( $mails[0] ), 'ed.x1 is already in To; author and subscriber get nothing.' );

		$run   = $this->only_run( 'barn_weekly_note' );
		$steps = $this->steps( $run['id'] );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( array( 'E***@example.org', 'role:editor (3)', 'role:barn_reviewer (1)' ), end( $steps )['summary']['to'] );

		$stored = $this->stored( $run['id'] );
		$page   = $this->run_page( $run['id'] );
		$this->assertStringContainsString( 'role:editor (3)', $page );
		foreach ( array_merge( $editors, $reviewers ) as $address ) {
			$this->assertStringNotContainsStringIgnoringCase( $address, $stored, "$address is not stored." );
			$this->assertStringNotContainsStringIgnoringCase( $address, $page, "$address is not on the run log." );
		}
	}

	/**
	 * A run whose role cannot be resolved fails without retry; the run and step rows and the run log name the
	 * role and hold no member of the other role.
	 */
	public function test_failed_run_names_the_role_only() {
		update_option( 'barn_settings', array( 'development_email' => 'dev@example.org' ) );
		$this->assertIsArray( ( new Manual() )->start( 'barn_weekly_note', null, $this->editor, 'cli' ) );
		$this->drain();

		$run = $this->only_run( 'barn_weekly_note' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'unknown_role: Role "barn_reviewer" does not exist on this site.', $run['error'] );
		$this->assertSame( array(), $this->mails() );
		$this->assertStringNotContainsString( 'ed.one@example.org', $this->stored( $run['id'] ) );
		$this->assertStringNotContainsString( 'ed.one@example.org', $this->run_page( $run['id'] ) );
	}

	/**
	 * With only role recipients To is empty and the mail still goes, once.
	 */
	public function test_role_only_has_an_empty_to() {
		$result = $this->send( '{role:editor}' );
		$this->assertSame( 'ok', $result->status, (string) $result->error );
		$this->assertSame( array( 'role:editor (1)' ), $result->summary['to'] );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( array(), $mails[0]['to'] );
		$this->assertSame( array( 'ed.one@example.org' ), self::bcc( $mails[0] ) );
	}

	/**
	 * 25 members send; 26 fail `too_many_recipients` with no retry and no mail; the cap holds across roles.
	 */
	public function test_member_cap() {
		$this->members( 'editor', 24, 'ed.x' );
		$this->assertSame( 'ok', $this->send( '{role:editor}' )->status, '25 editors.' );
		$this->assertCount( 25, self::bcc( $this->mails()[0] ) );

		reset_phpmailer_instance();
		$this->members( 'editor', 1, 'ed.y' );
		$result = $this->send( '{role:editor}, desk@example.org' );
		$this->assertSame( 'failed', $result->status );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 'too_many_recipients: Role "editor" has more than 25 members; at most 25 per email.', $result->error );
		$this->assertSame( array(), $this->mails() );

		// All roles together: 24 authors and 2 reviewers pass each role's cap but not the email's.
		$this->role( 'barn_reviewer', true );
		$this->members( 'barn_reviewer', 2, 'rev' );
		$this->members( 'author', 24, 'auth' );
		$this->assertSame( 'ok', $this->send( '{role:barn_reviewer}' )->status );
		$this->assertSame( 'too_many_recipients: 26 role members; at most 25 per email.', $this->send( '{role:author}, {role:barn_reviewer}' )->error );
	}

	/**
	 * Staff roles only: subscriber is refused unless the allow-list filter names it; an unknown role fails.
	 */
	public function test_staff_roles_only() {
		$this->members( 'subscriber', 2, 'sub' );

		$result = $this->send( '{role:subscriber}' );
		$this->assertSame( 'failed', $result->status );
		$this->assertFalse( $result->retryable );
		$this->assertStringStartsWith( 'role_not_allowed: Role "subscriber" is not a staff role', $result->error );
		$this->assertSame( 'unknown_role: Role "no_such_role" does not exist on this site.', $this->send( '{role:no_such_role}' )->error );
		$this->assertSame( array(), $this->mails() );

		$allow = fn() => array( 'subscriber' );
		add_filter( SendEmail::ROLES_FILTER, $allow );
		try {
			$result = $this->send( '{role:subscriber}' );
			$this->assertSame( 'ok', $result->status, (string) $result->error );
			$this->assertSame( array( 'sub1@example.org', 'sub2@example.org' ), self::bcc( $this->mails()[0] ) );
			$this->assertContains( 'subscriber', array_column( BuilderEditor::registry_config( pegwork_registry() )['roles'], 'slug' ) );
		} finally {
			remove_filter( SendEmail::ROLES_FILTER, $allow );
		}
	}

	/**
	 * A role with no member that has an address fails `no_recipient` (no retry), even beside an explicit
	 * address, so a partial send never hides a misconfiguration; the error names the role only.
	 */
	public function test_role_without_members_fails() {
		$this->role( 'barn_reviewer', true );
		foreach ( array( '{role:barn_reviewer}', 'desk@example.org, {role:barn_reviewer}' ) as $to ) {
			$result = $this->send( $to );
			$this->assertSame( 'failed', $result->status, $to );
			$this->assertFalse( $result->retryable, $to );
			$this->assertSame( 'no_recipient: Role "barn_reviewer" has no member with an email address.', $result->error, $to );
		}
		$this->assertSame( array(), $this->mails() );
	}

	/**
	 * A wp_mail() failure stays retryable and its message never names a member.
	 */
	public function test_mail_failure_names_no_member() {
		$fail = static function ( $short, $atts ) {
			$error = new WP_Error( 'wp_mail_failed', 'Could not deliver to ' . implode( ', ', (array) $atts['headers'] ) );
			do_action( 'wp_mail_failed', $error ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, as wp_mail() fires it.
			return false;
		};
		add_filter( 'pre_wp_mail', $fail, 10, 2 );
		try {
			$result = $this->send( 'desk@example.org, {role:editor}' );
		} finally {
			remove_filter( 'pre_wp_mail', $fail, 10 );
		}
		$this->assertSame( 'failed', $result->status );
		$this->assertTrue( $result->retryable );
		$this->assertStringStartsWith( 'mail_failed: Could not deliver to Bcc: [role member]', $result->error );
		$this->assertStringNotContainsString( 'ed.one', $result->error );
	}

	/**
	 * The dry run, the template preview and the builder config count members and never list them.
	 */
	public function test_dry_run_preview_and_config_mask_members() {
		$this->members( 'editor', 1, 'ed.x' );
		update_option( 'barn_settings', array( 'volunteer_manager' => 'manager@example.org' ) );
		$volunteer = Records::create(
			'barn_volunteer',
			array(
				'name'     => 'Sam',
				'email'    => 'sam@example.org',
				'interest' => 'garden',
			),
			'pending'
		);
		$this->assertIsInt( $volunteer );
		reset_phpmailer_instance();

		$result = DryRun::result( 'barn_volunteer_review', $volunteer, null );
		$this->assertIsArray( $result );
		$entry = $result['steps'][0];
		$this->assertSame( 'ok', $entry['result'] );
		$this->assertSame( array( 'm***@example.org', 'role:editor (2)' ), $entry['detail']['to'] );
		$this->assertSame( 'Send email notify: to role editor (2 people), m***@example.org, subject "Volunteer application to review: Sam" (not sent).', $entry['message'] );
		$json = (string) wp_json_encode( $result );
		foreach ( array( 'ed.one@example.org', 'ed.x1@example.org' ) as $address ) {
			$this->assertStringNotContainsString( $address, $json );
		}
		$this->assertSame( array(), $this->mails(), 'The dry run sends nothing.' );
		$this->assertSame( array(), $this->runs( 'barn_volunteer_review' ), 'The dry run writes no run.' );

		$this->assertSame( 'role:editor (2), role:subscriber (role_not_allowed), x@example.org', DryRun::preview( '{role:editor}, {role:subscriber}, x@example.org', false, null, array() ) );

		$roles = BuilderEditor::registry_config( pegwork_registry() )['roles'];
		$this->assertSame( array( 'administrator', 'editor', 'author', 'contributor' ), array_column( $roles, 'slug' ) );
		$this->assertSame( 'Editor', $roles[1]['name'] );
		$this->assertStringNotContainsString( '@', (string) wp_json_encode( $roles ) );
	}
}
