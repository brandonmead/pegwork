<?php
/**
 * Webhook signatures.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Webhooks;

/**
 * The signature scheme shared by outbound deliveries and the inbound endpoint (docs/automation.md,
 * "Verifying Pegwork webhooks"): header `X-Pegwork-Signature: sha256=<hex>`, the HMAC-SHA256 of
 * `<X-Pegwork-Timestamp>.<raw body>` keyed with the automation's secret. Compared with hash_equals.
 */
final class Signature {

	/**
	 * Header names.
	 */
	public const DELIVERY  = 'X-Pegwork-Delivery';
	public const TIMESTAMP = 'X-Pegwork-Timestamp';
	public const SIGNATURE = 'X-Pegwork-Signature';

	/**
	 * Signature prefix.
	 */
	public const PREFIX = 'sha256=';

	/**
	 * Accepted clock difference between the signer and the verifier, in seconds (replay window).
	 */
	public const WINDOW = 300;

	/**
	 * The signature header value.
	 *
	 * @param string $secret    Secret.
	 * @param int    $timestamp Unix time sent in X-Pegwork-Timestamp.
	 * @param string $body      Raw body.
	 */
	public static function sign( string $secret, int $timestamp, string $body ): string {
		return self::PREFIX . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Whether a signature header matches, in constant time.
	 *
	 * @param string $secret    Secret.
	 * @param string $timestamp X-Pegwork-Timestamp as received.
	 * @param string $body      Raw body.
	 * @param string $signature X-Pegwork-Signature as received.
	 */
	public static function verify( string $secret, string $timestamp, string $body, string $signature ): bool {
		if ( ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return false;
		}
		return hash_equals( self::sign( $secret, (int) $timestamp, $body ), trim( $signature ) );
	}

	/**
	 * Whether a timestamp is within the replay window of now.
	 *
	 * @param string $timestamp X-Pegwork-Timestamp as received.
	 * @param int    $now       Unix time.
	 */
	public static function fresh( string $timestamp, int $now ): bool {
		return 1 === preg_match( '/^\d{1,12}$/', $timestamp ) && abs( $now - (int) $timestamp ) <= self::WINDOW;
	}
}
