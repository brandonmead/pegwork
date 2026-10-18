<?php
/**
 * Plugin Name: Pegwork E2E forms
 * Description: Test-only helpers for the forms browser tests: registers tests/e2e/packages/forms, logs mail instead of sending it, and exposes admin-only REST routes to read mail and entries and to toggle the forms module. Not shipped.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper reading the module's own table.

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/forms' );
		}
	}
);

// Log every email to an option and report it sent; nothing leaves the test site.
add_filter(
	'pre_wp_mail',
	static function ( $result, $atts ) {
		$log   = get_option( 'pegwork_e2e_mail', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
			'headers' => $atts['headers'],
		);
		update_option( 'pegwork_e2e_mail', $log, false );
		return true;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );

		register_rest_route(
			'pegwork-e2e/v1',
			'/mail',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static fn() => rest_ensure_response( array_values( (array) get_option( 'pegwork_e2e_mail', array() ) ) ),
			)
		);

		register_rest_route(
			'pegwork-e2e/v1',
			'/entries',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;
					$table = $wpdb->prefix . 'pegwork_entries';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
						return rest_ensure_response( array() );
					}
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_key, status, email, post_id, field_values, meta FROM {$table} WHERE form_key = %s ORDER BY id DESC LIMIT 20", (string) $request['form'] ), ARRAY_A );
					foreach ( $rows as &$row ) {
						$row['values'] = json_decode( $row['field_values'], true );
						$row['meta']   = json_decode( $row['meta'], true );
						unset( $row['field_values'] );
					}
					return rest_ensure_response( $rows );
				},
			)
		);

		// Clears the mail log, rate-limit transients and the e2e forms' entries (which also resets the confirmation email cap).
		register_rest_route(
			'pegwork-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					delete_option( 'pegwork_e2e_mail' );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_pegwork_forms_rl_%', '_transient_timeout_pegwork_forms_rl_%' ) );
					wp_cache_flush();
					$table = $wpdb->prefix . 'pegwork_entries';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE form_key IN (%s, %s)", 'e2e_contact', 'e2e_project' ) );
					}
					return rest_ensure_response( true );
				},
			)
		);

		// Turns the forms module on or off (the pegwork_modules option, read on the next request).
		register_rest_route(
			'pegwork-e2e/v1',
			'/forms-module',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$enabled = array_values( array_diff( (array) get_option( 'pegwork_modules', array() ), array( 'forms' ) ) );
					if ( rest_sanitize_boolean( $request['enabled'] ) ) {
						$enabled[] = 'forms';
					}
					update_option( 'pegwork_modules', $enabled );
					return rest_ensure_response( $enabled );
				},
			)
		);
	}
);
