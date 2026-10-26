<?php
/**
 * Integration test: the Phase 1 gate. Config exports and re-imports cleanly.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Registry\Exporter;
use Pegwork\Registry\Importer;
use Pegwork\Registry\Loader;
use Pegwork\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Exports examples/sifg, removes it, imports the export into the DB source and checks nothing changed.
 */
class ExportImportGateTest extends WP_UnitTestCase {

	/**
	 * Temporary export directories to remove.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Package callback added to pegwork_register, so it can be removed mid-test.
	 *
	 * @var callable|null
	 */
	private $package;

	/**
	 * Removes the DB source and export directories, then rebuilds the registry.
	 */
	public function tear_down() {
		delete_option( Loader::DB_OPTION );
		foreach ( $this->dirs as $dir ) {
			foreach ( array_keys( $this->tree( $dir ) ) as $path ) {
				wp_delete_file( $dir . '/' . $path );
			}
			foreach ( array_merge( glob( $dir . '/*', GLOB_ONLYDIR ), array( $dir ) ) as $sub ) {
				rmdir( $sub ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			}
		}
		parent::tear_down();
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * Re-runs registration as the next request's init would, and rebuilds REST routes.
	 */
	private function reregister() {
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.
	}

	/**
	 * Exports the current registry to a new temporary directory, as `wp pegwork schema export` does.
	 *
	 * @return string Export directory.
	 */
	private function export() {
		$dir          = get_temp_dir() . 'pegwork-export-' . wp_generate_password( 8, false );
		$this->dirs[] = $dir;
		$db           = get_option( Loader::DB_OPTION, array() );
		Exporter::write( $dir, Exporter::files( Plugin::result(), 'all', $db[ Importer::MANIFEST ] ?? null ) );

		return $dir;
	}

	/**
	 * Every file under a directory, as relative path => contents, sorted by path.
	 *
	 * @param string $dir Directory.
	 * @return array<string, string>
	 */
	private function tree( $dir ) {
		$files = array();
		foreach ( glob( $dir . '/{,*/}*.json', GLOB_BRACE ) as $file ) {
			$files[ substr( $file, strlen( $dir ) + 1 ) ] = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test file.
		}
		ksort( $files );

		return $files;
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Body or query params.
	 */
	private function rest( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Edit-context REST responses for the given records, by ID.
	 *
	 * @param int[]  $ids  Record IDs.
	 * @param string $base REST base of their type.
	 * @return array<int, array>
	 */
	private function responses( $ids, $base = 'projects' ) {
		$responses = array();
		foreach ( $ids as $id ) {
			$response = $this->rest( 'GET', "/wp/v2/$base/$id", array( 'context' => 'edit' ) );
			$this->assertSame( 200, $response->get_status() );
			$responses[ $id ] = $response->get_data();
		}

		return $responses;
	}

	/**
	 * Number of terms in the sifg taxonomies, counted in the DB so unregistered taxonomies count too.
	 */
	private function seed_term_count() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( 'sifg_impact', 'sifg_project_status', 'sifg_project_type' )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
	}

	/**
	 * The gate: export, remove the package, import, re-export; everything matches.
	 */
	public function test_export_import_round_trip() {
		// 1. Load examples/sifg as a file package, create 3 Projects, capture R1.
		$this->package = fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' );
		add_action( 'pegwork_register', $this->package );
		$this->reregister();
		$this->assertSame( 'sifg', Plugin::result()->sources['content_type']['sifg_project'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$ids = array();
		foreach ( array( 'Clean water map', 'Food bank routing', 'Tutor matching' ) as $index => $title ) {
			$response = $this->rest(
				'POST',
				'/wp/v2/projects',
				array(
					'title'               => $title,
					'content'             => '<!-- wp:paragraph --><p>' . implode( ' ', array_fill( 0, 60, 'word' ) ) . '</p><!-- /wp:paragraph -->',
					'status'              => 'publish',
					'sifg_project_type'   => array( get_term_by( 'slug', 'nonprofit', 'sifg_project_type' )->term_id ),
					'sifg_project_status' => array( get_term_by( 'slug', 'active', 'sifg_project_status' )->term_id ),
					'sifg_impact'         => array( get_term_by( 'slug', sprintf( 'sdg-%02d', $index + 1 ), 'sifg_impact' )->term_id ),
					'meta'                => array(
						'_sifg_project_contact_email'    => "team$index@example.org",
						'_sifg_project_needs_volunteers' => 1 === $index,
						'_sifg_project_volunteer_link'   => 1 === $index ? 'https://example.org/help' : '',
						'_sifg_project_last_verified'    => '2026-10-17',
					),
				)
			);
			$this->assertSame( 201, $response->get_status(), $title );
			$ids[] = $response->get_data()['id'];
		}
		$stories = array();
		foreach ( array( array( $ids[0], $ids[1] ), array( $ids[2] ) ) as $projects ) {
			$response = $this->rest(
				'POST',
				'/wp/v2/stories',
				array(
					'title'   => 'Story ' . count( $stories ),
					'content' => '<!-- wp:paragraph --><p>It helped.</p><!-- /wp:paragraph -->',
					'status'  => 'publish',
					'meta'    => array( '_rel_story_projects' => $projects ),
				)
			);
			$this->assertSame( 201, $response->get_status() );
			$stories[] = $response->get_data()['id'];
		}
		$r1         = $this->responses( $ids );
		$r1_stories = $this->responses( $stories, 'stories' );
		$r1_links   = wp_list_pluck( wp_list_pluck( $r1_stories, 'meta' ), '_rel_story_projects' );
		$this->assertEqualsCanonicalizing( array( $ids[0], $ids[1] ), $r1_links[ $stories[0] ] );
		$this->assertSame( array( $ids[2] ), $r1_links[ $stories[1] ] );
		$terms = $this->seed_term_count();
		$this->assertSame( 27, $terms );
		$registry = Plugin::registry();
		$sections = array(
			'listing'       => $registry->listing( 'sifg_project' ),
			'forms'         => $registry->forms(),
			'automations'   => $registry->automations(),
			'options_pages' => $registry->options_pages(),
		);
		$this->assertSame( array( 'project_type', 'project_status', 'impact', 'volunteers' ), array_column( $sections['listing']['filters'], 'key' ) );
		$this->assertSame( array( 'request_help', 'submit_project' ), array_keys( $sections['forms'] ) );
		$this->assertSame( array( 'sifg_project_approved', 'sifg_staleness_check' ), array_keys( $sections['automations'] ) );
		$this->assertSame( array( 'sifg_settings' ), array_keys( $sections['options_pages'] ) );
		$this->assertSame( 'file:sifg', $registry->form_source( 'submit_project' ) );

		// Options page values, saved as the settings screen saves them (sanitize callback via update_option).
		update_option(
			'sifg_settings',
			array(
				'featured_project' => (string) $ids[0],
				'moderator_email'  => 'mod@example.org',
				'review_criteria'  => '<p>Clear impact.</p>',
				'contact_email'    => 'hello@example.org',
			)
		);
		$settings = pegwork_get_option( 'sifg_settings' );
		$this->assertSame( $ids[0], $settings['featured_project'] );
		$this->assertSame( 'pegwork_sifg_settings', get_registered_settings()['sifg_settings']['group'] );

		// 2. Export to A.
		$a = $this->export();
		$this->assertSame(
			array(
				'automations/sifg_project_approved.json',
				'automations/sifg_staleness_check.json',
				'content-types/sifg_project.json',
				'content-types/sifg_story.json',
				'forms/request_help.json',
				'forms/submit_project.json',
				'manifest.json',
				'options-pages/sifg_settings.json',
				'relationships/story_projects.json',
				'taxonomies/sifg_impact.json',
				'taxonomies/sifg_project_status.json',
				'taxonomies/sifg_project_type.json',
			),
			array_keys( $this->tree( $a ) )
		);

		// 3. Remove the file package and delete pegwork_schema; sifg_project is unregistered.
		remove_action( 'pegwork_register', $this->package );
		delete_option( Loader::DB_OPTION );
		$this->reregister();
		$this->assertFalse( post_type_exists( 'sifg_project' ) );
		$this->assertFalse( post_type_exists( 'sifg_story' ) );
		$this->assertFalse( taxonomy_exists( 'sifg_impact' ) );
		$this->assertArrayNotHasKey( 'sifg_settings', get_registered_settings() );
		$this->assertNull( pegwork_get_option( 'sifg_settings' ) );
		$this->assertSame( 'hello@example.org', get_option( 'sifg_settings' )['contact_email'], 'Removing the page keeps its stored values.' );

		// 4. Import A, re-run registration, export to B.
		$first = Importer::import( $a );
		$this->assertTrue( $first->is_valid() );
		$this->assertSame( 12, $first->count() ); // Manifest, 2 content types, 3 taxonomies, 1 relationship, 2 forms, 2 automations, 1 options page; terms already exist.
		$this->assertSame( array(), $first->terms );
		$this->reregister();
		$this->assertTrue( post_type_exists( 'sifg_project' ) );
		$this->assertSame( Loader::DB_SOURCE, Plugin::result()->sources['content_type']['sifg_project'] );
		$this->assertSame( get_option( 'pegwork_registry_hash' ), \Pegwork\Content\Registrar::hash( Plugin::registry() ) );
		$b = $this->export();

		// 5. A and B byte-identical; re-import reports 0 changes; R2 equals R1; seed terms unchanged; no anonymous contact_email.
		$this->assertSame( $this->tree( $a ), $this->tree( $b ) );
		$this->assertSame( 0, Importer::import( $a )->count() );
		$this->assertSame( 0, Importer::import( $b, true )->count() );
		$this->assertSame( $r1, $this->responses( $ids ) );
		$r2_stories = $this->responses( $stories, 'stories' );
		$this->assertSame( $r1_stories, $r2_stories );
		$this->assertSame( $r1_links, wp_list_pluck( wp_list_pluck( $r2_stories, 'meta' ), '_rel_story_projects' ) );
		$this->assertSame( Loader::DB_SOURCE, Plugin::result()->sources['relationship']['story_projects'] );
		$this->assertSame( array( $stories[0] ), pegwork_related_ids( $ids[0], 'story_projects' ) );
		$this->assertSame( $terms, $this->seed_term_count() );

		// Listing, forms, automations and options pages come back unchanged, now from the DB source.
		$registry = Plugin::registry();
		$this->assertSame(
			$sections,
			array(
				'listing'       => $registry->listing( 'sifg_project' ),
				'forms'         => $registry->forms(),
				'automations'   => $registry->automations(),
				'options_pages' => $registry->options_pages(),
			)
		);
		$this->assertSame( 'db', $registry->form_source( 'submit_project' ) );
		foreach ( array( 'form', 'automation', 'options_page' ) as $kind ) {
			$this->assertSame( array( Loader::DB_SOURCE ), array_values( array_unique( Plugin::result()->sources[ $kind ] ) ), $kind );
		}

		// The options page is a setting again, with the same values; the binding reads them.
		$this->assertSame( 'pegwork_sifg_settings', get_registered_settings()['sifg_settings']['group'] );
		$this->assertSame( $settings, pegwork_get_option( 'sifg_settings' ) );
		$this->assertSame(
			'hello@example.org',
			\Pegwork\Options\BindingSource::resolve(
				$registry,
				array(
					'page'  => 'sifg_settings',
					'field' => 'contact_email',
				),
				'content'
			)
		);

		wp_set_current_user( 0 );
		foreach ( $ids as $id ) {
			$response = $this->rest( 'GET', "/wp/v2/projects/$id" );
			$this->assertSame( 200, $response->get_status() );
			$this->assertArrayNotHasKey( '_sifg_project_contact_email', $response->get_data()['meta'] );
			$this->assertArrayHasKey( '_sifg_project_website', $response->get_data()['meta'] );
		}
	}

	/**
	 * Phase 6 gate: automations with decisions, nested branches, empty branches and form binding export,
	 * re-import and re-export byte-identically, with defaults filled inside branches.
	 */
	public function test_nested_steps_round_trip() {
		$this->package = fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__ ) . '/fixtures/valid/branching' );
		add_action( 'pegwork_register', $this->package );
		$this->reregister();
		$before = Plugin::registry()->automations();
		$this->assertCount( 8, $before );
		$this->assertSame( array(), Plugin::result()->errors );

		$a    = $this->export();
		$tree = $this->tree( $a );
		$deep = json_decode( $tree['automations/br_deep.json'], true );
		$task = $deep['steps'][0]['branches'][0]['steps'][0]['branches'][0]['steps'][0]['branches'][0]['steps'][0];
		$this->assertSame( array( 'make_task', 'draft' ), array( $task['id'], $task['status'] ), 'create_record defaults fill inside branches.' );
		$this->assertSame( array(), json_decode( $tree['automations/br_paths.json'], true )['steps'][0]['branches'][1]['steps'], 'An empty branch stays.' );

		remove_action( 'pegwork_register', $this->package );
		delete_option( Loader::DB_OPTION );
		$this->reregister();
		$this->assertSame( array(), Plugin::registry()->automations() );

		$this->assertTrue( Importer::import( $a )->is_valid() );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		$this->assertSame( $before, Plugin::registry()->automations() );
		$this->assertSame( $tree, $this->tree( $this->export() ) );
		$this->assertSame( 0, Importer::import( $a )->count() );
	}

	/**
	 * Automation v3 gate: the reads package (relationships, get_record, find_records, loop, relate, on_error,
	 * a manual trigger, call_webhook.record, relationship values and the new template filters) exports,
	 * re-imports into the DB source and re-exports byte-identically, with read, relate and manual defaults filled.
	 */
	public function test_reads_package_round_trip() {
		$fixture       = dirname( __DIR__ ) . '/fixtures/valid/reads';
		$this->package = fn( RegistryBuilder $builder ) => $builder->add_package( $fixture );
		add_action( 'pegwork_register', $this->package );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		$sections = array( 'content_types', 'taxonomies', 'relationships', 'options_pages', 'automations' );
		$before   = array_map( fn( $section ) => Plugin::registry()->$section(), array_combine( $sections, $sections ) );
		$this->assertSame( array( 3, 1, 2, 1, 8 ), array_values( array_map( 'count', $before ) ) );

		$a    = $this->export();
		$tree = $this->tree( $a );
		$this->assertSame(
			array_map( fn( $file ) => substr( $file, strlen( $fixture ) + 1 ), glob( $fixture . '/*/*.json' ) ),
			array_values( array_diff( array_keys( $tree ), array( 'manifest.json' ) ) ),
			'One exported file per fixture object.'
		);
		$thanks = json_decode( $tree['automations/barn_gift_thanks.json'], true );
		$this->assertSame(
			array(
				'sort'   => array(
					'field' => 'id',
					'order' => 'asc',
				),
				'status' => array( 'private', 'publish' ),
			),
			array_intersect_key( $thanks['steps'][0], array_flip( array( 'status', 'sort' ) ) ),
			'get_record defaults.'
		);
		$this->assertSame( 0, $thanks['steps'][5]['limit'] );
		$this->assertSame( 'barn_gift_receipt', $thanks['steps'][4]['automation'] );
		$shapes = json_decode( $tree['automations/reads_shapes.json'], true );
		$this->assertSame( array( 'draft', 'private', 'publish' ), $shapes['steps'][0]['status'], 'Statuses are sorted.' );
		$this->assertSame( 10, $shapes['steps'][4]['limit'], 'find_records limit default.' );
		$this->assertSame( 'add', $shapes['steps'][5]['action'], 'relate action default.' );
		$this->assertSame( 'asc', $shapes['steps'][3]['sort']['order'], 'Sort order default.' );
		$this->assertSame( 'admins', json_decode( $tree['automations/reads_manual_list.json'], true )['trigger']['allow'] );
		$digest = json_decode( $tree['automations/barn_tasks_digest.json'], true );
		$this->assertSame( array( 'publish' ), $digest['steps'][0]['status'] );
		$this->assertSame( array( 'remind', 'stamp' ), array_column( $digest['steps'][1]['steps'], 'id' ), 'Loop bodies export in order.' );

		remove_action( 'pegwork_register', $this->package );
		delete_option( Loader::DB_OPTION );
		$this->reregister();
		$this->assertSame( array(), Plugin::registry()->automations() );

		$this->assertTrue( Importer::import( $a )->is_valid() );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		foreach ( $sections as $section ) {
			$this->assertSame( $before[ $section ], Plugin::registry()->$section(), "$section unchanged." );
		}
		$this->assertSame( $tree, $this->tree( $this->export() ) );
		$this->assertSame( 0, Importer::import( $a )->count() );
	}

	/**
	 * Phase 6, Automation v3 and v4 gate: the BarnCRM proof package (four types, two relationships, an options
	 * page, a form and ten automations with decisions, reads, a loop, manual and webhook triggers, typed
	 * update_record targets, a form-bound trigger, a date wait, trash, a started automation and a role
	 * recipient) exports, re-imports into the DB source and re-exports byte-identically; the export holds one
	 * file per fixture object.
	 */
	public function test_barn_package_round_trip() {
		$fixture       = dirname( __DIR__ ) . '/fixtures/valid/barn';
		$this->package = fn( RegistryBuilder $builder ) => $builder->add_package( $fixture );
		add_action( 'pegwork_register', $this->package );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		$sections = array( 'content_types', 'relationships', 'options_pages', 'forms', 'automations' );
		$before   = array_map( fn( $section ) => Plugin::registry()->$section(), array_combine( $sections, $sections ) );
		$this->assertSame( array( 4, 2, 1, 1, 10 ), array_values( array_map( 'count', $before ) ) );

		$a    = $this->export();
		$tree = $this->tree( $a );
		$this->assertSame(
			array_map( fn( $file ) => substr( $file, strlen( $fixture ) + 1 ), glob( $fixture . '/*/*.json' ) ),
			array_values( array_diff( array_keys( $tree ), array( 'manifest.json' ) ) ),
			'One exported file per fixture object.'
		);
		$thanks = json_decode( $tree['automations/barn_gift_thanks.json'], true );
		$this->assertSame( 'publish', $thanks['steps'][2]['branches'][0]['steps'][1]['status'] );
		$this->assertSame( '{{record.id}}', $thanks['steps'][2]['branches'][0]['steps'][1]['values']['task_gift'] );
		$this->assertSame( '{{steps.donor.record_id}}', $thanks['steps'][3]['record'] );

		remove_action( 'pegwork_register', $this->package );
		delete_option( Loader::DB_OPTION );
		$this->reregister();
		$this->assertSame( array(), Plugin::registry()->automations() );
		$this->assertFalse( post_type_exists( 'barn_gift' ) );

		$this->assertTrue( Importer::import( $a )->is_valid() );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		foreach ( $sections as $section ) {
			$this->assertSame( $before[ $section ], Plugin::registry()->$section(), "$section unchanged." );
		}
		$this->assertTrue( post_type_exists( 'barn_volunteer' ) );
		$this->assertSame( $tree, $this->tree( $this->export() ) );
		$this->assertSame( 0, Importer::import( $a )->count() );
	}

	/**
	 * Automation v4 gate: the v4 shapes package (wait until, trash_record, start_automation with file-to-file
	 * starts, role recipients, read `created`) exports, re-imports into the DB source and re-exports
	 * byte-identically, with `until.at` filled, a zero offset dropped and `created` operands kept as written.
	 */
	public function test_v4_package_round_trip() {
		$fixture       = dirname( __DIR__ ) . '/fixtures/valid/v4';
		$this->package = fn( RegistryBuilder $builder ) => $builder->add_package( $fixture );
		add_action( 'pegwork_register', $this->package );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		$sections = array( 'content_types', 'relationships', 'options_pages', 'forms', 'automations' );
		$before   = array_map( fn( $section ) => Plugin::registry()->$section(), array_combine( $sections, $sections ) );
		$this->assertSame( array( 4, 2, 1, 1, 7 ), array_values( array_map( 'count', $before ) ) );

		$a    = $this->export();
		$tree = $this->tree( $a );
		$this->assertSame(
			array_map( fn( $file ) => substr( $file, strlen( $fixture ) + 1 ), glob( $fixture . '/*/*.json' ) ),
			array_values( array_diff( array_keys( $tree ), array( 'manifest.json' ) ) ),
			'One exported file per fixture object.'
		);
		$shapes = json_decode( $tree['automations/barn_v4_shapes.json'], true );
		$this->assertSame(
			array(
				'at'     => '07:30',
				'offset' => '+PT2H',
				'var'    => 'record.due_on',
			),
			$shapes['steps'][0]['until']
		);
		$this->assertSame(
			array(
				'at'  => '09:00',
				'var' => 'record.reminded_on',
			),
			$shapes['steps'][1]['until'],
			'until.at default; zero offset dropped.'
		);
		$this->assertSame(
			array(
				'after'  => array( 'var' => 'steps.gift.record.received_on' ),
				'before' => '2030-01-01',
			),
			$shapes['steps'][4]['created']
		);
		$this->assertSame( 'barn_gift_receipt', $shapes['steps'][5]['steps'][0]['automation'] );
		$this->assertSame( 'continue', $shapes['steps'][8]['branches'][0]['steps'][0]['on_error'] );
		$review = json_decode( $tree['automations/barn_volunteer_review.json'], true );
		$this->assertSame( '{role:editor}, {option:barn_settings.volunteer_manager}', $review['steps'][0]['to'] );

		remove_action( 'pegwork_register', $this->package );
		delete_option( Loader::DB_OPTION );
		$this->reregister();
		$this->assertSame( array(), Plugin::registry()->automations() );

		$this->assertTrue( Importer::import( $a )->is_valid() );
		$this->reregister();
		$this->assertSame( array(), Plugin::result()->errors );
		foreach ( $sections as $section ) {
			$this->assertSame( $before[ $section ], Plugin::registry()->$section(), "$section unchanged." );
		}
		$this->assertSame( $tree, $this->tree( $this->export() ) );
		$this->assertSame( 0, Importer::import( $a )->count() );
	}

	/**
	 * Import creates missing seed terms by slug and never deletes terms; dry run writes nothing.
	 */
	public function test_import_seeds_missing_terms_and_dry_run_writes_nothing() {
		$dir = dirname( __DIR__, 2 ) . '/examples/sifg';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) ); // Locked taxonomies: only admins add terms.

		$dry = Importer::import( $dir, false, true );
		$this->assertTrue( $dry->is_valid() );
		$this->assertFalse( get_option( Loader::DB_OPTION ) );
		$this->assertFalse( post_type_exists( 'sifg_project' ) );
		$this->assertSame( 12, count( $dry->changes ) );

		Importer::import( $dir );
		$this->assertTrue( taxonomy_exists( 'sifg_impact' ) );
		$this->assertSame( 27, $this->seed_term_count() );

		$extra = wp_insert_term( 'Extra', 'sifg_project_type', array( 'slug' => 'extra' ) );
		wp_delete_term( get_term_by( 'slug', 'idea', 'sifg_project_status' )->term_id, 'sifg_project_status' );

		$dry = Importer::import( $dir, false, true );
		$this->assertSame( array(), $dry->changes );
		$this->assertSame(
			array(
				array(
					'taxonomy' => 'sifg_project_status',
					'slug'     => 'idea',
				),
			),
			$dry->terms
		);
		$this->assertFalse( get_term_by( 'slug', 'idea', 'sifg_project_status' ) );

		$this->assertSame( 1, Importer::import( $dir )->count() );
		$this->assertInstanceOf( 'WP_Term', get_term_by( 'slug', 'idea', 'sifg_project_status' ) );
		$this->assertInstanceOf( 'WP_Term', get_term( $extra['term_id'] ) );
		$this->assertSame( 0, Importer::import( $dir )->count() );
	}

	/**
	 * An invalid package is rejected and nothing is written.
	 */
	public function test_invalid_import_writes_nothing() {
		$plan = Importer::import( dirname( __DIR__ ) . '/fixtures/invalid/callable' );

		$this->assertFalse( $plan->is_valid() );
		$this->assertFalse( get_option( Loader::DB_OPTION ) );
	}
}
