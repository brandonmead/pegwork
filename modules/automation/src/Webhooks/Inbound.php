<?php
/**
 * Inbound webhook endpoint.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Webhooks;

use Pegwork\Api\Throttle;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Runs;
use PegworkAutomation\Triggers;
use WeakMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/pegwork/v1/automations/<key>/webhook` starts a run of a webhook-triggered automation.
 *
 * Before WordPress decodes the body (rest_pre_dispatch, route matched without regard to letter case, as
 * WordPress matches it): at most 64 KB (413), `application/json` only (415), then the signature. Requests
 * that fail it count against their client IP (30 a minute, then 429 for every request from that IP until
 * the window has room), so they never touch an automation's quota and every key, known or not, answers
 * alike. Signed requests count against the automation (30 a minute, then 429 with Retry-After). The
 * signature is `X-Pegwork-Signature` checked against the automation's secret with hash_equals, with
 * `X-Pegwork-Timestamp` within 300 seconds of now (replay window). Unknown, disabled and non-webhook
 * automations get the same 401 as a bad signature, so the endpoint does not reveal which keys exist. The
 * permission callback repeats every check for a request rest_pre_dispatch did not screen, so no route
 * spelling skips them. The callback accepts each signature once (a replayed request is a duplicate, with
 * or without a new `X-Pegwork-Delivery`), stores the payload as `trigger.payload`, uses
 * `X-Pegwork-Delivery` as the idempotency key and answers 202 with the run ID (200 and the first run's ID
 * for a repeated delivery). It never echoes the payload; values reach records only through the run's
 * steps and so through `Pegwork\Api\Records` (sanitized and validated).
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
	 * Signed requests allowed per automation per window, and failed requests per client IP per window.
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
	 * Requests already screened in this PHP process (by rest_pre_dispatch or the permission callback).
	 *
	 * @var WeakMap<WP_REST_Request, true>|null
	 */
	private static ?WeakMap $screened = null;

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
	 * Size, type, signature and rate checks, before WordPress decodes the JSON body. Hooked to rest_pre_dispatch.
	 *
	 * @param mixed           $result  Response so far (null to continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function limit( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'POST' !== strtoupper( $request->get_method() ) ) {
			return $result;
		}
		$key = self::key( $request->get_route() );
		if ( null === $key ) {
			return $result;
		}

		$check = self::screen( $request, $key );
		if ( true === $check ) {
			return $result;
		}
		$wait = $check->get_error_data()['retry_after'] ?? null;
		if ( null === $wait ) {
			return $check;
		}
		$response = rest_convert_error_to_response( $check );
		$response->header( 'Retry-After', (string) $wait );
		return $response;
	}

	/**
	 * The permission callback of the route: the signature again, and every check of limit() for a request
	 * it did not screen (a route spelling it did not recognise).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		$key = (string) $request->get_param( 'key' );
		if ( ! isset( self::screened()[ $request ] ) ) {
			$check = self::screen( $request, $key );
			if ( true !== $check ) {
				return $check;
			}
		}
		return self::signed( $request, $key ) ? true : self::unauthorized();
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
			return self::unauthorized();
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
		// Each signature is accepted once while its timestamp is fresh. The delivery ID is not signed, so
		// without this a captured request could be replayed under a new (or no) delivery ID.
		$now       = $engine->clock()->now()->getTimestamp();
		$timestamp = (int) $request->get_header( 'x_pegwork_timestamp' );
		$signature = trim( (string) $request->get_header( 'x_pegwork_signature' ) );
		$replayed  = Throttle::take( 'webhook|signature|' . $key . '|' . $signature, 1, max( 1, $timestamp + Signature::WINDOW + 1 - $now ), $now ) > 0;

		$id = $replayed ? 0 : $engine->triggers()->webhook( $key, $definition, $payload, $delivery );
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
	 * Every check before the callback, counting the request once: size (413), type (415), a client IP with
	 * too many failures (429), the signature (401, counted against the client IP), then the automation's
	 * quota of signed requests (429).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Automation key from the route.
	 * @return true|WP_Error A 429 error carries `retry_after` in its data.
	 */
	private static function screen( WP_REST_Request $request, string $key ): bool|WP_Error {
		self::screened()[ $request ] = true;

		$length = $request->get_header( 'content_length' );
		if ( strlen( $request->get_body() ) > self::MAX_BYTES || ( is_numeric( $length ) && (int) $length > self::MAX_BYTES ) ) {
			/* translators: %d: maximum size in KB. */
			return new WP_Error( 'pegwork_webhook_too_large', sprintf( __( 'Webhook bodies are at most %d KB.', 'pegwork' ), self::MAX_BYTES / 1024 ), array( 'status' => 413 ) );
		}

		$type = strtolower( trim( explode( ';', (string) $request->get_header( 'content_type' ) )[0] ) );
		if ( 'application/json' !== $type ) {
			return new WP_Error( 'pegwork_webhook_unsupported_type', __( 'Webhook bodies are application/json.', 'pegwork' ), array( 'status' => 415 ) );
		}

		$now    = Engine::instance()->clock()->now()->getTimestamp();
		$client = 'webhook|client|' . Throttle::client_ip();
		$wait   = Throttle::wait( $client, self::rate( '' ), $now );
		if ( $wait > 0 ) {
			return self::too_many( $wait );
		}

		if ( ! self::signed( $request, $key ) ) {
			$wait = Throttle::take( $client, self::rate( '' ), self::WINDOW, $now );
			return $wait > 0 ? self::too_many( $wait ) : self::unauthorized();
		}

		$wait = Throttle::take( 'webhook|automation|' . $key, self::rate( $key ), self::WINDOW, $now );
		return $wait > 0 ? self::too_many( $wait ) : true;
	}

	/**
	 * Whether a request is signed with the secret of an enabled webhook automation, within the replay window.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Automation key.
	 */
	private static function signed( WP_REST_Request $request, string $key ): bool {
		$definition = self::automation( $key );
		// A throwaway key for unknown automations, so every request costs the same HMAC.
		$secret    = null === $definition ? null : Secrets::get( $key );
		$timestamp = (string) $request->get_header( 'x_pegwork_timestamp' );
		$valid     = Signature::verify( $secret ?? bin2hex( random_bytes( Secrets::BYTES ) ), $timestamp, $request->get_body(), (string) $request->get_header( 'x_pegwork_signature' ) );

		return $valid && null !== $secret && Signature::fresh( $timestamp, Engine::instance()->clock()->now()->getTimestamp() );
	}

	/**
	 * Requests per window: for an automation's signed requests, or ('' key) a client IP's failed ones.
	 *
	 * @param string $key Automation key, or '' for the per-IP bucket of failed requests.
	 */
	private static function rate( string $key ): int {
		/**
		 * Filters how many signed inbound webhook requests one automation accepts per minute, or (key '')
		 * how many failed ones one client IP may send per minute before every request from it gets 429.
		 *
		 * @param int    $rate Default 30. 0 turns the limit off.
		 * @param string $key  Automation key, or '' for failed requests per client IP.
		 */
		return (int) apply_filters( 'pegwork_automation_webhook_rate', self::RATE, $key );
	}

	/**
	 * The 401 every failed signature, and every unknown, disabled or non-webhook key, gets.
	 */
	private static function unauthorized(): WP_Error {
		return new WP_Error( 'pegwork_webhook_unauthorized', __( 'The webhook signature is missing, invalid or expired.', 'pegwork' ), array( 'status' => 401 ) );
	}

	/**
	 * A 429.
	 *
	 * @param int $wait Seconds until the window has room.
	 */
	private static function too_many( int $wait ): WP_Error {
		return new WP_Error(
			'pegwork_webhook_rate_limited',
			__( 'Too many webhook requests; try again later.', 'pegwork' ),
			array(
				'status'      => 429,
				'retry_after' => $wait,
			)
		);
	}

	/**
	 * The screened requests.
	 *
	 * @return WeakMap<WP_REST_Request, true>
	 */
	private static function screened(): WeakMap {
		self::$screened ??= new WeakMap();
		return self::$screened;
	}

	/**
	 * The automation key of a route on this endpoint, or null for other routes. Case-insensitive, as
	 * WordPress matches routes (`/Pegwork/v1/.../WEBHOOK` reaches the same handler).
	 *
	 * @param string $route Request route.
	 */
	private static function key( string $route ): ?string {
		if ( ! preg_match( '#^/' . preg_quote( self::NAMESPACE, '#' ) . '/automations/([^/]+)/webhook/?$#i', $route, $m ) ) {
			return null;
		}
		return rawurldecode( $m[1] );
	}
}
