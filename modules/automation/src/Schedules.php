<?php
/**
 * Schedule triggers.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ID-ordered keyset paging over posts; WP_Query has no "ID greater than".

/**
 * One self-rescheduling wake-up per enabled schedule automation (`pegwork_automation_schedule` [key]),
 * next time computed by the engine clock in site time. A firing without `for_each` creates one run with no
 * record. With `for_each` it sweeps the content type in ID order, 50 IDs per query, evaluates `where`
 * against each record, and creates one run per match unless the idempotency key exists. `max_runs` (default
 * 200) caps the runs one sweep creates; the excess is logged as a `skipped` run and is picked up next sweep.
 * A sweep that uses up the time budget continues in a chained wake-up (`pegwork_automation_sweep`).
 */
final class Schedules {

	/**
	 * Record IDs per query.
	 */
	public const BATCH = 50;

	/**
	 * Time of day when a daily or weekly schedule has no `at`.
	 */
	public const DEFAULT_AT = '00:00';

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs, scheduler and triggers.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Schedules the next firing of every enabled schedule automation that has none pending, or whose
	 * pending firing is later than its definition now gives (the `every` or `at` changed). Runs on boot.
	 */
	public function ensure(): void {
		$scheduler = $this->engine->scheduler();
		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			if ( 'schedule' !== $definition['trigger']['type'] || ! Overrides::enabled( $definition ) ) {
				continue;
			}
			$next    = $this->next_time( $definition['trigger'] );
			$pending = $scheduler->next( Engine::SCHEDULE_HOOK, array( (string) $key ) );
			if ( null === $pending || $pending > $next ) {
				$scheduler->schedule( Engine::SCHEDULE_HOOK, array( (string) $key ), $next );
			}
		}
	}

	/**
	 * Next firing after now, in site time: `hour` at minute MM of `at` past each hour, `day` at `at`,
	 * `week` at `at` on the site's first day of the week (Settings > General, option `start_of_week`).
	 * Wall-clock times hold across DST changes.
	 *
	 * @param array<string, mixed> $trigger Canonical schedule trigger.
	 * @return int Unix time.
	 */
	public function next_time( array $trigger ): int {
		$now    = $this->engine->clock()->now()->setTimezone( wp_timezone() );
		$parts  = array_map( 'intval', explode( ':', (string) ( $trigger['at'] ?? self::DEFAULT_AT ) ) );
		$hour   = $parts[0];
		$minute = $parts[1] ?? 0;

		switch ( $trigger['every'] ) {
			case 'hour':
				$next = $now->setTime( (int) $now->format( 'G' ), $minute );
				while ( $next <= $now ) {
					$next = $next->modify( '+1 hour' );
				}
				break;
			case 'week':
				$days = ( (int) get_option( 'start_of_week', 1 ) - (int) $now->format( 'w' ) + 7 ) % 7;
				$next = $now->modify( '+' . $days . ' days' )->setTime( $hour, $minute );
				if ( $next <= $now ) {
					$next = $next->modify( '+7 days' )->setTime( $hour, $minute );
				}
				break;
			default:
				$next = $now->setTime( $hour, $minute );
				if ( $next <= $now ) {
					$next = $now->modify( '+1 day' )->setTime( $hour, $minute );
				}
		}
		return $next->getTimestamp();
	}

	/**
	 * Scheduled firing: schedules the next one, then creates the runs. A removed, disabled or no longer
	 * scheduled automation is not rescheduled (boot re-creates the firing when it is enabled again).
	 *
	 * @param string $key Automation key.
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}
	 */
	public function fire( string $key ): array {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition || 'schedule' !== $definition['trigger']['type'] || ! Overrides::enabled( $definition ) ) {
			return self::result();
		}
		$this->engine->scheduler()->schedule( Engine::SCHEDULE_HOOK, array( $key ), $this->next_time( $definition['trigger'] ) );
		return $this->start( $key, $definition );
	}

	/**
	 * Creates the runs of one firing (also `wp pegwork automation run`, which does not move the schedule).
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param int|null             $only       Sweep only this record ID.
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}
	 */
	public function start( string $key, array $definition, ?int $only = null ): array {
		if ( isset( $definition['trigger']['for_each'] ) ) {
			return $this->sweep( $key, $definition, 0, 0, $only );
		}

		$result = self::result();
		$id     = $this->engine->triggers()->enqueue( $this->run_row( $key, $definition, null ), $definition, new Context( $this->engine->clock(), null ) );
		++$result['matched'];
		if ( $id > 0 ) {
			$result['runs'][] = $id;
		} else {
			++$result['duplicates'];
		}
		return $result;
	}

	/**
	 * Sweeps records after an ID. Continues in a chained wake-up when the time budget is used up.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param int                  $after      Last record ID already swept.
	 * @param int                  $created    Runs this sweep already created.
	 * @param int|null             $only       Sweep only this record ID.
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}
	 */
	public function sweep( string $key, array $definition, int $after, int $created, ?int $only = null ): array {
		$for_each = (array) $definition['trigger']['for_each'];
		$max      = max( 1, (int) ( $for_each['max_runs'] ?? 200 ) );
		$result   = self::result();
		$clock    = $this->engine->clock();

		/** This filter is documented in modules/automation/src/Executor.php */
		$deadline = microtime( true ) + (float) apply_filters( 'pegwork_automation_time_budget', Executor::TIME_BUDGET );

		while ( true ) {
			$ids = $this->batch( (string) $for_each['content_type'], (array) ( $for_each['status'] ?? array( 'publish' ) ), $after, $only );
			foreach ( $ids as $id ) {
				$after  = $id;
				$record = Records::get( $id );
				if ( null === $record ) {
					continue;
				}
				$context = new Context( $clock, $record );
				if ( isset( $for_each['where'] ) && ! Conditions::evaluate( (array) $for_each['where'], $context ) ) {
					continue;
				}

				++$result['matched'];
				if ( $created >= $max ) {
					$result['capped'] = true;
					$this->engine->runs()->insert(
						$this->run_row( $key, $definition, null ) + array(
							'status' => 'skipped',
							'error'  => sprintf( 'max_runs: the sweep stopped after %d runs; the remaining records are checked next sweep.', $max ),
						)
					);
					return $result;
				}

				$run_id = $this->engine->triggers()->enqueue( $this->run_row( $key, $definition, $id ), $definition, $context );
				if ( $run_id > 0 ) {
					$result['runs'][] = $run_id;
					++$created;
				} else {
					++$result['duplicates'];
				}
			}

			if ( count( $ids ) < self::BATCH ) {
				return $result;
			}
			if ( microtime( true ) >= $deadline ) {
				$this->engine->scheduler()->schedule( Engine::SWEEP_HOOK, array( $key, $after, $created ), $clock->now()->getTimestamp() );
				$result['continued'] = true;
				return $result;
			}
		}
	}

	/**
	 * Chained sweep wake-up.
	 *
	 * @param string $key     Automation key.
	 * @param int    $after   Last record ID already swept.
	 * @param int    $created Runs this sweep already created.
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}
	 */
	public function resume( string $key, int $after, int $created ): array {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition || ! isset( $definition['trigger']['for_each'] ) || ! Overrides::enabled( $definition ) ) {
			return self::result();
		}
		return $this->sweep( $key, $definition, $after, $created );
	}

	/**
	 * Next IDs of a content type with given statuses, in ID order.
	 *
	 * @param string   $type     Post type.
	 * @param string[] $statuses Post statuses.
	 * @param int      $after    Only IDs above this.
	 * @param int|null $only     Only this ID.
	 * @return int[]
	 */
	private function batch( string $type, array $statuses, int $after, ?int $only ): array {
		global $wpdb;
		$statuses = array_values( array_filter( array_map( 'strval', $statuses ), 'strlen' ) );
		if ( array() === $statuses ) {
			return array();
		}

		$in     = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$where  = null === $only ? '' : ' AND ID = %d';
		$values = array_merge( array( $wpdb->posts, $type ), $statuses, array( $after ), null === $only ? array() : array( $only ), array( self::BATCH ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Placeholder list built above, one per value.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = %s AND post_status IN ({$in}) AND ID > %d{$where} ORDER BY ID LIMIT %d", $values ) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Run row of a schedule firing.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param int|null             $record_id  Bound record.
	 * @return array<string, mixed>
	 */
	private function run_row( string $key, array $definition, ?int $record_id ): array {
		return array(
			'automation'      => $key,
			'trigger_type'    => 'schedule',
			'record_id'       => $record_id,
			'depth'           => 0,
			'definition_hash' => Triggers::hash( $definition ),
			'context'         => array( 'trigger' => array() ),
		);
	}

	/**
	 * Empty firing result.
	 *
	 * @return array{runs: int[], matched: int, duplicates: int, capped: bool, continued: bool}
	 */
	private static function result(): array {
		return array(
			'runs'       => array(),
			'matched'    => 0,
			'duplicates' => 0,
			'capped'     => false,
			'continued'  => false,
		);
	}
}
