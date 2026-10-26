<?php
/**
 * Plugin Name: Pegwork E2E start another automation
 * Description: Test-only helpers for the start_automation browser test: registers tests/e2e/packages/start (a manual automation that starts another), and exposes an admin-only REST route that deletes their runs. Not shipped.
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper clearing the module's own tables.

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/start' );
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		// Deletes the runs of the two automations.
		register_rest_route(
			'pegwork-e2e/v1',
			'/start/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => static function () {
					global $wpdb;
					$runs  = $wpdb->prefix . 'pegwork_runs';
					$steps = $wpdb->prefix . 'pegwork_run_steps';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs ) ) === $runs ) {
						foreach ( array( 'e2e_start_parent', 'e2e_start_note' ) as $key ) {
							$wpdb->query( $wpdb->prepare( "DELETE s FROM {$steps} s JOIN {$runs} r ON r.id = s.run_id WHERE r.automation = %s", $key ) );
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE automation = %s", $key ) );
						}
					}
					return rest_ensure_response( true );
				},
			)
		);
	}
);
