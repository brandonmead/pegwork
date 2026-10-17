<?php
/**
 * Registry loader: reads file packages and the DB source, applies precedence.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use JsonException;
use Pegwork\Api\Registry;

/**
 * Builds the effective registry.
 *
 * Precedence: packages load in registration order and a package that redefines an earlier key
 * is rejected whole. DB entries (`pegwork_schema` option) load after files; a DB entry whose key a
 * file already defines is shadowed, not an error. Any object whose references do not resolve
 * rejects its whole package (or its DB entry).
 */
final class Loader {

	/**
	 * Option holding site-local definitions: `content_types`, `taxonomies`, `relationships` lists.
	 */
	public const DB_OPTION = 'pegwork_schema';

	/**
	 * Source label and error file for DB entries. Package ids cannot contain a colon.
	 */
	public const DB_SOURCE = 'option:pegwork_schema';

	/**
	 * Package subdirectory for each object kind.
	 */
	public const DIRS = array(
		'content_type' => 'content-types',
		'taxonomy'     => 'taxonomies',
		'relationship' => 'relationships',
	);

	/**
	 * DB option section (and registry collection) for each object kind.
	 */
	public const SECTIONS = array(
		'content_type' => 'content_types',
		'taxonomy'     => 'taxonomies',
		'relationship' => 'relationships',
	);

	/**
	 * Loads file packages and DB entries into the effective registry.
	 *
	 * @param string[]             $package_dirs Package directories in registration order.
	 * @param array<string, mixed> $db           Value of the `pegwork_schema` option.
	 */
	public static function load( array $package_dirs, array $db = array() ): Result {
		$errors = array();
		$units  = array(); // Each unit is accepted or rejected whole: a package, or one DB entry.
		$taken  = array(); // Source of each registered key, by kind then key.
		$ids    = array();

		foreach ( $package_dirs as $dir ) {
			$package = self::read_package( (string) $dir );
			$found   = $package['errors'] ? $package['errors'] : self::conflicts( $package, $taken, $ids );
			if ( $found ) {
				array_push( $errors, ...$found );
				continue;
			}
			$ids[ $package['source'] ] = true;
			foreach ( $package['objects'] as $object ) {
				$taken[ $object['kind'] ][ $object['key'] ] = $package['source'];
			}
			$units[] = $package;
		}

		$shadowed = array();
		foreach ( self::SECTIONS as $kind => $section ) {
			$entries = $db[ $section ] ?? array();
			if ( ! is_array( $entries ) || ! array_is_list( $entries ) ) {
				$errors[] = new ValidationError( 'invalid_type', __( 'Expected a list.', 'pegwork' ), self::DB_SOURCE, '/' . $section );
				continue;
			}
			foreach ( $entries as $index => $data ) {
				$pointer = '/' . $section . '/' . $index;
				$found   = Validator::validate( $kind, $data, self::DB_SOURCE, $pointer );
				if ( $found ) {
					array_push( $errors, ...$found );
					continue;
				}
				$by = $taken[ $kind ][ $data['key'] ] ?? null;
				if ( self::DB_SOURCE === $by ) {
					/* translators: %s: key. */
					$errors[] = new ValidationError( 'duplicate_key', sprintf( __( 'Key "%s" is already defined in the DB source.', 'pegwork' ), $data['key'] ), self::DB_SOURCE, $pointer . '/key' );
					continue;
				}
				if ( null !== $by ) {
					$shadowed[] = array(
						'kind' => $kind,
						'key'  => $data['key'],
						'by'   => $by,
					);
					continue;
				}
				$taken[ $kind ][ $data['key'] ] = self::DB_SOURCE;
				$units[]                        = array(
					'source'  => self::DB_SOURCE,
					'objects' => array(
						array(
							'kind'    => $kind,
							'key'     => $data['key'],
							'data'    => $data,
							'file'    => self::DB_SOURCE,
							'pointer' => $pointer,
						),
					),
				);
			}
		}

		// Drop units with unresolved references until none remain; a dropped unit can break another.
		do {
			$known = array();
			foreach ( $units as $unit ) {
				foreach ( $unit['objects'] as $object ) {
					$known[ $object['kind'] ][ $object['key'] ] = true;
				}
			}
			$dropped = false;
			foreach ( $units as $index => $unit ) {
				$found = array();
				foreach ( $unit['objects'] as $object ) {
					array_push( $found, ...Validator::references( $object['kind'], $object['data'], $object['file'], $known, $object['pointer'] ) );
				}
				if ( $found ) {
					array_push( $errors, ...$found );
					unset( $units[ $index ] );
					$dropped = true;
				}
			}
		} while ( $dropped );

		$collections = array_fill_keys( self::SECTIONS, array() );
		$sources     = array();
		$manifests   = array();
		foreach ( $units as $unit ) {
			if ( isset( $unit['manifest_data'] ) ) {
				$manifests[ $unit['source'] ] = Normalizer::normalize( 'manifest', $unit['manifest_data'] );
			}
			foreach ( $unit['objects'] as $object ) {
				$collections[ self::SECTIONS[ $object['kind'] ] ][ $object['key'] ] = Normalizer::normalize( $object['kind'], $object['data'] );
				$sources[ $object['kind'] ][ $object['key'] ]                       = $unit['source'];
			}
		}

		return new Result(
			new Registry( $collections['content_types'], $collections['taxonomies'], $collections['relationships'] ),
			$sources,
			$errors,
			$shadowed,
			$manifests
		);
	}

