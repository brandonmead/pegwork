<?php
/**
 * Event triggers.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;

/**
 * Listens on the core event bus. For each enabled automation whose event trigger matches, it evaluates
 * `when` synchronously (the payload's old values exist only now), applies the loop depth cap and the
 * idempotency key, inserts a `queued` run and schedules its wake-up. The saving request never runs steps.
 */
final class Triggers {

	/**
	 * Default loop depth cap: a trigger at this origin depth or deeper is logged as skipped.
	 */
	public const MAX_DEPTH = 3;

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
	 * Handles one bus event.
	 *
	 * @param string               $name    Event name.
	 * @param array<string, mixed> $payload Event payload.
	 * @return int[] IDs of the runs created (queued or skipped).
	 */
	public function handle( string $name, array $payload ): array {
		$created = array();

		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			$trigger = $definition['trigger'];
			if ( 'event' !== $trigger['type'] || $trigger['event'] !== $name ) {
				continue;
			}
			if ( isset( $trigger['content_type'] ) && ( $payload['type'] ?? null ) !== $trigger['content_type'] ) {
				continue;
			}
			if ( isset( $trigger['form'] ) && ( $payload['form'] ?? null ) !== $trigger['form'] ) {
				continue;
			}
			if ( ! Overrides::enabled( $definition ) ) {
				continue;
			}

			$id = $this->start( (string) $key, $definition, $name, $payload );
			if ( $id > 0 ) {
				$created[] = $id;
			}
		}
		return $created;
	}

	/**
	 * Starts one run for a matching automation.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param string               $name       Event name.
	 * @param array<string, mixed> $payload    Event payload.
	 * @return int Run ID, or 0 when none was created.
	 */
	private function start( string $key, array $definition, string $name, array $payload ): int {
		$binds     = str_starts_with( $name, 'record.' );
		$record_id = $binds && isset( $payload['id'] ) ? (int) $payload['id'] : null;
		$record    = null === $record_id ? null : Records::get( $record_id );
		$context   = new Context( $this->engine->clock(), $record, $payload );

		if ( isset( $definition['trigger']['when'] ) && ! Conditions::evaluate( $definition['trigger']['when'], $context ) ) {
			return 0;
		}

		$depth = (int) ( $payload['origin']['depth'] ?? 0 );
		$run   = array(
			'automation'      => $key,
			'trigger_type'    => 'event',
			'record_id'       => $record_id,
			'depth'           => $depth,
			'definition_hash' => self::hash( $definition ),
			'context'         => array(
				'event'   => $name,
				'trigger' => $payload,
			),
		);

		/**
		 * Filters the loop depth cap: triggers at this origin depth or deeper are logged as skipped.
		 *
		 * @param int $max Default 3.
		 */
		$max = (int) apply_filters( 'pegwork_automation_max_depth', self::MAX_DEPTH );
		if ( $depth >= $max ) {
			return $this->engine->runs()->insert(
				$run + array(
					'status' => 'skipped',
					'error'  => sprintf( 'loop_depth: trigger depth %1$d reached the limit of %2$d.', $depth, $max ),
				)
			);
		}

		if ( isset( $definition['idempotency'] ) ) {
			$run['idempotency_key'] = self::key( Templates::render( (string) $definition['idempotency'], $context ) );
		}

		$id = $this->engine->runs()->insert( $run + array( 'status' => 'queued' ) );
		if ( $id > 0 ) {
			$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $this->engine->clock()->now()->getTimestamp() );
		}
		return $id;
	}

	/**
	 * Hash of a definition, stored on each run.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function hash( array $definition ): string {
		return md5( (string) wp_json_encode( $definition ) );
	}

	/**
	 * An idempotency key that fits the column: longer keys are hashed.
	 *
	 * @param string $key Rendered key.
	 */
	private static function key( string $key ): string {
		return strlen( $key ) <= 191 ? $key : 'md5:' . md5( $key );
	}
}
