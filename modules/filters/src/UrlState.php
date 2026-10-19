<?php
/**
 * Filter state from the URL: the only state the filters keep.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

/**
 * Parses query parameters against a Definition and turns the result into listing constraints.
 *
 * Pure: no globals, no database. The URL contract:
 *
 * - `q`: search, when the listing enables it; plain text (sanitize_text_field), at most 100 characters.
 * - `<key>=a,b` or `<key>[]=a&<key>[]=b`: values of one filter. Taxonomy values must be term slugs of
 *   that taxonomy, select values must be option values, a boolean takes only `1`. At most 20 values;
 *   a single-value filter keeps the first valid one.
 * - Anything else (undeclared keys, private fields, `meta_key`, paging, unknown values) is ignored.
 *
 * State shape: `array( 'q' => string, 'values' => array( <key> => list<string> ) )`; every declared key
 * is present, with an empty list when unset.
 */
final class UrlState {

	/**
	 * The search parameter.
	 */
	public const SEARCH = 'q';

	/**
	 * Most values kept per filter.
	 */
	public const MAX_VALUES = 20;

	/**
	 * Most characters kept of a search.
	 */
	public const MAX_SEARCH = 100;

	/**
	 * State from query parameters.
	 *
	 * @param array<string, mixed> $definition From Definition::for_type().
	 * @param array<string, mixed> $query      Unslashed query parameters (e.g. wp_unslash( $_GET )).
	 * @return array{q: string, values: array<string, list<string>>}
	 */
	public static function parse( array $definition, array $query ): array {
		$state = array(
			'q'      => '',
			'values' => array(),
		);

		if ( ! empty( $definition['search'] ) && isset( $query[ self::SEARCH ] ) && is_string( $query[ self::SEARCH ] ) ) {
			$state['q'] = trim( mb_substr( sanitize_text_field( $query[ self::SEARCH ] ), 0, self::MAX_SEARCH ) );
		}

		foreach ( $definition['filters'] ?? array() as $filter ) {
			$state['values'][ $filter['key'] ] = self::values( $filter, $query[ $filter['key'] ] ?? null );
		}

		return $state;
	}

	/**
	 * Valid values of one filter.
	 *
	 * @param array<string, mixed> $filter Filter from the definition.
	 * @param mixed                $raw    Raw parameter: string (comma separated) or list.
	 * @return list<string>
	 */
	private static function values( array $filter, mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$allowed = 'boolean' === $filter['kind'] ? array( '1' ) : array_column( $filter['options'] ?? array(), 'value' );
		$values  = array();
		foreach ( $raw as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( in_array( $value, $allowed, true ) && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		$limit = empty( $filter['multiple'] ) ? 1 : self::MAX_VALUES;
		return array_slice( $values, 0, $limit );
	}

	/**
	 * Whether any filter or the search is set.
	 *
	 * @param array{q: string, values: array<string, list<string>>} $state State.
	 */
	public static function active( array $state ): bool {
		if ( '' !== $state['q'] ) {
			return true;
		}
		foreach ( $state['values'] as $values ) {
			if ( array() !== $values ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Listing constraints for the state (see the `pegwork_listing_constraints` filter).
	 *
	 * @param array<string, mixed>                                  $definition Definition.
	 * @param array{q: string, values: array<string, list<string>>} $state      State.
	 * @return array<string, mixed> `taxonomies`, `fields` and `search` where set.
	 */
	public static function to_constraints( array $definition, array $state ): array {
		$constraints = array();
		foreach ( $definition['filters'] ?? array() as $filter ) {
			$values = $state['values'][ $filter['key'] ] ?? array();
			if ( array() === $values ) {
				continue;
			}
			if ( 'taxonomy' === $filter['kind'] ) {
				$constraints['taxonomies'][ $filter['target'] ] = $values;
			} elseif ( 'boolean' === $filter['kind'] ) {
				$constraints['fields'][ $filter['target'] ] = true;
			} else {
				$constraints['fields'][ $filter['target'] ] = empty( $filter['multiple'] ) ? $values[0] : $values;
			}
		}
		if ( '' !== $state['q'] ) {
			$constraints['search'] = $state['q'];
		}
		return $constraints;
	}

	/**
	 * Merges URL constraints into constraints already set (a loop's `where` presets): an existing
	 * taxonomy or field constraint wins over the URL for the same target; searches join.
	 *
	 * @param array<string, mixed> $constraints Existing constraints.
	 * @param array<string, mixed> $url         Constraints from to_constraints().
	 * @return array<string, mixed>
	 */
	public static function merge( array $constraints, array $url ): array {
		foreach ( array( 'taxonomies', 'fields' ) as $group ) {
			foreach ( $url[ $group ] ?? array() as $target => $value ) {
				if ( ! isset( $constraints[ $group ] ) || ! is_array( $constraints[ $group ] ) ) {
					$constraints[ $group ] = array();
				}
				if ( ! array_key_exists( $target, $constraints[ $group ] ) ) {
					$constraints[ $group ][ $target ] = $value;
				}
			}
		}
		if ( isset( $url['search'] ) ) {
			$existing              = isset( $constraints['search'] ) && is_string( $constraints['search'] ) ? trim( $constraints['search'] ) : '';
			$constraints['search'] = '' === $existing ? $url['search'] : $existing . ' ' . $url['search'];
		}
		return $constraints;
	}

	/**
	 * Query parameters for a state, in the comma form (for links; the JS writes the same form).
	 *
	 * @param array{q: string, values: array<string, list<string>>} $state State.
	 * @return array<string, string>
	 */
	public static function to_query( array $state ): array {
		$query = array();
		if ( '' !== $state['q'] ) {
			$query[ self::SEARCH ] = $state['q'];
		}
		foreach ( $state['values'] as $key => $values ) {
			if ( array() !== $values ) {
				$query[ $key ] = implode( ',', $values );
			}
		}
		return $query;
	}
}
