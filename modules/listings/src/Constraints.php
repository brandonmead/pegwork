<?php
/**
 * The listing constraint pipeline: constraints in, WP_Query args out.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkListings;

use WP_Post;
use WP_Query;

/**
 * Turns listing constraints into WP_Query args, for the archive main query and for Pegwork Query Loops.
 *
 * Constraints (every key optional):
 *
 *     array(
 *         'taxonomies' => array( 'sifg_impact' => array( 'sdg-04', 'sdg-13' ) ), // term slugs, OR within, AND across
 *         'fields'     => array( 'needs_volunteers' => true ),                  // public boolean (bool) or select (value or values, OR)
 *         'search'     => 'data',                                                // core search, becomes `s`
 *         'post__in'   => array( 12 ),                                           // record IDs; an empty list matches nothing
 *         'related'    => array( 'key' => 'story_projects', 'post' => 24 ),      // records linked to post 24 by the relationship
 *     )
 *
 * Every key is re-checked against the registry: a taxonomy the type does not use (or that is not
 * publicly queryable), a private, unknown or non-filterable field, or an invalid value is dropped.
 * A `related` constraint that does not resolve (unknown relationship, a type at neither end, no
 * displayable current post) matches nothing. Listing queries only ever return published records.
 */
final class Constraints {

	/**
	 * Hook the pipeline runs constraints through.
	 */
	public const FILTER = 'pegwork_listing_constraints';

	/**
	 * Most values kept per taxonomy or select field.
	 */
	public const MAX_VALUES = 20;

	/**
	 * Most characters kept of a search.
	 */
	public const MAX_SEARCH = 100;

	/**
	 * Field types a constraint may target.
	 */
	public const FIELD_TYPES = array( 'boolean', 'select' );

	/**
	 * `where` key that picks the record from an options page `post` field.
	 */
	public const OPTION_KEY = 'post_in_option';

	/**
	 * `where` key that lists the records linked to the current post by a relationship.
	 */
	public const RELATED_KEY = 'related';

	/**
	 * Non-persistent cache group for per-request lookups.
	 */
	private const CACHE_GROUP = 'pegwork_listings';

	/**
	 * Constraints from a Query Loop's `pegwork.where` presets.
	 *
	 * Field keys keep their value (to_query_args() checks them); `post_in_option` ("<page>.<field>")
	 * becomes `post__in` with the record chosen on that options page, else the newest published record;
	 * `related` ("<relationship key>") becomes a `related` constraint on the current post.
	 *
	 * @param string               $type    Content type key.
	 * @param array<string, mixed> $where   Presets.
	 * @param int                  $current The current post's ID (0 when there is none), for `related`.
	 * @return array<string, mixed> Constraints.
	 */
	public static function from_where( string $type, array $where, int $current = 0 ): array {
		$constraints = array();
		foreach ( $where as $key => $value ) {
			if ( self::OPTION_KEY === $key ) {
				$constraints['post__in'] = self::option_post_ids( $type, $value );
			} elseif ( self::RELATED_KEY === $key ) {
				$constraints['related'] = array(
					'key'  => is_string( $value ) ? $value : '',
					'post' => $current,
				);
			} elseif ( is_string( $key ) ) {
				$constraints['fields'][ $key ] = $value;
			}
		}
		return $constraints;
	}

