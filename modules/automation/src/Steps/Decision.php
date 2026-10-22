<?php
/**
 * `decision` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Conditions;
use PegworkAutomation\Context;

/**
 * Evaluates the branches in order; the first whose `when` holds is taken, else `otherwise` when the decision
 * has one, else none. The executor then runs that branch's steps and continues after the decision.
 *
 * The summary records the path by label (`branch`: the label, "otherwise" or null; `index`: the branch
 * index, -1 or null), so the run log still shows which path ran after the definition changes.
 */
final class Decision implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		foreach ( array_values( (array) ( $step['branches'] ?? array() ) ) as $index => $branch ) {
			if ( Conditions::evaluate( (array) ( $branch['when'] ?? array() ), $context ) ) {
				return StepResult::branch( $index, self::summary( (string) ( $branch['label'] ?? '' ), $index ) );
			}
		}
		if ( isset( $step['otherwise'] ) ) {
			return StepResult::branch( -1, self::summary( 'otherwise', -1 ) );
		}
		return StepResult::branch( null, self::summary( null, null ) );
	}

	/**
	 * Run-step summary of a decision.
	 *
	 * @param string|null $label Branch label, "otherwise", or null for no match.
	 * @param int|null    $index Branch index, -1, or null.
	 * @return array{branch: string|null, index: int|null}
	 */
	public static function summary( ?string $label, ?int $index ): array {
		return array(
			'branch' => $label,
			'index'  => $index,
		);
	}
}
