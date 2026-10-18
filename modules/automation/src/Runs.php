<?php
/**
 * Run log storage.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use DateTimeImmutable;
use PegworkAutomation\Clock\Clock;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; rows change constantly, so no object cache.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only the fixed table names are interpolated; every value is a placeholder.

/**
 * Reads and writes `pegwork_runs` and `pegwork_run_steps`. Every value goes through $wpdb->prepare or
 * $wpdb->insert/update. Times are UTC `Y-m-d H:i:s` from the engine clock.
 */
final class Runs {

	/**
	 * Run statuses.
	 */
	public const STATUSES = array( 'queued', 'running', 'waiting', 'completed', 'failed', 'cancelled', 'skipped' );

	/**
	 * Statuses of runs that are not finished; never pruned.
	 */
	public const OPEN = array( 'queued', 'running', 'waiting' );

	/**
	 * Maximum stored size of the JSON context, in bytes (the trigger payload is reduced to fit).
	 */
	public const CONTEXT_MAX = 16384;

	/**
	 * Engine clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Runs table.
	 *
	 * @var string
	 */
	private string $runs;

	/**
	 * Run steps table.
	 *
	 * @var string
	 */
	private string $steps;

	/**
	 * Uses the engine clock for timestamps.
	 *
	 * @param Clock $clock Engine clock.
	 */
	public function __construct( Clock $clock ) {
		$this->clock = $clock;
		$this->runs  = Schema::runs();
		$this->steps = Schema::steps();
	}