	/**
	 * Runs constraints through the `pegwork_listing_constraints` filter.
	 *
	 * @param array<string, mixed> $constraints Constraints so far.
	 * @param string               $type        Content type key.
	 * @param string               $source      `main` (post type archive) or `block` (Query Loop).
	 * @param bool                 $filters     Whether the listing takes filters (always true for `main`).
	 * @return array<string, mixed> Filtered constraints (non-array results become none).
	 */
	public static function filter( array $constraints, string $type, string $source, bool $filters ): array {
		/**
		 * Filters the constraints of a listing query before they become WP_Query args.
		 *
		 * @param array  $constraints Constraints: `taxonomies`, `fields`, `search`, `post__in`, `related` (see Constraints).
		 * @param string $type        Content type key.
		 * @param array  $context     `source` (`main` or `block`) and `filters` (bool).
		 */
		$filtered = apply_filters(
			'pegwork_listing_constraints',
			$constraints,
			$type,
			array(
				'source'  => $source,
				'filters' => $filters,
			)
		);
		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * The one translator: constraints to WP_Query args.
	 *
	 * @param string               $type        Content type key.
	 * @param array<string, mixed> $constraints Constraints (see the class comment).
	 * @return array<string, mixed> Args: always `post_status` `publish`; `tax_query`, `meta_query`, `s` and
	 *                              `post__in` when constrained. Empty for a type that is not in the registry.
	 */
	public static function to_query_args( string $type, array $constraints ): array {
		$registry = pegwork_registry();
		if ( null === $registry->content_type( $type ) ) {
			return array();
		}

		$args    = array( 'post_status' => 'publish' );
		$related = array_key_exists( 'related', $constraints ) ? self::related_args( $type, $constraints['related'] ) : array();

		$tax_query = array();
		foreach ( self::as_array( $constraints['taxonomies'] ?? null ) as $taxonomy => $slugs ) {
			$slugs = self::slugs( $slugs );
			if ( is_string( $taxonomy ) && array() !== $slugs && self::taxonomy_allowed( $type, $taxonomy ) ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $slugs,
					'operator' => 'IN',
				);
			}
		}
		if ( array() !== $tax_query ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Listing filters are taxonomy queries by design.
		}

		$meta_query = array();
		foreach ( self::as_array( $constraints['fields'] ?? null ) as $key => $value ) {
			$clause = is_string( $key ) ? self::field_clause( $type, $key, $value ) : null;
			if ( null !== $clause ) {
				$meta_query[] = $clause;
			}
		}
		foreach ( $related['meta_query'] ?? array() as $clause ) {
			$meta_query[] = $clause;
		}
		if ( array() !== $meta_query ) {
			$args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Boolean and select filters are meta queries by design.
		}

		$search = self::search( $constraints['search'] ?? null );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( array_key_exists( 'post__in', $constraints ) ) {
			$ids              = self::ids( $constraints['post__in'] );
			$args['post__in'] = array() === $ids ? array( 0 ) : $ids;
		}
		if ( isset( $related['post__in'] ) ) {
			$ids              = self::ids( $related['post__in'] );
			$ids              = isset( $args['post__in'] ) ? array_values( array_intersect( $args['post__in'], $ids ) ) : $ids;
			$args['post__in'] = array() === $ids ? array( 0 ) : $ids;
		}

		return $args;
	}

