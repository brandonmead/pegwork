<?php
/**
 * Automation module uninstall, included by Pegwork's uninstall.php: drops the run log tables and clears
 * the module's WP-Cron events. Options are removed by the core uninstall (pegwork_ prefix).
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'pegwork_run_steps', 'pegwork_runs' ) as $pegwork_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table names; logs are not content.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$pegwork_table}" );
}

wp_unschedule_hook( 'pegwork_automation_run' );
wp_unschedule_hook( 'pegwork_automation_maintenance' );
wp_unschedule_hook( 'pegwork_automation_schedule' );
wp_unschedule_hook( 'pegwork_automation_sweep' );
