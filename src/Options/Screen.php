<?php
/**
 * Generated settings screens for options pages.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Options;

use Pegwork\Registry\Validator;

use const Pegwork\Api\ADMIN_MENU;

/**
 * One Settings API screen per options page, under the Pegwork menu, slug `pegwork-options-{key}`.
 *
 * The page capability guards the menu item, the screen and the options.php save (Pages::capability()).
 */
final class Screen {

	/**
	 * Slug prefix of the screens.
	 */
	public const SLUG_PREFIX = 'pegwork-options-';

	/**
	 * First and last submenu positions reserved for options pages (docs/extension-api.md).
	 */
	public const FIRST_POSITION = 10;
	public const LAST_POSITION  = 19;

	/**
	 * Published posts listed in a post field's select when JavaScript is off.
	 */
	public const FALLBACK_POSTS = 100;

	/**
	 * Script and style handle of the post picker.
	 */
	public const HANDLE = 'pegwork-options-page';

	/**
	 * Hook suffix => options page key, for the screens added on this request.
	 *
	 * @var array<string, string>
	 */
	private static array $screens = array();

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menus' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Slug of a page's screen.
	 *
	 * @param string $key Options page key.
	 */
	public static function slug( string $key ): string {
		return self::SLUG_PREFIX . $key;
	}

	/**
	 * Adds one submenu per registered options page. Hooked to admin_menu (default priority).
	 */
	public static function add_menus(): void {
		$position = self::FIRST_POSITION;
		foreach ( Pages::registered() as $key ) {
			$page = Pages::page( $key );
			if ( null === $page ) {
				continue;
			}
			$hook = add_submenu_page(
				ADMIN_MENU,
				esc_html( $page['title'] ),
				// Core prints submenu titles unescaped; registry strings may come from the DB source.
				esc_html( $page['menu_title'] ?? $page['title'] ),
				$page['capability'],
				self::slug( $key ),
				fn() => self::render( $key ),
				min( $position, self::LAST_POSITION )
			);
			if ( is_string( $hook ) ) {
				self::$screens[ $hook ] = $key;
			}
			++$position;
		}
	}

	/**
	 * Enqueues the screen styles, and the post picker when the page has post fields.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public static function enqueue( string $hook_suffix ): void {
		$key = self::$screens[ $hook_suffix ] ?? null;
		if ( null === $key ) {
			return;
		}

		wp_register_style( self::HANDLE, false, array(), PEGWORK_VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style(
			self::HANDLE,
			'.pegwork-options .pegwork-field-error{color:#b32d2e;font-weight:600;margin:6px 0 0;}'
			. '.pegwork-options .pegwork-badge{display:inline-block;margin-left:6px;padding:0 6px;border:1px solid #8c8f94;border-radius:2px;font-size:12px;font-weight:400;line-height:1.6;}'
			. '.pegwork-options .pegwork-required{font-weight:400;}'
			. '.pegwork-options .pegwork-post-picker{max-width:25rem;}'
			// The combobox box is transparent outside the editor; give it the admin input look (white, same border).
			. '.pegwork-options .pegwork-post-picker .components-combobox-control__suggestions-container{background:#fff;border-color:#8c8f94;border-radius:4px;}'
			. '@media screen and (max-width:782px){.pegwork-options .pegwork-post-picker{max-width:none;}}'
			. '.pegwork-options .pegwork-post-picker__status{margin:4px 0 0;}'
			. '.pegwork-options .pegwork-notice-list{margin:.5em 0 .5em 1.5em;list-style:disc;}'
		);

		$page = Pages::page( $key );
		$post = array_filter( $page['fields'] ?? array(), fn( array $field ) => Validator::POST_TYPE_FIELD === $field['type'] );
		$file = PEGWORK_DIR . 'assets/build/options-page.asset.php';
		if ( ! $post || ! is_readable( $file ) ) {
			return;
		}
		$asset = require $file;
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/build/options-page.js', PEGWORK_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Prints a page's screen. Menu callback.
	 *
	 * @param string $key Options page key.
	 */
	public static function render( string $key ): void {
		$page = Pages::page( $key );
		if ( null === $page || ! current_user_can( $page['capability'] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pegwork' ), 403 );
		}

		$errors = array();
		foreach ( get_settings_errors( $key ) as $error ) {
			$errors[ $error['code'] ] = $error;
		}

		$slug    = self::slug( $key );
		$section = $slug . '-fields';
		add_settings_section( $section, '', '__return_null', $slug );
		$values = Values::stored( $key ) + Values::defaults( $page );
		foreach ( $page['fields'] as $field ) {
			$error = $errors[ Pages::error_code( $field['key'] ) ]['message'] ?? null;
			add_settings_field(
				$field['key'],
				self::label( $field ),
				fn() => self::control( $key, $field, $values[ $field['key'] ] ?? null, $error ),
				$slug,
				$section,
				array( 'label_for' => self::field_id( $key, $field['key'] ) )
			);
		}

		echo '<div class="wrap pegwork-admin pegwork-options">';
		echo '<h1>' . esc_html( $page['title'] ) . '</h1>';
		settings_errors( 'general' );
		self::notices( $key, $page, $errors );
		echo '<form method="post" action="options.php" novalidate>';
		settings_fields( Pages::group( $key ) );
		do_settings_sections( $slug );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Prints the error summary (each message links to its field) and success notices for this page.
	 *
	 * @param string                              $key    Options page key.
	 * @param array<string, mixed>                $page   Options page.
	 * @param array<string, array<string, mixed>> $errors Settings errors of the page by code.
	 */
	private static function notices( string $key, array $page, array $errors ): void {
		$items = array();
		foreach ( $page['fields'] as $field ) {
			$error = $errors[ Pages::error_code( $field['key'] ) ] ?? null;
			if ( null !== $error ) {
				$items[] = '<li><a href="#' . esc_attr( self::field_id( $key, $field['key'] ) ) . '">' . esc_html( (string) $error['message'] ) . '</a></li>';
			}
		}

		if ( $items ) {
			echo '<div class="notice notice-error" id="pegwork-options-errors"><p><strong>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of fields. */
					_n( '%d field was not saved:', '%d fields were not saved:', count( $items ), 'pegwork' ),
					count( $items )
				)
			);
			echo '</strong></p><ul class="pegwork-notice-list">' . implode( '', $items ) . '</ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Items are escaped above.
		}
		foreach ( $errors as $error ) {
			if ( 'success' === $error['type'] || 'updated' === $error['type'] ) {
				echo '<div class="notice notice-success"><p>' . esc_html( (string) $error['message'] ) . '</p></div>';
			}
		}
	}

