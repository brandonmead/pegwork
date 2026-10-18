<?php
/**
 * Listings module: the pegwork/field binding source and the pegwork/conditional block.
 *
 * Uses only Pegwork\Api and the global pegwork_* functions (bin/check-module-boundary.sh).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PEGWORK_LISTINGS_DIR' ) ) {
	define( 'PEGWORK_LISTINGS_DIR', __DIR__ . '/' );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, 'PegworkListings\\' ) ) {
				$file = PEGWORK_LISTINGS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PegworkListings\\' ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		}
	);
}

return new PegworkListings\Module();
