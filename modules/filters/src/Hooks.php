<?php
/**
 * Hooks into the listings pipeline and patterns.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

use WP_Block;
use WP_HTML_Tag_Processor;

/**
 * - `pegwork_listing_constraints`: adds the URL constraints to filterable listings (archives of a type
 *   with a `listing`, and Query Loops with `pegwork.filters`).
 * - `pegwork_listing_pattern`: puts the filters block above the generated directory pattern.
 * - `render_block_core/query-no-results`: points the pattern's "Clear all filters" link at the base URL.
 */
final class Hooks {

	/**
	 * Class of the Clear all link in the directory pattern's no-results message.
	 */
	public const CLEAR_CLASS = 'pegwork-filters-clear';

	/**
	 * Registers the hooks. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_filter( 'pegwork_listing_constraints', array( self::class, 'constraints' ) ) ) {
			add_filter( 'pegwork_listing_constraints', array( self::class, 'constraints' ), 10, 3 );
		}
		if ( ! has_filter( 'pegwork_listing_pattern', array( self::class, 'pattern' ) ) ) {
			add_filter( 'pegwork_listing_pattern', array( self::class, 'pattern' ), 10, 3 );
		}
		if ( ! has_filter( 'render_block_core/query-no-results', array( self::class, 'no_results' ) ) ) {
			add_filter( 'render_block_core/query-no-results', array( self::class, 'no_results' ), 10, 3 );
		}
	}

	/**
	 * Unslashed query parameters of the request. Only declared keys are ever read from it (UrlState).
	 *
	 * @return array<string, mixed>
	 */
	public static function request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public GET; every value is checked against the registry allowlist in UrlState.
		return isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
	}

	/**
	 * The URL state of a type for this request.
	 *
	 * @param string $type Content type key.
	 * @return array{q: string, values: array<string, list<string>>}|null Null when the type has no `listing`.
	 */
	public static function state( string $type ): ?array {
		$definition = Definition::for_type( $type );
		return null === $definition ? null : UrlState::parse( $definition, self::request() );
	}

	/**
	 * Adds URL constraints to a filterable listing. Hooked to pegwork_listing_constraints.
	 *
	 * @param array<string, mixed> $constraints Constraints so far.
	 * @param string               $type        Content type key.
	 * @param array<string, mixed> $context     `source` (`main` or `block`) and `filters`.
	 * @return array<string, mixed> Constraints.
	 */
	public static function constraints( $constraints, $type, $context = array() ) {
		if ( ! is_array( $constraints ) || ! is_string( $type ) || ! is_array( $context ) ) {
			return $constraints;
		}
		if ( empty( $context['filters'] ) && 'main' !== ( $context['source'] ?? null ) ) {
			return $constraints;
		}

		$definition = Definition::for_type( $type );
		if ( null === $definition ) {
			return $constraints;
		}
		return UrlState::merge( $constraints, UrlState::to_constraints( $definition, UrlState::parse( $definition, self::request() ) ) );
	}

	/**
	 * Puts the filters block, aligned with the grid, above the directory pattern's Query Loop. Hooked to
	 * pegwork_listing_pattern.
	 *
	 * @param array<string, mixed>|null $pattern Pattern properties.
	 * @param string                    $type    Content type key.
	 * @param string                    $kind    Pattern kind.
	 * @return array<string, mixed>|null Pattern.
	 */
	public static function pattern( $pattern, $type, $kind ) {
		if ( 'directory' !== $kind || ! is_array( $pattern ) || ! isset( $pattern['content'] ) || ! is_string( $pattern['content'] ) || ! is_string( $type ) ) {
			return $pattern;
		}
		if ( str_contains( $pattern['content'], '<!-- wp:' . FiltersBlock::NAME . ' ' ) ) {
			return $pattern;
		}

		$filters            = get_comment_delimited_block_content(
			FiltersBlock::NAME,
			array(
				'postType' => $type,
				'align'    => 'wide',
			),
			''
		);
		$pattern['content'] = $filters . "\n" . $pattern['content'];
		return $pattern;
	}

	/**
	 * Points `a.pegwork-filters-clear` links in a no-results message at the directory's base URL.
	 * Hooked to render_block_core/query-no-results.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $parsed  Parsed block.
	 * @param WP_Block|null        $block   Block instance.
	 * @return string Content.
	 */
	public static function no_results( $content, $parsed = array(), $block = null ) {
		unset( $parsed );
		if ( ! is_string( $content ) || ! str_contains( $content, self::CLEAR_CLASS ) ) {
			return $content;
		}

		$query = $block instanceof WP_Block && is_array( $block->context['query'] ?? null ) ? $block->context['query'] : array();
		$type  = isset( $query['postType'] ) && is_string( $query['postType'] ) ? $query['postType'] : null;
		if ( ! empty( $query['inherit'] ) ) {
			$queried = get_query_var( 'post_type' );
			$type    = is_string( $queried ) && '' !== $queried ? $queried : null;
		}
		if ( null === $type || null === Definition::for_type( $type ) ) {
			return $content;
		}

		$url  = FiltersBlock::base_url( $type );
		$tags = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
			if ( $tags->has_class( self::CLEAR_CLASS ) ) {
				// The tag processor escapes attribute values itself, so the URL goes in unencoded.
				$tags->set_attribute( 'href', esc_url_raw( $url ) );
			}
		}
		return $tags->get_updated_html();
	}
}
