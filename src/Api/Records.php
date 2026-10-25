<?php
/**
 * Public records API.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

use Pegwork\Content\Meta;
use Pegwork\Content\RecordQuery;
use Pegwork\Content\RecordValidator;
use Pegwork\Content\RecordValues;
use Pegwork\Content\Relationships;
use Pegwork\Events\RecordEvents;
use Pegwork\Plugin;
use WP_Error;

/**
 * Reads and writes records of registry content types by registry key. Part of the semver-stable public API.
 *
 * Writes sanitize each value for its field type, validate the merged record as a REST save would
 * (completeness rules only when the resulting status is publish, pending or future) and emit one
 * record event. Writes do not check the current user's capabilities: callers authorize by their own rules.
 * API 0.3.0 adds find(), relate(), unrelate() and relationship keys in create() and update() values.
 * API 0.4.0 adds trash() and the `created` query property of find().
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
	 * @param array<string, mixed> $values Field values by field key; term slug lists by taxonomy key; linked IDs by
	 *                                     relationship key (from this type: one ID, comma-separated IDs or a list; API 0.3.0).
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
		foreach ( $write['links'] as $key => $ids ) {
			Relationships::write_ids( $id, $key, $ids );
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
	 * @param array<string, mixed> $values Field values by field key; term slug lists by taxonomy key; linked IDs by
	 *                                     relationship key, replacing the record's links (empty clears; API 0.3.0).
	 * @param array<string, mixed> $origin Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return true|WP_Error WP_Error (`pegwork_invalid_record` with per-key messages in data `fields`) when invalid.
	 */
	public static function update( int $id, array $values, array $origin = array() ): true|WP_Error {
		$record = self::get( $id );
		if ( null === $record ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'pegwork_record_not_found', sprintf( __( 'No record with ID %d.', 'pegwork' ), $id ), array( 'status' => 404 ) );
		}

		$write = self::prepare( Plugin::registry(), $record['type'], $values, $record['values'], $record['status'], $id );
		if ( is_wp_error( $write ) ) {
			return $write;
		}

		$before = get_post( $id );
		$outer  = RecordEvents::claim( $id, $origin, true );
		$saved  = wp_update_post( wp_slash( array( 'ID' => $id ) + $write['post'] + array( 'meta_input' => $write['meta'] ) ), true, false );
		if ( ! is_wp_error( $saved ) ) {
			foreach ( $write['links'] as $key => $ids ) {
				Relationships::write_ids( $id, $key, $ids );
			}
		}
		$result = is_wp_error( $saved ) ? $saved : self::finish( $id, $write['terms'], true, $before );
		RecordEvents::release( $id, $outer );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Moves a record to the trash (API 0.4.0). Never deletes permanently: when trash is turned off
	 * (`EMPTY_TRASH_DAYS` is 0 or false, where wp_trash_post() would delete), it refuses and leaves the post.
	 * Emits record.updated (`changed` = `status`) and record.status_changed to `trash` with the caller's origin.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $origin Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return bool|WP_Error True when the record was moved, false when it was already in the trash; WP_Error
	 *                       `pegwork_record_not_found` (not a registry record, or an auto-draft), `trash_disabled`,
	 *                       or `pegwork_trash_failed` (another plugin stopped the trash through `pre_trash_post`).
	 */
	public static function trash( int $id, array $origin = array() ): bool|WP_Error {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status || null === Plugin::registry()->content_type( $post->post_type ) ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'pegwork_record_not_found', sprintf( __( 'No record with ID %d.', 'pegwork' ), $id ), array( 'status' => 404 ) );
		}
		if ( 'trash' === $post->post_status ) {
			return false;
		}
		if ( ! self::trash_enabled() ) {
			return new WP_Error( 'trash_disabled', __( 'Trash is turned off on this site (EMPTY_TRASH_DAYS is 0), so the record was not moved. Pegwork never deletes records permanently.', 'pegwork' ), array( 'status' => 409 ) );
		}

		$outer   = RecordEvents::claim( $id, $origin, true );
		$trashed = wp_trash_post( $id );
		RecordEvents::release( $id, $outer );

		if ( ! $trashed || 'trash' !== get_post_status( $id ) ) {
			/* translators: %d: post ID. */
			return new WP_Error( 'pegwork_trash_failed', sprintf( __( 'Record %d could not be moved to the trash.', 'pegwork' ), $id ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Whether trash() may call wp_trash_post(): only when the site keeps a trash. The
	 * `pegwork_records_trash_enabled` filter can turn it off but never on, because with
	 * `EMPTY_TRASH_DAYS` 0 wp_trash_post() deletes permanently.
	 */
	private static function trash_enabled(): bool {
		$enabled = defined( 'EMPTY_TRASH_DAYS' ) && (bool) EMPTY_TRASH_DAYS;

		/**
		 * Filters whether Records::trash() may move records to the trash. Returning true has no effect
		 * when `EMPTY_TRASH_DAYS` is 0.
		 *
		 * @param bool $enabled Whether the site keeps a trash.
		 */
		return $enabled && (bool) apply_filters( 'pegwork_records_trash_enabled', $enabled );
	}

	/**
	 * Finds records of a content type. Counts and sums are SQL over every match, not over the returned IDs.
	 *
	 * Operands are resolved literals. An operand that is empty (null, '' or an empty list) on any operator but
	 * `empty` and `not_empty` makes the whole query match nothing and is reported as `empty_operand`.
	 * Private fields can be filtered and summed: callers decide what is shown. Trash and auto-drafts are never read.
	 *
	 * @param string               $type  Content type key of the records read.
	 * @param array<string, mixed> $query `related` { relationship, of: record ID at the other end }, `filter`
	 *                                    { all|any: [ { field, op, value } ] } (1 to 10 leaves; `field` a field or
	 *                                    taxonomy key or `id`), `status` (default private and publish), `sort`
	 *                                    { field, order } (default id asc), `limit` (0 to 50, default 10), `count`
	 *                                    (bool), `sum` (up to 5 number field keys) and `created` { before, after }
	 *                                    (`Y-m-d` site dates compared with the creation time; API 0.4.0).
	 * @return array{ids: int[], count: int|null, sums: array<string, float>, empty_operand: string|null}|WP_Error
	 *         `count` is null unless asked; WP_Error `pegwork_invalid_query` (data `reason`, such as `unknown_field`) when invalid.
	 */
	public static function find( string $type, array $query = array() ): array|WP_Error {
		return RecordQuery::run( Plugin::registry(), $type, $query );
	}

	/**
	 * Links two records, validated as a save of the "from" record would be (target type, `one_to_many`,
	 * and `required` when the "from" record is published, pending or future). Emits one record.updated on
	 * the "from" record with the relationship key in `changed` and the previous IDs in `old`.
	 *
	 * @param string               $relationship Relationship key.
	 * @param int                  $from         "From" record ID.
	 * @param int                  $to           "To" record ID.
	 * @param array<string, mixed> $origin       Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return bool|WP_Error True when the link was added, false when it already existed; WP_Error
	 *                       (`pegwork_unknown_relationship`, or `pegwork_invalid_record` with the message in data `fields`).
	 */
	public static function relate( string $relationship, int $from, int $to, array $origin = array() ): bool|WP_Error {
		return self::link( $relationship, $from, $to, true, $origin );
	}

	/**
	 * Removes a link between two records, with the same validation and event as relate() (removing the
	 * last link of a `required` relationship from a published record fails).
	 *
	 * @param string               $relationship Relationship key.
	 * @param int                  $from         "From" record ID.
	 * @param int                  $to           "To" record ID.
	 * @param array<string, mixed> $origin       Optional `automation`, `run_id` and `depth` for the event's origin.
	 * @return bool|WP_Error True when the link was removed, false when it was already absent.
	 */
	public static function unrelate( string $relationship, int $from, int $to, array $origin = array() ): bool|WP_Error {
		return self::link( $relationship, $from, $to, false, $origin );
	}

	/**
	 * Adds or removes one link.
	 *
	 * @param string               $relationship Relationship key.
	 * @param int                  $from         "From" record ID.
	 * @param int                  $to           "To" record ID.
	 * @param bool                 $add          Add rather than remove.
	 * @param array<string, mixed> $origin       Event origin.
	 * @return bool|WP_Error
	 */
	private static function link( string $relationship, int $from, int $to, bool $add, array $origin ): bool|WP_Error {
		$registry   = Plugin::registry();
		$definition = $registry->relationships()[ $relationship ] ?? null;
		if ( null === $definition ) {
			/* translators: %s: relationship key. */
			return new WP_Error( 'pegwork_unknown_relationship', sprintf( __( 'Unknown relationship "%s".', 'pegwork' ), $relationship ), array( 'status' => 400 ) );
		}

		$post = get_post( $from );
		if ( ! $post instanceof \WP_Post || $definition['from'] !== $post->post_type ) {
			$label = $registry->content_type( $definition['from'] )['labels']['singular'] ?? $definition['from'];
			/* translators: 1: record ID, 2: singular label of the "from" content type. */
			return self::invalid( array( $relationship => sprintf( __( 'Record %1$d is not a %2$s.', 'pegwork' ), $from, $label ) ) );
		}

		$current = Relationships::stored_ids( $from, $relationship );
		$ids     = $add ? Relationships::ids( array_merge( $current, array( $to ) ) ) : array_values( array_diff( $current, array( $to ) ) );
		if ( $add && $to <= 0 ) {
			$ids = array_merge( $current, array( $to ) );
		}
		if ( $ids === $current ) {
			return false;
		}

		$complete = in_array( $post->post_status, RecordValidator::COMPLETE_STATUSES, true );
		$message  = RecordValidator::check_relationship( $registry, $relationship, $ids, $complete, $from );
		if ( null !== $message ) {
			return self::invalid( array( $relationship => $message ) );
		}

		$outer = RecordEvents::claim( $from, $origin, true );
		Relationships::write_ids( $from, $relationship, $ids );
		RecordEvents::emit_claimed( $from );
		RecordEvents::release( $from, $outer );

		return true;
	}

	/**
	 * Linked ID items of a relationship value: one ID, comma-separated IDs, or a list. Empty clears.
	 * Items are kept as given (trimmed) so validation can name a bad one.
	 *
	 * @param mixed $value Value.
	 * @return array<int, mixed>
	 */
	private static function link_items( mixed $value ): array {
		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}
		if ( is_string( $value ) ) {
			$value = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), fn( string $item ) => '' !== $item ) );
		}

		return array_values( is_array( $value ) ? $value : array( $value ) );
	}

	/**
	 * The invalid-record error of a write.
	 *
	 * @param array<string, string> $errors Messages by key.
	 */
	private static function invalid( array $errors ): WP_Error {
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
	 * Sanitizes values, merges them over the current ones and validates the result.
	 *
	 * @param Registry             $registry Effective registry.
	 * @param string               $type     Content type key.
	 * @param array<string, mixed> $values   Values to write.
	 * @param array<string, mixed> $current  Current (or default) values by key.
	 * @param string               $status   Resulting status.
	 * @param int                  $post_id  Record ID on update, 0 on create; validation reads its stored relationship links.
	 * @return array{post: array<string, mixed>, meta: array<string, mixed>, terms: array<string, int[]>, links: array<string, int[]>}|WP_Error
	 */
	private static function prepare( Registry $registry, string $type, array $values, array $current, string $status, int $post_id = 0 ): array|WP_Error {
		$fields        = $registry->fields( $type );
		$taxonomies    = array_column( $registry->content_type( $type )['taxonomies'], null, 'key' );
		$relationships = Relationships::from( $registry, $type );
		$write         = array(
			'post'  => array(),
			'meta'  => array(),
			'terms' => array(),
			'links' => array(),
		);
		$errors        = array();

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
			} elseif ( isset( $relationships[ $key ] ) ) {
				$items                  = self::link_items( $value );
				$current[ $key ]        = $items;
				$write['links'][ $key ] = Relationships::ids( $items );
			} else {
				/* translators: %s: value key. */
				$errors[ $key ] = sprintf( __( 'Unknown field or taxonomy "%s".', 'pegwork' ), $key );
			}
		}

		if ( array() === $errors ) {
			$valid = RecordValidator::validate( $registry, $type, $current, in_array( $status, RecordValidator::COMPLETE_STATUSES, true ), $post_id );
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
