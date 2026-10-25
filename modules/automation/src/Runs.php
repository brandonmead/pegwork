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
		$values  = array( $this->runs );
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column list and placeholders built above from fixed keys.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i ({$columns}) VALUES ({$holders})", $values ) );

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
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->runs, $id ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * The run of an automation with an idempotency key, with `context` decoded.
	 *
	 * @param string $automation Automation key.
	 * @param string $key        Idempotency key as stored.
	 * @return array<string, mixed>|null
	 */
	public function find_key( string $automation, string $key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE automation = %s AND idempotency_key = %s', $this->runs, $automation, $key ), ARRAY_A );
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
				"UPDATE %i SET status = 'running', claimed_at = %s, started_at = COALESCE(started_at, %s)
				WHERE id = %d AND ( status = 'queued' OR ( status = 'waiting' AND resume_at <= %s ) )",
				$this->runs,
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
	 * Pauses a running run until a time, to continue at a step (a wait, or a retry of the same step).
	 *
	 * @param int                  $id        Run ID.
	 * @param string               $step      Step to run on resume.
	 * @param DateTimeImmutable    $resume_at When it may resume.
	 * @param array<string, mixed> $context   Context.
	 * @param string|null          $error     Last error while waiting for a retry, else null.
	 */
	public function wait( int $id, string $step, DateTimeImmutable $resume_at, array $context, ?string $error = null ): void {
		$this->update(
			$id,
			array(
				'status'       => 'waiting',
				'current_step' => $step,
				'resume_at'    => $this->stamp( $resume_at ),
				'context'      => self::encode_context( $context ),
				'error'        => $error,
			)
		);
	}

	/**
	 * Puts a failed run back in the queue at its failed step (`wp pegwork automation retry`). Atomic: only
	 * a run that is still `failed` is reopened.
	 *
	 * @param int                  $id      Run ID.
	 * @param array<string, mixed> $context Context (with the step's retry count reset).
	 * @return bool Whether it was reopened.
	 */
	public function reopen( int $id, array $context ): bool {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'queued', error = NULL, finished_at = NULL, resume_at = NULL, claimed_at = %s, context = %s
				WHERE id = %d AND status = 'failed'",
				$this->runs,
				$this->stamp(),
				self::encode_context( $context ),
				$id
			)
		);
		return 1 === (int) $wpdb->rows_affected;
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
	 *                                   `summary` (array), `error`, and `iteration` (1-based item number of a
	 *                                   step inside a loop body, else null or absent).
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
				'iteration'   => isset( $step['iteration'] ) ? (int) $step['iteration'] : null, // wpdb writes null as NULL.
				'status'      => (string) $step['status'],
				'started_at'  => (string) $step['started_at'],
				'duration_ms' => max( 0, (int) $step['duration_ms'] ),
				'summary'     => array() === ( $step['summary'] ?? array() ) ? null : wp_json_encode( $step['summary'] ),
				'error'       => $step['error'] ?? null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Step attempts of a run, oldest first, `summary` decoded and `iteration` an int or null.
	 *
	 * @param int $run_id Run ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function steps( int $run_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id', $this->steps, $run_id ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['summary']   = null === $row['summary'] ? array() : (array) json_decode( $row['summary'], true );
			$row['iteration'] = isset( $row['iteration'] ) ? (int) $row['iteration'] : null;
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
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d', $this->steps, $run_id ) );
	}

	/**
	 * Number of attempts of one step in a run; inside a loop body, of that step in one iteration.
	 *
	 * @param int      $run_id    Run ID.
	 * @param string   $step_id   Step ID.
	 * @param int|null $iteration 1-based iteration, or null for a step outside loops.
	 */
	public function attempts( int $run_id, string $step_id, ?int $iteration = null ): int {
		global $wpdb;
		if ( null === $iteration ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND step_id = %s AND iteration IS NULL', $this->steps, $run_id, $step_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %d AND step_id = %s AND iteration = %d', $this->steps, $run_id, $step_id, $iteration ) );
	}

	/**
	 * Waiting runs bound to a record (one query on the `record_id` index): `id`, `automation`, `current_step`.
	 *
	 * @param int $record_id Record ID.
	 * @return array<int, array{id: int, automation: string, current_step: string}>
	 */
	public function waiting_on( int $record_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, automation, current_step FROM %i WHERE record_id = %d AND status = 'waiting'", $this->runs, $record_id ), ARRAY_A );
		return array_map(
			fn( $row ) => array(
				'id'           => (int) $row['id'],
				'automation'   => (string) $row['automation'],
				'current_step' => (string) $row['current_step'],
			),
			$rows
		);
	}

	/**
	 * Moves a waiting run's `resume_at` to now, only while it still waits at a step. Atomic, so a run that
	 * woke or moved on meanwhile is left alone.
	 *
	 * @param int    $id   Run ID.
	 * @param string $step Step the run must still wait at.
	 * @return bool Whether the run was moved.
	 */
	public function nudge( int $id, string $step ): bool {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET resume_at = %s WHERE id = %d AND status = 'waiting' AND current_step = %s",
				$this->runs,
				$this->stamp(),
				$id,
				$step
			)
		);
		return 1 === (int) $wpdb->rows_affected;
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
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE automation = %s AND status = %s ORDER BY id DESC LIMIT %d', $this->runs, $automation, $status, $limit ), ARRAY_A );
		} elseif ( null !== $automation ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE automation = %s ORDER BY id DESC LIMIT %d', $this->runs, $automation, $limit ), ARRAY_A );
		} elseif ( null !== $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d', $this->runs, $status, $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->runs, $limit ), ARRAY_A );
		}
		return array_map( array( self::class, 'decode' ), $rows );
	}

	/**
	 * One page of runs, newest first (the Automations screen's run log).
	 *
	 * @param string|null $automation Automation key filter.
	 * @param string|null $status     Status filter.
	 * @param int         $limit      Rows per page (1 to 1000).
	 * @param int         $offset     Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public function page( ?string $automation, ?string $status, int $limit, int $offset = 0 ): array {
		global $wpdb;
		[ $where, $values ] = self::filter( $automation, $status );
		$values[]           = max( 1, min( 1000, $limit ) );
		$values[]           = max( 0, $offset );
		array_unshift( $values, $this->runs );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds fixed columns with placeholders, one per value.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), $rows );
	}

	/**
	 * Number of runs matching the filters.
	 *
	 * @param string|null $automation Automation key filter.
	 * @param string|null $status     Status filter.
	 */
	public function count( ?string $automation = null, ?string $status = null ): int {
		global $wpdb;
		[ $where, $values ] = self::filter( $automation, $status );
		array_unshift( $values, $this->runs );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where holds fixed columns with placeholders, one per value.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i{$where}", $values ) );
	}

	/**
	 * WHERE clause and values for the run log filters.
	 *
	 * @param string|null $automation Automation key filter.
	 * @param string|null $status     Status filter.
	 * @return array{0: string, 1: array<int, string|int>}
	 */
	private static function filter( ?string $automation, ?string $status ): array {
		$clauses = array();
		$values  = array();
		if ( null !== $automation ) {
			$clauses[] = 'automation = %s';
			$values[]  = $automation;
		}
		if ( null !== $status ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}
		return array( array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ), $values );
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
			$wpdb->prepare(
				'SELECT r.automation, r.status, r.created_at FROM %i r JOIN (SELECT automation, MAX(id) AS id FROM %i GROUP BY automation) m ON r.id = m.id',
				$this->runs,
				$this->runs
			),
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
				"SELECT automation, status, COUNT(*) AS n FROM %i WHERE status IN ('queued','waiting','running') OR ( status = 'failed' AND created_at >= %s ) GROUP BY automation, status",
				$this->runs,
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

		// Completed runs in which `on_error: continue` steps failed count as failures too. The LIKE narrows the
		// rows; the decoded outcome decides (a trigger payload could hold the same text).
		$partial = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT automation, context FROM %i WHERE status = 'completed' AND created_at >= %s AND context LIKE %s",
				$this->runs,
				$failed_since,
				'%' . $wpdb->esc_like( '"outcome":"' . Executor::ERRORS_OUTCOME ) . '%'
			),
			ARRAY_A
		);
		foreach ( $partial as $row ) {
			$context = json_decode( (string) $row['context'], true );
			if ( ! is_array( $context ) || ! str_starts_with( (string) ( $context['outcome'] ?? '' ), Executor::ERRORS_OUTCOME ) ) {
				continue;
			}
			$key = $row['automation'];
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = $base;
			}
			++$out[ $key ]['failed'];
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
				"SELECT id FROM %i WHERE ( status = 'queued' AND COALESCE(claimed_at, created_at) < %s )
				OR ( status = 'waiting' AND resume_at <= %s ) ORDER BY id LIMIT %d",
				$this->runs,
				$idle_before,
				$this->stamp(),
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Open runs whose wake-up is overdue (Site Health): queued with no activity since a time, or waiting
	 * with `resume_at` before it.
	 *
	 * @param string $due_before UTC time.
	 * @return array{count: int, oldest: string|null} Count and the earliest due time (UTC).
	 */
	public function overdue( string $due_before ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, MIN( CASE WHEN status = 'waiting' THEN resume_at ELSE COALESCE(claimed_at, created_at) END ) AS oldest
				FROM %i WHERE ( status = 'queued' AND COALESCE(claimed_at, created_at) < %s ) OR ( status = 'waiting' AND resume_at < %s )",
				$this->runs,
				$due_before,
				$due_before
			),
			ARRAY_A
		);
		return array(
			'count'  => (int) ( $row['n'] ?? 0 ),
			'oldest' => isset( $row['oldest'] ) ? (string) $row['oldest'] : null,
		);
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
			$wpdb->prepare( "SELECT id FROM %i WHERE status = 'running' AND claimed_at < %s ORDER BY id LIMIT 500", $this->runs, $claimed_before )
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
				"UPDATE %i SET status = 'failed', error = %s, finished_at = %s WHERE id = %d AND status = 'running' AND claimed_at < %s",
				$this->runs,
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
			$wpdb->prepare( "SELECT id FROM %i WHERE status IN ({$in}) AND COALESCE(finished_at, created_at) < %s ORDER BY id LIMIT %d", array_merge( array( $this->runs ), $statuses, array( $before, $limit ) ) )
		);
		if ( array() === $ids ) {
			return 0;
		}

		$ids  = array_map( 'intval', $ids );
		$list = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE run_id IN ({$list})", array_merge( array( $this->steps ), $ids ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$list})", array_merge( array( $this->runs ), $ids ) ) );
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
