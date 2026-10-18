<?php
/**
 * `wait` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Duration;

/**
 * Pauses the run for `for` (ISO 8601, PT1H to P2Y). The executor stores `resume_at` and the next step,
 * sets the run `waiting` and schedules a wake-up; the claim only takes a waiting run once `resume_at` has
 * passed, and the daily maintenance (or `wp pegwork automation tick`) resumes it if the wake-up is lost.
 */
final class Wait implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$for    = (string) ( $step['for'] ?? '' );
		$resume = Duration::add( $context->clock()->now(), $for );
		if ( null === $resume ) {
			return StepResult::fail( 'invalid_duration', sprintf( 'The wait duration "%s" is not ISO 8601.', $for ), array(), false );
		}
		return StepResult::wait(
			$resume,
			array(
				'for'       => $for,
				'resume_at' => $resume->format( 'Y-m-d H:i:s' ),
			)
		);
	}
}
