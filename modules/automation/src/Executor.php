<?php
/**
 * Run executor.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;
use PegworkAutomation\Steps\StepResult;
use Throwable;

/**
 * Executes one run per wake-up: claims it atomically, re-reads the record, runs steps from `current_step`,
 * writes one run-step row per attempt, and stops after the time budget, re-queueing the rest.
 *
 * Steps form a tree (decisions hold branches of steps). A Plan built per wake-up gives each step id its
 * position, so `current_step` is still just a step id: the cursor continues with Plan::next(), or, after a
 * decision, with the first step of the branch it took. A step id that is no longer in the tree fails the run
 * (`definition_changed`); one that moved continues at its new position.
 *
 * Waits and retries share one mechanism: the run becomes `waiting` with `resume_at` and the step to run
 * next (the step after a wait, the same step for a retry or a date wait, whose result has `stay`), and a
 * wake-up is scheduled for `resume_at`. The claim takes a waiting run only once `resume_at` has passed.
 * Date wait state (`context.until`) is restored and stored like the loop state.
 *
 * A step with `on_error: continue` that fails after its retries is logged `failed`, outputs `failed: true`
 * and `error: "<code>"`, and the run continues; such a run completes with outcome
 * `completed_with_errors:<ids>`. Steps with `on_error` output `failed: false` on success. The run's read
 * count (`context.reads`) and trash count (`context.trashed`) are restored on each wake-up and stored with
 * the outputs.
 *
 * Loops: the cursor arriving at a loop id with no `context.loops.<id>` starts the loop (snapshot of the
 * collection's IDs, one logged row `{ items, ids }`, counted as an attempt), else advances it (no row, no
 * attempt). Each item runs the body from Plan::body_first(); an item deleted meanwhile is skipped with a
 * row `skipped_item`; a false condition in the body skips to the next item. After the last item the state
 * is removed, the loop outputs `count`, and the cursor continues at next( loop ). Because the state is part
 * of the stored context, a time-budget requeue, a retry and Retry from the log all resume at the same item.
 * Body rows carry `iteration` (1-based), and attempts are numbered per step and iteration.
 */
final class Executor {

	/**
	 * Default time budget per wake-up, in seconds (below typical 30 s shared-host and runner limits).
	 */
	public const TIME_BUDGET = 20;

	/**
	 * Default maximum step attempts per run (filter `pegwork_automation_step_limit`). The registry bounds a
	 * definition at 500 attempts without retries, so this is a safety net for retries, not a design limit.
	 */
	public const MAX_STEPS = 600;

