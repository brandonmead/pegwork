<?php
/**
 * Integration tests: the engine on real WP-Cron.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
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
		$this->assertCount( 1, $this->steps( $run['id'] ) );

		// job_verified was queued by the stamp; cron picks it up too.
		$this->run_due_cron( Engine::RUN_HOOK );
		$this->assertSame( 'completed', $this->only_run( 'job_verified' )['status'] );
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
