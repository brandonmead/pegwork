<?php
/**
 * Submission rate limits.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Sliding-window counts per form and subject (an HMAC of the client IP or of the email address), kept
 * in transients. Raw IPs and addresses are never stored.
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

		return count( self::hits( $kind, $form, $subject, $now, $limit['window'] ) ) >= $limit['max'];
	}

	/**
	 * Records one submission.
	 *
	 * @param string $kind    `ip` or `email`.
	 * @param string $form    Form key.
	 * @param string $subject IP address or email address.
	 * @param int    $now     Unix time.
	 */
	public static function hit( string $kind, string $form, string $subject, int $now ): void {
		$limit = self::limits( $form )[ $kind ] ?? null;
		if ( null === $limit || '' === $subject ) {
			return;
		}

		$hits   = self::hits( $kind, $form, $subject, $now, $limit['window'] );
		$hits[] = $now;
		set_transient( self::key( $kind, $form, $subject ), $hits, $limit['window'] );
	}

	/**
	 * The client IP: `REMOTE_ADDR`, filtered by `pegwork_forms_client_ip` (for trusted proxies).
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for rate limits. Return a proxy header's value only when the
		 * origin accepts traffic from that proxy alone.
		 *
		 * @param string $ip `REMOTE_ADDR`.
		 */
		$ip = apply_filters( 'pegwork_forms_client_ip', $remote );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Hit times inside the window.
	 *
	 * @param string $kind    Kind.
	 * @param string $form    Form key.
	 * @param string $subject Subject.
	 * @param int    $now     Unix time.
	 * @param int    $window  Window in seconds.
	 * @return int[]
	 */
	private static function hits( string $kind, string $form, string $subject, int $now, int $window ): array {
		$hits = get_transient( self::key( $kind, $form, $subject ) );
		$hits = is_array( $hits ) ? $hits : array();

		return array_values( array_filter( array_map( 'intval', $hits ), fn( int $time ) => $time > $now - $window ) );
	}

	/**
	 * Transient name: `pegwork_forms_rl_` plus an HMAC of kind, form and subject (lowercased).
	 *
	 * @param string $kind    Kind.
	 * @param string $form    Form key.
	 * @param string $subject Subject.
	 */
	private static function key( string $kind, string $form, string $subject ): string {
		return 'pegwork_forms_rl_' . substr( hash_hmac( 'sha256', $kind . '|' . $form . '|' . strtolower( $subject ), wp_salt( 'nonce' ) ), 0, 40 );
	}
}
