<?php
/**
 * Webhook secrets.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Webhooks;

/**
 * One signing secret per automation, for its outbound `call_webhook` deliveries and its inbound webhook
 * trigger. Stored in option `pegwork_automation_secrets` (`key => secret`, autoload off), generated on first
 * use (64 hex characters from 32 random bytes), never in the registry JSON, exports, the run log or any
 * error message. Removed with the other `pegwork_` options on uninstall.
 */
final class Secrets {

	/**
	 * Option name.
	 */
	public const OPTION = 'pegwork_automation_secrets';

	/**
	 * Random bytes per secret.
	 */
	public const BYTES = 32;

	/**
	 * The secret of an automation, generated and stored on first use.
	 *
	 * @param string $key Automation key.
	 * @return string|null Null when a new secret could not be stored.
	 */
	public static function get( string $key ): ?string {
		$all = self::all();
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		return self::store( $key );
	}

	/**
	 * The stored secret of an automation, without generating one.
	 *
	 * @param string $key Automation key.
	 */
	public static function peek( string $key ): ?string {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Replaces an automation's secret with a new random one: signatures made with the old one stop verifying.
	 *
	 * @param string $key Automation key.
	 * @return string|null The new secret, or null when it could not be stored.
	 */
	public static function rotate( string $key ): ?string {
		return self::store( $key );
	}

	/**
	 * Whether an automation uses a secret: a webhook trigger or a `call_webhook` step.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function used_by( array $definition ): bool {
		if ( 'webhook' === ( $definition['trigger']['type'] ?? '' ) ) {
			return true;
		}
		return in_array( 'call_webhook', array_column( (array) ( $definition['steps'] ?? array() ), 'type' ), true );
	}

	/**
	 * Generates and stores a secret.
	 *
	 * @param string $key Automation key.
	 */
	private static function store( string $key ): ?string {
		$secret      = bin2hex( random_bytes( self::BYTES ) );
		$all         = self::all();
		$all[ $key ] = $secret;
		ksort( $all );

		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $all, '', false );
		} else {
			update_option( self::OPTION, $all, false );
		}
		return ( self::all()[ $key ] ?? null ) === $secret ? $secret : null;
	}

	/**
	 * Every stored secret.
	 *
	 * @return array<string, string>
	 */
	private static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_filter( $stored, fn( $value, $key ) => is_string( $key ) && is_string( $value ) && '' !== $value, ARRAY_FILTER_USE_BOTH );
	}
}
