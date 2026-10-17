<?php
/**
 * Plugin bootstrap.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork;

/**
 * Wires Pegwork into WordPress. Registry, content and module loading arrive in later PRs.
 */
final class Plugin {

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers the plugin's WordPress hooks. Safe to call more than once.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'plugins_loaded', array( self::class, 'plugins_loaded' ) );
		add_action( 'init', array( self::class, 'init' ), 5 );
	}

	/**
	 * Display name of the plugin.
	 */
	public static function name(): string {
		return __( 'Pegwork Platform for WordPress', 'pegwork' );
	}

	/**
	 * Runs once all plugins are loaded. Module loading (PR 7) hooks in here.
	 */
	public static function plugins_loaded(): void {
	}

	/**
	 * Builds the registry and registers content on init, priority 5.
	 */
	public static function init(): void {
		/**
		 * Fires so packages can add schema sources before the registry loads.
		 */
		do_action( 'pegwork_register' );

		/**
		 * Fires after Pegwork has registered its content.
		 */
		do_action( 'pegwork_loaded' );
	}
}
