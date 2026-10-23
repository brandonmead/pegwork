<?php
/**
 * Submission rate limits.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Throttle;

/**
 * Sliding-window counts per form and subject (client IP or email address), kept by Pegwork\Api\Throttle:
 * atomic under parallel submissions, stored as an HMAC, so raw IPs and addresses are never stored.
 * exceeded() is the cheap early check; take() counts the entry right before it is stored and refuses it
 * when parallel submissions used up the limit in the meantime.
 *
 * Defaults: 5 stored entries per IP per form in 10 minutes (spam included, so a bot cannot fill the
 * table), 3 accepted submissions per email address per form in an hour. Filter `pegwork_forms_rate_limits`.
 */
final class RateLimit {

	/**
	 * Limits by kind, filtered.
	 *
	 * @param string $form Form key.
	 * @return array<string, array{max: int, window: int}>
	 */
	public static function limits( string $form ): array {
		$defaults = array(
			'ip'    => array(
				'max'    => 5,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			'email' => array(
				'max'    => 3,
				'window' => HOUR_IN_SECONDS,
			),
		);

		/**
		 * Filters the submission rate limits of a form.
		 *
		 * @param array  $limits `ip` and `email`, each `{ max, window }` (count, seconds). A max of 0 disables that limit.
		 * @param string $form   Form key.
		 */
		$limits = apply_filters( 'pegwork_forms_rate_limits', $defaults, $form );
		$limits = is_array( $limits ) ? $limits : $defaults;

		foreach ( $defaults as $kind => $default ) {
			$limit           = is_array( $limits[ $kind ] ?? null ) ? $limits[ $kind ] : $default;
			$limits[ $kind ] = array(
				'max'    => max( 0, (int) ( $limit['max'] ?? $default['max'] ) ),
				'window' => max( 1, (int) ( $limit['window'] ?? $default['window'] ) ),
			);
		}

		return $limits;
	}

	/**
	 * Whether another submission would exceed the limit.
	 *
	 * @param string $kind    `ip` or `email`.
	 * @param string $form    Form key.
	 * @param string $subject IP address or email address.
	 * @param int    $now     Unix time.
	 */
	public static function exceeded( string $kind, string $form, string $subject, int $now ): bool {
		$limit = self::limits( $form )[ $kind ] ?? null;
		if ( null === $limit || 0 === $limit['max'] || '' === $subject ) {
			return false;
		}

		return Throttle::wait( self::bucket( $kind, $form, $subject ), $limit['max'], $now ) > 0;
	}

	/**
	 * Seconds until another submission would be within the limit (0 when it already is): when enough of
	 * the hits in the window have aged out of it.
	 *
	 * @param string $kind    `ip` or `email`.
	 * @param string $form    Form key.
	 * @param string $subject IP address or email address.
	 * @param int    $now     Unix time.
	 */
	public static function retry_after( string $kind, string $form, string $subject, int $now ): int {
		$limit = self::limits( $form )[ $kind ] ?? null;
		if ( null === $limit || 0 === $limit['max'] || '' === $subject ) {
			return 0;
		}

		return Throttle::wait( self::bucket( $kind, $form, $subject ), $limit['max'], $now );
	}

	/**
	 * Seconds until fewer than `max` of these hit times are inside the window.
	 *
	 * @param int[] $hits   Hit times inside the window.
	 * @param int   $max    Allowed hits per window.
	 * @param int   $window Window in seconds.
	 * @param int   $now    Unix time.
	 */
	public static function wait( array $hits, int $max, int $window, int $now ): int {
		$excess = count( $hits ) - $max;
		if ( $excess < 0 ) {
			return 0;
		}
		sort( $hits );

		return max( 1, $hits[ $excess ] + $window - $now );
	}

	/**
	 * Counts one submission, atomically, unless the limit is already used up.
	 *
	 * @param string $kind    `ip` or `email`.
	 * @param string $form    Form key.
	 * @param string $subject IP address or email address.
	 * @param int    $now     Unix time.
	 * @return int 0 when counted (store the entry), else seconds until the limit has room.
	 */
	public static function take( string $kind, string $form, string $subject, int $now ): int {
		$limit = self::limits( $form )[ $kind ] ?? null;
		if ( null === $limit || '' === $subject ) {
			return 0;
		}

		return Throttle::take( self::bucket( $kind, $form, $subject ), $limit['max'], $limit['window'], $now );
	}

	/**
	 * The client IP: Throttle::client_ip() (`REMOTE_ADDR`, filter `pegwork_client_ip`), then filtered by
	 * `pegwork_forms_client_ip` (kept for sites that already use it).
	 */
	public static function client_ip(): string {
		/**
		 * Filters the client IP used for form rate limits. Return a proxy header's value only when the
		 * origin accepts traffic from that proxy alone. `pegwork_client_ip` covers every Pegwork limit.
		 *
		 * @param string $ip Client IP from `pegwork_client_ip`.
		 */
		$ip = apply_filters( 'pegwork_forms_client_ip', Throttle::client_ip() );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Throttle bucket of a kind, form and subject (lowercased).
	 *
	 * @param string $kind    Kind.
	 * @param string $form    Form key.
	 * @param string $subject Subject.
	 */
	private static function bucket( string $kind, string $form, string $subject ): string {
		return 'forms|' . $kind . '|' . $form . '|' . strtolower( $subject );
	}
}
