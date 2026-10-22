<?php
/**
 * Scheduler contract.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Scheduler;

/**
 * The wake-up transport: fires a WordPress action with arguments at (or soon after) a time. It holds no
 * automation state; `pegwork_runs` is the source of truth, so a lost or duplicated wake-up is harmless (the
 * executor claims runs atomically and the daily maintenance job re-schedules runs whose wake-up was lost).
 *
 * Implementations: WpCronScheduler (default, Engine::instance()), InlineScheduler (CLI `--sync`, `--now`)
 * and the test FakeScheduler (Engine::use()). Production runs WP-Cron from a server cron (docs/automation.md,
 * Production scheduling); there is no Action Scheduler adapter (decided 2026-10-22).
 */
interface Scheduler {

	/**
	 * Schedules one firing of `do_action_ref_array( $hook, $args )` at a time, replacing any pending firing
	 * with the same hook and arguments (at most one pending wake-up per hook and arguments).
	 *
	 * @param string            $hook      Action name.
	 * @param array<int, mixed> $args      Scalar arguments, such as array( $run_id ).
	 * @param int               $timestamp Unix time; now or earlier means as soon as possible.
	 * @return bool Whether it was scheduled.
	 */
	public function schedule( string $hook, array $args, int $timestamp ): bool;

	/**
	 * Cancels the pending firing with this hook and arguments, if any.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 */
	public function unschedule( string $hook, array $args ): void;

	/**
	 * Time of the pending firing with this hook and arguments.
	 *
	 * @param string            $hook Action name.
	 * @param array<int, mixed> $args Arguments.
	 * @return int|null Unix time, or null when none is pending.
	 */
	public function next( string $hook, array $args ): ?int;
}