	/**
	 * A time as stored (UTC).
	 *
	 * @param DateTimeImmutable|null $time Time, default now.
	 */
	public function stamp( ?DateTimeImmutable $time = null ): string {
		return ( $time ?? $this->clock->now() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Inserts a run.
	 *
	 * @param array<string, mixed> $run `automation`, `trigger_type`, `record_id`, `status`, `depth`, `idempotency_key`,
	 *                                  `definition_hash`, `context` (array), optional `error`, `current_step`.
	 * @return int Run ID, or 0 when a run with the same automation and idempotency key exists.
	 */
	public function insert( array $run ): int {
		global $wpdb;

		$row = array(
			'automation'      => (string) $run['automation'],
			'trigger_type'    => (string) $run['trigger_type'],
			'record_id'       => isset( $run['record_id'] ) ? (int) $run['record_id'] : null,
			'status'          => (string) $run['status'],
			'current_step'    => (string) ( $run['current_step'] ?? '' ),
			'depth'           => min( 255, max( 0, (int) $run['depth'] ) ),
			'idempotency_key' => isset( $run['idempotency_key'] ) ? (string) $run['idempotency_key'] : null,
			'definition_hash' => (string) $run['definition_hash'],
			'context'         => self::encode_context( (array) $run['context'] ),
			'error'           => isset( $run['error'] ) ? (string) $run['error'] : null,
			'created_at'      => $this->stamp(),
			'finished_at'     => in_array( $run['status'], self::OPEN, true ) ? null : $this->stamp(),
		);

		$columns = implode( ', ', array_keys( $row ) );
		$holders = array();
		$values  = array();
		foreach ( $row as $value ) {
			if ( null === $value ) {
				$holders[] = 'NULL';
				continue;
			}
			$holders[] = is_int( $value ) ? '%d' : '%s';
			$values[]  = $value;
		}
		$holders = implode( ', ', $holders );

		// IGNORE: the unique (automation, idempotency_key) index decides duplicates atomically.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Column list and placeholders built above from fixed keys.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$this->runs} ({$columns}) VALUES ({$holders})", $values ) );

		return 1 === (int) $wpdb->rows_affected ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One run, with `context` decoded.
	 *
	 * @param int $id Run ID.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->runs} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Claims a run for execution: queued, or waiting and due. Atomic, so a duplicate wake-up (two WP-Cron
	 * spawns, a re-entrant call) gets false and does nothing.
	 *
	 * @param int $id Run ID.
	 * @return bool Whether this caller owns the run now.
	 */
	public function claim( int $id ): bool {
		global $wpdb;
		$now = $this->stamp();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->runs} SET status = 'running', claimed_at = %s, started_at = COALESCE(started_at, %s)
				WHERE id = %d AND ( status = 'queued' OR ( status = 'waiting' AND resume_at <= %s ) )",
				$now,
				$now,
				$id,
				$now
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Saves the step about to run and the context.
	 *
	 * @param int                  $id      Run ID.
	 * @param string               $step    Step ID.
	 * @param array<string, mixed> $context Context.
	 */
	public function progress( int $id, string $step, array $context ): void {
		$this->update(
			$id,
			array(
				'current_step' => $step,
				'context'      => self::encode_context( $context ),
			)
		);
	}

	/**
	 * Puts a running run back in the queue (time budget used up) to continue at a step.
	 *
	 * @param int                  $id      Run ID.
	 * @param string               $step    Next step ID.
	 * @param array<string, mixed> $context Context.
	 */
	public function requeue( int $id, string $step, array $context ): void {
		$this->update(
			$id,
			array(
				'status'       => 'queued',
				'current_step' => $step,
				'claimed_at'   => $this->stamp(),
				'context'      => self::encode_context( $context ),
			)
		);
	}

	/**
	 * Finishes a run.
	 *
	 * @param int                       $id      Run ID.
	 * @param string                    $status  completed, failed, cancelled or skipped.
	 * @param string|null               $error   Reason or error message.
	 * @param array<string, mixed>|null $context Context, when changed.
	 */
	public function finish( int $id, string $status, ?string $error = null, ?array $context = null ): void {
		$data = array(
			'status'      => $status,
			'finished_at' => $this->stamp(),
			'error'       => $error,
		);
		if ( null !== $context ) {
			$data['context'] = self::encode_context( $context );
		}
		$this->update( $id, $data );
	}

	/**
	 * Records one step attempt.
	 *
	 * @param array<string, mixed> $step `run_id`, `step_id`, `step_type`, `attempt`, `status`, `started_at`, `duration_ms`,
	 *                                   `summary` (array), `error`.
	 */
	public function add_step( array $step ): void {
		global $wpdb;
		$wpdb->insert(
			$this->steps,
			array(
				'run_id'      => (int) $step['run_id'],
				'step_id'     => (string) $step['step_id'],
				'step_type'   => (string) $step['step_type'],
				'attempt'     => (int) ( $step['attempt'] ?? 1 ),
				'status'      => (string) $step['status'],
				'started_at'  => (string) $step['started_at'],
				'duration_ms' => max( 0, (int) $step['duration_ms'] ),
				'summary'     => array() === ( $step['summary'] ?? array() ) ? null : wp_json_encode( $step['summary'] ),
				'error'       => $step['error'] ?? null,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Step attempts of a run, oldest first, `summary` decoded.
	 *
	 * @param int $run_id Run ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function steps( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->steps} WHERE run_id = %d ORDER BY id", $run_id ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['summary'] = null === $row['summary'] ? array() : (array) json_decode( $row['summary'], true );
		}
		return $rows;
	}

	/**
	 * Number of step attempts of a run.
	 *
	 * @param int $run_id Run ID.
	 */
	public function step_count( int $run_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->steps} WHERE run_id = %d", $run_id ) );
	}

	/**
	 * Runs, newest first.
	 *
	 * @param string|null $automation Automation key filter.
	 * @param string|null $status     Status filter.
	 * @param int         $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find( ?string $automation = null, ?string $status = null, int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 1000, $limit ) );

		if ( null !== $automation && null !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->runs} WHERE automation = %s AND status = %s ORDER BY id DESC LIMIT %d", $automation, $status, $limit ), ARRAY_A );
		} elseif ( null !== $automation ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->runs} WHERE automation = %s ORDER BY id DESC LIMIT %d", $automation, $limit ), ARRAY_A );
		} elseif ( null !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->runs} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->runs} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		}
		return array_map( array( self::class, 'decode' ), $rows );
	}

	/**
	 * Per automation: the latest run, open run counts and failures since a time.
	 *
	 * @param string $failed_since UTC time.
	 * @return array<string, array{last_status: string, last_at: string, queued: int, waiting: int, running: int, failed: int}>
	 */
	public function summary( string $failed_since ): array {
		global $wpdb;
		$out  = array();
		$base = array(
			'last_status' => '',
			'last_at'     => '',
			'queued'      => 0,
			'waiting'     => 0,
			'running'     => 0,
			'failed'      => 0,
		);

		$latest = $wpdb->get_results(
			"SELECT r.automation, r.status, r.created_at FROM {$this->runs} r JOIN (SELECT automation, MAX(id) AS id FROM {$this->runs} GROUP BY automation) m ON r.id = m.id",
			ARRAY_A
		);
		foreach ( $latest as $row ) {
			$out[ $row['automation'] ] = array(
				'last_status' => $row['status'],
				'last_at'     => $row['created_at'],
			) + $base;
		}

		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT automation, status, COUNT(*) AS n FROM {$this->runs} WHERE status IN ('queued','waiting','running') OR ( status = 'failed' AND created_at >= %s ) GROUP BY automation, status",
				$failed_since
			),
			ARRAY_A
		);
		foreach ( $counts as $row ) {
			$key = $row['automation'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $base;
			}
			$out[ $key ][ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Runs whose wake-up may be lost: queued with no activity since a time, or waiting and due.
	 *
	 * @param string $idle_before UTC time; queued runs created or last claimed before it.
	 * @param int    $limit       Maximum rows.
	 * @return int[] Run IDs.
	 */
	public function orphans( string $idle_before, int $limit = 500 ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->runs} WHERE ( status = 'queued' AND COALESCE(claimed_at, created_at) < %s )
				OR ( status = 'waiting' AND resume_at <= %s ) ORDER BY id LIMIT %d",
				$idle_before,
				$this->stamp(),
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Runs marked running whose last claim is older than a time (the process died mid-run).
	 *
	 * @param string $claimed_before UTC time.
	 * @return int[] Run IDs.
	 */
	public function stale( string $claimed_before ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$this->runs} WHERE status = 'running' AND claimed_at < %s ORDER BY id LIMIT 500", $claimed_before )
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Marks a stale running run failed, unless it moved on meanwhile.
	 *
	 * @param int    $id             Run ID.
	 * @param string $claimed_before UTC time.
	 * @param string $error          Reason.
	 * @return bool Whether it was marked.
	 */
	public function fail_stale( int $id, string $claimed_before, string $error ): bool {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->runs} SET status = 'failed', error = %s, finished_at = %s WHERE id = %d AND status = 'running' AND claimed_at < %s",
				$error,
				$this->stamp(),
				$id,
				$claimed_before
			)
		);
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Deletes finished runs (and their steps) with given statuses that finished before a time.
	 *
	 * @param string[] $statuses Finished statuses only; open ones are ignored.
	 * @param string   $before   UTC time.
	 * @param int      $limit    Maximum runs per call.
	 * @return int Runs deleted.
	 */
	public function prune( array $statuses, string $before, int $limit = 1000 ): int {
		global $wpdb;
		$statuses = array_values( array_diff( array_intersect( $statuses, self::STATUSES ), self::OPEN ) );
		if ( array() === $statuses ) {
			return 0;
		}

		$in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- Placeholder lists built above, one per value.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$this->runs} WHERE status IN ({$in}) AND COALESCE(finished_at, created_at) < %s ORDER BY id LIMIT %d", array_merge( $statuses, array( $before, $limit ) ) )
		);
		if ( array() === $ids ) {
			return 0;
		}