	/**
	 * Outcome prefix of a completed run in which steps with `on_error: continue` failed.
	 */
	public const ERRORS_OUTCOME = 'completed_with_errors:';

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs, scheduler and steps.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Executes a run if this caller can claim it; a duplicate or early wake-up does nothing.
	 *
	 * @param int $id Run ID.
	 * @return string|null The run status afterwards, or null when it was not claimed.
	 */
	public function execute( int $id ): ?string {
		$runs = $this->engine->runs();
		if ( ! $runs->claim( $id ) ) {
			return null;
		}

		$run        = (array) $runs->get( $id );
		$definition = pegwork_registry()->automation( (string) $run['automation'] );
		if ( null === $definition ) {
			$runs->finish( $id, 'cancelled', 'automation_removed: the automation is no longer defined.' );
			return 'cancelled';
		}
		if ( ! Overrides::enabled( $definition ) ) {
			$runs->finish( $id, 'cancelled', 'automation_disabled: the automation is disabled.' );
			return 'cancelled';
		}
		$unsupported = $this->engine->unsupported( $definition );
		if ( null !== $unsupported ) {
			$runs->finish( $id, 'failed', 'unsupported_step: ' . $unsupported ); // Before any step runs: nothing half-done.
			return 'failed';
		}

		$steps = self::step_list( $definition, $run );
		if ( null === $steps ) {
			$runs->finish( $id, 'failed', sprintf( 'definition_changed: link "%s" is no longer in the automation.', (string) ( $run['context']['link'] ?? '' ) ) );
			return 'failed';
		}
		$plan    = new Plan( $steps );
		$step_id = '' === $run['current_step'] ? $plan->first() : (string) $run['current_step'];
		if ( null !== $step_id && ! $plan->has( $step_id ) ) {
			$runs->finish( $id, 'failed', sprintf( 'definition_changed: step "%s" is no longer in the automation.', $step_id ) );
			return 'failed';
		}

		$stored  = $run['context'];
		$record  = null === $run['record_id'] ? null : Records::get( (int) $run['record_id'] );
		$context = new Context( $this->engine->clock(), $record, (array) ( $stored['trigger'] ?? array() ), (array) ( $stored['steps'] ?? array() ), $id, (string) $run['automation'] );
		$context->use_plan( $plan );
		$context->restore_reads( (int) ( $stored['reads'] ?? 0 ) );
		$context->restore_trashed( (int) ( $stored['trashed'] ?? 0 ) );
		$context->restore_loops( (array) ( $stored['loops'] ?? array() ) );
		$context->restore_until( (array) ( $stored['until'] ?? array() ) );
		$moved = self::loop_mismatch( $plan, $step_id, $context );
		if ( null !== $moved ) {
			$runs->finish( $id, 'failed', 'definition_changed: ' . $moved );
			return 'failed';
		}

		/**
		 * Filters the time budget of one executor wake-up, in seconds. When it is used up, the run is
		 * re-queued at the next step (each wake-up runs at least one step).
		 *
		 * @param int $seconds Default 20.
		 */
		$deadline = microtime( true ) + (float) apply_filters( 'pegwork_automation_time_budget', self::TIME_BUDGET );
		$limit    = self::step_limit();
		$attempts = $runs->step_count( $id );
		$first    = true;

		// The cursor walks the tree: each step names the next one (Plan), a decision picks its branch, a loop
		// starts its body again or continues after itself.
		while ( null !== $step_id ) {
			$step    = (array) $plan->step( $step_id );
			$is_loop = $plan->is_loop( $step_id );
			$advance = $is_loop && null !== $context->loop( $step_id );

			// Checked before every step, loop iterations included; the stored loop state resumes the item.
			if ( ! $first && microtime( true ) >= $deadline ) {
				$runs->requeue( $id, $step_id, self::store( $stored, $context ) );
				$this->wake( $id, $this->engine->clock()->now()->getTimestamp() );
				return 'queued';
			}
			if ( ! $advance && $attempts >= $limit ) {
				$runs->finish( $id, 'failed', sprintf( 'step_limit: more than %d step attempts.', $limit ), self::store( $stored, $context ) );
				return 'failed';
			}

			if ( $is_loop ) {
				$error = null;
				if ( $advance ) {
					$context->advance_loop( $step_id ); // Not an attempt and no row: the wake-up's first step may still follow.
					$step_id = $this->enter( $run, $step, $plan, $context, $attempts );
				} else {
					$step_id = $this->start( $run, $step, $plan, $context, $attempts, $error );
					$first   = false;
				}
				if ( null !== $error ) {
					$runs->finish( $id, 'failed', $error, self::store( $stored, $context ) );
					return 'failed';
				}
				continue;
			}

			$loop      = $plan->loop_of( $step_id );
			$iteration = $context->iteration( $step_id );
			$runs->progress( $id, $step_id, self::store( $stored, $context ) );
			$started = $runs->stamp();
			$timer   = microtime( true );
			$result  = $this->attempt( $step, $context, $run );
			$attempt = $runs->attempts( $id, $step_id, $iteration ) + 1;
			++$attempts;

			$continue = 'continue' === ( $step['on_error'] ?? null );
			if ( $continue && 'ok' === $result->status ) {
				$result->output += array( 'failed' => false );
			}
			if ( array() !== $result->output ) {
				$context->set_output( $step_id, $result->output );
			}

			if ( 'failed' === $result->status ) {
				$done  = (int) ( $stored['retries'][ $step_id ] ?? 0 );
				$delay = $result->retryable ? Retry::delay( $step, $done, null !== $loop ) : null;
				$at    = null === $delay ? null : Duration::add( $this->engine->clock()->now(), $delay );
				$this->log( $run, $step, $result, null === $at ? 'failed' : 'retrying', $attempt, $started, $timer, $iteration );

				if ( null !== $at ) {
					$stored['retries'][ $step_id ] = $done + 1;
					$runs->wait( $id, $step_id, $at, self::store( $stored, $context ), 'retrying: ' . $result->error );
					$this->wake( $id, $at->getTimestamp() );
					return 'waiting';
				}
				if ( $continue ) {
					// `on_error: continue`: the failure is an output, and the run goes on.
					$context->set_output(
						$step_id,
						array(
							'failed' => true,
							'error'  => self::code( (string) $result->error ),
						) + $result->output
					);
					$stored['errors'] = array_values( array_unique( array_merge( (array) ( $stored['errors'] ?? array() ), array( $step_id ) ) ) );
					unset( $stored['retries'][ $step_id ] );
					$step_id = $plan->next( $step_id );
					$first   = false;
					continue;
				}
				$error = (string) $result->error . ( $done > 0 ? sprintf( ' (after %d attempts)', $done + 1 ) : '' );
				$runs->finish( $id, 'failed', $error, self::store( $stored, $context ) );
				return 'failed';
			}

			if ( $result->stop && null !== $loop ) {
				$result->summary['skipped_item'] = true;
			}
			$this->log( $run, $step, $result, $result->status, $attempt, $started, $timer, $iteration );
			unset( $stored['retries'][ $step_id ] );

			if ( $result->stop && null !== $loop ) {
				// A false condition inside a loop body skips the rest of this item, not the run.
				$step_id = $loop;
				$first   = false;
				continue;
			}
			if ( $result->stop ) {
				$stored['outcome'] = 'stopped_at:' . $step_id;
				$runs->finish( $id, 'completed', null, self::store( self::outcome( $stored ), $context ) );
				return 'completed';
			}
			$next   = 'decision' === ( $step['type'] ?? null ) ? $plan->after_branch( $step_id, $result->branch ) : $plan->next( $step_id );
			$resume = $result->stay ? $step_id : $next; // A date wait decides again on every wake-up.
			if ( null !== $result->resume_at && null !== $resume ) {
				$runs->wait( $id, $resume, $result->resume_at, self::store( $stored, $context ) );
				$this->wake( $id, $result->resume_at->getTimestamp() );
				return 'waiting';
			}
			$step_id = $next;
			$first   = false;
		}

		// A wait at the end of the tree has nothing left to wait for: the run completes now.
		$runs->finish( $id, 'completed', null, self::store( self::outcome( $stored ), $context ) );
		return 'completed';
	}

