<?php
/**
 * Plugin Name: Pegwork E2E signed link helpers
 * Description: Test-only helpers for the signed link browser tests: admin-only REST routes to create an SI for Good Project, read it back (private fields included) and mint a link with the same code as `wp pegwork automation link`. Raises the link rate limit so a spec run never trips it. The module switch is in pegwork-e2e-automation.php. Not shipped.
 *
 * @package Pegwork
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pegwork_automation_link_rate_limit', static fn() => 1000 );

add_action(
	'rest_api_init',
	static function () {
		$admin = static fn() => current_user_can( 'manage_options' );

		// Creates a published, valid Project (status Active, Last verified 13 months ago).
		register_rest_route(
			'pegwork-e2e/v1',
			'/project',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = Pegwork\Api\Records::create(
						'sifg_project',
						array(
							'name'                => (string) $request->get_param( 'name' ),
							'description'         => str_repeat( 'Neighbours share tools, seeds and time to grow food together. ', 6 ),
							'contact_email'       => 'owner@example.org',
							'last_verified'       => gmdate( 'Y-m-d', strtotime( '-13 months' ) ),
							'sifg_project_type'   => array( 'nonprofit' ),
							'sifg_project_status' => array( 'active' ),
						),
						'publish'
					);
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					return rest_ensure_response( array( 'id' => $id ) );
				},
			)
		);

		// Reads a Project through the Records API (test helper: private fields included).
		register_rest_route(
			'pegwork-e2e/v1',
			'/project/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static fn( WP_REST_Request $request ) => rest_ensure_response( Pegwork\Api\Records::get( (int) $request['id'] ) ),
			)
		);

		// Mints the still_active link for a Project, optionally as of an offset (for an expired link).
		register_rest_route(
			'pegwork-e2e/v1',
			'/link',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'PegworkAutomation\Links\Links' ) ) {
						return new WP_Error( 'pegwork_e2e_module_off', 'The automation module is not enabled.', array( 'status' => 409 ) );
					}
					$now = new DateTimeImmutable( (string) ( $request->get_param( 'offset' ) ?? 'now' ) );
					$url = PegworkAutomation\Links\Links::url( 'sifg_staleness_check', 'still_active', (int) $request->get_param( 'record' ), $now );
					return rest_ensure_response( array( 'url' => $url ) );
				},
			)
		);
	}
);
