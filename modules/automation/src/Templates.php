<?php
/**
 * Merge tag rendering.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

/**
 * Renders `{{ path }}` and `{{ path | default:"text" }}` (syntax validated at load time by the registry).
 *
 * In this release templates feed record values and idempotency keys, so lists (taxonomy slugs) render as
 * comma-separated slugs. Email text (Phase 4 PR 4) renders term names and escapes HTML bodies.
 */
final class Templates {

	/**
	 * One merge tag: path and optional default.
	 */
	private const TAG = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*(?:\|\s*default:"([^"]*)"\s*)?\}\}/';

	/**
	 * Renders a template to a string.
	 *
	 * @param string  $template Template.
	 * @param Context $context  Run context.
	 */
	public static function render( string $template, Context $context ): string {
		return (string) preg_replace_callback(
			self::TAG,
			fn( array $m ) => self::text( self::lookup( $m, $context ) ),
			$template
		);
	}

	/**
	 * A `values` entry: a non-string literal is kept; a string that is exactly one merge tag gives the raw
	 * value (a list stays a list, a boolean a boolean); any other string is rendered.
	 *
	 * @param mixed   $value   Literal or template.
	 * @param Context $context Run context.
	 * @return mixed
	 */
	public static function value( mixed $value, Context $context ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( preg_match( '/^' . substr( self::TAG, 1, -1 ) . '$/', trim( $value ), $m ) ) {
			return self::lookup( $m, $context );
		}
		return self::render( $value, $context );
	}

	/**
	 * Resolves one tag match, applying its default when the value is empty.
	 *
	 * @param array<int, string> $m       Match: path, optional default.
	 * @param Context            $context Run context.
	 * @return mixed
	 */
	private static function lookup( array $m, Context $context ): mixed {
		$value = $context->resolve( $m[1] );
		if ( ( null === $value || '' === $value || array() === $value ) && isset( $m[2] ) ) {
			return $m[2];
		}
		return $value;
	}

	/**
	 * A value as text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_array( $value ) ) {
			return implode( ',', array_map( fn( $item ) => is_scalar( $item ) ? (string) $item : '', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
