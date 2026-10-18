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
use Pegwork\Registry\Result;
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
				'Schema valid: %d content type(s), %d taxonomy(ies), %d relationship(s), %d form(s), %d automation(s), %d options page(s).',
				count( $registry->content_types() ),
				count( $registry->taxonomies() ),
				count( $registry->relationships() ),
				count( $registry->forms() ),
				count( $registry->automations() ),
				count( $registry->options_pages() )
			)
		);
	}

	/**
	 * Lists the effective registry objects with their source: content types, taxonomies, relationships,
	 * listings (one row per content type with a `listing` section), forms, automations and options pages.
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
		$result   = Plugin::result();
		$registry = $result->registry;
		$items    = array();

		foreach ( $registry->content_types() as $key => $type ) {
			$items[] = array(
				'kind'    => 'content_type',
				'key'     => $key,
				'label'   => $type['labels']['plural'],
				'details' => sprintf( '%d field(s), %d taxonomy(ies)', count( $type['fields'] ), count( $type['taxonomies'] ) ),
				'source'  => $result->sources['content_type'][ $key ],
			);
		}
		foreach ( $registry->taxonomies() as $key => $taxonomy ) {
			$items[] = array(
				'kind'    => 'taxonomy',
				'key'     => $key,
				'label'   => $taxonomy['labels']['plural'],
				'details' => sprintf( '%d term(s)', count( $taxonomy['terms'] ) ),
				'source'  => $result->sources['taxonomy'][ $key ],
			);
		}
		foreach ( $registry->relationships() as $key => $relationship ) {
			$items[] = array(
				'kind'    => 'relationship',
				'key'     => $key,
				'label'   => $relationship['from'] . ' -> ' . $relationship['to'],
				'details' => $relationship['cardinality'] . ', ' . $relationship['storage'],
				'source'  => $result->sources['relationship'][ $key ],
			);
		}
		array_push( $items, ...self::section_items( $result ) );

		if ( ! $items ) {
			WP_CLI::log( 'No schema registered.' );
			return;
		}

		format_items( $assoc_args['format'] ?? 'table', $items, array( 'kind', 'key', 'label', 'details', 'source' ) );
	}

	/**
	 * Reports DB entries shadowed by file packages with the same key.
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
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$shadowed = Plugin::result()->shadowed;

		if ( ! $shadowed ) {
			WP_CLI::success( 'No shadowed DB entries.' );
			return;
		}

		WP_CLI::warning( sprintf( '%d DB entry(ies) in the %s option are shadowed by file packages and ignored.', count( $shadowed ), Loader::DB_OPTION ) );
		format_items( $assoc_args['format'] ?? 'table', $shadowed, array( 'kind', 'key', 'by' ) );
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

	/**
	 * `schema list` rows for the optional sections: listings, forms, automations and options pages.
	 *
	 * @param Result $result Loaded registry with sources.
	 * @return list<array<string, string>> Rows with kind, key, label, details and source.
	 */
	private static function section_items( Result $result ): array {
		$registry = $result->registry;
		$items    = array();

		foreach ( $registry->content_types() as $key => $type ) {
			$listing = $registry->listing( $key );
			if ( null !== $listing ) {
				$items[] = array(
					'kind'    => 'listing',
					'key'     => $key,
					'label'   => $type['labels']['plural'],
					'details' => sprintf( '%d filter(s)%s', count( $listing['filters'] ), $listing['search'] ? ', search' : '' ),
					'source'  => $result->sources['content_type'][ $key ],
				);
			}
		}
		foreach ( $registry->forms() as $key => $form ) {
			$items[] = array(
				'kind'    => 'form',
				'key'     => $key,
				'label'   => $form['label'],
				'details' => isset( $form['target'] )
					? sprintf( '%s %s (%s)', $form['target']['action'], $form['target']['content_type'], isset( $form['steps'] ) ? count( $form['steps'] ) . ' step(s)' : count( $form['fields'] ) . ' field(s)' )
					: sprintf( 'entry only (%s)', isset( $form['steps'] ) ? count( $form['steps'] ) . ' step(s)' : count( $form['fields'] ) . ' field(s)' ),
				'source'  => $result->sources['form'][ $key ],
			);
		}
		foreach ( $registry->automations() as $key => $automation ) {
			$trigger = $automation['trigger'];
			$items[] = array(
				'kind'    => 'automation',
				'key'     => $key,
				'label'   => $automation['label'],
				'details' => sprintf(
					'%s, %d step(s)%s',
					match ( $trigger['type'] ) {
						'event'    => 'on ' . $trigger['event'],
						'schedule' => 'every ' . $trigger['every'],
						default    => 'webhook',
					},
					count( $automation['steps'] ),
					$automation['enabled'] ? '' : ', disabled'
				),
				'source'  => $result->sources['automation'][ $key ],
			);
		}
		foreach ( $registry->options_pages() as $key => $page ) {
			$items[] = array(
				'kind'    => 'options_page',
				'key'     => $key,
				'label'   => $page['title'],
				'details' => sprintf( '%d field(s), %s', count( $page['fields'] ), $page['capability'] ),
				'source'  => $result->sources['options_page'][ $key ],
			);
		}

		return $items;
	}
}
