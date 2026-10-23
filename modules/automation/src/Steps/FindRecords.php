<?php
/**
 * `find_records` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;

/**
 * Finds records of `content_type` (Reads::query()): up to `limit` IDs by `sort`, plus `count` and the
 * `sum` of number fields over every match, computed in SQL by core. Outputs `count`, `ids` (the
 * `steps.<id>.records` collection, for `{{#each}}` and loops) and `sum.<field>` (0 with no matches).
 *
 * Summary: `content_type`, `count`, `returned`, the IDs, sums of public fields only, and `empty_operand`
 * when an empty operand matched nothing. Never field values or titles.
 */
final class FindRecords implements Step {

	/**
	 * Default `limit`.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$type    = (string) $step['content_type'];
		$sum     = array_values( array_map( 'strval', (array) ( $step['sum'] ?? array() ) ) );
		$summary = array( 'content_type' => $type );
		$query   = Reads::query( $step, $context ) + array(
			'limit' => (int) ( $step['limit'] ?? self::DEFAULT_LIMIT ),
			'count' => true,
			'sum'   => $sum,
		);

		$found = Reads::find( $type, $query, $summary );
		if ( $found instanceof StepResult ) {
			return $found;
		}

		$ids    = array_values( array_map( 'intval', $found['ids'] ) );
		$sums   = array();
		$public = array_keys( pegwork_registry()->fields( $type, 'public' ) );
		foreach ( $sum as $key ) {
			$sums[ $key ] = (float) ( $found['sums'][ $key ] ?? 0 );
		}

		$summary += array(
			'count'    => (int) $found['count'],
			'returned' => count( $ids ),
			'ids'      => array_slice( $ids, 0, Reads::SUMMARY_IDS ),
		);
		if ( array() !== $sums ) {
			$summary['sum'] = array_intersect_key( $sums, array_flip( $public ) ); // A sum over a private field stays out of the log.
		}
		$empty = $found['empty_operand'] ?? Reads::empty_related( $query );
		if ( null !== $empty ) {
			$summary['empty_operand'] = $empty;
		}
		if ( ! $context->count_reads( count( $ids ) ) ) {
			return Reads::over_limit( count( $ids ), $context, $summary );
		}

		return StepResult::ok(
			array(
				'count' => (int) $found['count'],
				'ids'   => $ids,
				'sum'   => $sums,
			),
			$summary
		);
	}
}
