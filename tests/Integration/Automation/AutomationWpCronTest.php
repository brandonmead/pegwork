<?php
/**
 * Integration tests: the engine on real WP-Cron.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Schema;
use PegworkAutomation\Scheduler\WpCronScheduler;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Fires the module's (prefixed) cron hooks as wp-cron.php does.

/**
 * The default engine (system clock, WpCronScheduler) end to end: the save schedules a WP-Cron event, and
 * firing due events the way wp-cron.php does executes the run.
 */
class AutomationWpCronTest extends AutomationTestCase {

	/**
	 * Boots the default engine instead of the fake one.
	 */
	protected function boot() {
		Engine::reset();
		self::$module->boot();
	}

	/**
	 * Clears the module's cron events.
	 */
	public function tear_down() {
		wp_unschedule_hook( Engine::RUN_HOOK );
		wp_unschedule_hook( Engine::MAINTENANCE_HOOK );
		wp_unschedule_hook( Engine::SCHEDULE_HOOK );
		wp_unschedule_hook( Engine::SWEEP_HOOK );
		parent::tear_down();
	}

	/**
	 * Runs due events of a hook as wp-cron.php does: unschedule, then fire.
	 *
	 * @param string $hook Hook.
	 * @return int Events fired.
	 */
	private function run_due_cron( $hook ) {
		$fired = 0;
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( $timestamp > time() || ! isset( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( $hooks[ $hook ] as $event ) {
				wp_unschedule_event( $timestamp, $hook, $event['args'] );
				do_action_ref_array( $hook, $event['args'] );
				++$fired;
			}
		}
		return $fired;
	}

	/**
	 * Real-scheduler acceptance: approval through WP-Cron, and a duplicate cron firing does nothing.
	 */
	public function test_approval_runs_on_wp_cron() {
		$this->assertNotFalse( wp_next_scheduled( Engine::MAINTENANCE_HOOK ), 'Boot schedules maintenance.' );

		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$run = $this->only_run( 'job_approved' );
		$at  = wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) );
		$this->assertNotFalse( $at );
		$this->assertLessThanOrEqual( time(), $at );
		$this->assertNotFalse( has_action( 'shutdown', array( Engine::instance()->scheduler(), 'spawn' ) ), 'A due wake-up spawns WP-Cron at shutdown.' );

		$this->assertGreaterThanOrEqual( 1, $this->run_due_cron( Engine::RUN_HOOK ) );
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
		$this->assertSame( current_time( 'Y-m-d' ), $this->value( $id, 'last_verified' ) );

		do_action( Engine::RUN_HOOK, $run['id'] );
		$this->assertCount( 2, $this->steps( $run['id'] ), 'The duplicate firing ran no step again.' );

