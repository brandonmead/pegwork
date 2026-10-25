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
use PegworkAutomation\Steps\CallWebhook;
use PegworkAutomation\Steps\Condition;
use PegworkAutomation\Steps\CreateRecord;
use PegworkAutomation\Steps\Decision;
use PegworkAutomation\Steps\FindRecords;
use PegworkAutomation\Steps\GetRecord;
use PegworkAutomation\Steps\Relate;
use PegworkAutomation\Steps\SendEmail;
use PegworkAutomation\Steps\StartAutomation;
use PegworkAutomation\Steps\Step;
use PegworkAutomation\Steps\UpdateRecord;
use PegworkAutomation\Steps\Wait;

/**
 * Holds the clock, scheduler, run storage, triggers, schedules, executor, maintenance and step handlers for
 * the request. Hooks call the static entry points, which use the current instance, so tests and the CLI
 * `--now` and `--sync` flags can swap the clock and scheduler with Engine::use().
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
	 * Action fired by the scheduler for a schedule trigger; argument: automation key.
	 */
	public const SCHEDULE_HOOK = 'pegwork_automation_schedule';

	/**
	 * Action fired to continue a sweep that used up its time budget; arguments: key, last ID, runs created.
	 */
	public const SWEEP_HOOK = 'pegwork_automation_sweep';

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
			'update_record'    => new UpdateRecord(),
			'create_record'    => new CreateRecord(),
			'condition'        => new Condition(),
			'send_email'       => new SendEmail(),
			'call_webhook'     => new CallWebhook(),
			'wait'             => new Wait(),
			'decision'         => new Decision(),
			'get_record'       => new GetRecord(),
			'find_records'     => new FindRecords(),
			'relate'           => new Relate(),
			'start_automation' => new StartAutomation(),
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
	 * Replaces the current engine's clock and scheduler (tests, CLI `--now` and `--sync`).
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
	 * Registers the hooks and makes sure maintenance and schedule firings are pending. Safe to call more than once.
	 */
	public static function register(): void {
		$hooks = array(
			'pegwork_event'        => array( 'on_event', 2 ),
			self::RUN_HOOK         => array( 'on_run', 1 ),
			self::MAINTENANCE_HOOK => array( 'on_maintenance', 0 ),
			self::SCHEDULE_HOOK    => array( 'on_schedule', 1 ),
			self::SWEEP_HOOK       => array( 'on_sweep', 3 ),
		);
		foreach ( $hooks as $hook => [ $method, $args ] ) {
			if ( ! has_action( $hook, array( self::class, $method ) ) ) {
				add_action( $hook, array( self::class, $method ), 10, max( 1, $args ) );
			}
		}
		self::instance()->maintenance()->ensure_scheduled();
		self::instance()->schedules()->ensure();
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
	 * Schedule firing. Hooked to pegwork_automation_schedule.
	 *
	 * @param mixed $key Automation key.
	 */
	public static function on_schedule( $key ): void {
		if ( is_string( $key ) ) {
			self::instance()->schedules()->fire( $key );
		}
	}

	/**
	 * Sweep continuation. Hooked to pegwork_automation_sweep.
	 *
	 * @param mixed $key     Automation key.
	 * @param mixed $after   Last record ID swept.
	 * @param mixed $created Runs created so far.
	 */
	public static function on_sweep( $key, $after = 0, $created = 0 ): void {
		if ( is_string( $key ) && is_numeric( $after ) && is_numeric( $created ) ) {
			self::instance()->schedules()->resume( $key, (int) $after, (int) $created );
		}
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
	 * Schedule triggers.
	 */
	public function schedules(): Schedules {
		return new Schedules( $this );
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
	 * Signed links: verification and link runs.
	 */
	public function links(): Links\Links {
		return new Links\Links( $this );
	}

	/**
	 * Handler of a step type.
	 *
	 * @param string $type Step type.
	 */
	public function step( string $type ): ?Step {
		return $this->steps[ $type ] ?? null;
	}

	/**
	 * Why this module version cannot run a definition, or null when it can. The registry accepts shapes before
	 * the engine runs them all, so the executor refuses such a run before its first step instead of acting on
	 * part of it: a step type without a handler, at any depth (`loop` has none because the executor's cursor
	 * runs it). Until the Automation v4 engine PRs land (docs/automation-v4-design.md section 11), also the v4
	 * shapes of existing step types: a wait `until` a date, a `{role:<slug>}` recipient, and a read `created`.
	 * The new step type trash_record has no handler yet, so the first rule refuses it; start_automation runs
	 * (v4 PR 5).
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public function unsupported( array $definition ): ?string {
		$lists = array( (array) ( $definition['steps'] ?? array() ) );
		foreach ( (array) ( $definition['links'] ?? array() ) as $link ) {
			$lists[] = (array) ( $link['steps'] ?? array() );
		}
		foreach ( $lists as $steps ) {
			$reason = $this->unsupported_steps( $steps );
			if ( null !== $reason ) {
				return $reason;
			}
		}
		return null;
	}

	/**
	 * Why a step list (with its branches and loop bodies) cannot run, or null.
	 *
	 * @param array<int, mixed> $steps Steps.
	 */
	private function unsupported_steps( array $steps ): ?string {
		foreach ( $steps as $step ) {
			$step = (array) $step;
			$id   = (string) ( $step['id'] ?? '' );
			$type = (string) ( $step['type'] ?? '' );
			if ( null === $this->step( $type ) && 'loop' !== $type ) { // The executor's cursor runs loops itself.
				return sprintf( 'step "%1$s" has type "%2$s", which this version of the automation module cannot run.', $id, $type );
			}
			if ( 'wait' === $type && isset( $step['until'] ) ) {
				return sprintf( 'step "%s" waits until a date ("until"), which this version of the automation module cannot do.', $id );
			}
			if ( 'send_email' === $type && str_contains( (string) ( $step['to'] ?? '' ), '{role:' ) ) {
				return sprintf( 'step "%s" sends to a role ("{role:...}"), which this version of the automation module cannot do.', $id );
			}
			if ( in_array( $type, array( 'get_record', 'find_records' ), true ) && isset( $step['created'] ) ) {
				return sprintf( 'step "%s" reads by creation time ("created"), which this version of the automation module cannot do.', $id );
			}
			$children = array_merge(
				array_map( fn( $branch ) => (array) ( ( (array) $branch )['steps'] ?? array() ), (array) ( $step['branches'] ?? array() ) ),
				array( (array) ( $step['otherwise'] ?? array() ), (array) ( $step['steps'] ?? array() ) )
			);
			foreach ( $children as $list ) {
				$reason = $this->unsupported_steps( $list );
				if ( null !== $reason ) {
					return $reason;
				}
			}
		}
		return null;
	}
}
