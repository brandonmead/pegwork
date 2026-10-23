<?php
/**
 * Atomic rate limits.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

use Pegwork\Throttle\Schema;

/**
 * Sliding-window rate limits for public endpoints, safe under parallel requests with or without a
 * persistent object cache. Part of the semver-stable public API.
 *
 * Each counted hit is one row in `{prefix}pegwork_throttle`. take() inserts its hit first and only then
 * counts the other live hits, so of any number of parallel requests at most `max` are allowed: the last
 * one allowed always sees all the others (some may be refused early under a burst, never more allowed).
 * A read-modify-write counter in a transient cannot promise that, because parallel requests read the
 * same count. Buckets are stored as an HMAC, so raw IP or email addresses are never stored; rows that
 * expired more than a day ago are deleted as new hits come in, and uninstall drops the table.
 */
final class Throttle {

	/**
	 * Expired rows deleted per take(), at most.
	 */
	private const SWEEP = 200;

	/**
	 * Counts one hit in a bucket unless it already holds `max` live hits.
	 *
	 * @param string $bucket Bucket name, such as `forms|ip|contact|203.0.113.7` (any string; it is hashed).
	 * @param int    $max    Hits allowed per window; 0 or less turns the limit off (nothing is counted).
	 * @param int    $window Window in seconds (a hit counts for this long after it is made).
	 * @param int    $now    Unix time.
	 * @return int 0 when the hit was counted (allowed), else seconds until a hit leaves the window.
	 */
	public static function take( string $bucket, int $max, int $window, int $now ): int {
		global $wpdb;
		if ( $max <= 0 ) {
			return 0;
		}
		$table = Schema::table();
		$hash  = self::hash( $bucket );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counters must bypass caches to be atomic; table name from $wpdb->prefix.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d LIMIT %d', $table, $now - DAY_IN_SECONDS, self::SWEEP ) );
		$inserted = $wpdb->insert(
			$table,
			array(
				'bucket'     => $hash,
				'expires_at' => $now + max( 1, $window ),
			),
			array( '%s', '%d' )
		);
		if ( false === $inserted ) {
			// No table (install failed): fail open rather than block every visitor.
			return 0;
		}
		$id     = (int) $wpdb->insert_id;
		$others = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT expires_at FROM %i WHERE bucket = %s AND expires_at > %d AND id <> %d ORDER BY expires_at ASC', $table, $hash, $now, $id ) ) );
		if ( count( $others ) < $max ) {
			return 0;
		}
		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return self::until( $others, $max, $now );
	}

	/**
	 * Seconds until a bucket has room for another hit (0 when it has room now), without counting one.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Hits allowed per window; 0 or less means no limit.
	 * @param int    $now    Unix time.
	 */
	public static function wait( string $bucket, int $max, int $now ): int {
		return $max <= 0 ? 0 : self::until( self::expiries( $bucket, $now ), $max, $now );
	}

	/**
	 * Unix times at which the live hits of a bucket leave their window, oldest first.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $now    Unix time.
	 * @return int[]
	 */
	public static function expiries( string $bucket, int $now ): array {
		global $wpdb;
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counters must bypass caches; table name from $wpdb->prefix.
		$times = $wpdb->get_col( $wpdb->prepare( 'SELECT expires_at FROM %i WHERE bucket = %s AND expires_at > %d ORDER BY expires_at ASC', $table, self::hash( $bucket ), $now ) );
		return array_map( 'intval', is_array( $times ) ? $times : array() );
	}

	/**
	 * Forgets every hit of a bucket.
	 *
	 * @param string $bucket Bucket name.
	 */
	public static function clear( string $bucket ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counters bypass caches.
		$wpdb->delete( Schema::table(), array( 'bucket' => self::hash( $bucket ) ), array( '%s' ) );
	}

	/**
	 * The client IP for rate limits: `REMOTE_ADDR`, filtered by `pegwork_client_ip`; '' when not a valid IP.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used by every Pegwork rate limit (forms, signed links, webhooks). Return a
		 * proxy header's value only when the origin accepts traffic from that proxy alone (docs/forms.md).
		 *
		 * @param string $ip `REMOTE_ADDR`.
		 */
		$ip = apply_filters( 'pegwork_client_ip', $remote );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Seconds until fewer than `max` of these expiry times are still ahead (0 when that is already so).
	 *
	 * @param int[] $expiries Expiry times of live hits, oldest first.
	 * @param int   $max      Hits allowed.
	 * @param int   $now      Unix time.
	 */
	private static function until( array $expiries, int $max, int $now ): int {
		$excess = count( $expiries ) - $max;
		return $excess < 0 ? 0 : max( 1, $expiries[ $excess ] - $now );
	}

	/**
	 * Stored form of a bucket name.
	 *
	 * @param string $bucket Bucket name.
	 */
	private static function hash( string $bucket ): string {
		return hash_hmac( 'sha256', $bucket, wp_salt( 'nonce' ) );
	}
}
