<?php
/**
 * Public records API.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

use Pegwork\Content\Meta;
use Pegwork\Content\RecordValidator;
use Pegwork\Content\RecordValues;
use Pegwork\Events\RecordEvents;
use Pegwork\Plugin;
use WP_Error;

/**
 * Reads and writes records of registry content types by registry key. Part of the semver-stable public API.
 *
 * Writes sanitize each value for its field type, validate the merged record as a REST save would
 * (completeness rules only when the resulting status is publish, pending or future) and emit one
 * record event. Writes do not check the current user's capabilities: callers authorize by their own rules.
 */
final class Records {

	/**
	 * Reads a record.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed>|null `id`, `type`, `status`, `values` (field values by key, private ones
	 *                                   included; sorted term slugs by taxonomy key) and `permalink`, or null
	 *                                   when the post does not exist or is not of a registry content type.
	 */
	public static function get( int $id ): ?array {
		$post     = get_post( $id );
		$registry = Plugin::registry();
		if ( ! $post || null === $registry->content_type( $post->post_type ) ) {
			return null;
		}

		return array(
			'id'        => $post->ID,
			'type'      => $post->post_type,
			'status'    => $post->post_status,
			'values'    => RecordValues::read( $registry, $post ),
			'permalink' => (string) get_permalink( $post ),
		);
	}

	/**
	 * Creates a record.
	 *
	 * @param string               $type   Content type key.
	 * @param array<string, mixed> $values Field values by field key; term slug lists by taxonomy key.
	 * @param string               $status Post status.
	 * @param array<string, mixed> $origin Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return int|WP_Error The new post ID, or WP_Error (`pegwork_invalid_record` with per-key messages in data `fields`).
	 */
	public static function create( string $type, array $values, string $status = 'draft', array $origin = array() ): int|WP_Error {
		$registry = Plugin::registry();
		if ( null === $registry->content_type( $type ) ) {
			/* translators: %s: content type key. */
			return new WP_Error( 'pegwork_unknown_type', sprintf( __( 'Unknown content type "%s".', 'pegwork' ), $type ), array( 'status' => 400 ) );
		}
		if ( null === get_post_status_object( $status ) ) {
			/* translators: %s: post status. */
			return new WP_Error( 'pegwork_invalid_status', sprintf( __( 'Unknown status "%s".', 'pegwork' ), $status ), array( 'status' => 400 ) );
		}

		$defaults = array();
		foreach ( $registry->fields( $type ) as $key => $field ) {
			$defaults[ $key ] = RecordValues::cast( $field, $field['default'] ?? null );
		}
		foreach ( $registry->content_type( $type )['taxonomies'] as $usage ) {
			$defaults[ $usage['key'] ] = array();
		}

		$write = self::prepare( $registry, $type, $values, $defaults, $status );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$postarr = $write['post'] + array(
			'post_type'   => $type,
			'post_status' => $status,
			'meta_input'  => $write['meta'],
		);
		$id      = wp_insert_post( wp_slash( $postarr ), true, false );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$outer  = RecordEvents::claim( $id, $origin, false );
		$result = self::finish( $id, $write['terms'], false, null );
		RecordEvents::release( $id, $outer );

		return is_wp_error( $result ) ? $result : $id;
	}

	/**
	 * Updates a record. Values not given keep their stored value.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $values Field values by field key; term slug lists by taxonomy key.
	 * @param array<string, mixed> $origin Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return true|WP_Error WP_Error (`pegwork_invalid_record` with per-key messages in data `fields`) when invalid.
	 */
	public static function update( int $id, array $values, array $origin = array() ): true|WP_Error {
		$record = self::get( $id );
		if ( null === $record ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'pegwork_record_not_found', sprintf( __( 'No record with ID %d.', 'pegwork' ), $id ), array( 'status' => 404 ) );
		}

