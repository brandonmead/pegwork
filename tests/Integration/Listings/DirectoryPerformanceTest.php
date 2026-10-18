<?php
/**
 * Integration tests: the directory query budget on 500 records (docs/phase-2-design.md section 10).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Listings;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Registrar;
use Pegwork\Plugin;
use PegworkListings\Constraints;
use PegworkListings\Patterns;
use WP_UnitTestCase;

/**
 * At most BUDGET database queries per directory render, and the same count at 12 and 48 per page (no N+1).
 * Caches are flushed before each measurement, as in a request without a persistent object cache.
 */
class DirectoryPerformanceTest extends WP_UnitTestCase {

	/**
	 * Most queries per directory render.
	 */
	private const BUDGET = 35;

	/**
	 * Records seeded.
	 */
	private const RECORDS = 500;

	/**
	 * Package registration callback, kept to remove it after the class.
	 *
	 * @var callable|null
	 */
	private static $register = null;

	/**
	 * Measured counts, printed after the class: label => queries.
	 *
	 * @var array<string, int>
	 */
	private static $counts = array();

	/**
	 * Seeds 500 published Projects once for the class.
	 */
	public static function wpSetUpBeforeClass() {
		self::$register = fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 3 ) . '/examples/sifg' );
		add_action( 'pegwork_register', self::$register );
		Plugin::init();
		require_once dirname( __DIR__, 2 ) . '/perf/seed.php';
		pegwork_perf_seed( self::RECORDS );
	}

	/**
	 * Removes the registration hook added before the class.
	 */
	public static function wpTearDownAfterClass() {
		remove_action( 'pegwork_register', self::$register );
		// The seeded terms are deleted with the class data; a stale hash would stop later classes re-seeding them.
		delete_option( Registrar::HASH_OPTION );
		if ( getenv( 'PEGWORK_PERF_REPORT' ) ) {
			fwrite( STDERR, "\nDirectory queries (" . self::RECORDS . ' records): ' . wp_json_encode( self::$counts ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Opt-in report for architecture.md.
		}
	}

	/**
	 * Boots the listings module with pretty permalinks.
	 */
	public function set_up() {
		parent::set_up();
		// The suite restores hooks after each test, which can drop the one added before the class.
		if ( ! has_action( 'pegwork_register', self::$register ) ) {
			add_action( 'pegwork_register', self::$register );
		}
		Plugin::init();
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::init();
		flush_rewrite_rules( false );
		$module = require dirname( __DIR__, 3 ) . '/modules/listings/module.php';
		$module->boot();

		// Once-per-process work (salts, theme roots, global styles lookup) is not part of a directory render.
		do_blocks( $this->directory( 1 ) );
	}

	/**
	 * Rebuilds the registry.
	 */
	public function tear_down() {
		unset( $_GET[ 'query-' . $this->query_id() . '-page' ] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Query ID of the directory pattern.
	 */
	private function query_id() {
		return parse_blocks( Patterns::directory( 'sifg_project' ) )[0]['attrs']['queryId'];
	}

	/**
	 * Directory markup at a page size.
	 *
	 * @param int $per_page Records per page.
	 */
	private function directory( $per_page ) {
		$blocks                                 = parse_blocks( Patterns::directory( 'sifg_project' ) );
		$blocks[0]['attrs']['query']['perPage'] = $per_page;
		return serialize_blocks( $blocks );
	}

	/**
	 * The filtered scenario: Type nonprofit, impact sdg-04 or sdg-13, Needs volunteers, search "data".
	 */
	private function filter_scenario() {
		add_filter(
			Constraints::FILTER,
			fn() => array(
				'taxonomies' => array(
					'sifg_project_type' => array( 'nonprofit' ),
					'sifg_impact'       => array( 'sdg-04', 'sdg-13' ),
				),
				'fields'     => array( 'needs_volunteers' => true ),
				'search'     => 'data',
			)
		);
	}

	/**
	 * Queries and HTML of one render with a cold object cache. The same render runs once first, so
	 * once-per-process work (WP 6.7 looks up theme roots on the first render after a flush) is never
	 * counted against one page size only.
	 *
	 * @param callable $render Renders and returns HTML.
	 * @return array{0: int, 1: string}
	 */
	private function measure( callable $render ) {
		global $wpdb;
		$render();
		wp_cache_flush();
		$sql     = array();
		$collect = function ( $query ) use ( &$sql ) {
			$sql[] = preg_replace( '/\s+/', ' ', $query );
			return $query;
		};
		add_filter( 'query', $collect );
		$before = $wpdb->num_queries;
		$html   = $render();
		$count  = $wpdb->num_queries - $before;
		remove_filter( 'query', $collect );
		$this->last_sql = $sql;
		return array( $count, $html );
	}

	/**
	 * SQL of the last measurement, for failure messages.
	 *
	 * @var string[]
	 */
	private $last_sql = array();

	/**
	 * The last measurement's SQL as a message.
	 */
	private function sql() {
		return implode( "\n", $this->last_sql );
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
	 * Unfiltered page 1 and page 3 of the directory Query Loop: within budget, same count at 12 and 48.
	 */
	public function test_directory_block_budget() {
		list( $q12, $html12 ) = $this->measure( fn() => do_blocks( $this->directory( 12 ) ) );
		$sql12                = $this->sql();
		list( $q48, $html48 ) = $this->measure( fn() => do_blocks( $this->directory( 48 ) ) );

		$this->assertSame( 12, self::cards( $html12 ) );
		$this->assertSame( 48, self::cards( $html48 ) );
		$this->assertLessThanOrEqual( self::BUDGET, $q12, $sql12 );
		$this->assertSame( $q12, $q48, "N+1: query count grows with page size\n12:\n$sql12\n48:\n" . $this->sql() );

		$_GET[ 'query-' . $this->query_id() . '-page' ] = '3';

		list( $q3, $html3 ) = $this->measure( fn() => do_blocks( $this->directory( 12 ) ) );
		$this->assertSame( 12, self::cards( $html3 ) );
		$this->assertLessThanOrEqual( self::BUDGET, $q3 );

		self::$counts['block page 1 (12)'] = $q12;
		self::$counts['block page 1 (48)'] = $q48;
		self::$counts['block page 3 (12)'] = $q3;
	}

	/**
	 * The filtered scenario on the directory Query Loop.
	 */
	public function test_directory_block_filtered_budget() {
		$this->filter_scenario();
		list( $q12, $html12 ) = $this->measure( fn() => do_blocks( $this->directory( 12 ) ) );
		list( $q48 )          = $this->measure( fn() => do_blocks( $this->directory( 48 ) ) );

		$this->assertGreaterThan( 0, self::cards( $html12 ) );
		$this->assertLessThanOrEqual( self::BUDGET, $q12 );
		$this->assertSame( $q12, $q48 );
		self::$counts['block filtered (12)'] = $q12;
		self::$counts['block filtered (48)'] = $q48;
	}

	/**
	 * The archive: main query through pre_get_posts plus an inherit Query Loop with the card, unfiltered and
	 * filtered, within budget and the same at 12 and 48 per page.
	 */
	public function test_archive_budget() {
		$loop = '<!-- wp:query {"queryId":1,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template -->'
			. Patterns::card( 'sifg_project' )
			. '<!-- /wp:post-template --><!-- wp:query-pagination --><!-- wp:query-pagination-numbers /--><!-- /wp:query-pagination --></div><!-- /wp:query -->';
		$url  = get_post_type_archive_link( 'sifg_project' );

		$counts = array();
		foreach ( array( 'unfiltered', 'filtered' ) as $scenario ) {
			if ( 'filtered' === $scenario ) {
				$this->filter_scenario();
			}
			foreach ( array( 12, 48 ) as $per_page ) {
				update_option( 'posts_per_page', $per_page );
				list( $queries, $html ) = $this->measure(
					function () use ( $url, $loop ) {
						$this->go_to( $url );
						return do_blocks( $loop );
					}
				);
				$this->assertTrue( is_post_type_archive( 'sifg_project' ) );
				$this->assertLessThanOrEqual( self::BUDGET, $queries, "$scenario $per_page" );
				if ( 'unfiltered' === $scenario ) {
					$this->assertSame( $per_page, self::cards( $html ) );
				}
				$counts[ $scenario ][ $per_page ]                = $queries;
				self::$counts[ "archive $scenario ($per_page)" ] = $queries;
			}
			$this->assertSame( $counts[ $scenario ][12], $counts[ $scenario ][48], "N+1 on the $scenario archive" );
		}
	}
}
