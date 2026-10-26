<?php
/**
 * `update_record` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use Pegwork\Api\Records;
use PegworkAutomation\Context;
use PegworkAutomation\Templates;

/**
 * Writes values to the bound record (or the record a `record` template names) through the Records API,
 * which sanitizes, validates and emits `record.updated` with origin depth = run depth + 1. A record in the
 * trash (moved there by an earlier trash_record step, or by anyone) fails `pegwork_record_not_found`.
 */
final class UpdateRecord implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$bound = $context->record();
		$id    = isset( $step['record'] ) ? (int) Templates::render( (string) $step['record'], $context ) : (int) ( $bound['id'] ?? 0 );
		if ( $id <= 0 ) {
			return StepResult::fail( 'pegwork_record_not_found', 'No record to update.' );
		}
		if ( 'link' === ( $run['trigger_type'] ?? '' ) && (int) ( $bound['id'] ?? 0 ) !== $id ) {
			// A link click acts on the one record its token names, whatever the step's `record` template says.
			return StepResult::fail( 'link_scope', sprintf( 'A link run may only update its own record (%1$d), not %2$d.', (int) ( $bound['id'] ?? 0 ), $id ), array( 'record_id' => $id ), false );
		}

		$record = null !== $bound && $id === (int) $bound['id'] ? $bound : Records::get( $id );
		if ( null === $record ) {
			return StepResult::fail( 'pegwork_record_not_found', sprintf( 'No record with ID %d.', $id ), array( 'record_id' => $id ) );
		}
		if ( ! $context->can( 'edit_post', $id ) ) {
			return StepResult::fail( 'record_forbidden', sprintf( 'The user who started this run (%1$d) cannot edit record %2$d.', $context->actor(), $id ), array( 'record_id' => $id ), false );
		}
		if ( 'trash' === $record['status'] ) {
			// Records::update() would still write a trashed post; a run treats the trash as gone (no retry).
			return StepResult::fail( 'pegwork_record_not_found', sprintf( 'Record %d is in the trash.', $id ), array( 'record_id' => $id ), false );
		}

		$values  = Values::render( (array) ( $step['values'] ?? array() ), $context, (string) $record['type'] );
		$summary = array(
			'record_id' => $id,
			'keys'      => array_keys( $values ),
		);
		$result  = Records::update( $id, $values, self::origin( $run ) );
		if ( is_wp_error( $result ) ) {
			return StepResult::fail( (string) $result->get_error_code(), $result->get_error_message(), $summary );
		}

		$context->forget( $id ); // Later steps read the written values (the bound record is re-read too).
		return StepResult::ok( array( 'record_id' => $id ), $summary );
	}

	/**
	 * Origin for writes a run makes: its automation, ID and depth + 1.
	 *
	 * @param array<string, mixed> $run Run row.
	 * @return array{automation: string, run_id: int, depth: int}
	 */
	public static function origin( array $run ): array {
		return array(
			'automation' => (string) $run['automation'],
			'run_id'     => (int) $run['id'],
			'depth'      => (int) $run['depth'] + 1,
		);
	}
}
