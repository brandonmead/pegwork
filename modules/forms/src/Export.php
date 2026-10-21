<?php
/**
 * CSV export of entries.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Streams one form's entries in the current view (status and email search) as CSV: UTF-8 with a BOM,
 * fixed columns (entry ID, submitted, status, email, post ID) then one per value key (EntryFields::columns).
 * The fixed email column is left out when the form's email field (Definition::email_key()) has its own
 * column and every exported entry's email is the lowercased copy of that field's value, as it is when
 * the pipeline stores it.
 * Every cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a leading `'`, so a
 * spreadsheet shows it as text instead of running it as a formula.
 */
final class Export {

	/**
	 * `admin-post.php` action and nonce action.
	 */
	public const ACTION = 'pegwork_entries_export';

	/**
	 * Entries read per query while streaming.
	 */
	private const BATCH = 200;

	/**
	 * Registers the download handler.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) ) ) {
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		}
	}

	/**
	 * Download URL for a form and view.
	 *
	 * @param string $form   Form key.
	 * @param string $status Status or `all`.
	 * @param string $search Email search.
	 */
	public static function url( string $form, string $status, string $search ): string {
		$args = array(
			'action' => self::ACTION,
			'form'   => $form,
			'status' => $status,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Handles a download: capability, nonce, headers, stream. Hooked to admin_post_pegwork_entries_export.
	 */
	public static function handle(): void {
		EntriesAdmin::authorize();
		EntriesAdmin::verify_nonce( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above.
		$form   = isset( $_GET['form'] ) ? sanitize_key( wp_unslash( $_GET['form'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		if ( '' === $form ) {
			wp_die( esc_html__( 'Choose a form to export.', 'pegwork' ), '', array( 'response' => 400 ) );
		}
		$status = EntriesAdmin::view_status( $status );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $form, $status ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		if ( false !== $out ) {
			self::write( $out, $form, $status, $search );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream, not a file.
		}
		exit;
	}

	/**
	 * Writes the CSV (BOM, header row, one row per entry) to a stream.
	 *
	 * @param resource $out    Writable stream.
	 * @param string   $form   Form key.
	 * @param string   $status Status or `all`.
	 * @param string   $search Email search.
	 * @return int Entries written.
	 */
	public static function write( $out, string $form, string $status, string $search = '' ): int {
		$args = array(
			'form'     => $form,
			'status'   => $status,
			'search'   => $search,
			'order'    => 'asc',
			'per_page' => self::BATCH,
		);

		// Columns need every extra key first, so read the entries in batches once for the header.
		$definition = pegwork_registry()->form( $form );
		$columns    = EntryFields::columns( $definition, array() );
		// The entry email is a lowercased copy of the form's email field. When every entry's copy matches
		// its field value, that field's column already holds it and the fixed column would repeat it.
		$email_key  = is_array( $definition ) ? Definition::email_key( $definition ) : null;
		$with_email = null === $email_key;
		for ( $page = 1; ; $page++ ) {
			$batch    = Entries::query( $args + array( 'page' => $page ) );
			$columns += EntryFields::columns( $definition, $batch['items'] );
			foreach ( $with_email ? array() : $batch['items'] as $entry ) {
				$value      = $entry['values'][ $email_key ] ?? null;
				$with_email = ! is_string( $value ) || strtolower( $value ) !== (string) $entry['email'];
				if ( $with_email ) {
					break;
				}
			}
			if ( count( $batch['items'] ) < self::BATCH ) {
				break;
			}
		}
		$items      = EntryFields::items( $definition );
		$with_email = $with_email || ! isset( $columns[ (string) $email_key ] );

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Stream output; UTF-8 BOM so spreadsheets read the encoding.
		self::row(
			$out,
			array_merge(
				array( __( 'Entry ID', 'pegwork' ), __( 'Submitted (UTC)', 'pegwork' ), __( 'Status', 'pegwork' ) ),
				$with_email ? array( __( 'Email', 'pegwork' ) ) : array(),
				array( __( 'Post ID', 'pegwork' ) ),
				array_values( $columns )
			)
		);

		$written = 0;
		for ( $page = 1; ; $page++ ) {
			$batch = Entries::query( $args + array( 'page' => $page ) );
			foreach ( $batch['items'] as $entry ) {
				$row = array_merge(
					array( (string) $entry['id'], (string) $entry['created_at'], (string) $entry['status'] ),
					$with_email ? array( (string) $entry['email'] ) : array(),
					array( null === $entry['post_id'] ? '' : (string) $entry['post_id'] )
				);
				foreach ( array_keys( $columns ) as $key ) {
					$row[] = array_key_exists( $key, $entry['values'] ) ? EntryFields::text( $items[ $key ] ?? null, $entry['values'][ $key ] ) : '';
				}
				self::row( $out, $row );
				++$written;
			}
			if ( count( $batch['items'] ) < self::BATCH ) {
				break;
			}
		}
		return $written;
	}

	/**
	 * A cell made safe for spreadsheets: a leading `'` when it starts with a formula trigger.
	 *
	 * @param string $value Cell text.
	 */
	public static function cell( string $value ): string {
		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Writes one escaped row.
	 *
	 * @param resource $out   Stream.
	 * @param string[] $cells Cells.
	 */
	private static function row( $out, array $cells ): void {
		// RFC 4180 quoting only (no backslash escape character); explicit, as PHP 8.4 deprecates the default.
		fputcsv( $out, array_map( array( self::class, 'cell' ), $cells ), ',', '"', '' );
	}

	/**
	 * Download file name: `entries-<form>-<status>-<site date>.csv`.
	 *
	 * @param string $form   Form key (sanitized).
	 * @param string $status View status.
	 */
	private static function filename( string $form, string $status ): string {
		return sanitize_file_name( 'entries-' . $form . '-' . $status . '-' . wp_date( 'Y-m-d' ) . '.csv' );
	}
}
