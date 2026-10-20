<?php
/**
 * Query Loop and archive hooks for Pegwork directories.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Block;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;
use WP_Post;
use WP_Query;

/**
 * Adds the `pegwork` attribute to core/query and runs the constraint pipeline for Pegwork Query
 * Loops (query_loop_block_query_vars) and for archives of types with a `listing` (pre_get_posts).
 *
 * The core/query block provides its `pegwork` attribute as block context `pegwork/listing`, and every block
 * that uses the `query` context (post template, pagination, no results, query total) also receives
 * it. So each of those blocks builds the same constrained query, and page counts match the grid.
 */
final class QueryHooks {

	/**
	 * Attribute added to core/query.
	 */
	public const ATTRIBUTE = 'pegwork';

	/**
	 * Block context key carrying the attribute.
	 */
	public const CONTEXT = 'pegwork/listing';

	/**
	 * Attribute on the query wrapper naming the directory's content type.
	 */
	public const DIRECTORY_ATTRIBUTE = 'data-pegwork-directory';

	/**
	 * Registers the hooks and patches block types registered before boot. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_filter( 'register_block_type_args', array( self::class, 'block_type_args' ) ) ) {
			add_filter( 'register_block_type_args', array( self::class, 'block_type_args' ), 10, 2 );
		}
		if ( ! has_filter( 'query_loop_block_query_vars', array( self::class, 'block_query_vars' ) ) ) {
			add_filter( 'query_loop_block_query_vars', array( self::class, 'block_query_vars' ), 10, 3 );
		}
		if ( ! has_action( 'pre_get_posts', array( self::class, 'main_query' ) ) ) {
			add_action( 'pre_get_posts', array( self::class, 'main_query' ) );
		}
		if ( ! has_filter( 'render_block_core/query', array( self::class, 'mark_directory' ) ) ) {
			add_filter( 'render_block_core/query', array( self::class, 'mark_directory' ), 10, 3 );
		}
		if ( ! has_filter( 'get_block_type_uses_context', array( self::class, 'uses_context' ) ) ) {
			add_filter( 'get_block_type_uses_context', array( self::class, 'uses_context' ), 10, 2 );
		}

		$query = WP_Block_Type_Registry::get_instance()->get_registered( 'core/query' );
		if ( $query instanceof WP_Block_Type ) {
			$query->attributes       = self::with_attribute( is_array( $query->attributes ) ? $query->attributes : array() );
			$query->provides_context = self::with_context( is_array( $query->provides_context ) ? $query->provides_context : array() );
		}
	}

	/**
	 * Adds the attribute and provided context to core/query as it registers. Hooked to register_block_type_args.
	 *
	 * @param array<string, mixed> $args Block type args.
	 * @param string               $name Block name.
	 * @return array<string, mixed> Args.
	 */
	public static function block_type_args( $args, $name ) {
		if ( ! is_array( $args ) || 'core/query' !== $name ) {
			return $args;
		}

		$args['attributes']       = self::with_attribute( isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array() );
		$args['provides_context'] = self::with_context( isset( $args['provides_context'] ) && is_array( $args['provides_context'] ) ? $args['provides_context'] : array() );
		return $args;
	}

	/**
	 * Gives every block that uses the `query` context the `pegwork/listing` and `postId` contexts too
	 * (`postId` is the current post a `related` preset resolves against). Hooked to
	 * get_block_type_uses_context.
	 *
	 * @param string[] $uses Context names.
	 * @return string[] Context names.
	 */
	public static function uses_context( $uses ) {
		if ( ! is_array( $uses ) || ! in_array( 'query', $uses, true ) ) {
			return $uses;
		}
		foreach ( array( self::CONTEXT, 'postId' ) as $name ) {
			if ( ! in_array( $name, $uses, true ) ) {
				$uses[] = $name;
			}
		}
		return $uses;
	}

	/**
	 * Attributes with `pegwork`.
	 *
	 * @param array<string, mixed> $attributes Attribute schemas.
	 * @return array<string, mixed>
	 */
	private static function with_attribute( array $attributes ): array {
		$attributes[ self::ATTRIBUTE ] = array( 'type' => 'object' );
		return $attributes;
	}

	/**
	 * Provided context with `pegwork/listing`.
	 *
	 * @param array<string, string> $context Provided context.
	 * @return array<string, string>
	 */
	private static function with_context( array $context ): array {
		$context[ self::CONTEXT ] = self::ATTRIBUTE;
		return $context;
	}

