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
 * Waits and retries share one mechanism: the run becomes `waiting` with `resume_at` and the step to run
 * next (the step after a wait, the same step for a retry), and a wake-up is scheduled for `resume_at`. The
 * claim takes a waiting run only once `resume_at` has passed.
 */
final class Executor {

	/**
	 * Default time budget per wake-up, in seconds (below typical 30 s shared-host and runner limits).
	 */
	public const TIME_BUDGET = 20;

	/**
	 * Maximum step attempts per run.
	 */
	public const MAX_STEPS = 100;

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

		$steps = array_values( (array) $definition['steps'] );
		$start = 0;
		if ( '' !== $run['current_step'] ) {
			$start = array_search( $run['current_step'], array_column( $steps, 'id' ), true );
			if ( false === $start ) {
				$runs->finish( $id, 'failed', sprintf( 'definition_changed: step "%s" is no longer in the automation.', $run['current_step'] ) );
				return 'failed';
			}
		}

		$stored  = $run['context'];
		$record  = null === $run['record_id'] ? null : Records::get( (int) $run['record_id'] );
		$context = new Context( $this->engine->clock(), $record, (array) ( $stored['trigger'] ?? array() ), (array) ( $stored['steps'] ?? array() ), $id );

		/**
		 * Filters the time budget of one executor wake-up, in seconds. When it is used up, the run is
		 * re-queued at the next step (each wake-up runs at least one step).
		 *
		 * @param int $seconds Default 20.
		 */
		$deadline = microtime( true ) + (float) apply_filters( 'pegwork_automation_time_budget', self::TIME_BUDGET );
		$attempts = $runs->step_count( $id );
		$count    = count( $steps );

		for ( $i = $start; $i < $count; $i++ ) {
			$step    = $steps[ $i ];
			$step_id = (string) $step['id'];

			if ( $i > $start && microtime( true ) >= $deadline ) {
				$runs->requeue( $id, $step_id, self::store( $stored, $context ) );
				$this->wake( $id, $this->engine->clock()->now()->getTimestamp() );
				return 'queued';
			}
			if ( $attempts >= self::MAX_STEPS ) {
				$runs->finish( $id, 'failed', sprintf( 'step_limit: more than %d step attempts.', self::MAX_STEPS ), self::store( $stored, $context ) );
				return 'failed';
			}

			$runs->progress( $id, $step_id, self::store( $stored, $context ) );
			$started = $runs->stamp();
			$timer   = microtime( true );
			$result  = $this->attempt( $step, $context, $run );
			$attempt = $runs->attempts( $id, $step_id ) + 1;
			++$attempts;

			if ( array() !== $result->output ) {
				$context->set_output( $step_id, $result->output );
			}

			if ( 'failed' === $result->status ) {
				$done  = (int) ( $stored['retries'][ $step_id ] ?? 0 );
				$delay = $result->retryable ? Retry::delay( $step, $done ) : null;
				$at    = null === $delay ? null : Duration::add( $this->engine->clock()->now(), $delay );
				$this->log( $run, $step, $result, null === $at ? 'failed' : 'retrying', $attempt, $started, $timer );

				if ( null !== $at ) {
					$stored['retries'][ $step_id ] = $done + 1;
					$runs->wait( $id, $step_id, $at, self::store( $stored, $context ), 'retrying: ' . $result->error );
					$this->wake( $id, $at->getTimestamp() );
					return 'waiting';
				}
				$error = (string) $result->error . ( $done > 0 ? sprintf( ' (after %d attempts)', $done + 1 ) : '' );
				$runs->finish( $id, 'failed', $error, self::store( $stored, $context ) );
				return 'failed';
			}

			$this->log( $run, $step, $result, $result->status, $attempt, $started, $timer );
			unset( $stored['retries'][ $step_id ] );

			if ( $result->stop ) {
				$stored['outcome'] = 'stopped_at:' . $step_id;
				$runs->finish( $id, 'completed', null, self::store( $stored, $context ) );
				return 'completed';
			}
			if ( null !== $result->resume_at && $i + 1 < $count ) {
				$runs->wait( $id, (string) $steps[ $i + 1 ]['id'], $result->resume_at, self::store( $stored, $context ) );
				$this->wake( $id, $result->resume_at->getTimestamp() );
				return 'waiting';
			}
		}

		// A trailing wait has nothing left to wait for: the run completes now.
		$runs->finish( $id, 'completed', null, self::store( $stored, $context ) );
		return 'completed';
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
	 * @param string               $started Start time as stored.
	 * @param float                $timer   Start, microtime.
	 */
	private function log( array $run, array $step, StepResult $result, string $status, int $attempt, string $started, float $timer ): void {
		$this->engine->runs()->add_step(
			array(
				'run_id'      => (int) $run['id'],
				'step_id'     => (string) $step['id'],
				'step_type'   => (string) $step['type'],
				'attempt'     => $attempt,
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
		if ( isset( $stored['retries'] ) && array() === $stored['retries'] ) {
			unset( $stored['retries'] );
		}
		return $stored;
	}
}
