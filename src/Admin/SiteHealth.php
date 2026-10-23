<?php
/**
 * Site Health check.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

/**
 * Adds a Site Health test (Tools > Site Health) that warns when Pegwork's `vendor/` holds its development
 * dependencies (a `composer install` checkout rather than the release zip) on a site whose environment
 * type is not `local` or `development`. Those packages are for running tests and are not meant to be
 * reachable on a live site. Composer records whether dev dependencies were installed in
 * `vendor/composer/installed.php` (`root.dev`), which is read only when Site Health runs its tests.
 */
final class SiteHealth {

	/**
	 * Test ID.
	 */
	public const TEST = 'pegwork_dev_dependencies';

	/**
	 * Hooks the test. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_filter( 'site_status_tests', array( self::class, 'tests' ) ) ) {
			add_filter( 'site_status_tests', array( self::class, 'tests' ) );
		}
	}

	/**
	 * Adds the test. Hooked to site_status_tests.
	 *
	 * @param mixed $tests Tests by kind.
	 * @return mixed
	 */
	public static function tests( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct'][ self::TEST ] = array(
				'label' => __( 'Pegwork development dependencies', 'pegwork' ),
				'test'  => array( self::class, 'run' ),
			);
		}
		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		return self::result( self::dev_installed( PEGWORK_DIR . 'vendor/composer/installed.php' ), wp_get_environment_type() );
	}

	/**
	 * Whether a Composer `installed.php` says dev dependencies are installed.
	 *
	 * @param string $file Path to vendor/composer/installed.php.
	 */
	public static function dev_installed( string $file ): bool {
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$installed = include $file;
		return is_array( $installed ) && ! empty( $installed['root']['dev'] );
	}

	/**
	 * The Site Health result.
	 *
	 * @param bool   $dev         Whether dev dependencies are installed.
	 * @param string $environment wp_get_environment_type().
	 * @return array<string, mixed>
	 */
	public static function result( bool $dev, string $environment ): array {
		$warn = $dev && ! in_array( $environment, array( 'local', 'development' ), true );
		if ( $warn ) {
			$label = __( 'Pegwork is installed with its development dependencies', 'pegwork' );
		} elseif ( $dev ) {
			$label = __( 'Pegwork development dependencies are allowed on local sites', 'pegwork' );
		} else {
			$label = __( 'Pegwork is installed without development dependencies', 'pegwork' );
		}
		return array(
			'label'       => $label,
			'status'      => $warn ? 'critical' : 'good',
			'badge'       => array(
				'label' => __( 'Security', 'pegwork' ),
				'color' => $warn ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html(
				$warn
					? __( 'The plugin folder holds packages that are only for developing and testing Pegwork, and they should not be reachable on a live site. Install Pegwork from its release zip instead of a development checkout, or run "composer install --no-dev" in the plugin folder. Sites whose environment type is local or development are not warned.', 'pegwork' )
					: ( $dev
						? __( 'The plugin folder holds development-only packages, which is fine because this site is local or development. Do not publish this folder to a live site.', 'pegwork' )
						: __( 'The plugin folder holds no development-only packages.', 'pegwork' ) )
			) . '</p>',
			'actions'     => '',
			'test'        => self::TEST,
		);
	}
}
