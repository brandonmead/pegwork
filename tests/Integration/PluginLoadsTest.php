<?php
/**
 * Integration test: the plugin loads in WordPress.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * The plugin loads, defines its constants and wires its hooks.
 */
class PluginLoadsTest extends WP_UnitTestCase {

	/**
	 * Constants are defined and point at this plugin.
	 */
	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'PEGWORK_VERSION' ) );
		$this->assertTrue( defined( 'PEGWORK_FILE' ) );
		$this->assertTrue( defined( 'PEGWORK_DIR' ) );
		$this->assertSame( '0.2.0', PEGWORK_VERSION );
		// The API moved to 0.3.0 in Automation v3; the plugin takes the next minor at sign-off (docs/automation-v3-design.md section 3.6).
		$this->assertSame( '0.3.0', \Pegwork\Api\VERSION );
		$this->assertSame( 'pegwork.php', basename( PEGWORK_FILE ) );
		$this->assertFileExists( PEGWORK_DIR . 'pegwork.php' );
	}

	/**
	 * Header version matches the constant.
	 */
	public function test_header_version_matches_constant() {
		$data = get_file_data( PEGWORK_FILE, array( 'Version' => 'Version' ) );
		$this->assertSame( PEGWORK_VERSION, $data['Version'] );
	}

	/**
	 * Boot wired the hooks and init ran them.
	 */
	public function test_boot_wires_hooks() {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( 10, has_action( 'plugins_loaded', array( Plugin::class, 'plugins_loaded' ) ) );
		$this->assertSame( 5, has_action( 'init', array( Plugin::class, 'init' ) ) );
		$this->assertGreaterThan( 0, did_action( 'pegwork_loaded' ) );
	}
}
