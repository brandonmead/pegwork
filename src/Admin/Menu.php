<?php
/**
 * Top-level Pegwork admin menu.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

use const Pegwork\Api\ADMIN_MENU;

/**
 * Registers the Pegwork menu with its Overview and Modules screens.
 *
 * Contract for other screens (docs/extension-api.md): call `add_submenu_page( Pegwork\Api\ADMIN_MENU, ... )`
 * on `admin_menu` at the default priority. WordPress treats a submenu `position` as an index into the list
 * built so far, not a sort key, so Overview is added first (priority 9, position 0) and Modules last
 * (priority MODULES_PRIORITY); other screens fall between them in registration order.
 */
final class Menu {

	/**
	 * Capability for the Overview and Modules screens.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Slug of the Modules screen.
	 */
	public const MODULES_SLUG = 'pegwork-modules';

	/**
	 * `admin_menu` priority of the Modules submenu, after every default-priority submenu.
	 */
	public const MODULES_PRIORITY = 999;

	/**
	 * Style handle for the Pegwork screens.
	 */
	public const STYLE = 'pegwork-admin';

	/**
	 * Hook suffixes of the screens this class added.
	 *
	 * @var string[]
	 */
	private static array $screens = array();

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 9 );
		add_action( 'admin_menu', array( self::class, 'add_modules' ), self::MODULES_PRIORITY );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_' . Modules::ACTION, array( Modules::class, 'handle' ) );
	}

	/**
	 * Adds the few styles core lacks for the Pegwork tables (wrapping long codes, spacing field lists).
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::$screens, true ) ) {
			return;
		}
		wp_register_style( self::STYLE, false, array(), PEGWORK_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style(
			self::STYLE,
			'.pegwork-admin .pegwork-table code{overflow-wrap:anywhere;}'
			. '.pegwork-admin h2{margin-top:2em;}'
			. '.pegwork-admin .pegwork-fields{margin:1em 0;}'
			. '.pegwork-admin .pegwork-fields summary{cursor:pointer;font-weight:600;padding:4px 0;}'
			. '.pegwork-admin .pegwork-fields .pegwork-table{margin-top:.5em;}'
			. '.pegwork-admin .pegwork-reason{display:block;margin-top:4px;}'
			. '.pegwork-admin .pegwork-cli-note{margin-top:2em;}'
		);
	}

	/**
	 * Adds the top-level menu and its Overview submenu. Hooked to admin_menu, priority 9.
	 */
	public static function add_menu(): void {
		self::$screens[] = (string) add_menu_page(
			__( 'Pegwork', 'pegwork' ),
			__( 'Pegwork', 'pegwork' ),
			self::CAPABILITY,
			ADMIN_MENU,
			array( Overview::class, 'render' ),
			'dashicons-admin-generic'
		);
		add_submenu_page(
			ADMIN_MENU,
			__( 'Pegwork Overview', 'pegwork' ),
			__( 'Overview', 'pegwork' ),
			self::CAPABILITY,
			ADMIN_MENU,
			array( Overview::class, 'render' ),
			0
		);
	}

	/**
	 * Adds the Modules submenu, last. Hooked to admin_menu, priority MODULES_PRIORITY.
	 */
	public static function add_modules(): void {
		self::$screens[] = (string) add_submenu_page(
			ADMIN_MENU,
			__( 'Pegwork Modules', 'pegwork' ),
			__( 'Modules', 'pegwork' ),
			self::CAPABILITY,
			self::MODULES_SLUG,
			array( Modules::class, 'render' ),
			90
		);
	}
}
