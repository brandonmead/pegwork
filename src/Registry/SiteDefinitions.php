<?php
/**
 * Saves and deletes site-local (DB source) form and automation definitions.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use InvalidArgumentException;
use Pegwork\Plugin;
use WP_Error;

/**
 * Backs pegwork_save_form(), pegwork_delete_form(), pegwork_save_automation() and
 * pegwork_delete_automation().
 *
 * A saved definition passes the same validator as a file or imported one, its references resolve
 * against the effective registry, and it is stored in canonical form in the `pegwork_schema` option
 * (by key, in place), so export and import carry it unchanged. File-defined keys are read-only. No
 * capability checks: callers authorize.
 */
final class SiteDefinitions {

	/**
	 * Kinds a site may save, with the error code stem of each.
	 */
	private const KINDS = array(
		'form'       => 'form',
		'automation' => 'automation',
	);

	/**
	 * Validates a definition and upserts it into the DB source, then rebuilds the registry.
	 *
	 * @param string               $kind       `form` or `automation`.
	 * @param array<string, mixed> $definition Definition (format 1).
	 * @return true|WP_Error `pegwork_invalid_<kind>` (400, messages by JSON pointer in data `fields`), or
	 *                       `pegwork_<kind>_file_defined` (409) for a key a file package defines.
	 */
	public static function save( string $kind, array $definition ): true|WP_Error {
		$section = self::section( $kind );
		$errors  = Validator::validate( $kind, $definition, Loader::DB_SOURCE );
		if ( $errors ) {
			return self::invalid( $kind, $errors );
		}

		$key      = (string) $definition['key'];
		$registry = Plugin::registry();
		$source   = self::source( $kind, $key );
		if ( null !== $source && 'db' !== $source ) {
			return self::file_defined( $kind, $key );
		}

		$known = array();
		foreach ( Loader::SECTIONS as $known_kind => $known_section ) {
			$known[ $known_kind ] = $registry->{$known_section}();
		}
		$canonical              = Normalizer::normalize( $kind, $definition );
		$known[ $kind ][ $key ] = $canonical;

		$errors = Validator::references( $kind, $definition, Loader::DB_SOURCE, $known );
		if ( $errors ) {
			return self::invalid( $kind, $errors );
		}

		$db       = self::option( $section );
		$previous = $db;
		$entries  = array();
		$replaced = false;
		foreach ( $db[ $section ] as $entry ) {
			if ( is_array( $entry ) && ( $entry['key'] ?? null ) === $key ) {
				if ( ! $replaced ) {
					$entries[] = $canonical;
					$replaced  = true;
				}
				continue;
			}
			$entries[] = $entry;
		}
		if ( ! $replaced ) {
			$entries[] = $canonical;
		}
		$db[ $section ] = $entries;

		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		if ( null === Plugin::registry()->{$kind}( $key ) ) {
			update_option( Loader::DB_OPTION, $previous, true );
			Plugin::init();
			return self::invalid(
				$kind,
				array_filter( Plugin::result()->errors, fn( ValidationError $error ) => Loader::DB_SOURCE === $error->file ),
				'form' === $kind
					? __( 'The form was not accepted by the registry.', 'pegwork' )
					: __( 'The automation was not accepted by the registry.', 'pegwork' )
			);
		}

		return true;
	}

	/**
	 * Removes a definition from the DB source, then rebuilds the registry.
	 *
	 * @param string $kind `form` or `automation`.
	 * @param string $key  Key.
	 * @return true|WP_Error `pegwork_<kind>_file_defined` (409) for a file definition, `pegwork_<kind>_not_found` (404).
	 */
	public static function delete( string $kind, string $key ): true|WP_Error {
		$section = self::section( $kind );
		$source  = self::source( $kind, $key );
		if ( null !== $source && 'db' !== $source ) {
			return self::file_defined( $kind, $key );
		}

		$db    = self::option( $section );
		$kept  = array_values( array_filter( $db[ $section ], fn( $entry ) => ! is_array( $entry ) || ( $entry['key'] ?? null ) !== $key ) );
		$found = count( $kept ) !== count( $db[ $section ] );
		if ( ! $found ) {
			return new WP_Error(
				'pegwork_' . self::KINDS[ $kind ] . '_not_found',
				'form' === $kind
					/* translators: %s: form key. */
					? sprintf( __( 'No site form with key "%s".', 'pegwork' ), $key )
					/* translators: %s: automation key. */
					: sprintf( __( 'No site automation with key "%s".', 'pegwork' ), $key ),
				array( 'status' => 404 )
			);
		}

		$db[ $section ] = $kept;
		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		return true;
	}

	/**
	 * The option section of a kind; refuses kinds a site cannot save.
	 *
	 * @param string $kind Kind.
	 * @throws InvalidArgumentException For any other kind (a programming error).
	 */
	private static function section( string $kind ): string {
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			throw new InvalidArgumentException( esc_html( 'Not a site-definition kind: ' . $kind ) );
		}
		return Loader::SECTIONS[ $kind ];
	}

	/**
	 * Where a definition comes from (Registry::form_source() or automation_source()).
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 */
	private static function source( string $kind, string $key ): ?string {
		$registry = Plugin::registry();
		return 'form' === $kind ? $registry->form_source( $key ) : $registry->automation_source( $key );
	}

	/**
	 * The `pegwork_schema` option with a list for the section.
	 *
	 * @param string $section Option section.
	 * @return array<string, mixed>
	 */
	private static function option( string $section ): array {
		$db = get_option( Loader::DB_OPTION, array() );
		$db = is_array( $db ) ? $db : array();
		if ( ! isset( $db[ $section ] ) || ! is_array( $db[ $section ] ) || ! array_is_list( $db[ $section ] ) ) {
			$db[ $section ] = array();
		}

		return $db;
	}

	/**
	 * The error for a key that a file package defines.
	 *
	 * @param string $kind Kind.
	 * @param string $key  Key.
	 */
	private static function file_defined( string $kind, string $key ): WP_Error {
		return new WP_Error(
			'pegwork_' . self::KINDS[ $kind ] . '_file_defined',
			'form' === $kind
				/* translators: %s: form key. */
				? sprintf( __( 'Form "%s" is defined by a file package and is read-only here. Duplicate it under a new key instead.', 'pegwork' ), $key )
				/* translators: %s: automation key. */
				: sprintf( __( 'Automation "%s" is defined by a file package and is read-only here. Duplicate it under a new key instead.', 'pegwork' ), $key ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The error for validation failures, with messages by JSON pointer.
	 *
	 * @param string            $kind    Kind.
	 * @param ValidationError[] $errors  Errors.
	 * @param string|null       $message Overall message; defaults to the joined messages.
	 */
	private static function invalid( string $kind, array $errors, ?string $message = null ): WP_Error {
		$fields = array();
		foreach ( $errors as $error ) {
			$pointer            = '' === $error->pointer ? '/' : $error->pointer;
			$fields[ $pointer ] = isset( $fields[ $pointer ] ) ? $fields[ $pointer ] . ' ' . $error->message : $error->message;
		}
		$joined = implode( ' ', array_map( fn( ValidationError $error ) => $error->message, $errors ) );

		return new WP_Error(
			'pegwork_invalid_' . self::KINDS[ $kind ],
			$message ?? ( 'form' === $kind
				/* translators: %s: validation messages. */
				? sprintf( __( 'This form cannot be saved: %s', 'pegwork' ), $joined )
				/* translators: %s: validation messages. */
				: sprintf( __( 'This automation cannot be saved: %s', 'pegwork' ), $joined ) ),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}
}
