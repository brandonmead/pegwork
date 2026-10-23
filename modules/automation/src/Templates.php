<?php
/**
 * Merge tag rendering.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use DateTimeImmutable;
use WP_Term;

/**
 * Renders merge tags `{{ path [| add:"<signed duration>"] [| date | number[:<0-4>]] [| default:"text"] }}`
 * and `{{#each steps.<id>.records}} ... {{item.<key>}} ... {{else}} ... {{/each}}` blocks (syntax and types
 * validated at load time by the registry). A path is a context path (`record.name`) or
 * `option:<page>.<field>`, an options page field read with pegwork_get_option() (public fields only, checked
 * by the registry).
 *
 * Filters, applied in this order to a non-empty value:
 * - `add` moves a `Y-m-d` date (giving `Y-m-d`) or an ISO 8601 date and time (giving ISO) by a signed
 *   duration in site time, with calendar units, so `P1D` across a DST change keeps the wall-clock time.
 * - `date` formats a `Y-m-d` date or an ISO 8601 date and time with the site's date format (Settings >
 *   General) in site time.
 * - `number` formats a number with number_format_i18n() (site locale): `number:2` with 2 decimals; without
 *   decimals, whole numbers print bare and others with 2 decimals.
 * Other values pass through each filter unchanged. `default` applies when the value is empty, and its text
 * is never filtered.
 *
 * `{{#each}}` renders its body once per record of a find_records result that still exists (at most 50),
 * with `item.*` reading that record, or its `{{else}}` part when there is none. A template with a block
 * renders at most 64 KB, else TemplateTooLarge is thrown (the step fails `template_too_large`). Values are
 * never rendered again, so a stored value that looks like a tag stays text.
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
 * - `preview` and `preview_html` (editor preview and dry run): as `text` and `html`, but `{{link.*}}`
 *   renders `[signed link]` and is never looked up (nothing is minted, no key is created), and
 *   `{{steps.<id>.*}}` (and an `{{#each}}` over it) renders `[from step <id>]` when that step has no
 *   output, since a preview runs no step and a dry run applies no write (its reads have real outputs).
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
	 * Preview of email text (see the class description).
	 */
	public const PREVIEW = 'preview';

	/**
	 * Preview of email HTML: values escaped.
	 */
	public const PREVIEW_HTML = 'preview_html';

	/**
	 * What a signed link renders as in the preview modes.
	 */
	public const PREVIEW_LINK = '[signed link]';

	/**
	 * Most items one `{{#each}}` renders.
	 */
	public const MAX_ITEMS = 50;

	/**
	 * Most bytes a template with an `{{#each}}` block renders to.
	 */
	public const MAX_BYTES = 65536;

	/**
	 * One merge tag: `path`, optional `add` duration, optional `format` (date or number) with `decimals`,
	 * optional `fallback` with its `default` text.
	 */
	private const TAG = '\{\{\s*(?<path>(?:option:)?[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*(?:\|\s*add:"(?<add>[^"]*)"\s*)?(?:\|\s*(?<format>date|number)(?::(?<decimals>[0-4]))?\s*)?(?<fallback>\|\s*default:"(?<default>[^"]*)"\s*)?\}\}';

	/**
	 * One `{{#each <path>}} body {{else}} else {{/each}}` block.
	 */
	private const EACH = '\{\{\s*#each\s+(?<each>[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}(?<body>.*?)(?:\{\{\s*else\s*\}\}(?<else>.*?))?\{\{\s*\/each\s*\}\}';

	/**
	 * Paths whose values are URLs (escaped with esc_url in HTML).
	 */
	private const URL_PATHS = array( 'record.permalink', 'record.edit_url', 'site.url' );

	/**
	 * Read record paths whose values are URLs.
	 */
	private const READ_URL_PATH = '/^(?:steps\.[a-z0-9_]+\.(?:record|item)|item)\.(?:permalink|edit_url)$/';

	/**
	 * Renders a template to a string.
	 *
	 * @param string  $template Template.
	 * @param Context $context  Run context.
	 * @param string  $mode     raw, text, html, log, preview or preview_html.
	 * @throws TemplateTooLarge When a template with an `{{#each}}` block renders past MAX_BYTES.
	 */
	public static function render( string $template, Context $context, string $mode = self::RAW ): string {
		$out = (string) preg_replace_callback(
			'/' . self::EACH . '|' . self::TAG . '/s',
			fn( array $m ) => null !== $m['each'] ? self::each( $m, $context, $mode ) : self::tag( $m, $context, $mode ),
			$template,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
		if ( strlen( $out ) > self::MAX_BYTES && preg_match( '/\{\{\s*#each\b/', $template ) ) {
			throw new TemplateTooLarge( esc_html( sprintf( 'The template rendered %1$d bytes; at most %2$d with {{#each}}.', strlen( $out ), self::MAX_BYTES ) ) );
		}
		return $out;
	}

	/**
	 * Renders a URL template: a tag at offset 0 as `raw`, every other tag's value rawurlencode()d.
	 *
	 * @param string  $template URL template (trimmed first).
	 * @param Context $context  Run context.
	 */
	public static function render_url( string $template, Context $context ): string {
		return (string) preg_replace_callback(
			'/' . self::TAG . '/',
			function ( array $m ) use ( $context ): string {
				$groups = array_map( fn( array $group ) => $group[0], $m );
				return self::tag( $groups, $context, 0 === $m[0][1] ? self::RAW : self::URL );
			},
			trim( $template ),
			-1,
			$count,
			PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
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
		if ( preg_match( '/^' . self::TAG . '$/', trim( $value ), $m, PREG_UNMATCHED_AS_NULL ) ) {
			return self::lookup( $m, $context );
		}
		return self::render( $value, $context );
	}

	/**
	 * Renders the tags of a block body (no nested blocks).
	 *
	 * @param string  $template Body.
	 * @param Context $context  Run context.
	 * @param string  $mode     Mode.
	 */
	private static function tags( string $template, Context $context, string $mode ): string {
		return (string) preg_replace_callback(
			'/' . self::TAG . '/',
			fn( array $m ) => self::tag( $m, $context, $mode ),
			$template,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);
	}

	/**
	 * Renders one `{{#each}}` block.
	 *
	 * @param array<string, string|null> $m       Match: `each` path, `body`, `else`.
	 * @param Context                    $context Run context.
	 * @param string                     $mode    Mode.
	 * @throws TemplateTooLarge When the items render past MAX_BYTES.
	 */
	private static function each( array $m, Context $context, string $mode ): string {
		$path = (string) $m['each'];
		$ids  = $context->collection( $path );
		if ( null === $ids && ( self::PREVIEW === $mode || self::PREVIEW_HTML === $mode ) ) {
			/* translators: %s: step ID. */
			$from = sprintf( __( '[from step %s]', 'pegwork' ), (string) ( explode( '.', $path )[1] ?? '' ) );
			return self::PREVIEW_HTML === $mode ? esc_html( $from ) : $from;
		}

		$out   = '';
		$shown = 0;
		foreach ( array_slice( $ids ?? array(), 0, self::MAX_ITEMS ) as $id ) {
			$record = $context->record_by_id( (int) $id );
			if ( null === $record ) {
				continue; // Deleted or trashed since it was found.
			}
			$context->set_item( $record );
			try {
				$out .= self::tags( (string) $m['body'], $context, $mode );
			} finally {
				$context->set_item( null );
			}
			++$shown;
			if ( strlen( $out ) > self::MAX_BYTES ) {
				throw new TemplateTooLarge( esc_html( sprintf( 'The {{#each}} block rendered more than %d bytes.', self::MAX_BYTES ) ) );
			}
		}
		return 0 === $shown ? self::tags( (string) ( $m['else'] ?? '' ), $context, $mode ) : $out;
	}

	/**
	 * Renders one tag match in a mode.
	 *
	 * @param array<int|string, string|null> $m       Match: `path`, `add`, `format`, `decimals`, `fallback`, `default`.
	 * @param Context                        $context Run context.
	 * @param string                         $mode    Mode.
	 */
	private static function tag( array $m, Context $context, string $mode ): string {
		if ( self::RAW === $mode ) {
			return self::text( self::lookup( $m, $context ) );
		}
		if ( self::URL === $mode ) {
			return rawurlencode( self::text( self::lookup( $m, $context ) ) );
		}

		$path = (string) $m['path'];
		if ( self::PREVIEW === $mode || self::PREVIEW_HTML === $mode ) {
			if ( str_starts_with( $path, 'link.' ) ) {
				return self::PREVIEW_LINK;
			}
			$step = str_starts_with( $path, 'steps.' ) ? (string) ( explode( '.', $path )[1] ?? '' ) : null;
			if ( null !== $step && ! $context->has_output( $step ) ) {
				/* translators: %s: step ID. */
				$from = sprintf( __( '[from step %s]', 'pegwork' ), $step );
				return self::PREVIEW_HTML === $mode ? esc_html( $from ) : $from;
			}
			$mode = self::PREVIEW_HTML === $mode ? self::HTML : self::TEXT;
		}
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
	 * Resolves one tag match: its default when the value is empty, else the value through its filters.
	 *
	 * @param array<int|string, string|null> $m       Match.
	 * @param Context                        $context Run context.
	 * @return mixed
	 */
	private static function lookup( array $m, Context $context ): mixed {
		$value = $context->resolve( (string) $m['path'] );
		if ( null === $value || '' === $value || array() === $value ) {
			return null !== ( $m['fallback'] ?? null ) ? (string) $m['default'] : $value;
		}
		if ( null !== ( $m['add'] ?? null ) ) {
			$value = self::add( $value, (string) $m['add'] );
		}
		$format = $m['format'] ?? null;
		if ( 'date' === $format ) {
			return self::date( $value );
		}
		if ( 'number' === $format ) {
			$decimals = $m['decimals'] ?? null;
			return self::number( $value, null === $decimals ? null : (int) $decimals );
		}
		return $value;
	}

	/**
	 * A date or date and time moved by a signed ISO 8601 duration in site time: a `Y-m-d` date gives
	 * `Y-m-d`, an ISO 8601 date and time gives ISO 8601 in site time. Anything else is returned unchanged.
	 *
	 * @param mixed  $value    Value.
	 * @param string $duration Signed duration, such as P2D or -PT6H.
	 * @return mixed
	 */
	public static function add( mixed $value, string $duration ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$zone = wp_timezone();
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone );
			if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
				return $value;
			}
			return Conditions::offset( $date, $duration )->format( 'Y-m-d' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?$/', $value ) ) {
			$time = date_create_immutable( $value, $zone );
			return false === $time ? $value : Conditions::offset( $time->setTimezone( $zone ), $duration )->format( 'Y-m-d\TH:i:sP' );
		}
		return $value;
	}

	/**
	 * A number in the site locale: with the given decimals, else whole numbers bare and others with 2
	 * decimals. Anything that is not numeric is returned unchanged.
	 *
	 * @param mixed    $value    Value.
	 * @param int|null $decimals Decimals (0 to 4), or null.
	 * @return mixed
	 */
	public static function number( mixed $value, ?int $decimals = null ): mixed {
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return $value;
		}
		$number = (float) $value;
		if ( null === $decimals ) {
			$decimals = floor( $number ) === $number ? 0 : 2;
		}
		return number_format_i18n( $number, $decimals );
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
			? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone )
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
	 * Whether a path holds a URL: a permalink or edit URL (bound, read or item record), the site URL, a
	 * signed link or a `url` field.
	 *
	 * @param string  $path    Path.
	 * @param Context $context Run context.
	 */
	private static function is_url( string $path, Context $context ): bool {
		return in_array( $path, self::URL_PATHS, true )
			|| str_starts_with( $path, 'link.' )
			|| 1 === preg_match( self::READ_URL_PATH, $path )
			|| 'url' === $context->field_type( $path );
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
