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
	 * Enables modules, in the order given. Each module's required modules must be enabled already or
	 * earlier in the list.
	 *
	 * ## OPTIONS
	 *
	 * <slug>...
	 * : One or more module slugs, as shown by `wp pegwork module list`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork module enable listings filters
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function enable( array $args ): void {
		self::report( self::toggle( 'enable', $args ) );
	}

	/**
	 * Disables modules, in the order given (dependents first).
	 *
	 * ## OPTIONS
	 *
	 * <slug>...
	 * : One or more module slugs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pegwork module disable filters listings
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function disable( array $args ): void {
		self::report( self::toggle( 'disable', $args ) );
	}

	/**
	 * Enables or disables each slug in turn with the Loader's rules. A failure does not stop the rest.
	 *
	 * @param string   $action `enable` or `disable`.
	 * @param string[] $slugs  Module slugs.
	 * @return list<array{slug: string, ok: bool, message: string}> One result per slug, in order.
	 */
	public static function toggle( string $action, array $slugs ): array {
		$results = array();
		foreach ( $slugs as $slug ) {
			$slug   = (string) $slug;
			$result = 'disable' === $action ? Loader::disable( $slug ) : Loader::enable( $slug );
			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'slug'    => $slug,
					'ok'      => false,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$results[] = array(
				'slug'    => $slug,
				'ok'      => true,
				'message' => 'disable' === $action ? "Disabled module {$slug}." : "Enabled module {$slug}.",
			);
		}
		return $results;
	}

	/**
	 * Prints one line per slug and exits 1 when any slug failed.
	 *
	 * @param list<array{slug: string, ok: bool, message: string}> $results Results of toggle().
	 */
	private static function report( array $results ): void {
		$failed = 0;
		foreach ( $results as $result ) {
			if ( $result['ok'] ) {
				WP_CLI::success( $result['message'] );
			} else {
				++$failed;
				WP_CLI::warning( "{$result['slug']}: {$result['message']}" );
			}
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d of %d modules failed.', $failed, count( $results ) ) );
		}
	}
}
