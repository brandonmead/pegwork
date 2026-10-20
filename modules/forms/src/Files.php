<?php
/**
 * Downloads of uploaded files.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Serves a stored upload through `admin-post.php?action=pegwork_forms_file&entry=<id>&field=<key>`
 * with the forms capability and a nonce for that entry and field, as an attachment with
 * `X-Content-Type-Options: nosniff`. Logged-out requests never reach the handler (no `nopriv` hook:
 * admin-post.php answers 400).
 */
final class Files {

	/**
	 * `admin-post.php` action.
	 */
	public const ACTION = 'pegwork_forms_file';

	/**
	 * Hooks the handler. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) ) ) {
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		}
	}

	/**
	 * Nonce action for one entry's file.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $key      Item key.
	 */
	public static function nonce_action( int $entry_id, string $key ): string {
		return self::ACTION . '_' . $entry_id . '_' . $key;
	}

	/**
	 * Download URL of one entry's file (for the current user).
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $key      Item key.
	 */
	public static function url( int $entry_id, string $key ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'entry'    => $entry_id,
				'field'    => $key,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $entry_id, $key ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Sends the file. Hooked to admin_post_pegwork_forms_file.
	 */
	public static function handle(): void {
		$file = self::authorize( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorize() verifies the nonce.
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ), '', array( 'response' => (int) ( $file->get_error_data()['status'] ?? 403 ) ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $file['mime'] );
		header( 'Content-Disposition: ' . self::disposition( $file['name'] ) );
		header( 'Content-Length: ' . (string) filesize( $file['file'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Referrer-Policy: no-referrer' );
		readfile( $file['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a private file.
		exit;
	}

	/**
	 * Checks a download request: capability, nonce, entry, field and file.
	 *
	 * @param array<string, mixed> $request Query arguments (`entry`, `field`, `_wpnonce`).
	 * @return array{file: string, name: string, mime: string}|\WP_Error Error data `status` 403 or 404.
	 */
	public static function authorize( array $request ): array|\WP_Error {
		if ( ! current_user_can( Module::capability() ) ) {
			return new \WP_Error( 'pegwork_forms_file_forbidden', __( 'Sorry, you are not allowed to download form files.', 'pegwork' ), array( 'status' => 403 ) );
		}
		$entry_id = is_scalar( $request['entry'] ?? null ) ? absint( $request['entry'] ) : 0;
		$key      = is_string( $request['field'] ?? null ) ? sanitize_key( wp_unslash( $request['field'] ) ) : '';
		$nonce    = is_string( $request['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $request['_wpnonce'] ) ) : '';
		if ( 0 === $entry_id || '' === $key || ! wp_verify_nonce( $nonce, self::nonce_action( $entry_id, $key ) ) ) {
			return new \WP_Error( 'pegwork_forms_file_nonce', __( 'The link you followed has expired. Go back, reload the page and try again.', 'pegwork' ), array( 'status' => 403 ) );
		}

		$entry = Entries::get( $entry_id );
		$value = $entry['values'][ $key ] ?? null;
		$file  = Uploads::absolute( $value );
		if ( null === $file ) {
			return new \WP_Error( 'pegwork_forms_file_missing', __( 'This file does not exist. It may have been deleted.', 'pegwork' ), array( 'status' => 404 ) );
		}

		$mime = is_string( $value['mime'] ?? null ) && preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $value['mime'] ) ? $value['mime'] : 'application/octet-stream';

		return array(
			'file' => $file,
			'name' => is_string( $value['name'] ?? null ) && '' !== $value['name'] ? $value['name'] : wp_basename( $file ),
			'mime' => $mime,
		);
	}

	/**
	 * `Content-Disposition` for a download: an ASCII fallback name plus the UTF-8 name (RFC 6266).
	 *
	 * @param string $name File name.
	 */
	public static function disposition( string $name ): string {
		$ascii = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $name );
		$ascii = is_string( $ascii ) && '' !== trim( $ascii, '._' ) ? $ascii : 'file';

		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name );
	}
}
