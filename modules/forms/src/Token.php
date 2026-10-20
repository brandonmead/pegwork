<?php
/**
 * Signed time token.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * `<render time>.<HMAC-SHA256(form key | render time)>`, keyed with the site's nonce salt.
 *
 * Under MIN_SECONDS the submission is treated as a bot (too fast); over MAX_SECONDS the page was open
 * too long. Page caches make the render time older, never newer, so they only weaken the floor.
 */
final class Token {

	/**
	 * Fastest plausible human submission, in seconds.
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Oldest accepted token, in seconds (7 days).
	 */
	public const MAX_SECONDS = 604800;

	/**
	 * Creates a token for a form rendered at a time.
	 *
	 * @param string $form Form key.
	 * @param int    $time Unix render time.
	 */
	public static function create( string $form, int $time ): string {
		return $time . '.' . self::sign( $form, $time );
	}

	/**
	 * Checks a posted token.
	 *
	 * @param string $form  Form key.
	 * @param mixed  $token Posted value.
	 * @param int    $now   Unix time.
	 * @return string `ok`, `too_fast`, `expired` or `invalid` (missing, malformed, wrong form or bad signature).
	 */
	public static function check( string $form, mixed $token, int $now ): string {
		if ( ! is_string( $token ) || ! preg_match( '/^([0-9]{1,12})\.([a-f0-9]{64})$/', $token, $parts ) ) {
			return 'invalid';
		}
		$time = (int) $parts[1];
		if ( ! hash_equals( self::sign( $form, $time ), $parts[2] ) ) {
			return 'invalid';
		}
		$age = $now - $time;
		if ( $age < self::MIN_SECONDS ) {
			return 'too_fast';
		}
		return $age > self::MAX_SECONDS ? 'expired' : 'ok';
	}

	/**
	 * The render time of a posted token that passes (`ok`), else null. A re-render with errors issues its
	 * token for this time again, so a visitor who corrects an error and sends at once is as old as the
	 * first render (which already passed the floor), while a bot posting a fresh page is still too fast.
	 *
	 * @param string $form  Form key.
	 * @param mixed  $token Posted value.
	 * @param int    $now   Unix time.
	 */
	public static function issued( string $form, mixed $token, int $now ): ?int {
		return is_string( $token ) && 'ok' === self::check( $form, $token, $now ) ? (int) strtok( $token, '.' ) : null;
	}

	/**
	 * Signature of a form key and time.
	 *
	 * @param string $form Form key.
	 * @param int    $time Unix time.
	 */
	private static function sign( string $form, int $time ): string {
		return hash_hmac( 'sha256', 'pegwork_form|' . $form . '|' . $time, wp_salt( 'nonce' ) );
	}
}
