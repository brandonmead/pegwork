<?php
/**
 * Step contract.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;

/**
 * One step type (`update_record`, `create_record`, `condition`; email, webhook and wait arrive in later PRs).
 * A handler runs one attempt and reports a StepResult; the executor logs it, stores outputs and decides
 * what happens next. Handlers never output private values and never call time() (use the context clock).
 */
interface Step {

	/**
	 * Runs one attempt of a step.
	 *
	 * @param array<string, mixed> $step    Step definition (canonical form from the registry).
	 * @param Context              $context Run context (bound record, trigger, step outputs, clock).
	 * @param array<string, mixed> $run     Run row: `id`, `automation`, `depth`, ...
	 */
	public function run( array $step, Context $context, array $run ): StepResult;
}
