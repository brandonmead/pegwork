<?php
/**
 * In-memory scheduler for tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use PegworkAutomation\Scheduler\Scheduler;

/**
 * Holds wake-ups in memory; drain() fires the due ones in time order, as WP-Cron or Action Scheduler would.
 */
final class FakeScheduler implements Scheduler {

	/**
	 * Pending wake-ups by hook and serialized args.
	 *
	 * @var array<string, array{hook: string, args: array, at: int}>
	 */
	public array $jobs = array();

	/**
	 * Wake-ups fired so far, as [ hook, args ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public array $fired = array();

	/**
	 * Schedules one firing, replacing a pending one with the same hook and args.
	 *
	 * @param string $hook      Action name.
	 * @param array  $args      Arguments.
	 * @param int    $timestamp Unix time.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool {
		$this->jobs[ $hook . wp_json_encode( $args ) ] = array(
			'hook' => $hook,
			'args' => $args,
			'at'   => $timestamp,
		);
		return true;
	}

	/**
	 * Cancels a pending firing.
	 *
	 * @param string $hook Action name.
	 * @param array  $args Arguments.
	 */
	public function unschedule( string $hook, array $args ): void {
		unset( $this->jobs[ $hook . wp_json_encode( $args ) ] );
	}

	/**
	 * Time of a pending firing.
	 *
	 * @param string $hook Action name.
	 * @param array  $args Arguments.
	 */
	public function next( string $hook, array $args ): ?int {
		return $this->jobs[ $hook . wp_json_encode( $args ) ]['at'] ?? null;
	}

	/**
	 * Fires due wake-ups (at or before $until) in time order, including ones they schedule, until none is due.
	 *
	 * @param int      $until Unix time.
	 * @param string[] $hooks Only these hooks (default: the run hook).
	 * @return int Number fired.
	 */
	public function drain( int $until, array $hooks = array( 'pegwork_automation_run' ) ): int {
		$fired = 0;
		while ( true ) {
			$due = array_filter( $this->jobs, fn( $job ) => $job['at'] <= $until && in_array( $job['hook'], $hooks, true ) );
			if ( array() === $due ) {
				return $fired;
			}
			uasort( $due, fn( $a, $b ) => $a['at'] <=> $b['at'] );
			$key = array_key_first( $due );
			$job = $due[ $key ];
			unset( $this->jobs[ $key ] );
			$this->fired[] = array( $job['hook'], $job['args'] );
			do_action_ref_array( $job['hook'], $job['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Fires scheduled module hooks.
			if ( ++$fired > 1000 ) {
				return $fired;
			}
		}
	}
}
