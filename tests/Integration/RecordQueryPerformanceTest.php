<?php
/**
 * Integration tests: Records::find() timing on 10,000 gifts across 3,000 contacts.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Records;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Registrar;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * Design budgets (docs/automation-v3-design.md section 8): a related find with count and sum takes under 200 ms, and the
 * reads of a 50-item digest wake-up stay far under 5 s. Rows are bulk-inserted with SQL (the shape the
 * Records API writes: meta per field, one `_rel_gift_donor` row per link) so the class seeds in seconds.
 * The measured numbers are printed to STDERR for the PR and architecture.md.
 */
class RecordQueryPerformanceTest extends WP_UnitTestCase {

	/**
	 * Gifts seeded.
	 */
	private const GIFTS = 10000;

	/**
	 * Contacts seeded.
	 */
	private const CONTACTS = 3000;

	/**
	 * Design budget for one related find with count and sum, in milliseconds (median).
	 */
	private const FIND_BUDGET_MS = 200;

	/**
	 * Design budget for a 50-item digest wake-up, in milliseconds.
	 */
	private const DIGEST_BUDGET_MS = 5000;

	/**
	 * Package registration callback, kept to remove it after the class.
	 *
	 * @var callable|null
	 */
	private static $register = null;

	/**
	 * Contact IDs.
	 *
	 * @var int[]
	 */
	private static array $contacts = array();

	/**
	 * Measurements, printed after the class.
	 *
	 * @var array<string, string>
	 */
	private static array $report = array();

