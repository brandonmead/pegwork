<?php
/**
 * Real clock.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The system time.
 */
final class SystemClock implements Clock {

	/**
	 * The current time, in UTC.
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
