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
	 * @return true|WP_Error `pegwork_invalid_<kind>` (400, messages by JSON pointer in data `fields`),
	 *                       `pegwork_<kind>_file_defined` (409) for a key a file package defines, or
	 *                       `pegwork_automation_in_use` (409, callers in data `automations`) when automations that
	 *                       start this one would no longer load (for example after its trigger stops being manual).
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

		$before = array_keys( $registry->automations() );
		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		$lost = 'automation' === $kind ? self::lost( $before, $key ) : array();
		if ( $lost ) {
			update_option( Loader::DB_OPTION, $previous, true );
			Plugin::init();
			return self::in_use( $key, $lost );
		}
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
	 * @return true|WP_Error `pegwork_<kind>_file_defined` (409) for a file definition, `pegwork_<kind>_not_found` (404),
	 *                       `pegwork_automation_in_use` (409, callers in data `automations`) for an automation that
	 *                       other automations start; nothing is removed then.
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

		$previous       = $db;
		$before         = array_keys( Plugin::registry()->automations() );
		$db[ $section ] = $kept;
		update_option( Loader::DB_OPTION, $db, true );
		Plugin::init();

		$lost = 'automation' === $kind ? self::lost( $before, $key ) : array();
		if ( $lost ) {
			update_option( Loader::DB_OPTION, $previous, true );
			Plugin::init();
			return self::in_use( $key, $lost );
		}

		return true;
	}

	/**
	 * Automations that were registered before a change and no longer are, other than the changed key: they
	 * start it (start_automation), and the loader drops an automation whose start target is gone or no
	 * longer startable.
	 *
	 * @param string[] $before Automation keys registered before the change.
	 * @param string   $key    Changed automation key.
	 * @return string[]
	 */
	private static function lost( array $before, string $key ): array {
		return array_values( array_diff( $before, array_keys( Plugin::registry()->automations() ), array( $key ) ) );
	}

	/**
	 * The error for a change that other automations' start steps would not survive.
	 *
	 * @param string   $key     Automation key.
	 * @param string[] $callers Automations that start it.
	 */
	private static function in_use( string $key, array $callers ): WP_Error {
		return new WP_Error(
			'pegwork_automation_in_use',
			/* translators: 1: automation key, 2: comma-separated automation keys. */
			sprintf( __( 'Automation "%1$s" is started by %2$s, which would stop loading. Remove or change those "Start another automation" steps first.', 'pegwork' ), $key, implode( ', ', $callers ) ),
			array(
				'status'      => 409,
				'automations' => $callers,
			)
		);
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
	 * For automations, a missing or empty required value is reported at its own pointer as
	 * "Required: Subject." (the builder shows it at that control), not at its object in schema words.
	 *
	 * @param string            $kind    Kind.
	 * @param ValidationError[] $errors  Errors.
	 * @param string|null       $message Overall message; defaults to the joined messages.
	 */
	private static function invalid( string $kind, array $errors, ?string $message = null ): WP_Error {
		$fields   = array();
		$messages = array();
		foreach ( $errors as $error ) {
			$pointer = '' === $error->pointer ? '/' : $error->pointer;
			$text    = $error->message;
			$name    = substr( (string) strrchr( $error->field, '/' ), 1 );
			if ( 'automation' === $kind && '' !== $name && ! is_numeric( $name ) ) {
				$pointer = $error->field;
				/* translators: %s: property name in words, such as "Subject" or "Content type". */
				$text = sprintf( __( 'Required: %s.', 'pegwork' ), ucfirst( str_replace( '_', ' ', $name ) ) );
			}
			$fields[ $pointer ] = isset( $fields[ $pointer ] ) ? $fields[ $pointer ] . ' ' . $text : $text;
			$messages[]         = $text;
		}
		$joined = implode( ' ', $messages );

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