	/**
	 * Most step attempts one run may make.
	 */
	public static function step_limit(): int {
		/**
		 * Filters the most step attempts one run may make (retries and loop items included). Past it the run
		 * fails with `step_limit`. The registry already bounds a definition at 500 attempts without retries.
		 *
		 * @param int $limit Default 600.
		 */
		return max( 1, (int) apply_filters( 'pegwork_automation_step_limit', self::MAX_STEPS ) );
	}

	/**
	 * Starts a loop: snapshots the IDs of the collection it iterates (counted toward the read limit), logs
	 * one row `{ items, ids }`, and continues at the first item that still exists, or after the loop when
	 * there is none (`count` 0).
	 *
	 * @param array<string, mixed> $run      Run row.
	 * @param array<string, mixed> $step     Loop step.
	 * @param Plan                 $plan     Plan.
	 * @param Context              $context  Context.
	 * @param int                  $attempts Attempts so far in the run (incremented).
	 * @param string|null          $error    Set to the run error when the loop cannot start.
	 * @return string|null The step to continue at.
	 */
	private function start( array $run, array $step, Plan $plan, Context $context, int &$attempts, ?string &$error ): ?string {
		$loop = (string) $step['id'];
		$over = $plan->over( $loop );
		if ( null === $over || ! $plan->has( $over ) ) {
			$error = sprintf( 'definition_changed: loop "%1$s" iterates step "%2$s", which is no longer in the automation.', $loop, (string) $over );
			return null;
		}

		$ids     = $context->collection( (string) $step['over'] ) ?? array(); // A find that failed with on_error has no records.
		$summary = array(
			'items' => count( $ids ),
			'ids'   => $ids,
		);
		$started = $this->engine->runs()->stamp();
		$timer   = microtime( true );
		$attempt = $this->engine->runs()->attempts( (int) $run['id'], $loop ) + 1;
		++$attempts;

		if ( ! $context->count_reads( count( $ids ) ) ) {
			$result = Steps\Reads::over_limit( count( $ids ), $context, $summary );
			$this->log( $run, $step, $result, 'failed', $attempt, $started, $timer, null );
			$error = (string) $result->error;
			return null;
		}
		$this->log( $run, $step, StepResult::ok( array(), $summary ), 'ok', $attempt, $started, $timer, null );

		if ( array() === $ids ) {
			$context->end_loop( $loop );
			return $plan->next( $loop );
		}
		$context->start_loop( $loop, $ids );
		return $this->enter( $run, $step, $plan, $context, $attempts );
	}

