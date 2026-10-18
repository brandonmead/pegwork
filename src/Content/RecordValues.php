<?php
/**
 * Reads a record's stored values by registry key.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use WP_Post;

/**
 * Stored field values and term slugs of a record, typed so that equal values compare equal.
 *
 * Shared by the Records API (reads) and record events (snapshots and diffs).
 */
final class RecordValues {

	/**
	 * Field values by field key (all visibilities), then sorted term slug lists by taxonomy key.
	 *
	 * Booleans are bool, numbers int, float or null when empty, everything else a string.
	 *
	 * @param Registry $registry Effective registry.
	 * @param WP_Post  $post     Record of a registry content type.
	 * @return array<string, mixed>
	 */
	public static function read( Registry $registry, WP_Post $post ): array {
		$type   = $post->post_type;
		$values = array();

		foreach ( $registry->fields( $type ) as $key => $field ) {
			$values[ $key ] = self::field( $post, $type, $field );
		}

		foreach ( $registry->content_type( $type )['taxonomies'] ?? array() as $usage ) {
			$values[ $usage['key'] ] = self::terms( $post->ID, $usage['key'] );
		}

		return $values;
	}

	/**
	 * Stored value of one field.
	 *
	 * @param WP_Post              $post  Record.
	 * @param string               $type  Content type key.
	 * @param array<string, mixed> $field Normalized field.
	 */
	public static function field( WP_Post $post, string $type, array $field ): mixed {
		$storage = $field['wp']['storage'] ?? null;
		$raw     = null === $storage ? get_post_meta( $post->ID, Meta::key( $type, $field ), true ) : $post->$storage;

		return self::cast( $field, $raw );
	}

	/**
	 * Casts a stored or sanitized value to its field type.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @param mixed                $raw   Raw value.
	 */
	public static function cast( array $field, mixed $raw ): mixed {
		return match ( $field['type'] ) {
			'boolean' => rest_sanitize_boolean( $raw ),
			'number'  => is_numeric( $raw ) ? 0 + $raw : null,
			default   => is_scalar( $raw ) ? (string) $raw : '',
		};
	}

	/**
	 * Sorted slugs of the terms a record has in a taxonomy.
	 *
	 * @param int    $id       Post ID.
	 * @param string $taxonomy Taxonomy key.
	 * @return string[]
	 */
	public static function terms( int $id, string $taxonomy ): array {
		$slugs = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
		$slugs = is_array( $slugs ) ? array_map( 'strval', $slugs ) : array();
		sort( $slugs );

		return $slugs;
	}
}
