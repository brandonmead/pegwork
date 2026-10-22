<?php
/**
 * Integration tests: scheduling health (Site Health test, admin notice, cron tick).
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The notice test drives core's admin page globals.

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Health;

use const Pegwork\Api\ADMIN_MENU;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Overdue runs, a missing or late maintenance job and DISABLE_WP_CRON without a recent cron run flag
 * scheduling as unhealthy; the notice shows only to managers on Pegwork screens. The WP test suite
 * defines DISABLE_WP_CRON, so every test runs in that configuration.
 */
class AutomationHealthTest extends AutomationTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * An administrator, a fresh tick, and no admin page.
	 */
	public function set_up() {
		parent::set_up();
		$this->assertTrue( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'The WP test suite defines DISABLE_WP_CRON.' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$this->tick();
		unset( $GLOBALS['plugin_page'] );
		$GLOBALS['submenu'] = array();
	}

	/**
	 * Clears the admin page globals.
	 */
	public function tear_down() {
		unset( $GLOBALS['plugin_page'] );
		$GLOBALS['submenu'] = array();
		parent::tear_down();
	}

	/**
	 * Health for the current engine.
	 *
	 * @return Health
	 */
	private function health() {
		return new Health( Engine::instance() );
	}

	/**
	 * Stores a cron tick at the clock's time minus some seconds.
	 *
	 * @param int $ago Seconds before now.
	 */
	private function tick( $ago = 0 ) {
		update_option( Health::TICK_OPTION, $this->clock->now()->getTimestamp() - $ago, false );
	}

	/**
	 * A queued run of job_digest at the current clock time.
	 *
	 * @return int Run ID.
	 */
	private function queued_run() {
		return Engine::instance()->runs()->insert(
			array(
				'automation'      => 'job_digest',
				'trigger_type'    => 'schedule',
				'status'          => 'queued',
				'depth'           => 0,
				'definition_hash' => '',
				'context'         => array(),
			)
		);
	}

	/**
	 * Codes of the current problems.
	 *
	 * @return string[]
	 */
	private function codes() {
		return array_keys( $this->health()->problems() );
	}

	/**
	 * Prints the notice as on an admin page.
	 *
	 * @param string|null $page Plugin page slug, or null for a core screen.
	 * @return string HTML.
	 */
	private function notice( $page ) {
		$GLOBALS['submenu'][ ADMIN_MENU ] = array(
			array( 'Overview', 'manage_options', ADMIN_MENU ),
			array( 'Automations', 'manage_options', 'pegwork-automations' ),
		);
		$GLOBALS['plugin_page']           = $page;
		ob_start();
		Health::notice();
		return (string) ob_get_clean();
	}

	/**
	 * With a recent tick, nothing overdue and the maintenance job pending, the test passes.
	 */
	public function test_healthy_state_passes() {
		$this->tick( 4 * MINUTE_IN_SECONDS );
		$this->assertSame( array(), $this->health()->problems() );

		$result = $this->health()->result();
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Health::TEST, $result['test'] );
		$this->assertSame( 'Pegwork automations are running on time', $result['label'] );
		$this->assertStringContainsString( 'the last run was 4 minutes ago', $result['description'] );
		$this->assertSame( '', $result['actions'] );
	}

	/**
	 * The test is registered as a direct Site Health test whose callback returns the result.
	 */
	public function test_registered_as_direct_site_health_test() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, applied as Site Health does.
		$this->assertArrayHasKey( Health::TEST, $tests['direct'] );
		$test = $tests['direct'][ Health::TEST ];
		$this->assertTrue( is_callable( $test['test'] ) );
		$this->assertSame( 'good', call_user_func( $test['test'] )['status'] );
	}

	/**
	 * A waiting run more than 15 minutes past resume_at, or a queued run idle that long, is overdue.
	 */
	public function test_overdue_run_flags_unhealthy() {
		$runs = Engine::instance()->runs();
		$id   = $this->queued_run();
		$runs->wait( $id, 'step', $this->clock->now()->modify( '-14 minutes' ), array() );
		$this->assertSame( array(), $this->codes(), 'A waiting run 14 minutes past due is not yet overdue.' );

		$runs->wait( $id, 'step', $this->clock->now()->modify( '-3 hours' ), array() );
		$problems = $this->health()->problems();
		$this->assertSame( array( 'overdue' ), array_keys( $problems ) );
		$this->assertSame( 'critical', $problems['overdue']['status'] );
		$this->assertSame( '1 automation run is overdue; the oldest was due 3 hours ago.', $problems['overdue']['message'] );

		$result = $this->health()->result();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'Pegwork automations are not running on time', $result['label'] );
		$this->assertStringContainsString( '<li>1 automation run is overdue', $result['description'] );
		$this->assertStringContainsString( 'wp cron event run --due-now', $result['actions'] );
		$this->assertStringContainsString( 'page=pegwork-automations&#038;view=log', $result['actions'] );

		// A queued run counts from creation (or its last claim).
		$this->queued_run();
		$this->clock->advance( 'PT16M' );
		$this->tick();
		$this->assertSame( 2, $runs->overdue( $runs->stamp( $this->clock->now()->modify( '-15 minutes' ) ) )['count'] );
		$this->assertStringStartsWith( '2 automation runs are overdue', $this->health()->problems()['overdue']['message'] );

		// Finished runs never count.
		$runs->finish( $id, 'completed' );
		$this->assertSame( 1, $runs->overdue( $runs->stamp( $this->clock->now()->modify( '-15 minutes' ) ) )['count'] );
	}

	/**
	 * A missing maintenance event, or one not run for over 26 hours, flags unhealthy (recommended).
	 */
	public function test_missing_or_late_maintenance_flags_unhealthy() {
		$this->scheduler->unschedule( Engine::MAINTENANCE_HOOK, array() );
		$problems = $this->health()->problems();
		$this->assertSame( array( 'maintenance_missing' ), array_keys( $problems ) );
		$this->assertSame( 'recommended', $problems['maintenance_missing']['status'] );
		$this->assertSame( 'recommended', $this->health()->result()['status'] );

		Engine::instance()->maintenance()->ensure_scheduled(); // Due 2026-11-03 03:00.
		$this->assertSame( array(), $this->codes() );

		$this->clock->set( '2026-11-03 04:59' ); // 25 h 59 min after the previous slot.
		$this->tick();
		$this->assertSame( array(), $this->codes() );

		$this->clock->set( '2026-11-03 05:01' );
		$this->tick();
		$problems = $this->health()->problems();
		$this->assertSame( array( 'maintenance_late' ), array_keys( $problems ) );
		$this->assertSame( 'The daily maintenance job has not run for over 26 hours; it was due 2 hours ago.', $problems['maintenance_late']['message'] );

		// Running it moves it to the next day, which is healthy again.
		Engine::instance()->maintenance()->run();
		$this->assertSame( array(), $this->codes() );
	}

	/**
	 * With DISABLE_WP_CRON, no tick or a tick over two hours old flags unhealthy (no server cron).
	 */
	public function test_disabled_wp_cron_without_recent_tick_flags_unhealthy() {
		delete_option( Health::TICK_OPTION );
		$problems = $this->health()->problems();
		$this->assertSame( array( 'no_cron' ), array_keys( $problems ) );
		$this->assertSame( 'critical', $problems['no_cron']['status'] );
		$this->assertStringContainsString( 'no cron run has been seen', $problems['no_cron']['message'] );

		$this->tick( 3 * HOUR_IN_SECONDS );
		$this->assertSame( 'DISABLE_WP_CRON is set, but the last cron run was 3 hours ago, so the server cron seems to have stopped.', $this->health()->problems()['no_cron']['message'] );

		$this->tick( 90 * MINUTE_IN_SECONDS );
		$this->assertSame( array(), $this->codes() );
	}

	/**
	 * Cron processes record the tick at shutdown, at most once a minute, autoload off; other requests never write.
	 */
	public function test_tick_recorded_in_cron_at_most_once_a_minute() {
		delete_option( Health::TICK_OPTION );
		Health::on_shutdown();
		$this->assertFalse( get_option( Health::TICK_OPTION ), 'Not a cron process.' );

		add_filter( 'wp_doing_cron', '__return_true' );
		$start = $this->clock->now()->getTimestamp();
		Health::on_shutdown();
		$this->assertSame( $start, (int) get_option( Health::TICK_OPTION ) );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Health::TICK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( $autoload, array( 'off', 'no' ) );

		$this->clock->advance( 'PT59S' );
		$this->assertFalse( $this->health()->record_tick(), 'Within a minute: no write.' );
		$this->assertSame( $start, (int) get_option( Health::TICK_OPTION ) );

		$this->clock->advance( 'PT2S' );
		Health::on_shutdown();
		$this->assertSame( $start + 61, (int) get_option( Health::TICK_OPTION ) );
	}

	/**
	 * The notice shows once on Pegwork screens to users who can manage options, and nowhere else.
	 */
	public function test_notice_only_for_managers_on_pegwork_screens() {
		$this->assertSame( '', $this->notice( 'pegwork-automations' ), 'Healthy: no notice.' );

		delete_option( Health::TICK_OPTION );
		$html = $this->notice( 'pegwork-automations' );
		$this->assertSame( 1, substr_count( $html, 'pegwork-health-notice' ) );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Pegwork automations are not running on time.', $html );
		$this->assertStringContainsString( 'no cron run has been seen', $html );
		$this->assertStringContainsString( 'site-health.php', $html );
		$this->assertStringContainsString( 'pegwork-health-notice', $this->notice( ADMIN_MENU ), 'Overview.' );

		$this->assertSame( '', $this->notice( null ), 'Core screen.' );
		$this->assertSame( '', $this->notice( 'other-plugin' ), 'Another plugin page.' );

		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( '', $this->notice( 'pegwork-automations' ), $role );
		}

		// A manager who cannot see Site Health gets the fix inline instead of the link.
		wp_set_current_user( $this->admin );
		$deny = static function ( $caps, $cap ) {
			return 'view_site_health_checks' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$html = $this->notice( 'pegwork-automations' );
		remove_filter( 'map_meta_cap', $deny, 10 );
		$this->assertStringNotContainsString( 'site-health.php', $html );
		$this->assertStringContainsString( 'wp cron event run --due-now', $html );

		// Recommended-only problems use the warning style.
		$this->tick();
		$this->scheduler->unschedule( Engine::MAINTENANCE_HOOK, array() );
		$this->assertStringContainsString( 'notice-warning', $this->notice( 'pegwork-automations' ) );
	}
}
