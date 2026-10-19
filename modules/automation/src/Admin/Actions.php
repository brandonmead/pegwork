<?php
/**
 * Form handlers of the Automations screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use WP_Error;

/**
 * Enable/Disable, Run now and Retry, each a POST to admin-post.php with `manage_options` and a per-row
 * nonce. They call what the CLI calls: Overrides::toggle() (`wp pegwork automation enable|disable`),
 * Manual::run() (`run <key>`, without --sync: runs are queued for WP-Cron) and Manual::retry()
 * (`retry <run>`), so the screen and the CLI apply identical rules.
 */
final class Actions {

	/**
	 * The admin-post action of Enable/Disable; nonce action prefix too (the key is appended).
	 */
	public const TOGGLE = 'pegwork_automation_toggle';

	/**
	 * The admin-post action of Run now; nonce action prefix too (the key is appended).
	 */
	public const RUN = 'pegwork_automation_run';

	/**
	 * The admin-post action of Retry; nonce action prefix too (the run ID is appended).
	 */
	public const RETRY = 'pegwork_automation_retry';

	/**
	 * Enables or disables an automation for this site. Hooked to admin_post_pegwork_automation_toggle.
	 */
	public static function toggle(): void {
		self::authorize();
		$key = self::key();
		check_admin_referer( self::TOGGLE . '_' . $key );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( ! in_array( $op, array( 'enable', 'disable' ), true ) ) {
			wp_die( esc_html__( 'Unknown automation action.', 'pegwork' ), '', array( 'response' => 400 ) );
		}

		$result = Overrides::toggle( $key, 'enable' === $op ? 'on' : 'off' );
		if ( $result instanceof WP_Error ) {
			Screen::set_notice( 'error', $result->get_error_message() );
		} else {
			$label = self::label( $key );
			Screen::set_notice(
				'success',
				'enable' === $op
					/* translators: %s: automation label. */
					? sprintf( __( 'Automation %s enabled for this site.', 'pegwork' ), $label )
					/* translators: %s: automation label. */
					: sprintf( __( 'Automation %s disabled for this site. New runs do not start; queued runs are cancelled when they wake.', 'pegwork' ), $label )
			);
		}

		self::redirect( Screen::url() );
	}

	/**
	 * Fires a schedule automation once now, as `wp pegwork automation run <key>` does: its records are
	 * swept (filters applied, idempotency keys respected) and the runs queued for WP-Cron; its regular
	 * schedule does not move. Hooked to admin_post_pegwork_automation_run.
	 */
	public static function run(): void {
		self::authorize();
		$key = self::key();
		check_admin_referer( self::RUN . '_' . $key );

		$definition = pegwork_registry()->automation( $key );
		if ( null !== $definition && 'schedule' !== $definition['trigger']['type'] ) {
			Screen::set_notice( 'error', __( 'Run now is for schedule automations. Event automations run when their event fires.', 'pegwork' ) );
			self::redirect( Screen::url() );
		}

		$manual = new Manual();
		$result = $manual->run( $key );
		$manual->finish();

		if ( $result instanceof WP_Error ) {
			Screen::set_notice( 'error', $result->get_error_message() );
			self::redirect( Screen::url() );
		}

		$created = count( $result['runs'] );
		$message = sprintf(
			/* translators: 1: automation label, 2: number of records or firings matched, 3: number of runs created. */
			_n( '%1$s: matched %2$s, created %3$s run. It runs in the background; reload this page to follow it.', '%1$s: matched %2$s, created %3$s runs. They run in the background; reload this page to follow them.', $created, 'pegwork' ),
			self::label( $key ),
			number_format_i18n( $result['matched'] ),
			number_format_i18n( $created )
		);
		if ( $result['duplicates'] > 0 ) {
			/* translators: %s: number of matches skipped. */
			$message .= ' ' . sprintf( _n( '%s already had a run for its idempotency key.', '%s already had a run for their idempotency key.', $result['duplicates'], 'pegwork' ), number_format_i18n( $result['duplicates'] ) );
		}
		if ( $result['capped'] ) {
			$message .= ' ' . __( 'Stopped at max_runs; the rest are checked next time.', 'pegwork' );
		}
		if ( $result['continued'] ) {
			$message .= ' ' . __( 'The sweep continues in the background.', 'pegwork' );
		}
		Screen::set_notice( 'success', $message );

		self::redirect(
			Screen::url(
				array(
					'view'       => 'log',
					'automation' => $key,
				)
			)
		);
	}

	/**
	 * Resumes a failed run from its failed step, as `wp pegwork automation retry <run>` does (retry policy
	 * counted afresh; queued for WP-Cron). Hooked to admin_post_pegwork_automation_retry.
	 */
	public static function retry(): void {
		self::authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce action includes the run ID, so it is read first and verified next.
		$id = isset( $_POST['run'] ) ? absint( wp_unslash( $_POST['run'] ) ) : 0;
		check_admin_referer( self::RETRY . '_' . $id );

		$manual = new Manual();
		$result = $manual->retry( $id );
		$manual->finish();

		if ( $result instanceof WP_Error ) {
			Screen::set_notice( 'error', $result->get_error_message() );
		} else {
			Screen::set_notice(
				'success',
				/* translators: %d: run ID. */
				sprintf( __( 'Run %d queued again from the step that failed. It runs in the background; reload this page to follow it.', 'pegwork' ), $id )
			);
		}

		self::redirect(
			Screen::url(
				array(
					'view' => 'run',
					'run'  => $id,
				)
			)
		);
	}

	/**
	 * Refuses users without the capability, with a 403.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Screen::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Pegwork automations.', 'pegwork' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The posted automation key (read before the nonce check, since the nonce action includes it).
	 */
	private static function key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the caller right after.
		return isset( $_POST['automation'] ) ? sanitize_text_field( wp_unslash( $_POST['automation'] ) ) : '';
	}

	/**
	 * An automation's label, or its key when it is not registered.
	 *
	 * @param string $key Automation key.
	 */
	private static function label( string $key ): string {
		return (string) ( pegwork_registry()->automation( $key )['label'] ?? $key );
	}

	/**
	 * Redirects to a screen URL and ends the request.
	 *
	 * @param string $url Screen URL.
	 */
	private static function redirect( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}
}
