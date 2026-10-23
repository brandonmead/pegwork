<?php
/**
 * `get_record` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Templates;

/**
 * Reads one record of `content_type`: by ID (`record`), or the first by `sort` of `related` and `filter`
 * (Reads::query()). Outputs `found` and `record_id` (null when nothing matched); `steps.<id>.record.<key>`
 * re-reads that record by ID (Context). Not finding a record is not a failure. A record of another type,
 * a status the step does not read, or the trash, is not found.
 *
 * Summary: `content_type`, `found`, `record_id`, and `empty_operand` when an empty operand matched nothing.
 */
final class GetRecord implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$type    = (string) $step['content_type'];
		$summary = array( 'content_type' => $type );
		$empty   = null;

		if ( isset( $step['record'] ) ) {
			$rendered = trim( Templates::render( (string) $step['record'], $context ) );
			$id       = (int) $rendered;
			$record   = $context->record_by_id( $id );
			$id       = null !== $record && $type === $record['type'] && in_array( (string) $record['status'], Reads::statuses( $step ), true ) ? $id : 0;
			$empty    = '' === $rendered ? 'record' : null;
		} else {
			$query = Reads::query( $step, $context ) + array(
				'limit' => 1,
				'count' => false,
			);
			$found = Reads::find( $type, $query, $summary );
			if ( $found instanceof StepResult ) {
				return $found;
			}
			$id    = (int) ( $found['ids'][0] ?? 0 );
			$empty = $found['empty_operand'] ?? Reads::empty_related( $query );
		}

		$summary += array(
			'found'     => $id > 0,
			'record_id' => $id > 0 ? $id : null,
		);
		if ( $id <= 0 && null !== $empty ) {
			$summary['empty_operand'] = $empty;
		}
		if ( ! $context->count_reads( $id > 0 ? 1 : 0 ) ) {
			return Reads::over_limit( 1, $context, $summary );
		}

		return StepResult::ok(
			array(
				'found'     => $id > 0,
				'record_id' => $id > 0 ? $id : null,
			),
			$summary
		);
	}
}
