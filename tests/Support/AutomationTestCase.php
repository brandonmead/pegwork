<?php
/**
 * Base class for automation module integration tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Engine;
use PegworkAutomation\Schema;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/modules/automation/module.php'; // Registers the module autoloader.
require_once __DIR__ . '/FakeScheduler.php';

/**
 * Registers tests/fixtures/valid/engine, installs the tables once per class, and boots the module with a
 * frozen clock and the fake scheduler (subclasses may boot it differently).
 */
abstract class AutomationTestCase extends WP_UnitTestCase {

	/**
	 * The module object returned by module.php.
	 *
	 * @var \Pegwork\Api\Module
	 */
	protected static $module;

	/**
	 * Frozen clock, 2026-11-02 09:00 site time (UTC).
	 *
	 * @var FrozenClock
	 */
	protected $clock;

	/**
	 * Fake scheduler.
	 *
	 * @var FakeScheduler
	 */
	protected $scheduler;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor;

	/**
	 * Loads the module classes and creates the tables (DDL outside the per-test transaction).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		self::$module = require dirname( __DIR__, 2 ) . '/modules/automation/module.php';
		Schema::install();
	}

	/**
	 * Registers the fixture package and boots the module.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( $this->package() ) );
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

		$this->boot();
	}

	/**
	 * Package directory the test registers (default: the engine fixture).
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__ ) . '/fixtures/valid/engine';
	}

	/**
	 * Boots the module with the frozen clock and the fake scheduler.
	 */
	protected function boot() {
		$this->clock     = new FrozenClock( '2026-11-02 09:00' );
		$this->scheduler = new FakeScheduler();
		Engine::use( $this->clock, $this->scheduler );
		self::$module->boot();
	}

	/**
	 * Drops the engine and the fixture registry.
	 */
	public function tear_down() {
		Engine::reset();
		parent::tear_down();
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * Runs of an automation, oldest first.
	 *
	 * @param string $automation Automation key.
	 * @return array<int, array<string, mixed>>
	 */
	protected function runs( $automation ) {
		return array_reverse( Engine::instance()->runs()->find( $automation, null, 1000 ) );
	}

	/**
	 * The only run of an automation.
	 *
	 * @param string $automation Automation key.
	 * @return array<string, mixed>
	 */
	protected function only_run( $automation ) {
		$runs = $this->runs( $automation );
		$this->assertCount( 1, $runs, "Exactly one $automation run expected." );
		return $runs[0];
	}

	/**
	 * Re-reads a run.
	 *
	 * @param int $id Run ID.
	 * @return array<string, mixed>
	 */
	protected function run_row( $id ) {
		return Engine::instance()->runs()->get( $id );
	}

	/**
	 * Step rows of a run.
	 *
	 * @param int $id Run ID.
	 * @return array<int, array<string, mixed>>
	 */
	protected function steps( $id ) {
		return Engine::instance()->runs()->steps( $id );
	}

	/**
	 * Fires every due run wake-up at the clock's time.
	 */
	protected function drain() {
		return $this->scheduler->drain( $this->clock->now()->getTimestamp() );
	}

	/**
	 * Creates a job post (emits record.created).
	 *
	 * @param string $status Post status.
	 * @param string $title  Title.
	 * @return int Post ID.
	 */
	protected function job( $status = 'pending', $title = 'A job' ) {
		return self::factory()->post->create(
			array(
				'post_type'   => 'job',
				'post_status' => $status,
				'post_title'  => $title,
			)
		);
	}

	/**
	 * A field value of a job, as Records reads it.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Field or taxonomy key.
	 * @return mixed
	 */
	protected function value( $id, $key ) {
		return \Pegwork\Api\Records::get( $id )['values'][ $key ];
	}
}
