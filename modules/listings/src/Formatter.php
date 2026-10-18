<?php
/**
 * Formats field values for block output.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use DateTimeImmutable;

/**
 * Turns a typed field value into escaped block attribute output.
 */
final class Formatter {

	/**
	 * URL schemes a bound `url` attribute may use.
	 */
	public const URL_PROTOCOLS = array( 'http', 'https', 'mailto' );

	/**
	 * Formats a value for one block attribute.
	 *
	 * Text-like attributes get escaped HTML: dates through wp_date(), select option labels, boolean
	 * labels, rich text through wp_kses_post(), everything else through esc_html(), with an optional
	 * `prefix`. The `url` attribute takes url fields (http, https, mailto only) and email fields
	 * (as mailto:); anything else is refused.
	 *
	 * @param array<string, mixed> $field     Field definition.
	 * @param mixed                $value     Typed value.
	 * @param array<string, mixed> $args      Binding args: `format`, `prefix`, `label`.
	 * @param string               $attribute Bound block attribute.
	 * @return string|null Output, or null when the value is empty or refused.
	 */
	public static function format( array $field, mixed $value, array $args, string $attribute ): ?string {
		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}

		if ( 'url' === $attribute ) {
			return self::url( $field, $value );
		}

		$text = match ( $field['type'] ) {
			'boolean'   => true === $value ? ( self::text_arg( $args, 'label' ) ?? esc_html( (string) $field['label'] ) ) : null,
			'date'      => self::date( $value, $args ),
			'select'    => self::option_label( $field, $value ),
			'rich_text' => is_string( $value ) ? wp_kses_post( $value ) : null,
			default     => is_scalar( $value ) ? esc_html( (string) $value ) : null,
		};

		if ( null === $text || '' === trim( $text ) ) {
			return null;
		}

		return ( self::text_arg( $args, 'prefix' ) ?? '' ) . $text;
	}

	/**
	 * A safe URL from a url or email field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Typed value.
	 * @return string|null Escaped URL, or null.
	 */
	private static function url( array $field, mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		if ( 'email' === $field['type'] ) {
			$email = sanitize_email( $value );
			return '' === $email ? null : 'mailto:' . $email;
		}

		if ( 'url' !== $field['type'] ) {
			return null;
		}

		$url = esc_url( trim( $value ), self::URL_PROTOCOLS );
		return '' === $url ? null : $url;
	}

	/**
	 * A `Y-m-d` date in the `format` arg (default: the site date format), localized by wp_date().
	 *
	 * @param mixed                $value Stored date.
	 * @param array<string, mixed> $args  Binding args.
	 * @return string|null Escaped date, or null for an invalid date.
	 */
	private static function date( mixed $value, array $args ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}

		$format = isset( $args['format'] ) && is_string( $args['format'] ) && '' !== $args['format']
			? $args['format']
			: (string) get_option( 'date_format' );
		$output = wp_date( $format, $date->getTimestamp() );

		return false === $output ? null : esc_html( $output );
	}

	/**
	 * The label of a select field's option.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Stored option value.
	 * @return string|null Escaped label, or null for an unknown value.
	 */
	private static function option_label( array $field, mixed $value ): ?string {
		foreach ( $field['options'] ?? array() as $option ) {
			if ( (string) $option['value'] === (string) $value ) {
				return esc_html( (string) ( $option['label'] ?? $option['value'] ) );
			}
		}
		return null;
	}

	/**
	 * An escaped, non-empty string binding arg.
	 *
	 * @param array<string, mixed> $args Binding args.
	 * @param string               $name Arg name.
	 * @return string|null Escaped text, or null when absent.
	 */
	private static function text_arg( array $args, string $name ): ?string {
		return isset( $args[ $name ] ) && is_string( $args[ $name ] ) && '' !== $args[ $name ]
			? esc_html( $args[ $name ] )
			: null;
	}
}
