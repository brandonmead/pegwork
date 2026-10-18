<?php
/**
 * Uninstall: runs each module's uninstall.php (module tables, such as automation run logs), then deletes
 * pegwork_* options. Content (posts, meta, terms) is never deleted.
 *
 * @package Pegwork
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( glob( __DIR__ . '/modules/*/uninstall.php' ) as $pegwork_module_uninstall ) {
	include $pegwork_module_uninstall;
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off prefix lookup at uninstall; no API lists options by prefix.
$pegwork_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'pegwork_' ) . '%'
	)
);

foreach ( $pegwork_options as $pegwork_option ) {
	delete_option( $pegwork_option );
}
