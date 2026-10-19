<?php
/**
 * Inbound webhook endpoint.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Webhooks;

use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Runs;
use PegworkAutomation\Triggers;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/pegwork/v1/automations/<key>/webhook` starts a run of a webhook-triggered automation.
 *
 * Before WordPress decodes the body (rest_pre_dispatch): 30 requests per minute per automation (429, with
 * Retry-After; requests for keys that are not enabled webhook automations share one bucket), at most 64 KB
 * (413), `application/json` only (415). Then the permission callback verifies `X-Pegwork-Signature` against
 * the automation's secret with hash_equals and requires `X-Pegwork-Timestamp` within 300 seconds of now
 * (replay window). Unknown, disabled and non-webhook automations get the same 401 as a bad signature, so
 * the endpoint does not reveal which keys exist. The callback stores the payload as `trigger.payload`, uses
 * `X-Pegwork-Delivery` as the idempotency key and answers 202 with the run ID (200 and the first run's ID
 * for a repeated delivery). It never echoes the payload; values reach records only through the run's steps
 * and so through `Pegwork\Api\Records` (sanitized and validated).
 */
final class Inbound {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'pegwork/v1';

	/**
	 * Route pattern. Any segment matches, so every key gets the same answers.
	 */
	public const ROUTE = '/automations/(?P<key>[^/]+)/webhook';

	/**
	 * Maximum body size, bytes.
	 */
	public const MAX_BYTES = 65536;

	/**
	 * Requests allowed per automation per window.
	 */
	public const RATE = 30;

	/**
	 * Rate window, seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Largest JSON payload a run stores, bytes (the run context holds at most Runs::CONTEXT_MAX).
	 */
	public const MAX_PAYLOAD = Runs::CONTEXT_MAX - 1024;

	/**
	 * Transient prefix of the rate buckets.
	 */
	private const BUCKET = 'pegwork_automation_hook_';

