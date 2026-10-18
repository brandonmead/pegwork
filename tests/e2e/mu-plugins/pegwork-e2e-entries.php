<?php
/**
 * Plugin Name: Pegwork E2E entries
 * Description: Test-only helper for the entries admin browser tests: an admin-only REST route that stores entries for the e2e_contact form through the forms module's own storage. Not shipped.
 *
 * @package Pegwork
 */

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'pegwork-e2e/v1',
			'/entries/seed',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'PegworkForms\Entries' ) ) {
						return new WP_Error( 'pegwork_e2e_forms_off', 'Enable the forms module first.', array( 'status' => 409 ) );
					}
					$ids = array();
					foreach ( (array) $request['entries'] as $i => $entry ) {
						$ids[] = PegworkForms\Entries::insert(
							array(
								'form_key'    => 'e2e_contact',
								'status'      => (string) ( $entry['status'] ?? 'received' ),
								'email'       => strtolower( (string) ( $entry['email'] ?? '' ) ),
								'values'      => (array) ( $entry['values'] ?? array() ),
								'meta'        => (array) ( $entry['meta'] ?? array() ),
								'fingerprint' => hash( 'sha256', 'e2e-seed-' . microtime() . '-' . $i ),
								'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 60 * ( count( (array) $request['entries'] ) - $i ) ),
							)
						);
					}
					return rest_ensure_response( $ids );
				},
			)
		);
	}
);
