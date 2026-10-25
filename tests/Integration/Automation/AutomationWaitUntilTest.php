<?php
/**
 * Integration tests: waits until a date field (Automation v4 PR 3).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Format;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use ReflectionProperty;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * `barn_task_due_reminder` of the v4 fixture (wait until 3 days before `due_on` at 09:00, then `still_open`,
 * `remind`, `stamp`) against the rules of docs/automation-v4-design.md section 3.2: the cursor stays on the
 * wait, each wake-up decides again, and Waits::nudge() pulls the wake-up to now when `due_on` or the status
 * changes. Site in New York (DST starts 2027-03-14); tasks created 2027-03-01 10:00. `barn_v4_shapes` (also
 * on barn_task) is disabled so only the reminder runs.
 */
class AutomationWaitUntilTest extends AutomationTestCase {

	/**
	 * The reminder automation.
	 */
	private const KEY = 'barn_task_due_reminder';

	/**
	 * Registers the v4 fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/v4';
	}

	/**
	 * Site in New York, clock at 2027-03-01 10:00, mail mock reset, the shapes automation off.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'gmt_offset', '' );
		Overrides::set( 'barn_v4_shapes', 'off' );
		$this->clock->set( '2027-03-01 10:00' );
		reset_phpmailer_instance();
	}

	/**
	 * Creates a task (emits record.created, which starts a reminder run) and drains.
	 *
	 * @param string $due  Due date, or '' for none.
	 * @param string $name Name.
	 * @return int Task ID.
	 */
	private function task( $due, $name = 'Call the barn' ) {
		$id = Records::create(
			'barn_task',
			array(
				'name'           => $name,
				'due_on'         => $due,
				'assignee_email' => 'staff@example.org',
			),
			'publish'
		);
		$this->assertIsInt( $id );
		$this->drain();
		return $id;
	}

	/**
	 * Moves the clock to a site time and fires due wake-ups.
	 *
	 * @param string $at Site time.
	 */
	private function go( $at ) {
		$this->clock->set( $at );
		$this->drain();
	}

	/**
	 * Updates a task (emits record.updated) and drains.
	 *
	 * @param int                  $id     Task ID.
	 * @param array<string, mixed> $values Values.
	 */
	private function edit( $id, $values ) {
		$this->assertTrue( Records::update( $id, $values ) );
		$this->drain();
	}

	/**
	 * The reminder run of a task.
	 *
	 * @param int $task Task ID.
	 * @return array<string, mixed>
	 */
	private function run_of( $task ) {
		$runs = array_values( array_filter( $this->runs( self::KEY ), fn( $run ) => $task === $run['record_id'] ) );
		$this->assertCount( 1, $runs, "One reminder run for task $task." );
		return $runs[0];
	}

	/**
	 * Mail sent so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mail() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Rows of the wait step: [ attempt, summary ].
	 *
	 * @param int $run Run ID.
	 * @return array<int, array{0: int, 1: array<string, mixed>}>
	 */
	private function wait_rows( $run ) {
		$rows = array_filter( $this->steps( $run ), fn( $step ) => 'before_due' === $step['step_id'] );
		return array_values( array_map( fn( $step ) => array( (int) $step['attempt'], (array) $step['summary'] ), $rows ) );
	}

