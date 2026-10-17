<?php
/**
 * Module loader.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Modules;

use Pegwork\Api\Module;
use WP_Error;

/**
 * Boots the enabled modules listed in the manifest and manages the enabled list.
 */
final class Loader {

	/**
	 * Option holding the enabled module slugs.
	 */
	public const OPTION = 'pegwork_modules';

	/**
	 * Problems found by the last load(), shown as an admin notice.
	 *
	 * @var array<int, array{kind: string, slug: string, detail: string}>
	 */
	private static array $problems = array();

	/**
	 * The manifest: slug => label, description, requires, path. Filterable.
	 *
	 * @return array<string, array{label: string, description: string, requires: string[], path: string}>
	 */
	public static function manifest(): array {
		/**
		 * Filters the module manifest.
		 *
		 * @param array $manifest Slug => array( label, description, requires, path ).
		 */
		$raw = apply_filters( 'pegwork_module_manifest', require __DIR__ . '/manifest.php' );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$manifest = array();
		foreach ( $raw as $slug => $entry ) {
			if ( ! is_string( $slug ) || ! is_array( $entry ) || ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) ) {
				continue;
			}
			$manifest[ $slug ] = array(
				'label'       => isset( $entry['label'] ) ? (string) $entry['label'] : $slug,
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'requires'    => isset( $entry['requires'] ) ? array_values( array_filter( (array) $entry['requires'], 'is_string' ) ) : array(),
				'path'        => $entry['path'],
			);
		}
		return $manifest;
	}

	/**
	 * The enabled module slugs, as stored.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( $stored, 'is_string' ) ) );
	}

	/**
	 * Includes and boots every enabled module whose requirements are met.
	 *
	 * Disabled modules' files are never included. Unknown slugs and modules with missing
	 * requirements are skipped and reported in an admin notice.
	 *
	 * @return string[] Slugs of the modules that booted, in boot order.
	 */
	public static function load(): array {
		self::$problems = array();
		$manifest       = self::manifest();
		$pending        = array();

		foreach ( self::enabled() as $slug ) {
			if ( isset( $manifest[ $slug ] ) ) {
				$pending[ $slug ] = $manifest[ $slug ];
			} else {
				self::$problems[] = array(
					'kind'   => 'unknown',
					'slug'   => $slug,
					'detail' => '',
				);
			}
		}

		// Boot in dependency order: each pass loads the modules whose requirements already booted.
		$booted = array();
		do {
			$progress = false;
			foreach ( $pending as $slug => $entry ) {
				if ( array_diff( $entry['requires'], $booted ) ) {
					continue;
				}
				unset( $pending[ $slug ] );
				$progress = true;

				$module = self::include_module( $entry['path'] );
				if ( ! $module instanceof Module ) {
					self::$problems[] = array(
						'kind'   => 'invalid',
						'slug'   => $slug,
						'detail' => '',
					);
					continue;
				}
				$module->boot();
				$booted[] = $slug;
			}
		} while ( $progress && $pending );

		foreach ( $pending as $slug => $entry ) {
			self::$problems[] = array(
				'kind'   => 'requires',
				'slug'   => $slug,
				'detail' => implode( ', ', array_diff( $entry['requires'], $booted ) ),
			);
		}

		if ( self::$problems ) {
			add_action( 'admin_notices', array( self::class, 'print_notices' ) );
		}

		return $booted;
	}

	/**
	 * Enables a module. The slug must be in the manifest and its requirements already enabled.
	 *
	 * @param string $slug Module slug.
	 * @return true|WP_Error
	 */
	public static function enable( string $slug ) {
		$manifest = self::manifest();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return new WP_Error( 'pegwork_module_unknown', "Unknown module: {$slug}." );
		}

		$enabled = self::enabled();
		$missing = array_diff( $manifest[ $slug ]['requires'], $enabled );
		if ( $missing ) {
			return new WP_Error( 'pegwork_module_requires', "Module {$slug} requires these modules to be enabled first: " . implode( ', ', $missing ) . '.' );
		}

		if ( ! in_array( $slug, $enabled, true ) ) {
			$enabled[] = $slug;
			update_option( self::OPTION, $enabled );
		}
		return true;
	}

	/**
	 * Disables a module. Also removes slugs that are no longer in the manifest.
	 *
	 * @param string $slug Module slug.
	 * @return true|WP_Error
	 */
	public static function disable( string $slug ) {
		$enabled = self::enabled();
		if ( ! in_array( $slug, $enabled, true ) ) {
			return new WP_Error( 'pegwork_module_not_enabled', "Module {$slug} is not enabled." );
		}

		update_option( self::OPTION, array_values( array_diff( $enabled, array( $slug ) ) ) );
		return true;
	}

	/**
	 * Prints the problems found by the last load(). Admins only.
	 */
	public static function print_notices(): void {
		if ( ! self::$problems || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning">';
		foreach ( self::$problems as $problem ) {
			switch ( $problem['kind'] ) {
				case 'unknown':
					/* translators: %s: module slug */
					$message = sprintf( __( 'Pegwork: the enabled module "%s" does not exist and was ignored.', 'pegwork' ), $problem['slug'] );
					break;
				case 'requires':
					/* translators: 1: module slug, 2: comma-separated list of module slugs */
					$message = sprintf( __( 'Pegwork: the module "%1$s" was skipped because it requires: %2$s.', 'pegwork' ), $problem['slug'], $problem['detail'] );
					break;
				default:
					/* translators: %s: module slug */
					$message = sprintf( __( 'Pegwork: the module "%s" was skipped because its module.php did not return a Pegwork module.', 'pegwork' ), $problem['slug'] );
			}
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Includes a module's module.php in an isolated scope and returns what it returns.
	 *
	 * @param string $path Module directory.
	 * @return mixed The file's return value, or false when the file is missing.
	 */
	private static function include_module( string $path ) {
		$file = rtrim( $path, '/\\' ) . '/module.php';
		if ( ! is_readable( $file ) ) {
			return false;
		}
		return include $file;
	}
}
