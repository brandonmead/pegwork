<?php
/**
 * File uploads for `file` items.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Checks, stores and deletes uploaded files. One file per item.
 *
 * Checks: the item's `accept` extensions intersected with get_allowed_mime_types(); content sniffing
 * with wp_check_filetype_and_ext() must agree; size within `max_size_mb` and wp_max_upload_size();
 * no denylisted extension anywhere in the name (`x.php.jpg`). Files are stored outside the media
 * library (attachments get public, guessable URLs) under `uploads/pegwork-forms/<32 hex>/<name>`,
 * with `index.php` and a deny-all `.htaccess` in `pegwork-forms/`; nginx ignores `.htaccess`, so the
 * random directory is the real guard. They are served only by Files (admin-post.php, capability and
 * nonce). A stored value is `{path, name, size, mime}`, `path` relative to the uploads base directory.
 */
final class Uploads {

	/**
	 * Directory under the uploads base directory.
	 */
	public const DIR = 'pegwork-forms';

	/**
	 * Extensions never accepted, last or anywhere else in a name (the registry denylist, plus other
	 * server-executed script extensions that a misconfigured server might run from the middle of a name).
	 */
	private const DENIED = '/^(php[a-z0-9]*|phtml|pht|phar|html?|xhtml|shtml|svgz?|m?js|exe|cgi|pl|py|sh|asp|aspx|jsp|bat|cmd|com)$/';

	/**
	 * Shape of a stored path.
	 */
	private const PATH = '#^pegwork-forms/[0-9a-f]{32}/[^/\\\\]+$#';

