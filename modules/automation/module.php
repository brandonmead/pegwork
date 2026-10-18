<?php
/**
 * Automation module: runs the registry's automations (event triggers and steps) in the background.
 *
 * Uses only Pegwork\Api and the global pegwork_* functions (bin/check-module-boundary.sh).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'PEGWORK_AUTOMATION_DIR' ) ) {
	define( 'PEGWORK_AUTOMATION_DIR', __DIR__ . '/' );

	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( str_starts_with( $class_name, 'PegworkAutomation\\' ) ) {
				$file = PEGWORK_AUTOMATION_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'PegworkAutomation\\' ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		}
	);
}

return new PegworkAutomation\Module();
