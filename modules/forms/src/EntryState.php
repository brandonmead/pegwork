<?php
/**
 * Entry status transitions and confirmation bookkeeping.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Writes the lifecycle columns of `{prefix}pegwork_entries` (`status`, `post_id`, `token_hash`,
 * `confirmed_at`) and claims entries atomically. Every query is prepared. Reads stay in Entries.
 */
final class EntryState {

	/**
	 * Columns set() may write, with their formats.
	 */
	private const COLUMNS = array(
		'status'       => '%s',
		'post_id'      => '%d',
		'token_hash'   => '%s',
		'confirmed_at' => '%s',
	);

	/**
	 * Moves an entry from one status to another in one statement, so two requests cannot both win.
	 *
	 * @param int    $id   Entry ID.
	 * @param string $from Expected current status.
	 * @param string $to   New status.
	 * @return bool Whether this call changed the row (false: another request claimed it, or it was not in `$from`).
	 */
	public static function claim( int $id, string $from, string $to ): bool {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from $wpdb->prefix.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d AND status = %s", $to, $id, $from ) );

		return 1 === $changed;
	}

	/**
	 * Sets the status of entries whose status is one of `$from` (the entries admin's bulk spam and not
	 * spam). Conditional like claim(), so an entry a confirmation holds (`processing`) is left alone.
	 *
	 * @param int[]    $ids  Entry IDs.
	 * @param string[] $from Statuses the entries must have now.
	 * @param string   $to   New status.
	 * @return int Rows changed.
	 */
	public static function transition( array $ids, array $from, string $to ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids || array() === $from ) {
			return 0;
		}
		$table = Schema::table();
		$sql   = 'id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND status IN (' . implode( ', ', array_fill( 0, count( $from ), '%s' ) ) . ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; $sql holds only placeholders.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE {$sql}", ...array_merge( array( $to ), $ids, array_values( $from ) ) ) );

		return (int) $changed;
	}

	/**
	 * Sets lifecycle columns of an entry. Unknown columns are ignored.
	 *
	 * @param int                  $id      Entry ID.
	 * @param array<string, mixed> $columns `status`, `post_id`, `token_hash`, `confirmed_at` (UTC `Y-m-d H:i:s`).
	 */
	public static function set( int $id, array $columns ): void {
		global $wpdb;

		$columns = array_intersect_key( $columns, self::COLUMNS );
		if ( array() === $columns ) {
			return;
		}
		$formats = array();
		foreach ( array_keys( $columns ) as $column ) {
			$formats[] = self::COLUMNS[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; update() prepares.
		$wpdb->update( Schema::table(), $columns, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * How many confirmation emails went to an address (any form) since a time: entries with a token.
	 *
	 * @param string $email Lowercased address.
	 * @param int    $since Unix time.
	 */
	public static function confirm_mails( string $email, int $since ): int {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from $wpdb->prefix.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email = %s AND token_hash IS NOT NULL AND created_at >= %s", $email, gmdate( 'Y-m-d H:i:s', $since ) ) );

		return (int) $count;
	}
}