	/**
	 * Constrains a Pegwork Query Loop. Hooked to query_loop_block_query_vars.
	 *
	 * @param array<string, mixed> $query Query vars built from the block.
	 * @param WP_Block|null        $block The block building the query (post template, pagination, ...).
	 * @param int                  $page  Current page.
	 * @return array<string, mixed> Query vars.
	 */
	public static function block_query_vars( $query, $block = null, $page = 1 ) {
		unset( $page );
		$listing = $block instanceof WP_Block ? ( $block->context[ self::CONTEXT ] ?? null ) : null;
		if ( ! is_array( $query ) || ! is_array( $listing ) ) {
			return $query;
		}

		$type = $query['post_type'] ?? null;
		if ( ! is_string( $type ) || null === pegwork_registry()->content_type( $type ) ) {
			return $query;
		}

		$where       = isset( $listing['where'] ) && is_array( $listing['where'] ) ? $listing['where'] : array();
		$current     = array_key_exists( Constraints::RELATED_KEY, $where ) ? self::current_post_id( $block ) : 0;
		$constraints = Constraints::filter( Constraints::from_where( $type, $where, $current ), $type, 'block', ! empty( $listing['filters'] ) );

		return Constraints::merge( $query, Constraints::to_query_args( $type, $constraints ) );
	}

	/**
	 * The post a `related` preset resolves against: the `postId` block context, as for field bindings and
	 * "Show if" (the viewed post in a single template, the card's record inside another Query Loop), else
	 * the queried post.
	 *
	 * @param WP_Block $block The block building the query.
	 * @return int Post ID, or 0.
	 */
	private static function current_post_id( WP_Block $block ): int {
		$id = $block->context['postId'] ?? null;
		if ( is_numeric( $id ) && (int) $id > 0 ) {
			return (int) $id;
		}
		$queried = get_queried_object();
		return $queried instanceof WP_Post ? $queried->ID : 0;
	}

	/**
	 * Constrains the main query of an archive whose type has a `listing`. Hooked to pre_get_posts.
	 *
	 * Runs after parse_query, so a search constraint sets `s` while `is_search()` stays false and the
	 * archive template is kept.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function main_query( $query ): void {
		if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$type = self::archive_type( $query );
		if ( null === $type ) {
			return;
		}

		$constraints = Constraints::filter( array(), $type, 'main', true );
		Constraints::apply( $query, Constraints::to_query_args( $type, $constraints ) );
	}

	/**
	 * Adds `data-pegwork-directory="<type>"` to the wrapper of a Pegwork Query Loop, and of an inherit
	 * Query Loop on the archive of a type with a `listing`. Hooked to render_block_core/query.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $parsed  Parsed block.
	 * @return string Content.
	 */
	public static function mark_directory( $content, $parsed ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$attrs = isset( $parsed['attrs'] ) && is_array( $parsed['attrs'] ) ? $parsed['attrs'] : array();
		$query = isset( $attrs['query'] ) && is_array( $attrs['query'] ) ? $attrs['query'] : array();
		$type  = null;
		if ( ! empty( $query['inherit'] ) ) {
			global $wp_query;
			$type = $wp_query instanceof WP_Query ? self::archive_type( $wp_query ) : null;
		} elseif ( isset( $attrs[ self::ATTRIBUTE ] ) && is_array( $attrs[ self::ATTRIBUTE ] ) ) {
			$type = isset( $query['postType'] ) && is_string( $query['postType'] ) ? $query['postType'] : 'post';
			$type = null === pegwork_registry()->content_type( $type ) ? null : $type;
		}
		if ( null === $type ) {
			return $content;
		}

		$tags = new WP_HTML_Tag_Processor( $content );
		if ( $tags->next_tag() ) {
			$tags->set_attribute( self::DIRECTORY_ATTRIBUTE, $type );
			return $tags->get_updated_html();
		}
		return $content;
	}

	/**
	 * The listing type a query is the archive of.
	 *
	 * @param WP_Query $query Query.
	 * @return string|null Content type key with a `listing`, or null.
	 */
	private static function archive_type( WP_Query $query ): ?string {
		if ( ! $query->is_post_type_archive() ) {
			return null;
		}

		$type = $query->get( 'post_type' );
		if ( is_array( $type ) && 1 === count( $type ) ) {
			$type = reset( $type );
		}
		if ( ! is_string( $type ) || null === pegwork_registry()->listing( $type ) ) {
			return null;
		}
		return $type;
	}
}