	/**
	 * Enters the loop's current item: the first step of the body when the item can still be read, else a row
	 * `{ item, skipped_item }` for it and the next item. After the last item the loop ends (state removed,
	 * output `count`) and the cursor continues after it.
	 *
	 * @param array<string, mixed> $run      Run row.
	 * @param array<string, mixed> $step     Loop step.
	 * @param Plan                 $plan     Plan.
	 * @param Context              $context  Context.
	 * @param int                  $attempts Attempts so far in the run (incremented per skipped item).
	 * @return string|null The step to continue at.
	 */
	private function enter( array $run, array $step, Plan $plan, Context $context, int &$attempts ): ?string {
		$loop = (string) $step['id'];
		$body = $plan->body_first( $loop );
		$item = $context->loop_item( $loop );
		while ( null !== $item && null !== $body ) {
			if ( null !== $context->record_by_id( $item ) ) {
				return $body;
			}
			// Deleted or trashed since the snapshot: skipped.
			$started = $this->engine->runs()->stamp();
			$summary = array(
				'item'         => $item,
				'skipped_item' => true,
			);
			$this->log( $run, $step, StepResult::ok( array(), $summary ), 'ok', 1, $started, microtime( true ), (int) ( $context->loop( $loop )['index'] ?? 0 ) + 1 );
			++$attempts;
			$item = $context->advance_loop( $loop ) ? $context->loop_item( $loop ) : null;
		}
		$context->end_loop( $loop );
		return $plan->next( $loop );
	}

	/**
	 * Why the stored loop state no longer fits the cursor after a definition edit, or null: state for a loop
	 * other than the one around (or at) the cursor, or a cursor inside a loop that has no state.
	 *
	 * @param Plan        $plan    Plan.
	 * @param string|null $step_id Cursor.
	 * @param Context     $context Context with the stored loop state.
	 */
	private static function loop_mismatch( Plan $plan, ?string $step_id, Context $context ): ?string {
		$around   = null === $step_id ? null : $plan->loop_of( $step_id );
		$expected = $around ?? ( null !== $step_id && $plan->is_loop( $step_id ) ? $step_id : null );
		foreach ( array_keys( $context->loops() ) as $loop ) {
			if ( $loop !== $expected ) {
				return sprintf( 'loop "%1$s" is no longer around step "%2$s".', (string) $loop, (string) $step_id );
			}
		}
		if ( null !== $around && null === $context->loop( $around ) ) {
			return sprintf( 'step "%1$s" is now inside loop "%2$s".', (string) $step_id, $around );
		}
		return null;
	}

