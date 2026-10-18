<?php
/**
 * Daily maintenance job.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

/**
 * Daily: re-schedules runs whose wake-up was lost, fails runs stuck in `running` (never repeating a side
 * effect automatically), and prunes old logs. `wp pegwork automation tick` runs only the resume part, so a
 * clock moved with `--now` never prunes or interrupts real runs.
 */
final class Maintenance {

	/**
	 * Site time of day the job runs.
	 */
	public const AT = '03:00';

	/**
	 * Minutes a queued run may sit without a wake-up before it counts as orphaned.
	 */
	public const ORPHAN_MINUTES = 10;

	/**
	 * Minutes after its last claim that a running run counts as interrupted.
	 */
	public const STALE_MINUTES = 15;

	/**
	 * Default retention in days: finished runs (completed, skipped, cancelled) and failed runs.
	 */
	public const RETENTION = array(
		'done'   => 90,
		'failed' => 180,
	);

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs and scheduler.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Schedules the daily job unless it is pending.
	 */
	public function ensure_scheduled(): void {
		if ( null === $this->engine->scheduler()->next( Engine::MAINTENANCE_HOOK, array() ) ) {
			$this->engine->scheduler()->schedule( Engine::MAINTENANCE_HOOK, array(), $this->next_time() );
		}
	}

	/**
	 * Next run time: the next AT in site time after now.
	 *
	 * @return int Unix time.
	 */
	public function next_time(): int {
		$now    = $this->engine->clock()->now()->setTimezone( wp_timezone() );
		$parts  = array_map( 'intval', explode( ':', self::AT ) );
		$hour   = $parts[0];
		$minute = $parts[1];
		$next   = $now->setTime( $hour, $minute );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
		}
		return $next->getTimestamp();
	}

	/**
	 * Runs the job and schedules the next one.
	 *
	 * @return array{resumed: int, interrupted: int, pruned: int}
	 */
	public function run(): array {
		$engine    = $this->engine;
		$runs      = $engine->runs();
		$now       = $engine->clock()->now();
		$scheduler = $engine->scheduler();
		$scheduler->schedule( Engine::MAINTENANCE_HOOK, array(), $this->next_time() );

		$resumed     = count( $this->resume() );
		$interrupted = 0;
		$cutoff      = $runs->stamp( $now->modify( '-' . self::STALE_MINUTES . ' minutes' ) );
		foreach ( $runs->stale( $cutoff ) as $id ) {
			if ( $runs->fail_stale( $id, $cutoff, sprintf( 'interrupted: still running %d minutes after its last start; not repeated automatically.', self::STALE_MINUTES ) ) ) {
				++$interrupted;
			}
		}

		$days   = $this->retention();
		$pruned = $runs->prune( array( 'completed', 'skipped', 'cancelled' ), $runs->stamp( $now->modify( '-' . $days['done'] . ' days' ) ) )
			+ $runs->prune( array( 'failed' ), $runs->stamp( $now->modify( '-' . $days['failed'] . ' days' ) ) );

		return array(
			'resumed'     => $resumed,
			'interrupted' => $interrupted,
			'pruned'      => $pruned,
		);
	}

	/**
	 * Schedules a wake-up for now for waiting runs whose `resume_at` has passed and queued runs idle for
	 * ORPHAN_MINUTES. Without $force only runs with no pending wake-up (a lost one); with $force every such
	 * run, replacing a pending wake-up (`wp pegwork automation tick`, whose clock may be ahead of WP-Cron's).
	 *
	 * @param bool $force Replace pending wake-ups too.
	 * @return int[] IDs of the runs scheduled.
	 */
	public function resume( bool $force = false ): array {
		$runs      = $this->engine->runs();
		$now       = $this->engine->clock()->now();
		$scheduler = $this->engine->scheduler();
		$resumed   = array();

		foreach ( $runs->orphans( $runs->stamp( $now->modify( '-' . self::ORPHAN_MINUTES . ' minutes' ) ) ) as $id ) {
			if ( $force || null === $scheduler->next( Engine::RUN_HOOK, array( $id ) ) ) {
				$scheduler->schedule( Engine::RUN_HOOK, array( $id ), $now->getTimestamp() );
				$resumed[] = $id;
			}
		}
		return $resumed;
	}

	/**
	 * Retention days: defaults, then option `pegwork_automation_retention_days` (`done`, `failed`), then the filter.
	 *
	 * @return array{done: int, failed: int}
	 */
	public function retention(): array {
		$option = get_option( 'pegwork_automation_retention_days', array() );
		$days   = array_merge( self::RETENTION, is_array( $option ) ? array_intersect_key( $option, self::RETENTION ) : array() );

		/**
		 * Filters how long finished runs stay in the log, in days. Queued, running and waiting runs are never pruned.
		 *
		 * @param array{done: int, failed: int} $days `done` (completed, skipped, cancelled; default 90) and `failed` (default 180).
		 */
		$days = (array) apply_filters( 'pegwork_automation_retention_days', $days );
		return array(
			'done'   => max( 1, (int) ( $days['done'] ?? self::RETENTION['done'] ) ),
			'failed' => max( 1, (int) ( $days['failed'] ?? self::RETENTION['failed'] ) ),
		);
	}
}
