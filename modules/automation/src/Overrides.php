<?php
/**
 * Site kill switch.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use WP_Error;

/**
 * Option `pegwork_automation_overrides` (`key => on|off`) overrides an automation's JSON `enabled`
 * without a deploy. Set through toggle() by `wp pegwork automation enable|disable` and by the
 * Automations screen (`manage_options` and a nonce).
 */
final class Overrides {

	/**
	 * Option name.
	 */
	public const OPTION = 'pegwork_automation_overrides';

	/**
	 * All overrides.
	 *
	 * @return array<string, string> Key => on|off.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_filter( $stored, fn( $value, $key ) => is_string( $key ) && in_array( $value, array( 'on', 'off' ), true ), ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * The override of one automation.
	 *
	 * @param string $key Automation key.
	 * @return string|null on, off or null.
	 */
	public static function get( string $key ): ?string {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sets an override.
	 *
	 * @param string $key   Automation key.
	 * @param string $value on or off.
	 */
	public static function set( string $key, string $value ): void {
		$all         = self::all();
		$all[ $key ] = 'on' === $value ? 'on' : 'off';
		ksort( $all );
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Turns a registered automation on or off for this site: the one rule `wp pegwork automation
	 * enable|disable` and the Automations screen share.
	 *
	 * @param string $key   Automation key.
	 * @param string $value on or off.
	 * @return true|WP_Error
	 */
	public static function toggle( string $key, string $value ): bool|WP_Error {
		if ( null === pegwork_registry()->automation( $key ) ) {
			return new WP_Error( 'pegwork_unknown_automation', sprintf( 'Unknown automation: %s.', $key ) );
		}
		self::set( $key, $value );
		return true;
	}

	/**
	 * Whether an automation is effectively enabled: its override, else its JSON `enabled`.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function enabled( array $definition ): bool {
		$override = self::get( (string) $definition['key'] );
		if ( null !== $override ) {
			return 'on' === $override;
		}
		return (bool) ( $definition['enabled'] ?? true );
	}
}