	/**
	 * Registers tests/fixtures/records and bulk-inserts the contacts and gifts.
	 */
	public static function wpSetUpBeforeClass() {
		self::$register = fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__ ) . '/fixtures/records' );
		add_action( 'pegwork_register', self::$register );
		Plugin::init();

		self::$contacts = self::insert_posts( 'rq_contact', self::CONTACTS );
		$gifts          = self::insert_posts( 'rq_gift', self::GIFTS );

		$registry = Plugin::registry();
		$amount   = (string) $registry->meta_key( 'rq_gift', 'amount' );
		$received = (string) $registry->meta_key( 'rq_gift', 'received_on' );

		mt_srand( 42 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Deterministic fixture.
		$rows = array();
		foreach ( $gifts as $i => $id ) {
			$contact = self::$contacts[ mt_rand( 0, self::CONTACTS - 1 ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			$rows[]  = array( $id, $amount, (string) ( mt_rand( 500, 100000 ) / 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			$rows[]  = array( $id, $received, gmdate( 'Y-m-d', strtotime( '2025-01-01' ) + ( $i % 730 ) * DAY_IN_SECONDS ) );
			$rows[]  = array( $id, '_rel_gift_donor', (string) $contact );
		}
		self::insert_meta( $rows );

		// A live site has index statistics; right after a bulk insert InnoDB may not have recalculated them yet,
		// and with stale statistics MySQL can pick the meta_key index for per-record lookups (seconds, not ms).
		global $wpdb;
		$wpdb->query( "ANALYZE TABLE {$wpdb->posts}, {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Test seeding: fresh index statistics.
	}

	/**
	 * Removes the registration hook and prints the numbers.
	 */
	public static function wpTearDownAfterClass() {
		remove_action( 'pegwork_register', self::$register );
		delete_option( Registrar::HASH_OPTION );
		Plugin::init();
		if ( array() !== self::$report ) {
			fwrite( STDERR, "\nRecords::find() on " . self::GIFTS . ' gifts, ' . self::CONTACTS . ' contacts: ' . implode( '; ', self::$report ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Perf numbers for the PR.
		}
	}

	/**
	 * Inserts private posts of a type in batches.
	 *
	 * @param string $type  Post type.
	 * @param int    $count How many.
	 * @return int[] IDs in insert order.
	 */
	private static function insert_posts( string $type, int $count ): array {
		global $wpdb;
		$now = '2027-01-01 00:00:00';
		for ( $start = 0; $start < $count; $start += 1000 ) {
			$values = array();
			$args   = array();
			$end    = min( $count, $start + 1000 );
			for ( $i = $start; $i < $end; $i++ ) {
				$values[] = "( 1, %s, %s, '', %s, '', 'private', 'closed', 'closed', %s, '', '', %s, %s, '', %s )";
				array_push( $args, $now, $now, "{$type} {$i}", "{$type}-{$i}", $now, $now, $type );
			}
			$sql = "INSERT INTO {$wpdb->posts} ( post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type ) VALUES " . implode( ', ', $values );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test seeding; $sql holds only placeholders.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test seeding.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", $type ) ) );
	}

	/**
	 * Inserts meta rows in batches.
	 *
	 * @param array<int, array{0: int, 1: string, 2: string}> $rows Post ID, key, value.
	 */
	private static function insert_meta( array $rows ): void {
		global $wpdb;
		foreach ( array_chunk( $rows, 2000 ) as $chunk ) {
			$sql  = "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value ) VALUES " . implode( ', ', array_fill( 0, count( $chunk ), '( %d, %s, %s )' ) );
			$args = array_merge( ...$chunk );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test seeding; $sql holds only placeholders.
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/**
	 * Milliseconds a callback takes.
	 *
	 * @param callable $callback Callback.
	 */
	private static function time( callable $callback ): float {
		$start = hrtime( true );
		$callback();

		return ( hrtime( true ) - $start ) / 1e6;
	}

	/**
	 * Median of a list.
	 *
	 * @param float[] $values Values.
	 */
	private static function median( array $values ): float {
		sort( $values );
		$n = count( $values );

		return 0 === $n % 2 ? ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2 : $values[ intdiv( $n, 2 ) ];
	}

	/**
	 * A donor's lifetime giving (related find, count and sum, limit 0) and the newest gifts (limit 10, sorted).
	 */
	public function test_related_find_with_count_and_sum() {
		$totals = array();
		$listed = array();
		$checks = 0;
		$list   = null;
		foreach ( self::$contacts as $i => $contact ) {
			if ( 0 !== $i % 150 ) {
				continue;
			}
			$query    = array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $contact,
				),
				'limit'   => 0,
				'count'   => true,
				'sum'     => array( 'amount' ),
			);
			$result   = null;
			$totals[] = self::time(
				function () use ( $query, &$result ) {
					$result = Records::find( 'rq_gift', $query );
				}
			);
			$this->assertIsArray( $result );

			$listed[] = self::time(
				function () use ( $query, &$list ) {
					$list = Records::find(
						'rq_gift',
						array(
							'sort'  => array(
								'field' => 'received_on',
								'order' => 'desc',
							),
							'limit' => 10,
						) + $query
					);
				}
			);
			$this->assertSame( min( 10, $result['count'] ), count( $list['ids'] ) );

			if ( $checks < 3 && $result['count'] > 0 ) {
				// The SQL sum equals the sum of the stored values.
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test check.
				$expected = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM( a.meta_value ) FROM {$wpdb->postmeta} r INNER JOIN {$wpdb->postmeta} a ON a.post_id = r.post_id AND a.meta_key = %s WHERE r.meta_key = '_rel_gift_donor' AND r.meta_value = %s", Plugin::registry()->meta_key( 'rq_gift', 'amount' ), (string) $contact ) );
				$this->assertEqualsWithDelta( $expected, $result['sums']['amount'], 0.001 );
				++$checks;
			}
		}

		self::$report['related find, count and sum (limit 0)'] = sprintf( 'related count+sum median %.1f ms, max %.1f ms over %d donors', self::median( $totals ), max( $totals ), count( $totals ) );
		self::$report['related find, 10 newest']               = sprintf( 'related 10 newest median %.1f ms, max %.1f ms', self::median( $listed ), max( $listed ) );
		$this->assertLessThan( self::FIND_BUDGET_MS, self::median( $totals ) );
		$this->assertLessThan( self::FIND_BUDGET_MS, self::median( $listed ) );
	}

	/**
	 * A type-wide aggregate (filter over every gift, count and sum) for reference.
	 */
	public function test_type_wide_aggregate() {
		$times = array();
		for ( $run = 0; $run < 5; $run++ ) {
			$times[] = self::time(
				function () use ( &$result ) {
					$result = Records::find(
						'rq_gift',
						array(
							'filter' => array(
								'all' => array(
									array(
										'field' => 'received_on',
										'op'    => 'gte',
										'value' => '2025-07-01',
									),
								),
							),
							'limit'  => 0,
							'count'  => true,
							'sum'    => array( 'amount' ),
						)
					);
				}
			);
		}
		$this->assertGreaterThan( 5000, $result['count'] );
		self::$report['type-wide'] = sprintf( 'type-wide filter count+sum over %d matches median %.1f ms', $result['count'], self::median( $times ) );
	}

	/**
	 * The reads of a 50-item digest wake-up: one sorted find of 50, then Records::get() of each with cold caches.
	 */
	public function test_digest_reads() {
		$ms = self::time(
			function () use ( &$count ) {
				wp_cache_flush();
				$found = Records::find(
					'rq_gift',
					array(
						'filter' => array(
							'all' => array(
								array(
									'field' => 'received_on',
									'op'    => 'lt',
									'value' => '2025-03-01',
								),
							),
						),
						'sort'   => array( 'field' => 'received_on' ),
						'limit'  => 50,
						'count'  => true,
					)
				);
				$count = 0;
				foreach ( $found['ids'] as $id ) {
					$count += null === Records::get( $id ) ? 0 : 1;
				}
			}
		);

		$this->assertSame( 50, $count );
		self::$report['digest'] = sprintf( 'digest reads (find 50 + 50 cold Records::get) %.1f ms', $ms );
		$this->assertLessThan( self::DIGEST_BUDGET_MS, $ms );
	}
}
