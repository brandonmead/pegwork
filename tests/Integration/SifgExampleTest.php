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
	 * Stories link to one or more Projects through the required story_projects relationship.
	 */
	public function test_sifg_story_relates_to_projects() {
		$this->register( dirname( __DIR__, 2 ) . '/examples/sifg' );
		$registry = pegwork_registry();

		$this->assertSame( 'Stories', $registry->content_type( 'sifg_story' )['labels']['plural'] );
		$this->assertTrue( post_type_exists( 'sifg_story' ) );
		$this->assertTrue( post_type_supports( 'sifg_story', 'custom-fields' ), 'Relationship meta needs custom-fields for REST.' );
		$this->assertSame(
			array(
				'cardinality' => 'many_to_many',
				'format'      => 1,
				'from'        => 'sifg_story',
				'key'         => 'story_projects',
				'label'       => 'Projects',
				'required'    => true,
				'storage'     => 'meta',
				'to'          => 'sifg_project',
			),
			$registry->relationships()['story_projects']
		);
		$this->assertSame( array( 'story_projects' ), array_keys( $registry->relationships_for( 'sifg_project' ) ) );
	}

	/**
	 * The directory listing, the two forms, the approval automation and the settings page load as data only.
	 */
	public function test_sifg_sections() {
		$this->register( dirname( __DIR__, 2 ) . '/examples/sifg' );
		$registry = pegwork_registry();

		$listing = $registry->listing( 'sifg_project' );
		$this->assertTrue( $listing['search'] );
		$this->assertSame(
			array(
				'project_type'   => false,
				'project_status' => false,
				'impact'         => true,
				'volunteers'     => false,
			),
			array_column( $listing['filters'], 'multiple', 'key' )
		);
		$this->assertNull( $registry->listing( 'sifg_story' ) );

		$submit = $registry->form( 'submit_project' );
		$this->assertSame( 'pending', $submit['target']['status'] );
		$this->assertSame( 72, $submit['confirm']['expires_hours'] );
		$this->assertSame( '{option:sifg_settings.moderator_email}', $submit['notifications'][0]['to'] );
		$this->assertSame( 'file:sifg', $registry->form_source( 'submit_project' ) );
		$this->assertArrayNotHasKey( 'target', $registry->form( 'request_help' ) );

		$approved = $registry->automation( 'sifg_project_approved' );
		$this->assertTrue( $approved['enabled'] );
		$this->assertSame( array( 'verify', 'notify' ), array_column( $approved['steps'], 'id' ) );

		$settings = $registry->options_page( 'sifg_settings' );
		$this->assertSame( array( 'featured_project', 'moderator_email', 'review_criteria', 'contact_email' ), array_column( $settings['fields'], 'key' ) );
		$this->assertSame( 'sifg_project', $settings['fields'][0]['post_type'] );

		$this->assertFalse( shortcode_exists( 'submit_project' ) );
		$this->assertFalse( get_option( 'sifg_settings', false ), 'Registering the options page stores nothing until it is saved.' );
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
