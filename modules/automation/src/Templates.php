<?php
/**
 * Merge tag rendering.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use WP_Term;

/**
 * Renders `{{ path }}`, `{{ path | date }}`, `{{ path | default:"text" }}` and `{{ path | date | default:"text" }}`
 * (syntax validated at load time by the registry). A path is a context path (`record.name`) or
 * `option:<page>.<field>`, an options page field read with pegwork_get_option() (public fields only, checked
 * by the registry). `date` formats a `Y-m-d` date or an ISO 8601 date and time with the site's date format
 * (Settings > General) in site time; other values pass through unchanged. `default` applies when the value
 * is empty, and its text is never date-formatted.
 *
 * Modes:
 * - `raw` (record values, idempotency keys): lists render as comma-separated slugs, booleans as 1/0.
 * - `text` (email subject and plain body): taxonomy keys render term names, lists join with ", ", and
 *   HTML entities WordPress stores in titles and the site name (`&amp;`) are decoded.
 * - `html` (email HTML body): as `text`, then every value is escaped, URLs with esc_url and everything
 *   else with esc_html. The authored template itself is trusted (committed config, imported by CLI only).
 * - `log` (what the run log may show, such as the subject): as `text`, but private fields render as
 *   `[private]`, so a private value never reaches anything but the email to its recipient.
 * - `url` (render_url(), the `call_webhook` URL): as `raw`, then each value is rawurlencode()d, so a value
 *   cannot add query parameters, a fragment or a space. A tag at the very start of the template (a
 *   whole URL, or the site URL a path follows) renders as `raw`.
 */
final class Templates {

	/**
	 * Record values and keys.
	 */
	public const RAW = 'raw';

	/**
	 * Email text.
	 */
	public const TEXT = 'text';

	/**
	 * Email HTML: values escaped.
	 */
	public const HTML = 'html';

	/**
	 * Run log: private fields hidden.
	 */
	public const LOG = 'log';

	/**
	 * URL: values encoded (see render_url()).
	 */
	public const URL = 'url';

	/**
	 * What a private value renders as in `log` mode.
	 */
	public const PRIVATE_MASK = '[private]';

	/**
	 * What a signed link renders as in `log` mode.
	 */
	public const LINK_MASK = '[link]';

	/**
	 * One merge tag: path (1), optional `date` filter (2), optional default (3).
	 */
	private const TAG = '/\{\{\s*((?:option:)?[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*(\|\s*date\s*)?(?:\|\s*default:"([^"]*)"\s*)?\}\}/';

	/**
	 * Paths whose values are URLs (escaped with esc_url in HTML).
	 */
	private const URL_PATHS = array( 'record.permalink', 'record.edit_url', 'site.url' );

	/**
	 * Renders a template to a string.
	 *
	 * @param string  $template Template.
	 * @param Context $context  Run context.
	 * @param string  $mode     raw, text, html or log.
	 */
	public static function render( string $template, Context $context, string $mode = self::RAW ): string {
		return (string) preg_replace_callback(
			self::TAG,
			fn( array $m ) => self::tag( $m, $context, $mode ),
			$template
		);
	}

	/**
	 * Renders a URL template: a tag at offset 0 as `raw`, every other tag's value rawurlencode()d.
	 *
	 * @param string  $template URL template (trimmed first).
	 * @param Context $context  Run context.
	 */
	public static function render_url( string $template, Context $context ): string {
		return (string) preg_replace_callback(
			self::TAG,
			function ( array $m ) use ( $context ): string {
				$groups = array();
				foreach ( $m as $index => $group ) {
					if ( -1 !== $group[1] ) {
						$groups[ $index ] = $group[0];
					}
				}
				return self::tag( $groups, $context, 0 === $m[0][1] ? self::RAW : self::URL );
			},
			trim( $template ),
			-1,
			$count,
			PREG_OFFSET_CAPTURE
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
	 * Renders one tag match in a mode.
	 *
	 * @param array<int, string> $m       Match: path, optional default.
	 * @param Context            $context Run context.
	 * @param string             $mode    Mode.
	 */
	private static function tag( array $m, Context $context, string $mode ): string {
		if ( self::RAW === $mode ) {
			return self::text( self::lookup( $m, $context ) );
		}
		if ( self::URL === $mode ) {
			return rawurlencode( self::text( self::lookup( $m, $context ) ) );
		}

		$path = $m[1];
		if ( self::LOG === $mode && $context->is_private( $path ) ) {
			return self::PRIVATE_MASK;
		}
		if ( self::LOG === $mode && str_starts_with( $path, 'link.' ) ) {
			return self::LINK_MASK; // A signed link is a credential: never in the log, never minted for it.
		}

		$value    = self::lookup( $m, $context );
		$taxonomy = $context->taxonomy( $path );
		if ( null !== $taxonomy && is_array( $value ) ) {
			$value = self::term_names( $value, $taxonomy );
		}
		$text = self::text( $value, ', ' );

		if ( self::HTML !== $mode ) {
			// WordPress stores titles and the site name HTML-encoded (&amp;); plain text shows them decoded, as core's own emails do.
			return wp_specialchars_decode( $text, ENT_QUOTES );
		}
		// esc_html does not double-encode, so a stored &amp; still renders as "&".
		return self::is_url( $path, $context ) ? esc_url( $text ) : esc_html( $text );
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
		if ( null === $value || '' === $value || array() === $value ) {
			return isset( $m[3] ) ? $m[3] : $value;
		}
		return '' !== ( $m[2] ?? '' ) ? self::date( $value ) : $value;
	}

	/**
	 * A date as the site shows dates: `Y-m-d` (a date field, `today`) or ISO 8601 with a time (`now`) in
	 * the site's date format and time zone. Anything else is returned unchanged.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function date( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$zone   = wp_timezone();
		$parsed = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value )
			? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone )
			: ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/', $value ) ? date_create_immutable( $value, $zone ) : false );
		if ( false === $parsed || ( 10 === strlen( $value ) && $parsed->format( 'Y-m-d' ) !== $value ) ) {
			return $value;
		}

		return (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $parsed->getTimestamp(), $zone );
	}

	/**
	 * Term names for slugs (a slug without a term stays as is).
	 *
	 * @param array<int, mixed> $slugs    Slugs.
	 * @param string            $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private static function term_names( array $slugs, string $taxonomy ): array {
		$names = array();
		foreach ( $slugs as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}
			$term    = get_term_by( 'slug', (string) $slug, $taxonomy );
			$names[] = $term instanceof WP_Term ? $term->name : (string) $slug;
		}
		return $names;
	}

	/**
	 * Whether a path holds a URL: the permalink, edit URL, site URL, a signed link or a `url` field.
	 *
	 * @param string  $path    Path.
	 * @param Context $context Run context.
	 */
	private static function is_url( string $path, Context $context ): bool {
		return in_array( $path, self::URL_PATHS, true ) || str_starts_with( $path, 'link.' ) || 'url' === $context->field_type( $path );
	}

	/**
	 * A value as text.
	 *
	 * @param mixed  $value Value.
	 * @param string $glue  List separator.
	 */
	private static function text( mixed $value, string $glue = ',' ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_array( $value ) ) {
			return implode( $glue, array_map( fn( $item ) => is_scalar( $item ) ? (string) $item : '', $value ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
