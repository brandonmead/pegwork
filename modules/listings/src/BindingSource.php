<?php
/**
 * The pegwork/field block bindings source.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Block;

/**
 * Binds core paragraph, heading, button and image attributes to public Pegwork fields, and
 * renders nothing for blocks whose Pegwork bindings are all empty.
 */
final class BindingSource {

	/**
	 * Source name.
	 */
	public const NAME = 'pegwork/field';

	/**
	 * Blocks whose bound attributes core processes (WordPress 6.7 and later).
	 */
	public const BLOCKS = array( 'core/paragraph', 'core/heading', 'core/button', 'core/image' );

	/**
	 * Registers the source and the hide-when-empty filter. Safe to call more than once.
	 */
	public static function register(): void {
		if ( null === get_block_bindings_source( self::NAME ) ) {
			register_block_bindings_source(
				self::NAME,
				array(
					'label'              => __( 'Pegwork field', 'pegwork' ),
					'get_value_callback' => array( self::class, 'get_value' ),
					'uses_context'       => array( 'postId', 'postType' ),
				)
			);
		}

		if ( ! has_filter( 'render_block', array( self::class, 'hide_empty' ) ) ) {
			add_filter( 'render_block', array( self::class, 'hide_empty' ), 10, 3 );
		}
	}

	/**
	 * Core's get_value_callback.
	 *
	 * @param array<string, mixed> $args      Binding args.
	 * @param WP_Block|null        $block     Block instance.
	 * @param string               $attribute Bound attribute.
	 * @return string|null Output, or null (core then keeps the block's own markup).
	 */
	public static function get_value( array $args, $block, string $attribute ): ?string {
		return self::resolve( $args, $block instanceof WP_Block ? $block->context : array(), $attribute );
	}

	/**
	 * Resolves a binding against block context.
	 *
	 * @param array<string, mixed> $args      Binding args: `key` (required), `format`, `prefix`, `label`.
	 * @param array<string, mixed> $context   Block context (`postId`, `postType`).
	 * @param string               $attribute Bound attribute.
	 * @return string|null Escaped output, or null when the field is unknown, private, hidden or empty.
	 */
	public static function resolve( array $args, array $context, string $attribute ): ?string {
		$key = $args['key'] ?? null;
		if ( ! is_string( $key ) || '' === $key ) {
			return null;
		}

		$post = Fields::context_post( $context );
		if ( null === $post ) {
			return null;
		}

		$field = Fields::public_field( $post->post_type, $key );
		if ( null === $field ) {
			return null;
		}

		return Formatter::format( $field, Fields::value( $post, $key ), $args, $attribute );
	}

	/**
	 * Renders '' for a supported block whose pegwork/field bindings all resolve empty, unless one
	 * of them sets `keepEmpty`. Hooked to render_block.
	 *
	 * @param string               $content Rendered block.
	 * @param array<string, mixed> $parsed  Parsed block.
	 * @param WP_Block|null        $block   Block instance (its context includes the source's uses_context).
	 * @return string The content, or ''.
	 */
	public static function hide_empty( $content, $parsed, $block = null ) {
		$bindings = $parsed['attrs']['metadata']['bindings'] ?? null;
		if ( ! is_array( $bindings ) || ! in_array( $parsed['blockName'] ?? null, self::BLOCKS, true ) ) {
			return $content;
		}

		$context = $block instanceof WP_Block ? $block->context : array();
		$ours    = 0;
		foreach ( $bindings as $attribute => $binding ) {
			if ( ! is_array( $binding ) || self::NAME !== ( $binding['source'] ?? null ) ) {
				continue;
			}
			$args = isset( $binding['args'] ) && is_array( $binding['args'] ) ? $binding['args'] : array();
			if ( ! empty( $args['keepEmpty'] ) || null !== self::resolve( $args, $context, (string) $attribute ) ) {
				return $content;
			}
			++$ours;
		}

		return $ours > 0 ? '' : $content;
	}
}