		// job_verified was queued by the stamp; cron picks it up too.
		$this->run_due_cron( Engine::RUN_HOOK );
		$this->assertSame( 'completed', $this->only_run( 'job_verified' )['status'] );
	}

	/**
	 * Schedule trigger on real WP-Cron: boot schedules the firing at 09:00 site time; when it fires it
	 * reschedules itself, sweeps, and the run sends its email and waits on a WP-Cron event 30 days out.
	 */
	public function test_schedule_fires_on_wp_cron() {
		reset_phpmailer_instance();
		$next = wp_next_scheduled( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) );
		$this->assertNotFalse( $next );
		$this->assertSame( '09:00', wp_date( 'H:i', $next ) );
		$this->assertGreaterThan( time(), $next );

		$id = \Pegwork\Api\Records::create(
			'job',
			array(
				'title'         => 'Stale job',
				'last_verified' => wp_date( 'Y-m-d', strtotime( '-2 years' ) ),
				'contact_email' => 'owner@example.org',
			),
			'publish'
		);
		$this->run_due_cron( Engine::RUN_HOOK );

		// Make the firing due, as if 09:00 had come, and run cron as wp-cron.php would.
		( new WpCronScheduler() )->schedule( Engine::SCHEDULE_HOOK, array( 'job_sweep' ), time() - 1 );
		$this->assertSame( 1, $this->run_due_cron( Engine::SCHEDULE_HOOK ) );
		$this->assertSame( $next, wp_next_scheduled( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ), 'Rescheduled for the next 09:00.' );

		$run = $this->only_run( 'job_sweep' );
		$this->assertSame( $id, $run['record_id'] );
		$this->run_due_cron( Engine::RUN_HOOK );

		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'owner@example.org', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
		$resume = wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) );
		$this->assertSame( strtotime( $run['resume_at'] . ' UTC' ), $resume );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, $resume, HOUR_IN_SECONDS + 60, 'About 30 days (DST may shift it by an hour).' );
	}

	/**
	 * A wait inside a decision's branch on real WP-Cron: the resume event continues inside the branch, a wait
	 * last in the branch continues after the decision, and the decision runs once.
	 */
	public function test_wait_inside_a_branch_resumes_on_wp_cron() {
		global $wpdb;
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/branching' ) );
		Plugin::init();
		$this->assertNotNull( pegwork_registry()->automation( 'br_wait' ) );

		// Makes the run's wait due now, as if its time had come, and runs cron as wp-cron.php would.
		$resume = function ( $id ) use ( $wpdb ) {
			$wpdb->update( Schema::runs(), array( 'resume_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test moves the clock of one run.
			( new WpCronScheduler() )->schedule( Engine::RUN_HOOK, array( $id ), time() - 1 );
			$this->assertGreaterThanOrEqual( 1, $this->run_due_cron( Engine::RUN_HOOK ) );
			return $this->run_row( $id );
		};

		\Pegwork\Api\Records::create(
			'help_request',
			array(
				'name'     => 'Ada',
				'email'    => 'ada@example.org',
				'scenario' => 'wait',
			),
			'publish'
		);
		$this->run_due_cron( Engine::RUN_HOOK );
		$run = $this->only_run( 'br_wait' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'check', $run['current_step'] );
		$this->assertSame( strtotime( $run['resume_at'] . ' UTC' ), wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) ) );

		$run = $resume( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'still_new', $run['current_step'], 'Resumes inside the branch.' );
		$this->assertSame( strtotime( $run['resume_at'] . ' UTC' ), wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) ) );

		$run = $resume( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'wrap', $run['current_step'], 'The wait last in the branch resumes after the decision.' );

		$run = $resume( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'hold', 'check', 'remind', 'hold_2', 'still_new', 'escalate', 'hold_3', 'wrap' ), array_column( $this->steps( $run['id'] ), 'step_id' ) );
	}

	/**
	 * A loop on real WP-Cron with a zero time budget: each wake-up runs one step and re-queues the run as a
	 * new WP-Cron event, mid-loop included (`current_step` inside the body, `context.loops` stored); firing
	 * the events as wp-cron.php does resumes each time at the same item and finishes the loop once.
	 */
	public function test_loop_resumes_after_a_time_budget_requeue_on_wp_cron() {
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/engine-loops' ) );
		Plugin::init();
		$this->assertNotNull( pegwork_registry()->automation( 'lp_items' ) );
		update_option( 'barn_settings', array( 'development_email' => 'development@example.org' ) );
		reset_phpmailer_instance();
		add_filter( 'pegwork_automation_time_budget', fn() => 0 );

		$tasks = array();
		foreach ( array( 'Ada', 'Bo', 'Cy' ) as $name ) {
			$tasks[] = \Pegwork\Api\Records::create(
				'barn_task',
				array(
					'name'           => $name,
					'due_on'         => '2026-11-04',
					'assignee_email' => strtolower( $name ) . '@example.org',
				),
				'publish'
			);
		}
		$id = Engine::instance()->triggers()->webhook( 'lp_items', pegwork_registry()->automation( 'lp_items' ), array(), null );
		$this->assertGreaterThan( 0, $id );

		$requeued = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$this->assertNotFalse( wp_next_scheduled( Engine::RUN_HOOK, array( $id ) ), 'Each requeue is a WP-Cron event.' );
			$this->assertSame( 1, $this->run_due_cron( Engine::RUN_HOOK ) );
			$run = $this->run_row( $id );
			if ( 'completed' === $run['status'] ) {
				break;
			}
			$this->assertSame( 'queued', $run['status'] );
			if ( isset( $run['context']['loops']['each'] ) ) {
				$requeued[] = $run['current_step'] . '@' . $run['context']['loops']['each']['index'];
			}
		}

		$this->assertSame( 'completed', $run['status'] );
		$this->assertContains( 'mail@1', $requeued, 'Requeued mid-loop at the second item.' );
		$this->assertContains( 'each@2', $requeued, 'Requeued at the loop between items.' );
		$this->assertSame( array_unique( $requeued ), $requeued, 'Never the same position twice.' );
		$this->assertArrayNotHasKey( 'loops', $run['context'] );
		$this->assertSame(
			array( 'ada@example.org', 'bo@example.org', 'cy@example.org', 'development@example.org' ),
			array_map( fn( $mail ) => $mail['to'][0][0], tests_retrieve_phpmailer_instance()->mock_sent ),
			'Each item emailed once, in order.'
		);
		$this->assertSame( $tasks, $this->steps( $id )[1]['summary']['ids'] );
		$this->assertSame( array( 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3 ), array_values( array_filter( array_column( $this->steps( $id ), 'iteration' ) ) ) );
	}

	/**
	 * A wait until a date on real WP-Cron (Automation v4 PR 3): the run waits on a WP-Cron event at the
	 * target; moving the date earlier moves that event to now (Waits::nudge), and firing it as wp-cron.php
	 * does decides again and sends the reminder.
	 */
	public function test_date_wait_wakes_on_wp_cron() {
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/v4' ) );
		Plugin::init();
		$this->assertNotNull( pegwork_registry()->automation( 'barn_task_due_reminder' ) );
		reset_phpmailer_instance();

		$task = \Pegwork\Api\Records::create(
			'barn_task',
			array(
				'name'           => 'Call the barn',
				'due_on'         => wp_date( 'Y-m-d', strtotime( '+30 days' ) ),
				'assignee_email' => 'staff@example.org',
			),
			'publish'
		);
		$this->run_due_cron( Engine::RUN_HOOK );
		$run = $this->only_run( 'barn_task_due_reminder' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'before_due', $run['current_step'] );
		$at = wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) );
		$this->assertSame( strtotime( $run['resume_at'] . ' UTC' ), $at );
		$this->assertGreaterThan( time() + 25 * DAY_IN_SECONDS, $at, 'About 27 days out.' );
		$this->assertSame( 0, $this->run_due_cron( Engine::RUN_HOOK ), 'Nothing is due yet.' );

		// Due in two days: three days before has passed, so the nudged wake-up reminds at once.
		\Pegwork\Api\Records::update( $task, array( 'due_on' => wp_date( 'Y-m-d', strtotime( '+2 days' ) ) ) );
		$at = wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) );
		$this->assertNotFalse( $at );
		$this->assertLessThanOrEqual( time(), $at, 'The wake-up moved to now.' );
		$this->assertSame( 'waiting', $this->run_row( $run['id'] )['status'], 'No step ran in the saving request.' );

		$this->assertGreaterThanOrEqual( 1, $this->run_due_cron( Engine::RUN_HOOK ) );
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
		$this->assertSame( 'staff@example.org', tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
		$this->assertFalse( wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) ), 'No wake-up left.' );
	}

	/**
	 * The scheduler keeps one pending event per hook and args, and spawn honours the filter.
	 */
	public function test_wp_cron_scheduler() {
		$scheduler = new WpCronScheduler();
		$this->assertTrue( $scheduler->schedule( 'pegwork_automation_run', array( 99 ), time() + 600 ) );
		$this->assertTrue( $scheduler->schedule( 'pegwork_automation_run', array( 99 ), time() + 60 ) );
		$this->assertSame( time() + 60, $scheduler->next( 'pegwork_automation_run', array( 99 ) ) );
		$this->assertNull( $scheduler->next( 'pegwork_automation_run', array( 98 ) ) );

		$scheduler->unschedule( 'pegwork_automation_run', array( 99 ) );
		$this->assertNull( $scheduler->next( 'pegwork_automation_run', array( 99 ) ) );

		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$requests ) {
				$requests[] = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			},
			10,
			3
		);
		$scheduler->schedule( 'pegwork_automation_run', array( 97 ), time() - 1 );

		add_filter( 'pegwork_automation_spawn_cron', '__return_false' );
		$scheduler->spawn();
		$this->assertSame( array(), $requests );

		// The WP test suite defines DISABLE_WP_CRON (a system cron runs events), so there is no loopback either.
		// The spawning path is checked on a real site (docs/automation.md, local check).
		remove_filter( 'pegwork_automation_spawn_cron', '__return_false' );
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$scheduler->spawn();
		$this->assertSame( array(), $requests );
	}
}
