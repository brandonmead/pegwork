<?php
/**
 * The pegwork/conditional block ("Show if").
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Block;
use WP_Block_Type_Registry;
use WP_Post;

/**
 * Renders inner blocks only when every condition on the context record holds. Fails closed:
 * an unknown or private field, a taxonomy not on the type, a relationship the type is not part of,
 * an unknown operator, a missing record or a record that is not displayable renders nothing.
 */
final class Conditional {

	/**
	 * Block name.
	 */
	public const NAME = 'pegwork/conditional';

	/**
	 * Registers the block type. Safe to call more than once.
	 */
	public static function register(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}

		register_block_type(
			PEGWORK_LISTINGS_DIR . 'blocks/conditional',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Rendered inner blocks.
	 * @param WP_Block|null        $block      Block instance.
	 * @return string Inner blocks, or ''.
	 */
	public static function render( $attributes, $content, $block = null ): string {
		// Core swaps a schema-invalid attribute for its default ([]), which would pass; read the saved value instead.
		$conditions = $block instanceof WP_Block && array_key_exists( 'conditions', $block->parsed_block['attrs'] ?? array() )
			? $block->parsed_block['attrs']['conditions']
			: ( $attributes['conditions'] ?? array() );
		if ( ! is_array( $conditions ) ) {
			return '';
		}

		return self::passes( $conditions, $block instanceof WP_Block ? $block->context : array() ) ? (string) $content : '';
	}

	/**
	 * Whether all conditions hold (AND). No conditions always pass.
	 *
	 * @param array<int, mixed>    $conditions Conditions.
	 * @param array<string, mixed> $context    Block context (`postId`, `postType`).
	 */
	public static function passes( array $conditions, array $context ): bool {
		if ( array() === $conditions ) {
			return true;
		}

		$post = Fields::context_post( $context );
		if ( null === $post || ! Fields::displayable( $post ) ) {
			return false;
		}

		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) || ! self::holds( $post, $condition ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Evaluates one condition.
	 *
	 * @param WP_Post              $post      Record.
	 * @param array<string, mixed> $condition `{ field, op, value }`, `{ taxonomy, op, terms }` or `{ relationship, op }`.
	 */
	private static function holds( WP_Post $post, array $condition ): bool {
		$op = $condition['op'] ?? null;

		if ( isset( $condition['relationship'] ) && is_string( $condition['relationship'] ) ) {
			return self::related_holds( $post, $condition['relationship'], $op );
		}
		if ( isset( $condition['taxonomy'] ) && is_string( $condition['taxonomy'] ) ) {
			return self::terms_hold( $post, $condition['taxonomy'], $op, $condition['terms'] ?? array() );
		}
		if ( isset( $condition['field'] ) && is_string( $condition['field'] ) ) {
			return self::field_holds( $post, $condition['field'], $op, $condition['value'] ?? null );
		}
		return false;
	}

	/**
	 * Relationship condition: `not_empty` when the record has a published record at the other end of the
	 * relationship, `empty` when it has none. So a "Related stories" heading and its loop can hide together.
	 *
	 * @param WP_Post $post Record.
	 * @param string  $key  Relationship key; the record's type must be at one end.
	 * @param mixed   $op   Operator.
	 */
	private static function related_holds( WP_Post $post, string $key, mixed $op ): bool {
		$relationship = pegwork_registry()->relationships()[ $key ] ?? null;
		if ( null === $relationship || ! in_array( $post->post_type, array( $relationship['from'], $relationship['to'] ), true ) ) {
			return false;
		}

		return match ( $op ) {
			'not_empty' => Constraints::has_related( $post, $key ),
			'empty'     => ! Constraints::has_related( $post, $key ),
			default     => false,
		};
	}

	/**
	 * Taxonomy condition: `in` or `not_in` a list of term slugs.
	 *
	 * @param WP_Post $post     Record.
	 * @param string  $taxonomy Taxonomy key; must be used by the record's content type.
	 * @param mixed   $op       Operator.
	 * @param mixed   $terms    Term slugs.
	 */
	private static function terms_hold( WP_Post $post, string $taxonomy, mixed $op, mixed $terms ): bool {
		$type = pegwork_registry()->content_type( $post->post_type );
		if ( null === $type || ! in_array( $taxonomy, array_column( $type['taxonomies'] ?? array(), 'key' ), true ) ) {
			return false;
		}
		if ( ! in_array( $op, array( 'in', 'not_in' ), true ) || ! is_array( $terms ) ) {
			return false;
		}

		$assigned = get_the_terms( $post, $taxonomy );
		if ( is_wp_error( $assigned ) ) {
			return false;
		}
		$slugs = is_array( $assigned ) ? wp_list_pluck( $assigned, 'slug' ) : array();
		$has   = array() !== array_intersect( array_map( 'strval', array_filter( $terms, 'is_scalar' ) ), $slugs );

		return 'in' === $op ? $has : ! $has;
	}

	/**
	 * Field condition: `equals`, `not_equals`, `empty` or `not_empty`, on the visitor-visible value
	 * (an unmet `show_when` counts as empty).
	 *
	 * @param WP_Post $post     Record.
	 * @param string  $key      Field key; must be a public field of the record's content type.
	 * @param mixed   $op       Operator.
	 * @param mixed   $expected Value for equals and not_equals.
	 */
	private static function field_holds( WP_Post $post, string $key, mixed $op, mixed $expected ): bool {
		$field = Fields::public_field( $post->post_type, $key );
		if ( null === $field ) {
			return false;
		}

		$value = Fields::value( $post, $key );

		return match ( $op ) {
			'empty'      => self::is_empty( $value ),
			'not_empty'  => ! self::is_empty( $value ),
			'equals'     => self::equals( $field['type'], $value, $expected ),
			'not_equals' => ! self::equals( $field['type'], $value, $expected ),
			default      => false,
		};
	}

	/**
	 * Whether a typed value is empty (null, false or '').
	 *
	 * @param mixed $value Typed value.
	 */
	private static function is_empty( mixed $value ): bool {
		return null === $value || false === $value || '' === $value;
	}

	/**
	 * Typed equality: booleans as booleans, numbers numerically, everything else as strings.
	 *
	 * @param string $type     Field type.
	 * @param mixed  $value    Typed value.
	 * @param mixed  $expected Condition value.
	 */
	private static function equals( string $type, mixed $value, mixed $expected ): bool {
		if ( ! is_scalar( $expected ) ) {
			return false;
		}
		if ( 'boolean' === $type ) {
			return ( true === $value ) === rest_sanitize_boolean( $expected );
		}
		if ( 'number' === $type ) {
			return null !== $value && is_numeric( $expected ) && (float) $value === (float) $expected;
		}
		return null !== $value && (string) $value === (string) $expected;
	}
}
