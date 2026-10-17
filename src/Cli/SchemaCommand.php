<?php
/**
 * WP-CLI: wp pegwork schema.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Cli;

use Pegwork\Plugin;
use Pegwork\Registry\Loader;
use WP_CLI;

use function WP_CLI\Utils\format_items;

/**
 * Validates and lists Pegwork schema packages.
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
}
