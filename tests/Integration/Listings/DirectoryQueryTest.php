<?php
/**
 * Integration tests: Pegwork Query Loops, the archive main query, presets and generated patterns.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Listings;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use PegworkListings\Constraints;
use PegworkListings\Patterns;
use PegworkListings\QueryHooks;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The constraint pipeline on the SI for Good example, rendered through do_blocks() and go_to().
 */
class DirectoryQueryTest extends WP_UnitTestCase {

	/**
	 * Contact email used to detect leaks.
	 */
	private const EMAIL = 'secret-contact@example.org';

	/**
	 * Constraints seen by the pegwork_listing_constraints filter: [ constraints, type, context ].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private $seen = array();

	/**
	 * Registers examples/sifg and boots the listings module.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 3 ) . '/examples/sifg' ) );
		Plugin::init();
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::init();
		flush_rewrite_rules( false );
		Taxonomies::seed( pegwork_registry() );

		$module = require dirname( __DIR__, 3 ) . '/modules/listings/module.php';
		$module->boot();
		$this->seen = array();
	}

	/**
	 * Removes generated patterns and rebuilds the registry without the test package.
	 */
	public function tear_down() {
		foreach ( Patterns::KINDS as $kind ) {
			$name = Patterns::name( 'sifg_project', $kind );
			if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_pattern( $name );
			}
		}
		unset( $GLOBALS['post'], $_GET[ 'query-' . $this->query_id( 'directory' ) . '-page' ] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Creates a project.
	 *
	 * @param string $title  Title.
	 * @param array  $meta   Field values by key.
	 * @param array  $terms  Terms by taxonomy.
	 * @param array  $post   Extra post args.
	 * @return int Post ID.
	 */
	private function project( $title, $meta = array(), $terms = array(), $post = array() ) {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'sifg_project',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<p>About ' . $title . '.</p>',
				),
				$post
			)
		);
		foreach ( array_merge( array( 'contact_email' => self::EMAIL ), $meta ) as $key => $value ) {
			update_post_meta( $id, '_sifg_project_' . $key, $value );
		}
		foreach ( array_merge(
			array(
				'sifg_project_status' => 'active',
				'sifg_project_type'   => 'nonprofit',
			),
			$terms
		) as $taxonomy => $slugs ) {
			wp_set_object_terms( $id, $slugs, $taxonomy );
		}
		return $id;
	}

	/**
	 * Query ID of a generated pattern's Query Loop.
	 *
	 * @param string $kind Pattern kind.
	 */
	private function query_id( $kind ) {
		$blocks = parse_blocks( Patterns::pattern( 'sifg_project', $kind )['content'] );
		return $blocks[0]['attrs']['queryId'];
	}

	/**
	 * Generated pattern markup with attributes of its Query Loop replaced.
	 *
	 * @param string $kind  Pattern kind.
	 * @param array  $query Query attribute overrides.
	 * @param array  $attrs Block attribute overrides (null removes).
	 */
	private function markup( $kind, $query = array(), $attrs = array() ) {
		$blocks                      = parse_blocks( Patterns::pattern( 'sifg_project', $kind )['content'] );
		$blocks[0]['attrs']['query'] = array_merge( $blocks[0]['attrs']['query'], $query );
		foreach ( $attrs as $key => $value ) {
			if ( null === $value ) {
				unset( $blocks[0]['attrs'][ $key ] );
			} else {
				$blocks[0]['attrs'][ $key ] = $value;
			}
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * Titles rendered by the post-template, in order.
	 *
	 * @param string $html Rendered HTML.
	 * @return string[]
	 */
	private static function titles( $html ) {
		preg_match_all( '#<h3[^>]*><a [^>]*>([^<]+)</a></h3>#', $html, $matches );
		return $matches[1];
	}

	/**
	 * Records the constraint filter calls.
	 */
	private function watch_constraints() {
		add_filter(
			Constraints::FILTER,
			function ( $constraints, $type, $context ) {
				$this->seen[] = array( $constraints, $type, $context );
				return $constraints;
			},
			10,
			3
		);
	}

	/**
	 * The Query Loop gains the attribute; blocks using `query` context also get `pegwork/listing`.
	 */
	public function test_block_types_patched() {
		$registry = WP_Block_Type_Registry::get_instance();
		$query    = $registry->get_registered( 'core/query' );
		$this->assertSame( array( 'type' => 'object' ), $query->attributes['pegwork'] );
		$this->assertSame( 'pegwork', $query->provides_context['pegwork/listing'] );

		foreach ( array( 'core/post-template', 'core/query-pagination-next', 'core/query-pagination-numbers', 'core/query-no-results' ) as $name ) {
			$this->assertContains( 'pegwork/listing', $registry->get_registered( $name )->uses_context, $name );
		}

		$this->assertSame( array( 'query', 'pegwork/listing', 'postId' ), QueryHooks::uses_context( array( 'query' ) ) );
		$this->assertSame( array( 'query', 'postId', 'pegwork/listing' ), QueryHooks::uses_context( array( 'query', 'postId' ) ) );
		$this->assertSame( array( 'postId' ), QueryHooks::uses_context( array( 'postId' ) ) );
		$args = QueryHooks::block_type_args( array( 'attributes' => array() ), 'core/query' );
		$this->assertSame( array( 'type' => 'object' ), $args['attributes']['pegwork'] );
		$this->assertSame( array( 'title' => 'x' ), QueryHooks::block_type_args( array( 'title' => 'x' ), 'core/group' ) );
	}

	/**
	 * The directory pattern renders a grid of published records with pagination, marked as a directory,
	 * and never lists drafts, pending, private, future or trashed records, even for an administrator.
	 */
	public function test_directory_lists_published_only_and_paginates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		for ( $i = 1; $i <= 13; $i++ ) {
			$this->project( sprintf( 'Published %02d', $i ), array(), array(), array( 'post_date' => sprintf( '2026-01-%02d 10:00:00', $i ) ) );
		}
		foreach ( array( 'draft', 'pending', 'private', 'future', 'trash' ) as $status ) {
			$this->project(
				'Hidden ' . $status,
				array(),
				array(),
				array(
					'post_status' => $status,
					'post_date'   => 'future' === $status ? '2030-01-01 00:00:00' : '2026-02-01 00:00:00',
				)
			);
		}

		$this->watch_constraints();
		$html = do_blocks( Patterns::directory( 'sifg_project' ) );

		$this->assertStringContainsString( 'data-pegwork-directory="sifg_project"', $html );
		$this->assertCount( 12, self::titles( $html ) );
		$this->assertSame( 'Published 13', self::titles( $html )[0] );
		$this->assertStringNotContainsString( 'Hidden', $html );
		$this->assertStringNotContainsString( self::EMAIL, $html );
		$this->assertStringContainsString( 'query-' . $this->query_id( 'directory' ) . '-page=2', $html );

		// Every query block of the loop ran the pipeline with the same context.
		$this->assertNotEmpty( $this->seen );
		foreach ( $this->seen as $call ) {
			$this->assertSame( 'sifg_project', $call[1] );
			$this->assertSame(
				array(
					'source'  => 'block',
					'filters' => true,
				),
				$call[2]
			);
		}

		$_GET[ 'query-' . $this->query_id( 'directory' ) . '-page' ] = '2';
		$page2 = do_blocks( Patterns::directory( 'sifg_project' ) );
		$this->assertSame( array( 'Published 01' ), self::titles( $page2 ) );
		$this->assertStringNotContainsString( 'Hidden', $page2 );
	}

	/**
	 * With nothing to list the no-results message shows.
	 */
	public function test_directory_no_results() {
		$html = do_blocks( Patterns::directory( 'sifg_project' ) );
		$this->assertStringContainsString( 'No projects match your search or filters.', $html );
		$this->assertSame( array(), self::titles( $html ) );
	}

	/**
	 * Constraints from the filter narrow a Pegwork Query Loop; a plain Query Loop is untouched.
	 */
	public function test_block_constraints_and_plain_loop() {
		$this->project( 'Match', array( 'needs_volunteers' => '1' ), array( 'sifg_impact' => array( 'sdg-04' ) ) );
		$this->project( 'Wrong impact', array( 'needs_volunteers' => '1' ), array( 'sifg_impact' => array( 'sdg-01' ) ) );
		$this->project( 'No volunteers', array(), array( 'sifg_impact' => array( 'sdg-04' ) ) );

		add_filter(
			Constraints::FILTER,
			fn( $c ) => array_merge(
				$c,
				array(
					'taxonomies' => array( 'sifg_impact' => array( 'sdg-04', 'sdg-13' ) ),
					'fields'     => array(
						'needs_volunteers' => true,
						'contact_email'    => self::EMAIL,
					),
				)
			)
		);

		$this->assertSame( array( 'Match' ), self::titles( do_blocks( Patterns::directory( 'sifg_project' ) ) ) );

		// The latest pattern is a plain Query Loop: no pipeline, no directory marker.
		$latest = do_blocks( Patterns::latest( 'sifg_project' ) );
		$this->assertCount( 3, self::titles( $latest ) );
		$this->assertStringNotContainsString( 'data-pegwork-directory', $latest );
	}

	/**
	 * Security review L-06: a password-protected record never appears in a listing filtered on field or
	 * taxonomy values (either branch would reveal its hidden value); an unfiltered listing still lists it.
	 */
	public function test_filtered_listings_leave_out_password_protected_records() {
		$this->project( 'Open', array( 'needs_volunteers' => '1' ) );
		$this->project( 'Protected', array( 'needs_volunteers' => '1' ), array(), array( 'post_password' => 'secret' ) );

		foreach ( array( true, false ) as $value ) {
			$markup = $this->markup( 'directory', array(), array( 'pegwork' => array( 'where' => array( 'needs_volunteers' => $value ) ) ) );
			$this->assertNotContains( 'Protected', self::titles( do_blocks( $markup ) ), $value ? 'yes branch' : 'no branch' );
		}
		$args = Constraints::to_query_args( 'sifg_project', array( 'taxonomies' => array( 'sifg_project_type' => array( 'nonprofit' ) ) ) );
		$this->assertFalse( $args['has_password'] );
		$this->assertCount( 2, self::titles( do_blocks( Patterns::directory( 'sifg_project' ) ) ), 'Unfiltered, both are listed.' );
	}

	/**
	 * `where` presets: public boolean fields constrain; private and unknown keys are ignored.
	 */
	public function test_where_presets() {
		$this->project( 'Needs help', array( 'needs_volunteers' => '1' ) );
		$this->project( 'All set' );

		$markup = $this->markup( 'directory', array(), array( 'pegwork' => array( 'where' => array( 'needs_volunteers' => true ) ) ) );
		$this->assertSame( array( 'Needs help' ), self::titles( do_blocks( $markup ) ) );

		$markup = $this->markup( 'directory', array(), array( 'pegwork' => array( 'where' => array( 'needs_volunteers' => false ) ) ) );
		$this->assertSame( array( 'All set' ), self::titles( do_blocks( $markup ) ) );

		$markup = $this->markup(
			'directory',
			array(),
			array(
				'pegwork' => array(
					'where' => array(
						'contact_email' => 'nobody@example.org',
						'nope'          => 'x',
					),
				),
			)
		);
		$this->assertCount( 2, self::titles( do_blocks( $markup ) ) );
	}

	/**
	 * Featured preset (real pegwork_get_option()): only the record chosen on the options page; empty,
	 * unpublished or foreign choices fall back to the newest published record.
	 */
	public function test_featured_uses_option_and_falls_back() {
		$chosen = $this->project( 'Chosen', array(), array(), array( 'post_date' => '2026-01-01 00:00:00' ) );
		$this->project( 'Newest', array(), array(), array( 'post_date' => '2026-03-01 00:00:00' ) );
		$draft = $this->project( 'Draft pick', array(), array(), array( 'post_status' => 'draft' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertSame( 'sifg_settings.featured_project', Patterns::option_field( 'sifg_project' ) );
		$featured = Patterns::featured( 'sifg_project' );
		$this->assertStringContainsString( '"pegwork":{"where":{"post_in_option":"sifg_settings.featured_project"}}', $featured );
		$this->assertStringNotContainsString( 'wp:query-pagination', $featured );

		update_option( 'sifg_settings', array( 'featured_project' => $chosen ) );
		$this->assertSame( array( 'Chosen' ), self::titles( do_blocks( $featured ) ) );

		foreach ( array( '', 0, $draft, $page, 999999 ) as $value ) {
			update_option( 'sifg_settings', array( 'featured_project' => $value ) );
			$this->assertSame( array( 'Newest' ), self::titles( do_blocks( $featured ) ), var_export( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Assertion message.
		}

		delete_option( 'sifg_settings' );
		$this->assertSame( array( 'Newest' ), self::titles( do_blocks( $featured ) ) );

		// A reference to a private or non-post option field is ignored the same way.
		update_option(
			'sifg_settings',
			array(
				'featured_project' => $chosen,
				'moderator_email'  => $chosen,
			)
		);
		$this->assertSame( array( $chosen ), Constraints::option_post_ids( 'sifg_project', 'sifg_settings.featured_project' ) );
		$this->assertNotSame( array( $chosen ), Constraints::option_post_ids( 'sifg_project', 'sifg_settings.moderator_email' ) );
		$this->assertNotSame( array( $chosen ), Constraints::option_post_ids( 'sifg_project', 'nope.featured_project' ) );
	}

	/**
	 * The archive main query runs the pipeline (source main), keeps the archive template flags, and lists
	 * published records only.
	 */
	public function test_archive_main_query() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->project( 'Open data map', array( 'needs_volunteers' => '1' ), array( 'sifg_impact' => array( 'sdg-13' ) ) );
		$this->project( 'Garden club', array( 'needs_volunteers' => '1' ), array( 'sifg_impact' => array( 'sdg-13' ) ) );
		$this->project( 'Private data map', array( 'needs_volunteers' => '1' ), array( 'sifg_impact' => array( 'sdg-13' ) ), array( 'post_status' => 'private' ) );

		$this->watch_constraints();
		add_filter(
			Constraints::FILTER,
			fn( $c, $type, $context ) => 'main' === $context['source']
				? array(
					'taxonomies' => array( 'sifg_impact' => array( 'sdg-13' ) ),
					'fields'     => array( 'needs_volunteers' => true ),
					'search'     => 'data',
				)
				: $c,
			20,
			3
		);

		$this->go_to( get_post_type_archive_link( 'sifg_project' ) );

		$this->assertTrue( is_post_type_archive( 'sifg_project' ) );
		$this->assertFalse( is_search() );
		$this->assertSame( array( 'Open data map' ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'post_title' ) );
		$this->assertSame(
			array(
				array(),
				'sifg_project',
				array(
					'source'  => 'main',
					'filters' => true,
				),
			),
			$this->seen[0]
		);

		// An inherit Query Loop on the archive is marked as the type's directory.
		$html = do_blocks( '<!-- wp:query {"queryId":1,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->' );
		$this->assertStringContainsString( 'data-pegwork-directory="sifg_project"', $html );
		$this->assertStringContainsString( 'Open data map', $html );
	}

	/**
	 * Archive URL params outside the pipeline (private fields, meta keys) change nothing.
	 */
	public function test_archive_ignores_private_params() {
		$this->project( 'One' );
		$this->project( 'Two', array( 'contact_email' => 'other@example.org' ) );

		$this->go_to( add_query_arg( array( 'contact_email' => self::EMAIL, '_sifg_project_contact_email' => self::EMAIL, 'meta_key' => '_sifg_project_contact_email', 'meta_value' => self::EMAIL ), get_post_type_archive_link( 'sifg_project' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing, WordPress.DB.SlowDBQuery -- Hostile URL under test.
		$this->assertCount( 2, $GLOBALS['wp_query']->posts );
	}

	/**
	 * Other main queries and admin queries are untouched.
	 */
	public function test_other_queries_untouched() {
		$this->watch_constraints();
		$this->go_to( home_url( '/' ) );
		$this->assertSame( array(), $this->seen );

		$query = new \WP_Query( array( 'post_type' => 'sifg_project' ) );
		$this->assertSame( array(), $this->seen );
		$this->assertNotSame( 'publish', $query->get( 'post_status' ) );
	}

	/**
	 * Five patterns per listing type in category Pegwork; the directory is the Pegwork Query Loop.
	 */
	public function test_patterns_registered() {
		Patterns::register_patterns();
		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( Patterns::KINDS as $kind ) {
			$pattern = $registry->get_registered( 'pegwork/sifg_project-' . $kind );
			$this->assertNotNull( $pattern, $kind );
			$this->assertSame( array( 'pegwork' ), $pattern['categories'] );
			$this->assertSame( $pattern['content'], serialize_blocks( parse_blocks( $pattern['content'] ) ), $kind . ' round-trips' );
		}
		$this->assertSame( 'Projects directory', $registry->get_registered( 'pegwork/sifg_project-directory' )['title'] );
		$this->assertTrue( \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'pegwork' ) );
		$this->assertNull( $registry->get_registered( 'pegwork/sifg_story-card' ), 'sifg_story has no listing' );

		$directory = parse_blocks( $registry->get_registered( 'pegwork/sifg_project-directory' )['content'] )[0];
		$this->assertSame( 'core/query', $directory['blockName'] );
		$this->assertSame( array( 'filters' => true ), $directory['attrs']['pegwork'] );
		$this->assertTrue( $directory['attrs']['enhancedPagination'] );
		$this->assertSame( 'sifg_project', $directory['attrs']['query']['postType'] );
		$this->assertSame( Patterns::PER_PAGE, $directory['attrs']['query']['perPage'] );

		$latest = parse_blocks( $registry->get_registered( 'pegwork/sifg_project-latest' )['content'] )[0];
		$this->assertArrayNotHasKey( 'pegwork', $latest['attrs'] );
		$this->assertSame( 3, $latest['attrs']['query']['perPage'] );

		$card = $registry->get_registered( 'pegwork/sifg_project-card' )['content'];
		$this->assertStringContainsString( '"key":"needs_volunteers"', $card );
		$this->assertStringContainsString( '"term":"sifg_impact"', $card );
		$this->assertStringNotContainsString( 'contact_email', $card );
		$this->assertStringNotContainsString( 'contact_email', $registry->get_registered( 'pegwork/sifg_project-details' )['content'] );
	}

	/**
	 * A card registered first (by a theme) wins and flows into the directory, featured and latest patterns.
	 */
	public function test_theme_card_overrides() {
		foreach ( Patterns::KINDS as $kind ) {
			unregister_block_pattern( Patterns::name( 'sifg_project', $kind ) );
		}
		register_block_pattern(
			'pegwork/sifg_project-card',
			array(
				'title'   => 'Theme card',
				'content' => '<!-- wp:post-title {"level":3,"isLink":true,"className":"theme-card"} /-->',
			)
		);
		Patterns::register_patterns();

		$registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertSame( 'Theme card', $registry->get_registered( 'pegwork/sifg_project-card' )['title'] );
		foreach ( array( 'directory', 'featured', 'latest' ) as $kind ) {
			$this->assertStringContainsString( 'theme-card', $registry->get_registered( 'pegwork/sifg_project-' . $kind )['content'], $kind );
		}

		add_filter( 'pegwork_listing_pattern', fn( $pattern, $type, $kind ) => 'details' === $kind ? null : $pattern, 10, 3 );
		unregister_block_pattern( 'pegwork/sifg_project-details' );
		Patterns::register_patterns();
		$this->assertFalse( $registry->is_registered( 'pegwork/sifg_project-details' ) );
	}

	/**
	 * The details pattern shows public fields on a single record, the volunteer button only when shown,
	 * and never the contact email.
	 */
	public function test_details_pattern() {
		$id = $this->project(
			'River Watch',
			array(
				'who_for'          => 'Neighborhood groups',
				'needs_volunteers' => '1',
				'volunteer_link'   => 'https://river.example.org/volunteer',
				'website'          => 'https://river.example.org',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Single template context.

		$html = do_blocks( Patterns::details( 'sifg_project' ) );
		$this->assertStringContainsString( "Who it's for: Neighborhood groups", html_entity_decode( $html, ENT_QUOTES ) );
		$this->assertStringContainsString( 'href="https://river.example.org/volunteer"', $html );
		$this->assertStringContainsString( 'href="https://river.example.org"', $html );
		$this->assertStringNotContainsString( self::EMAIL, $html );
		$this->assertStringNotContainsString( 'GitHub link', $html, 'empty url fields render nothing' );
		$this->assertDoesNotMatchRegularExpression( '#<div class="wp-block-buttons[^"]*">\s*</div>#', $html, 'no empty Buttons wrapper for an empty url field' );
		$this->assertSame( 2, preg_match_all( '#class="wp-block-buttons[ "]#', $html ), 'one wrapper per url field with a value' );

		update_post_meta( $id, '_sifg_project_needs_volunteers', '' );
		$this->assertStringNotContainsString( 'volunteer', do_blocks( Patterns::details( 'sifg_project' ) ) );
	}
}
