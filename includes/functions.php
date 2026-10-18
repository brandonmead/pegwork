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
 * One options page value, or all of a page's values, as typed values.
 *
 * Private fields are included: this is for server code (notification recipients, for example). Never
 * print a private value; the `pegwork/option` binding source is the way to show public ones.
 *
 * @since 0.2.0
 *
 * @param string      $page  Options page key (also the option name).
 * @param string|null $field Field key, or null for every field of the page.
 * @return mixed For one field: the stored value cast to its type, else its default. Boolean fields bool;
 *               number fields int, float or null when unset; post fields the post ID or null; other fields
 *               string ('' when unset). For null `$field`: an array of those by field key. Null for an
 *               unknown page or field.
 */
function pegwork_get_option( string $page, ?string $field = null ): mixed {
	return Pegwork\Options\Values::get( pegwork_registry(), $page, $field );
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
 * Sanitizes one untrusted value for a field definition, with the Phase 1 per-type sanitizers.
 *
 * @since 0.2.0
 *
 * @param array<string, mixed> $field Field definition (`type`, plus `options` for select).
 * @param mixed                $value Raw value, for example from a form POST.
 * @return bool|int|float|string Booleans bool (missing is false); numbers int or float, '' when empty or not
 *                               numeric; other types a string, '' when empty or invalid (bad email, URL other
 *                               than http or https, impossible date, undefined option). Arrays count as empty.
 */
function pegwork_sanitize_field( array $field, mixed $value ): bool|int|float|string {
	return Pegwork\Content\Meta::sanitize( $field, $value );
}

/**
 * Validates values against a list of field definitions (required, required_when, validate rules), as
 * record validation does for a content type's fields. A required boolean must be true (a consent box).
 *
 * @since 0.2.0
 *
 * @param array<int, array<string, mixed>> $fields   Field definitions (`key`, `type`, `label`, `required`, ...).
 * @param array<string, mixed>             $values   Sanitized values by field key.
 * @param bool                             $complete Whether completeness rules apply; false accepts anything.
 * @return true|WP_Error WP_Error `pegwork_invalid_fields` (status 400) with per-field messages in data `fields`.
 */
function pegwork_validate_fields( array $fields, array $values, bool $complete = true ): bool|WP_Error {
	$errors = Pegwork\Content\RecordValidator::check_values( $fields, $values, $complete, true );
	if ( array() === $errors ) {
		return true;
	}

	return new WP_Error(
		'pegwork_invalid_fields',
		/* translators: %s: per-field error messages. */
		sprintf( __( 'These values are not valid: %s', 'pegwork' ), implode( ' ', $errors ) ),
		array(
			'status' => 400,
			'fields' => $errors,
		)
	);
}

/**
 * Saves a site-local form definition (the `pegwork_schema` option), then rebuilds the registry.
 *
 * The definition passes the registry validator and its references resolve against the effective
 * registry. Keys a file package defines are refused. No capability check: the caller authorizes.
 *
 * @since 0.2.0
 *
 * @param array<string, mixed> $definition Form definition (format 1).
 * @return true|WP_Error `pegwork_invalid_form` (400, messages by JSON pointer in data `fields`) or
 *                       `pegwork_form_file_defined` (409).
 */
function pegwork_save_form( array $definition ): bool|WP_Error {
	return Pegwork\Registry\Forms::save( $definition );
}

/**
 * Deletes a site-local form definition, then rebuilds the registry. Stored entries are kept.
 * No capability check: the caller authorizes.
 *
 * @since 0.2.0
 *
 * @param string $key Form key.
 * @return true|WP_Error `pegwork_form_file_defined` (409) or `pegwork_form_not_found` (404).
 */
function pegwork_delete_form( string $key ): bool|WP_Error {
	return Pegwork\Registry\Forms::delete( $key );
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
