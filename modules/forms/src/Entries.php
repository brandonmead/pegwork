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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from $wpdb->prefix.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s AND status <> 'spam' AND created_at >= %s LIMIT 1", $fingerprint, gmdate( 'Y-m-d H:i:s', $since ) ) );

		return null !== $found;
	}
}
