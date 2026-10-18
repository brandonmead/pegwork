<?php
/**
 * Forms module: the pegwork/form block, submission handling, entries table and notifications.
 *
 * Uses only Pegwork\Api and the global pegwork_* functions (bin/check-module-boundary.sh).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PEGWORK_FORMS_DIR' ) ) {
	define( 'PEGWORK_FORMS_DIR', __DIR__ . '/' );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, 'PegworkForms\\' ) ) {
				$file = PEGWORK_FORMS_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PegworkForms\\' ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		}
	);
}

return new PegworkForms\Module();
