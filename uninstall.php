<?php
/**
 * Uninstall, on every site of a multisite network: runs each module's uninstall.php (module tables, such as
 * automation run logs), drops the rate limit table, then deletes pegwork_* options and Pegwork transients.
 * Content (posts, meta, terms) is never deleted, nor are form entries and uploaded files (readme.txt).
 *
 * @package Pegwork
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$pegwork_uninstall_site = static function (): void {
	global $wpdb;

	foreach ( glob( __DIR__ . '/modules/*/uninstall.php' ) as $pegwork_module_uninstall ) {
		include $pegwork_module_uninstall;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Fixed table name; rate limit counters are not content.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pegwork_throttle" );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off prefix lookup at uninstall; no API lists options by prefix.
	$pegwork_options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'pegwork_' ) . '%',
			$wpdb->esc_like( '_transient_pegwork_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pegwork_' ) . '%'
		)
	);

	foreach ( $pegwork_options as $pegwork_option ) {
		delete_option( $pegwork_option );
	}
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pegwork_site ) {
		switch_to_blog( (int) $pegwork_site );
		$pegwork_uninstall_site();
		restore_current_blog();
	}
} else {
	$pegwork_uninstall_site();
}
