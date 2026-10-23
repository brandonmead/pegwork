<?php
/**
 * Pegwork > Automations admin screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use const Pegwork\Api\ADMIN_MENU;

/**
 * Registers the Automations submenu (position 30, docs/extension-api.md) and its form handlers, and
 * routes the screen's three views: the automations list, the run log (`view=log`) and one run
 * (`view=run&run=<id>`). Every view and action requires `manage_options`.
 */
final class Screen {

	/**
	 * Capability for every view and action.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Page slug.
	 */
	public const SLUG = 'pegwork-automations';

	/**
	 * Submenu position (an index into the submenu built so far; see docs/extension-api.md).
	 */
	public const POSITION = 30;

	/**
	 * Style handle.
	 */
	public const STYLE = 'pegwork-automation-admin';

	/**
	 * Transient prefix for the notice shown after an action; the user ID is appended.
	 */
	private const NOTICE = 'pegwork_automation_notice_';

	/**
	 * Hook suffix of the screen, once added.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Registers the hooks. Safe to call more than once (add_action ignores duplicates).
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_' . Actions::TOGGLE, array( Actions::class, 'toggle' ) );
		add_action( 'admin_post_' . Actions::RUN, array( Actions::class, 'run' ) );
		add_action( 'admin_post_' . Actions::RETRY, array( Actions::class, 'retry' ) );
	}

	/**
	 * Adds the submenu. Hooked to admin_menu at the default priority.
	 */
	public static function add_menu(): void {
		self::$hook = (string) add_submenu_page(
			ADMIN_MENU,
			__( 'Pegwork Automations', 'pegwork' ),
			__( 'Automations', 'pegwork' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' ),
			self::POSITION
		);
	}

	/**
	 * Adds the few styles core lacks (status badges, wrapping codes, compact lists).
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue( string $hook_suffix ): void {
		if ( '' === self::$hook || $hook_suffix !== self::$hook ) {
			return;
		}
		wp_register_style( self::STYLE, false, array(), PEGWORK_VERSION );
		wp_enqueue_style( self::STYLE );
		wp_add_inline_style(
			self::STYLE,
			'.pegwork-automations .pegwork-table code{overflow-wrap:anywhere;}'
			// A step id in the Step column widens the column instead of breaking mid-word (anywhere, and core's word-break:break-word, shrink min-content).
			. '.pegwork-automations .pegwork-table th[scope=row] code{overflow-wrap:break-word;word-break:normal;}'
			. '.pegwork-automations .pegwork-table a{text-decoration:underline;}'
			. '.pegwork-automations .pegwork-table td,.pegwork-automations .pegwork-table th{vertical-align:top;}'
			. '.pegwork-automations .pegwork-meta{display:block;margin-top:4px;color:#50575e;}'
			. '.pegwork-automations .pegwork-status{display:inline-block;padding:0 6px;border:1px solid #8c8f94;border-radius:3px;background:#f6f7f7;font-weight:600;}'
			. '.pegwork-automations .pegwork-status-failed{border-color:#d63638;background:#fcf0f1;color:#8a2424;}'
			. '.pegwork-automations .pegwork-status-completed,.pegwork-automations .pegwork-status-ok,.pegwork-automations .pegwork-status-on{border-color:#00a32a;background:#edfaef;color:#005c12;}'
			. '.pegwork-automations .pegwork-actions form{display:inline-block;margin:0 4px 4px 0;}'
			. '.pegwork-automations .pegwork-actions .button{margin-bottom:4px;}'
			. '.pegwork-automations .pegwork-runs-link{display:block;}'
			. '.pegwork-automations .pegwork-edit-links{display:block;margin:0 0 6px;}'
			. '.pegwork-automations .pegwork-edit-links a{display:inline-block;margin:0 8px 2px 0;}'
			. '.pegwork-automations .pegwork-reason{display:block;margin-top:2px;}'
			. '.pegwork-automations .pegwork-summary{margin:0;}'
			. '.pegwork-automations .pegwork-summary li{margin:0 0 2px;}'
			. '.pegwork-automations .pegwork-filters{margin:1em 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}'
			. '.pegwork-automations .pegwork-filters select{width:auto;max-width:min(100%,26em);text-overflow:ellipsis;}'
			. '.pegwork-automations .pegwork-pages{margin:1em 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}'
			. '.pegwork-automations .pegwork-pages .page-numbers{display:inline-block;min-width:30px;padding:4px 8px;text-align:center;}'
			. '.pegwork-automations .pegwork-pages .page-numbers.current{font-weight:600;}'
			. '.pegwork-automations h2{margin-top:2em;}'
			. '.pegwork-automations .pegwork-cli-note{margin-top:2em;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-1{padding-left:32px;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-2{padding-left:56px;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-3{padding-left:80px;}'
			. '.pegwork-automations .pegwork-branch-row td{padding-top:12px;padding-bottom:4px;}'
			. '@media screen and (max-width:782px){.pegwork-automations .pegwork-table td,.pegwork-automations .pegwork-table th{padding:8px 6px;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-1{padding-left:18px;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-2{padding-left:30px;}'
			. '.pegwork-automations .pegwork-table .pegwork-depth-3{padding-left:42px;}}'
		);
	}

	/**
	 * URL of a view of the screen.
	 *
	 * @param array<string, string|int> $args Query arguments (`view`, `run`, `automation`, `status`, `paged`).
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	/**
	 * Prints the screen. Menu callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pegwork' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$run  = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap pegwork-automations">';
		if ( 'run' === $view ) {
			RunLog::render_run( $run );
		} elseif ( 'log' === $view ) {
			RunLog::render_log();
		} else {
			AutomationList::render();
		}
		echo '</div>';
	}

	/**
	 * Stores the current user's notice for the next view.
	 *
	 * @param string $type    success or error.
	 * @param string $message Plain text.
	 */
	public static function set_notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints and clears the current user's notice from the last action, if any.
	 */
	public static function notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/**
	 * Hidden fields of a per-row action form: the admin-post action and a nonce without the repeated
	 * `id="_wpnonce"` that wp_nonce_field() would print in every row.
	 *
	 * @param string $action admin-post action.
	 * @param string $nonce  Nonce action.
	 */
	public static function form_fields( string $action, string $nonce ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $nonce ) ) . '">';
	}
}
