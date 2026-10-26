<?php
/**
 * Plugin Name:       Pegwork
 * Description:       Application builder for WordPress: content types, fields and taxonomies defined in versioned JSON, on one schema registry.
 * Version:           0.4.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Brandon Mead
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pegwork
 *
 * @package Pegwork
 */

// This file must parse on old PHP so the version guard can run: no typed or 8.x-only syntax here.

defined( 'ABSPATH' ) || exit;

define( 'PEGWORK_VERSION', '0.4.0' );
define( 'PEGWORK_FILE', __FILE__ );
define( 'PEGWORK_DIR', plugin_dir_path( __FILE__ ) );

if ( ! function_exists( 'pegwork_unmet_requirements' ) ) {
	/**
	 * Lists the minimum versions the environment does not meet.
	 *
	 * @param string $php_version PHP version to check.
	 * @param string $wp_version  WordPress version to check.
	 * @return array<string, string> Unmet minimums keyed by 'php' or 'wp'. Empty when all are met.
	 */
	function pegwork_unmet_requirements( $php_version, $wp_version ) {
		$minimums = array(
			'php' => '8.2',
			'wp'  => '6.7',
		);
		$current  = array(
			'php' => $php_version,
			'wp'  => $wp_version,
		);
		$unmet    = array();

		foreach ( $minimums as $key => $minimum ) {
			if ( version_compare( $current[ $key ], $minimum, '<' ) ) {
				$unmet[ $key ] = $minimum;
			}
		}

		return $unmet;
	}
}

if ( ! function_exists( 'pegwork_requirements_notice' ) ) {
	/**
	 * Prints an admin notice naming the unmet minimum versions.
	 */
	function pegwork_requirements_notice() {
		$unmet = pegwork_unmet_requirements( PHP_VERSION, get_bloginfo( 'version' ) );

		if ( isset( $unmet['php'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Pegwork requires PHP %1$s or later. This site runs PHP %2$s, so Pegwork is not loaded.', 'pegwork' ),
						$unmet['php'],
						PHP_VERSION
					)
				)
			);
		}

		if ( isset( $unmet['wp'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required WordPress version, 2: current WordPress version. */
						__( 'Pegwork requires WordPress %1$s or later. This site runs WordPress %2$s, so Pegwork is not loaded.', 'pegwork' ),
						$unmet['wp'],
						get_bloginfo( 'version' )
					)
				)
			);
		}
	}
}

if ( pegwork_unmet_requirements( PHP_VERSION, get_bloginfo( 'version' ) ) ) {
	add_action( 'admin_notices', 'pegwork_requirements_notice' );
	return;
}

require_once PEGWORK_DIR . 'vendor/autoload.php';
// Not a Composer `files` autoload: it guards against direct access, and the test bootstrap loads the
// autoloader before WordPress defines ABSPATH.
require_once PEGWORK_DIR . 'src/Api/constants.php';
require_once PEGWORK_DIR . 'includes/functions.php';

Pegwork\Plugin::boot();
