<?php
/**
 * Block editor assets for the automation builder.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Editor;

use Pegwork\Api\Registry;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Webhooks\Inbound;
use WP_Block_Type_Registry;
use WP_Post;

/**
 * Registers the builder blocks and, on `pegwork_automation` editor screens only, enqueues the builder
 * script with its config (whether the automation is file-defined, with its Duplicate link). Prefills
 * "Add automation" (`enabled: false`) and "Duplicate as site automation" (key `<key>_copy`,
 * `enabled: false`). Nothing is saved until the user saves.
 */
final class BuilderEditor {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'pegwork-automation-builder';

	/**
	 * Nonce action of the Duplicate link.
	 */
	public const DUPLICATE_NONCE = 'pegwork_automation_duplicate';

	/**
	 * Block category slug (shared with the forms and listings modules).
	 */
	public const CATEGORY = 'pegwork';

	/**
	 * Registers blocks and hooks. Safe to call more than once.
	 */
	public static function register(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$blocks   = array(
			Builder::STEP     => 'automation-step',
			Builder::DECISION => 'automation-decision',
			Builder::BRANCH   => 'automation-branch',
		);
		foreach ( $blocks as $name => $dir ) {
			if ( ! $registry->is_registered( $name ) ) {
				// Builder blocks only describe a definition; stray copies in other content render nothing.
				register_block_type( PEGWORK_AUTOMATION_DIR . 'blocks/' . $dir, array( 'render_callback' => '__return_empty_string' ) );
			}
		}

		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
			add_action( 'enqueue_block_assets', array( self::class, 'enqueue_style' ) );
			add_action( 'load-post-new.php', array( self::class, 'prepare_new' ) );
			add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
			add_filter( 'block_categories_all', array( self::class, 'block_categories' ) );
		}
	}

	/**
	 * Adds the "Pegwork" block category once (the forms and listings modules add the same one).
	 *
	 * @param array<int, array<string, mixed>> $categories Block categories.
	 * @return array<int, array<string, mixed>> Categories with Pegwork's.
	 */
	public static function block_categories( $categories ): array {
		$categories = is_array( $categories ) ? $categories : array();
		if ( in_array( self::CATEGORY, array_column( $categories, 'slug' ), true ) ) {
			return $categories;
		}

		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Pegwork', 'pegwork' ),
			'icon'  => null,
		);
		return $categories;
	}

	/**
	 * Adds `pegwork-automation-readonly` to the editor body of a file automation, for the read-only
	 * styles (core keeps the inserter button enabled under `templateLock: all`). Hooked to admin_body_class.
	 *
	 * @param string $classes Space-separated classes.
	 */
	public static function body_class( $classes ): string {
		$classes = is_string( $classes ) ? $classes : '';
		$post    = get_post();
		if ( ! AutomationPost::on_editor_screen() || ! $post instanceof WP_Post ) {
			return $classes;
		}
		$key = AutomationPost::key_of( $post->ID );
		return '' !== $key && AutomationPost::is_file_automation( $key ) ? $classes . ' pegwork-automation-readonly' : $classes;
	}

	/**
	 * Enqueues the builder on automation editor screens when it is built.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( null === $screen || AutomationPost::TYPE !== $screen->post_type || ! $post instanceof WP_Post ) {
			return;
		}
		$asset_file = PEGWORK_DIR . 'assets/build/automation-builder.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/automation-builder.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkAutomationBuilder = ' . wp_json_encode( self::config( $post ) ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * Enqueues the builder stylesheet on automation editor screens. Hooked to enqueue_block_assets,
	 * which the block editor also runs for its iframed canvas. Depends on dashicons, which the canvas
	 * does not load by itself, for the card icons.
	 */
	public static function enqueue_style(): void {
		if ( ! is_admin() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || AutomationPost::TYPE !== $screen->post_type || ! is_readable( PEGWORK_DIR . 'assets/build/automation-builder.css' ) ) {
			return;
		}
		$asset_file = PEGWORK_DIR . 'assets/build/automation-builder.asset.php';
		$version    = is_readable( $asset_file ) ? ( require $asset_file )['version'] : null;
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/build/automation-builder.css', PEGWORK_FILE ), array( 'dashicons' ), $version );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
	}

	/**
	 * The builder config for an automation post.
	 *
	 * @param WP_Post $post Automation post.
	 * @return array<string, mixed>
	 */
	public static function config( WP_Post $post ): array {
		$key    = AutomationPost::key_of( $post->ID );
		$source = '' === $key ? null : pegwork_registry()->automation_source( $key );
		$file   = null !== $source && 'db' !== $source;
		$open   = 0;
		if ( null !== $source ) {
			$engine = Engine::instance();
			$counts = $engine->runs()->summary( $engine->runs()->stamp( $engine->clock()->now() ) )[ $key ] ?? array();
			$open   = (int) ( $counts['queued'] ?? 0 ) + (int) ( $counts['waiting'] ?? 0 ) + (int) ( $counts['running'] ?? 0 );
		}
		$rest = array();
		foreach ( array_keys( pegwork_registry()->content_types() ) as $type ) {
			$object = get_post_type_object( (string) $type );
			if ( null !== $object && $object->show_in_rest ) {
				$rest[ (string) $type ] = (string) ( $object->rest_base ? $object->rest_base : $type );
			}
		}

		return array(
			'lockedKey'    => $key,
			'readOnly'     => $file,
			'package'      => $file ? substr( (string) $source, 5 ) : '',
			'duplicateUrl' => $file ? self::duplicate_url( $key ) : '',
			'listUrl'      => Screen::url(),
			'webhookUrl'   => null !== $source ? Inbound::url( $key ) : '',
			'override'     => null !== $source ? (string) Overrides::get( $key ) : '',
			'openRuns'     => $open,
			'rest'         => (object) $rest,
		) + self::registry_config( pegwork_registry() );
	}

	/**
	 * What the builder needs from the registry to list fields, merge tags and operators: content types
	 * (fields with type, options and privacy; taxonomies with single or multiple select and terms),
	 * forms (target, confirm, items with privacy from the target type) and options pages. The shared
	 * fixture tests/fixtures/automation/scope.json holds this shape for the branching fixture package.
	 *
	 * @param Registry $registry Registry.
	 * @return array{types: list<array<string, mixed>>, forms: list<array<string, mixed>>, optionsPages: list<array<string, mixed>>}
	 */
	public static function registry_config( Registry $registry ): array {
		$taxonomies = $registry->taxonomies();
		$options    = static fn( $choices ) => array_map(
			static fn( $option ) => array(
				'value' => (string) $option['value'],
				'label' => (string) ( $option['label'] ?? $option['value'] ),
			),
			is_array( $choices ) ? array_values( $choices ) : array()
		);
		$field      = static fn( array $definition ) => array_filter(
			array(
				'key'     => (string) $definition['key'],
				'label'   => (string) ( $definition['label'] ?? $definition['key'] ),
				'type'    => (string) $definition['type'],
				'options' => isset( $definition['options'] ) ? $options( $definition['options'] ) : null,
				'private' => 'private' === ( $definition['visibility'] ?? 'public' ) ? true : null,
			),
			static fn( $value ) => null !== $value
		);

		$types = array();
		foreach ( $registry->content_types() as $type_key => $type ) {
			$usages = array();
			foreach ( $type['taxonomies'] ?? array() as $usage ) {
				$definition = $taxonomies[ $usage['key'] ] ?? array();
				$multiple   = 'multi' === ( $usage['select'] ?? 'multi' );
				$labels     = $definition['labels'] ?? array();
				$usages[]   = array(
					'key'      => (string) $usage['key'],
					'label'    => (string) ( $multiple ? ( $labels['plural'] ?? $usage['key'] ) : ( $labels['singular'] ?? $usage['key'] ) ),
					'multiple' => $multiple,
					'options'  => array_map(
						static fn( $term ) => array(
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
				'fields'     => array_map( $field, array_values( $registry->fields( (string) $type_key ) ) ),
				'taxonomies' => $usages,
			);
		}

		$forms = array();
		foreach ( $registry->forms() as $form_key => $form ) {
			$target = (string) ( $form['target']['content_type'] ?? '' );
			$fields = $registry->fields( $target );
			$items  = array();
			$lists  = isset( $form['steps'] ) ? array_column( $form['steps'], 'fields' ) : array( $form['fields'] ?? array() );
			foreach ( $lists as $list ) {
				foreach ( $list as $item ) {
					$bound = $item['bind']['field'] ?? null;
					$key   = (string) ( $bound ?? ( $item['bind']['taxonomy'] ?? ( $item['key'] ?? '' ) ) );
					if ( '' === $key ) {
						continue;
					}
					$items[] = array_filter(
						array(
							'key'     => $key,
							'label'   => (string) ( $item['label'] ?? ( $fields[ $key ]['label'] ?? $key ) ),
							'private' => null !== $bound && 'private' === ( $fields[ $key ]['visibility'] ?? 'public' ) ? true : null,
						),
						static fn( $value ) => null !== $value
					);
				}
			}
			$forms[] = array(
				'key'     => (string) $form_key,
				'label'   => (string) ( $form['label'] ?? $form_key ),
				'target'  => $target,
				'confirm' => isset( $form['confirm'] ),
				'fields'  => $items,
			);
		}

		$pages = array();
		foreach ( $registry->options_pages() as $page_key => $page ) {
			$pages[] = array(
				'key'    => (string) $page_key,
				'label'  => (string) ( $page['title'] ?? $page_key ),
				'fields' => array_map( $field, array_values( $page['fields'] ?? array() ) ),
			);
		}

		return array(
			'types'        => $types,
			'forms'        => $forms,
			'optionsPages' => $pages,
		);
	}

	/**
	 * The "Duplicate as site automation" link for an automation.
	 *
	 * @param string $key Automation key.
	 */
	public static function duplicate_url( string $key ): string {
		return add_query_arg(
			array(
				'post_type'         => AutomationPost::TYPE,
				'pegwork_duplicate' => $key,
				'_wpnonce'          => wp_create_nonce( self::DUPLICATE_NONCE ),
			),
			admin_url( 'post-new.php' )
		);
	}

	/**
	 * On post-new.php for automations: starts the settings with `enabled: false`, or, with
	 * `pegwork_duplicate`, prefills the new automation from that definition under a free key, also
	 * disabled. Hooked to load-post-new.php.
	 */
	public static function prepare_new(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified just below before anything is read from the registry.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$key  = isset( $_GET['pegwork_duplicate'] ) ? sanitize_key( wp_unslash( $_GET['pegwork_duplicate'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( AutomationPost::TYPE !== $type ) {
			return;
		}

		$settings = array( 'enabled' => false );
		if ( '' !== $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces are compared, not stored or printed.
			$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, self::DUPLICATE_NONCE ) || ! current_user_can( AutomationPost::CAP ) ) {
				wp_die( esc_html__( 'The link you followed has expired. Go back, reload the page and try again.', 'pegwork' ), '', array( 'response' => 403 ) );
			}
			$automation = pegwork_registry()->automation( $key );
			if ( null !== $automation ) {
				$generated = Builder::generate( $automation );
				$settings  = array( 'key' => self::free_key( $key . '_copy' ) ) + $generated['settings'];
				/* translators: %s: automation label. */
				$title = sprintf( __( '%s (copy)', 'pegwork' ), $generated['title'] );
				add_filter( 'default_title', static fn( $value, $post ) => $post instanceof WP_Post && AutomationPost::TYPE === $post->post_type ? $title : $value, 10, 2 );
				add_filter( 'default_content', static fn( $value, $post ) => $post instanceof WP_Post && AutomationPost::TYPE === $post->post_type ? $generated['content'] : $value, 10, 2 );
			}
			$settings['enabled'] = false;
		}

		add_action(
			'wp_insert_post',
			static function ( $post_id, $post ) use ( $settings ) {
				if ( $post instanceof WP_Post && AutomationPost::TYPE === $post->post_type && 'auto-draft' === $post->post_status ) {
					update_post_meta( (int) $post_id, AutomationPost::META_SETTINGS, wp_slash( AutomationPost::settings_json( $settings ) ) );
				}
			},
			10,
			2
		);
	}

	/**
	 * A key no registry automation uses: the base, else base_2, base_3 and so on (at most 64 characters).
	 *
	 * @param string $base Wanted key.
	 */
	public static function free_key( string $base ): string {
		$base = substr( $base, 0, 60 );
		$key  = $base;
		$n    = 1;
		while ( null !== pegwork_registry()->automation( $key ) ) {
			$key = $base . '_' . ( ++$n );
		}
		return $key;
	}
}