	/**
	 * Uploaded files of a request for one form, by item key, from `$_FILES['pegwork']`.
	 *
	 * Only files PHP received for this request (`is_uploaded_file()`) or that carry an upload error are
	 * kept, so a posted path can never point at a file already on the server. "No file" is dropped.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @return array<string, array{name: string, tmp_name: string, error: int, size: int}>
	 */
	public static function from_request( array $form ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Submission::process() checks the nonce (logged in) and the signed token; names are sanitized in check(), tmp paths must pass is_uploaded_file().
		$raw = isset( $_FILES[ Submission::VALUES ] ) && is_array( $_FILES[ Submission::VALUES ] ) ? $_FILES[ Submission::VALUES ] : array();
		if ( ! is_array( $raw['name'] ?? null ) ) {
			return array();
		}

		$files = array();
		foreach ( Definition::items_by_key( $form ) as $key => $item ) {
			if ( 'file' !== ( $item['type'] ?? '' ) || ! isset( $raw['name'][ $key ] ) || ! is_string( $raw['name'][ $key ] ) ) {
				continue;
			}
			$file = array(
				'name'     => (string) $raw['name'][ $key ],
				'tmp_name' => is_string( $raw['tmp_name'][ $key ] ?? null ) ? $raw['tmp_name'][ $key ] : '',
				'error'    => (int) ( $raw['error'][ $key ] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $raw['size'][ $key ] ?? 0 ),
			);
			if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
				continue;
			}
			if ( UPLOAD_ERR_OK === $file['error'] && ( '' === $file['tmp_name'] || ! is_uploaded_file( $file['tmp_name'] ) ) ) {
				continue;
			}
			$files[ $key ] = $file;
		}
		return $files;
	}

	/**
	 * Allowed mime types for an item: its `accept` extensions intersected with get_allowed_mime_types().
	 *
	 * @param array<string, mixed> $item File item.
	 * @return array<string, string> Extension pattern (`jpg|jpeg`) => mime type, as WordPress expects.
	 */
	public static function mimes( array $item ): array {
		$accept = array();
		foreach ( (array) ( $item['accept'] ?? array() ) as $extension ) {
			$extension = strtolower( (string) $extension );
			if ( ! preg_match( self::DENIED, $extension ) ) {
				$accept[] = $extension;
			}
		}

		$mimes = array();
		foreach ( get_allowed_mime_types() as $pattern => $mime ) {
			$allowed = array_values( array_intersect( explode( '|', (string) $pattern ), $accept ) );
			if ( array() !== $allowed ) {
				$mimes[ implode( '|', $allowed ) ] = (string) $mime;
			}
		}
		return $mimes;
	}

	/**
	 * Extensions an item accepts after the intersection, in `accept` order.
	 *
	 * @param array<string, mixed> $item File item.
	 * @return string[]
	 */
	public static function extensions( array $item ): array {
		$allowed = explode( '|', implode( '|', array_keys( self::mimes( $item ) ) ) );

		return array_values( array_filter( array_map( 'strtolower', array_map( 'strval', (array) ( $item['accept'] ?? array() ) ) ), fn( string $ext ) => in_array( $ext, $allowed, true ) ) );
	}

	/**
	 * Largest accepted file in bytes: `max_size_mb` when set, never above wp_max_upload_size().
	 *
	 * @param array<string, mixed> $item File item.
	 */
	public static function max_bytes( array $item ): int {
		$server = (int) wp_max_upload_size();
		if ( isset( $item['max_size_mb'] ) && is_numeric( $item['max_size_mb'] ) ) {
			$own = (int) floor( (float) $item['max_size_mb'] * MB_IN_BYTES );
			return $server > 0 ? min( $own, $server ) : $own;
		}
		return $server;
	}

	/**
	 * Help text stating the accepted types and size ("PDF or DOCX, up to 5 MB.").
	 *
	 * @param array<string, mixed> $item File item.
	 */
	public static function hint( array $item ): string {
		$types = array_map( 'strtoupper', self::extensions( $item ) );
		$last  = array_pop( $types );
		$list  = null === $last ? '' : ( array() === $types ? $last : sprintf(
			/* translators: 1: comma-separated file types, 2: the last file type. */
			__( '%1$s or %2$s', 'pegwork' ),
			implode( ', ', $types ),
			$last
		) );

		/* translators: 1: accepted file types, 2: largest size, such as "5 MB". */
		return sprintf( __( '%1$s, up to %2$s.', 'pegwork' ), $list, size_format( self::max_bytes( $item ) ) );
	}

	/**
	 * Checks one upload. Nothing is moved.
	 *
	 * @param array<string, mixed>                                         $item File item.
	 * @param array{name: string, tmp_name: string, error: int, size: int} $file Upload.
	 * @return array{name: string, size: int, mime: string}|string The file's metadata (proper name,
	 *                                                              sniffed type), or the error message.
	 */
	public static function check( array $item, array $file ): array|string {
		$label = (string) $item['label'];
		$max   = self::max_bytes( $item );

		if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			/* translators: 1: field label, 2: largest size, such as "5 MB". */
			return sprintf( __( 'The file for %1$s is too large. Choose a file up to %2$s.', 'pegwork' ), $label, size_format( $max ) );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] || ! is_readable( $file['tmp_name'] ) ) {
			/* translators: %s: field label. */
			return sprintf( __( 'The file for %s could not be uploaded. Try again.', 'pegwork' ), $label );
		}

		$size = (int) filesize( $file['tmp_name'] );
		if ( $size <= 0 ) {
			/* translators: %s: field label. */
			return sprintf( __( 'The file for %s is empty.', 'pegwork' ), $label );
		}
		if ( $max > 0 && $size > $max ) {
			/* translators: 1: field label, 2: largest size, such as "5 MB". */
			return sprintf( __( 'The file for %1$s is too large. Choose a file up to %2$s.', 'pegwork' ), $label, size_format( $max ) );
		}

		$wrong_type = sprintf(
			/* translators: 1: field label, 2: accepted types and size. */
			__( 'The file for %1$s must be %2$s', 'pegwork' ),
			$label,
			self::hint( $item )
		);

		$name  = sanitize_file_name( wp_basename( str_replace( '\\', '/', $file['name'] ) ) );
		$parts = explode( '.', strtolower( $name ) );
		if ( count( $parts ) < 2 || '' === $parts[0] ) {
			return $wrong_type;
		}
		// Every part after the first: `x.php.jpg` is refused for its "php", not just checked as "jpg".
		// sanitize_file_name() turns unknown middle parts into "php_", so the underscore is ignored.
		foreach ( array_slice( $parts, 1 ) as $part ) {
			if ( preg_match( self::DENIED, rtrim( $part, '_' ) ) ) {
				return $wrong_type;
			}
		}

		$mimes = self::mimes( $item );
		$typed = wp_check_filetype( $name, $mimes );
		if ( empty( $typed['ext'] ) ) {
			return $wrong_type;
		}
		// Content sniffing: the bytes must be what the extension says.
		$sniffed = wp_check_filetype_and_ext( $file['tmp_name'], $name, $mimes );
		if ( empty( $sniffed['ext'] ) || empty( $sniffed['type'] ) ) {
			/* translators: %s: field label. */
			return sprintf( __( 'The file for %s does not match its file type. Choose the original file.', 'pegwork' ), $label );
		}

		return array(
			'name' => is_string( $sniffed['proper_filename'] ?? null ) && '' !== $sniffed['proper_filename'] ? (string) $sniffed['proper_filename'] : $name,
			'size' => $size,
			'mime' => (string) $sniffed['type'],
		);
	}

	/**
	 * Moves a checked upload into its own random directory.
	 *
	 * Uses wp_handle_sideload() (the same checks as wp_handle_upload(), with `test_form` off and the
	 * item's mimes): from_request() already required is_uploaded_file(), and the sideload variant lets
	 * the pipeline run in tests.
	 *
	 * @param array<string, mixed>                                         $item File item.
	 * @param array{name: string, tmp_name: string, error: int, size: int} $file Upload.
	 * @param array{name: string, size: int, mime: string}                 $meta From check().
	 * @return array{path: string, name: string, size: int, mime: string}|\WP_Error
	 */
	public static function store( array $item, array $file, array $meta ): array|\WP_Error {
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$base = self::base_dir();
		if ( null === $base || ! self::guard( $base ) ) {
			return new \WP_Error( 'pegwork_forms_upload_dir', __( 'The upload folder could not be created.', 'pegwork' ) );
		}

		$dir    = bin2hex( random_bytes( 16 ) );
		$subdir = '/' . self::DIR . '/' . $dir;
		$filter = static function ( $uploads ) use ( $subdir ) {
			$uploads['subdir'] = $subdir;
			$uploads['path']   = $uploads['basedir'] . $subdir;
			$uploads['url']    = $uploads['baseurl'] . $subdir;
			return $uploads;
		};

		add_filter( 'upload_dir', $filter, PHP_INT_MAX );
		$upload = array(
			'name'     => $meta['name'],
			'type'     => $meta['mime'],
			'tmp_name' => $file['tmp_name'],
			'error'    => UPLOAD_ERR_OK,
			'size'     => $meta['size'],
		);
		$result = wp_handle_sideload(
			$upload,
			array(
				'test_form' => false,
				'mimes'     => self::mimes( $item ),
			)
		);
		remove_filter( 'upload_dir', $filter, PHP_INT_MAX );

		if ( ! is_array( $result ) || isset( $result['error'] ) || ! is_string( $result['file'] ?? null ) ) {
			return new \WP_Error( 'pegwork_forms_upload', is_array( $result ) && is_string( $result['error'] ?? null ) ? $result['error'] : __( 'The file could not be saved.', 'pegwork' ) );
		}
		self::write( $base . '/' . $dir . '/index.php', "<?php\n// Silence is golden.\n" );

		return array(
			'path' => self::DIR . '/' . $dir . '/' . wp_basename( $result['file'] ),
			'name' => $meta['name'],
			'size' => $meta['size'],
			'mime' => is_string( $result['type'] ?? null ) && '' !== $result['type'] ? $result['type'] : $meta['mime'],
		);
	}

	/**
	 * Whether a value is a stored file (`path` of the right shape).
	 *
	 * @param mixed $value Stored value.
	 */
	public static function is_stored( mixed $value ): bool {
		return is_array( $value ) && is_string( $value['path'] ?? null ) && 1 === preg_match( self::PATH, $value['path'] );
	}

	/**
	 * Absolute path of a stored file that exists inside `uploads/pegwork-forms/`, or null.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function absolute( mixed $value ): ?string {
		if ( ! self::is_stored( $value ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$path    = $uploads['basedir'] . '/' . $value['path'];
		$real    = realpath( $path );
		$root    = realpath( $uploads['basedir'] . '/' . self::DIR );
		if ( false === $real || false === $root || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Deletes the stored files among some values, with their random directories.
	 *
	 * @param array<string, mixed> $values Entry values.
	 * @return int Files deleted.
	 */
	public static function delete( array $values ): int {
		$deleted = 0;
		foreach ( $values as $value ) {
			$file = self::absolute( $value );
			if ( null === $file ) {
				continue;
			}
			$dir = dirname( $file );
			if ( wp_delete_file_from_directory( $file, $dir ) || ! file_exists( $file ) ) {
				++$deleted;
			}
			$index = $dir . '/index.php';
			if ( is_file( $index ) ) {
				wp_delete_file( $index );
			}
			if ( is_dir( $dir ) && array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty local directory.
			}
		}
		return $deleted;
	}

	/**
	 * Values without file paths (events and other public payloads get `{name, size, mime}` only).
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, mixed>
	 */
	public static function without_paths( array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) && isset( $value['name'], $value['size'] ) && ! array_is_list( $value ) ) {
				$values[ $key ] = array_intersect_key( $value, array_flip( array( 'name', 'size', 'mime' ) ) );
			}
		}
		return $values;
	}

	/**
	 * `uploads/pegwork-forms`, created when missing, or null when uploads are not writable.
	 */
	private static function base_dir(): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}
		$dir = $uploads['basedir'] . '/' . self::DIR;
		return wp_mkdir_p( $dir ) ? $dir : null;
	}

	/**
	 * Writes `index.php` and a deny-all `.htaccess` into `pegwork-forms/` when missing.
	 *
	 * @param string $dir `uploads/pegwork-forms`.
	 */
	private static function guard( string $dir ): bool {
		$ok = true;
		if ( ! is_file( $dir . '/index.php' ) ) {
			$ok = self::write( $dir . '/index.php', "<?php\n// Silence is golden.\n" ) && $ok;
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			$rules = "# Pegwork Forms: uploads are served only through admin-post.php?action=pegwork_forms_file.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
			$ok    = self::write( $dir . '/.htaccess', $rules ) && $ok;
		}
		return $ok;
	}

	/**
	 * Writes a small file.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 */
	private static function write( string $path, string $content ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small guard file in uploads; WP_Filesystem may need credentials.
		return false !== file_put_contents( $path, $content );
	}
}
