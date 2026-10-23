<?php
/**
 * Plugin Name: Pegwork E2E BarnCRM
 * Description: Test-only helpers for the BarnCRM flows browser test: registers tests/e2e/packages/barn (types, form and options page, no automations), and exposes admin-only REST routes to create a sample gift, to compare a saved site automation with its fixture in canonical form, and to reset (the BarnCRM site automations, their runs and the sample gifts). Not shipped.
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper clearing the module's own tables.

add_action(
	'pegwork_register',
	static function ( $builder ) {
		if ( defined( 'PEGWORK_DIR' ) ) {
			$builder->add_package( PEGWORK_DIR . 'tests/e2e/packages/barn' );
		}
	}
);

/**
 * The automation keys the spec builds (the files in tests/fixtures/valid/barn/automations).
 *
 * @return string[]
 */
function pegwork_e2e_barn_keys() {
	return array( 'barn_gift_thanks', 'barn_gift_followup', 'barn_volunteer_intake' );
}

/**
 * Canonical JSON of an automation without `enabled`, as the round-trip test compares them.
 *
 * @param array $definition Definition.
 * @return string
 */
function pegwork_e2e_barn_canonical( array $definition ) {
	$canonical = \Pegwork\Registry\Normalizer::normalize( 'automation', $definition );
	unset( $canonical['enabled'] );
	return (string) wp_json_encode( $canonical, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );

		// Creates a private sample gift (no donor) with the given amount; returns its ID and title.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/gift',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$amount = (int) $request['amount'];
					$title  = sprintf( 'E2E gift of %d', $amount );
					$id     = wp_insert_post(
						array(
							'post_type'   => 'barn_gift',
							'post_status' => 'private',
							'post_title'  => $title,
							'meta_input'  => array(
								'_barn_gift_amount'      => (string) $amount,
								'_barn_gift_received_on' => wp_date( 'Y-m-d' ),
								'_barn_gift_donor_name'  => 'E2E Donor',
								'_barn_gift_donor_email' => 'donor@example.org',
							),
						),
						true
					);
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					return rest_ensure_response(
						array(
							'id'    => $id,
							'title' => $title,
						)
					);
				},
			)
		);

		// The saved site automation and its fixture, each as canonical JSON without `enabled`.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/compare',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'args'                => array( 'key' => array( 'required' => true ) ),
				'callback'            => static function ( WP_REST_Request $request ) {
					$key = sanitize_key( (string) $request->get_param( 'key' ) );
					if ( ! in_array( $key, pegwork_e2e_barn_keys(), true ) || ! defined( 'PEGWORK_DIR' ) ) {
						return new WP_Error( 'pegwork_e2e_barn_key', 'Unknown BarnCRM automation.', array( 'status' => 404 ) );
					}
					$saved = pegwork_registry()->automation( $key );
					$file  = PEGWORK_DIR . 'tests/fixtures/valid/barn/automations/' . $key . '.json';
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
					$fixture = json_decode( (string) file_get_contents( $file ), true );
					return rest_ensure_response(
						array(
							'source'  => pegwork_registry()->automation_source( $key ),
							'enabled' => is_array( $saved ) ? ( $saved['enabled'] ?? null ) : null,
							'saved'   => is_array( $saved ) ? pegwork_e2e_barn_canonical( $saved ) : null,
							'fixture' => pegwork_e2e_barn_canonical( $fixture ),
						)
					);
				},
			)
		);

		// Deletes the BarnCRM site automations (definition and post), unsaved automation posts, their runs and the sample gifts.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$keys  = pegwork_e2e_barn_keys();
					$posts = get_posts(
						array(
							'post_type'      => 'pegwork_automation',
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					foreach ( $posts as $post_id ) {
						$key = (string) get_post_meta( (int) $post_id, '_pegwork_automation_key', true );
						if ( '' === $key || ( in_array( $key, $keys, true ) && 'db' === pegwork_registry()->automation_source( $key ) ) ) {
							wp_delete_post( (int) $post_id, true );
						}
					}
					$runs  = $wpdb->prefix . 'pegwork_runs';
					$steps = $wpdb->prefix . 'pegwork_run_steps';
					$table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs ) ) === $runs;
					foreach ( $keys as $key ) {
						if ( 'db' === pegwork_registry()->automation_source( $key ) ) {
							pegwork_delete_automation( $key );
						}
						if ( $table ) {
							$wpdb->query( $wpdb->prepare( "DELETE s FROM {$steps} s JOIN {$runs} r ON r.id = s.run_id WHERE r.automation = %s", $key ) );
							$wpdb->query( $wpdb->prepare( "DELETE FROM {$runs} WHERE automation = %s", $key ) );
						}
					}
					$gifts = get_posts(
						array(
							'post_type'      => array( 'barn_gift', 'barn_task' ),
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					foreach ( $gifts as $post_id ) {
						wp_delete_post( (int) $post_id, true );
					}
					return rest_ensure_response( true );
				},
			)
		);
	}
);
