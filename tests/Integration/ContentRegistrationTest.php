<?php
/**
 * Integration test: post types, taxonomies, seed terms and the registry hash.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Registry;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\PostTypes;
use Pegwork\Content\Registrar;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * Registering examples/sifg yields the Project type, its taxonomies and every seed term.
 */
class ContentRegistrationTest extends WP_UnitTestCase {

	/**
	 * Registers examples/sifg and rebuilds the registry, as init does.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		Plugin::init();
	}

	/**
	 * Rebuilds the registry without the package, which unregisters its content.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * The Project post type carries the registry's WordPress settings.
	 */
	public function test_project_post_type_is_registered() {
		$type = get_post_type_object( 'sifg_project' );

		$this->assertNotNull( $type );
		$this->assertSame( 'Projects', $type->labels->name );
		$this->assertSame( 'Project', $type->labels->singular_name );
		$this->assertTrue( $type->public );
		$this->assertTrue( $type->show_in_rest );
		$this->assertSame( 'projects', $type->rest_base );
		$this->assertTrue( $type->has_archive );
		$this->assertSame( 'projects', $type->rewrite['slug'] );
		$this->assertSame( 'dashicons-lightbulb', $type->menu_icon );
		$this->assertTrue( $type->map_meta_cap );
		$this->assertSame( 'post', $type->capability_type );
		$this->assertSame( 'edit_posts', $type->cap->edit_posts );
		foreach ( array( 'title', 'editor', 'revisions', 'custom-fields' ) as $feature ) {
			$this->assertTrue( post_type_supports( 'sifg_project', $feature ), $feature );
		}
		$this->assertFalse( post_type_supports( 'sifg_project', 'thumbnail' ) );

		$this->set_permalink_structure( '/%postname%/' );
		Plugin::init();
		$this->assertStringEndsWith( '/projects/', get_post_type_archive_link( 'sifg_project' ) );
	}

	/**
	 * The three taxonomies attach to Projects and hold all their seed terms.
	 */
	public function test_taxonomies_have_all_seed_terms() {
		$expected = array(
			'sifg_project_type'   => 4,
			'sifg_project_status' => 6,
			'sifg_impact'         => 17,
		);
		foreach ( $expected as $key => $count ) {
			$taxonomy = get_taxonomy( $key );
			$this->assertNotFalse( $taxonomy, $key );
			$this->assertSame( array( 'sifg_project' ), $taxonomy->object_type, $key );
			$this->assertTrue( $taxonomy->show_in_rest, $key );
			$this->assertCount(
				$count,
				get_terms(
					array(
						'taxonomy'   => $key,
						'hide_empty' => false,
					)
				),
				$key
			);
		}
		$this->assertSame( 'impact', get_taxonomy( 'sifg_impact' )->rewrite['slug'] );
		$this->assertSame( 'Partnerships for the Goals', get_term_by( 'slug', 'sdg-17', 'sifg_impact' )->name );
	}

	/**
	 * Seeding again creates nothing and leaves renamed terms alone.
	 */
	public function test_reseeding_is_a_no_op() {
		$term = get_term_by( 'slug', 'idea', 'sifg_project_status' );
		wp_update_term( $term->term_id, 'sifg_project_status', array( 'name' => 'Just an idea' ) );

		$this->assertSame( 0, Taxonomies::seed( pegwork_registry() ) );
		$this->assertSame( 0, Registrar::sync( pegwork_registry() ) );
		$this->assertCount(
			6,
			get_terms(
				array(
					'taxonomy'   => 'sifg_project_status',
					'hide_empty' => false,
				)
			)
		);
		$this->assertSame( 'Just an idea', get_term( $term->term_id )->name );
	}

