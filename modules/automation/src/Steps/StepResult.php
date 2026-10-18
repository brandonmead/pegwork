<?php
/**
 * Outcome of one step attempt.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

/**
 * What a step attempt did. `ok` continues with the next step, `stop` ends the run as completed (a false
 * condition), `failed` ends it as failed. Later PRs add retry (status `retrying` plus a resume time) and
 * wait results here; the executor already logs any status string it gets.
 */
final class StepResult {

	/**
	 * Run-step status: ok or failed.
	 *
	 * @var string
	 */
	public string $status;

	/**
	 * Whether the run ends here as completed.
	 *
	 * @var bool
	 */
	public bool $stop = false;

	/**
	 * Outputs for `steps.<id>.<key>` paths.
	 *
	 * @var array<string, mixed>
	 */
	public array $output = array();

	/**
	 * Log summary: no private values (keys, IDs, counts).
	 *
	 * @var array<string, mixed>
	 */
	public array $summary = array();

	/**
	 * Error code and message for failures.
	 *
	 * @var string|null
	 */
	public ?string $error = null;

	/**
	 * Sets the status.
	 *
	 * @param string $status Status.
	 */
	private function __construct( string $status ) {
		$this->status = $status;
	}

	/**
	 * The step succeeded.
	 *
	 * @param array<string, mixed> $output  Outputs.
	 * @param array<string, mixed> $summary Log summary.
	 */
	public static function ok( array $output = array(), array $summary = array() ): self {
		$result          = new self( 'ok' );
		$result->output  = $output;
		$result->summary = $summary;
		return $result;
	}

	/**
	 * The step succeeded and the run ends here (completed).
	 *
	 * @param array<string, mixed> $summary Log summary.
	 */
	public static function stop( array $summary = array() ): self {
		$result          = new self( 'ok' );
		$result->stop    = true;
		$result->summary = $summary;
		return $result;
	}

	/**
	 * The step failed; the run fails.
	 *
	 * @param string               $code    Error code, such as pegwork_invalid_record.
	 * @param string               $message Message (no private values).
	 * @param array<string, mixed> $summary Log summary.
	 */
	public static function fail( string $code, string $message, array $summary = array() ): self {
		$result          = new self( 'failed' );
		$result->error   = $code . ': ' . $message;
		$result->summary = $summary;
		return $result;
	}
}
