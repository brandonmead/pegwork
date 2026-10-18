<?php
/**
 * Clock contract.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Clock;

use DateTimeImmutable;

/**
 * The engine's only source of time, so tests (and later `--now`) can simulate it. The engine never calls
 * time() or current_time() for business time; only the per-request time budget uses wall-clock time.
 */
interface Clock {

	/**
	 * The current time, in UTC.
	 */
	public function now(): DateTimeImmutable;
}
