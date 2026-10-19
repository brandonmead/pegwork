<?php
/**
 * Plugin Name: Pegwork E2E webhooks
 * Description: Test-only helpers for the webhook browser tests: registers tests/e2e/packages/webhooks, answers outbound deliveries to receiver.e2e.test in process (verifying the signature as docs/automation.md describes, or failing while "down"), and exposes admin-only REST routes to reset (fixed test secret, cleared runs and rate buckets), switch the receiver down and read what it received. The module switch and the cron runner are in pegwork-e2e-automation.php. Not shipped.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper clearing the module's own tables.

const PEGWORK_E2E_HOOK_SECRET = 'e2e-webhook-secret-not-for-production-0123456789';

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/webhooks' );
		}
	}
);

// The test receiver: only for receiver.e2e.test, so no request leaves the site.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 'receiver.e2e.test' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}
		$headers  = (array) $args['headers'];
		$secrets  = (array) get_option( 'pegwork_automation_secrets', array() );
		$expected = 'sha256=' . hash_hmac( 'sha256', ( $headers['X-Pegwork-Timestamp'] ?? '' ) . '.' . $args['body'], (string) ( $secrets['e2e_hook'] ?? '' ) );
		$down     = (bool) get_option( 'pegwork_e2e_hook_down' );
		$log      = (array) get_option( 'pegwork_e2e_hook_received', array() );
		$log[]    = array(
			'delivery' => $headers['X-Pegwork-Delivery'] ?? '',
			'verified' => hash_equals( $expected, (string) ( $headers['X-Pegwork-Signature'] ?? '' ) ),
			'down'     => $down,
		);
		update_option( 'pegwork_e2e_hook_received', $log, false );

		if ( $down ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to receiver.e2e.test port 443' );
		}
		return array(
			'headers'  => array(),
			'body'     => 'ok',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );

		// Fixed test secret, no e2e_hook runs, empty rate buckets, receiver up and its log empty.
		register_rest_route(
			'pegwork-e2e/v1',
			'/webhooks/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$secrets             = (array) get_option( 'pegwork_automation_secrets', array() );
					$secrets['e2e_hook'] = PEGWORK_E2E_HOOK_SECRET;
					update_option( 'pegwork_automation_secrets', $secrets, false );
					delete_option( 'pegwork_e2e_hook_down' );
					delete_option( 'pegwork_e2e_hook_received' );
					foreach ( array( 'e2e_hook', '' ) as $bucket ) {
						delete_transient( 'pegwork_automation_hook_' . md5( $bucket ) );
					}
					$runs  = $wpdb->prefix . 'pegwork_runs';
					$steps = $wpdb->prefix . 'pegwork_run_steps';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs ) ) === $runs ) {
						$wpdb->query( $wpdb->prepare( "DELETE s FROM {$steps} s JOIN {$runs} r ON r.id = s.run_id WHERE r.automation = %s", 'e2e_hook' ) );
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE automation = %s", 'e2e_hook' ) );
					}
					return rest_ensure_response( true );
				},
			)
		);

		// Switches the test receiver down (transport error) or up.
		register_rest_route(
			'pegwork-e2e/v1',
			'/webhooks/down',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					update_option( 'pegwork_e2e_hook_down', rest_sanitize_boolean( $request['down'] ) ? 1 : 0 );
					return rest_ensure_response( true );
				},
			)
		);

		// What the test receiver got: delivery ID, whether the signature verified, whether it was down.
		register_rest_route(
			'pegwork-e2e/v1',
			'/webhooks/received',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static fn() => rest_ensure_response( array_values( (array) get_option( 'pegwork_e2e_hook_received', array() ) ) ),
			)
		);
	}
);
