<?php
/**
 * Plugin Name: Pegwork E2E automation
 * Description: Test-only helpers for the Automations screen browser tests: registers tests/e2e/packages/automation, can make wp_mail() fail on demand, and exposes admin-only REST routes to toggle the automation module, run due automation wake-ups in process, and reset. Not shipped.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper clearing the module's own tables.

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/automation' );
		}
	}
);

// While the flag is set, every email fails (a retryable mail_failed); otherwise nothing is sent.
add_filter(
	'pre_wp_mail',
	static function ( $result ) {
		if ( get_option( 'pegwork_e2e_mail_fail' ) ) {
			return false;
		}
		return null === $result ? true : $result;
	},
	20
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );

		// Turns the automation module on or off (the pegwork_modules option, read on the next request).
		register_rest_route(
			'pegwork-e2e/v1',
			'/automation-module',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$enabled = array_values( array_diff( (array) get_option( 'pegwork_modules', array() ), array( 'automation' ) ) );
					if ( rest_sanitize_boolean( $request['enabled'] ) ) {
						$enabled[] = 'automation';
					}
					update_option( 'pegwork_modules', $enabled );
					return rest_ensure_response( $enabled );
				},
			)
		);

		// Switches the mail failure flag on or off.
		register_rest_route(
			'pegwork-e2e/v1',
			'/automation/mail-fail',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					update_option( 'pegwork_e2e_mail_fail', rest_sanitize_boolean( $request['fail'] ) ? 1 : 0 );
					return rest_ensure_response( true );
				},
			)
		);

		// Runs the due automation wake-ups in this request, as wp-cron.php would (loopback cron is unreliable in containers).
		register_rest_route(
			'pegwork-e2e/v1',
			'/automation/cron',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					$fired = 0;
					foreach ( wp_get_ready_cron_jobs() as $timestamp => $hooks ) {
						foreach ( array( 'pegwork_automation_run', 'pegwork_automation_sweep' ) as $hook ) {
							foreach ( $hooks[ $hook ] ?? array() as $event ) {
								wp_unschedule_event( $timestamp, $hook, $event['args'] );
								do_action_ref_array( $hook, $event['args'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The module's own hooks.
								++$fired;
							}
						}
					}
					return rest_ensure_response( $fired );
				},
			)
		);

		// Deletes the e2e automations' runs and overrides, and clears the mail flag.
		register_rest_route(
			'pegwork-e2e/v1',
			'/automation/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					delete_option( 'pegwork_e2e_mail_fail' );
					$overrides = get_option( 'pegwork_automation_overrides', array() );
					if ( is_array( $overrides ) ) {
						unset( $overrides['e2e_digest'] );
						update_option( 'pegwork_automation_overrides', $overrides );
					}
					$runs  = $wpdb->prefix . 'pegwork_runs';
					$steps = $wpdb->prefix . 'pegwork_run_steps';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs ) ) === $runs ) {
						$wpdb->query( $wpdb->prepare( "DELETE s FROM {$steps} s JOIN {$runs} r ON r.id = s.run_id WHERE r.automation = %s", 'e2e_digest' ) );
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE automation = %s", 'e2e_digest' ) );
					}
					wp_clear_scheduled_hook( 'pegwork_automation_schedule', array( 'e2e_digest' ) );
					return rest_ensure_response( true );
				},
			)
		);
	}
);
