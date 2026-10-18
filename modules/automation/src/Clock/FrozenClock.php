<?php
/**
 * Settable clock.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * A clock that stands still until moved: tests, and later the CLI `--now` flag.
 */
final class FrozenClock implements Clock {

	/**
	 * The time it shows, in UTC.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Starts the clock.
	 *
	 * @param DateTimeInterface|string $at Time; a string without a zone is read in site time.
	 */
	public function __construct( DateTimeInterface|string $at ) {
		$this->set( $at );
	}

	/**
	 * The time it shows, in UTC.
	 */
	public function now(): DateTimeImmutable {
		return $this->now;
	}

	/**
	 * Moves the clock to a time.
	 *
	 * @param DateTimeInterface|string $at Time; a string without a zone is read in site time.
	 */
	public function set( DateTimeInterface|string $at ): void {
		$time      = $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface( $at ) : new DateTimeImmutable( $at, wp_timezone() );
		$this->now = $time->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Moves the clock forward (or back, with a leading "-") by an ISO 8601 duration, such as P12M or -PT1H.
	 * Calendar units (Y, M, D) move in site time, so P1D across a DST change keeps the site's wall time.
	 *
	 * @param string $duration ISO 8601 duration with an optional sign.
	 */
	public function advance( string $duration ): void {
		$interval  = new DateInterval( ltrim( $duration, '+-' ) );
		$local     = $this->now->setTimezone( wp_timezone() );
		$moved     = str_starts_with( $duration, '-' ) ? $local->sub( $interval ) : $local->add( $interval );
		$this->now = $moved->setTimezone( new DateTimeZone( 'UTC' ) );
	}
}
