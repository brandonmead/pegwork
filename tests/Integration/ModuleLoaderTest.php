<?php
/**
 * Integration tests: the module loader.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Modules\Loader;
use WP_UnitTestCase;

/**
 * Modules boot only when enabled and satisfied; disabled modules' files are never included.
 */
class ModuleLoaderTest extends WP_UnitTestCase {

	/**
	 * Slugs of fixture modules that booted.
	 *
	 * @var string[]
	 */
	private array $booted = array();

	/**
	 * Registers the fixture manifest and a boot recorder.
	 */
	public function set_up() {
		parent::set_up();

		$this->booted = array();
		unset( $GLOBALS['pegwork_tripwire_included'], $GLOBALS['pegwork_needs_sample_included'] );

		add_filter(
			'pegwork_module_manifest',
			static function ( $manifest ) {
				$base = dirname( __DIR__ ) . '/fixtures/modules/';
				return array_merge(
					$manifest,
					array(
						'sample'       => array(
							'label' => 'Sample',
							'path'  => $base . 'sample',
						),
						'tripwire'     => array(
							'label' => 'Tripwire',
							'path'  => $base . 'tripwire',
						),
						'needs-sample' => array(
							'label'    => 'Needs sample',
							'requires' => array( 'sample' ),
							'path'     => $base . 'needs-sample',
						),
					)
				);
			}
		);
		add_action(
			'pegwork_fixture_booted',
			function ( $slug ) {
				$this->booted[] = $slug;
			}
		);
	}

	/**
	 * Removes test globals.
	 */
	public function tear_down() {
		unset( $GLOBALS['pegwork_tripwire_included'], $GLOBALS['pegwork_needs_sample_included'] );
		parent::tear_down();
	}

	/**
	 * Nothing enabled: nothing boots and no fixture file is included.
	 */
	public function test_nothing_loads_when_nothing_is_enabled() {
		$this->assertSame( array(), Loader::load() );
		$this->assertSame( array(), $this->booted );
		$this->assertArrayNotHasKey( 'pegwork_tripwire_included', $GLOBALS );
	}

	/**
	 * An enabled module is included on load but boots only when pegwork_loaded fires, once.
	 */
	public function test_enabled_module_boots_on_pegwork_loaded() {
		update_option( 'pegwork_modules', array( 'sample' ) );

		$this->assertSame( array( 'sample' ), Loader::load() );
		$this->assertSame( array(), $this->booted, 'boot() must not run before pegwork_loaded.' );

		do_action( 'pegwork_loaded' );
		$this->assertSame( array( 'sample' ), $this->booted );

		do_action( 'pegwork_loaded' );
		$this->assertSame( array( 'sample' ), $this->booted, 'boot() runs once.' );
	}

	/**
	 * Module files are included at load time, before boot.
	 */
	public function test_module_file_is_included_before_boot() {
		update_option( 'pegwork_modules', array( 'tripwire' ) );

		Loader::load();

		$this->assertTrue( $GLOBALS['pegwork_tripwire_included'] );
		$this->assertSame( array(), $this->booted );
	}

	/**
	 * A disabled module's file is never included, so its top-level code never runs.
	 */
	public function test_disabled_module_file_is_never_included() {
		update_option( 'pegwork_modules', array( 'sample' ) );

		Loader::load();
		do_action( 'pegwork_loaded' );

		$this->assertArrayNotHasKey( 'pegwork_tripwire_included', $GLOBALS );
		$this->assertSame( array( 'sample' ), $this->booted );

		// Control: the tripwire does fire when its module is enabled.
		update_option( 'pegwork_modules', array( 'sample', 'tripwire' ) );
		Loader::load();
		$this->assertTrue( $GLOBALS['pegwork_tripwire_included'] );
	}

	/**
	 * A module whose requirement is not enabled is skipped without including its file.
	 */
	public function test_missing_requirement_skips_module() {
		update_option( 'pegwork_modules', array( 'needs-sample' ) );

		$this->assertSame( array(), Loader::load() );
		do_action( 'pegwork_loaded' );
		$this->assertSame( array(), $this->booted );
		$this->assertArrayNotHasKey( 'pegwork_needs_sample_included', $GLOBALS );
	}

	/**
	 * Requirements boot first, whatever the stored order.
	 */
	public function test_requirements_boot_before_dependents() {
		update_option( 'pegwork_modules', array( 'needs-sample', 'sample' ) );

		$this->assertSame( array( 'sample', 'needs-sample' ), Loader::load() );
		do_action( 'pegwork_loaded' );
		$this->assertSame( array( 'sample', 'needs-sample' ), $this->booted );
	}

	/**
	 * Unknown slugs are ignored and reported with an escaped notice.
	 */
	public function test_unknown_slug_is_ignored_with_escaped_notice() {
		update_option( 'pegwork_modules', array( 'sample', '<script>alert(1)</script>' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array( 'sample' ), Loader::load() );
		$this->assertIsInt( has_action( 'admin_notices', array( Loader::class, 'print_notices' ) ) );

		ob_start();
		Loader::print_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * Enabling rejects unknown slugs and unmet requirements, and stores nothing.
	 */
	public function test_enable_rejects_unknown_slug_and_missing_requirement() {
		$unknown = Loader::enable( 'nope' );
		$this->assertWPError( $unknown );
		$this->assertSame( 'pegwork_module_unknown', $unknown->get_error_code() );

		$unmet = Loader::enable( 'needs-sample' );
		$this->assertWPError( $unmet );
		$this->assertSame( 'pegwork_module_requires', $unmet->get_error_code() );

		$this->assertSame( array(), Loader::enabled() );
	}

	/**
	 * Enable then disable round-trips through the option.
	 */
	public function test_enable_and_disable_round_trip() {
		$this->assertTrue( Loader::enable( 'sample' ) );
		$this->assertTrue( Loader::enable( 'needs-sample' ) );
		$this->assertSame( array( 'sample', 'needs-sample' ), get_option( 'pegwork_modules' ) );

		$this->assertTrue( Loader::disable( 'needs-sample' ) );
		$this->assertSame( array( 'sample' ), get_option( 'pegwork_modules' ) );
		$this->assertWPError( Loader::disable( 'needs-sample' ) );
	}

	/**
	 * The shipped manifest lists listings and filters (filters requires listings); each path has a module.php
	 * that returns a module with the matching id and requirements.
	 */
	public function test_shipped_manifest_lists_listings_and_filters() {
		$manifest = require dirname( __DIR__, 2 ) . '/src/Modules/manifest.php';

		$this->assertSame( array( 'listings', 'filters' ), array_keys( $manifest ) );
		$this->assertSame( array(), $manifest['listings']['requires'] );
		$this->assertSame( array( 'listings' ), $manifest['filters']['requires'] );

		foreach ( $manifest as $slug => $entry ) {
			$module = require $entry['path'] . '/module.php';
			$this->assertInstanceOf( \Pegwork\Api\Module::class, $module );
			$this->assertSame( $slug, $module->id() );
			$this->assertSame( $entry['requires'], $module->requires() );
		}
	}

	/**
	 * Enabling filters before listings is rejected.
	 */
	public function test_filters_requires_listings() {
		$this->assertWPError( Loader::enable( 'filters' ) );
		$this->assertTrue( Loader::enable( 'listings' ) );
		$this->assertTrue( Loader::enable( 'filters' ) );
	}
}
