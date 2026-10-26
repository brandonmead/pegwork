<?php
/**
 * Plugin Name: Pegwork E2E BarnCRM
 * Description: Test-only helpers for the BarnCRM browser tests: registers tests/e2e/packages/barn (types, relationships, form and options page, no automations), and exposes admin-only REST routes to create a sample gift (with a linked donor), task or volunteer and an editor user, to update a task, to save an Automation v4 fixture automation as an enabled site automation, to compare a saved site automation with its fixture in canonical form, and to reset (the BarnCRM site automations, their runs and the sample records). Not shipped.
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
 * The automation keys the specs build (each has its file in tests/fixtures/valid/barn/automations).
 *
 * @return string[]
 */
function pegwork_e2e_barn_keys() {
	return array( 'barn_gift_thanks', 'barn_volunteer_intake', 'barn_lifetime_milestone', 'barn_tasks_digest' );
}

/**
 * Automation v4 keys the specs save from tests/fixtures/valid/v4/automations (reset deletes them too).
 *
 * @return string[]
 */
function pegwork_e2e_barn_v4_keys() {
	// Callers before the automations they start, so reset deletes them in an order the registry accepts.
	return array( 'barn_task_due_reminder', 'barn_volunteer_review', 'barn_volunteer_retention', 'barn_gift_receipt_start', 'barn_gift_receipt' );
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

		// Creates a published sample task (body: name, due_on, assignee_email, done); returns its ID.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/task',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$values = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'name', 'due_on', 'assignee_email', 'done' ) ) );
					$id     = \Pegwork\Api\Records::create( 'barn_task', $values, 'publish' );
					return is_wp_error( $id ) ? $id : rest_ensure_response( array( 'id' => $id ) );
				},
			)
		);

		// Creates a published sample volunteer (body: name, email, interest); returns its ID and title.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/volunteer',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$values = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'name', 'email', 'interest' ) ) );
					$id     = \Pegwork\Api\Records::create( 'barn_volunteer', $values, 'publish' );
					return is_wp_error( $id ) ? $id : rest_ensure_response(
						array(
							'id'    => $id,
							'title' => get_the_title( $id ),
						)
					);
				},
			)
		);

		// For the role recipient tests: makes sure the editor user e2e_barn_editor exists and sets the volunteer
		// manager address (barn_settings); returns the user's ID.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/editor',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					$user = get_user_by( 'login', 'e2e_barn_editor' );
					$id   = $user ? $user->ID : wp_insert_user(
						array(
							'user_login' => 'e2e_barn_editor',
							'user_email' => 'e2e-editor@example.org',
							'user_pass'  => wp_generate_password(),
							'role'       => 'editor',
						)
					);
					$settings                      = (array) get_option( 'barn_settings', array() );
					$settings['volunteer_manager'] = 'manager@example.org';
					update_option( 'barn_settings', $settings );
					return is_wp_error( $id ) ? $id : rest_ensure_response( array( 'id' => $id ) );
				},
			)
		);

		// Updates a sample task (body: id, values with name, due_on, done); emits record.updated.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/task-update',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$values = array_intersect_key( (array) $request['values'], array_flip( array( 'name', 'due_on', 'done' ) ) );
					$done   = \Pegwork\Api\Records::update( (int) $request['id'], $values );
					return is_wp_error( $done ) ? $done : rest_ensure_response( true );
				},
			)
		);

		// Saves a v4 fixture automation (body: key) as an enabled site automation.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/v4-automation',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$key = sanitize_key( (string) $request['key'] );
					if ( ! in_array( $key, pegwork_e2e_barn_v4_keys(), true ) || ! defined( 'PEGWORK_DIR' ) ) {
						return new WP_Error( 'pegwork_e2e_barn_key', 'Unknown v4 automation.', array( 'status' => 404 ) );
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
					$definition = json_decode( (string) file_get_contents( PEGWORK_DIR . 'tests/fixtures/valid/v4/automations/' . $key . '.json' ), true );
					$saved      = pegwork_save_automation( array( 'enabled' => true ) + (array) $definition );
					return is_wp_error( $saved ) ? $saved : rest_ensure_response( true );
				},
			)
		);

		// Creates a private sample gift with the given amount, linked by gift_donor to a new donor contact; returns its ID and title.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/gift',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$amount = (int) $request['amount'];
					$title  = sprintf( 'E2E gift of %d', $amount );
					$donor  = wp_insert_post(
						array(
							'post_type'   => 'barn_contact',
							'post_status' => 'private',
							'post_title'  => 'E2E Donor',
							'meta_input'  => array( '_barn_contact_email' => 'donor@example.org' ),
						),
						true
					);
					if ( is_wp_error( $donor ) ) {
						return $donor;
					}
					$id = wp_insert_post(
						array(
							'post_type'   => 'barn_gift',
							'post_status' => 'private',
							'post_title'  => $title,
							'meta_input'  => array(
								'_barn_gift_amount'      => (string) $amount,
								'_barn_gift_received_on' => wp_date( 'Y-m-d' ),
							),
						),
						true
					);
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					$linked = \Pegwork\Api\Records::relate( 'gift_donor', (int) $id, (int) $donor );
					if ( is_wp_error( $linked ) ) {
						return $linked;
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
					$v4  = in_array( $key, pegwork_e2e_barn_v4_keys(), true );
					if ( ( ! $v4 && ! in_array( $key, pegwork_e2e_barn_keys(), true ) ) || ! defined( 'PEGWORK_DIR' ) ) {
						return new WP_Error( 'pegwork_e2e_barn_key', 'Unknown BarnCRM automation.', array( 'status' => 404 ) );
					}
					$saved = pegwork_registry()->automation( $key );
					// The BarnCRM proof fixture's file when it has one (the v4 worked examples are copied there byte for byte).
					$file = PEGWORK_DIR . 'tests/fixtures/valid/barn/automations/' . $key . '.json';
					if ( $v4 && ! file_exists( $file ) ) {
						$file = PEGWORK_DIR . 'tests/fixtures/valid/v4/automations/' . $key . '.json';
					}
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

		// Deletes the BarnCRM site automations (definition and post), unsaved automation posts, their runs and the sample records.
		register_rest_route(
			'pegwork-e2e/v1',
			'/barn/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					global $wpdb;
					$keys  = array_merge( pegwork_e2e_barn_keys(), pegwork_e2e_barn_v4_keys() );
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
							'post_type'      => array( 'barn_gift', 'barn_task', 'barn_contact', 'barn_volunteer' ),
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
						)
					);
					foreach ( $gifts as $post_id ) {
						wp_delete_post( (int) $post_id, true );
					}
					$editor = get_user_by( 'login', 'e2e_barn_editor' );
					if ( $editor ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
						wp_delete_user( $editor->ID );
					}
					$settings = get_option( 'barn_settings' );
					if ( is_array( $settings ) && isset( $settings['volunteer_manager'] ) ) {
						unset( $settings['volunteer_manager'] );
						update_option( 'barn_settings', $settings );
					}
					return rest_ensure_response( true );
				},
			)
		);
	}
);
