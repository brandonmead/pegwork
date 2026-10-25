<?php
/**
 * Date wait change listener.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Registry;
use WeakMap;

/**
 * Pulls the wake-up of runs that wait until a date field to now when that field, or the record's status,
 * changes (docs/automation-v4-design.md section 3.2), so a date moved earlier, cleared or set, and a record
 * trashed, are decided at once instead of at the old time. Hooked to `pegwork_event_record_updated`.
 *
 * The index (content type => automation => wait step id => field key) is built once per registry from
 * every automation's `until` waits. An event whose type has none, whose `changed` holds neither a waited
 * field nor `status`, or whose waits all belong to disabled automations returns without a query. Otherwise
 * one indexed query finds the record's waiting runs, and each run waiting at a matching step gets
 * `resume_at` = now (atomically, only while it still waits at that step) and a wake-up now. No step runs in
 * the saving request. A run waiting for a retry at another step is left alone.
 */
final class Waits {

	/**
	 * Indexes by registry object.
	 *
	 * @var WeakMap<Registry, array<string, array<string, array<string, string>>>>|null
	 */
	private static ?WeakMap $indexes = null;

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
	 * Handles a `record.updated` payload.
	 *
	 * @param array<string, mixed> $payload Event payload (`id`, `type`, `changed`, ...).
	 * @return int Runs nudged.
	 */
	public function nudge( array $payload ): int {
		$type    = $payload['type'] ?? null;
		$record  = (int) ( $payload['id'] ?? 0 );
		$changed = (array) ( $payload['changed'] ?? array() );
		$waits   = is_string( $type ) ? ( self::index( pegwork_registry() )[ $type ] ?? array() ) : array();
		if ( array() === $waits || $record <= 0 || array() === $changed ) {
			return 0;
		}

		$status  = in_array( 'status', $changed, true );
		$matches = array();
		foreach ( $waits as $key => $steps ) {
			$hit = array_keys( array_filter( $steps, fn( $field ) => $status || in_array( $field, $changed, true ) ) );
			if ( array() === $hit ) {
				continue;
			}
			$definition = pegwork_registry()->automation( (string) $key );
			if ( null !== $definition && Overrides::enabled( $definition ) ) { // Waking a disabled one would cancel it early.
				$matches[ (string) $key ] = $hit;
			}
		}
		if ( array() === $matches ) {
			return 0;
		}

		$nudged = 0;
		$runs   = $this->engine->runs();
		foreach ( $runs->waiting_on( $record ) as $run ) {
			if ( ! in_array( $run['current_step'], $matches[ $run['automation'] ] ?? array(), true ) ) {
				continue;
			}
			if ( $runs->nudge( $run['id'], $run['current_step'] ) ) {
				$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $run['id'] ), $this->engine->clock()->now()->getTimestamp() );
				++$nudged;
			}
		}
		return $nudged;
	}

	/**
	 * The date waits of a registry: content type => automation key => wait step id => field key. Built once
	 * per registry object (the registry is rebuilt when definitions change).
	 *
	 * @param Registry $registry Registry.
	 * @return array<string, array<string, array<string, string>>>
	 */
	public static function index( Registry $registry ): array {
		self::$indexes ??= new WeakMap();
		if ( isset( self::$indexes[ $registry ] ) ) {
			return self::$indexes[ $registry ];
		}
		$index = array();
		foreach ( $registry->automations() as $key => $definition ) {
			$type = DryRun::bound_type( (array) ( $definition['trigger'] ?? array() ) );
			if ( null === $type ) {
				continue; // The registry refuses date waits without a typed bound record.
			}
			$fields = array();
			self::collect( (array) ( $definition['steps'] ?? array() ), $fields );
			if ( array() !== $fields ) {
				$index[ $type ][ (string) $key ] = $fields;
			}
		}
		self::$indexes[ $registry ] = $index;
		return $index;
	}

	/**
	 * The label of the field a date wait step waits on (for "Waiting for Due on"), or null.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param string               $step_id    Step id.
	 */
	public static function field_label( array $definition, string $step_id ): ?string {
		$fields = array();
		self::collect( (array) ( $definition['steps'] ?? array() ), $fields );
		$type = DryRun::bound_type( (array) ( $definition['trigger'] ?? array() ) );
		if ( ! isset( $fields[ $step_id ] ) || null === $type ) {
			return null;
		}
		$field = pegwork_registry()->fields( $type )[ $fields[ $step_id ] ] ?? null;
		return is_array( $field ) ? (string) ( $field['label'] ?? $fields[ $step_id ] ) : $fields[ $step_id ];
	}

	/**
	 * Collects the date waits of a step list, branches included: step id => field key.
	 *
	 * @param array<int, mixed>     $steps  Steps.
	 * @param array<string, string> $fields Found waits, appended to.
	 */
	private static function collect( array $steps, array &$fields ): void {
		foreach ( $steps as $step ) {
			$step = (array) $step;
			if ( 'wait' === ( $step['type'] ?? null ) && is_array( $step['until'] ?? null ) ) {
				$key = WaitUntil::key( (string) ( $step['until']['var'] ?? '' ) );
				if ( '' !== $key ) {
					$fields[ (string) $step['id'] ] = $key;
				}
			}
			foreach ( (array) ( $step['branches'] ?? array() ) as $branch ) {
				self::collect( (array) ( ( (array) $branch )['steps'] ?? array() ), $fields );
			}
			self::collect( (array) ( $step['otherwise'] ?? array() ), $fields );
			self::collect( (array) ( $step['steps'] ?? array() ), $fields );
		}
	}
}
