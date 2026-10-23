<?php
/**
 * Editor preview and test routes.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Editor;

use PegworkAutomation\DryRun;
use PegworkAutomation\Webhooks\Inbound;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST pegwork/v1/automation-editor/preview` renders one template against a sample record, and
 * `POST pegwork/v1/automation-editor/test` dry-runs a saved automation (DryRun). Both need the
 * automation managers' capability (`pegwork_manage_automations`, mapped to manage_options with no
 * filter to lower it, as for the `pegwork_automation` post type) and the REST cookie
 * nonce, accept only JSON bodies (else 415), and send `Cache-Control: no-store`. Neither has a side
 * effect: no email, HTTP, record write, run row, signed link or secret. Previews are never logged.
 */
final class PreviewRoutes {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'pegwork/v1';

	/**
	 * Route base.
	 */
	public const BASE = '/automation-editor';

	/**
	 * Longest template a preview renders, characters.
	 */
	public const MAX_TEMPLATE = 65536;

	/**
	 * Hooks the routes and the no-store header. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'rest_api_init', array( self::class, 'routes' ) ) ) {
			add_action( 'rest_api_init', array( self::class, 'routes' ) );
		}
		if ( ! has_filter( 'rest_pre_dispatch', array( self::class, 'json_only' ) ) ) {
			add_filter( 'rest_pre_dispatch', array( self::class, 'json_only' ), 10, 3 );
		}
		if ( ! has_filter( 'rest_post_dispatch', array( self::class, 'no_store' ) ) ) {
			add_filter( 'rest_post_dispatch', array( self::class, 'no_store' ), 10, 3 );
		}
	}

	/**
	 * Refuses a body that is not JSON with 415 before WordPress validates the parameters (which would
	 * otherwise answer 400 for the missing ones). Hooked to rest_pre_dispatch.
	 *
	 * @param mixed           $result  Response so far (null to continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function json_only( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! self::is_ours( $request ) ) {
			return $result;
		}
		$check = self::json( $request );
		return true === $check ? $result : $check;
	}

	/**
	 * Registers the routes. Hooked to rest_api_init.
	 */
	public static function routes(): void {
		$sample = array(
			'record_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'payload'   => array(
				'type' => array( 'object', 'array' ),
			),
		);
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => array( self::class, 'authorize' ),
				'args'                => array(
					'template' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => self::MAX_TEMPLATE,
					),
					'html'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'trigger'  => array(
						'type'     => 'object',
						'required' => true,
					),
					'steps'    => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'object' ),
						'default' => array(),
					),
				) + $sample,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			self::BASE . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'test' ),
				'permission_callback' => array( self::class, 'authorize' ),
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				) + $sample,
			)
		);
	}

	/**
	 * Permission callback: the capability (the cookie nonce is checked by core before this, so a
	 * request without it reaches here as no user), then a JSON body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( AutomationPost::CAP ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to test automations.', 'pegwork' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return self::json( $request );
	}

	/**
	 * Whether a request is for one of these routes (any letter case).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function is_ours( WP_REST_Request $request ): bool {
		return 1 === preg_match( '#^/' . preg_quote( self::NAMESPACE . self::BASE, '#' ) . '/#i', $request->get_route() );
	}

	/**
	 * True for a JSON body, else a 415 error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function json( WP_REST_Request $request ): bool|WP_Error {
		$type = $request->get_content_type();
		if ( 'application/json' !== ( $type['value'] ?? '' ) ) {
			return new WP_Error(
				'pegwork_preview_json_only',
				__( 'Send the request body as JSON (Content-Type: application/json).', 'pegwork' ),
				array( 'status' => 415 )
			);
		}
		return true;
	}

	/**
	 * Renders one template. Returns `{ rendered }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$trigger = (array) $request->get_param( 'trigger' );
		$payload = self::payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$record = DryRun::sample_record( $trigger, self::record_id( $request ) );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$rendered = DryRun::preview(
			(string) $request->get_param( 'template' ),
			(bool) $request->get_param( 'html' ),
			$record,
			DryRun::payload( $trigger, $record, $payload ),
			array_values( array_filter( (array) $request->get_param( 'steps' ), 'is_array' ) )
		);
		return new WP_REST_Response( array( 'rendered' => $rendered ), 200 );
	}

	/**
	 * Dry-runs a saved automation. Returns the DryRun report plus its text `lines`, the same as
	 * `wp pegwork automation test --format=json`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function test( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = self::payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$result = DryRun::result( (string) $request->get_param( 'key' ), self::record_id( $request ), $payload );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Adds `Cache-Control: no-store` to every response of these routes, refusals included. Hooked to
	 * rest_post_dispatch.
	 *
	 * @param mixed           $response Response.
	 * @param WP_REST_Server  $server   Server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function no_store( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && $request instanceof WP_REST_Request && self::is_ours( $request ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * The sample record ID, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function record_id( WP_REST_Request $request ): ?int {
		$id = $request->get_param( 'record_id' );
		return null === $id ? null : (int) $id;
	}

	/**
	 * The pasted sample payload, or null; 413 when it is larger than an inbound webhook may be.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<mixed>|null|WP_Error
	 */
	private static function payload( WP_REST_Request $request ): array|null|WP_Error {
		$payload = $request->get_param( 'payload' );
		if ( null === $payload ) {
			return null;
		}
		if ( strlen( (string) wp_json_encode( $payload ) ) > Inbound::MAX_PAYLOAD ) {
			return new WP_Error(
				'pegwork_preview_too_large',
				/* translators: %d: maximum size in KB. */
				sprintf( __( 'Sample payloads are at most %d KB.', 'pegwork' ), (int) floor( Inbound::MAX_PAYLOAD / 1024 ) ),
				array( 'status' => 413 )
			);
		}
		return (array) $payload;
	}
}