	/**
	 * Reads and validates one package directory on its own. References to other packages are not checked.
	 *
	 * Used by load() and by the importer, so imported packages pass exactly the file package rules.
	 *
	 * @param string $dir Package directory.
	 * @return array{source: string, manifest: string, manifest_data: array<string, mixed>|null, objects: list<array<string, mixed>>, errors: ValidationError[]}
	 *         `manifest` is the manifest file path; `manifest_data` the decoded manifest once it validates.
	 */
	public static function read_package( string $dir ): array {
		$real          = realpath( $dir );
		$manifest_file = ( false === $real ? $dir : $real ) . '/manifest.json';
		$package       = array(
			'source'        => false === $real ? $dir : basename( $real ),
			'manifest'      => $manifest_file,
			'manifest_data' => null,
			'objects'       => array(),
			'errors'        => array(),
		);

		if ( false === $real || ! is_dir( $real ) ) {
			$package['errors'][] = new ValidationError( 'missing_package', __( 'Package directory not found.', 'pegwork' ), $dir );
			return $package;
		}

		if ( ! is_file( $manifest_file ) ) {
			$package['errors'][] = new ValidationError( 'missing_manifest', __( 'Package has no manifest.json.', 'pegwork' ), $manifest_file );
			return $package;
		}

		$manifest = self::read_json( $manifest_file, $package['errors'] );
		if ( ! $package['errors'] ) {
			$package['errors'] = Validator::validate( 'manifest', $manifest, $manifest_file );
		}
		if ( ! $package['errors'] ) {
			$package['source']        = $manifest['id'];
			$package['manifest_data'] = $manifest;
		}

		$seen = array();
		foreach ( self::DIRS as $kind => $subdir ) {
			foreach ( glob( $real . '/' . $subdir . '/*.json' ) as $file ) {
				$found = array();
				$data  = self::read_json( $file, $found );
				$found = $found ? $found : Validator::validate( $kind, $data, $file );
				if ( $found ) {
					array_push( $package['errors'], ...$found );
					continue;
				}
				if ( isset( $seen[ $kind ][ $data['key'] ] ) ) {
					$package['errors'][] = new ValidationError(
						'duplicate_key',
						/* translators: 1: key, 2: file that already defines it. */
						sprintf( __( 'Key "%1$s" is already defined in %2$s.', 'pegwork' ), $data['key'], $seen[ $kind ][ $data['key'] ] ),
						$file,
						'/key'
					);
					continue;
				}
				$seen[ $kind ][ $data['key'] ] = $file;
				$package['objects'][]          = array(
					'kind'    => $kind,
					'key'     => $data['key'],
					'data'    => $data,
					'file'    => $file,
					'pointer' => '',
				);
			}
		}

		return $package;
	}

	/**
	 * Reports a package whose id or keys an earlier package already registered.
	 *
	 * @param array<string, mixed>                 $package Valid package from read_package().
	 * @param array<string, array<string, string>> $taken   Registered keys: [kind][key] => source.
	 * @param array<string, bool>                  $ids     Registered package ids.
	 * @return ValidationError[]
	 */
	private static function conflicts( array $package, array $taken, array $ids ): array {
		$errors = array();

		if ( isset( $ids[ $package['source'] ] ) ) {
			$errors[] = new ValidationError(
				'duplicate_package',
				/* translators: %s: package id. */
				sprintf( __( 'Package "%s" is already registered; this copy was not registered.', 'pegwork' ), $package['source'] ),
				$package['manifest'],
				'/id'
			);
		}

		foreach ( $package['objects'] as $object ) {
			$by = $taken[ $object['kind'] ][ $object['key'] ] ?? null;
			if ( null !== $by ) {
				$errors[] = new ValidationError(
					'duplicate_key',
					/* translators: 1: key, 2: package that defines it, 3: rejected package. */
					sprintf( __( 'Key "%1$s" is already defined by package "%2$s"; package "%3$s" was not registered.', 'pegwork' ), $object['key'], $by, $package['source'] ),
					$object['file'],
					'/key'
				);
			}
		}

		return $errors;
	}

	/**
	 * Reads and decodes a JSON file, recording an error when that fails.
	 *
	 * @param string            $file   File path.
	 * @param ValidationError[] $errors Errors, appended to on failure.
	 * @return mixed Decoded value, or null on failure.
	 */
	private static function read_json( string $file, array &$errors ): mixed {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local package file, not a remote URL.
		$json = file_get_contents( $file );

		try {
			return json_decode( (string) $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			/* translators: %s: JSON parser message. */
			$errors[] = new ValidationError( 'invalid_json', sprintf( __( 'Invalid JSON: %s.', 'pegwork' ), $e->getMessage() ), $file );
			return null;
		}
	}
}
