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
	 * Modules included by the last load() and waiting for boot(), in dependency order.
	 *
	 * @var array<string, Module>
	 */
	private static array $modules = array();

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
	 * Includes every enabled module whose requirements are met and collects its Module object.
	 * Boot happens later, on pegwork_loaded (see boot()).
	 *
	 * Disabled modules' files are never included. Unknown slugs and modules with missing
	 * requirements are skipped and reported in an admin notice.
	 *
	 * @return string[] Slugs of the modules collected for boot, in boot order.
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

		// Resolve in dependency order: each pass includes the modules whose requirements already resolved.
		self::$modules = array();
		do {
			$progress = false;
			foreach ( $pending as $slug => $entry ) {
				if ( array_diff( $entry['requires'], array_keys( self::$modules ) ) ) {
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
				self::$modules[ $slug ] = $module;
			}
		} while ( $progress && $pending );

		foreach ( $pending as $slug => $entry ) {
			self::$problems[] = array(
				'kind'   => 'requires',
				'slug'   => $slug,
				'detail' => implode( ', ', array_diff( $entry['requires'], array_keys( self::$modules ) ) ),
			);
		}

		if ( self::$problems ) {
			add_action( 'admin_notices', array( self::class, 'print_notices' ) );
		}

		// Modules need the registry, which exists once pegwork_loaded fires.
		add_action( 'pegwork_loaded', array( self::class, 'boot' ) );

		return array_keys( self::$modules );
	}

	/**
	 * Boots the modules collected by load(), in dependency order. Hooked to pegwork_loaded.
	 *
	 * @return string[] Slugs of the modules that booted.
	 */
	public static function boot(): array {
		$modules       = self::$modules;
		self::$modules = array();

		foreach ( $modules as $module ) {
			$module->boot();
		}
		return array_keys( $modules );
	}

	/**
	 * Enables a module. The slug must be in the manifest and its requirements already enabled.
	 *
	 * @param string $slug Module slug.
	 * @return true|WP_Error
	 */
	public static function enable( string $slug ) {
		if ( ! isset( self::manifest()[ $slug ] ) ) {
			/* translators: %s: module slug. */
			return new WP_Error( 'pegwork_module_unknown', sprintf( __( 'Unknown module: %s.', 'pegwork' ), $slug ) );
		}

		$missing = self::missing_requirements( $slug );
		if ( $missing ) {
			return new WP_Error(
				'pegwork_module_requires',
				/* translators: 1: module slug, 2: comma-separated module slugs. */
				sprintf( __( 'Module %1$s requires these modules to be enabled first: %2$s.', 'pegwork' ), $slug, implode( ', ', $missing ) )
			);
		}

		$enabled = self::enabled();
		if ( ! in_array( $slug, $enabled, true ) ) {
			$enabled[] = $slug;
			update_option( self::OPTION, $enabled );
		}
		return true;
	}

	/**
	 * Disables a module. Rejected while an enabled module requires it.
	 *
	 * @param string $slug Module slug.
	 * @return true|WP_Error
	 */
	public static function disable( string $slug ) {
		$enabled = self::enabled();
		if ( ! in_array( $slug, $enabled, true ) ) {
			/* translators: %s: module slug. */
			return new WP_Error( 'pegwork_module_not_enabled', sprintf( __( 'Module %s is not enabled.', 'pegwork' ), $slug ) );
		}

		$dependents = self::dependents( $slug );
		if ( $dependents ) {
			return new WP_Error(
				'pegwork_module_required_by',
				/* translators: 1: module slug, 2: comma-separated module slugs. */
				sprintf( __( 'Module %1$s is required by these enabled modules, so disable them first: %2$s.', 'pegwork' ), $slug, implode( ', ', $dependents ) )
			);
		}

		update_option( self::OPTION, array_values( array_diff( $enabled, array( $slug ) ) ) );
		return true;
	}

	/**
	 * Requirements of a module that are not enabled.
	 *
	 * @param string $slug Module slug.
	 * @return string[] Required slugs that are not enabled, in manifest order. Empty for an unknown slug.
	 */
	public static function missing_requirements( string $slug ): array {
		$manifest = self::manifest();
		if ( ! isset( $manifest[ $slug ] ) ) {
			return array();
		}
		return array_values( array_diff( $manifest[ $slug ]['requires'], self::enabled() ) );
	}

	/**
	 * Enabled modules that require a module.
	 *
	 * @param string $slug Module slug.
	 * @return string[] Slugs of the enabled modules whose manifest entry requires $slug.
	 */
	public static function dependents( string $slug ): array {
		$manifest   = self::manifest();
		$dependents = array();
		foreach ( self::enabled() as $enabled ) {
			if ( $enabled !== $slug && in_array( $slug, $manifest[ $enabled ]['requires'] ?? array(), true ) ) {
				$dependents[] = $enabled;
			}
		}
		return $dependents;
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
