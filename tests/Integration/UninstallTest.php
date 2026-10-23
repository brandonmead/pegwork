<?php
/**
 * Uninstall tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use WP_UnitTestCase;

/**
 * Security review L-08: uninstall removes Pegwork options and transients (the multisite loop runs the same
 * cleanup on every site). The test suite turns DROP TABLE into a temporary-table drop, so real tables stay
 * for the other tests, and the per-test transaction restores the options.
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Options and transients with the Pegwork prefix are deleted; other options stay.
	 */
	public function test_uninstall_removes_options_and_transients() {
		update_option( 'pegwork_test_option', 'x', false );
		set_transient( 'pegwork_test_transient', 'y', HOUR_IN_SECONDS );
		update_option( 'other_plugin_option', 'z' );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'pegwork/pegwork.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's constant, as uninstall_plugin() sets it.
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertFalse( get_option( 'pegwork_test_option' ) );
		$this->assertFalse( get_transient( 'pegwork_test_transient' ) );
		$this->assertFalse( get_option( '_transient_timeout_pegwork_test_transient' ) );
		$this->assertSame( 'z', get_option( 'other_plugin_option' ) );
	}
}
