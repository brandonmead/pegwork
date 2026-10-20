<?php
/**
 * Integration tests: the `related` preset, the relationship condition and the related patterns.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Listings;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use PegworkListings\Conditional;
use PegworkListings\Constraints;
use PegworkListings\Editor;
use PegworkListings\Patterns;
use WP_Block_Patterns_Registry;
use WP_UnitTestCase;

/**
 * Story <-> Project (`story_projects`, from sifg_story to sifg_project) on the SI for Good example.
 */
class RelatedListingTest extends WP_UnitTestCase {

	/**
	 * Query ID of the test loop.
	 */
	private const QUERY_ID = 77;

	/**
	 * Most queries per render (the directory budget, docs/display.md).
	 */
	private const BUDGET = 35;

	/**
	 * Constraints seen by the pegwork_listing_constraints filter.
	 *
	 * @var array<int, array<string, mixed>>
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
	 * Resets globals and rebuilds the registry without the test package.
	 */
	public function tear_down() {
		unset( $GLOBALS['post'], $_GET[ 'query-' . self::QUERY_ID . '-page' ] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Creates a record.
	 *
	 * @param string $type   Content type.
	 * @param string $title  Title.
	 * @param array  $post   Extra post args.
	 * @return int Post ID.
	 */
	private function record( $type, $title, $post = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $type,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => '<p>About ' . $title . '.</p>',
				),
				$post
			)
		);
	}

	/**
	 * Links a story to projects.
	 *
	 * @param int   $story    Story ID.
	 * @param int[] $projects Project IDs.
	 */
	private static function link( $story, $projects ) {
		foreach ( $projects as $project ) {
			add_post_meta( $story, '_rel_story_projects', $project );
		}
	}

	/**
	 * A Pegwork Query Loop with titles, next-page link and a no-results message.
	 *
	 * @param string $type     Listed content type.
	 * @param array  $pegwork  The `pegwork` attribute.
	 * @param int    $per_page Records per page.
	 */
	private static function loop( $type, $pegwork, $per_page = 6 ) {
		$attrs = array(
			'queryId' => self::QUERY_ID,
			'query'   => array(
				'perPage'  => $per_page,
				'pages'    => 0,
				'offset'   => 0,
				'postType' => $type,
				'order'    => 'desc',
				'orderBy'  => 'date',
				'inherit'  => false,
			),
			'pegwork' => $pegwork,
		);
		return get_comment_delimited_block_content(
			'core/query',
			$attrs,
			'<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"level":3,"isLink":true} /--><!-- /wp:post-template -->'
			. '<!-- wp:query-pagination --><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->'
			. '<!-- wp:query-no-results --><p>Nothing related.</p><!-- /wp:query-no-results --></div>'
		);
	}

	/**
	 * A loop with the related preset.
	 *
	 * @param string $type     Listed content type.
	 * @param string $key      Relationship key.
	 * @param int    $per_page Records per page.
	 */
	private static function related( $type, $key = 'story_projects', $per_page = 6 ) {
		return self::loop( $type, array( 'where' => array( Constraints::RELATED_KEY => $key ) ), $per_page );
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
	 * Views a record's single page (sets the main query and the global post, as a template render does).
	 *
	 * @param int $id Post ID.
	 */
	private function view( $id ) {
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( is_singular() );
	}

	/**
	 * From a Project: its published Stories, in the loop's own order, with its own paging; drafts, private
	 * and unlinked Stories never show, even for an administrator.
	 */
	public function test_project_lists_its_stories() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$project = $this->record( 'sifg_project', 'Literacy app' );
		$other   = $this->record( 'sifg_project', 'Other project' );
		foreach ( array( 1, 2, 3 ) as $day ) {
			self::link( $this->record( 'sifg_story', "Story {$day}", array( 'post_date' => "2026-01-0{$day} 10:00:00" ) ), array( $project ) );
		}
		self::link( $this->record( 'sifg_story', 'Draft story', array( 'post_status' => 'draft' ) ), array( $project ) );
		self::link( $this->record( 'sifg_story', 'Private story', array( 'post_status' => 'private' ) ), array( $project ) );
		self::link( $this->record( 'sifg_story', 'Elsewhere' ), array( $other ) );
		$this->record( 'sifg_story', 'Unlinked' );

		$this->view( $project );
		$this->assertSame( array( 'Story 3', 'Story 2', 'Story 1' ), self::titles( do_blocks( self::related( 'sifg_story' ) ) ) );

		$page1 = do_blocks( self::related( 'sifg_story', 'story_projects', 2 ) );
		$this->assertSame( array( 'Story 3', 'Story 2' ), self::titles( $page1 ) );
		$this->assertStringContainsString( 'query-' . self::QUERY_ID . '-page=2', $page1 );

		$_GET[ 'query-' . self::QUERY_ID . '-page' ] = '2';
		$this->assertSame( array( 'Story 1' ), self::titles( do_blocks( self::related( 'sifg_story', 'story_projects', 2 ) ) ) );
	}

	/**
	 * From a Story: its published Projects (the other direction), not its unpublished ones.
	 */
	public function test_story_lists_its_projects() {
		$a     = $this->record( 'sifg_project', 'Project A', array( 'post_date' => '2026-01-01 10:00:00' ) );
		$b     = $this->record( 'sifg_project', 'Project B', array( 'post_date' => '2026-01-02 10:00:00' ) );
		$draft = $this->record( 'sifg_project', 'Draft project', array( 'post_status' => 'draft' ) );
		$this->record( 'sifg_project', 'Unlinked project' );
		$story = $this->record( 'sifg_story', 'A story' );
		self::link( $story, array( $a, $b, $draft ) );

		$this->view( $story );
		$this->assertSame( array( 'Project B', 'Project A' ), self::titles( do_blocks( self::related( 'sifg_project' ) ) ) );
	}

	/**
	 * Nothing linked, no current post, an unknown relationship, a loop of the viewed post's own type, or a
	 * viewed post visitors cannot see: the loop lists nothing (no-results), never everything.
	 */
	public function test_returns_nothing_rather_than_everything() {
		$project = $this->record( 'sifg_project', 'Linked project' );
		$lonely  = $this->record( 'sifg_project', 'Lonely project' );
		$story   = $this->record( 'sifg_story', 'Linked story' );
		self::link( $story, array( $project ) );

		$this->view( $lonely );
		$html = do_blocks( self::related( 'sifg_story' ) );
		$this->assertSame( array(), self::titles( $html ) );
		$this->assertStringContainsString( 'Nothing related.', $html );

		$this->view( $project );
		$this->assertSame( array(), self::titles( do_blocks( self::related( 'sifg_story', 'nope' ) ) ) );
		$this->assertSame( array(), self::titles( do_blocks( self::related( 'sifg_project' ) ) ) ); // Same type as the viewed post.
		$this->assertSame( array(), self::titles( do_blocks( self::loop( 'sifg_story', array( 'where' => array( 'related' => array( 'story_projects' ) ) ) ) ) ) );
		$this->assertSame( array( 'Linked story' ), self::titles( do_blocks( self::related( 'sifg_story' ) ) ) );

		$this->go_to( home_url( '/' ) );
		unset( $GLOBALS['post'] );
		$this->assertSame( array(), self::titles( do_blocks( self::related( 'sifg_story' ) ) ) );

		wp_update_post(
			array(
				'ID'            => $project,
				'post_password' => 'secret',
			)
		);
		$this->view( $project );
		$this->assertSame( array(), self::titles( do_blocks( self::related( 'sifg_story' ) ) ) );
	}

	/**
	 * Inside another Query Loop (a card on an archive page), the related loop follows the card's record.
	 */
	public function test_nested_in_a_card_follows_the_card() {
		$a = $this->record( 'sifg_project', 'Project A', array( 'post_date' => '2026-01-02 10:00:00' ) );
		$b = $this->record( 'sifg_project', 'Project B', array( 'post_date' => '2026-01-01 10:00:00' ) );
		self::link( $this->record( 'sifg_story', 'Story of A' ), array( $a ) );
		self::link( $this->record( 'sifg_story', 'Story of B' ), array( $b ) );

		$inner = str_replace( '"queryId":' . self::QUERY_ID, '"queryId":78', self::related( 'sifg_story' ) );
		$outer = get_comment_delimited_block_content(
			'core/query',
			array(
				'queryId' => 79,
				'query'   => array(
					'perPage'  => 10,
					'postType' => 'sifg_project',
					'order'    => 'desc',
					'orderBy'  => 'date',
					'inherit'  => false,
				),
				'pegwork' => array( 'filters' => false ),
			),
			'<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"level":2} /-->' . $inner . '<!-- /wp:post-template --></div>'
		);

		$this->go_to( get_post_type_archive_link( 'sifg_project' ) );
		$this->assertFalse( is_singular() );
		$html = do_blocks( $outer );
		$this->assertMatchesRegularExpression( '#Project A</h2>.*Story of A.*Project B</h2>.*Story of B#s', $html );
		$this->assertSame( array( 'Story of A', 'Story of B' ), self::titles( $html ) );
	}

	/**
	 * The related preset reaches the constraint filter as `related` with the viewed post, and AND-s with
	 * other presets and constraints.
	 */
	public function test_constraint_and_combination() {
		$a     = $this->record( 'sifg_project', 'Needs help' );
		$b     = $this->record( 'sifg_project', 'All set' );
		$story = $this->record( 'sifg_story', 'Story' );
		update_post_meta( $a, '_sifg_project_needs_volunteers', '1' );
		self::link( $story, array( $a, $b ) );
		add_filter(
			Constraints::FILTER,
			function ( $constraints ) {
				$this->seen[] = $constraints;
				return $constraints;
			}
		);

		$this->view( $story );
		$markup = self::loop(
			'sifg_project',
			array(
				'where' => array(
					'related'          => 'story_projects',
					'needs_volunteers' => true,
				),
			)
		);
		$this->assertSame( array( 'Needs help' ), self::titles( do_blocks( $markup ) ) );
		$this->assertSame(
			array(
				'key'  => 'story_projects',
				'post' => $story,
			),
			$this->seen[0]['related']
		);

		// post__in from another constraint intersects with the related IDs.
		$related = $this->seen[0]['related'];
		$args    = Constraints::to_query_args(
			'sifg_project',
			array(
				'post__in' => array( $b ),
				'related'  => $related,
			)
		);
		$this->assertSame( array( $b ), $args['post__in'] );
		$args = Constraints::to_query_args(
			'sifg_project',
			array(
				'post__in' => array( 999999 ),
				'related'  => $related,
			)
		);
		$this->assertSame( array( 0 ), $args['post__in'] );
		$this->assertSame( array( 'post__in' => array( 0 ) ), Constraints::related_args( 'sifg_project', array( 'key' => 'story_projects' ) ) );
	}

	/**
	 * Show if `{ relationship, op }`: not_empty only when a published related record exists; empty is the
	 * reverse; an unknown relationship or a type at neither end fails closed.
	 */
	public function test_relationship_condition() {
		$project = $this->record( 'sifg_project', 'Project' );
		$story   = $this->record( 'sifg_story', 'Draft story', array( 'post_status' => 'draft' ) );
		self::link( $story, array( $project ) );
		$page = $this->record( 'page', 'A page' );

		$context   = fn( $id ) => array(
			'postId'   => $id,
			'postType' => get_post_type( $id ),
		);
		$condition = fn( $op, $key = 'story_projects' ) => array(
			array(
				'relationship' => $key,
				'op'           => $op,
			),
		);

		$this->assertFalse( Conditional::passes( $condition( 'not_empty' ), $context( $project ) ) );
		$this->assertTrue( Conditional::passes( $condition( 'empty' ), $context( $project ) ) );

		wp_publish_post( $story );
		$this->assertTrue( Conditional::passes( $condition( 'not_empty' ), $context( $project ) ) );
		$this->assertFalse( Conditional::passes( $condition( 'empty' ), $context( $project ) ) );
		$this->assertTrue( Conditional::passes( $condition( 'not_empty' ), $context( $story ) ) );

		$this->assertFalse( Conditional::passes( $condition( 'equals' ), $context( $project ) ) );
		$this->assertFalse( Conditional::passes( $condition( 'not_empty', 'nope' ), $context( $project ) ) );
		$this->assertFalse( Conditional::passes( $condition( 'empty', 'nope' ), $context( $project ) ) );
		$this->assertFalse( Conditional::passes( $condition( 'empty' ), $context( $page ) ) );
	}

	/**
	 * One pattern per relationship end: a "Show if" relationship condition around a heading and the
	 * related loop. It renders the section when something is related and nothing at all otherwise.
	 */
	public function test_related_patterns() {
		Patterns::register_patterns();
		$registry   = WP_Block_Patterns_Registry::get_instance();
		$on_project = $registry->get_registered( Patterns::related_name( 'sifg_project', 'story_projects' ) );
		$on_story   = $registry->get_registered( Patterns::related_name( 'sifg_story', 'story_projects' ) );

		$this->assertSame( 'Project related Stories', $on_project['title'] );
		$this->assertSame( array( 'single-sifg_project' ), $on_project['templateTypes'] );
		$this->assertSame( 'Story related Projects', $on_story['title'] );
		$this->assertStringContainsString( '<!-- wp:pegwork/conditional {"conditions":[{"relationship":"story_projects","op":"not_empty"}]} -->', $on_project['content'] );
		$this->assertStringContainsString( '"postType":"sifg_story"', $on_project['content'] );
		$this->assertStringContainsString( '"pegwork":{"where":{"related":"story_projects"}}', $on_project['content'] );
		$this->assertStringContainsString( 'Related stories', $on_project['content'] );
		$this->assertNull( Patterns::related_pattern( 'page', 'story_projects' ) );
		$this->assertNull( Patterns::related_pattern( 'sifg_project', 'nope' ) );

		$project = $this->record( 'sifg_project', 'Project' );
		$lonely  = $this->record( 'sifg_project', 'Lonely' );
		self::link( $this->record( 'sifg_story', 'Story one' ), array( $project ) );

		$this->view( $project );
		$html = do_blocks( $on_project['content'] );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Related stories</h2>', $html );
		$this->assertSame( array( 'Story one' ), self::titles( $html ) );
		$this->assertStringContainsString( 'pegwork-card', $html );

		$this->view( $lonely );
		$this->assertSame( '', trim( do_blocks( $on_project['content'] ) ) );
	}

	/**
	 * Generated patterns never carry `"pegwork":[]`: registration re-serializes attributes, so an empty
	 * object would become a list, which the editor's object attribute drops.
	 */
	public function test_pattern_attribute_serializes_as_object() {
		$registry = WP_Block_Patterns_Registry::get_instance();

		// A featured pattern for a type with no options page post field (Stories): the newest record.
		register_block_pattern(
			'pegwork-test/featured-story',
			array(
				'title'   => 'Featured story',
				'content' => Patterns::featured( 'sifg_story' ),
			)
		);
		$content = $registry->get_registered( 'pegwork-test/featured-story' )['content'];
		unregister_block_pattern( 'pegwork-test/featured-story' );
		$this->assertStringContainsString( '"pegwork":{"filters":false}', $content );

		$story = $this->record( 'sifg_story', 'Newest story' );
		$this->record( 'sifg_story', 'Older story', array( 'post_date' => '2020-01-01 00:00:00' ) );
		$this->assertSame( array( 'Newest story' ), self::titles( do_blocks( $content ) ) );
		$this->assertStringContainsString( 'data-pegwork-directory="sifg_story"', do_blocks( $content ) );
		$this->assertGreaterThan( 0, $story );

		Patterns::register_patterns();
		foreach ( $registry->get_all_registered() as $pattern ) {
			if ( str_starts_with( $pattern['name'], 'pegwork/' ) ) {
				$this->assertStringNotContainsString( '"pegwork":[]', $pattern['content'], $pattern['name'] );
			}
		}
	}

	/**
	 * The editor config lists each type's relationships with the type at the other end.
	 */
	public function test_editor_config_relationships() {
		$types = Editor::config()['types'];
		$this->assertSame(
			array(
				array(
					'key'    => 'story_projects',
					'type'   => 'sifg_story',
					'label'  => 'Story',
					'plural' => 'Stories',
				),
			),
			$types['sifg_project']['relationships']
		);
		$this->assertSame( 'sifg_project', $types['sifg_story']['relationships'][0]['type'] );
	}

	/**
	 * Query budget: the related section (condition plus loop) renders within the directory budget, and
	 * the count does not grow with the number of related records (no N+1), in both directions.
	 */
	public function test_query_budget() {
		$project  = $this->record( 'sifg_project', 'Big project' );
		$projects = array();
		for ( $i = 1; $i <= 24; $i++ ) {
			self::link( $this->record( 'sifg_story', "Story {$i}" ), array( $project ) );
			$projects[] = $this->record( 'sifg_project', "Project {$i}" );
		}
		$story = $this->record( 'sifg_story', 'Broad story' );
		self::link( $story, $projects );

		Patterns::register_patterns();
		$registry = WP_Block_Patterns_Registry::get_instance();
		$section  = fn( $context, $per_page ) => str_replace(
			'"perPage":' . Patterns::RELATED,
			'"perPage":' . $per_page,
			$registry->get_registered( Patterns::related_name( $context, 'story_projects' ) )['content']
		);

		foreach ( array(
			array( $project, 'sifg_project' ),
			array( $story, 'sifg_story' ),
		) as list( $id, $context ) ) {
			$this->view( $id );
			list( $q6, $html6 )   = $this->measure( fn() => do_blocks( $section( $context, 6 ) ) );
			list( $q24, $html24 ) = $this->measure( fn() => do_blocks( $section( $context, 24 ) ) );

			$this->assertCount( 6, self::titles( $html6 ), $context );
			$this->assertCount( 24, self::titles( $html24 ), $context );
			$this->assertLessThanOrEqual( self::BUDGET, $q6, $context );
			$this->assertSame( $q6, $q24, "N+1 from {$context}" );
			if ( getenv( 'PEGWORK_PERF_REPORT' ) ) {
				fwrite( STDERR, "\nRelated section queries from {$context}: {$q6}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Opt-in report for architecture.md.
			}
		}
	}

	/**
	 * Queries and HTML of a render with a cold object cache (rendered once before, so per-process static
	 * caches are not charged to it).
	 *
	 * @param callable $render Renders and returns HTML.
	 * @return array{0: int, 1: string}
	 */
	private function measure( callable $render ) {
		global $wpdb;
		wp_cache_flush();
		$render();
		wp_cache_flush();
		$before = $wpdb->num_queries;
		$html   = $render();
		return array( $wpdb->num_queries - $before, $html );
	}
}
