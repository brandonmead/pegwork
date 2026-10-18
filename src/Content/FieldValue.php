<?php
/**
 * Typed field values for pegwork_get_field().
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use WP_Post;

/**
 * Reads one field of a record as a typed value, enforcing the private-field rule.
 */
final class FieldValue {

	/**
	 * Reads a field value.
	 *
	 * Private fields return null unless `$context` is `edit` and the current user can edit the post.
	 *
	 * @param Registry $registry Effective registry.
	 * @param WP_Post  $post     Record.
	 * @param string   $key      Field key.
	 * @param string   $context  `view` or `edit`.
	 * @return mixed Boolean fields bool; number fields int, float or null when unset; others string. Null for an unknown field or a refused private field.
	 */
	public static function get( Registry $registry, WP_Post $post, string $key, string $context = 'view' ): mixed {
		$field = $registry->fields( $post->post_type )[ $key ] ?? null;
		if ( null === $field ) {
			return null;
		}

		if ( 'private' === $field['visibility'] && ( 'edit' !== $context || ! current_user_can( 'edit_post', $post->ID ) ) ) {
			return null;
		}

		$storage = $field['wp']['storage'] ?? null;
		if ( null !== $storage ) {
			return (string) $post->{$storage};
		}

		$meta_key = (string) $registry->meta_key( $post->post_type, $key );
		$raw      = metadata_exists( 'post', $post->ID, $meta_key )
			? get_post_meta( $post->ID, $meta_key, true )
			: ( $field['default'] ?? null );

		return self::cast( $field['type'], $raw );
	}

	/**
	 * Casts a stored value to its field type.
	 *
	 * @param string $type Field type.
	 * @param mixed  $raw  Stored value.
	 * @return mixed Typed value.
	 */
	public static function cast( string $type, mixed $raw ): mixed {
		if ( 'boolean' === $type ) {
			return null !== $raw && rest_sanitize_boolean( $raw );
		}

		if ( 'number' === $type ) {
			$number = Meta::sanitize_number( $raw );
			return '' === $number ? null : $number;
		}

		return is_scalar( $raw ) ? (string) $raw : '';
	}
}
