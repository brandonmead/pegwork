<?php
/**
 * REST route that staff use to start a manual automation.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Throttle;
use PegworkAutomation\Admin\Screen;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /wp-json/pegwork/v1/automations/<key>/start { record_id, request }` starts a run of a `manual`
 * automation from the record editor (docs/automation-v3-design.md sections 3.3 and 7).
 *
 * The permission callback needs the REST cookie nonce (`X-WP-Nonce`, action `wp_rest`) and a logged-in user,
 * then the capability the trigger's `allow` names: `manage_options` for `admins`, or `edit_post` on the
 * named record for `editors` (administrators pass both). The callback counts the user against a limit of 10
 * starts a minute (429 with Retry-After), checks the record's type and the trigger's `when`, and starts the
 * run with idempotency key `manual:<user>:<request>`, so a double submit of one dialog starts one run (200
 * with the first run's ID; 202 for a new run). Editors only start what the administrator wrote: nothing in
 * the request changes which steps run.
 */
final class StartRoute {

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'pegwork/v1';

	/**
	 * Route pattern.
	 */
	public const ROUTE = '/automations/(?P<key>[a-z0-9_]+)/start';

	/**
	 * Starts allowed per user per window.
	 */
	public const RATE = 10;

	/**
	 * Rate window, seconds.
	 */
	public const WINDOW = 60;

	/**
	 * Hooks the route. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'rest_api_init', array( self::class, 'routes' ) ) ) {
			add_action( 'rest_api_init', array( self::class, 'routes' ) );
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
				'callback'            => array( self::class, 'start' ),
				'permission_callback' => array( self::class, 'authorize' ),
				'args'                => array(
					'record_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'request'   => array( // One random ID per confirmation dialog.
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[A-Za-z0-9-]{8,64}$',
					),
				),
			)
		);
	}

	/**
	 * The route URL of an automation (relative to the REST root, as apiFetch takes it).
	 *
	 * @param string $key Automation key.
	 */
	public static function path( string $key ): string {
		return '/' . self::NAMESPACE . '/automations/' . rawurlencode( $key ) . '/start';
	}

	/**
	 * Nonce, login and the capability `allow` names.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ): bool|WP_Error {
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?? $request->get_param( '_wpnonce' ) ?? '' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'pegwork' ), array( 'status' => 403 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to run automations.', 'pegwork' ), array( 'status' => 401 ) );
		}
		return self::can_run( (string) $request['key'], (int) $request['record_id'] )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to run this automation.', 'pegwork' ), array( 'status' => 403 ) );
	}

	/**
	 * Whether the current user may start an automation (on a record, for a trigger with a content type).
	 * Unknown and non-manual automations are refused alike, so the route does not reveal which keys exist.
	 * Editors also need `delete_post` on the record when the automation, or one it starts, can move records
	 * to the trash (Manual::trashes()).
	 *
	 * @param string $key    Automation key.
	 * @param int    $record Record ID, or 0.
	 */
	public static function can_run( string $key, int $record = 0 ): bool {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition || 'manual' !== $definition['trigger']['type'] ) {
			return false;
		}
		if ( current_user_can( Screen::CAPABILITY ) ) {
			return true;
		}
		return 'editors' === ( $definition['trigger']['allow'] ?? 'admins' )
			&& isset( $definition['trigger']['content_type'] )
			&& $record > 0
			&& current_user_can( 'edit_post', $record )
			&& ( ! Manual::trashes( $key ) || current_user_can( 'delete_post', $record ) );
	}

	/**
	 * Starts the run. Answers 202 `{ run, duplicate: false, message, url }`, 200 for a repeated request, or
	 * an error (400, 404, 409, 429) whose message the editor shows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$key  = (string) $request['key'];
		$user = get_current_user_id();
		$now  = Engine::instance()->clock()->now()->getTimestamp();

		$wait = Throttle::take( 'automation|manual|' . $user, self::RATE, self::WINDOW, $now );
		if ( $wait > 0 ) {
			$error = new WP_Error(
				'pegwork_rate_limited',
				/* translators: %d: seconds to wait. */
				sprintf( _n( 'Too many automations started. Try again in %d second.', 'Too many automations started. Try again in %d seconds.', $wait, 'pegwork' ), $wait ),
				array( 'status' => 429 )
			);
			$response = rest_convert_error_to_response( $error );
			$response->header( 'Retry-After', (string) $wait );
			return $response;
		}

		$record = (int) $request['record_id'];
		$result = ( new Manual() )->start( $key, $record > 0 ? $record : null, $user, 'editor', (string) $request['request'] );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$label = (string) pegwork_registry()->automation( $key )['trigger']['button'];
		$url   = null;
		if ( $result['run'] > 0 && current_user_can( Screen::CAPABILITY ) ) {
			$url = Screen::url(
				array(
					'view' => 'run',
					'run'  => $result['run'],
				)
			);
		}
		return new WP_REST_Response(
			array(
				'run'       => $result['run'],
				'duplicate' => $result['duplicate'],
				'message'   => $result['duplicate']
					/* translators: %s: button text of the automation. */
					? sprintf( __( '%s was already started from this dialog.', 'pegwork' ), $label )
					/* translators: %s: button text of the automation. */
					: sprintf( __( '%s started. It runs in the background.', 'pegwork' ), $label ),
				'url'       => $url,
			),
			$result['duplicate'] ? 200 : 202
		);
	}
}
