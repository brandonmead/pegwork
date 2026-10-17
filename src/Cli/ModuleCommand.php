<?php
/**
 * WP-CLI module commands.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Cli;

use Pegwork\Modules\Loader;
use WP_CLI;

/**
 * Lists, enables and disables Pegwork modules.
 */
final class ModuleCommand {

	/**
	 * Lists the available modules and whether each is enabled.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, csv, yaml, count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public function list( array $args, array $assoc_args ): void {
		$enabled = Loader::enabled();
		$items   = array();

		foreach ( Loader::manifest() as $slug => $entry ) {
			$items[] = array(
				'slug'     => $slug,
				'label'    => $entry['label'],
				'status'   => in_array( $slug, $enabled, true ) ? 'enabled' : 'disabled',
				'requires' => implode( ', ', $entry['requires'] ),
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'slug', 'label', 'status', 'requires' ) );
	}

	/**
	 * Enables a module. Its required modules must already be enabled.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Module slug, as shown by `wp pegwork module list`.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function enable( array $args ): void {
		$result = Loader::enable( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( "Enabled module {$args[0]}." );
	}

	/**
	 * Disables a module.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Module slug.
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function disable( array $args ): void {
		$result = Loader::disable( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( "Disabled module {$args[0]}." );
	}
}
