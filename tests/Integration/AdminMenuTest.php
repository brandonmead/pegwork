<?php
/**
 * Integration tests: the Pegwork admin menu.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The test drives core's admin menu globals and fires admin_menu as core does.

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Menu;
use WP_UnitTestCase;

use const Pegwork\Api\ADMIN_MENU;

/**
 * The menu holds Overview first and Modules last, and lower-capability submenus reach their users.
 */
class AdminMenuTest extends WP_UnitTestCase {

	/**
	 * Loads the admin menu API and resets the menu globals.
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->reset_menu_globals();
	}

	/**
	 * Resets the menu globals for the next test.
	 */
	public function tear_down() {
		$this->reset_menu_globals();
		parent::tear_down();
	}

	/**
	 * Empties the globals add_menu_page() and add_submenu_page() write.
	 */
	private function reset_menu_globals() {
		$GLOBALS['menu']               = array();
		$GLOBALS['submenu']            = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();
		$GLOBALS['_wp_menu_nopriv']    = array();
		$GLOBALS['_registered_pages']  = array();
		$GLOBALS['_parent_pages']      = array();
		$GLOBALS['admin_page_hooks']   = array();
		unset( $GLOBALS['plugin_page'], $GLOBALS['pagenow'] );
	}

	/**
	 * Adds an options-page-like submenu at the documented position, as later PRs will.
	 *
	 * @param string $capability Capability of the submenu.
	 */
	private function add_options_like_submenu( $capability ) {
		add_action(
			'admin_menu',
			static function () use ( $capability ) {
				add_submenu_page( ADMIN_MENU, 'Site settings', 'Site settings', $capability, 'pegwork-options-test', '__return_null', 10 );
			}
		);
	}

	/**
	 * Slugs of the Pegwork submenu, in order.
	 *
	 * @return string[]
	 */
	private function submenu_slugs() {
		return array_values( array_column( $GLOBALS['submenu'][ ADMIN_MENU ] ?? array(), 2 ) );
	}

	/**
	 * The public constant names the menu slug.
	 */
	public function test_admin_menu_constant() {
		$this->assertSame( 'pegwork', ADMIN_MENU );
	}

	/**
	 * An administrator sees the top-level menu with Overview first and Modules last.
	 */
	public function test_admin_sees_overview_first_and_modules_last() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->add_options_like_submenu( 'manage_options' );

		do_action( 'admin_menu', '' );

		$top = array_values( array_filter( $GLOBALS['menu'], fn( $item ) => ADMIN_MENU === $item[2] ) );
		$this->assertCount( 1, $top );
		$this->assertSame( 'manage_options', $top[0][1] );
		$this->assertSame( 'dashicons-admin-generic', $top[0][6] );
		$this->assertSame( array( ADMIN_MENU, 'pegwork-options-test', Menu::MODULES_SLUG ), $this->submenu_slugs() );
		$this->assertSame( array( 'Overview', 'Site settings', 'Modules' ), array_values( array_column( $GLOBALS['submenu'][ ADMIN_MENU ], 0 ) ) );
	}

	/**
	 * An editor sees only a submenu whose capability is lower than manage_options, and it becomes the
	 * menu's first item, which WordPress uses as the top-level link. Overview and Modules stay denied.
	 */
	public function test_lower_capability_submenu_is_promoted_for_editor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->add_options_like_submenu( 'edit_others_posts' );

		do_action( 'admin_menu', '' );

		$this->assertSame( array( 'pegwork-options-test' ), $this->submenu_slugs() );
		$this->assertTrue( isset( $GLOBALS['_wp_submenu_nopriv'][ ADMIN_MENU ][ ADMIN_MENU ] ) );
		$this->assertTrue( isset( $GLOBALS['_wp_submenu_nopriv'][ ADMIN_MENU ][ Menu::MODULES_SLUG ] ) );

		$GLOBALS['pagenow']     = 'admin.php';
		$GLOBALS['plugin_page'] = 'pegwork-options-test';
		$this->assertTrue( user_can_access_admin_page(), 'Editor can open the options page.' );

		$GLOBALS['plugin_page'] = ADMIN_MENU;
		$this->assertFalse( user_can_access_admin_page(), 'Editor cannot open Overview.' );

		$GLOBALS['plugin_page'] = Menu::MODULES_SLUG;
		$this->assertFalse( user_can_access_admin_page(), 'Editor cannot open Modules.' );
	}

	/**
	 * A user with no accessible Pegwork screen gets no Pegwork submenu at all.
	 */
	public function test_subscriber_gets_no_submenu() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->add_options_like_submenu( 'edit_others_posts' );

		do_action( 'admin_menu', '' );

		$this->assertSame( array(), $this->submenu_slugs() );
	}
}
