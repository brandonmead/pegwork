<?php
/**
 * Registers options pages as settings.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Options;

use Pegwork\Api\Registry;

/**
 * Each options page is one setting: option name = page key, group `pegwork_{key}`, not in REST.
 *
 * The sanitize callback keeps valid fields, keeps the stored value of invalid ones and reports each
 * through add_settings_error(). `option_page_capability_pegwork_{key}` lets users with the page's
 * capability save through options.php.
 */
final class Pages {

	/**
	 * Settings group prefix; the group of a page is this plus its key.
	 */
	public const GROUP_PREFIX = 'pegwork_';

	/**
	 * Registry the settings were registered from.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Keys of the pages registered by the last call.
	 *
	 * @var string[]
	 */
	private static array $registered = array();

	/**
	 * Keys skipped because another plugin registered the same option name.
	 *
	 * @var string[]
	 */
	private static array $conflicts = array();

	/**
	 * Registers a setting per options page, unregisters pages that left the registry, and adds the hooks.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		self::$registry = $registry;

		foreach ( array_diff( self::$registered, array_keys( $registry->options_pages() ) ) as $gone ) {
			unregister_setting( self::group( $gone ), $gone );
			remove_filter( 'option_page_capability_' . self::group( $gone ), array( self::class, 'capability' ) );
		}
		self::$registered = array();
		self::$conflicts  = array();

		$settings = get_registered_settings();
		foreach ( $registry->options_pages() as $key => $page ) {
			if ( isset( $settings[ $key ] ) && self::group( $key ) !== ( $settings[ $key ]['group'] ?? null ) ) {
				self::$conflicts[] = $key;
				continue;
			}
			if ( isset( $settings[ $key ] ) ) {
				// Ours from an earlier init: drop it so its sanitize closure is not added twice.
				unregister_setting( self::group( $key ), $key );
			}

			register_setting(
				self::group( $key ),
				$key,
				array(
					'type'              => 'object',
					'label'             => $page['title'],
					'default'           => Values::defaults( $page ),
					'sanitize_callback' => fn( $input ) => self::sanitize( $key, $input ),
					'show_in_rest'      => false,
				)
			);
			if ( ! has_filter( 'option_page_capability_' . self::group( $key ), array( self::class, 'capability' ) ) ) {
				add_filter( 'option_page_capability_' . self::group( $key ), array( self::class, 'capability' ) );
			}
			self::$registered[] = $key;
		}

		if ( ! has_action( 'admin_notices', array( self::class, 'conflict_notice' ) ) ) {
			add_action( 'admin_notices', array( self::class, 'conflict_notice' ) );
		}
	}

	/**
	 * Settings group of a page.
	 *
	 * @param string $key Options page key.
	 */
	public static function group( string $key ): string {
		return self::GROUP_PREFIX . $key;
	}

	/**
	 * Keys of the pages registered as settings (conflicting pages excluded).
	 *
	 * @return string[]
	 */
	public static function registered(): array {
		return self::$registered;
	}

	/**
	 * A registered options page, or null when unknown or skipped for a conflict.
	 *
	 * @param string $key Options page key.
	 * @return array<string, mixed>|null
	 */
	public static function page( string $key ): ?array {
		if ( null === self::$registry || ! in_array( $key, self::$registered, true ) ) {
			return null;
		}

		return self::$registry->options_page( $key );
	}

	/**
	 * Capability options.php requires to save a page's group. Hooked to option_page_capability_pegwork_{key}.
	 *
	 * @param string $capability Capability core would require (manage_options).
	 * @return string
	 */
	public static function capability( $capability ) {
		$group = (string) preg_replace( '/^option_page_capability_/', '', (string) current_filter() );
		$page  = self::page( (string) substr( $group, strlen( self::GROUP_PREFIX ) ) );

		return null === $page ? $capability : (string) $page['capability'];
	}

	/**
	 * Sanitize callback: keeps valid fields, keeps stored values of invalid ones, reports each error.
	 *
	 * Core may call it twice for one save (update_option() then add_option() on the first save), so an error
	 * already reported for a field is not added again.
	 *
	 * @param string $key   Options page key.
	 * @param mixed  $input Submitted value.
	 * @return array<string, mixed> Value to store.
	 */
	public static function sanitize( string $key, mixed $input ): array {
		$stored = Values::stored( $key );
		$page   = self::page( $key );
		if ( null === $page ) {
			return $stored;
		}

		$result = Values::sanitize( $page, $input, $stored );
		if ( ! function_exists( 'add_settings_error' ) ) {
			return $result['values'];
		}

		$reported = array_column( get_settings_errors( $key ), 'code' );
		foreach ( $result['errors'] as $field => $message ) {
			if ( ! in_array( self::error_code( $field ), $reported, true ) ) {
				// Core prints settings error messages unescaped; the message quotes submitted text.
				add_settings_error( $key, self::error_code( $field ), esc_html( $message ), 'error' );
			}
		}
		$saved = array_diff( $result['submitted'], array_keys( $result['errors'] ) );
		if ( $result['errors'] && $saved && ! in_array( 'pegwork_partial', $reported, true ) ) {
			add_settings_error( $key, 'pegwork_partial', esc_html__( 'The other fields were saved.', 'pegwork' ), 'success' );
		}

		return $result['values'];
	}

	/**
	 * Settings error code of a field.
	 *
	 * @param string $field Field key.
	 */
	public static function error_code( string $field ): string {
		return 'pegwork_field_' . $field;
	}

	/**
	 * Tells administrators about pages whose option name another plugin already registered. Hooked to admin_notices.
	 */
	public static function conflict_notice(): void {
		if ( ! self::$conflicts || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: comma-separated option names. */
				__( 'Pegwork did not register these options pages because another plugin already registered a setting with the same option name: %s. Rename the options page key.', 'pegwork' ),
				implode( ', ', self::$conflicts )
			)
		);
		echo '</p></div>';
	}
}
