<?php
/**
 * Filters module: the pegwork/filters block (search and filter controls for Pegwork directories),
 * URL state, and URL constraints for the listings pipeline.
 *
 * Talks to listings only through hooks (pegwork_listing_constraints, pegwork_listing_pattern, block
 * context `pegwork/listing`), never its classes. Uses only Pegwork\Api and the global pegwork_*
 * functions (bin/check-module-boundary.sh).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PEGWORK_FILTERS_DIR' ) ) {
	define( 'PEGWORK_FILTERS_DIR', __DIR__ . '/' );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, 'PegworkFilters\\' ) ) {
				$file = PEGWORK_FILTERS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PegworkFilters\\' ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		}
	);
}

return new PegworkFilters\Module();
