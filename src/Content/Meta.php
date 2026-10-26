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

		foreach ( array_keys( $registry->content_types() ) as $type_key ) {
			if ( ! has_filter( 'rest_prepare_' . $type_key, array( self::class, 'rest_prepare' ) ) ) {
				add_filter( 'rest_prepare_' . $type_key, array( self::class, 'rest_prepare' ), 10, 3 );
			}
		}
	}

	/**
	 * Leaves Pegwork field meta and relationship links (`_rel_*`) out of the REST response of a
	 * password-protected record until the reader gives the password, as core does for its content. Other
	 * meta is left to its own plugin. Hooked to rest_prepare_{post_type}.
	 *
	 * @param \WP_REST_Response|mixed $response Response.
	 * @param \WP_Post|mixed          $post     Post.
	 * @param \WP_REST_Request|mixed  $request  Request.
	 * @return \WP_REST_Response|mixed
	 */
	public static function rest_prepare( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post || '' === $post->post_password || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! is_array( $data['meta'] ?? null ) || self::can_read_protected( $post, $request ) ) {
			return $response;
		}

		foreach ( array_keys( $data['meta'] ) as $meta_key ) {
			if ( in_array( $meta_key, self::$registered[ $post->post_type ] ?? array(), true ) || str_starts_with( (string) $meta_key, Relationships::META_PREFIX ) ) {
				unset( $data['meta'][ $meta_key ] );
			}
		}
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a REST reader may see a password-protected record's content, by core's rules: an editor in edit
	 * context, the right `password` param, or the post password cookie.
	 *
	 * @param \WP_Post         $post    Post with a password.
	 * @param \WP_REST_Request $request Request.
	 */
	private static function can_read_protected( \WP_Post $post, \WP_REST_Request $request ): bool {
		if ( 'edit' === $request['context'] && current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}
		$password = $request['password'];
		if ( is_string( $password ) && '' !== $password ) {
			return hash_equals( $post->post_password, $password );
		}

		return ! post_password_required( $post );
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
	 * An untrusted web address as a clean URL, or '' when it is not one: trimmed, `https://` added when
	 * it has no scheme, esc_url_raw() with http and https only, then valid_url().
	 *
	 * @param string $value Raw value.
	 */
	public static function sanitize_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $value ) ) {
			$value = 'https://' . ltrim( $value, '/' );
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );

		return '' !== $url && self::valid_url( $url ) ? $url : '';
	}

	/**
	 * Whether a URL is a web address, by the rules of core's wp_http_validate_url() that need no network:
	 * http or https, a host, no user or password; the host an IPv4 address or dot-separated labels (letters,
	 * digits, hyphens, underscores, non-ASCII for IDNs) ending in a top-level label of two or more letters
	 * (or `xn--` punycode). No DNS lookup, so typos in real-looking hosts pass and offline sites still work.
	 *
	 * @param string $url URL.
	 */
	public static function valid_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			|| empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$host = rtrim( strtolower( (string) $parts['host'] ), '.' );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return true;
		}

		return 1 === preg_match( '/^(?:[a-z0-9_\x80-\xff](?:[a-z0-9_\x80-\xff-]{0,61}[a-z0-9_\x80-\xff])?\.)+(?:[a-z\x80-\xff]{2,63}|xn--[a-z0-9-]{1,59})$/', $host );
	}

	/**
	 * Sanitizes one untrusted value (public form input) for a field, as a typed value.
	 *
	 * Booleans return bool (missing is false); numbers int or float, '' when empty or not numeric;
	 * every other type a string, '' when empty or invalid (a malformed email, URL or date, an option
	 * the field does not define). URLs must pass valid_url() (see sanitize_url()). Arrays and objects count as empty.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @param mixed                $value Raw value.
	 * @return bool|int|float|string
	 */
	public static function sanitize( array $field, mixed $value ): bool|int|float|string {
		if ( 'boolean' === $field['type'] ) {
			return is_scalar( $value ) && rest_sanitize_boolean( $value );
		}
		if ( ! is_scalar( $value ) || '' === $value ) {
			return '';
		}
		$value = (string) $value;

		return match ( $field['type'] ) {
			'url'    => self::sanitize_url( $value ),
			'email'  => is_email( trim( $value ) ) ? sanitize_email( trim( $value ) ) : '',
			'select' => in_array( $value, array_map( 'strval', array_column( $field['options'] ?? array(), 'value' ) ), true ) ? $value : '',
			'number' => self::sanitize_number( $value ),
			'date'   => self::sanitize_date( trim( $value ) ),
			'file'   => '',
			default  => (string) call_user_func( self::sanitizer( $field ), $value ),
		};
	}

	/**
	 * Sanitize callback for a field's type. URLs go through sanitize_url(): web addresses only, '' otherwise.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 */
	public static function sanitizer( array $field ): callable {
		return match ( $field['type'] ) {
			'textarea'  => 'sanitize_textarea_field',
			'rich_text' => 'wp_kses_post',
			'url'       => fn( $value ) => is_scalar( $value ) ? self::sanitize_url( (string) $value ) : '',
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
