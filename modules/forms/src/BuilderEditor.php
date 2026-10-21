<?php
/**
 * Block editor assets for the form builder.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use WP_Block_Type_Registry;
use WP_Post;

/**
 * Registers the builder blocks and, on `pegwork_form` editor screens only, enqueues the builder
 * script with its config: registry content types (fields, rules and taxonomies, for bound
 * variations and read-only rule summaries), whether Turnstile keys are set, and whether the form is
 * file-defined (read-only, with a Duplicate link). Also prefills "Duplicate as site form".
 */
final class BuilderEditor {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'pegwork-forms-builder';

	/**
	 * Nonce action of the Duplicate link.
	 */
	public const DUPLICATE_NONCE = 'pegwork_form_duplicate';

	/**
	 * Registers blocks and hooks. Safe to call more than once.
	 */
	public static function register(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$blocks   = array(
			Builder::FIELD => 'form-field',
			Builder::STEP  => 'form-step',
		);
		foreach ( $blocks as $name => $dir ) {
			if ( ! $registry->is_registered( $name ) ) {
				// Builder blocks only describe a definition; stray copies in other content render nothing.
				register_block_type( PEGWORK_FORMS_DIR . 'blocks/' . $dir, array( 'render_callback' => '__return_empty_string' ) );
			}
		}

		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
			add_action( 'enqueue_block_assets', array( self::class, 'enqueue_style' ) );
			add_action( 'load-post-new.php', array( self::class, 'prepare_duplicate' ) );
			add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
		}
	}

	/**
	 * Adds `pegwork-form-readonly` to the editor body of a file form, for the read-only styles: the
	 * inserter button is hidden (FormPost::editor_settings() already locks every block, but core keeps
	 * the button enabled) and so is the "Last edited" line (the post is regenerated from the file, so
	 * that time says nothing). Hooked to admin_body_class.
	 *
	 * @param string $classes Space-separated classes.
	 */
	public static function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post    = get_post();
		if ( null === $screen || 'post' !== $screen->base || FormPost::TYPE !== $screen->post_type || ! $post instanceof WP_Post ) {
			return $classes;
		}
		$key = FormPost::key_of( $post->ID );
		return '' !== $key && FormPost::is_file_form( $key ) ? $classes . ' pegwork-form-readonly' : $classes;
	}

	/**
	 * Enqueues the builder on form editor screens when it is built.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( null === $screen || FormPost::TYPE !== $screen->post_type || ! $post instanceof WP_Post ) {
			return;
		}
		$asset_file = PEGWORK_DIR . 'assets/build/forms-builder.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/forms-builder.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkFormBuilder = ' . wp_json_encode( self::config( $post ) ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * Enqueues the builder stylesheet on form editor screens. Hooked to enqueue_block_assets, which
	 * the block editor also runs for its iframed canvas (enqueue_block_editor_assets styles stay
	 * outside the iframe), so the field and step blocks are styled in the canvas and the sidebar.
	 */
	public static function enqueue_style(): void {
		if ( ! is_admin() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || FormPost::TYPE !== $screen->post_type || ! is_readable( PEGWORK_DIR . 'assets/build/forms-builder.css' ) ) {
			return;
		}
		$asset_file = PEGWORK_DIR . 'assets/build/forms-builder.asset.php';
		$version    = is_readable( $asset_file ) ? ( require $asset_file )['version'] : null;
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/forms-builder.css', PEGWORK_FILE ), array(), $version );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * The builder config for a form post.
	 *
	 * @param WP_Post $post Form post.
	 * @return array<string, mixed>
	 */
	public static function config( WP_Post $post ): array {
		$key    = FormPost::key_of( $post->ID );
		$source = '' === $key ? null : pegwork_registry()->form_source( $key );
		$file   = null !== $source && 'db' !== $source;

		return array(
			'types'        => self::types(),
			'turnstile'    => self::turnstile_keys_set(),
			'lockedKey'    => $key,
			'readOnly'     => $file,
			'package'      => $file ? substr( (string) $source, 5 ) : '',
			'duplicateUrl' => $file ? self::duplicate_url( $key ) : '',
			'formsUrl'     => FormsAdmin::url(),
		);
	}

	/**
	 * Registry content types a form can target, with what bound items inherit.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function types(): array {
		$registry   = pegwork_registry();
		$taxonomies = $registry->taxonomies();
		$types      = array();
		foreach ( $registry->content_types() as $type_key => $type ) {
			$fields = array();
			foreach ( $registry->fields( (string) $type_key ) as $field ) {
				if ( 'post' === ( $field['type'] ?? '' ) ) {
					continue;
				}
				$fields[] = array_filter(
					array(
						'key'           => (string) $field['key'],
						'label'         => (string) $field['label'],
						'type'          => (string) $field['type'],
						'required'      => (bool) ( $field['required'] ?? false ),
						'required_when' => $field['required_when'] ?? null,
						'show_when'     => $field['show_when'] ?? null,
						'validate'      => $field['validate'] ?? null,
						'options'       => $field['options'] ?? null,
						'private'       => 'private' === ( $field['visibility'] ?? 'public' ),
					),
					fn( $value ) => null !== $value && array() !== $value
				);
			}
			$usages = array();
			foreach ( $type['taxonomies'] ?? array() as $usage ) {
				$definition = $taxonomies[ $usage['key'] ] ?? array();
				$multiple   = 'multi' === ( $usage['select'] ?? 'multi' );
				$labels     = $definition['labels'] ?? array();
				$usages[]   = array(
					'key'      => (string) $usage['key'],
					'label'    => (string) ( $multiple ? ( $labels['plural'] ?? $usage['key'] ) : ( $labels['singular'] ?? $usage['key'] ) ),
					'multiple' => $multiple,
					'required' => (bool) ( $usage['required'] ?? false ),
					'options'  => array_map(
						fn( $term ) => array(
							'value' => (string) $term['slug'],
							'label' => (string) $term['name'],
						),
						$definition['terms'] ?? array()
					),
				);
			}
			$types[] = array(
				'key'        => (string) $type_key,
				'label'      => (string) ( $type['labels']['singular'] ?? $type_key ),
				'fields'     => $fields,
				'taxonomies' => $usages,
			);
		}
		return $types;
	}

	/**
	 * Whether both Turnstile keys are set in wp-config.php (the same check the runtime makes).
	 */
	public static function turnstile_keys_set(): bool {
		return null !== Turnstile::keys();
	}

	/**
	 * The "Duplicate as site form" link for a form.
	 *
	 * @param string $key Form key.
	 */
	public static function duplicate_url( string $key ): string {
		return add_query_arg(
			array(
				'post_type'         => FormPost::TYPE,
				'pegwork_duplicate' => $key,
				'_wpnonce'          => wp_create_nonce( self::DUPLICATE_NONCE ),
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * On post-new.php with `pegwork_duplicate`, prefills the new form from that definition under a
	 * free key. Nothing is saved until the user saves. Hooked to load-post-new.php.
	 */
	public static function prepare_duplicate(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified just below.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$key  = isset( $_GET['pegwork_duplicate'] ) ? sanitize_key( wp_unslash( $_GET['pegwork_duplicate'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( FormPost::TYPE !== $type || '' === $key ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces are compared, not stored or printed.
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DUPLICATE_NONCE ) || ! current_user_can( FormPost::CAP ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Go back, reload the page and try again.', 'pegwork' ), '', array( 'response' => 403 ) );
		}
		$form = pegwork_registry()->form( $key );
		if ( null === $form ) {
			return;
		}

		$generated                    = Builder::generate( $form );
		$generated['settings']['key'] = self::free_key( $key . '_copy' );
		/* translators: %s: form label. */
		$title = sprintf( __( '%s (copy)', 'pegwork' ), $generated['title'] );

		add_filter( 'default_title', static fn( $value, $post ) => $post instanceof WP_Post && FormPost::TYPE === $post->post_type ? $title : $value, 10, 2 );
		add_filter( 'default_content', static fn( $value, $post ) => $post instanceof WP_Post && FormPost::TYPE === $post->post_type ? $generated['content'] : $value, 10, 2 );
		add_action(
			'wp_insert_post',
			static function ( $post_id, $post ) use ( $generated ) {
				if ( $post instanceof WP_Post && FormPost::TYPE === $post->post_type && 'auto-draft' === $post->post_status ) {
					update_post_meta( (int) $post_id, FormPost::META_SETTINGS, wp_slash( FormPost::settings_json( $generated['settings'] ) ) );
				}
			},
			10,
			2
		);
	}

	/**
	 * A key no registry form uses: the base, else base_2, base_3 and so on (at most 64 characters).
	 *
	 * @param string $base Wanted key.
	 */
	public static function free_key( string $base ): string {
		$base = substr( $base, 0, 60 );
		$key  = $base;
		$n    = 1;
		while ( null !== pegwork_registry()->form( $key ) ) {
			$key = $base . '_' . ( ++$n );
		}
		return $key;
	}
}
