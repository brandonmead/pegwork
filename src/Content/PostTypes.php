<?php
/**
 * Registers content types as WordPress post types.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;

/**
 * Maps each normalized content type to register_post_type().
 */
final class PostTypes {

	/**
	 * Post types registered by the last call, so ones that leave the registry are unregistered.
	 *
	 * @var string[]
	 */
	private static array $registered = array();

	/**
	 * Registers every content type in the registry and unregisters ones that were removed.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		$types = $registry->content_types();

		foreach ( array_diff( self::$registered, array_keys( $types ) ) as $gone ) {
			unregister_post_type( $gone );
		}
		self::$registered = array();

		foreach ( $types as $key => $type ) {
			$result = register_post_type( $key, self::args( $type ) );
			if ( ! is_wp_error( $result ) ) {
				self::$registered[] = $key;
			}
		}
	}

	/**
	 * Builds register_post_type() arguments from a normalized content type.
	 *
	 * @param array<string, mixed> $type Normalized content type.
	 * @return array<string, mixed>
	 */
	public static function args( array $type ): array {
		$wp = $type['wp'] ?? array();

		$args = array(
			'labels'          => self::labels( $type['labels'] ),
			'public'          => $type['public'],
			'show_in_rest'    => true,
			'rest_base'       => $wp['rest_base'] ?? $type['key'],
			'has_archive'     => $wp['has_archive'] ?? false,
			'rewrite'         => array( 'slug' => $wp['rewrite_slug'] ?? $type['key'] ),
			'supports'        => $wp['supports'] ?? array( 'title', 'editor' ),
			'capability_type' => $wp['capability_type'] ?? 'post',
			'map_meta_cap'    => true,
		);

		// Core exposes registered meta over REST only for types that support custom-fields.
		$has_meta = array() !== array_filter( $type['fields'], fn( array $field ) => ! isset( $field['wp']['storage'] ) );
		if ( $has_meta && ! in_array( 'custom-fields', $args['supports'], true ) ) {
			$args['supports'][] = 'custom-fields';
		}

		if ( isset( $wp['menu_icon'] ) ) {
			$args['menu_icon'] = $wp['menu_icon'];
		}

		return $args;
	}

	/**
	 * Builds post type labels from the singular and plural names.
	 *
	 * @param array{singular: string, plural: string} $labels Registry labels.
	 * @return array<string, string>
	 */
	private static function labels( array $labels ): array {
		$one  = $labels['singular'];
		$many = $labels['plural'];

		return array(
			'name'               => $many,
			'singular_name'      => $one,
			'menu_name'          => $many,
			/* translators: %s: plural content type name. */
			'all_items'          => sprintf( __( 'All %s', 'pegwork' ), $many ),
			/* translators: %s: singular content type name. */
			'add_new_item'       => sprintf( __( 'Add New %s', 'pegwork' ), $one ),
			/* translators: %s: singular content type name. */
			'new_item'           => sprintf( __( 'New %s', 'pegwork' ), $one ),
			/* translators: %s: singular content type name. */
			'edit_item'          => sprintf( __( 'Edit %s', 'pegwork' ), $one ),
			/* translators: %s: singular content type name. */
			'view_item'          => sprintf( __( 'View %s', 'pegwork' ), $one ),
			/* translators: %s: plural content type name. */
			'view_items'         => sprintf( __( 'View %s', 'pegwork' ), $many ),
			/* translators: %s: plural content type name. */
			'search_items'       => sprintf( __( 'Search %s', 'pegwork' ), $many ),
			/* translators: %s: plural content type name. */
			'not_found'          => sprintf( __( 'No %s found.', 'pegwork' ), $many ),
			/* translators: %s: plural content type name. */
			'not_found_in_trash' => sprintf( __( 'No %s found in Trash.', 'pegwork' ), $many ),
			/* translators: %s: singular content type name. */
			'archives'           => sprintf( __( '%s Archives', 'pegwork' ), $one ),
		);
	}
}
