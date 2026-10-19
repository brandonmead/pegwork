<?php
/**
 * Integration tests: the query budget with filters active, on 500 records (docs/phase-2-design.md section 10).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Filters;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Registrar;
use Pegwork\Plugin;
use PegworkFilters\Hooks;
use PegworkListings\Patterns;
use WP_UnitTestCase;

/**
 * The filters block plus its directory (or the archive), with the scenario URL
 * `?project_type=nonprofit&impact=sdg-04,sdg-13&volunteers=1&q=data`: at most BUDGET queries, and the same
 * count at 12 and 48 per page. Caches are flushed before each measurement.
 */
class FiltersPerformanceTest extends WP_UnitTestCase {

	/**
	 * Most queries per render.
	 */
	private const BUDGET = 35;

	/**
	 * Records seeded.
	 */
	private const RECORDS = 500;

	/**
	 * The design's filtered scenario.
	 */
	private const SCENARIO = array(
		'project_type' => 'nonprofit',
		'impact'       => 'sdg-04,sdg-13',
		'volunteers'   => '1',
		'q'            => 'data',
	);

	/**
	 * Package registration callback.
	 *
	 * @var callable|null
	 */
	private static $register = null;

	/**
	 * Measured counts: label => queries.
	 *
	 * @var array<string, int>
	 */
	private static $counts = array();

	/**
	 * Seeds 500 published Projects once.
	 */
	public static function wpSetUpBeforeClass() {
		self::$register = fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 3 ) . '/examples/sifg' );
		add_action( 'pegwork_register', self::$register );
		Plugin::init();
		require_once dirname( __DIR__, 2 ) . '/perf/seed.php';
		pegwork_perf_seed( self::RECORDS );
	}

	/**
	 * Removes the registration hook; prints the counts with PEGWORK_PERF_REPORT=1.
	 */
	public static function wpTearDownAfterClass() {
		remove_action( 'pegwork_register', self::$register );
		delete_option( Registrar::HASH_OPTION );
		if ( getenv( 'PEGWORK_PERF_REPORT' ) ) {
			fwrite( STDERR, "\nFiltered directory queries (" . self::RECORDS . ' records): ' . wp_json_encode( self::$counts ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Opt-in report for architecture.md.
		}
	}

	/**
	 * Boots listings and filters with pretty permalinks.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! has_action( 'pegwork_register', self::$register ) ) {
			add_action( 'pegwork_register', self::$register );
		}
		Plugin::init();
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::init();
		flush_rewrite_rules( false );
		foreach ( array( 'listings', 'filters' ) as $slug ) {
			$module = require dirname( __DIR__, 3 ) . "/modules/$slug/module.php";
			$module->boot();
		}
		$_GET = array();
		do_blocks( $this->directory( 12 ) );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down() {
		$_GET = array();
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Filters block plus directory Query Loop markup at a page size.
	 *
	 * @param int $per_page Records per page.
	 */
	private function directory( $per_page ) {
		$blocks = parse_blocks( Hooks::pattern( Patterns::pattern( 'sifg_project', 'directory' ), 'sifg_project', 'directory' )['content'] );
		foreach ( $blocks as $index => $block ) {
			if ( 'core/query' === $block['blockName'] ) {
				$blocks[ $index ]['attrs']['query']['perPage'] = $per_page;
			}
		}
		return serialize_blocks( $blocks );
	}

	/**
	 * Queries and HTML of one render with a cold object cache (rendered once before, so per-process
	 * static caches are not charged to one page size).
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

	/**
	 * Cards rendered.
	 *
	 * @param string $html HTML.
	 */
	private static function cards( $html ) {
		return substr_count( $html, 'class="wp-block-group pegwork-card' );
	}

	/**
	 * A page holding the filters block and directory Query Loop (the main query for the page included):
	 * unfiltered and filtered, within budget, no N+1. On a page the filters block finds its bound loop,
	 * so its count query is the loop's own query and the loop reuses it.
	 */
	public function test_directory_page_with_filters_budget() {
		$pages = array();
		foreach ( array( 12, 48 ) as $per_page ) {
			$pages[ $per_page ] = self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => "Directory $per_page",
					'post_content' => $this->directory( $per_page ),
				)
			);
		}

		foreach ( array(
			'unfiltered' => array(),
			'filtered'   => self::SCENARIO,
		) as $scenario => $params ) {
			$counts = array();
			foreach ( $pages as $per_page => $page ) {
				list( $queries, $html ) = $this->measure(
					function () use ( $page, $params ) {
						$this->go_to( add_query_arg( $params, get_permalink( $page ) ) );
						return do_blocks( get_post( $page )->post_content );
					}
				);
				$this->assertStringContainsString( 'class="pegwork-filters__count" role="status"', $html );
				$this->assertGreaterThan( 0, self::cards( $html ), $scenario );
				$this->assertLessThanOrEqual( self::BUDGET, $queries, "$scenario $per_page" );
				$counts[ $per_page ]                               = $queries;
				self::$counts[ "directory $scenario ($per_page)" ] = $queries;
			}
			$this->assertSame( $counts[12], $counts[48], "N+1 on the $scenario directory" );
		}
	}

	/**
	 * The filters block and directory rendered outside a page (no bound loop: count-only query).
	 */
	public function test_directory_blocks_with_filters_budget() {
		foreach ( array(
			'unfiltered' => array(),
			'filtered'   => self::SCENARIO,
		) as $scenario => $params ) {
			$_GET                 = $params;
			list( $q12, $html12 ) = $this->measure( fn() => do_blocks( $this->directory( 12 ) ) );
			list( $q48 )          = $this->measure( fn() => do_blocks( $this->directory( 48 ) ) );

			$this->assertGreaterThan( 0, self::cards( $html12 ), $scenario );
			$this->assertLessThanOrEqual( self::BUDGET, $q12, $scenario );
			$this->assertSame( $q12, $q48, "N+1 on the $scenario blocks" );
			self::$counts[ "blocks $scenario (12)" ] = $q12;
			self::$counts[ "blocks $scenario (48)" ] = $q48;
		}
	}

	/**
	 * The archive (main query) with the filters block above an inherit loop.
	 */
	public function test_archive_with_filters_budget() {
		$markup = '<!-- wp:pegwork/filters /--><!-- wp:query {"queryId":1,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template -->'
			. Patterns::card( 'sifg_project' )
			. '<!-- /wp:post-template --><!-- wp:query-pagination --><!-- wp:query-pagination-numbers /--><!-- /wp:query-pagination --></div><!-- /wp:query -->';
		$url    = get_post_type_archive_link( 'sifg_project' );

		foreach ( array(
			'unfiltered' => array(),
			'filtered'   => self::SCENARIO,
		) as $scenario => $params ) {
			$counts = array();
			foreach ( array( 12, 48 ) as $per_page ) {
				update_option( 'posts_per_page', $per_page );
				list( $queries, $html ) = $this->measure(
					function () use ( $url, $params, $markup ) {
						$this->go_to( add_query_arg( $params, $url ) );
						return do_blocks( $markup );
					}
				);
				$this->assertStringContainsString( 'class="pegwork-filters__count" role="status"', $html );
				$this->assertLessThanOrEqual( self::BUDGET, $queries, "$scenario $per_page" );
				$counts[ $per_page ]                             = $queries;
				self::$counts[ "archive $scenario ($per_page)" ] = $queries;
			}
			$this->assertSame( $counts[12], $counts[48], "N+1 on the $scenario archive" );
		}
	}
}
