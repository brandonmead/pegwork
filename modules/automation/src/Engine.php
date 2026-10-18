<?php
/**
 * Engine wiring.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use PegworkAutomation\Clock\Clock;
use PegworkAutomation\Clock\SystemClock;
use PegworkAutomation\Scheduler\Scheduler;
use PegworkAutomation\Scheduler\WpCronScheduler;
use PegworkAutomation\Steps\Condition;
use PegworkAutomation\Steps\CreateRecord;
use PegworkAutomation\Steps\Step;
use PegworkAutomation\Steps\UpdateRecord;

/**
 * Holds the clock, scheduler, run storage, triggers, executor, maintenance and step handlers for the
 * request. Hooks call the static entry points, which use the current instance, so tests (and later the
 * CLI `--now` flag) can swap the clock and scheduler with Engine::use().
 */
final class Engine {

	/**
	 * Action fired by the scheduler to execute a run; argument: run ID.
	 */
	public const RUN_HOOK = 'pegwork_automation_run';

	/**
	 * Action fired by the scheduler for the daily maintenance job.
	 */
	public const MAINTENANCE_HOOK = 'pegwork_automation_maintenance';

	/**
	 * Current instance.
	 *
	 * @var Engine|null
	 */
	private static ?Engine $instance = null;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Run storage.
	 *
	 * @var Runs
	 */
	private Runs $runs;

	/**
	 * Step handlers by type.
	 *
	 * @var array<string, Step>
	 */
	private array $steps;

	/**
	 * Builds an engine.
	 *
	 * @param Clock     $clock     Clock.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public function __construct( Clock $clock, Scheduler $scheduler ) {
		$this->clock     = $clock;
		$this->scheduler = $scheduler;
		$this->runs      = new Runs( $clock );
		$this->steps     = array(
			'update_record' => new UpdateRecord(),
			'create_record' => new CreateRecord(),
			'condition'     => new Condition(),
		);
	}

	/**
	 * The current engine: system clock and WP-Cron.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self( new SystemClock(), new WpCronScheduler() );
		}
		return self::$instance;
	}

	/**
	 * Replaces the current engine's clock and scheduler (tests; later the CLI `--now` flag).
	 *
	 * @param Clock     $clock     Clock.
	 * @param Scheduler $scheduler Scheduler.
	 */
	public static function use( Clock $clock, Scheduler $scheduler ): self {
		self::$instance = new self( $clock, $scheduler );
		return self::$instance;
	}

	/**
	 * Drops the current engine; the next instance() builds the default one.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Registers the hooks. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'pegwork_event', array( self::class, 'on_event' ) ) ) {
			add_action( 'pegwork_event', array( self::class, 'on_event' ), 10, 2 );
		}
		if ( ! has_action( self::RUN_HOOK, array( self::class, 'on_run' ) ) ) {
			add_action( self::RUN_HOOK, array( self::class, 'on_run' ) );
		}
		if ( ! has_action( self::MAINTENANCE_HOOK, array( self::class, 'on_maintenance' ) ) ) {
			add_action( self::MAINTENANCE_HOOK, array( self::class, 'on_maintenance' ) );
		}
		self::instance()->maintenance()->ensure_scheduled();
	}

	/**
	 * Bus listener. Hooked to pegwork_event.
	 *
	 * @param string $name    Event name.
	 * @param mixed  $payload Event payload.
	 */
	public static function on_event( $name, $payload ): void {
		if ( is_string( $name ) && is_array( $payload ) ) {
			self::instance()->triggers()->handle( $name, $payload );
		}
	}

	/**
	 * Run wake-up. Hooked to pegwork_automation_run.
	 *
	 * @param mixed $run_id Run ID.
	 */
	public static function on_run( $run_id ): void {
		if ( is_numeric( $run_id ) ) {
			self::instance()->executor()->execute( (int) $run_id );
		}
	}

	/**
	 * Maintenance wake-up. Hooked to pegwork_automation_maintenance.
	 */
	public static function on_maintenance(): void {
		self::instance()->maintenance()->run();
	}

	/**
	 * Clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Scheduler.
	 */
	public function scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Run storage.
	 */
	public function runs(): Runs {
		return $this->runs;
	}

	/**
	 * Event triggers.
	 */
	public function triggers(): Triggers {
		return new Triggers( $this );
	}

	/**
	 * Executor.
	 */
	public function executor(): Executor {
		return new Executor( $this );
	}

	/**
	 * Maintenance job.
	 */
	public function maintenance(): Maintenance {
		return new Maintenance( $this );
	}

	/**
	 * Handler of a step type.
	 *
	 * @param string $type Step type.
	 */
	public function step( string $type ): ?Step {
		return $this->steps[ $type ] ?? null;
	}
}
