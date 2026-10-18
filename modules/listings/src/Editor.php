<?php
/**
 * Block editor script for the listings module.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

/**
 * Enqueues the listings editor script (binding source preview, Show if edit UI) with its config.
 *
 * The config holds public fields only, so the editor can never offer or preview a private field.
 */
final class Editor {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'pegwork-listings-editor';

	/**
	 * Most terms listed per taxonomy in the config.
	 */
	private const MAX_TERMS = 200;

	/**
	 * Registers the hook. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Enqueues the script in every block editor (post, site, widgets) when it is built.
	 */
	public static function enqueue(): void {
		$asset_file = PEGWORK_DIR . 'assets/build/listings-editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/listings-editor.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkListings = ' . wp_json_encode( self::config() ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * The editor config: public fields and taxonomies (with terms) per content type.
	 *
	 * @return array<string, mixed> Config.
	 */
	public static function config(): array {
		$registry = pegwork_registry();
		$types    = array();

		foreach ( $registry->content_types() as $type_key => $type ) {
			$fields = array();
			foreach ( $registry->fields( $type_key, 'public' ) as $field ) {
				$fields[] = array(
					'key'      => $field['key'],
					'type'     => $field['type'],
					'label'    => $field['label'],
					'storage'  => $field['wp']['storage'] ?? 'meta',
					'metaKey'  => $registry->meta_key( $type_key, $field['key'] ),
					'options'  => array_values( $field['options'] ?? array() ),
					'showWhen' => $field['show_when'] ?? null,
				);
			}

			$taxonomies = array();
			foreach ( $type['taxonomies'] ?? array() as $usage ) {
				$taxonomy     = $registry->taxonomies()[ $usage['key'] ] ?? array();
				$taxonomies[] = array(
					'key'   => $usage['key'],
					'label' => $taxonomy['labels']['singular'] ?? $usage['key'],
					'terms' => self::terms( $usage['key'] ),
				);
			}

			$types[ $type_key ] = array(
				'label'      => $type['labels']['singular'] ?? $type_key,
				'fields'     => $fields,
				'taxonomies' => $taxonomies,
			);
		}

		return array(
			'dateFormat' => (string) get_option( 'date_format' ),
			'types'      => $types,
		);
	}

	/**
	 * Terms of a taxonomy as slug and name.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @return array<int, array{slug: string, name: string}>
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_TERMS,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$list = array();
		foreach ( $terms as $term ) {
			$list[] = array(
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}
		return $list;
	}
}
