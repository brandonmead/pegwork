<?php
/**
 * The result count a filters block shows.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

use WP_Block;
use WP_Block_Patterns_Registry;
use WP_Block_Template;
use WP_Post;
use WP_Query;

/**
 * Counts the records the directory a filters block belongs to lists, with the request's filters applied.
 *
 * - On the archive of the type (`/projects/`), the main query's `found_posts`.
 * - Otherwise the bound Query Loop: the first core/query with `pegwork.filters` for the type in the
 *   page content or the current block template (patterns, synced patterns and template parts referenced
 *   there included, to a depth limit). Blocks render in
 *   order, so the filters block usually renders before the loop; it builds the loop's query the way core's
 *   post template does (build_query_vars_from_query_block(), with context `query`, `queryId` and
 *   `pegwork/listing`, so the listings pipeline applies). Core caches identical WP_Query runs, so the
 *   loop's own run costs no further SQL.
 * - No bound loop found (an editor preview, content rendered outside the main post): the same pipeline over
 *   a plain loop of the type, as a count-only query (IDs, one row, no cache priming).
 */
final class Count {

	/**
	 * Most nested pattern references followed while looking for the bound loop.
	 */
	private const MAX_DEPTH = 4;

	/**
	 * The count for a type on the current request.
	 *
	 * @param string $type Content type key.
	 */
	public static function for_type( string $type ): int {
		global $wp_query;
		if ( $wp_query instanceof WP_Query && $wp_query->is_main_query() && $wp_query->is_post_type_archive( $type ) && ! is_admin() ) {
			return (int) $wp_query->found_posts;
		}

		$loop = self::bound_loop( $type );
		return null === $loop ? self::loop_count( self::plain_loop( $type ), true ) : self::loop_count( $loop );
	}

	/**
	 * The count of a Query Loop's records, as its post template would query them.
	 *
	 * @param array<string, mixed> $attrs   core/query attributes (`query`, `queryId`, `pegwork`).
	 * @param bool                 $id_only Count only: IDs of one record, no cache priming (when no loop
	 *                                      will run the same query afterwards).
	 */
	public static function loop_count( array $attrs, bool $id_only = false ): int {
		$context = array(
			'query'           => is_array( $attrs['query'] ?? null ) ? $attrs['query'] : array(),
			'pegwork/listing' => is_array( $attrs['pegwork'] ?? null ) ? $attrs['pegwork'] : array( 'filters' => true ),
		);
		if ( isset( $attrs['queryId'] ) ) {
			$context['queryId'] = $attrs['queryId'];
		}

		$page_key = isset( $attrs['queryId'] ) ? 'query-' . $attrs['queryId'] . '-page' : 'query-page';
		$request  = Hooks::request();
		$page     = isset( $request[ $page_key ] ) && is_numeric( $request[ $page_key ] ) ? max( 1, (int) $request[ $page_key ] ) : 1;

		$block = new WP_Block(
			array(
				'blockName'    => 'core/post-template',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$context
		);

		$vars = build_query_vars_from_query_block( $block, $page );
		if ( $id_only ) {
			$vars = array_merge(
				$vars,
				array(
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
		}
		$query = new WP_Query( $vars );
		return (int) $query->found_posts;
	}

	/**
	 * A plain filterable loop of the type.
	 *
	 * @param string $type Content type key.
	 * @return array<string, mixed> core/query attributes.
	 */
	private static function plain_loop( string $type ): array {
		return array(
			'query'   => array(
				'postType' => $type,
				'perPage'  => (int) get_option( 'posts_per_page', 10 ),
				'inherit'  => false,
			),
			'pegwork' => array( 'filters' => true ),
		);
	}

	/**
	 * Attributes of the first filterable Query Loop of the type in the page content or the current template.
	 *
	 * @param string $type Content type key.
	 * @return array<string, mixed>|null core/query attributes, or null when none is found.
	 */
	public static function bound_loop( string $type ): ?array {
		global $_wp_current_template_content;

		$sources = array();
		$post    = get_queried_object();
		if ( $post instanceof WP_Post && is_singular() ) {
			$sources[] = $post->post_content;
		}
		if ( is_string( $_wp_current_template_content ) ) {
			$sources[] = $_wp_current_template_content;
		}

		foreach ( $sources as $source ) {
			if ( ! str_contains( $source, '<!-- wp:' ) ) {
				continue;
			}
			$found = self::find( parse_blocks( $source ), $type, 0 );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * The block markup a reference block stands for: a registered pattern (`core/pattern`), a synced
	 * pattern (`core/block`, a published `wp_block`) or a template part (`core/template-part`).
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $attrs Block attributes.
	 * @return string Markup, or '' when the block is no reference or its target is missing.
	 */
	private static function referenced_content( string $name, array $attrs ): string {
		if ( 'core/pattern' === $name && isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) ) {
			$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( $attrs['slug'] );
			return is_array( $pattern ) && isset( $pattern['content'] ) && is_string( $pattern['content'] ) ? $pattern['content'] : '';
		}

		if ( 'core/block' === $name && isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ) {
			$post = get_post( (int) $attrs['ref'] );
			return $post instanceof WP_Post && 'wp_block' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ? $post->post_content : '';
		}

		if ( 'core/template-part' === $name && isset( $attrs['slug'] ) && is_string( $attrs['slug'] ) && function_exists( 'get_block_template' ) ) {
			$theme    = isset( $attrs['theme'] ) && is_string( $attrs['theme'] ) && '' !== $attrs['theme'] ? $attrs['theme'] : get_stylesheet();
			$template = get_block_template( $theme . '//' . $attrs['slug'], 'wp_template_part' );
			return $template instanceof WP_Block_Template && is_string( $template->content ) ? $template->content : '';
		}

		return '';
	}

	/**
	 * Depth-first search of parsed blocks for a filterable loop of the type.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $type   Content type key.
	 * @param int                              $depth  Pattern references followed so far.
	 * @return array<string, mixed>|null Attributes.
	 */
	private static function find( array $blocks, string $type, int $depth ): ?array {
		foreach ( $blocks as $block ) {
			$name  = $block['blockName'] ?? null;
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			if ( 'core/query' === $name ) {
				$query = is_array( $attrs['query'] ?? null ) ? $attrs['query'] : array();
				if ( ! empty( $attrs['pegwork']['filters'] ) && empty( $query['inherit'] ) && ( $query['postType'] ?? 'post' ) === $type ) {
					return $attrs;
				}
			}

			if ( $depth < self::MAX_DEPTH ) {
				$referenced = self::referenced_content( (string) $name, $attrs );
				if ( '' !== $referenced && str_contains( $referenced, '<!-- wp:' ) ) {
					$found = self::find( parse_blocks( $referenced ), $type, $depth + 1 );
					if ( null !== $found ) {
						return $found;
					}
				}
			}

			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( array() !== $inner ) {
				$found = self::find( $inner, $type, $depth );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
