<?php
/**
 * Entries storage.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Reads and writes rows of `{prefix}pegwork_entries`. Every query is prepared.
 */
final class Entries {

	/**
	 * Inserts an entry.
	 *
	 * @param array<string, mixed> $entry `form_key`, `status`, `email`, `user_id` (int|null), `values` (array),
	 *                                    `meta` (array), `fingerprint`; `created_at` (UTC `Y-m-d H:i:s`) defaults to now.
	 * @return int The new entry ID, 0 on a database error.
	 */
	public static function insert( array $entry ): int {
		global $wpdb;

		$row     = array(
			'form_key'     => (string) $entry['form_key'],
			'status'       => (string) $entry['status'],
			'email'        => (string) ( $entry['email'] ?? '' ),
			'field_values' => (string) wp_json_encode( (object) ( $entry['values'] ?? array() ) ),
			'meta'         => (string) wp_json_encode( (object) ( $entry['meta'] ?? array() ) ),
			'fingerprint'  => (string) $entry['fingerprint'],
			'created_at'   => (string) ( $entry['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( ! empty( $entry['user_id'] ) ) {
			$row['user_id'] = (int) $entry['user_id'];
			$formats[]      = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; insert() prepares.
		$ok = $wpdb->insert( Schema::table(), $row, $formats );

		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * One entry with decoded `values` and `meta`, or null.
	 *
	 * @param int $id Entry ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['id']      = (int) $row['id'];
		$row['post_id'] = null === $row['post_id'] ? null : (int) $row['post_id'];
		$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];
		$row['values']  = json_decode( (string) $row['field_values'], true ) ?? array();
		$row['meta']    = json_decode( (string) $row['meta'], true ) ?? array();
		unset( $row['field_values'] );

		return $row;
	}

	/**
	 * Replaces an entry's meta.
	 *
	 * @param int                  $id   Entry ID.
	 * @param array<string, mixed> $meta Meta.
	 */
	public static function update_meta( int $id, array $meta ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; update() prepares.
		$wpdb->update( Schema::table(), array( 'meta' => (string) wp_json_encode( (object) $meta ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Whether a non-spam entry with this fingerprint was stored at or after a time.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @param int    $since       Unix time.
	 */
	public static function fingerprint_seen( string $fingerprint, int $since ): bool {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE fingerprint = %s AND status <> 'spam' AND created_at >= %s LIMIT 1", $table, $fingerprint, gmdate( 'Y-m-d H:i:s', $since ) ) );

		return null !== $found;
	}

	/**
	 * Statuses an entry can have, in the order the admin views list them.
	 */
	public const STATUSES = array( 'received', 'unconfirmed', 'processing', 'failed', 'spam' );

	/**
	 * A page of entries, newest first, with decoded `values` and `meta`.
	 *
	 * @param array<string, mixed> $args `form` (key, '' for every form), `status` (a status, or `all` for every
	 *                                   status but spam), `search` (part of the email), `order` (`desc` or `asc`
	 *                                   by date), `per_page`, `page` (from 1).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function query( array $args ): array {
		global $wpdb;

		$where    = self::where( $args );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$order    = 'asc' === ( $args['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$table    = Schema::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $where[0] holds only placeholders and its values are prepared here; $order is one of two literals.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where[0]}", ...array_merge( array( $table ), $where[1] ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where[0]} ORDER BY created_at {$order}, id {$order} LIMIT %d OFFSET %d", ...array_merge( array( $table ), $where[1], array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Number of entries by status for a form ('' for every form) and email search.
	 *
	 * @param string $form   Form key, or ''.
	 * @param string $search Part of the email, or ''.
	 * @return array<string, int> Count by status (every status in STATUSES present).
	 */
	public static function status_counts( string $form = '', string $search = '' ): array {
		global $wpdb;

		$where = self::where(
			array(
				'form'   => $form,
				'status' => '',
				'search' => $search,
			)
		);
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $where[0] holds only placeholders, its values are prepared here.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE {$where[0]} GROUP BY status", ...array_merge( array( $table ), $where[1] ) ), ARRAY_A );

		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Entry counts by form key and status, in one query (the Forms list).
	 *
	 * @return array<string, array<string, int>> Form key => status => count (statuses without entries absent).
	 */
	public static function counts_by_form(): array {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no input.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT form_key, status, COUNT(*) AS n FROM %i GROUP BY form_key, status', $table ), ARRAY_A );

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['form_key'] ][ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Distinct form keys that have entries (includes forms since deleted from the registry).
	 *
	 * @return string[]
	 */
	public static function form_keys(): array {
		global $wpdb;

		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no input.
		$keys = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT form_key FROM %i ORDER BY form_key', $table ) );

		return array_map( 'strval', is_array( $keys ) ? $keys : array() );
	}

	/**
	 * Entries whose `email` column matches an address (lowercased, exact), or that the user account with
	 * that address submitted (forms without an email item), oldest first.
	 *
	 * @param string $email  Address.
	 * @param int    $limit  Rows.
	 * @param int    $offset Rows to skip.
	 * @return array<int, array<string, mixed>>
	 */
	public static function by_email( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		$user  = '' === $email ? false : get_user_by( 'email', $email );
		$table = Schema::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s OR ( user_id > 0 AND user_id = %d ) ORDER BY id ASC LIMIT %d OFFSET %d', $table, $email, $user ? (int) $user->ID : 0, $limit, $offset ), ARRAY_A );

		return array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * IDs of entries in some statuses created before a time, optionally for one form.
	 *
	 * @param string[]    $statuses Statuses.
	 * @param string      $before   UTC `Y-m-d H:i:s`.
	 * @param string|null $form     Form key, or null for every form.
	 * @param int         $limit    Rows.
	 * @return int[]
	 */
	public static function ids_before( array $statuses, string $before, ?string $form, int $limit ): array {
		global $wpdb;

		if ( array() === $statuses ) {
			return array();
		}
		$table  = Schema::table();
		$params = array_values( $statuses );
		$sql    = 'status IN (' . implode( ', ', array_fill( 0, count( $params ), '%s' ) ) . ') AND created_at < %s';
		$params = array_merge( $params, array( $before ) );
		if ( null !== $form ) {
			$sql     .= ' AND form_key = %s';
			$params[] = $form;
		}
		$params[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $sql holds only placeholders.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE {$sql} ORDER BY id ASC LIMIT %d", ...array_merge( array( $table ), $params ) ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Deletes entries and their uploaded files. The single delete path for the admin, retention and the
	 * privacy eraser. Rows go first, then the files of the rows that were deleted.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return 0;
		}
		$table = Schema::table();
		$sql   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table; $sql holds only %d placeholders.
		$rows    = $wpdb->get_col( $wpdb->prepare( "SELECT field_values FROM %i WHERE id IN ({$sql}) AND field_values LIKE %s", ...array_merge( array( $table ), $ids, array( '%' . $wpdb->esc_like( '"path":"' . Uploads::DIR ) . '%' ) ) ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$sql})", ...array_merge( array( $table ), $ids ) ) );
		// phpcs:enable

		if ( $deleted > 0 ) {
			foreach ( is_array( $rows ) ? $rows : array() as $json ) {
				$values = json_decode( (string) $json, true );
				Uploads::delete( is_array( $values ) ? $values : array() );
			}
		}

		return (int) $deleted;
	}

	/**
	 * The WHERE clause (placeholders only) and its values for query() and status_counts().
	 *
	 * @param array<string, mixed> $args See query().
	 * @return array{0: string, 1: array<int, string>}
	 */
	private static function where( array $args ): array {
		global $wpdb;

		$sql    = array( '1=1' );
		$params = array();

		$form = (string) ( $args['form'] ?? '' );
		if ( '' !== $form ) {
			$sql[]    = 'form_key = %s';
			$params[] = $form;
		}

		$status = (string) ( $args['status'] ?? '' );
		if ( 'all' === $status ) {
			$sql[]    = 'status <> %s';
			$params[] = 'spam';
		} elseif ( '' !== $status ) {
			$sql[]    = 'status = %s';
			$params[] = $status;
		}

		$search = strtolower( trim( (string) ( $args['search'] ?? '' ) ) );
		if ( '' !== $search ) {
			$sql[]    = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return array( implode( ' AND ', $sql ), $params );
	}

	/**
	 * A row with typed IDs and decoded `values` and `meta` (the shape get() returns).
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['post_id'] = null === $row['post_id'] ? null : (int) $row['post_id'];
		$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];
		$row['values']  = json_decode( (string) $row['field_values'], true ) ?? array();
		$row['meta']    = json_decode( (string) $row['meta'], true ) ?? array();
		unset( $row['field_values'] );

		return $row;
	}
}
