<?php
/**
 * Schema exporter: writes the effective registry as a package directory.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

/**
 * Turns a loaded registry into package files: `manifest.json` plus one file per object.
 *
 * Output is deterministic: objects are in canonical (normalized) form, object keys sorted, list order kept,
 * no timestamps and no paths. The same registry always exports the same bytes, whatever its source.
 */
final class Exporter {

	/**
	 * Values of `--source`: every effective object, only DB entries, or only file packages.
	 */
	public const SOURCES = array( 'all', 'db', 'files' );

	/**
	 * Encoding flags for every exported file.
	 */
	public const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Manifest used when the export does not come from exactly one package, before normalization.
	 */
	public const DEFAULT_MANIFEST = array(
		'format'  => Validator::FORMAT,
		'id'      => 'site',
		'label'   => 'Site schema',
		'version' => '0.0.0',
	);

	/**
	 * Builds the export files for a loaded registry.
	 *
	 * The manifest is the one of the only source that contributes objects: a file package's own manifest, or for
	 * the DB source the manifest stored by the last import. Several sources, or none, give DEFAULT_MANIFEST.
	 *
	 * @param Result $result      Loaded registry with sources and file package manifests.
	 * @param string $source      One of SOURCES.
	 * @param mixed  $db_manifest Manifest stored in the `pegwork_schema` option, if any; ignored unless valid.
	 * @return array<string, string> File contents by path relative to the export directory, sorted by path.
	 */
	public static function files( Result $result, string $source = 'all', mixed $db_manifest = null ): array {
		$files   = array();
		$sources = array();

		foreach ( Loader::SECTIONS as $kind => $section ) {
			foreach ( $result->registry->{$section}() as $key => $object ) {
				$from = $result->sources[ $kind ][ $key ];
				if ( ! self::selected( $source, $from ) ) {
					continue;
				}
				$sources[ $from ]                                      = true;
				$files[ Loader::DIRS[ $kind ] . '/' . $key . '.json' ] = self::encode( $object );
			}
		}

		$manifest = self::DEFAULT_MANIFEST;
		if ( 1 === count( $sources ) ) {
			$only     = (string) array_key_first( $sources );
			$manifest = Loader::DB_SOURCE === $only ? self::db_manifest( $db_manifest ) : ( $result->manifests[ $only ] ?? $manifest );
		}
		$files['manifest.json'] = self::encode( Normalizer::normalize( 'manifest', $manifest ) );

		ksort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Writes export files into a directory and removes stale object files from an earlier export.
	 *
	 * Only `manifest.json` and `*.json` files in the object subdirectories are managed; anything else is left alone.
	 *
	 * @param string                $dir   Export directory, created when missing.
	 * @param array<string, string> $files Output of files().
	 * @return array{written: string[], removed: string[]} Relative paths written and removed.
	 */
	public static function write( string $dir, array $files ): array {
		$dir     = rtrim( $dir, '/' );
		$removed = array();

		foreach ( Loader::DIRS as $subdir ) {
			foreach ( glob( $dir . '/' . $subdir . '/*.json' ) as $file ) {
				$path = $subdir . '/' . basename( $file );
				if ( ! isset( $files[ $path ] ) ) {
					wp_delete_file( $file );
					$removed[] = $path;
				}
			}
		}

		foreach ( $files as $path => $contents ) {
			wp_mkdir_p( dirname( $dir . '/' . $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI export to a local directory.
			file_put_contents( $dir . '/' . $path, $contents );
		}

		foreach ( Loader::DIRS as $subdir ) {
			if ( is_dir( $dir . '/' . $subdir ) && array() === glob( $dir . '/' . $subdir . '/*' ) ) {
				rmdir( $dir . '/' . $subdir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty object directory left by removed files.
			}
		}

		sort( $removed, SORT_STRING );

		return array(
			'written' => array_keys( $files ),
			'removed' => $removed,
		);
	}

	/**
	 * Encodes one canonical object as an export file.
	 *
	 * @param array<string, mixed> $data Canonical object.
	 */
	public static function encode( array $data ): string {
		return (string) wp_json_encode( $data, self::JSON_FLAGS ) . "\n";
	}

	/**
	 * Whether an object from a given source belongs in the export.
	 *
	 * @param string $source One of SOURCES.
	 * @param string $from   Package id or Loader::DB_SOURCE.
	 */
	private static function selected( string $source, string $from ): bool {
		return match ( $source ) {
			'db'    => Loader::DB_SOURCE === $from,
			'files' => Loader::DB_SOURCE !== $from,
			default => true,
		};
	}

	/**
	 * The stored DB manifest when it is valid, otherwise DEFAULT_MANIFEST.
	 *
	 * @param mixed $manifest Stored manifest.
	 * @return array<string, mixed>
	 */
	private static function db_manifest( mixed $manifest ): array {
		if ( is_array( $manifest ) && array() === Validator::validate( 'manifest', $manifest, Loader::DB_SOURCE, '/manifest' ) ) {
			return $manifest;
		}

		return self::DEFAULT_MANIFEST;
	}
}
