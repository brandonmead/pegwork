<?php
/**
 * Block editor panel for Pegwork fields, taxonomies and relationships.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

use Pegwork\Api\Registry;
use Pegwork\Content\Relationships;
use WP_Block_Editor_Context;
use WP_Post;

/**
 * Enqueues the editor panel script with its config, and limits post_content blocks.
 *
 * Both read the schema registry through pegwork_registry(). The config contract is documented
 * in docs/editor-panel.md.
 */
final class EditorPanel {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'pegwork-editor-panel';

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_block_types' ), 10, 2 );
	}

	/**
	 * Enqueues the panel on post editor screens for post types in the config.
	 */
	public static function enqueue(): void {
		$config    = self::config();
		$post_type = self::current_post_type();
		if ( null === $post_type || empty( $config['types'][ $post_type ] ) ) {
			return;
		}

		$asset_file = PEGWORK_DIR . 'assets/build/editor-panel.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/editor-panel.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkEditor = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );

		if ( is_readable( PEGWORK_DIR . 'assets/build/editor-panel.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				plugins_url( 'assets/build/editor-panel.css', PEGWORK_FILE ),
				array(),
				$asset['version']
			);
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * The panel config from the registry, after the `pegwork_editor_panel_config` filter.
	 *
	 * @return array{types: array<string, array<string, mixed>>} Config. No types when the filter returns a malformed value.
	 */
	public static function config(): array {
		$config = self::from_registry( pegwork_registry() );

		/**
		 * Filters the editor panel config localized as window.pegworkEditor.
		 *
		 * @param array $config Config: `types` keyed by post type, each with `fields`, `taxonomies` and `relationships`.
		 */
		$config = apply_filters( 'pegwork_editor_panel_config', $config );

		return is_array( $config ) && isset( $config['types'] ) && is_array( $config['types'] )
			? $config
			: array( 'types' => array() );
	}

	/**
	 * Builds the panel config from a registry, without the filter.
	 *
	 * @param Registry $registry Schema registry.
	 * @return array{types: array<string, array<string, mixed>>} Config.
	 */
	public static function from_registry( Registry $registry ): array {
		return self::build_config( $registry->content_types(), $registry->taxonomies(), $registry->relationships() );
	}

	/**
	 * Builds the panel config from registry data. Pure: no WordPress calls.
	 *
	 * @param array<array-key, array<string, mixed>> $content_types Content types (registry shape).
	 * @param array<string, array<string, mixed>>    $taxonomies    Taxonomies by key (registry shape), for labels.
	 * @param array<array-key, array<string, mixed>> $relationships Relationships (registry shape); each goes on its "from" type.
	 * @return array{types: array<string, array<string, mixed>>} Config.
	 */
	public static function build_config( array $content_types, array $taxonomies = array(), array $relationships = array() ): array {
		$types  = array();
		$by_key = array();

		foreach ( $content_types as $array_key => $type ) {
			$post_type            = (string) ( $type['key'] ?? $array_key );
			$by_key[ $post_type ] = $type;
			$fields               = array();
			$usages               = array();

			foreach ( $type['fields'] ?? array() as $field ) {
				$fields[] = self::field_config( $post_type, $field );
			}

			foreach ( $type['taxonomies'] ?? array() as $usage ) {
				$usages[] = self::taxonomy_config( $usage, $taxonomies[ $usage['key'] ] ?? array() );
			}

			$types[ $post_type ] = array(
				'fields'        => $fields,
				'taxonomies'    => $usages,
				'relationships' => array(),
			);
		}

		foreach ( $relationships as $array_key => $relationship ) {
			$from = (string) ( $relationship['from'] ?? '' );
			if ( isset( $types[ $from ] ) ) {
				$key                               = (string) ( $relationship['key'] ?? $array_key );
				$types[ $from ]['relationships'][] = self::relationship_config( $key, $relationship, $by_key[ $relationship['to'] ?? '' ] ?? array() );
			}
		}

		return array( 'types' => $types );
	}

	/**
	 * Default meta key of a field: `_{post_type}_{field}`.
	 *
	 * @param string $post_type Post type key.
	 * @param string $field     Field key.
	 */
	public static function meta_key( string $post_type, string $field ): string {
		return '_' . $post_type . '_' . $field;
	}

	/**
	 * Narrows allowed blocks for post types that declare `wp.allowed_blocks`.
	 *
	 * @param bool|string[]           $allowed Allowed block types: true for all, false for none, or a list.
	 * @param WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[] Allowed block types.
	 */
	public static function allowed_block_types( $allowed, $context ) {
		if ( ! $context instanceof WP_Block_Editor_Context || ! $context->post instanceof WP_Post ) {
			return $allowed;
		}

		return self::allowed_blocks_for( $allowed, pegwork_registry()->content_type( $context->post->post_type ) );
	}

	/**
	 * Applies a content type's `wp.allowed_blocks` to an allowed-blocks value. Pure.
	 *
	 * @param bool|string[]             $allowed      Allowed block types so far.
	 * @param array<string, mixed>|null $content_type Content type (registry shape), or null.
	 * @return bool|string[] The declared list, intersected with an existing list; unchanged when none is declared.
	 */
	public static function allowed_blocks_for( $allowed, ?array $content_type ) {
		$blocks = $content_type['wp']['allowed_blocks'] ?? null;
		if ( ! is_array( $blocks ) || array() === $blocks || false === $allowed ) {
			return $allowed;
		}

		if ( is_array( $allowed ) ) {
			return array_values( array_intersect( $allowed, $blocks ) );
		}

		return array_values( $blocks );
	}

	/**
	 * Config entry for one field.
	 *
	 * @param string               $post_type Post type key.
	 * @param array<string, mixed> $field     Field (registry shape).
	 * @return array<string, mixed> Field entry.
	 */
	private static function field_config( string $post_type, array $field ): array {
		$wp      = $field['wp'] ?? array();
		$storage = $wp['storage'] ?? 'meta';

		return array(
			'key'           => $field['key'],
			'type'          => $field['type'],
			'label'         => $field['label'] ?? $field['key'],
			'required'      => (bool) ( $field['required'] ?? false ),
			'visibility'    => 'private' === ( $field['visibility'] ?? 'public' ) ? 'private' : 'public',
			'meta_key'      => 'meta' === $storage ? ( $wp['meta_key'] ?? self::meta_key( $post_type, $field['key'] ) ) : null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Config key, not a query.
			'storage'       => $storage,
			'show_when'     => $field['show_when'] ?? null,
			'required_when' => $field['required_when'] ?? null,
			'validate'      => $field['validate'] ?? array(),
			'options'       => array_values( $field['options'] ?? array() ),
			'default'       => $field['default'] ?? null,
		);
	}

	/**
	 * Config entry for one taxonomy used by a content type.
	 *
	 * @param array<string, mixed> $usage    Taxonomy usage on the content type: key, select, required.
	 * @param array<string, mixed> $taxonomy Taxonomy definition (registry shape), or empty.
	 * @return array<string, mixed> Taxonomy entry.
	 */
	private static function taxonomy_config( array $usage, array $taxonomy ): array {
		$select = 'single' === ( $usage['select'] ?? 'multi' ) ? 'single' : 'multi';
		$labels = $taxonomy['labels'] ?? array();
		$label  = 'single' === $select
			? ( $labels['singular'] ?? $usage['key'] )
			: ( $labels['plural'] ?? $labels['singular'] ?? $usage['key'] );

		return array(
			'key'       => $usage['key'],
			'rest_base' => $taxonomy['wp']['rest_base'] ?? $usage['key'],
			'select'    => $select,
			'required'  => (bool) ( $usage['required'] ?? false ),
			'label'     => $label,
		);
	}

	/**
	 * Config entry for one relationship, on its "from" type.
	 *
	 * Labels follow Relationships::label(): the relationship's `label`, else the "to" type's plural label.
	 *
	 * @param string               $key          Relationship key.
	 * @param array<string, mixed> $relationship Relationship (registry shape).
	 * @param array<string, mixed> $to_type      The "to" content type (registry shape), or empty.
	 * @return array<string, mixed> Relationship entry.
	 */
	private static function relationship_config( string $key, array $relationship, array $to_type ): array {
		$to     = (string) ( $relationship['to'] ?? '' );
		$labels = $to_type['labels'] ?? array();

		return array(
			'key'               => $key,
			'meta_key'          => Relationships::meta_key( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Config key, not a query.
			'to'                => $to,
			'label'             => $relationship['label'] ?? $labels['plural'] ?? $to,
			'to_label_singular' => $labels['singular'] ?? $to,
			'cardinality'       => 'one_to_many' === ( $relationship['cardinality'] ?? '' ) ? 'one_to_many' : 'many_to_many',
			'required'          => (bool) ( $relationship['required'] ?? false ),
		);
	}

	/**
	 * Post type of the current editor screen.
	 *
	 * @return string|null Post type, or null outside a post editor.
	 */
	private static function current_post_type(): ?string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && '' !== $screen->post_type ? $screen->post_type : null;
	}
}
