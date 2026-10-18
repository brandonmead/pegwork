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
	 * Executes a run if this caller can claim it; a duplicate wake-up does nothing.
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
			$step = $steps[ $i ];

			if ( $i > $start && microtime( true ) >= $deadline ) {
				$runs->requeue( $id, (string) $step['id'], self::store( $stored, $context ) );
				$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $this->engine->clock()->now()->getTimestamp() );
				return 'queued';
			}
			if ( $attempts >= self::MAX_STEPS ) {
				$runs->finish( $id, 'failed', sprintf( 'step_limit: more than %d step attempts.', self::MAX_STEPS ), self::store( $stored, $context ) );
				return 'failed';
			}

			$runs->progress( $id, (string) $step['id'], self::store( $stored, $context ) );
			$result = $this->attempt( $step, $context, $run );
			++$attempts;

			if ( array() !== $result->output ) {
				$context->set_output( (string) $step['id'], $result->output );
			}
			if ( 'failed' === $result->status ) {
				$runs->finish( $id, 'failed', $result->error, self::store( $stored, $context ) );
				return 'failed';
			}
			if ( $result->stop ) {
				$stored['outcome'] = 'stopped_at:' . $step['id'];
				$runs->finish( $id, 'completed', null, self::store( $stored, $context ) );
				return 'completed';
			}
		}

		$runs->finish( $id, 'completed', null, self::store( $stored, $context ) );
		return 'completed';
	}

	/**
	 * Runs and logs one step attempt.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	private function attempt( array $step, Context $context, array $run ): StepResult {
		$handler = $this->engine->step( (string) $step['type'] );
		$started = $this->engine->runs()->stamp();
		$timer   = microtime( true );

		if ( null === $handler ) {
			$result = StepResult::fail( 'unsupported_step', sprintf( 'Step type "%s" is not supported by this version of the automation module.', $step['type'] ) );
		} else {
			try {
				$result = $handler->run( $step, $context, $run );
			} catch ( Throwable $e ) {
				$result = StepResult::fail( 'exception', get_class( $e ) . ': ' . $e->getMessage() );
			}
		}

		$this->engine->runs()->add_step(
			array(
				'run_id'      => (int) $run['id'],
				'step_id'     => (string) $step['id'],
				'step_type'   => (string) $step['type'],
				'attempt'     => 1,
				'status'      => $result->status,
				'started_at'  => $started,
				'duration_ms' => (int) round( ( microtime( true ) - $timer ) * 1000 ),
				'summary'     => $result->summary,
				'error'       => $result->error,
			)
		);
		return $result;
	}

	/**
	 * Context to store: the stored trigger and outcome plus current step outputs.
	 *
	 * @param array<string, mixed> $stored  Stored context.
	 * @param Context              $context Run context.
	 * @return array<string, mixed>
	 */
	private static function store( array $stored, Context $context ): array {
		$stored['steps'] = $context->outputs();
		return $stored;
	}
}
