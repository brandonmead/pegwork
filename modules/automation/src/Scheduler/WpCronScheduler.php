<?php
/**
 * WP-Cron scheduler.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Scheduler;

/**
 * Wake-ups as WP-Cron single events. WP-Cron runs due events on page loads (or from a system cron with
 * `wp cron event run --due-now`); it can fire an event twice under concurrent spawns, which the executor's
 * atomic claim absorbs.
 *
 * When something is scheduled for now, the request spawns WP-Cron at shutdown (a non-blocking loopback, as
 * core's wp_cron() does on the next page load) so the run does not wait for the next visitor.
 */
final class WpCronScheduler implements Scheduler {

	/**
	 * Whether a due wake-up was scheduled in this request.
	 *
	 * @var bool
	 */
	private bool $due = false;

	/**
	 * Schedules one firing, replacing a pending one with the same hook and arguments.
	 *
	 * @param string            $hook      Action name.
	 * @param array<int, mixed> $args      Arguments.
	 * @param int               $timestamp Unix time.
	 * @return bool Whether it was scheduled.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool {
		wp_clear_scheduled_hook( $hook, $args );
		$scheduled = wp_schedule_single_event( $timestamp, $hook, $args, true );
		if ( true !== $scheduled ) {
			return false;
		}

		if ( $timestamp <= time() && ! $this->due ) {
			$this->due = true;
			add_action( 'shutdown', array( $this, 'spawn' ) );
		}
		return true;
	}

	/**
	 * Cancels the pending firing with this hook and arguments.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 */
	public function unschedule( string $hook, array $args ): void {
		wp_clear_scheduled_hook( $hook, $args );
	}

	/**
	 * Time of the pending firing.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 * @return int|null Unix time, or null.
	 */
	public function next( string $hook, array $args ): ?int {
		self::fresh();
		$next = wp_next_scheduled( $hook, $args );
		return false === $next ? null : (int) $next;
	}

	/**
	 * Drops this process's copy of the `cron` option, so next() sees what other processes scheduled
	 * meanwhile: a long cron pass otherwise reads, and writes back, the copy it loaded when it started. A
	 * persistent object cache is shared, so nothing is dropped then. Costs one options query per call.
	 */
	private static function fresh(): void {
		if ( wp_using_ext_object_cache() ) {
			return;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'cron', 'options' );
	}

	/**
	 * Spawns WP-Cron once at shutdown when a due wake-up was scheduled. Hooked to shutdown.
	 */
	public function spawn(): void {
		$this->due = false;
		if ( wp_doing_cron() || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return;
		}

		/**
		 * Filters whether the automation module spawns WP-Cron at the end of a request that queued a run.
		 *
		 * @param bool $spawn Default true. Return false when a system cron runs `wp cron event run --due-now`.
		 */
		if ( apply_filters( 'pegwork_automation_spawn_cron', true ) ) {
			spawn_cron();
		}
	}
}
