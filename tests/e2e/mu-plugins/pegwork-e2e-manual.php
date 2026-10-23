<?php
/**
 * Plugin Name: Pegwork E2E manual trigger
 * Description: Test-only helpers for the manual trigger browser test: registers tests/e2e/packages/manual (two manual automations, one on the BarnCRM gift of pegwork-e2e-barn.php), and exposes admin-only REST routes to read their runs and to reset (their runs and the start-route rate buckets). Not shipped.
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper reading and clearing the module's own tables.

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/manual' );
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );
		$keys  = array( 'e2e_manual_thanks', 'e2e_manual_report' );

		// Runs of the two automations, oldest first: id, automation, record, status, user and source.
		register_rest_route(
			'pegwork-e2e/v1',
			'/manual/runs',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, automation, record_id, status, context FROM {$wpdb->prefix}pegwork_runs WHERE automation IN (%s, %s) ORDER BY id", 'e2e_manual_thanks', 'e2e_manual_report' ), ARRAY_A );
					return rest_ensure_response(
						array_map(
							static function ( $row ) {
								$context = json_decode( (string) $row['context'], true );
								return array(
									'id'         => (int) $row['id'],
									'automation' => $row['automation'],
									'record_id'  => null === $row['record_id'] ? null : (int) $row['record_id'],
									'status'     => $row['status'],
									'user_id'    => (int) ( $context['trigger']['user_id'] ?? 0 ),
									'source'     => (string) ( $context['trigger']['source'] ?? '' ),
								);
							},
							(array) $rows
						)
					);
				},
			)
		);

		// Deletes the two automations' runs and every rate bucket.
		register_rest_route(
			'pegwork-e2e/v1',
			'/manual/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () use ( $keys ) {
					global $wpdb;
					$runs  = $wpdb->prefix . 'pegwork_runs';
					$steps = $wpdb->prefix . 'pegwork_run_steps';
					if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs ) ) === $runs ) {
						foreach ( $keys as $key ) {
							$wpdb->query( $wpdb->prepare( "DELETE s FROM {$steps} s JOIN {$runs} r ON r.id = s.run_id WHERE r.automation = %s", $key ) );
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE automation = %s", $key ) );
						}
					}
					$wpdb->query( "DELETE FROM {$wpdb->prefix}pegwork_throttle" );
					return rest_ensure_response( true );
				},
			)
		);
	}
);