	/**
	 * HTML id of a field's control.
	 *
	 * @param string $key   Options page key.
	 * @param string $field Field key.
	 */
	public static function field_id( string $key, string $field ): string {
		return 'pegwork-' . $key . '-' . $field;
	}

	/**
	 * Escaped label HTML for the row header: label, "(required)" and a "Private" badge in text.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function label( array $field ): string {
		$html = esc_html( (string) $field['label'] );
		if ( $field['required'] ) {
			$html .= ' <span class="pegwork-required">' . esc_html__( '(required)', 'pegwork' ) . '</span>';
		}
		if ( 'private' === $field['visibility'] ) {
			$html .= ' <span class="pegwork-badge">' . esc_html__( 'Private', 'pegwork' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Prints one field's control, description and error.
	 *
	 * @param string               $key   Options page key.
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Stored value, or the default.
	 * @param string|null          $error Error message from the last save (already escaped).
	 */
	private static function control( string $key, array $field, mixed $value, ?string $error ): void {
		$id        = self::field_id( $key, $field['key'] );
		$name      = $key . '[' . $field['key'] . ']';
		$described = array();
		$after     = '';

		if ( 'private' === $field['visibility'] ) {
			$described[] = $id . '-description';
			$after      .= '<p class="description" id="' . esc_attr( $id . '-description' ) . '">' . esc_html__( 'Private: used by the site, never shown to visitors.', 'pegwork' ) . '</p>';
		}
		if ( null !== $error ) {
			$described[] = $id . '-error';
			/* translators: %s: error message. */
			$after .= '<p class="pegwork-field-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( sprintf( __( 'Error: %s', 'pegwork' ), $error ) ) . '</p>';
		}

		$attrs = array(
			'id'               => $id,
			'name'             => $name,
			'aria-describedby' => $described ? implode( ' ', $described ) : null,
			'aria-invalid'     => null !== $error ? 'true' : null,
			'required'         => $field['required'] ? 'required' : null,
			'aria-required'    => $field['required'] ? 'true' : null,
		);
		$text  = is_scalar( $value ) ? (string) $value : '';

		switch ( $field['type'] ) {
			case 'textarea':
			case 'rich_text':
				$box = array(
					'rows'  => '6',
					'class' => 'large-text',
				);
				echo '<textarea' . self::attributes( $attrs + $box ) . '>' . esc_textarea( $text ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes.
				if ( 'rich_text' === $field['type'] ) {
					echo '<p class="description">' . esc_html__( 'HTML allowed in posts is kept; other markup is removed.', 'pegwork' ) . '</p>';
				}
				break;
			case 'boolean':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<input type="checkbox" value="1"' . self::attributes( $attrs ) . checked( true === $value || ( is_scalar( $value ) && rest_sanitize_boolean( $value ) ), true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes; checked() is a literal.
				break;
			case 'select':
				echo '<select' . self::attributes( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes.
				echo '<option value="">' . esc_html__( 'None', 'pegwork' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					echo '<option value="' . esc_attr( (string) $option['value'] ) . '"' . selected( (string) $option['value'], $text, false ) . '>' . esc_html( (string) ( $option['label'] ?? $option['value'] ) ) . '</option>';
				}
				echo '</select>';
				break;
			case Validator::POST_TYPE_FIELD:
				self::post_control( $field, $attrs, (int) ( is_numeric( $value ) ? $value : 0 ) );
				break;
			default:
				$type = match ( $field['type'] ) {
					'email'  => 'email',
					'url'    => 'url',
					'number' => 'number',
					'date'   => 'date',
					default  => 'text',
				};
				$extra = array(
					'type'  => $type,
					'value' => $text,
					'class' => match ( $type ) {
						'number' => 'small-text',
						'date'   => null,
						default  => 'regular-text',
					},
					'step'  => 'number' === $type ? 'any' : null,
				);
				echo '<input' . self::attributes( $extra + $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes.
		}

		echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
	}

	/**
	 * A post field: a select of published posts (works without JavaScript), which the post picker script
	 * turns into a searchable combobox.
	 *
	 * @param array<string, mixed>       $field   Post field.
	 * @param array<string, string|null> $attrs   Control attributes.
	 * @param int                        $current Stored post ID, 0 for none.
	 */
	private static function post_control( array $field, array $attrs, int $current ): void {
		$type         = get_post_type_object( $field['post_type'] );
		$posts        = get_posts(
			array(
				'post_type'        => $field['post_type'],
				'post_status'      => 'publish',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'numberposts'      => self::FALLBACK_POSTS,
				'suppress_filters' => false,
			)
		);
		$current_post = $current > 0 ? get_post( $current ) : null;
		$listed       = array_map( fn( \WP_Post $post ) => $post->ID, $posts );
		if ( $current_post instanceof \WP_Post && ! in_array( $current_post->ID, $listed, true ) ) {
			array_unshift( $posts, $current_post );
		}

		$title = fn( \WP_Post $post ) => '' !== $post->post_title ? $post->post_title : sprintf(
			/* translators: %d: post ID. */
			__( '(no title, #%d)', 'pegwork' ),
			$post->ID
		);

		$config = array(
			'restBase'      => $type && is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $field['post_type'],
			'restNamespace' => $type && is_string( $type->rest_namespace ?? null ) ? $type->rest_namespace : 'wp/v2',
			'singular'      => $type ? $type->labels->singular_name : $field['post_type'],
			'current'       => $current_post instanceof \WP_Post ? array(
				'id'    => $current_post->ID,
				'title' => $title( $current_post ),
			) : null,
		);

		echo '<div class="pegwork-post-picker" data-pegwork-post-picker="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';
		echo '<select' . self::attributes( $attrs ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes.
		echo '<option value="">' . esc_html__( 'None', 'pegwork' ) . '</option>';
		foreach ( $posts as $post ) {
			$label = $title( $post );
			if ( 'publish' !== $post->post_status ) {
				/* translators: %s: post title. */
				$label = sprintf( __( '%s (not published)', 'pegwork' ), $label );
			}
			echo '<option value="' . esc_attr( (string) $post->ID ) . '"' . selected( $post->ID, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		if ( $type && count( $posts ) >= self::FALLBACK_POSTS ) {
			echo '<p class="description pegwork-no-js-only">' . esc_html(
				sprintf(
					/* translators: %d: number of posts listed. */
					__( 'Without JavaScript only the first %d published items are listed.', 'pegwork' ),
					self::FALLBACK_POSTS
				)
			) . '</p>';
		}
	}

	/**
	 * Escaped HTML attributes; null values are left out.
	 *
	 * @param array<string, string|null> $attrs Attributes.
	 */
	private static function attributes( array $attrs ): string {
		$html = '';
		foreach ( $attrs as $name => $value ) {
			if ( null !== $value ) {
				$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
			}
		}

		return $html;
	}
}
