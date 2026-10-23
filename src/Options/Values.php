<?php
/**
 * Reads, checks and sanitizes options page values.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Options;

use Pegwork\Api\Registry;
use Pegwork\Content\FieldValue;
use Pegwork\Content\Meta;
use Pegwork\Content\RecordValidator;
use Pegwork\Registry\Validator;

/**
 * One option per page, named by the page key, holding `{ field key => value }`.
 *
 * Saving keeps every valid field and, for each invalid one, the stored value plus an error message.
 * Type checks live here; `required` and `validate` rules come from RecordValidator::check_values(), so
 * options pages and records share them.
 */
final class Values {

	/**
	 * Typed values of a page, or of one field. Private fields are included: this is the server-side read.
	 *
	 * @param Registry    $registry Effective registry.
	 * @param string      $page     Options page key.
	 * @param string|null $field    Field key, or null for every field.
	 * @return mixed One typed value (see cast()), an array of them by field key, or null for an unknown page or field.
	 */
	public static function get( Registry $registry, string $page, ?string $field = null ): mixed {
		$definition = $registry->options_page( $page );
		if ( null === $definition ) {
			return null;
		}

		$stored = self::stored( $page );
		$values = array();
		foreach ( $definition['fields'] as $item ) {
			if ( null !== $field && $field !== $item['key'] ) {
				continue;
			}
			$raw                    = array_key_exists( $item['key'], $stored ) ? $stored[ $item['key'] ] : ( $item['default'] ?? null );
			$values[ $item['key'] ] = self::cast( $item, $raw );
		}

		if ( null === $field ) {
			return $values;
		}

		return $values[ $field ] ?? null;
	}