	/**
	 * The relationship part of a listing query: pegwork_related_query_args() for the current post, when
	 * the relationship links the listing's type to the post's type.
	 *
	 * @param string $type    Content type key of the listing.
	 * @param mixed  $related `array( 'key' => <relationship key>, 'post' => <current post ID> )`.
	 * @return array<string, mixed> `meta_query` (listing the "from" type from a "to" post) or `post__in` (listing
	 *                              the "to" type from a "from" post). `post__in` `array( 0 )`, which matches
	 *                              nothing, when the constraint does not resolve.
	 */
	public static function related_args( string $type, mixed $related ): array {
		$none = array( 'post__in' => array( 0 ) );
		$post = self::related_post( is_array( $related ) ? ( $related['key'] ?? null ) : null, is_array( $related ) ? ( $related['post'] ?? null ) : null );
		if ( null === $post ) {
			return $none;
		}

		$args = pegwork_related_query_args( $post, (string) $related['key'] );
		if ( ( $args['post_type'] ?? null ) !== $type ) {
			return $none;
		}
		return isset( $args['meta_query'] ) && is_array( $args['meta_query'] )
			? array( 'meta_query' => $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Relationship lookup (docs/relationships.md).
			: array( 'post__in' => $args['post__in'] ?? array( 0 ) );
	}

	/**
	 * Whether a record has any published record at the other end of a relationship.
	 *
	 * @param WP_Post $post Record (must be of a type at one end).
	 * @param string  $key  Relationship key.
	 * @return bool False also for an unknown relationship or a record of neither type.
	 */
	public static function has_related( WP_Post $post, string $key ): bool {
		$relationship = pegwork_registry()->relationships()[ $key ] ?? null;
		if ( null === $relationship || ! in_array( $post->post_type, array( $relationship['from'], $relationship['to'] ), true ) ) {
			return false;
		}

		$args = pegwork_related_query_args( $post, $key );
		if ( array( 0 ) === ( $args['post__in'] ?? null ) ) {
			return false;
		}

		$query = new WP_Query(
			array_merge(
				$args,
				array(
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
		return array() !== $query->posts;
	}

	/**
	 * The current post of a `related` constraint: a record of a type at one end of a known relationship,
	 * publicly viewable and not password-protected (the rule for showing any of its values).
	 *
	 * @param mixed $key     Relationship key.
	 * @param mixed $post_id Current post ID.
	 */
	private static function related_post( mixed $key, mixed $post_id ): ?WP_Post {
		if ( ! is_string( $key ) || ! is_numeric( $post_id ) || (int) $post_id <= 0 ) {
			return null;
		}
		$relationship = pegwork_registry()->relationships()[ $key ] ?? null;
		$post         = get_post( (int) $post_id );
		if ( null === $relationship || ! $post instanceof WP_Post || ! Fields::displayable( $post ) ) {
			return null;
		}
		return in_array( $post->post_type, array( $relationship['from'], $relationship['to'] ), true ) ? $post : null;
	}

	/**
	 * Merges translated args into existing WP_Query vars (the Query Loop's own settings).
	 *
	 * Tax and meta queries combine with AND, searches join, `post__in` intersects (no overlap matches
	 * nothing), `post_status` is replaced.
	 *
	 * @param array<string, mixed> $vars Existing query vars.
	 * @param array<string, mixed> $args Args from to_query_args().
	 * @return array<string, mixed> Merged vars.
	 */
	public static function merge( array $vars, array $args ): array {
		foreach ( array( 'tax_query', 'meta_query' ) as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$existing     = isset( $vars[ $key ] ) && is_array( $vars[ $key ] ) ? $vars[ $key ] : array();
			$vars[ $key ] = self::has_clauses( $existing )
				? array(
					'relation' => 'AND',
					$existing,
					$args[ $key ],
				)
				: $args[ $key ];
		}

		if ( isset( $args['s'] ) ) {
			$existing  = isset( $vars['s'] ) && is_string( $vars['s'] ) ? trim( $vars['s'] ) : '';
			$vars['s'] = '' === $existing ? $args['s'] : $existing . ' ' . $args['s'];
		}

		if ( isset( $args['post__in'] ) ) {
			$existing         = isset( $vars['post__in'] ) && is_array( $vars['post__in'] ) ? array_map( 'intval', $vars['post__in'] ) : array();
			$ids              = array() === $existing ? $args['post__in'] : array_values( array_intersect( $existing, $args['post__in'] ) );
			$vars['post__in'] = array() === $ids ? array( 0 ) : $ids;
		}

		if ( isset( $args['post_status'] ) ) {
			$vars['post_status'] = $args['post_status'];
		}

		return $vars;
	}

	/**
	 * Sets translated args on a WP_Query (the main query, in pre_get_posts).
	 *
	 * @param WP_Query             $query Query.
	 * @param array<string, mixed> $args  Args from to_query_args().
	 */
	public static function apply( WP_Query $query, array $args ): void {
		$merged = self::merge( $query->query_vars, $args );
		foreach ( array( 'tax_query', 'meta_query', 's', 'post__in', 'post_status' ) as $key ) {
			if ( array_key_exists( $key, $merged ) ) {
				$query->set( $key, $merged[ $key ] );
			}
		}
	}

	/**
	 * The meta clause for one field constraint.
	 *
	 * @param string $type  Content type key.
	 * @param string $key   Field key.
	 * @param mixed  $value Boolean field: bool (`1`, `0`, `'true'`, `'false'` accepted). Select: value or list.
	 * @return array<string, mixed>|null The clause, or null when the field or value is not allowed.
	 */
	private static function field_clause( string $type, string $key, mixed $value ): ?array {
		$field    = Fields::public_field( $type, $key );
		$meta_key = pegwork_registry()->meta_key( $type, $key );
		if ( null === $field || null === $meta_key || ! in_array( $field['type'], self::FIELD_TYPES, true ) ) {
			return null;
		}

		if ( 'boolean' === $field['type'] ) {
			$on = self::boolean( $value );
			if ( null === $on ) {
				return null;
			}
			if ( $on ) {
				return array(
					'key'     => $meta_key,
					'value'   => '1',
					'compare' => '=',
				);
			}
			return array(
				'relation' => 'OR',
				array(
					'key'     => $meta_key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => $meta_key,
					'value'   => '1',
					'compare' => '!=',
				),
			);
		}

		$allowed = array_map( 'strval', array_column( $field['options'] ?? array(), 'value' ) );
		$values  = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			if ( ( is_string( $one ) || is_int( $one ) ) && in_array( (string) $one, $allowed, true ) && ! in_array( (string) $one, $values, true ) ) {
				$values[] = (string) $one;
			}
		}
		$values = array_slice( $values, 0, self::MAX_VALUES );
		if ( array() === $values ) {
			return null;
		}

		return 1 === count( $values )
			? array(
				'key'     => $meta_key,
				'value'   => $values[0],
				'compare' => '=',
			)
			: array(
				'key'     => $meta_key,
				'value'   => $values,
				'compare' => 'IN',
			);
	}

	/**
	 * Whether a taxonomy may constrain a type's listing: used by the type and publicly queryable.
	 *
	 * @param string $type     Content type key.
	 * @param string $taxonomy Taxonomy key.
	 */
	private static function taxonomy_allowed( string $type, string $taxonomy ): bool {
		$usages = pegwork_registry()->content_type( $type )['taxonomies'] ?? array();
		return in_array( $taxonomy, array_column( $usages, 'key' ), true ) && is_taxonomy_viewable( $taxonomy );
	}

	/**
	 * Term slugs: strings already in slug form, de-duplicated, at most MAX_VALUES.
	 *
	 * @param mixed $slugs Slug or list.
	 * @return list<string>
	 */
	private static function slugs( mixed $slugs ): array {
		$list = array();
		foreach ( is_array( $slugs ) ? $slugs : array( $slugs ) as $slug ) {
			if ( is_string( $slug ) && '' !== $slug && sanitize_title( $slug ) === $slug && ! in_array( $slug, $list, true ) ) {
				$list[] = $slug;
			}
		}
		return array_slice( $list, 0, self::MAX_VALUES );
	}

	/**
	 * A boolean constraint value.
	 *
	 * @param mixed $value Value.
	 * @return bool|null The boolean, or null when it is not one.
	 */
	private static function boolean( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( in_array( $value, array( 1, '1', 'true' ), true ) ) {
			return true;
		}
		if ( in_array( $value, array( 0, '0', 'false' ), true ) ) {
			return false;
		}
		return null;
	}

	/**
	 * A search string: plain text, at most MAX_SEARCH characters.
	 *
	 * @param mixed $search Search.
	 */
	private static function search( mixed $search ): string {
		if ( ! is_string( $search ) ) {
			return '';
		}
		return trim( mb_substr( sanitize_text_field( $search ), 0, self::MAX_SEARCH ) );
	}

	/**
	 * Positive, unique record IDs.
	 *
	 * @param mixed $ids IDs.
	 * @return list<int>
	 */
	private static function ids( mixed $ids ): array {
		$list = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$list[] = (int) $id;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Whether existing tax or meta query vars hold any clause.
	 *
	 * @param array<int|string, mixed> $query Query vars value.
	 */
	private static function has_clauses( array $query ): bool {
		unset( $query['relation'] );
		return array() !== array_filter( $query, 'is_array' );
	}

	/**
	 * An array value, or none.
	 *
	 * @param mixed $value Value.
	 * @return array<int|string, mixed>
	 */
	private static function as_array( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The record for a `post_in_option` preset: the published record of the type chosen in the options
	 * page `post` field, else the newest published record of the type.
	 *
	 * @param string $type Content type key.
	 * @param mixed  $ref  `"<page>.<field>"`.
	 * @return list<int> One ID, or none when the type has no published record.
	 */
	public static function option_post_ids( string $type, mixed $ref ): array {
		$id = self::option_post( $type, $ref );
		if ( null === $id ) {
			$id = self::newest( $type );
		}
		return null === $id ? array() : array( $id );
	}

	/**
	 * The published record of the type chosen in an options page `post` field.
	 *
	 * The field must be a public `post` field for this type. Reads it with pegwork_get_option() when
	 * that function exists (options pages module).
	 *
	 * @param string $type Content type key.
	 * @param mixed  $ref  `"<page>.<field>"`.
	 * @return int|null The ID, or null when the reference, the stored value or the record does not qualify.
	 */
	private static function option_post( string $type, mixed $ref ): ?int {
		if ( ! is_string( $ref ) || ! str_contains( $ref, '.' ) || ! function_exists( 'pegwork_get_option' ) ) {
			return null;
		}
		list( $page_key, $field_key ) = explode( '.', $ref, 2 );

		$field = null;
		foreach ( pegwork_registry()->options_page( $page_key )['fields'] ?? array() as $candidate ) {
			if ( ( $candidate['key'] ?? null ) === $field_key ) {
				$field = $candidate;
			}
		}
		if ( null === $field || 'post' !== ( $field['type'] ?? null ) || 'public' !== ( $field['visibility'] ?? 'public' ) || ( $field['post_type'] ?? null ) !== $type ) {
			return null;
		}

		$value = pegwork_get_option( $page_key, $field_key );
		if ( ! is_numeric( $value ) || (int) $value <= 0 ) {
			return null;
		}

		$post = get_post( (int) $value );
		if ( null === $post || $type !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return $post->ID;
	}

	/**
	 * The newest published record of a type, cached for the request until posts change.
	 *
	 * @param string $type Content type key.
	 * @return int|null The ID, or null when there is none.
	 */
	private static function newest( string $type ): ?int {
		wp_cache_add_non_persistent_groups( array( self::CACHE_GROUP ) );
		$cache_key = 'newest:' . $type . ':' . wp_cache_get_last_changed( 'posts' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_int( $cached ) ) {
			return $cached > 0 ? $cached : null;
		}

		$ids = get_posts(
			array(
				'post_type'     => $type,
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$id  = isset( $ids[0] ) ? (int) $ids[0] : 0;
		wp_cache_set( $cache_key, $id, self::CACHE_GROUP );
		return $id > 0 ? $id : null;
	}
}
