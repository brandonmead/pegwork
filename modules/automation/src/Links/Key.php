<?php
/**
 * Link signing key.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Links;

/**
 * The HMAC key for signed links: the `PEGWORK_LINK_SECRET` constant when defined (wp-config.php), else the
 * option `pegwork_link_secret` (32 random bytes, base64, autoload off), generated on first use. Not
 * `wp_salt()`: a salt rotation would silently kill every outstanding link. Never printed, logged or exported
 * (export covers the registry only). Rotating the option invalidates every link sent so far.
 */
final class Key {

	/**
	 * Constant that overrides the option.
	 */
	public const CONSTANT = 'PEGWORK_LINK_SECRET';

	/**
	 * Option holding the generated key.
	 */
	public const OPTION = 'pegwork_link_secret';

	/**
	 * Shortest constant value accepted without a warning from `wp pegwork automation link-key`.
	 */
	public const MIN_CONSTANT_LENGTH = 32;

	/**
	 * Where the key comes from: `constant`, `option`, or `none` (not generated yet).
	 */
	public static function source(): string {
		if ( self::constant() !== null ) {
			return 'constant';
		}
		return self::stored() !== null ? 'option' : 'none';
	}

	/**
	 * The key, generating and storing one on first use.
	 */
	public static function get(): string {
		$constant = self::constant();
		if ( null !== $constant ) {
			return $constant;
		}
		$stored = self::stored();
		if ( null !== $stored ) {
			return $stored;
		}
		// add_option() does nothing when another request created the option first; read back the winner.
		add_option( self::OPTION, self::generate(), '', false );
		$stored = self::stored();
		if ( null === $stored ) {
			// The option holds something unreadable: replace it.
			update_option( self::OPTION, self::generate(), false );
			$stored = (string) self::stored();
		}
		return $stored;
	}

	/**
	 * The key if one exists, without generating it (verification never creates a key).
	 */
	public static function existing(): ?string {
		return self::constant() ?? self::stored();
	}

	/**
	 * Replaces the stored key with a new one; every link sent so far stops working.
	 *
	 * @return bool False when the constant is defined (change it in wp-config.php instead).
	 */
	public static function rotate(): bool {
		if ( null !== self::constant() ) {
			return false;
		}
		if ( false === get_option( self::OPTION ) ) {
			return add_option( self::OPTION, self::generate(), '', false );
		}
		return update_option( self::OPTION, self::generate(), false );
	}

	/**
	 * Length of the constant's value, or null without one (for the CLI warning).
	 */
	public static function constant_length(): ?int {
		$constant = self::constant();
		return null === $constant ? null : strlen( $constant );
	}

	/**
	 * The constant's value, when defined and non-empty.
	 */
	private static function constant(): ?string {
		if ( ! defined( self::CONSTANT ) ) {
			return null;
		}
		$value = constant( self::CONSTANT );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * The stored key (raw bytes), or null.
	 */
	private static function stored(): ?string {
		$value = get_option( self::OPTION );
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$bytes = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Stored key encoding.
		return false === $bytes || strlen( $bytes ) < 16 ? null : $bytes;
	}

	/**
	 * A new key, base64 of 32 random bytes.
	 */
	private static function generate(): string {
		return base64_encode( random_bytes( 32 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Stored key encoding.
	}
}
