<?php
/**
 * Validator rules for the `listing` section of a content type (Phase 2 display).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry\Rules;

/**
 * `listing` declares what a directory of the type may filter by. It is the public allowlist of URL parameters,
 * so private fields and query-var names can never appear in it.
 *
 * Used by Pegwork\Registry\Validator, whose helpers (props, type, key, add, ...) it calls.
 */
trait ListingRules {

	/**
	 * URL parameter used by listing search; never a filter key.
	 */
	public const LISTING_SEARCH_PARAM = 'q';

	/**
	 * Field types a listing can filter on.
	 */
	public const LISTING_FIELD_TYPES = array( 'boolean', 'select' );

	/**
	 * Validates the shape of a `listing` object.
	 *
	 * @param mixed  $listing Decoded listing.
	 * @param string $pointer JSON pointer of the listing.
	 */
	private function listing( mixed $listing, string $pointer ): void {
		$this->props(
			$listing,
			$pointer,
			array(
				'search'  => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'filters' => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->listing_filter( $item, $q ) ),
			)
		);
	}

	/**
	 * Validates one listing filter on its own: shape, key and exactly one target.
	 *
	 * @param mixed  $filter  Decoded filter.
	 * @param string $pointer JSON pointer of the filter.
	 */
	private function listing_filter( mixed $filter, string $pointer ): void {
		$is_object = $this->props(
			$filter,
			$pointer,
			array(
				'key'      => function ( $v, $p ) {
					if ( $this->key( $v, $p ) && ( self::LISTING_SEARCH_PARAM === $v || in_array( $v, self::RESERVED_KEYS, true ) || str_starts_with( $v, 'wp_' ) ) ) {
						$this->add(
							'listing_reserved_key',
							$p,
							/* translators: %s: filter key. */
							sprintf( __( 'Filter key "%s" is reserved (a WordPress query var, "q" or a "wp_" name); choose another URL parameter.', 'pegwork' ), $v )
						);
					}
				},
				'taxonomy' => fn( $v, $p ) => $this->key( $v, $p ),
				'field'    => fn( $v, $p ) => $this->key( $v, $p ),
				'label'    => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'multiple' => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
			),
			array( 'key' )
		);

		if ( $is_object && array_key_exists( 'taxonomy', $filter ) === array_key_exists( 'field', $filter ) ) {
			$this->add( 'listing_one_target', $pointer, __( 'A filter needs exactly one of "taxonomy" or "field".', 'pegwork' ) );
		}
	}

	/**
	 * Checks filters against the content type that holds them: duplicate keys, taxonomy usage and field rules.
	 *
	 * @param array<string, mixed> $type    Decoded content type (may be invalid elsewhere).
	 * @param string               $pointer JSON pointer of the content type.
	 */
	private function listing_targets( array $type, string $pointer ): void {
		$filters = is_array( $type['listing'] ?? null ) ? ( $type['listing']['filters'] ?? null ) : null;
		if ( ! is_array( $filters ) || ! array_is_list( $filters ) ) {
			return;
		}

		$taxonomies = array();
		foreach ( is_array( $type['taxonomies'] ?? null ) ? $type['taxonomies'] : array() as $usage ) {
			if ( is_array( $usage ) && is_string( $usage['key'] ?? null ) ) {
				$taxonomies[ $usage['key'] ] = true;
			}
		}
		$fields = array();
		foreach ( is_array( $type['fields'] ?? null ) ? $type['fields'] : array() as $field ) {
			if ( is_array( $field ) && is_string( $field['key'] ?? null ) ) {
				$fields[ $field['key'] ] = $field;
			}
		}

		$seen = array();
		foreach ( $filters as $index => $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$at  = $pointer . '/listing/filters/' . $index;
			$key = $filter['key'] ?? null;
			if ( is_string( $key ) ) {
				if ( isset( $seen[ $key ] ) ) {
					/* translators: %s: filter key. */
					$this->add( 'listing_duplicate_key', $at . '/key', sprintf( __( 'Filter key "%s" is already used by another filter of this listing.', 'pegwork' ), $key ) );
				}
				$seen[ $key ] = true;
			}

			$taxonomy = $filter['taxonomy'] ?? null;
			if ( is_string( $taxonomy ) && ! isset( $taxonomies[ $taxonomy ] ) ) {
				/* translators: %s: taxonomy key. */
				$this->add( 'listing_unknown_target', $at . '/taxonomy', sprintf( __( 'Taxonomy "%s" is not in this content type\'s "taxonomies".', 'pegwork' ), $taxonomy ) );
			}

			$name = $filter['field'] ?? null;
			if ( ! is_string( $name ) ) {
				continue;
			}
			$field = $fields[ $name ] ?? null;
			if ( null === $field ) {
				/* translators: %s: field key. */
				$this->add( 'listing_unknown_target', $at . '/field', sprintf( __( 'Field "%s" is not defined on this content type.', 'pegwork' ), $name ) );
				continue;
			}
			if ( 'private' === ( $field['visibility'] ?? 'public' ) ) {
				/* translators: %s: field key. */
				$this->add( 'listing_private_field', $at . '/field', sprintf( __( 'Field "%s" is private; listings can only filter on public fields.', 'pegwork' ), $name ) );
				continue;
			}
			if ( ! in_array( $field['type'] ?? null, self::LISTING_FIELD_TYPES, true ) ) {
				$this->add(
					'listing_field_type',
					$at . '/field',
					/* translators: 1: field key, 2: field type. */
					sprintf( __( 'Field "%1$s" has type "%2$s"; listings filter only on boolean and select fields.', 'pegwork' ), $name, (string) ( $field['type'] ?? '' ) )
				);
				continue;
			}
			if ( 'boolean' === $field['type'] && true === ( $filter['multiple'] ?? false ) ) {
				/* translators: %s: field key. */
				$this->add( 'listing_field_type', $at . '/multiple', sprintf( __( 'Field "%s" is a boolean; boolean filters cannot be "multiple".', 'pegwork' ), $name ) );
			}
		}
	}

	/**
	 * Checks filter keys against every defined content type and taxonomy key (both are core query vars).
	 *
	 * @param array<string, mixed>                $type    Valid content type.
	 * @param string                              $pointer JSON pointer of the content type.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 */
	private function listing_references( array $type, string $pointer, array $known ): void {
		foreach ( $type['listing']['filters'] ?? array() as $index => $filter ) {
			foreach ( array( 'content_type', 'taxonomy' ) as $kind ) {
				if ( isset( $known[ $kind ][ $filter['key'] ] ) ) {
					$this->add(
						'listing_reserved_key',
						$pointer . '/listing/filters/' . $index . '/key',
						/* translators: %s: filter key. */
						sprintf( __( 'Filter key "%s" is a content type or taxonomy key, which WordPress reads as a query var; choose another URL parameter.', 'pegwork' ), $filter['key'] )
					);
					break;
				}
			}
		}
	}
}
