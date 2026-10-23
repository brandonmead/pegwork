<?php
/**
 * Outcome of one step attempt.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use DateTimeImmutable;

/**
 * What a step attempt did. `ok` continues with the next step, `stop` ends the run as completed (a false
 * condition), `branch` continues in the decision branch it names, `wait` pauses the run until a time
 * (status `waiting`, then the next step), `failed` ends it
 * as failed, or, when the failure is retryable and the step's retry policy has attempts left, pauses it
 * and runs the same step again after the backoff (run-step status `retrying`).
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
	 * For a wait: when the run continues with the next step (UTC).
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $resume_at = null;

	/**
	 * For a decision: the branch taken (index, -1 for otherwise), or null when none matched (and for every
	 * other step). Read only by the executor.
	 *
	 * @var int|null
	 */
	public ?int $branch = null;

	/**
	 * For a failure: whether another attempt could succeed (a transport error, not a configuration error).
	 *
	 * @var bool
	 */
	public bool $retryable = false;

	/**
	 * Outputs for `steps.<id>.<key>` paths.
	 *
	 * @var array<string, mixed>
	 */
	public array $output = array();

	/**
	 * Log summary: no private values (keys, IDs, counts, masked addresses).
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
	 * The step succeeded and the run waits until a time before the next step.
	 *
	 * @param DateTimeImmutable    $resume_at When to continue.
	 * @param array<string, mixed> $summary   Log summary.
	 */
	public static function wait( DateTimeImmutable $resume_at, array $summary = array() ): self {
		$result            = new self( 'ok' );
		$result->resume_at = $resume_at;
		$result->summary   = $summary;
		return $result;
	}

	/**
	 * A decision was evaluated: the run continues with the first step of the branch, or after the decision
	 * when the branch is empty or nothing matched.
	 *
	 * @param int|null             $index   Branch index, -1 for otherwise, null for no match.
	 * @param array<string, mixed> $summary Log summary.
	 */
	public static function branch( ?int $index, array $summary = array() ): self {
		$result          = new self( 'ok' );
		$result->branch  = $index;
		$result->summary = $summary;
		return $result;
	}

	/**
	 * The step failed; the run fails, or is retried per the step's policy when the failure is retryable.
	 *
	 * @param string               $code      Error code, such as pegwork_invalid_record.
	 * @param string               $message   Message (no private values).
	 * @param array<string, mixed> $summary   Log summary.
	 * @param bool                 $retryable Whether another attempt could succeed (default true; false for
	 *                                        configuration errors such as an invalid recipient).
	 */
	public static function fail( string $code, string $message, array $summary = array(), bool $retryable = true ): self {
		$result            = new self( 'failed' );
		$result->error     = $code . ': ' . $message;
		$result->summary   = $summary;
		$result->retryable = $retryable;
		return $result;
	}
}
