<?php
/**
 * Field lookup and public value resolution shared by the binding source and the conditional block.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Post;

/**
 * Resolves what a visitor may see of a record's fields.
 *
 * Private fields are refused for every user and context: this class only ever returns public fields.
 */
final class Fields {

	/**
	 * A public field of a content type.
	 *
	 * @param string $type Content type key.
	 * @param string $key  Field key.
	 * @return array<string, mixed>|null The field, or null when unknown or private.
	 */
	public static function public_field( string $type, string $key ): ?array {
		return pegwork_registry()->fields( $type, 'public' )[ $key ] ?? null;
	}

	/**
	 * The record named by block context (`postId`, optionally checked against `postType`).
	 *
	 * @param array<string, mixed> $context Block context.
	 * @return WP_Post|null The post, or null when absent or when its type differs from `postType`.
	 */
	public static function context_post( array $context ): ?WP_Post {
		$id = $context['postId'] ?? null;
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			return null;
		}

		$post = get_post( (int) $id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( isset( $context['postType'] ) && $context['postType'] !== $post->post_type ) {
			return null;
		}
		return $post;
	}

	/**
	 * Whether a record's values may be displayed: publicly viewable and not password-protected.
	 *
	 * @param WP_Post $post Record.
	 */
	public static function displayable( WP_Post $post ): bool {
		return is_post_publicly_viewable( $post ) && ! post_password_required( $post );
	}

	/**
	 * Whether a field's `show_when` condition holds (true when it has none).
	 *
	 * @param WP_Post              $post  Record.
	 * @param array<string, mixed> $field Field.
	 */
	public static function shown( WP_Post $post, array $field ): bool {
		$condition = $field['show_when'] ?? null;
		if ( ! is_array( $condition ) || ! isset( $condition['field'] ) || ! is_string( $condition['field'] ) ) {
			return true;
		}

		return pegwork_get_field( $post, $condition['field'] ) === ( $condition['equals'] ?? null );
	}

	/**
	 * The typed value a visitor may see.
	 *
	 * @param WP_Post $post Record.
	 * @param string  $key  Field key.
	 * @return mixed The typed value (see pegwork_get_field()), or null for an unknown or private field,
	 *               a record that is not displayable, or an unmet `show_when`.
	 */
	public static function value( WP_Post $post, string $key ): mixed {
		$field = self::public_field( $post->post_type, $key );
		if ( null === $field || ! self::displayable( $post ) || ! self::shown( $post, $field ) ) {
			return null;
		}

		return pegwork_get_field( $post, $key );
	}
}