	/**
	 * Due 03-20: waits until 03-17 09:00 EDT (13:00 UTC); nothing at 08:59; one reminder at 09:00, stamped.
	 */
	public function test_waits_until_three_days_before_then_reminds() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'before_due', $run['current_step'], 'The cursor stays on the wait.' );
		$this->assertSame( '2027-03-17 13:00:00', $run['resume_at'] );
		$this->assertSame( array( 'before_due' => array( 'since' => '2027-03-01 15:00:00' ) ), $run['context']['until'] );
		$this->assertSame(
			array(
				array(
					1,
					array(
						'until'     => '3 days before due_on at 09:00',
						'date'      => '2027-03-20',
						'resume_at' => '2027-03-17 13:00:00',
					),
				),
			),
			$this->wait_rows( $run['id'] )
		);

		$this->go( '2027-03-16 09:00' );
		$this->go( '2027-03-17 08:59' );
		$this->assertSame( array(), $this->mail(), 'Nothing before 09:00.' );
		$this->assertSame( 'waiting', $this->run_row( $run['id'] )['status'] );

		$this->go( '2027-03-17 09:00' );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 1, $this->mail() );
		$this->assertSame( 'staff@example.org', $this->mail()[0]['to'][0][0] );
		$this->assertSame( '2027-03-17', Records::get( $task )['values']['reminded_on'] );
		$this->assertArrayNotHasKey( 'until', $run['context'], 'The wait state is removed when the step completes.' );
		$this->assertSame(
			array(
				'until' => '3 days before due_on at 09:00',
				'date'  => '2027-03-20',
				'due'   => true,
				'late'  => false,
			),
			$this->wait_rows( $run['id'] )[1][1]
		);
		$this->assertSame( array( 'before_due', 'before_due', 'still_open', 'remind', 'stamp' ), array_column( $this->steps( $run['id'] ), 'step_id' ) );
		$this->assertSame( 'Checked the date again: the time has come, so the run continues', Format::wait_label( $this->wait_rows( $run['id'] )[1][1], 2 ) );
		$this->assertStringStartsWith( 'Waits until March 17, 2027', Format::wait_label( $this->wait_rows( $run['id'] )[0][1], 1 ) );
	}

	/**
	 * Due 03-12, moved to 03-07 on 03-05 10:00: nudged, and reminded at once (the new target has passed).
	 */
	public function test_moved_earlier_is_nudged_and_continues() {
		$task = $this->task( '2027-03-12' );
		$run  = $this->run_of( $task );
		$this->assertSame( '2027-03-09 14:00:00', $run['resume_at'], '09:00 EST.' );

		$this->clock->set( '2027-03-05 10:00' );
		$this->edit( $task, array( 'due_on' => '2027-03-07' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 1, $this->mail(), 'Reminded at 10:00 on 03-05.' );
		$this->assertSame( '2027-03-05', Records::get( $task )['values']['reminded_on'] );
		$this->assertSame(
			array(
				'due'  => true,
				'late' => false,
			),
			array_intersect_key( $this->wait_rows( $run['id'] )[1][1], array_flip( array( 'due', 'late' ) ) )
		);
	}

	/**
	 * Moved earlier but still in the future: nudged, it waits until the new target.
	 */
	public function test_moved_earlier_waits_for_the_new_target() {
		$task = $this->task( '2027-03-31' );
		$run  = $this->run_of( $task );
		$this->assertSame( '2027-03-28 13:00:00', $run['resume_at'] );

		$this->clock->set( '2027-03-05 10:00' );
		$this->edit( $task, array( 'due_on' => '2027-03-20' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2027-03-17 13:00:00', $run['resume_at'] );
		$this->assertSame( '2027-03-17 13:00:00', gmdate( 'Y-m-d H:i:s', $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) ), 'The wake-up moved too.' );
		$this->assertSame( array( 1, 2 ), array_column( $this->wait_rows( $run['id'] ), 0 ) );
	}

	/**
	 * Due 03-10, moved to 03-31 on 03-05: nothing on 03-07; reminded 03-28 09:00.
	 */
	public function test_moved_later_waits_again() {
		$task = $this->task( '2027-03-10' );
		$run  = $this->run_of( $task );
		$this->assertSame( '2027-03-07 14:00:00', $run['resume_at'] );

		$this->clock->set( '2027-03-05 10:00' );
		$this->edit( $task, array( 'due_on' => '2027-03-31' ) );
		$this->assertSame( '2027-03-28 13:00:00', $this->run_row( $run['id'] )['resume_at'] );

		$this->go( '2027-03-07 12:00' );
		$this->assertSame( array(), $this->mail(), 'Nothing on 03-07.' );
		$this->go( '2027-03-28 09:00' );
		$this->assertCount( 1, $this->mail() );
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
	}

	/**
	 * The same move written straight to post meta (no event): the original wake-up on 03-07 decides again
	 * and waits until 03-28.
	 */
	public function test_direct_meta_write_is_caught_on_the_original_wake() {
		$task = $this->task( '2027-03-10' );
		$run  = $this->run_of( $task );

		$this->clock->set( '2027-03-05 10:00' );
		update_post_meta( $task, '_barn_task_due_on', '2027-03-31' );
		$this->drain();
		$this->assertSame( '2027-03-07 14:00:00', $this->run_row( $run['id'] )['resume_at'], 'No event, no nudge.' );

		$this->go( '2027-03-07 09:00' );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2027-03-28 13:00:00', $run['resume_at'] );
		$this->assertSame( 'Checked the date again: waits until March 28, 2027 9:00 am', Format::wait_label( $this->wait_rows( $run['id'] )[1][1], 2 ) );

		$this->go( '2027-03-28 09:00' );
		$this->assertCount( 1, $this->mail() );
	}

	/**
	 * No due date: the run parks ("Waiting for Due on") for up to 2 years. Due set to 04-10 on 03-20:
	 * reminded 04-07 09:00.
	 */
	public function test_empty_date_parks_until_a_date_is_set() {
		$task = $this->task( '' );
		$run  = $this->run_of( $task );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2029-03-01 15:00:00', $run['resume_at'], 'The horizon: 2 years from the first arrival.' );
		$this->assertTrue( $run['context']['until']['before_due']['empty'] );
		$this->assertSame( 'Waiting for Due on', Format::outcome( $run ) );
		$this->assertSame( array( 'until', 'waiting_for_date', 'resume_at' ), array_keys( $this->wait_rows( $run['id'] )[0][1] ) );
		$this->assertSame( 'The date is empty, so it waits for a date', Format::wait_label( $this->wait_rows( $run['id'] )[0][1], 1 ) );

		$this->clock->set( '2027-03-20 10:00' );
		$this->edit( $task, array( 'due_on' => '2027-04-10' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( '2027-04-07 13:00:00', $run['resume_at'] );
		$this->assertSame( array( 'since' => '2027-03-01 15:00:00' ), $run['context']['until']['before_due'], 'The first arrival is kept; empty is cleared.' );
		$this->assertSame( '', Format::outcome( $run ) );

		$this->go( '2027-04-07 08:59' );
		$this->assertSame( array(), $this->mail() );
		$this->go( '2027-04-07 09:00' );
		$this->assertCount( 1, $this->mail() );
	}

	/**
	 * Due 03-20, cleared on 03-10: parked, no email. On 2029-03-01 10:00 it stops at the wait (`no_date`).
	 */
	public function test_cleared_date_parks_then_stops_after_two_years() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );

		$this->clock->set( '2027-03-10 10:00' );
		$this->edit( $task, array( 'due_on' => '' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2029-03-01 15:00:00', $run['resume_at'], 'The horizon counts from the first arrival, not the clearing.' );

		$this->go( '2027-03-17 09:00' );
		$this->go( '2029-03-01 09:59' );
		$this->assertSame( 'waiting', $this->run_row( $run['id'] )['status'] );
		$this->go( '2029-03-01 10:00' );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:before_due', $run['context']['outcome'] );
		$this->assertSame( 'Stopped at wait before_due', Format::outcome( $run ) );
		$rows = $this->wait_rows( $run['id'] );
		$this->assertTrue( end( $rows )[1]['no_date'] );
		$this->assertSame( array(), $this->mail() );
	}

	/**
	 * Due 03-02: the target (02-27 09:00) has passed at the first arrival, so the run continues at once.
	 */
	public function test_past_date_continues_at_once() {
		$task = $this->task( '2027-03-02' );
		$run  = $this->run_of( $task );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 1, $this->mail() );
		$this->assertSame(
			array(
				'until' => '3 days before due_on at 09:00',
				'date'  => '2027-03-02',
				'due'   => true,
				'late'  => true,
			),
			$this->wait_rows( $run['id'] )[0][1]
		);
	}

	/**
	 * Imported with due 2026-12-01: continues, stops at `still_open`, no email.
	 */
	public function test_overdue_import_stops_at_still_open() {
		$run = $this->run_of( $this->task( '2026-12-01' ) );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:still_open', $run['context']['outcome'] );
		$this->assertSame( 'Stopped at condition still_open', Format::outcome( $run ) );
		$this->assertSame( array(), $this->mail() );
	}

	/**
	 * Marked done on 03-15 (not a waited field: no nudge): wakes 03-17 and stops at `still_open`.
	 */
	public function test_done_task_wakes_on_time_and_stops() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );

		$this->clock->set( '2027-03-15 10:00' );
		$this->edit( $task, array( 'done' => true ) );
		$this->assertSame( '2027-03-17 13:00:00', $this->run_row( $run['id'] )['resume_at'] );
		$this->assertCount( 1, $this->wait_rows( $run['id'] ), 'Not woken.' );

		$this->go( '2027-03-17 09:00' );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'stopped_at:still_open', $run['context']['outcome'] );
		$this->assertSame( array(), $this->mail() );
	}

	/**
	 * Trashed on 03-10: the status change nudges the run, and it stops (`no_record`).
	 */
	public function test_trashed_record_is_nudged_and_stops() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );

		$this->clock->set( '2027-03-10 10:00' );
		$this->assertTrue( Records::trash( $task ) );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:before_due', $run['context']['outcome'] );
		$this->assertTrue( $this->wait_rows( $run['id'] )[1][1]['no_record'] );
		$this->assertSame( 'Checked the date again: the record is gone or in the trash, so the run stops', Format::wait_label( $this->wait_rows( $run['id'] )[1][1], 2 ) );
		$this->assertSame( array(), $this->mail() );
	}

	/**
	 * Deleted for good (no event): the original wake-up finds no record and stops.
	 */
	public function test_deleted_record_stops_on_the_original_wake() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );
		wp_delete_post( $task, true );

		$this->go( '2027-03-17 09:00' );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'stopped_at:before_due', $run['context']['outcome'] );
		$this->assertSame( array(), $this->mail() );
	}

	/**
	 * A change of another field leaves the resume time alone; the listener makes no query when no enabled
	 * automation waits on the type and field, and one indexed query when one does.
	 */
	public function test_listener_skips_unrelated_changes_without_queries() {
		global $wpdb;
		$task                   = $this->task( '2027-03-20' );
		$run                    = $this->run_of( $task );
		$this->scheduler->fired = array();

		$this->clock->set( '2027-03-05 10:00' );
		$this->edit( $task, array( 'name' => 'Call the barn today' ) );
		$this->assertSame( '2027-03-17 13:00:00', $this->run_row( $run['id'] )['resume_at'] );
		$this->assertSame( array(), $this->scheduler->fired, 'Nothing woke.' );

		$payload = fn( $type, $changed ) => array(
			'id'      => $task,
			'type'    => $type,
			'status'  => 'publish',
			'changed' => $changed,
			'old'     => array(),
			'user_id' => 0,
			'origin'  => array( 'depth' => 0 ),
		);
		Engine::on_record_updated( $payload( 'barn_task', array( 'name' ) ) ); // Warms the index and the option cache.
		$queries = function ( $payload ) use ( $wpdb ) {
			$before = $wpdb->num_queries;
			Engine::on_record_updated( $payload );
			return $wpdb->num_queries - $before;
		};
		$this->assertSame( 0, $queries( $payload( 'barn_contact', array( 'name', 'status' ) ) ), 'No automation waits on contacts.' );
		$this->assertSame( 0, $queries( $payload( 'barn_task', array( 'name', 'done' ) ) ), 'No wait on these fields.' );
		$this->assertSame( 0, $queries( $payload( 'barn_task', array() ) ), 'Nothing changed.' );
		$this->assertSame( 0, $queries( $payload( 'barn_task', array( 'reminded_on' ) ) ), 'Only barn_v4_shapes waits on reminded_on, and it is off.' );
		$this->assertSame( 1, $queries( array( 'id' => 999999 ) + $payload( 'barn_task', array( 'due_on' ) ) ), 'A waited field: one query, which finds no run of this record.' );
		$this->assertSame( '2027-03-17 13:00:00', $this->run_row( $run['id'] )['resume_at'] );
	}

	/**
	 * Runs of a disabled automation are not nudged.
	 */
	public function test_disabled_automation_is_not_nudged() {
		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );
		Overrides::set( self::KEY, 'off' );

		$this->clock->set( '2027-03-05 10:00' );
		$this->edit( $task, array( 'due_on' => '2027-03-07' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2027-03-17 13:00:00', $run['resume_at'] );
	}

	/**
	 * A run waiting for a retry at another step (the reminder mail failed) is untouched by a date change.
	 */
	public function test_retry_waiting_run_is_untouched() {
		add_filter( 'pre_wp_mail', '__return_false' );
		$task = $this->task( '2027-03-02' );
		$run  = $this->run_of( $task );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'remind', $run['current_step'] );
		$resume = $run['resume_at'];

		$this->edit( $task, array( 'due_on' => '2027-03-03' ) );
		$this->assertSame( $resume, $this->run_row( $run['id'] )['resume_at'] );
		remove_filter( 'pre_wp_mail', '__return_false' );
	}

	/**
	 * A time-budget requeue right before the wait resumes at the wait, which then decides as usual.
	 */
	public function test_time_budget_requeue_at_the_wait() {
		$registry    = pegwork_registry();
		$property    = new ReflectionProperty( $registry, 'automations' );
		$automations = $property->getValue( $registry );
		array_unshift(
			$automations[ self::KEY ]['steps'],
			array(
				'id'   => 'named',
				'type' => 'condition',
				'when' => array(
					'var' => 'record.name',
					'op'  => 'not_empty',
				),
			)
		);
		$property->setValue( $registry, $automations );
		add_filter( 'pegwork_automation_time_budget', '__return_zero' );

		$task = $this->task( '2027-03-20' );
		$run  = $this->run_of( $task );
		remove_filter( 'pegwork_automation_time_budget', '__return_zero' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'before_due', $run['current_step'] );
		$this->assertSame( '2027-03-17 13:00:00', $run['resume_at'] );
		$this->assertSame( array( 'named', 'before_due' ), array_column( $this->steps( $run['id'] ), 'step_id' ) );
		$this->assertCount( 2, array_filter( $this->scheduler->fired, fn( $fired ) => array( $run['id'] ) === $fired[1] ), 'Two wake-ups: the requeue resumed at the wait.' );
	}

	/**
	 * A private date field's value is masked in the summary.
	 */
	public function test_private_field_is_masked_in_the_summary() {
		$registry = pegwork_registry();
		$property = new ReflectionProperty( $registry, 'content_types' );
		$types    = $property->getValue( $registry );
		foreach ( $types['barn_task']['fields'] as $index => $field ) {
			if ( 'due_on' === $field['key'] ) {
				$types['barn_task']['fields'][ $index ]['visibility'] = 'private';
			}
		}
		$property->setValue( $registry, $types );

		$run = $this->run_of( $this->task( '2027-03-20' ) );
		$this->assertSame( '[private]', $this->wait_rows( $run['id'] )[0][1]['date'] );
		$this->assertStringNotContainsString( '2027-03-20', (string) wp_json_encode( $this->steps( $run['id'] ) ) );
	}

	/**
	 * Each re-check is an attempt toward the step limit: many edits during one wait reach `step_limit`.
	 */
	public function test_many_edits_reach_the_step_limit() {
		$limit = fn() => 3;
		add_filter( 'pegwork_automation_step_limit', $limit );
		$task  = $this->task( '2027-03-20' );
		$run   = $this->run_of( $task );

		$this->clock->set( '2027-03-05 10:00' );
		foreach ( array( '2027-03-21', '2027-03-22', '2027-03-23' ) as $due ) {
			$this->edit( $task, array( 'due_on' => $due ) );
		}
		remove_filter( 'pegwork_automation_step_limit', $limit );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'step_limit:', $run['error'] );
		$this->assertCount( 3, $this->wait_rows( $run['id'] ) );
	}

	/**
	 * Dry run lines: the wake time, an empty date, and a time that has passed. Nothing is written or sent.
	 */
	public function test_dry_run_lines() {
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$future = Records::create(
			'barn_task',
			array(
				'name'   => 'A',
				'due_on' => '2027-03-20',
			),
			'publish'
		);
		$empty  = Records::create( 'barn_task', array( 'name' => 'B' ), 'publish' );
		$past   = Records::create(
			'barn_task',
			array(
				'name'   => 'C',
				'due_on' => '2027-03-02',
			),
			'publish'
		);
		$runs   = count( $this->runs( self::KEY ) );
		$sent   = 0;
		$count  = function ( $value ) use ( &$sent ) {
			++$sent;
			return $value;
		};
		add_filter( 'pre_wp_mail', $count );

		$lines = DryRun::result( self::KEY, $future, null )['lines'];
		$this->assertContains( 'Wait before_due: until 3 days before Due on (March 20, 2027) at 09:00, that is March 17, 2027 9:00 am. When it wakes it checks the date again. The test continues as if it had passed.', $lines );
		$lines = DryRun::result( self::KEY, $empty, null )['lines'];
		$this->assertContains( 'Wait before_due: Due on is empty: it would wait for a date (up to 2 years). The test continues as if it had passed.', $lines );
		$lines = DryRun::result( self::KEY, $past, null )['lines'];
		$this->assertContains( 'Wait before_due: until 3 days before Due on (March 2, 2027) at 09:00. That time has passed: it continues at once.', $lines );

		remove_filter( 'pre_wp_mail', $count );
		$this->assertSame( 0, $sent, 'No mail.' );
		$this->assertCount( $runs, $this->runs( self::KEY ), 'No run rows.' );
		$this->assertSame( '', (string) Records::get( $future )['values']['reminded_on'], 'No write.' );
	}
}
