<?php
/**
 * Signed link tokens.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Links;

/**
 * Stateless HMAC-SHA256 tokens: `v1.<base64url(json {a, l, r, e})>.<base64url(HMAC-SHA256(key, "v1." + payload))>`,
 * where `a` is the automation key, `l` the link name, `r` the record ID and `e` the expiry (Unix time).
 * Pure functions of their inputs, so they are unit-tested without WordPress. Verification compares the
 * signature with hash_equals() before reading the payload; expiry is checked by the caller against the
 * engine clock.
 */
final class Token {

	/**
	 * Token version prefix.
	 */
	public const VERSION = 'v1';

	/**
	 * Longest token read; anything longer is invalid without hashing it.
	 */
	public const MAX_LENGTH = 512;

	/**
	 * Key pattern of automation keys and link names.
	 */
	private const KEY = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Mints a token.
	 *
	 * @param string $key        HMAC key.
	 * @param string $automation Automation key.
	 * @param string $link       Link name.
	 * @param int    $record     Record ID.
	 * @param int    $expires    Expiry, Unix time.
	 */
	public static function mint( string $key, string $automation, string $link, int $record, int $expires ): string {
		$payload = self::encode(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure function, also used without WordPress.
				array(
					'a' => $automation,
					'l' => $link,
					'r' => $record,
					'e' => $expires,
				)
			)
		);
		$signed  = self::VERSION . '.' . $payload;
		return $signed . '.' . self::sign( $key, $signed );
	}

	/**
	 * Reads a token whose signature is valid.
	 *
	 * @param string $key   HMAC key.
	 * @param string $token Token.
	 * @return array{automation: string, link: string, record: int, expires: int}|null Null when the token is
	 *         malformed or its signature does not match (tampered, or signed with another key).
	 */
	public static function read( string $key, string $token ): ?array {
		if ( '' === $key || strlen( $token ) > self::MAX_LENGTH ) {
			return null;
		}
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || self::VERSION !== $parts[0] || '' === $parts[1] ) {
			return null;
		}
		if ( ! hash_equals( self::sign( $key, $parts[0] . '.' . $parts[1] ), $parts[2] ) ) {
			return null;
		}

		$data = json_decode( (string) self::decode( $parts[1] ), true );
		if ( ! is_array( $data ) || ! isset( $data['a'], $data['l'], $data['r'], $data['e'] ) ) {
			return null;
		}
		if ( ! is_string( $data['a'] ) || ! preg_match( self::KEY, $data['a'] ) || ! is_string( $data['l'] ) || ! preg_match( self::KEY, $data['l'] ) ) {
			return null;
		}
		if ( ! is_int( $data['r'] ) || $data['r'] <= 0 || ! is_int( $data['e'] ) ) {
			return null;
		}
		return array(
			'automation' => $data['a'],
			'link'       => $data['l'],
			'record'     => $data['r'],
			'expires'    => $data['e'],
		);
	}

	/**
	 * A digest of a token for the run log (single-use check): the token cannot be rebuilt from it.
	 *
	 * @param string $token Token.
	 */
	public static function digest( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Signature of a signed part.
	 *
	 * @param string $key    HMAC key.
	 * @param string $signed Version and payload.
	 */
	private static function sign( string $key, string $signed ): string {
		return self::encode( hash_hmac( 'sha256', $signed, $key, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding.
	}

	/**
	 * Decodes base64url.
	 *
	 * @param string $text Base64url text.
	 * @return string|false
	 */
	private static function decode( string $text ): string|false {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
			return false;
		}
		return base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token encoding.
	}
}
