<?php
/**
 * `relate` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use Pegwork\Api\Records;
use PegworkAutomation\Context;
use PegworkAutomation\Templates;

/**
 * Adds (`action` add, the default) or removes one link of a relationship through Records::relate() and
 * Records::unrelate(), which validate it as a save of the "from" record would (types, `one_to_many`,
 * `required`) and emit `record.updated` on the "from" record with origin depth = run depth + 1. `from`
 * defaults to the bound record when it is of the relationship's "from" type. A link that already existed
 * (or was already absent) is not a failure: the summary says `changed: false`. A record in the trash at
 * either end fails `pegwork_record_not_found` (no retry). No output.
 *
 * Summary: `relationship`, `action`, `from`, `to`, `changed`.
 */
final class Relate implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$key          = (string) $step['relationship'];
		$action       = 'remove' === ( $step['action'] ?? 'add' ) ? 'remove' : 'add';
		$relationship = pegwork_registry()->relationships()[ $key ] ?? null;
		$bound        = $context->record();
		if ( isset( $step['from'] ) ) {
			$from = (int) Templates::render( (string) $step['from'], $context );
		} else {
			$from = null !== $bound && ( $relationship['from'] ?? null ) === $bound['type'] ? (int) $bound['id'] : 0;
		}
		$to      = (int) Templates::render( (string) ( $step['to'] ?? '' ), $context );
		$summary = array(
			'relationship' => $key,
			'action'       => $action,
			'from'         => $from,
			'to'           => $to,
		);

		if ( $from <= 0 || $to <= 0 ) {
			return StepResult::fail( 'invalid_id', sprintf( 'The link needs a record at both ends ("from" %1$d, "to" %2$d).', $from, $to ), $summary, false );
		}
		foreach ( array( $from, $to ) as $end ) {
			if ( 'trash' === get_post_status( $end ) ) {
				return StepResult::fail( 'pegwork_record_not_found', sprintf( 'Record %d is in the trash.', $end ), $summary, false );
			}
		}

		$origin = UpdateRecord::origin( $run );
		$result = 'add' === $action ? Records::relate( $key, $from, $to, $origin ) : Records::unrelate( $key, $from, $to, $origin );
		if ( is_wp_error( $result ) ) {
			return StepResult::fail( (string) $result->get_error_code(), $result->get_error_message(), $summary, false );
		}

		$context->forget( $from );
		$context->forget( $to );
		return StepResult::ok( array(), $summary + array( 'changed' => $result ) );
	}
}
