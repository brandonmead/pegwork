<?php
/**
 * Pegwork > Forms screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api;

/**
 * The Forms list (`admin.php?page=pegwork-forms`, submenu position 20): every registry form with its
 * builder link, key, source, received and unconfirmed counts, Entries link and "Copy block". Visiting
 * it creates the `pegwork_form` post of any form that has none. Requires the forms capability.
 */
final class FormsAdmin {

	/**
	 * Submenu slug.
	 */
	public const SLUG = 'pegwork-forms';

	/**
	 * Submenu position under Pegwork (`docs/extension-api.md`: Forms 20, Entries 21).
	 */
	public const POSITION = 20;

	/**
	 * Hooks the submenu. Registered before EntriesAdmin, so Forms sits above Entries.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_menu', array( self::class, 'menu' ) ) ) {
			add_action( 'admin_menu', array( self::class, 'menu' ) );
		}
	}

	/**
	 * Adds Pegwork > Forms. Hooked to admin_menu at the default priority.
	 */
	public static function menu(): void {
		add_submenu_page(
			Api\ADMIN_MENU,
			__( 'Forms', 'pegwork' ),
			__( 'Forms', 'pegwork' ),
			Module::capability(),
			self::SLUG,
			array( self::class, 'render' ),
			self::POSITION
		);
	}

	/**
	 * URL of the screen.
	 */
	public static function url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Placement markup for a form.
	 *
	 * @param string $key Form key.
	 */
	public static function block_markup( string $key ): string {
		return '<!-- wp:pegwork/form ' . wp_json_encode( array( 'form' => $key ) ) . ' /-->';
	}

	/**
	 * Prints the screen. Menu callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Module::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage forms.', 'pegwork' ), '', array( 'response' => 403 ) );
		}

		FormPost::ensure_posts();
		$table = new FormsTable();
		$table->prepare_items();

		echo '<style>.pegwork-forms__markup{display:inline-block;max-width:100%;font-size:12px;word-break:break-all;}</style>';
		echo '<div class="wrap pegwork-forms">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Forms', 'pegwork' ) . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=' . FormPost::TYPE ) ) . '">' . esc_html__( 'Add form', 'pegwork' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'Build a form in the block editor, then place it on any page with the Form block, or paste its block markup into a pattern. Forms from a file package are read-only here: duplicate one to change it.', 'pegwork' ) . '</p>';
		$table->display();
		echo '<p class="screen-reader-text" id="pegwork-forms-status" aria-live="polite"></p>';
		echo '</div>';

		self::print_copy_script();
	}

	/**
	 * Inline script that reveals and wires the Copy block buttons (clipboard API, else a text selection copy).
	 */
	private static function print_copy_script(): void {
		$copied = esc_js( __( 'Copied the block markup to the clipboard.', 'pegwork' ) );
		$failed = esc_js( __( 'Could not copy. Select the block markup and copy it yourself.', 'pegwork' ) );
		?>
		<script>
		( function () {
			var status = document.getElementById( 'pegwork-forms-status' );
			function say( text ) {
				status.textContent = '';
				window.setTimeout( function () { status.textContent = text; }, 50 );
			}
			function fallback( button ) {
				var code = button.parentNode.querySelector( '.pegwork-forms__markup' );
				var range = document.createRange();
				range.selectNodeContents( code );
				var selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange( range );
				var ok = false;
				try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
				say( ok ? '<?php echo $copied; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' : '<?php echo $failed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' );
			}
			document.querySelectorAll( '.pegwork-forms__copy' ).forEach( function ( button ) {
				button.hidden = false;
				button.addEventListener( 'click', function () {
					var text = button.getAttribute( 'data-block' );
					if ( navigator.clipboard && window.isSecureContext ) {
						navigator.clipboard.writeText( text ).then(
							function () { say( '<?php echo $copied; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js above. ?>' ); },
							function () { fallback( button ); }
						);
					} else {
						fallback( button );
					}
				} );
			} );
		}() );
		</script>
		<?php
	}
}