	/**
	 * Stored context with the outcome of a completed run: `completed_with_errors:<ids>` when steps with
	 * `on_error: continue` failed (it wins over `stopped_at:<id>`, so the failures stay visible and count in
	 * the list's failures), else the outcome so far.
	 *
	 * @param array<string, mixed> $stored Stored context.
	 * @return array<string, mixed>
	 */
	private static function outcome( array $stored ): array {
		if ( array() !== (array) ( $stored['errors'] ?? array() ) ) {
			$stored['outcome'] = self::ERRORS_OUTCOME . implode( ',', (array) $stored['errors'] );
		}
		return $stored;
	}

	/**
	 * The code of a step error (`code: message`).
	 *
	 * @param string $error Error.
	 */
	private static function code( string $error ): string {
		$colon = strpos( $error, ':' );
		return false === $colon ? $error : substr( $error, 0, $colon );
	}

	/**
	 * The steps a run executes: a `link` run executes only the steps of the link named in its context
	 * (`context.link`), every other run the automation's own steps.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param array<string, mixed> $run        Run row.
	 * @return array<int, array<string, mixed>>|null Null when the run's link is no longer defined.
	 */
	public static function step_list( array $definition, array $run ): ?array {
		if ( 'link' !== $run['trigger_type'] ) {
			return array_values( (array) $definition['steps'] );
		}
		$name = (string) ( $run['context']['link'] ?? '' );
		$link = $definition['links'][ $name ] ?? null;
		return is_array( $link ) ? array_values( (array) $link['steps'] ) : null;
	}

	/**
	 * Runs one step attempt.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	private function attempt( array $step, Context $context, array $run ): StepResult {
		$handler = $this->engine->step( (string) $step['type'] );
		if ( null === $handler ) {
			return StepResult::fail( 'unsupported_step', sprintf( 'Step type "%s" is not supported by this version of the automation module.', $step['type'] ), array(), false );
		}
		try {
			return $handler->run( $step, $context, $run );
		} catch ( Throwable $e ) {
			return StepResult::fail( 'exception', get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Writes the run-step row of an attempt.
	 *
	 * @param array<string, mixed> $run     Run row.
	 * @param array<string, mixed> $step    Step definition.
	 * @param StepResult           $result  Result.
	 * @param string               $status  ok, failed or retrying.
	 * @param int                  $attempt Attempt number of this step in the run.
	 * @param string               $started   Start time as stored.
	 * @param float                $timer     Start, microtime.
	 * @param int|null             $iteration 1-based loop item number, null outside loop bodies.
	 */
	private function log( array $run, array $step, StepResult $result, string $status, int $attempt, string $started, float $timer, ?int $iteration ): void {
		$this->engine->runs()->add_step(
			array(
				'run_id'      => (int) $run['id'],
				'step_id'     => (string) $step['id'],
				'step_type'   => (string) $step['type'],
				'attempt'     => $attempt,
				'iteration'   => $iteration,
				'status'      => $status,
				'started_at'  => $started,
				'duration_ms' => (int) round( ( microtime( true ) - $timer ) * 1000 ),
				'summary'     => $result->summary,
				'error'       => $result->error,
			)
		);
	}

	/**
	 * Schedules the run's next wake-up.
	 *
	 * @param int $id        Run ID.
	 * @param int $timestamp Unix time.
	 */
	private function wake( int $id, int $timestamp ): void {
		$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $timestamp );
	}

	/**
	 * Context to store: the stored trigger, outcome and retry counts plus current step outputs.
	 *
	 * @param array<string, mixed> $stored  Stored context.
	 * @param Context              $context Run context.
	 * @return array<string, mixed>
	 */
	private static function store( array $stored, Context $context ): array {
		$stored['steps'] = $context->outputs();
		if ( $context->reads() > 0 ) {
			$stored['reads'] = $context->reads();
		}
		if ( $context->trashed() > 0 ) {
			$stored['trashed'] = $context->trashed();
		}
		unset( $stored['loops'] );
		if ( array() !== $context->loops() ) {
			$stored['loops'] = $context->loops();
		}
		unset( $stored['until'] );
		if ( array() !== $context->until_states() ) {
			$stored['until'] = $context->until_states();
		}
		if ( isset( $stored['retries'] ) && array() === $stored['retries'] ) {
			unset( $stored['retries'] );
		}
		return $stored;
	}
}
