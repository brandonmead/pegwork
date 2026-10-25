<?php
/**
 * Date waits: target times and wording.
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
 * The arithmetic of a wait `until` a date field (docs/automation-v4-design.md section 3.2): the target is the
 * date at `until.at` in site time, then moved by the signed `until.offset` (in site time, so calendar units
 * keep the wall-clock time across a DST change, as Conditions::offset() and FrozenClock::advance() do). One
 * wait spans at most HORIZON; a longer target is reached by waking at the horizon and deciding again. A run
 * whose date stays empty waits at most HORIZON from its first arrival at the step.
 */
final class WaitUntil {

	/**
	 * Longest single wait, and how long a run waits for an empty date (from its first arrival).
	 */
	public const HORIZON = 'P2Y';

	/**
	 * Default time of day (the normalizer fills it; kept for definitions built in code).
	 */
	public const AT_DEFAULT = '09:00';

	/**
	 * The field key a `record.<key>` var names, or '' when the var has another shape.
	 *
	 * @param string $path Var path.
	 */
	public static function key( string $path ): string {
		$parts = explode( '.', $path );
		return 2 === count( $parts ) && 'record' === $parts[0] ? $parts[1] : '';
	}

	/**
	 * The target instant (UTC) of a date value, or null when the value is not a `Y-m-d` date.
	 *
	 * @param mixed  $date   Field value (`Y-m-d`).
	 * @param string $at     `HH:MM` site time.
	 * @param string $offset Signed ISO 8601 duration, or '' for none.
	 */
	public static function target( mixed $date, string $at = self::AT_DEFAULT, string $offset = '' ): ?DateTimeImmutable {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $at ) ) {
			return null;
		}
		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $at, wp_timezone() );
		if ( ! $local instanceof DateTimeImmutable || $local->format( 'Y-m-d' ) !== $date ) {
			return null;
		}
		return Conditions::offset( $local, $offset )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The time a run waits for: the target, or now plus HORIZON when that is sooner.
	 *
	 * @param DateTimeImmutable $target Target.
	 * @param DateTimeImmutable $now    Now.
	 */
	public static function clamp( DateTimeImmutable $target, DateTimeImmutable $now ): DateTimeImmutable {
		$limit = self::horizon( $now );
		return $target > $limit ? $limit : $target;
	}

	/**
	 * When a run that first arrived at a date wait at `$since` stops waiting for an empty date.
	 *
	 * @param DateTimeImmutable $since First arrival (UTC).
	 */
	public static function horizon( DateTimeImmutable $since ): DateTimeImmutable {
		return Duration::add( $since, self::HORIZON ) ?? $since; // HORIZON always parses.
	}

	/**
	 * A date wait in words: "3 days before Due on at 09:00", "2 hours after due_on at 07:30", "due_on at 09:00".
	 *
	 * @param array<string, mixed> $until The step's `until`.
	 * @param string               $field Field label or key, as the reader should see it.
	 */
	public static function describe( array $until, string $field ): string {
		$at     = (string) ( $until['at'] ?? self::AT_DEFAULT );
		$offset = (string) ( $until['offset'] ?? '' );
		$amount = self::amount( $offset );
		if ( '' === $amount ) {
			/* translators: 1: field label, 2: time of day (HH:MM). */
			return sprintf( __( '%1$s at %2$s', 'pegwork' ), $field, $at );
		}
		return str_starts_with( $offset, '-' )
			/* translators: 1: amount of time such as "3 days", 2: field label, 3: time of day (HH:MM). */
			? sprintf( __( '%1$s before %2$s at %3$s', 'pegwork' ), $amount, $field, $at )
			/* translators: 1: amount of time such as "2 hours", 2: field label, 3: time of day (HH:MM). */
			: sprintf( __( '%1$s after %2$s at %3$s', 'pegwork' ), $amount, $field, $at );
	}

	/**
	 * An unsigned ISO 8601 duration in words ("1 week, 2 days"), or '' when it is zero or does not parse.
	 *
	 * @param string $offset Signed duration.
	 */
	private static function amount( string $offset ): string {
		$bare = ltrim( $offset, '+-' );
		if ( '' === $bare ) {
			return '';
		}
		try {
			$interval = new DateInterval( $bare );
		} catch ( Exception $e ) {
			return '';
		}
		$parts = array();
		$units = array(
			/* translators: %s: number of years. */
			'y' => fn( $n ) => sprintf( _n( '%s year', '%s years', $n, 'pegwork' ), number_format_i18n( $n ) ),
			/* translators: %s: number of months. */
			'm' => fn( $n ) => sprintf( _n( '%s month', '%s months', $n, 'pegwork' ), number_format_i18n( $n ) ),
			/* translators: %s: number of days. */
			'd' => fn( $n ) => sprintf( _n( '%s day', '%s days', $n, 'pegwork' ), number_format_i18n( $n ) ),
			/* translators: %s: number of hours. */
			'h' => fn( $n ) => sprintf( _n( '%s hour', '%s hours', $n, 'pegwork' ), number_format_i18n( $n ) ),
			/* translators: %s: number of minutes. */
			'i' => fn( $n ) => sprintf( _n( '%s minute', '%s minutes', $n, 'pegwork' ), number_format_i18n( $n ) ),
			/* translators: %s: number of seconds. */
			's' => fn( $n ) => sprintf( _n( '%s second', '%s seconds', $n, 'pegwork' ), number_format_i18n( $n ) ),
		);
		foreach ( $units as $unit => $words ) {
			$count = (int) $interval->$unit;
			if ( $count > 0 ) {
				$parts[] = $words( $count );
			}
		}
		/* translators: separator between amounts of time, such as "1 day, 2 hours". */
		return implode( _x( ', ', 'duration separator', 'pegwork' ), $parts );
	}
}