		$write = self::prepare( Plugin::registry(), $record['type'], $values, $record['values'], $record['status'] );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$before = get_post( $id );
		$outer  = RecordEvents::claim( $id, $origin, true );
		$saved  = wp_update_post( wp_slash( array( 'ID' => $id ) + $write['post'] + array( 'meta_input' => $write['meta'] ) ), true, false );
		$result = is_wp_error( $saved ) ? $saved : self::finish( $id, $write['terms'], true, $before );
		RecordEvents::release( $id, $outer );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Sanitizes values, merges them over the current ones and validates the result.
	 *
	 * @param Registry             $registry Effective registry.
	 * @param string               $type     Content type key.
	 * @param array<string, mixed> $values   Values to write.
	 * @param array<string, mixed> $current  Current (or default) values by key.
	 * @param string               $status   Resulting status.
	 * @return array{post: array<string, mixed>, meta: array<string, mixed>, terms: array<string, int[]>}|WP_Error
	 */
	private static function prepare( Registry $registry, string $type, array $values, array $current, string $status ): array|WP_Error {
		$fields     = $registry->fields( $type );
		$taxonomies = array_column( $registry->content_type( $type )['taxonomies'], null, 'key' );
		$write      = array(
			'post'  => array(),
			'meta'  => array(),
			'terms' => array(),
		);
		$errors     = array();

		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( isset( $fields[ $key ] ) ) {
				$field = $fields[ $key ];
				$clean = '' === $value || null === $value ? '' : call_user_func( Meta::sanitizer( $field ), $value );
				if ( '' === $clean && '' !== $value && null !== $value && ! in_array( $field['type'], array( 'text', 'textarea', 'rich_text' ), true ) ) {
					/* translators: %s: field label. */
					$errors[ $key ] = sprintf( __( '%s has an invalid value.', 'pegwork' ), $field['label'] );
					continue;
				}
				$current[ $key ] = RecordValues::cast( $field, $clean );
				$storage         = $field['wp']['storage'] ?? null;
				if ( null === $storage ) {
					$write['meta'][ Meta::key( $type, $field ) ] = $clean;
				} else {
					$write['post'][ $storage ] = (string) $clean;
				}
			} elseif ( isset( $taxonomies[ $key ] ) ) {
				$ids   = array();
				$slugs = array();
				foreach ( (array) $value as $slug ) {
					$term = is_string( $slug ) && '' !== $slug ? get_term_by( 'slug', $slug, $key ) : false;
					if ( ! $term instanceof \WP_Term ) {
						/* translators: 1: term slug, 2: taxonomy key. */
						$errors[ $key ] = sprintf( __( 'Unknown term "%1$s" in %2$s.', 'pegwork' ), is_scalar( $slug ) ? (string) $slug : '', $key );
						continue 2;
					}
					$ids[]   = $term->term_id;
					$slugs[] = $term->slug;
				}
				$current[ $key ]        = $slugs;
				$write['terms'][ $key ] = $ids;
			} else {
				/* translators: %s: value key. */
				$errors[ $key ] = sprintf( __( 'Unknown field or taxonomy "%s".', 'pegwork' ), $key );
			}
		}

		if ( array() === $errors ) {
			$valid = RecordValidator::validate( $registry, $type, $current, in_array( $status, RecordValidator::COMPLETE_STATUSES, true ) );
			if ( true === $valid ) {
				return $write;
			}
			$errors = $valid->get_error_data()['fields'] ?? array();
		}

		return new WP_Error(
			'pegwork_invalid_record',
			/* translators: %s: per-field error messages. */
			sprintf( __( 'This record cannot be saved: %s', 'pegwork' ), implode( ' ', $errors ) ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Sets terms, then fires wp_after_insert_post, which emits the write's record event.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, int[]> $terms  Term IDs by taxonomy key.
	 * @param bool                 $update Whether the post existed before.
	 * @param \WP_Post|null        $before Post before an update.
	 * @return true|WP_Error
	 */
	private static function finish( int $id, array $terms, bool $update, ?\WP_Post $before ): true|WP_Error {
		foreach ( $terms as $taxonomy => $ids ) {
			$set = wp_set_object_terms( $id, $ids, $taxonomy );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		wp_after_insert_post( $id, $update, $before );

		return true;
	}
}
