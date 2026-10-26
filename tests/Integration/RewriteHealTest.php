<?php
/**
 * Integration test: rewrite rules recover after a flush without Pegwork's types, and activation,
 * deactivation and module enable leave the site consistent (QA C2, D1, E4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Registrar;
use Pegwork\Modules\Loader;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * Uses examples/sifg (public Project type with archive `projects`).
 */
class RewriteHealTest extends WP_UnitTestCase {

	/**
	 * Registers examples/sifg with pretty permalinks.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		$this->set_permalink_structure( '/%postname%/' );
		update_option( Registrar::HASH_OPTION, 'stale' );
		Plugin::init();
		delete_transient( Registrar::HEAL_TRANSIENT );
	}

	/**
	 * Rebuilds the registry without the package.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Saved rules without the Project rules, as after a flush in a request where Pegwork did not register its types.
	 */
	private function lose_project_rules(): void {
		$rules = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( 'projects/?$', $rules );
		update_option( 'rewrite_rules', array_filter( $rules, fn( $query ) => ! str_contains( $query, 'sifg_project=' ) && ! str_contains( $query, 'post_type=sifg_project' ) ) );
	}

	/**
	 * QA D1 and C2: missing Pegwork rules are restored on wp_loaded with one flush, and only once per hour.
	 */
	public function test_missing_rules_are_restored_once() {
		$this->lose_project_rules();
		$flushes = 0;
		add_action(
			'generate_rewrite_rules',
			function () use ( &$flushes ) {
				++$flushes;
			}
		);

		Registrar::heal_rewrites();
		$this->assertArrayHasKey( 'projects/?$', get_option( 'rewrite_rules' ) );
		$this->assertSame( 1, $flushes );

		Registrar::heal_rewrites();
		$this->assertSame( 1, $flushes, 'Rules present: no flush.' );

		$this->lose_project_rules();
		Registrar::heal_rewrites();
		$this->assertSame( 1, $flushes, 'A second loss within the hour does not flush again.' );
		$this->assertArrayNotHasKey( 'projects/?$', get_option( 'rewrite_rules' ) );
	}

	/**
	 * Plain permalinks have no rules to check.
	 */
	public function test_plain_permalinks_never_flush() {
		$this->set_permalink_structure( '' );
		Registrar::heal_rewrites();
		$this->assertFalse( get_transient( Registrar::HEAL_TRANSIENT ) );
	}

	/**
	 * QA C2: activation clears the registry hash (the next load syncs and flushes) and marks activation pending;
	 * deactivation drops the saved rules so WordPress rebuilds them without Pegwork's types.
	 */
	public function test_activation_and_deactivation() {
		Plugin::deactivate();
		$this->assertEmpty( get_option( 'rewrite_rules' ) );

		Plugin::activate();
		$this->assertFalse( get_option( Registrar::HASH_OPTION ) );
		$this->assertNotFalse( get_option( Plugin::ACTIVATED_OPTION ) );
	}

	/**
	 * QA E4: pegwork_activated fires once on the first load after activation or a module enable.
	 */
	public function test_activated_fires_once_after_activation_or_module_enable() {
		$before = did_action( 'pegwork_activated' );
		Plugin::fire_activated();
		$this->assertSame( $before, did_action( 'pegwork_activated' ), 'Nothing pending.' );

		Plugin::activate();
		Plugin::fire_activated();
		$this->assertSame( $before + 1, did_action( 'pegwork_activated' ) );
		Plugin::fire_activated();
		$this->assertSame( $before + 1, did_action( 'pegwork_activated' ) );

		$enabled = get_option( Loader::OPTION );
		update_option( Loader::OPTION, array() );
		$this->assertTrue( Loader::enable( 'listings' ) );
		Plugin::fire_activated();
		$this->assertSame( $before + 2, did_action( 'pegwork_activated' ) );
		update_option( Loader::OPTION, $enabled );
	}

	/**
	 * The hooks are wired.
	 */
	public function test_hooks_are_registered() {
		$this->assertNotFalse( has_action( 'wp_loaded', array( Registrar::class, 'heal_rewrites' ) ) );
		$this->assertNotFalse( has_action( 'wp_loaded', array( Plugin::class, 'fire_activated' ) ) );
		$this->assertNotFalse( has_action( 'activate_' . plugin_basename( PEGWORK_FILE ), array( Plugin::class, 'activate' ) ) );
		$this->assertNotFalse( has_action( 'deactivate_' . plugin_basename( PEGWORK_FILE ), array( Plugin::class, 'deactivate' ) ) );
	}
}
