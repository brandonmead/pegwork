<?php
/**
 * `condition` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Conditions;
use PegworkAutomation\Context;

/**
 * Continues when `when` holds; otherwise the run ends as completed with outcome `stopped_at:<id>`.
 */
final class Condition implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		if ( Conditions::evaluate( (array) ( $step['when'] ?? array() ), $context ) ) {
			return StepResult::ok( array(), array( 'passed' => true ) );
		}
		return StepResult::stop( array( 'passed' => false ) );
	}
}
