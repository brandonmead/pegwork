<?php
/**
 * The pegwork/option block bindings source.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Options;

use DateTimeImmutable;
use Pegwork\Api\Registry;
use Pegwork\Registry\Validator;

/**
 * Binds core block attributes to public options page fields: `{ page, field, format? }`.
 *
 * Returns null (core keeps the block's saved markup, the fallback text) when the page or field is unknown,
 * the field is private or a post field, or the value is empty. Output follows the pegwork/field rules
 * (docs/display.md): escaped for its type, `url` attributes only from url and email fields.
 */
final class BindingSource {

	/**
	 * Source name.
	 */
	public const NAME = 'pegwork/option';

	/**
	 * URL schemes a bound `url` attribute may use.
	 */
	public const URL_PROTOCOLS = array( 'http', 'https', 'mailto' );

	/**
	 * Registry the source reads, set on every init.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Registers the source once.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		self::$registry = $registry;
		if ( ! function_exists( 'register_block_bindings_source' ) || null !== get_block_bindings_source( self::NAME ) ) {
			return;
		}

		register_block_bindings_source(
			self::NAME,
			array(
				'label'              => __( 'Pegwork option', 'pegwork' ),
				'get_value_callback' => array( self::class, 'get_value' ),
			)
		);
	}

	/**
	 * Core's get_value_callback.
	 *
	 * @param array<string, mixed> $args      Binding args.
	 * @param mixed                $block     Block instance (unused).
	 * @param string               $attribute Bound attribute.
	 * @return string|null Escaped output, or null to keep the block's own markup.
	 */
	public static function get_value( array $args, $block, string $attribute ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- Core's callback signature.
		return null === self::$registry ? null : self::resolve( self::$registry, $args, $attribute );
	}

	/**
	 * Resolves a binding.
	 *
	 * @param Registry             $registry  Effective registry.
	 * @param array<string, mixed> $args      `page`, `field` (both required), `format` (`mailto` for email
	 *                                        fields, a PHP date format for date fields).
	 * @param string               $attribute Bound attribute.
	 * @return string|null Escaped output, or null.
	 */
	public static function resolve( Registry $registry, array $args, string $attribute ): ?string {
		$page = $args['page'] ?? null;
		$key  = $args['field'] ?? null;
		if ( ! is_string( $page ) || ! is_string( $key ) ) {
			return null;
		}

		$field = null;
		foreach ( $registry->options_page( $page )['fields'] ?? array() as $item ) {
			if ( $key === $item['key'] ) {
				$field = $item;
			}
		}
		if ( null === $field || 'private' === $field['visibility'] || Validator::POST_TYPE_FIELD === $field['type'] ) {
			return null;
		}

		return self::format( $field, Values::get( $registry, $page, $key ), $args, $attribute );
	}

	/**
	 * Formats a typed value for one attribute.
	 *
	 * @param array<string, mixed> $field     Field definition.
	 * @param mixed                $value     Typed value.
	 * @param array<string, mixed> $args      Binding args.
	 * @param string               $attribute Bound attribute.
	 * @return string|null Escaped output, or null when empty or refused.
	 */
	public static function format( array $field, mixed $value, array $args, string $attribute ): ?string {
		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}

		$format = isset( $args['format'] ) && is_string( $args['format'] ) && '' !== $args['format'] ? $args['format'] : null;

		if ( 'email' === $field['type'] && ( 'url' === $attribute || 'mailto' === $format ) ) {
			$email = is_string( $value ) ? sanitize_email( $value ) : '';
			return '' === $email ? null : esc_url( 'mailto:' . $email, self::URL_PROTOCOLS );
		}

		if ( 'url' === $attribute ) {
			if ( 'url' !== $field['type'] || ! is_string( $value ) ) {
				return null;
			}
			$url = esc_url( trim( $value ), self::URL_PROTOCOLS );
			return '' === $url ? null : $url;
		}

		$text = match ( $field['type'] ) {
			'boolean'   => true === $value ? esc_html( (string) $field['label'] ) : null,
			'date'      => self::date( $value, $format ),
			'select'    => self::option_label( $field, $value ),
			'rich_text' => is_string( $value ) ? wp_kses_post( $value ) : null,
			default     => is_scalar( $value ) ? esc_html( (string) $value ) : null,
		};

		return null === $text || '' === trim( $text ) ? null : $text;
	}

	/**
	 * A `Y-m-d` date in `$format` (default: the site date format), localized by wp_date().
	 *
	 * @param mixed       $value  Stored date.
	 * @param string|null $format PHP date format.
	 * @return string|null Escaped date, or null for an invalid date.
	 */
	private static function date( mixed $value, ?string $format ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return null;
		}
		$output = wp_date( $format ?? (string) get_option( 'date_format' ), $date->getTimestamp() );

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
}
