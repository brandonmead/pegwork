<?php
/**
 * Integration tests: the automation module through the module loader.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Modules\Loader;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Disabled, the module does nothing; enabled through the loader, it boots and runs.
 */
class AutomationModuleTest extends AutomationTestCase {

	/**
	 * Does not boot the module: each test decides.
	 */
	protected function boot() {
		remove_action( 'pegwork_event', array( Engine::class, 'on_event' ) );
		remove_action( Engine::RUN_HOOK, array( Engine::class, 'on_run' ) );
		wp_unschedule_hook( Engine::RUN_HOOK );
	}

	/**
	 * Acceptance: with the module disabled, an approval creates no run and schedules nothing.
	 */
	public function test_disabled_module_does_nothing() {
		$this->assertArrayHasKey( 'automation', Loader::manifest() );
		$this->assertNotContains( 'automation', Loader::enabled() );

		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( array(), $this->runs( 'job_approved' ) );
		$this->assertFalse( wp_next_scheduled( Engine::RUN_HOOK, array( 1 ) ) );
		$this->assertFalse( has_action( 'pegwork_event', array( Engine::class, 'on_event' ) ) );
	}

	/**
	 * Enabled through the loader, the module boots on pegwork_loaded and listens.
	 */
	public function test_enabled_module_boots_through_the_loader() {
		$this->assertTrue( Loader::enable( 'automation' ) );
		$this->assertContains( 'automation', Loader::load() );
		$this->assertSame( array( 'automation' ), Loader::boot() );

		$this->assertNotFalse( has_action( 'pegwork_event', array( Engine::class, 'on_event' ) ) );
		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$run = $this->only_run( 'job_approved' );
		$this->assertNotFalse( wp_next_scheduled( Engine::RUN_HOOK, array( $run['id'] ) ) );
		wp_unschedule_hook( Engine::RUN_HOOK );
		wp_unschedule_hook( Engine::MAINTENANCE_HOOK );
	}
}
