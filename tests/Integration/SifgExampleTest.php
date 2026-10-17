<?php
/**
 * Integration test: examples/sifg loads through pegwork_register.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Notices;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * The worked example registers through the public hook and reads back through pegwork_registry().
 */
class SifgExampleTest extends WP_UnitTestCase {

	/**
	 * Rebuilds the registry without the test's packages (hooks are restored by the parent).
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Registers a package directory and rebuilds the registry, as init does.
	 *
	 * @param string $dir Package directory.
	 */
	private function register( $dir ) {
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( $dir ) );
		Plugin::init();
	}

	/**
	 * The Project type has its 9 fields and 3 taxonomies, and the taxonomies have all their terms.
	 */
	public function test_sifg_loads_via_pegwork_register() {
		$this->register( dirname( __DIR__, 2 ) . '/examples/sifg' );

		$this->assertTrue( Plugin::result()->is_valid() );
		$registry = pegwork_registry();

		$project = $registry->content_type( 'sifg_project' );
		$this->assertSame( 'Project', $project['labels']['singular'] );
		$this->assertSame( 'sifg', Plugin::result()->sources['content_type']['sifg_project'] );

		$this->assertSame(
			array( 'name', 'description', 'who_for', 'website', 'needs_volunteers', 'volunteer_link', 'github_link', 'contact_email', 'last_verified' ),
			array_keys( $registry->fields( 'sifg_project' ) )
		);
		$this->assertSame( array( 'contact_email' ), array_keys( $registry->fields( 'sifg_project', 'private' ) ) );
		$this->assertCount( 8, $registry->fields( 'sifg_project', 'public' ) );
		$this->assertSame( 'post_content', $registry->fields( 'sifg_project' )['description']['wp']['storage'] );

		$this->assertSame( array( 'sifg_project_type', 'sifg_project_status', 'sifg_impact' ), array_column( $project['taxonomies'], 'key' ) );

		$taxonomies = $registry->taxonomies();
		$this->assertSame( array( 'sifg_impact', 'sifg_project_status', 'sifg_project_type' ), array_keys( $taxonomies ) );
		$this->assertSame( array( 'individual', 'nonprofit', 'community-group', 'corporation' ), array_column( $taxonomies['sifg_project_type']['terms'], 'slug' ) );
		$this->assertSame( array( 'idea', 'building', 'piloting', 'active', 'complete', 'paused' ), array_column( $taxonomies['sifg_project_status']['terms'], 'slug' ) );
		$this->assertSame(
			array_map( fn( $n ) => sprintf( 'sdg-%02d', $n ), range( 1, 17 ) ),
			array_column( $taxonomies['sifg_impact']['terms'], 'slug' )
		);
		$this->assertTrue( $taxonomies['sifg_impact']['locked_terms'] );
	}

	/**
	 * The public API version constant is semver.
	 */
	public function test_api_version_constant() {
		$this->assertTrue( defined( 'Pegwork\Api\VERSION' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', \Pegwork\Api\VERSION );
	}

	/**
	 * An invalid package is not registered and administrators see a notice; subscribers do not.
	 */
	public function test_invalid_package_shows_admin_notice() {
		$this->register( dirname( __DIR__ ) . '/fixtures/invalid/reserved-key' );

		$this->assertSame( array(), pegwork_registry()->taxonomies() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Notices::render();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'reserved-key/taxonomies/type.json#/key', $notice );
		$this->assertStringContainsString( '&quot;type&quot; is reserved by WordPress.', $notice );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Notices::render();
		$this->assertSame( '', ob_get_clean() );
	}
}
