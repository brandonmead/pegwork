<?php
/**
 * In-process scheduler for WP-CLI.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Scheduler;

/**
 * Holds run and sweep wake-ups in memory so `wp pegwork automation run|tick|retry --sync` (and `--now`)
 * can execute them in the same process with drain(). Wake-ups still pending at the end (a wait or retry
 * whose time has not come) go to the fallback scheduler with flush(), so the site's own scheduler resumes
 * them. Other hooks (the daily maintenance, schedule firings) go straight to the fallback.
 */
final class InlineScheduler implements Scheduler {

	/**
	 * Hooks held in memory.
	 *
	 * @var string[]
	 */
	private array $hooks;

	/**
	 * Scheduler for everything else and for what is left at flush().
	 *
	 * @var Scheduler
	 */
	private Scheduler $fallback;

	/**
	 * Pending wake-ups by hook and serialized args.
	 *
	 * @var array<string, array{hook: string, args: array<int, mixed>, at: int}>
	 */
	private array $jobs = array();

	/**
	 * Wraps a scheduler.
	 *
	 * @param Scheduler $fallback Site scheduler.
	 * @param string[]  $hooks    Hooks to hold and drain in process.
	 */
	public function __construct( Scheduler $fallback, array $hooks ) {
		$this->fallback = $fallback;
		$this->hooks    = $hooks;
	}

	/**
	 * Schedules one firing, replacing a pending one with the same hook and args (in memory and in the fallback).
	 *
	 * @param string            $hook      Action name.
	 * @param array<int, mixed> $args      Arguments.
	 * @param int               $timestamp Unix time.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return $this->fallback->schedule( $hook, $args, $timestamp );
		}
		$this->fallback->unschedule( $hook, $args );
		$this->jobs[ self::id( $hook, $args ) ] = array(
			'hook' => $hook,
			'args' => $args,
			'at'   => $timestamp,
		);
		return true;
	}

	/**
	 * Cancels a pending firing.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 */
	public function unschedule( string $hook, array $args ): void {
		unset( $this->jobs[ self::id( $hook, $args ) ] );
		$this->fallback->unschedule( $hook, $args );
	}

	/**
	 * Time of a pending firing, in memory or in the fallback.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 */
	public function next( string $hook, array $args ): ?int {
		return $this->jobs[ self::id( $hook, $args ) ]['at'] ?? $this->fallback->next( $hook, $args );
	}

	/**
	 * Fires held wake-ups due at or before a time, in time order, including the ones they schedule.
	 *
	 * @param int $until Unix time.
	 * @param int $limit Safety cap on firings.
	 * @return int Number fired.
	 */
	public function drain( int $until, int $limit = 10000 ): int {
		$fired = 0;
		while ( $fired < $limit ) {
			$due = array_filter( $this->jobs, fn( array $job ) => $job['at'] <= $until );
			if ( array() === $due ) {
				break;
			}
			uasort( $due, fn( array $a, array $b ) => $a['at'] <=> $b['at'] );
			$key = (string) array_key_first( $due );
			$job = $due[ $key ];
			unset( $this->jobs[ $key ] );
			do_action_ref_array( $job['hook'], $job['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Fires the module's own hooks.
			++$fired;
		}
		return $fired;
	}

	/**
	 * Hands the wake-ups still held to the fallback scheduler.
	 *
	 * @return int Number handed over.
	 */
	public function flush(): int {
		$count = count( $this->jobs );
		foreach ( $this->jobs as $job ) {
			$this->fallback->schedule( $job['hook'], $job['args'], $job['at'] );
		}
		$this->jobs = array();
		return $count;
	}

	/**
	 * Job key.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 */
	private static function id( string $hook, array $args ): string {
		return $hook . wp_json_encode( $args );
	}
}
