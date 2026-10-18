<?php
/**
 * Public global functions.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The effective schema registry, read-only. Built on init at priority 5; empty before that.
 */
function pegwork_registry(): Pegwork\Api\Registry {
	return Pegwork\Plugin::registry();
}

/**
 * One field of a record, as a typed value.
 *
 * Private fields are null unless `$context` is `edit` and the current user can edit the post.
 * No display rules apply here (no `show_when`, password or status checks): this is the raw value.
 *
 * @since 0.2.0
 *
 * @param int|WP_Post $post    Post ID or object.
 * @param string      $key     Field key.
 * @param string      $context `view` (default) or `edit`.
 * @return mixed Boolean fields bool; number fields int, float or null when unset; other fields string.
 *               Null for a missing post, an unknown field, or a refused private field.
 */
function pegwork_get_field( int|WP_Post $post, string $key, string $context = 'view' ): mixed {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	return Pegwork\Content\FieldValue::get( pegwork_registry(), $post, $key, $context );
}

/**
 * Validates a record against its content type, as publishing would.
 *
 * @param string               $type    Content type key.
 * @param array<string, mixed> $values  Field values by field key (title and content included); term ID lists by taxonomy key;
 *                                      linked ID lists by relationship key (missing means the record's stored links
 *                                      when `$post_id` is set, else no links).
 * @param int                  $post_id ID of the record being validated, 0 for a new one; its own links do not count
 *                                      against `one_to_many` limits.
 * @return true|WP_Error WP_Error (status 400) with per-field messages in its data `fields`.
 */
function pegwork_validate_record( string $type, array $values, int $post_id = 0 ): bool|WP_Error {
	return Pegwork\Content\RecordValidator::validate( pegwork_registry(), $type, $values, true, $post_id );
}

/**
 * IDs of the records at the other end of a relationship. Direction follows the post's type
 * ("from" or "to"). Every status is included; callers filter.
 *
 * @param int|WP_Post $post Post or post ID.
 * @param string      $key  Relationship key.
 * @return int[] Sorted IDs. Empty for an unknown key, a missing post, or a post of neither type.
 */
function pegwork_related_ids( int|WP_Post $post, string $key ): array {
	return Pegwork\Content\Relationships::related_ids( pegwork_registry(), $post, $key );
}

/**
 * WP_Query args for the records at the other end of a relationship. Callers add status, order and paging.
 *
 * From a "to" post: the "from" type with a meta_query on `_rel_{key}`. From a "from" post: the
 * "to" type with `post__in` (`array( 0 )` when nothing is linked, so the query matches nothing).
 *
 * @param int|WP_Post $post Post or post ID.
 * @param string      $key  Relationship key.
 * @return array<string, mixed> Args that match nothing for an unknown key, a missing post, or a post of neither type.
 */
function pegwork_related_query_args( int|WP_Post $post, string $key ): array {
	return Pegwork\Content\Relationships::query_args( pegwork_registry(), $post, $key );
}
