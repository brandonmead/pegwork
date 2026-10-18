<?php
/**
 * Saves and deletes site-local (DB source) form definitions.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use Pegwork\Plugin;
use WP_Error;

/**
 * Backs pegwork_save_form() and pegwork_delete_form().
 *
 * A saved form passes the same validator as a file or imported form, its references resolve against the
 * effective registry, and it is stored in canonical form in the `pegwork_schema` option, so export and
 * import carry it unchanged. File-defined keys are read-only. No capability checks: callers authorize.
 */
final class Forms {

	/**
	 * Validates a form definition and upserts it into the DB source, then rebuilds the registry.
	 *
	 * @param array<string, mixed> $definition Form definition (format 1).
	 * @return true|WP_Error `pegwork_invalid_form` (400, messages by JSON pointer in data `fields`), or
	 *                       `pegwork_form_file_defined` (409) for a key a file package defines.
	 */
	public static function save( array $definition ): true|WP_Error {
		$errors = Validator::validate( 'form', $definition, Loader::DB_SOURCE );
		if ( $errors ) {
			return self::invalid( $errors );
		}

		$key      = (string) $definition['key'];
		$registry = Plugin::registry();
		$source   = $registry->form_source( $key );
		if ( null !== $source && 'db' !== $source ) {
			return self::file_defined( $key );
		}

		$known = array();
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$known[ $kind ] = $registry->{$section}();
		}
		$canonical             = Normalizer::normalize( 'form', $definition );
		$known['form'][ $key ] = $canonical;

		$errors = Validator::references( 'form', $definition, Loader::DB_SOURCE, $known );
		if ( $errors ) {
			return self::invalid( $errors );
		}

		$db       = self::option();
		$previous = $db;
		$entries  = array();
		$replaced = false;
		foreach ( $db['forms'] as $entry ) {
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
		$db['forms'] = $entries;

		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		if ( null === Plugin::registry()->form( $key ) ) {
			update_option( Loader::DB_OPTION, $previous, true );
			Plugin::init();
			return self::invalid(
				array_filter( Plugin::result()->errors, fn( ValidationError $error ) => Loader::DB_SOURCE === $error->file ),
				__( 'The form was not accepted by the registry.', 'pegwork' )
			);
		}

		return true;
	}

	/**
	 * Removes a form from the DB source, then rebuilds the registry. Its entries are kept.
	 *
	 * @param string $key Form key.
	 * @return true|WP_Error `pegwork_form_file_defined` (409) for a file form, `pegwork_form_not_found` (404).
	 */
	public static function delete( string $key ): true|WP_Error {
		$source = Plugin::registry()->form_source( $key );
		if ( null !== $source && 'db' !== $source ) {
			return self::file_defined( $key );
		}

		$db    = self::option();
		$kept  = array_values( array_filter( $db['forms'], fn( $entry ) => ! is_array( $entry ) || ( $entry['key'] ?? null ) !== $key ) );
		$found = count( $kept ) !== count( $db['forms'] );
		if ( ! $found ) {
			return new WP_Error(
				'pegwork_form_not_found',
				/* translators: %s: form key. */
				sprintf( __( 'No site form with key "%s".', 'pegwork' ), $key ),
				array( 'status' => 404 )
			);
		}

		$db['forms'] = $kept;
		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		return true;
	}

	/**
	 * The `pegwork_schema` option with a `forms` list.
	 *
	 * @return array<string, mixed>
	 */
	private static function option(): array {
		$db = get_option( Loader::DB_OPTION, array() );
		$db = is_array( $db ) ? $db : array();
		if ( ! isset( $db['forms'] ) || ! is_array( $db['forms'] ) || ! array_is_list( $db['forms'] ) ) {
			$db['forms'] = array();
		}

		return $db;
	}

	/**
	 * The error for a key that a file package defines.
	 *
	 * @param string $key Form key.
	 */
	private static function file_defined( string $key ): WP_Error {
		return new WP_Error(
			'pegwork_form_file_defined',
			/* translators: %s: form key. */
			sprintf( __( 'Form "%s" is defined by a file package and is read-only here. Duplicate it under a new key instead.', 'pegwork' ), $key ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The error for validation failures, with messages by JSON pointer.
	 *
	 * @param ValidationError[] $errors  Errors.
	 * @param string|null       $message Overall message; defaults to the joined messages.
	 */
	private static function invalid( array $errors, ?string $message = null ): WP_Error {
		$fields = array();
		foreach ( $errors as $error ) {
			$pointer            = '' === $error->pointer ? '/' : $error->pointer;
			$fields[ $pointer ] = isset( $fields[ $pointer ] ) ? $fields[ $pointer ] . ' ' . $error->message : $error->message;
		}

		return new WP_Error(
			'pegwork_invalid_form',
			$message ?? sprintf(
				/* translators: %s: validation messages. */
				__( 'This form cannot be saved: %s', 'pegwork' ),
				implode( ' ', array_map( fn( ValidationError $error ) => $error->message, $errors ) )
			),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}
}
