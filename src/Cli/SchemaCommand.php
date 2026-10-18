<?php
/**
 * WP-CLI: wp pegwork schema.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Cli;

use Pegwork\Plugin;
use Pegwork\Registry\Exporter;
use Pegwork\Registry\Importer;
use Pegwork\Registry\Loader;
use Pegwork\Registry\Status;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * Validates, lists, exports and imports Pegwork schema packages.
 */
final class SchemaCommand {

	/**
	 * Validates the registered packages and DB entries, or one package directory.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<path>]
	 * : Package directory to validate on its own instead of the registered packages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork schema validate
	 *     wp pegwork schema validate --dir=wp-content/plugins/sifg-config/schema
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function validate( array $args, array $assoc_args ): void {
		$result = isset( $assoc_args['dir'] ) ? Loader::load( array( $assoc_args['dir'] ) ) : Plugin::result();

		foreach ( $result->errors as $error ) {
			WP_CLI::log( (string) $error );
		}

		if ( ! $result->is_valid() ) {
			WP_CLI::error( sprintf( 'Schema invalid: %d error(s). Packages with errors are not registered.', count( $result->errors ) ) );
		}

		$registry = $result->registry;
		WP_CLI::success(
			sprintf(
				'Schema valid: %d content type(s), %d taxonomy(ies), %d relationship(s).',
				count( $registry->content_types() ),
				count( $registry->taxonomies() ),
				count( $registry->relationships() )
			)
		);
	}

	/**
	 * Lists the effective content types, taxonomies and relationships with their source.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function list_( array $args, array $assoc_args ): void {
		$status = Status::current();
		$items  = array();

		foreach ( $status->content_types() as $type ) {
			$items[] = array(
				'kind'    => 'content_type',
				'key'     => $type['key'],
				'label'   => $type['label'],
				'details' => sprintf( '%d field(s), %d taxonomy(ies)', count( $type['fields'] ), count( $type['taxonomies'] ) ),
				'source'  => $type['source'],
			);
		}
		foreach ( $status->taxonomies() as $taxonomy ) {
			$items[] = array(
				'kind'    => 'taxonomy',
				'key'     => $taxonomy['key'],
				'label'   => $taxonomy['label'],
				'details' => sprintf( '%d term(s)', $taxonomy['terms'] ) . ( $taxonomy['locked'] ? ', locked' : '' ),
				'source'  => $taxonomy['source'],
			);
		}
		foreach ( $status->relationships() as $relationship ) {
			$items[] = array(
				'kind'    => 'relationship',
				'key'     => $relationship['key'],
				'label'   => $relationship['from'] . ' -> ' . $relationship['to'],
				'details' => $relationship['cardinality'] . ', ' . $relationship['storage'] . ', ' . ( $relationship['required'] ? 'required' : 'optional' ),
				'source'  => $relationship['source'],
			);
		}

		if ( ! $items ) {
			WP_CLI::log( 'No schema registered.' );
			return;
		}

		format_items( $assoc_args['format'] ?? 'table', $items, array( 'kind', 'key', 'label', 'details', 'source' ) );
	}

	/**
	 * Reports DB entries shadowed by file packages with the same key, and rejected packages and DB entries.
	 *
	 * Prints one table per section that has rows: shadowed entries, then rejection errors.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format of each table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status   = Status::current();
		$format   = $assoc_args['format'] ?? 'table';
		$shadowed = $status->shadowed();
		$rejected = $status->rejected();

		if ( $shadowed ) {
			WP_CLI::warning( sprintf( '%d DB entry(ies) in the %s option are shadowed by file packages and ignored.', count( $shadowed ), Loader::DB_OPTION ) );
			format_items( $format, $shadowed, array( 'kind', 'key', 'by' ) );
		} else {
			WP_CLI::success( 'No shadowed DB entries.' );
		}

		if ( ! $rejected ) {
			WP_CLI::success( 'No rejected packages or DB entries.' );
			return;
		}

		$errors = array();
		foreach ( $rejected as $group ) {
			foreach ( $group['errors'] as $error ) {
				$errors[] = array( 'source' => $group['source'] ) + $error;
			}
		}
		WP_CLI::warning( sprintf( '%d source(s) rejected with %d error(s); their objects are not registered.', count( $rejected ), count( $errors ) ) );
		format_items( $format, $errors, array( 'source', 'code', 'file', 'pointer', 'message' ) );
	}

	/**
	 * Exports the effective registry as a package directory.
	 *
	 * Writes manifest.json plus one canonical JSON file per object. Output is deterministic (no timestamps or
	 * paths); object files from an earlier export that no longer exist are removed.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<path>]
	 * : Export directory, created when missing.
	 * ---
	 * default: pegwork-export
	 * ---
	 *
	 * [--source=<source>]
	 * : Which effective objects to export.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - db
	 *   - files
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork schema export --dir=schema-export
	 *     wp pegwork schema export --dir=site-schema --source=db
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function export( array $args, array $assoc_args ): void {
		$dir    = $assoc_args['dir'] ?? 'pegwork-export';
		$source = $assoc_args['source'] ?? 'all';
		if ( ! in_array( $source, Exporter::SOURCES, true ) ) {
			WP_CLI::error( sprintf( 'Unknown --source "%s". Use all, db or files.', $source ) );
		}

		$db     = get_option( Loader::DB_OPTION, array() );
		$files  = Exporter::files( Plugin::result(), $source, is_array( $db ) ? ( $db[ Importer::MANIFEST ] ?? null ) : null );
		$report = Exporter::write( $dir, $files );

		foreach ( $report['written'] as $path ) {
			WP_CLI::log( 'Wrote ' . $path );
		}
		foreach ( $report['removed'] as $path ) {
			WP_CLI::log( 'Removed ' . $path );
		}
		WP_CLI::success( sprintf( 'Exported %d object(s) to %s (%d stale file(s) removed).', count( $files ) - 1, $dir, count( $report['removed'] ) ) );
	}

	/**
	 * Imports a package directory into the pegwork_schema option.
	 *
	 * Validates the package with the file package rules, upserts its objects by key, stores its manifest and
	 * creates missing seed terms. Never deletes terms, posts or meta. Running it again reports 0 changes.
	 *
	 * ## OPTIONS
	 *
	 * <dir>
	 * : Package directory, such as the output of `wp pegwork schema export`.
	 *
	 * [--prune]
	 * : Also remove DB entries the package does not define. File packages are never changed.
	 *
	 * [--dry-run]
	 * : Report the changes without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork schema import schema-export --dry-run
	 *     wp pegwork schema import schema-export --prune
	 *
	 * @param string[]                   $args       Positional arguments: the package directory.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function import( array $args, array $assoc_args ): void {
		$dry_run = (bool) ( $assoc_args['dry-run'] ?? false );
		$plan    = Importer::import( $args[0], (bool) ( $assoc_args['prune'] ?? false ), $dry_run );

		if ( ! $plan->is_valid() ) {
			foreach ( $plan->errors as $error ) {
				WP_CLI::log( (string) $error );
			}
			WP_CLI::error( sprintf( 'Import rejected: %d error(s). Nothing was written.', count( $plan->errors ) ) );
		}

		$verbs = $dry_run
			? array(
				'add'    => 'Would add',
				'update' => 'Would update',
				'remove' => 'Would remove',
				'term'   => 'Would create term',
			)
			: array(
				'add'    => 'Added',
				'update' => 'Updated',
				'remove' => 'Removed',
				'term'   => 'Created term',
			);
		foreach ( $plan->changes as $change ) {
			WP_CLI::log( sprintf( '%s %s %s', $verbs[ $change['action'] ], $change['kind'], $change['key'] ) );
		}
		foreach ( $plan->terms as $term ) {
			WP_CLI::log( sprintf( '%s %s/%s', $verbs['term'], $term['taxonomy'], $term['slug'] ) );
		}
		foreach ( $plan->shadowed as $entry ) {
			WP_CLI::warning( sprintf( 'DB entry %s %s is shadowed by file package "%s" and ignored while that package is registered.', $entry['kind'], $entry['key'], $entry['by'] ) );
		}

		$prefix = $dry_run ? 'Dry run: ' : '';
		if ( 0 === $plan->count() ) {
			WP_CLI::success( $prefix . '0 changes.' );
			return;
		}
		WP_CLI::success(
			sprintf(
				'%s%d change(s): %d added, %d updated, %d removed, %d term(s) %s.',
				$prefix,
				$plan->count(),
				$plan->count_action( 'add' ),
				$plan->count_action( 'update' ),
				$plan->count_action( 'remove' ),
				count( $plan->terms ),
				$dry_run ? 'to create' : 'created'
			)
		);
	}
}
