<?php
/**
 * `create_record` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use Pegwork\Api\Records;
use PegworkAutomation\Context;

/**
 * Creates a record through the Records API. Output: `steps.<id>.record_id`.
 */
final class CreateRecord implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$type    = (string) $step['content_type'];
		$status  = (string) ( $step['status'] ?? 'draft' );
		$values  = Values::render( (array) ( $step['values'] ?? array() ), $context, $type );
		$summary = array(
			'content_type' => $type,
			'status'       => $status,
			'keys'         => array_keys( $values ),
		);

		$id = Records::create( $type, $values, $status, UpdateRecord::origin( $run ) );
		if ( is_wp_error( $id ) ) {
			return StepResult::fail( (string) $id->get_error_code(), $id->get_error_message(), $summary );
		}
		return StepResult::ok( array( 'record_id' => $id ), array( 'record_id' => $id ) + $summary );
	}
}
