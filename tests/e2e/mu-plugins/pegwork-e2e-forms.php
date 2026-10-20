<?php
/**
 * Plugin Name: Pegwork E2E forms
 * Description: Test-only helpers for the forms browser tests: registers tests/e2e/packages/forms, logs mail instead of sending it, defines Cloudflare's published Turnstile test keys per mode, and exposes admin-only REST routes to read mail and entries, to toggle the forms module and to switch the Turnstile mode. Not shipped.
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

/*
 * Turnstile keys for this request, from option pegwork_e2e_turnstile (set by POST pegwork-e2e/v1/turnstile).
 * The keys are Cloudflare's published test keys (https://developers.cloudflare.com/turnstile/troubleshooting/testing/),
 * not secrets. Modes: pass (default; site key always passes, secret always passes), fail (secret always
 * fails), block (site key always blocks, so the widget gives no token), offline (pass keys, siteverify
 * answers with a network error), none (no keys). A site whose wp-config defines the constants keeps them.
 */
$pegwork_e2e_turnstile = (string) get_option( 'pegwork_e2e_turnstile', 'pass' );
if ( 'none' !== $pegwork_e2e_turnstile && ! defined( 'PEGWORK_TURNSTILE_SITE_KEY' ) && ! defined( 'PEGWORK_TURNSTILE_SECRET_KEY' ) ) {
	define( 'PEGWORK_TURNSTILE_SITE_KEY', 'block' === $pegwork_e2e_turnstile ? '2x00000000000000000000AB' : '1x00000000000000000000AA' );
	define( 'PEGWORK_TURNSTILE_SECRET_KEY', 'fail' === $pegwork_e2e_turnstile ? '2x0000000000000000000000000000000AA' : '1x0000000000000000000000000000000AA' );
}
if ( 'offline' === $pegwork_e2e_turnstile ) {
	add_filter(
		'pre_http_request',
		static fn( $pre, $args, $url ) => 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $url ? new WP_Error( 'http_request_failed', 'E2E: Cloudflare unreachable' ) : $pre,
		10,
		3
	);
}
unset( $pegwork_e2e_turnstile );

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
						// Upload and Turnstile entries go through the module, so their files go too.
						$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE form_key IN (%s, %s)", 'e2e_upload', 'e2e_turnstile' ) );
						if ( $ids && class_exists( 'PegworkForms\Entries' ) ) {
							PegworkForms\Entries::delete( array_map( 'intval', $ids ) );
						}
					}
					delete_option( 'pegwork_e2e_turnstile' );
					return rest_ensure_response( true );
				},
			)
		);

		// Switches the Turnstile test keys (read at the start of the next request; see the top of this file).
		register_rest_route(
			'pegwork-e2e/v1',
			'/turnstile',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$mode = (string) $request['mode'];
					if ( ! in_array( $mode, array( 'pass', 'fail', 'block', 'offline', 'none' ), true ) ) {
						return new WP_Error( 'pegwork_e2e_mode', 'Unknown mode.', array( 'status' => 400 ) );
					}
					update_option( 'pegwork_e2e_turnstile', $mode, false );
					return rest_ensure_response( $mode );
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