	/**
	 * Hooks the route and the pre-dispatch limits. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'rest_api_init', array( self::class, 'routes' ) ) ) {
			add_action( 'rest_api_init', array( self::class, 'routes' ) );
		}
		if ( ! has_filter( 'rest_pre_dispatch', array( self::class, 'limit' ) ) ) {
			add_filter( 'rest_pre_dispatch', array( self::class, 'limit' ), 10, 3 );
		}
	}

	/**
	 * Registers the route. Hooked to rest_api_init.
	 */
	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => array( self::class, 'authorize' ),
				'args'                => array(),
			)
		);
	}

	/**
	 * The inbound URL of an automation.
	 *
	 * @param string $key Automation key.
	 */
	public static function url( string $key ): string {
		return rest_url( self::NAMESPACE . '/automations/' . rawurlencode( $key ) . '/webhook' );
	}

	/**
	 * Rate, size and type limits, checked before WordPress decodes the JSON body. Hooked to rest_pre_dispatch.
	 *
	 * @param mixed           $result  Response so far (null to continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function limit( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $result;
		}
		$key = self::key( $request->get_route() );
		if ( null === $key ) {
			return $result;
		}

		$wait = self::count( null === self::automation( $key ) ? '' : $key );
		if ( $wait > 0 ) {
			$response = rest_convert_error_to_response(
				new WP_Error( 'pegwork_webhook_rate_limited', __( 'Too many webhook requests for this automation; try again later.', 'pegwork' ), array( 'status' => 429 ) )
			);
			$response->header( 'Retry-After', (string) $wait );
			return $response;
		}

		$length = $request->get_header( 'content_length' );
		if ( strlen( $request->get_body() ) > self::MAX_BYTES || ( is_numeric( $length ) && (int) $length > self::MAX_BYTES ) ) {
			/* translators: %d: maximum size in KB. */
			return new WP_Error( 'pegwork_webhook_too_large', sprintf( __( 'Webhook bodies are at most %d KB.', 'pegwork' ), self::MAX_BYTES / 1024 ), array( 'status' => 413 ) );
		}

		$type = strtolower( trim( explode( ';', (string) $request->get_header( 'content_type' ) )[0] ) );
		if ( 'application/json' !== $type ) {
			return new WP_Error( 'pegwork_webhook_unsupported_type', __( 'Webhook bodies are application/json.', 'pegwork' ), array( 'status' => 415 ) );
		}
		return $result;
	}

	/**
	 * Verifies the signature and the replay window. The permission callback of the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		$key        = (string) $request->get_param( 'key' );
		$definition = self::automation( $key );
		// A throwaway key for unknown automations, so every request costs the same HMAC.
		$secret    = null === $definition ? null : Secrets::get( $key );
		$timestamp = (string) $request->get_header( 'x_pegwork_timestamp' );
		$valid     = Signature::verify( $secret ?? bin2hex( random_bytes( Secrets::BYTES ) ), $timestamp, $request->get_body(), (string) $request->get_header( 'x_pegwork_signature' ) );

		if ( ! $valid || null === $secret || ! Signature::fresh( $timestamp, Engine::instance()->clock()->now()->getTimestamp() ) ) {
			return new WP_Error( 'pegwork_webhook_unauthorized', __( 'The webhook signature is missing, invalid or expired.', 'pegwork' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Starts the run. The callback of the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key        = (string) $request->get_param( 'key' );
		$definition = self::automation( $key );
		if ( null === $definition ) {
			return new WP_Error( 'pegwork_webhook_unauthorized', __( 'The webhook signature is missing, invalid or expired.', 'pegwork' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $request->get_body(), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'pegwork_webhook_invalid_payload', __( 'The webhook body is not a JSON object or array.', 'pegwork' ), array( 'status' => 400 ) );
		}
		if ( strlen( (string) wp_json_encode( $payload ) ) > self::MAX_PAYLOAD ) {
			/* translators: %d: maximum size in KB. */
			return new WP_Error( 'pegwork_webhook_too_large', sprintf( __( 'Webhook payloads are at most %d KB once decoded.', 'pegwork' ), (int) floor( self::MAX_PAYLOAD / 1024 ) ), array( 'status' => 413 ) );
		}

		$delivery = trim( (string) $request->get_header( 'x_pegwork_delivery' ) );
		$delivery = '' === $delivery ? null : $delivery;
		if ( null !== $delivery && ! preg_match( '/^[A-Za-z0-9._:-]{1,128}$/', $delivery ) ) {
			return new WP_Error( 'pegwork_webhook_invalid_delivery', __( 'X-Pegwork-Delivery is 1 to 128 letters, digits, dots, colons, underscores or hyphens.', 'pegwork' ), array( 'status' => 400 ) );
		}

		$engine = Engine::instance();
		$id     = $engine->triggers()->webhook( $key, $definition, $payload, $delivery );
		if ( $id > 0 ) {
			return new WP_REST_Response(
				array(
					'run_id' => $id,
					'status' => 'queued',
				),
				202
			);
		}

		$first = null === $delivery ? null : $engine->runs()->find_key( $key, Triggers::delivery_key( $delivery ) );
		return new WP_REST_Response(
			array(
				'run_id'    => null === $first ? null : (int) $first['id'],
				'duplicate' => true,
			),
			200
		);
	}

	/**
	 * An enabled automation with a webhook trigger, or null.
	 *
	 * @param string $key Automation key.
	 * @return array<string, mixed>|null
	 */
	public static function automation( string $key ): ?array {
		$definition = '' === $key ? null : pegwork_registry()->automation( $key );
		if ( null === $definition || 'webhook' !== $definition['trigger']['type'] || ! Overrides::enabled( $definition ) ) {
			return null;
		}
		return $definition;
	}

	/**
	 * The automation key of a route on this endpoint, or null for other routes.
	 *
	 * @param string $route Request route.
	 */
	private static function key( string $route ): ?string {
		if ( ! preg_match( '#^/' . preg_quote( self::NAMESPACE, '#' ) . '/automations/([^/]+)/webhook/?$#', $route, $m ) ) {
			return null;
		}
		return rawurldecode( $m[1] );
	}

	/**
	 * Counts one request in an automation's bucket (fixed one-minute windows on the engine clock).
	 *
	 * @param string $key Automation key, or '' for the shared bucket of every other key.
	 * @return int 0 when the request is allowed, else seconds until the window ends.
	 */
	private static function count( string $key ): int {
		$now    = Engine::instance()->clock()->now()->getTimestamp();
		$name   = self::BUCKET . md5( $key );
		$bucket = get_transient( $name );
		if ( ! is_array( $bucket ) || ! isset( $bucket['start'], $bucket['count'] ) || $now >= (int) $bucket['start'] + self::WINDOW || $now < (int) $bucket['start'] ) {
			$bucket = array(
				'start' => $now,
				'count' => 0,
			);
		}
		++$bucket['count'];
		set_transient( $name, $bucket, self::WINDOW );

		/**
		 * Filters how many inbound webhook requests one automation accepts per minute.
		 *
		 * @param int    $rate Default 30.
		 * @param string $key  Automation key ('' for keys that are not enabled webhook automations).
		 */
		$rate = (int) apply_filters( 'pegwork_automation_webhook_rate', self::RATE, $key );
		return $bucket['count'] > $rate ? max( 1, (int) $bucket['start'] + self::WINDOW - $now ) : 0;
	}
}