	/**
	 * The stored option as an array, without defaults. Empty when nothing has been saved.
	 *
	 * @param string $page Options page key (the option name).
	 * @return array<string, mixed>
	 */
	public static function stored( string $page ): array {
		$stored = get_option( $page, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Casts a stored value to its field type.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @param mixed                $raw   Stored value.
	 * @return mixed Boolean fields bool; number fields int, float or null when unset; post fields a post ID or
	 *               null when unset; other fields string.
	 */
	public static function cast( array $field, mixed $raw ): mixed {
		if ( Validator::POST_TYPE_FIELD === $field['type'] ) {
			$id = is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ? (int) $raw : 0;
			return $id > 0 ? $id : null;
		}

		return FieldValue::cast( $field['type'], $raw );
	}

	/**
	 * Default values of a page: fields that declare a `default`.
	 *
	 * @param array<string, mixed> $page Normalized options page.
	 * @return array<string, mixed>
	 */
	public static function defaults( array $page ): array {
		$defaults = array();
		foreach ( $page['fields'] as $field ) {
			if ( array_key_exists( 'default', $field ) ) {
				$defaults[ $field['key'] ] = $field['default'];
			}
		}

		return $defaults;
	}

	/**
	 * Checks submitted values against a page and merges them over the stored ones.
	 *
	 * A field missing from `$input` keeps its stored value unchecked. A submitted field that fails its type
	 * check or its rules keeps its stored value (or stays unset) and gets an error message naming the field
	 * and the rejected value.
	 *
	 * @param array<string, mixed> $page   Normalized options page.
	 * @param mixed                $input  Submitted values by field key.
	 * @param array<string, mixed> $stored Stored values by field key.
	 * @return array{values: array<string, mixed>, errors: array<string, string>, submitted: string[]}
	 *         Values to store, error messages by field key, and the keys of the submitted fields.
	 */
	public static function sanitize( array $page, mixed $input, array $stored ): array {
		$input     = is_array( $input ) ? $input : array();
		$fields    = array();
		$candidate = array();
		$errors    = array();
		$submitted = array();

		foreach ( $page['fields'] as $field ) {
			$key            = $field['key'];
			$fields[ $key ] = $field;
			if ( ! array_key_exists( $key, $input ) ) {
				$candidate[ $key ] = $stored[ $key ] ?? ( $field['default'] ?? null );
				continue;
			}
			$submitted[] = $key;
			$result      = self::check_type( $field, $input[ $key ] );
			if ( is_array( $result ) ) {
				$candidate[ $key ] = $result[0];
			} else {
				$errors[ $key ] = $result;
			}
		}

		// Rules see every field (stored or submitted) so required fields left unsubmitted are not reported.
		$checkable = array_filter( $page['fields'], fn( array $field ) => in_array( $field['key'], $submitted, true ) && ! isset( $errors[ $field['key'] ] ) );
		$rules     = RecordValidator::check_values( array_values( $checkable ), $candidate );
		foreach ( $rules as $key => $message ) {
			$errors[ $key ] = $message;
		}

		$values = $stored;
		foreach ( $submitted as $key ) {
			if ( ! isset( $errors[ $key ] ) ) {
				$values[ $key ] = $candidate[ $key ];
			}
		}
		// Drop values of fields the page no longer defines.
		$values = array_intersect_key( $values, $fields );

		$messages = array();
		foreach ( $fields as $key => $field ) {
			if ( isset( $errors[ $key ] ) ) {
				$messages[ $key ] = self::rejection( $field, $input[ $key ] ?? null, $errors[ $key ], array_key_exists( $key, $stored ) );
			}
		}

		return array(
			'values'    => $values,
			'errors'    => $messages,
			'submitted' => $submitted,
		);
	}

	/**
	 * Type-checks and cleans one submitted value.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @param mixed                $value Submitted value.
	 * @return array{0: mixed}|string The clean value wrapped in an array, or an error message.
	 */
	public static function check_type( array $field, mixed $value ): array|string {
		$label = (string) $field['label'];

		if ( 'boolean' === $field['type'] ) {
			return array( null !== $value && '' !== $value && rest_sanitize_boolean( $value ) );
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s must be a single value.', 'pegwork' ), $label );
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return array( '' );
		}

		switch ( $field['type'] ) {
			case 'email':
				return is_email( $text ) ? array( sanitize_email( $text ) )
					/* translators: %s: field label. */
					: sprintf( __( '%s must be a valid email address.', 'pegwork' ), $label );
			case 'url':
				$url = esc_url_raw( $text, array( 'http', 'https' ) );
				return '' !== $url && Meta::valid_url( $url ) ? array( $url )
					/* translators: %s: field label. */
					: sprintf( __( '%s must be a web address starting with http:// or https://.', 'pegwork' ), $label );
			case 'number':
				$number = Meta::sanitize_number( $text );
				return '' !== $number ? array( $number )
					/* translators: %s: field label. */
					: sprintf( __( '%s must be a number.', 'pegwork' ), $label );
			case 'date':
				return '' !== Meta::sanitize_date( $text ) ? array( $text )
					/* translators: %s: field label. */
					: sprintf( __( '%s must be a date in the form YYYY-MM-DD.', 'pegwork' ), $label );
			case 'select':
				return in_array( $text, array_map( 'strval', array_column( $field['options'], 'value' ) ), true ) ? array( $text )
					/* translators: %s: field label. */
					: sprintf( __( '%s must be one of its options.', 'pegwork' ), $label );
			case Validator::POST_TYPE_FIELD:
				return self::check_post( $field, $text );
			case 'rich_text':
				return array( wp_kses_post( (string) $value ) );
			case 'textarea':
				return array( sanitize_textarea_field( (string) $value ) );
			default:
				return array( sanitize_text_field( (string) $value ) );
		}
	}

	/**
	 * Checks a post field value: the ID of a published post of the field's `post_type`.
	 *
	 * @param array<string, mixed> $field Normalized post field.
	 * @param string               $text  Submitted value, trimmed and non-empty.
	 * @return array{0: int}|string
	 */
	private static function check_post( array $field, string $text ): array|string {
		$post = ctype_digit( $text ) ? get_post( (int) $text ) : null;
		if ( $post instanceof \WP_Post && $field['post_type'] === $post->post_type && 'publish' === $post->post_status ) {
			return array( $post->ID );
		}

		$type  = get_post_type_object( $field['post_type'] );
		$label = $type ? $type->labels->singular_name : $field['post_type'];

		/* translators: 1: field label, 2: singular label of the content type. */
		return sprintf( __( '%1$s must be a published %2$s.', 'pegwork' ), $field['label'], $label );
	}

	/**
	 * The message shown for a rejected field: the rule's message, the rejected value, and what was kept.
	 *
	 * @param array<string, mixed> $field   Normalized field.
	 * @param mixed                $value   Rejected value as submitted.
	 * @param string               $message Rule or type message.
	 * @param bool                 $kept    Whether a stored value was kept.
	 */
	private static function rejection( array $field, mixed $value, string $message, bool $kept ): string {
		$shown = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( 'rich_text' === $field['type'] || 'textarea' === $field['type'] || mb_strlen( $shown ) > 80 ) {
			$shown = '';
		}
		if ( '' !== $shown ) {
			/* translators: 1: error message, 2: rejected value. */
			$message = sprintf( __( '%1$s You entered "%2$s".', 'pegwork' ), $message, $shown );
		}

		return $message . ' ' . ( $kept ? __( 'The previous value was kept.', 'pegwork' ) : __( 'Nothing was saved for this field.', 'pegwork' ) );
	}
}