	/**
	 * A deleted seed term comes back only when the registry hash changes.
	 */
	public function test_seeding_runs_only_when_hash_changes() {
		$term = get_term_by( 'slug', 'paused', 'sifg_project_status' );
		wp_delete_term( $term->term_id, 'sifg_project_status' );

		Plugin::init();
		$this->assertFalse( get_term_by( 'slug', 'paused', 'sifg_project_status' ) );

		update_option( Registrar::HASH_OPTION, 'stale' );
		Plugin::init();
		$this->assertNotFalse( get_term_by( 'slug', 'paused', 'sifg_project_status' ) );
	}

	/**
	 * The hash is stable for the same registry and changes when the registry changes.
	 */
	public function test_hash_changes_only_with_registry() {
		$hash = get_option( Registrar::HASH_OPTION );
		$this->assertSame( Registrar::hash( pegwork_registry() ), $hash );

		Plugin::init();
		$this->assertSame( $hash, get_option( Registrar::HASH_OPTION ) );
		$this->assertSame( $hash, Registrar::hash( pegwork_registry() ) );

		$this->assertNotSame( $hash, Registrar::hash( new Registry() ) );

		$types                                       = pegwork_registry()->content_types();
		$types['sifg_project']['labels']['singular'] = 'Venture';
		$changed                                     = new Registry( $types, pegwork_registry()->taxonomies(), pegwork_registry()->relationships() );
		$this->assertNotSame( $hash, Registrar::hash( $changed ) );
	}

	/**
	 * A changed registry flushes rewrite rules, so the archive resolves without re-saving permalinks.
	 */
	public function test_rewrite_rules_include_archive_after_sync() {
		$this->set_permalink_structure( '/%postname%/' );
		update_option( Registrar::HASH_OPTION, 'stale' );
		Plugin::init();

		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( 'projects/?$', $rules );
		$this->assertArrayHasKey( 'project-type/([^/]+)/?$', $rules );
	}

	/**
	 * Locked taxonomies need manage_options to manage terms; editors may still assign them.
	 */
	public function test_locked_taxonomy_caps_require_manage_options() {
		$taxonomy = get_taxonomy( 'sifg_impact' );
		foreach ( array( 'manage_terms', 'edit_terms', 'delete_terms' ) as $cap ) {
			$this->assertSame( 'manage_options', $taxonomy->cap->$cap, $cap );
		}
		$this->assertSame( 'edit_posts', $taxonomy->cap->assign_terms );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( user_can( $editor, $taxonomy->cap->manage_terms ) );
		$this->assertTrue( user_can( $editor, $taxonomy->cap->assign_terms ) );
		$this->assertTrue( user_can( $admin, $taxonomy->cap->manage_terms ) );

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered taxonomies.
		$request                   = new \WP_REST_Request( 'POST', '/wp/v2/sifg_impact' );
		$request->set_param( 'name', 'Not allowed' );

		wp_set_current_user( $editor );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertFalse( get_term_by( 'name', 'Not allowed', 'sifg_impact' ) );

		wp_set_current_user( $admin );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
	}

	/**
	 * Content types removed from the registry are unregistered.
	 */
	public function test_removed_types_are_unregistered() {
		remove_all_actions( 'pegwork_register' );
		Plugin::init();

		$this->assertNull( get_post_type_object( 'sifg_project' ) );
		$this->assertFalse( taxonomy_exists( 'sifg_impact' ) );
	}

	/**
	 * Support for custom-fields is added only for types with meta-stored fields.
	 */
	public function test_custom_fields_support_only_with_meta_fields() {
		$type = array(
			'key'    => 'demo_note',
			'labels' => array(
				'singular' => 'Note',
				'plural'   => 'Notes',
			),
			'public' => true,
			'fields' => array(
				array(
					'key' => 'title',
					'wp'  => array( 'storage' => 'post_title' ),
				),
			),
			'wp'     => array( 'supports' => array( 'title' ) ),
		);
		$this->assertSame( array( 'title' ), PostTypes::args( $type )['supports'] );

		$type['fields'][] = array( 'key' => 'note' );
		$this->assertSame( array( 'title', 'custom-fields' ), PostTypes::args( $type )['supports'] );
	}
}
