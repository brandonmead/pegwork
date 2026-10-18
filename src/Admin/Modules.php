<?php
/**
 * Pegwork Modules screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

use Pegwork\Modules\Loader;
use WP_Error;

/**
 * Lists the modules in the manifest and enables or disables them.
 *
 * Toggles go through Modules\Loader::enable() and disable(), the same calls `wp pegwork module` makes, so
 * the screen and the CLI apply identical rules.
 */
final class Modules {

	/**
	 * `admin-post.php` action of the toggle forms.
	 */
	public const ACTION = 'pegwork_module';

	/**
	 * Nonce action prefix; the module slug is appended.
	 */
	public const NONCE = 'pegwork_module_';

	/**
	 * Transient prefix for the notice shown after a toggle; the user ID is appended.
	 */
	private const NOTICE = 'pegwork_module_notice_';

	/**
	 * URL of the Modules screen.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . Menu::MODULES_SLUG );
	}

	/**
	 * Prints the screen. Menu callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pegwork' ), 403 );
		}

		$manifest = Loader::manifest();
		$enabled  = Loader::enabled();

		echo '<div class="wrap pegwork-admin">';
		echo '<h1>' . esc_html__( 'Pegwork Modules', 'pegwork' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( 'Optional parts of Pegwork. A disabled module does not load at all. A module can be enabled once the modules it requires are enabled, and disabled once no enabled module requires it.', 'pegwork' ) . '</p>';

		echo '<table class="wp-list-table widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Modules', 'pegwork' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Module', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Description', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Requires', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'pegwork' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'pegwork' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $manifest ) {
			echo '<tr class="no-items"><td colspan="5">' . esc_html__( 'No modules available.', 'pegwork' ) . '</td></tr>';
		}

		foreach ( $manifest as $slug => $entry ) {
			$slug       = (string) $slug;
			$is_enabled = in_array( $slug, $enabled, true );
			$requires   = array_map( fn( string $required ) => $manifest[ $required ]['label'] ?? $required, $entry['requires'] );

			echo '<tr>';
			echo '<th scope="row"><strong>' . esc_html( $entry['label'] ) . '</strong><br><code>' . esc_html( $slug ) . '</code></th>';
			echo '<td>' . esc_html( $entry['description'] ) . '</td>';
			echo '<td>' . esc_html( $requires ? implode( ', ', $requires ) : __( 'None', 'pegwork' ) ) . '</td>';
			echo '<td>' . esc_html( $is_enabled ? __( 'Enabled', 'pegwork' ) : __( 'Disabled', 'pegwork' ) ) . '</td>';
			echo '<td>';
			self::action( $slug, $entry['label'], $is_enabled, $manifest );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="pegwork-cli-note">';
		printf(
			/* translators: %s: CLI command. */
			esc_html__( 'The same rules apply to %s.', 'pegwork' ),
			'<code>wp pegwork module enable|disable &lt;slug&gt;</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Prints a row's action: a toggle form, or a disabled button with the visible reason it is blocked.
	 *
	 * @param string                              $slug       Module slug.
	 * @param string                              $label      Module label.
	 * @param bool                                $is_enabled Whether the module is enabled.
	 * @param array<string, array<string, mixed>> $manifest   Module manifest.
	 */
	private static function action( string $slug, string $label, bool $is_enabled, array $manifest ): void {
		$op      = $is_enabled ? 'disable' : 'enable';
		$verb    = $is_enabled ? __( 'Disable', 'pegwork' ) : __( 'Enable', 'pegwork' );
		$blocked = $is_enabled ? Loader::dependents( $slug ) : Loader::missing_requirements( $slug );
		$name    = '<span class="screen-reader-text"> ' . esc_html( $label ) . '</span>';

		if ( $blocked ) {
			$labels = implode( ', ', array_map( fn( string $other ) => $manifest[ $other ]['label'] ?? $other, $blocked ) );
			$reason = $is_enabled
				/* translators: %s: comma-separated module names. */
				? sprintf( __( 'Required by %s', 'pegwork' ), $labels )
				/* translators: %s: comma-separated module names. */
				: sprintf( __( 'Requires %s', 'pegwork' ), $labels );
			$id = 'pegwork-reason-' . sanitize_html_class( $slug );

			echo '<button type="button" class="button disabled" aria-disabled="true" aria-describedby="' . esc_attr( $id ) . '">' . esc_html( $verb ) . $name . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
			echo '<span class="pegwork-reason description" id="' . esc_attr( $id ) . '">' . esc_html( $reason ) . '</span>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="module" value="' . esc_attr( $slug ) . '">';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		// wp_nonce_field() would repeat id="_wpnonce" in every row; the same fields without the id.
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::NONCE . $slug ) ) . '">';
		wp_referer_field();
		echo '<button type="submit" class="' . esc_attr( $is_enabled ? 'button' : 'button button-primary' ) . '">' . esc_html( $verb ) . $name . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $name is escaped above.
		echo '</form>';
	}

	/**
	 * Handles a toggle form: capability, nonce, Loader call, notice, redirect. Hooked to admin_post_pegwork_module.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Pegwork modules.', 'pegwork' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce action includes the slug, so it is read first and verified next.
		$slug = isset( $_POST['module'] ) ? sanitize_text_field( wp_unslash( $_POST['module'] ) ) : '';
		check_admin_referer( self::NONCE . $slug );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'enable' === $op ) {
			$result = Loader::enable( $slug );
		} elseif ( 'disable' === $op ) {
			$result = Loader::disable( $slug );
		} else {
			wp_die( esc_html__( 'Unknown module action.', 'pegwork' ), '', array( 'response' => 400 ) );
		}

		$label = Loader::manifest()[ $slug ]['label'] ?? $slug;
		if ( $result instanceof WP_Error ) {
			$notice = array(
				'type'    => 'error',
				'message' => $result->get_error_message(),
			);
		} else {
			$notice = array(
				'type'    => 'success',
				'message' => 'enable' === $op
					/* translators: %s: module name. */
					? sprintf( __( 'Module %s enabled.', 'pegwork' ), $label )
					/* translators: %s: module name. */
					: sprintf( __( 'Module %s disabled.', 'pegwork' ), $label ),
			);
		}
		set_transient( self::NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Prints and clears the current user's notice from the last toggle, if any.
	 */
	private static function notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}
}
