<?php
/**
 * Registers meta-stored fields as post meta.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use Pegwork\Registry\Validator;

/**
 * Maps each field without `wp.storage` to register_post_meta(): key, type, sanitizer,
 * edit_post auth and a REST schema. Private fields are readable only in edit context.
 */
final class Meta {

	/**
	 * Meta keys registered by the last call, by post type, so removed fields are unregistered.
	 *
	 * @var array<string, string[]>
	 */
	private static array $registered = array();

	/**
	 * Registers meta for every meta-stored field and unregisters ones that were removed.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		$keys = array();
		foreach ( $registry->content_types() as $type_key => $type ) {
			foreach ( $type['fields'] as $field ) {
				if ( self::is_meta( $field ) ) {
					$keys[ $type_key ][ self::key( $type_key, $field ) ] = $field;
				}
			}
		}

		foreach ( self::$registered as $type_key => $meta_keys ) {
			foreach ( array_diff( $meta_keys, array_keys( $keys[ $type_key ] ?? array() ) ) as $gone ) {
				unregister_post_meta( $type_key, $gone );
			}
		}
		self::$registered = array();

		foreach ( $keys as $type_key => $fields ) {
			foreach ( $fields as $meta_key => $field ) {
				if ( register_post_meta( $type_key, $meta_key, self::args( $field ) ) ) {
					self::$registered[ $type_key ][] = $meta_key;
				}
			}
		}
	}

	/**
	 * Whether a field is stored in post meta (no `wp.storage` column).
	 *
	 * @param array<string, mixed> $field Normalized field.
	 */
	public static function is_meta( array $field ): bool {
		return ! isset( $field['wp']['storage'] );
	}

	/**
	 * Meta key of a field: `wp.meta_key`, else `_{post_type}_{field}`.
	 *
	 * @param string               $type  Content type key.
	 * @param array<string, mixed> $field Normalized field.
	 */
	public static function key( string $type, array $field ): string {
		return $field['wp']['meta_key'] ?? '_' . $type . '_' . $field['key'];
	}

	/**
	 * Builds register_post_meta() arguments for a field.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @return array<string, mixed>
	 */
	public static function args( array $field ): array {
		$schema = self::schema( $field );
		if ( 'private' === $field['visibility'] ) {
			$schema['context'] = array( 'edit' );
		}

		$args = array(
			'type'              => $schema['type'],
			'label'             => $field['label'],
			'single'            => true,
			'sanitize_callback' => self::sanitizer( $field ),
			'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			'show_in_rest'      => array( 'schema' => $schema ),
		);

		if ( array_key_exists( 'default', $field ) ) {
			$args['default'] = $field['default'];
		}

		return $args;
	}

	/**
	 * REST schema for a field's type. Empty values stay valid so optional fields can be cleared;
	 * completeness rules belong to RecordValidator.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @return array<string, mixed>
	 */
	private static function schema( array $field ): array {
		return match ( $field['type'] ) {
			'boolean' => array( 'type' => 'boolean' ),
			'number'  => array( 'type' => 'number' ),
			'url'     => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'email'   => array(
				'type'  => 'string',
				'anyOf' => array(
					array(
						'type'   => 'string',
						'format' => 'email',
					),
					array(
						'type'      => 'string',
						'maxLength' => 0,
					),
				),
			),
			'date'    => array(
				'type'    => 'string',
				'pattern' => '^([0-9]{4}-[0-9]{2}-[0-9]{2})?$',
			),
			'select'  => array(
				'type' => 'string',
				'enum' => array_merge( array( '' ), array_column( $field['options'], 'value' ) ),
			),
			default   => array( 'type' => 'string' ),
		};
	}

	/**
	 * Sanitize callback for a field's type.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 */
	public static function sanitizer( array $field ): callable {
		return match ( $field['type'] ) {
			'textarea'  => 'sanitize_textarea_field',
			'rich_text' => 'wp_kses_post',
			'url'       => 'esc_url_raw',
			'email'     => 'sanitize_email',
			'boolean'   => 'rest_sanitize_boolean',
			'date'      => array( self::class, 'sanitize_date' ),
			'number'    => array( self::class, 'sanitize_number' ),
			'select'    => fn( $value ) => in_array( $value, array_column( $field['options'], 'value' ), true ) ? $value : '',
			default     => 'sanitize_text_field',
		};
	}

	/**
	 * Keeps a real `Y-m-d` date, else empty.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_date( mixed $value ): string {
		if ( ! is_string( $value ) || ! preg_match( Validator::DATE_PATTERN, $value ) ) {
			return '';
		}
		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $value ) );

		return checkdate( $month, $day, $year ) ? $value : '';
	}

	/**
	 * Keeps a numeric value as int or float, else empty.
	 *
	 * @param mixed $value Raw value.
	 * @return int|float|string
	 */
	public static function sanitize_number( mixed $value ): int|float|string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return is_string( $value ) && is_numeric( trim( $value ) ) ? 0 + trim( $value ) : '';
	}
}
