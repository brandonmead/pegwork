<?php
/**
 * ISO 8601 durations.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Adds durations (validated at load time by the registry) to engine times. Calendar units (Y, M, W, D) move
 * in site time, so P30D across a DST change keeps the site's wall-clock time, as FrozenClock::advance does.
 */
final class Duration {

	/**
	 * A time plus a duration.
	 *
	 * @param DateTimeImmutable $time     Time.
	 * @param string            $duration ISO 8601 duration, such as P30D or PT15M.
	 * @return DateTimeImmutable|null The result in UTC, or null when the duration does not parse.
	 */
	public static function add( DateTimeImmutable $time, string $duration ): ?DateTimeImmutable {
		try {
			$interval = new DateInterval( $duration );
		} catch ( Exception $e ) {
			return null;
		}
		return $time->setTimezone( wp_timezone() )->add( $interval )->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
