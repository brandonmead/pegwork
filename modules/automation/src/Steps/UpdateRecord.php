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
 * which sanitizes, validates and emits `record.updated` with origin depth = run depth + 1.
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

		$record = null !== $bound && $id === (int) $bound['id'] ? $bound : Records::get( $id );
		if ( null === $record ) {
			return StepResult::fail( 'pegwork_record_not_found', sprintf( 'No record with ID %d.', $id ), array( 'record_id' => $id ) );
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

		if ( null !== $bound && $id === (int) $bound['id'] ) {
			$context->refresh_record();
		}
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
