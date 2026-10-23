<?php
/**
 * Rate limit counter tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Throttle;
use Pegwork\Throttle\Schema;
use WP_UnitTestCase;

/**
 * Security review L-01: Pegwork\Api\Throttle counts hits in table rows (insert, then count), so its limits
 * hold under parallel requests without a persistent object cache. PHP tests run one request at a time, so
 * the interleaving test inserts the "parallel" hit directly between a request's insert and its count.
 */
class ThrottleTest extends WP_UnitTestCase {

	/**
	 * Base time.
	 */
	private const NOW = 1798000000;

	/**
	 * `max` hits per window, then the wait until the oldest leaves it; the window slides.
	 */
	public function test_take_refuses_over_the_limit_and_slides() {
		$this->assertSame( 0, Throttle::take( 'test|a', 3, 600, self::NOW ) );
		$this->assertSame( 0, Throttle::take( 'test|a', 3, 600, self::NOW + 60 ) );
		$this->assertSame( 0, Throttle::take( 'test|a', 3, 600, self::NOW + 120 ) );
		$this->assertSame( 480, Throttle::take( 'test|a', 3, 600, self::NOW + 120 ), 'The first hit leaves at NOW+600.' );
		$this->assertSame( 480, Throttle::wait( 'test|a', 3, self::NOW + 120 ) );
		$this->assertCount( 3, Throttle::expiries( 'test|a', self::NOW + 120 ), 'A refused hit is not counted.' );
		$this->assertSame( 0, Throttle::take( 'test|a', 3, 600, self::NOW + 600 ), 'Room again once the first hit expired.' );
		$this->assertSame( 0, Throttle::take( 'test|b', 3, 600, self::NOW ), 'Buckets are separate.' );

		Throttle::clear( 'test|a' );
		$this->assertSame( array(), Throttle::expiries( 'test|a', self::NOW ) );
		$this->assertSame( 0, Throttle::take( 'test|off', 0, 600, self::NOW ), 'A max of 0 turns the limit off.' );
		$this->assertSame( array(), Throttle::expiries( 'test|off', self::NOW ), 'And counts nothing.' );
	}

	/**
	 * Bucket names are stored as an HMAC (no raw IP or address); rows that expired over a day ago go.
	 */
	public function test_storage_is_hashed_and_swept() {
		global $wpdb;
		Throttle::take( 'forms|ip|contact|203.0.113.7', 5, 60, self::NOW );
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table.
		$rows = $wpdb->get_col( "SELECT bucket FROM {$table}" );
		$this->assertCount( 1, $rows );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $rows[0] );

		Throttle::take( 'test|later', 5, 60, self::NOW + DAY_IN_SECONDS + 61 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the table.
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), 'The day-old row is gone.' );
	}

	/**
	 * Two requests that both passed a read of the count cannot both be allowed at the last slot: the
	 * second insert is already in the table when the first counts, so the first is refused.
	 */
	public function test_parallel_hit_between_insert_and_count_is_seen() {
		global $wpdb;
		$this->assertSame( 0, Throttle::take( 'test|race', 2, 600, self::NOW ) );

		$fired    = false;
		$parallel = function ( $query ) use ( $wpdb, &$fired ) {
			if ( ! $fired && str_starts_with( $query, 'SELECT expires_at' ) ) {
				$fired = true;
				// The other request's hit lands after this one's insert, before its count.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulates another request.
				$wpdb->insert(
					Schema::table(),
					array(
						'bucket'     => hash_hmac( 'sha256', 'test|race', wp_salt( 'nonce' ) ),
						'expires_at' => self::NOW + 600,
					)
				);
			}
			return $query;
		};
		add_filter( 'query', $parallel );
		$this->assertGreaterThan( 0, Throttle::take( 'test|race', 2, 600, self::NOW ), 'Refused: the slot went to the parallel hit.' );
		remove_filter( 'query', $parallel );
		$this->assertTrue( $fired );
		$this->assertCount( 2, Throttle::expiries( 'test|race', self::NOW ), 'Never more than max hits.' );
	}
}
