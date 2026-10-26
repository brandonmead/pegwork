<?php
/**
 * `trash_record` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use Pegwork\Api\Records;
use PegworkAutomation\Context;
use PegworkAutomation\Templates;

/**
 * Moves one record of `content_type` to the trash through Records::trash(), which never deletes
 * permanently (`trash_disabled` when the site keeps no trash) and emits `record.updated` and
 * `record.status_changed` with origin depth = run depth + 1. The target is the bound record, or the typed
 * reference `record` renders (the validator allows only `{{record.id}}`, a read's `record_id` and a loop
 * item's `id`). A record already in the trash is not a failure: `changed: false`. No output.
 *
 * Limits: at most limit() records moved per run (`context.trashed`), else `trash_limit`; never in a
 * schedule sweep (`trash_in_sweep`, checked again here for a definition from a newer registry). These, a
 * record of another type (`record_type`), a missing record and `trash_disabled` are not retried.
 *
 * Summary: `content_type`, `record_id`, `changed`.
 */
final class TrashRecord implements Step {

	/**
	 * Default per-run cap (one full loop).
	 */
	public const LIMIT = 50;

	/**
	 * Highest cap the filter may set.
	 */
	public const MAX_LIMIT = 200;

	/**
	 * Most records one run may move to the trash.
	 */
	public static function limit(): int {
		/**
		 * Filters the most records one automation run may move to the trash. The value is capped at 200.
		 * A trash past it fails with `trash_limit` (no retry; `on_error` applies).
		 *
		 * @param int $limit Default 50.
		 */
		return min( self::MAX_LIMIT, max( 0, (int) apply_filters( 'pegwork_automation_trash_limit', self::LIMIT ) ) );
	}

	/**
	 * Whether a run is one record of a schedule sweep (`for_each`), or a run that a sweep started through
	 * start_automation, directly or further down the chain (`context.sweep`).
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	public static function in_sweep( array $run ): bool {
		return null !== self::sweep_of( $run );
	}

	/**
	 * The key of the schedule sweep a run belongs to: its own automation for a sweep run, the stored
	 * `context.sweep` for a run a sweep's chain started, else null.
	 *
	 * @param array<string, mixed> $run Run row.
	 */
	public static function sweep_of( array $run ): ?string {
		$stored = $run['context']['sweep'] ?? null;
		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}
		if ( 'schedule' !== ( $run['trigger_type'] ?? '' ) ) {
			return null;
		}
		$key        = (string) ( $run['automation'] ?? '' );
		$definition = pegwork_registry()->automation( $key );
		return null !== ( $run['record_id'] ?? null ) || isset( $definition['trigger']['for_each'] ) ? $key : null;
	}

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$type    = (string) $step['content_type'];
		$bound   = $context->record();
		$id      = isset( $step['record'] ) ? (int) trim( Templates::render( (string) $step['record'], $context ) ) : (int) ( $bound['id'] ?? 0 );
		$summary = array(
			'content_type' => $type,
			'record_id'    => $id > 0 ? $id : null,
		);

		if ( self::in_sweep( $run ) ) {
			return StepResult::fail( 'trash_in_sweep', 'A schedule sweep (for_each) may not move records to the trash; use a schedule with find_records and a loop.', $summary, false );
		}
		if ( $id <= 0 ) {
			return StepResult::fail( 'pegwork_record_not_found', 'No record to move to the trash.', $summary, false );
		}
		$record = Records::get( $id );
		if ( null === $record || 'auto-draft' === $record['status'] ) {
			return StepResult::fail( 'pegwork_record_not_found', sprintf( 'No record with ID %d.', $id ), $summary, false );
		}
		if ( ! $context->can( 'delete_post', $id ) ) {
			return StepResult::fail( 'record_forbidden', sprintf( 'The user who started this run (%1$d) cannot move record %2$d to the trash.', $context->actor(), $id ), $summary, false );
		}
		if ( $type !== $record['type'] ) {
			return StepResult::fail( 'record_type', sprintf( 'Record %1$d is a %2$s; this step moves only %3$s records to the trash.', $id, (string) $record['type'], $type ), $summary, false );
		}
		if ( 'trash' === $record['status'] ) {
			return StepResult::ok( array(), $summary + array( 'changed' => false ) );
		}
		$limit = self::limit();
		if ( $context->trashed() >= $limit ) {
			return StepResult::fail( 'trash_limit', sprintf( 'This run already moved %d records to the trash, the most one run may move.', $limit ), $summary, false );
		}

		$result = Records::trash( $id, UpdateRecord::origin( $run ) );
		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			return StepResult::fail( $code, $result->get_error_message(), $summary, 'pegwork_trash_failed' === $code );
		}
		if ( $result ) {
			$context->add_trashed();
		}
		$context->forget( $id ); // Later reads and loop items treat the record as gone.
		return StepResult::ok( array(), $summary + array( 'changed' => $result ) );
	}
}
