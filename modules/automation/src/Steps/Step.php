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
 * One step type (`update_record`, `create_record`, `condition`, `send_email`, `wait`; `call_webhook` arrives
 * in a later PR). A handler runs one attempt and reports a StepResult; the executor logs it, stores outputs
 * and decides what happens next (continue, stop, wait, retry or fail). Handlers never put private values in
 * summaries or errors and never call time() (use the context clock).
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