		$ids  = array_map( 'intval', $ids );
		$list = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->steps} WHERE run_id IN ({$list})", $ids ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->runs} WHERE id IN ({$list})", $ids ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		return count( $ids );
	}

	/**
	 * Updates columns of a run.
	 *
	 * @param int                  $id   Run ID.
	 * @param array<string, mixed> $data Column => value.
	 */
	private function update( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->runs, $data, array( 'id' => $id ) );
	}

	/**
	 * Decodes a run row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$row['id']        = (int) $row['id'];
		$row['depth']     = (int) $row['depth'];
		$row['record_id'] = null === $row['record_id'] ? null : (int) $row['record_id'];
		$context          = json_decode( (string) $row['context'], true );
		$row['context']   = is_array( $context ) ? $context : array();
		return $row;
	}

	/**
	 * JSON for the context column, within CONTEXT_MAX: an oversized trigger payload keeps only its scalar
	 * top-level keys and the `origin`, and is flagged `_truncated`.
	 *
	 * @param array<string, mixed> $context Context.
	 */
	public static function encode_context( array $context ): string {
		$json = (string) wp_json_encode( $context );
		if ( strlen( $json ) <= self::CONTEXT_MAX || ! isset( $context['trigger'] ) || ! is_array( $context['trigger'] ) ) {
			return $json;
		}

		$kept = array( '_truncated' => true );
		foreach ( $context['trigger'] as $key => $value ) {
			if ( is_scalar( $value ) || 'origin' === $key ) {
				$kept[ $key ] = $value;
			}
		}
		$context['trigger'] = $kept;
		return (string) wp_json_encode( $context );
	}
}
