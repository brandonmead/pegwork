<?php
/**
 * Integration tests: QA fixes in the automation runtime (SEC-1, E1, E2 runtime, E3, E5).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use Pegwork\Tests\Support\FakeScheduler;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Scheduler\InlineScheduler;
use PegworkAutomation\StartRoute;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Fixture tests/fixtures/valid/engine-actor: `act_donor` (gift, editors: get the donor, find donors, an
 * email reading both, then stamp, start `act_child` on, and trash the donor, each `on_error: continue`, and a
 * report email), `act_child` (trash the bound contact), `act_two` (two emails), `act_reads` (an email, a
 * find, a loop of emails) and `act_wait` (email, wait P1D, email).
 */
class AutomationQaEngineTest extends AutomationTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Contributor user ID.
	 *
	 * @var int
	 */
	private $contributor;

	/**
	 * Registers the fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/engine-actor';
	}

	/**
	 * Users and a fresh mail mock.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
	}

	/**
	 * Sent mail.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mail() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Everything the mail mock sent, as one string.
	 */
	private function mail_text() {
		return implode( "\n", array_map( fn( $m ) => $m['subject'] . "\n" . $m['body'], $this->mail() ) );
	}

	/**
	 * A contact written by the administrator.
	 *
	 * @param string $status Post status.
	 * @param string $stage  Stage.
	 * @param string $name   Name.
	 * @return int
	 */
	private function contact( $status = 'private', $stage = '', $name = 'Dana' ) {
		wp_set_current_user( $this->admin );
		$id = Records::create(
			'act_contact',
			array(
				'name'  => $name,
				'email' => 'secret@example.org',
				'stage' => $stage,
			),
			$status
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A gift by a user, linked to a donor.
	 *
	 * @param int    $author User ID.
	 * @param int    $donor  Contact ID, or 0.
	 * @param string $status Post status.
	 * @return int
	 */
	private function gift( $author, $donor = 0, $status = 'draft' ) {
		wp_set_current_user( $author );
		$values = array(
			'name'   => 'My gift',
			'amount' => 10,
		);
		if ( $donor > 0 ) {
			$values['act_gift_donor'] = array( $donor );
		}
		$id = Records::create( 'act_gift', $values, $status );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Starts a manual automation as a user (as the record editor's route does once authorized) and drains.
	 *
	 * @param string   $key    Automation key.
	 * @param int|null $record Record ID.
	 * @param int      $user   User ID.
	 * @return int Run ID.
	 */
	private function start( $key, $record, $user ) {
		$result = ( new Manual() )->start( $key, $record, $user, 'editor' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->drain();
		return $result['run'];
	}

	/**
	 * SEC-1: `allow: editors` means the type's `edit_others_posts` plus read and edit on the record, so a
	 * contributor cannot start it on their own draft, nor an author on their own published gift.
	 */
	public function test_editors_means_editors_of_every_record() {
		$donor  = $this->contact();
		$draft  = $this->gift( $this->contributor, $donor );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = $this->gift( $author, $donor, 'publish' );

		wp_set_current_user( $this->contributor );
		$this->assertTrue( current_user_can( 'edit_post', $draft ), 'The contributor edits their own draft.' );
		$this->assertFalse( StartRoute::can_run( 'act_donor', $draft ) );
		wp_set_current_user( $author );
		$this->assertTrue( current_user_can( 'edit_post', $own ) );
		$this->assertFalse( StartRoute::can_run( 'act_donor', $own ) );
		wp_set_current_user( $this->editor );
		$this->assertTrue( StartRoute::can_run( 'act_donor', $draft ) );
		wp_set_current_user( $this->admin );
		$this->assertTrue( StartRoute::can_run( 'act_donor', $draft ) );
	}

	/**
	 * SEC-1: a run a contributor started reads only what the contributor can read: the private donor is
	 * not found, its email is never sent, and it is neither stamped nor trashed nor handed to act_child.
	 */
	public function test_user_started_run_does_not_read_private_records() {
		$donor = $this->contact( 'private' );
		$gift  = $this->gift( $this->contributor, $donor );

		$run = $this->start( 'act_donor', $gift, $this->contributor );

		$this->assertSame( 'completed', $this->run_row( $run )['status'] );
		$this->assertStringNotContainsString( 'secret@example.org', $this->mail_text() );
		$this->assertSame( 'private', get_post_status( $donor ), 'Not trashed.' );
		$this->assertSame( '', (string) Records::get( $donor )['values']['stage'], 'Not stamped.' );
		$this->assertSame( array(), $this->runs( 'act_child' ) );
		$steps = array_column( $this->steps( $run ), 'summary', 'step_id' );
		$this->assertFalse( $steps['donor']['found'] );
		$this->assertSame( 1, $steps['donor']['unreadable'] );
		$this->assertSame( 0, $steps['all']['returned'] );
		$this->assertSame( 1, $steps['all']['unreadable'] );
	}

	/**
	 * SEC-1: a donor the contributor can read but not edit or delete is read, but update_record,
	 * start_automation and trash_record on it fail `record_forbidden` (no retry).
	 */
	public function test_user_started_run_needs_edit_and_delete_on_other_records() {
		$donor = $this->contact( 'publish' );
		$gift  = $this->gift( $this->contributor, $donor );

		$this->start( 'act_donor', $gift, $this->contributor );

		$this->assertStringContainsString( 'Donor found: 1', $this->mail_text() );
		$this->assertStringContainsString( 'stamp=record_forbidden child=record_forbidden remove=record_forbidden', $this->mail_text() );
		$this->assertSame( 'publish', get_post_status( $donor ) );
		$this->assertSame( '', (string) Records::get( $donor )['values']['stage'] );
		$this->assertSame( array(), $this->runs( 'act_child' ) );
	}

	/**
	 * SEC-1: an editor's run acts on the private donor; the child it starts carries the editor as actor.
	 * A system run (no starting user) acts as before.
	 */
	public function test_editor_and_system_runs_act_on_other_records() {
		foreach ( array( $this->editor, 0 ) as $user ) {
			reset_phpmailer_instance();
			$donor = $this->contact( 'private' );
			$gift  = $this->gift( $this->admin, $donor );

			$this->start( 'act_donor', $gift, $user );

			$this->assertStringContainsString( 'secret@example.org', $this->mail_text(), "User $user." );
			$this->assertStringContainsString( 'stamp= child= remove=', $this->mail_text() );
			$this->assertSame( 'thanked', Records::get( $donor )['values']['stage'] );
			$this->assertSame( 'trash', get_post_status( $donor ) );
			$child = array_values( array_filter( $this->runs( 'act_child' ), fn( $r ) => (int) $r['record_id'] === $donor ) );
			$this->assertCount( 1, $child );
			$this->assertSame( $user > 0 ? $user : null, $child[0]['context']['actor'] ?? null );
		}
	}

	/**
	 * E2 (runtime): a run that a schedule sweep started, directly or further down the chain, never trashes
	 * (`trash_in_sweep`), even when the definitions passed the registry.
	 */
	public function test_sweep_chain_cannot_trash() {
		$contact = $this->contact( 'publish' );
		$sweep   = array(
			'id'           => 999001,
			'automation'   => 'qa_sweep',
			'trigger_type' => 'schedule',
			'record_id'    => $contact,
			'depth'        => 0,
			'context'      => array( 'trigger' => array() ),
		);
		$child   = Manual::chain( 'act_child', $contact, $sweep, 'start' );
		$this->assertIsArray( $child );
		$this->drain();

		$row = $this->run_row( $child['run'] );
		$this->assertSame( 'qa_sweep', $row['context']['sweep'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringStartsWith( 'trash_in_sweep:', $row['error'] );
		$this->assertSame( 'publish', get_post_status( $contact ) );

		$grandchild = Manual::chain( 'act_child', $contact, $row, 'remove' );
		$this->assertSame( 'qa_sweep', $this->run_row( $grandchild['run'] )['context']['sweep'], 'Carried down the chain.' );
	}

	/**
	 * E1: a run that fails `step_limit` before a step resumes at that step on retry, so the step before it
	 * is not repeated (one "One" email).
	 */
	public function test_retry_after_step_limit_does_not_repeat_the_previous_step() {
		$limit = fn() => 1;
		add_filter( 'pegwork_automation_step_limit', $limit );

		$run = $this->start( 'act_two', null, 0 );
		$row = $this->run_row( $run );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringStartsWith( 'step_limit:', $row['error'] );
		$this->assertSame( 'two', $row['current_step'] );
		remove_filter( 'pegwork_automation_step_limit', $limit );

		$this->assertTrue( ( new Manual() )->retry( $run ) );
		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $run )['status'] );
		$this->assertSame( array( 'One', 'Two' ), array_column( $this->mail(), 'subject' ) );
	}

	/**
	 * E1: a loop that fails `read_limit` when it starts resumes at the loop on retry; the email before the
	 * find is sent once.
	 */
	public function test_retry_after_read_limit_at_loop_start_restarts_the_loop() {
		$this->contact( 'publish', 'loop', 'Ann' );
		$this->contact( 'publish', 'loop', 'Ben' );
		$limit = fn() => 3; // The find reads 2; the loop's snapshot 2 more.
		add_filter( 'pegwork_automation_read_limit', $limit );

		$run = $this->start( 'act_reads', null, 0 );
		$row = $this->run_row( $run );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringStartsWith( 'read_limit', $row['error'] );
		$this->assertSame( 'each', $row['current_step'] );
		remove_filter( 'pegwork_automation_read_limit', $limit );

		$this->assertTrue( ( new Manual() )->retry( $run ) );
		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $run )['status'] );
		$this->assertSame( array( 'Before', 'Item Ann', 'Item Ben' ), array_column( $this->mail(), 'subject' ) );
	}

	/**
	 * E3: the bound record deleted for good during a `wait for` stops the run (`no_record`) before the next
	 * step: no email with an empty record.
	 */
	public function test_record_deleted_during_wait_stops_the_run() {
		$gift = $this->gift( $this->admin );
		$run  = $this->start( 'act_wait', $gift, $this->editor );
		$this->assertSame( 'waiting', $this->run_row( $run )['status'] );
		wp_delete_post( $gift, true );

		$this->clock->advance( 'P2D' );
		$this->drain();
		$row = $this->run_row( $run );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( 'no_record', $row['context']['outcome'] );
		$this->assertSame( array( 'Now My gift' ), array_column( $this->mail(), 'subject' ) );
		$this->assertSame( 'Stopped: the record was deleted', \PegworkAutomation\Admin\Format::outcome( $row ) );
	}

	/**
	 * E5: with a clock, drain() reads the time again before each firing, so a wake-up a firing schedules
	 * for a later "now" still fires in process instead of being handed to WP-Cron.
	 */
	public function test_inline_drain_reads_the_clock_again() {
		foreach ( array( false, true ) as $with_clock ) {
			$now    = 100;
			$inline = new InlineScheduler( new FakeScheduler(), array( 'pegwork_qa_hook' ) );
			$fired  = array();
			$hook   = function ( $n ) use ( &$now, &$fired, $inline ) {
				$fired[] = $n;
				if ( 1 === $n ) {
					$now = 101; // The firing took past a second boundary, then asked for a wake-up "now".
					$inline->schedule( 'pegwork_qa_hook', array( 2 ), $now );
				}
			};
			add_action( 'pegwork_qa_hook', $hook );
			$inline->schedule( 'pegwork_qa_hook', array( 1 ), 100 );
			$clock = function () use ( &$now ) {
				return $now;
			};
			$inline->drain( 100, 10000, $with_clock ? $clock : null );
			remove_action( 'pegwork_qa_hook', $hook );

			$this->assertSame( $with_clock ? array( 1, 2 ) : array( 1 ), $fired );
			$this->assertSame( $with_clock ? 0 : 1, $inline->flush() );
		}
	}
}
