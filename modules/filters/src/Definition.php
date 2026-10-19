<?php
/**
 * What a content type's directories may filter by, resolved for rendering and URL parsing.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkFilters;

use WP_Term;

/**
 * Builds the filter definition of a type from its registry `listing` section: labels resolved at runtime
 * (registry label edits propagate), allowed values (term slugs, select options), and the search switch.
 *
 * Definition shape:
 *
 *     array(
 *         'type'    => 'sifg_project',
 *         'plural'  => 'Projects',
 *         'search'  => true,
 *         'filters' => array(
 *             array( 'key' => 'impact', 'kind' => 'taxonomy', 'target' => 'sifg_impact', 'multiple' => true,
 *                    'label' => 'Impact category', 'options' => array( array( 'value' => 'sdg-04', 'label' => 'Quality Education' ) ) ),
 *             array( 'key' => 'volunteers', 'kind' => 'boolean', 'target' => 'needs_volunteers', 'multiple' => false,
 *                    'label' => 'Needs volunteers', 'options' => array() ),
 *         ),
 *     )
 *
 * `kind` is `taxonomy`, `boolean` or `select`. Filters on private, unknown or non-filterable fields and
 * taxonomies the type does not use are left out (defense in depth behind the Validator).
 */
final class Definition {

	/**
	 * Most terms offered (and accepted) per taxonomy filter.
	 */
	public const MAX_TERMS = 200;

	/**
	 * Per-request cache: definition by type and terms cache generation.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * The definition of a type.
	 *
	 * @param string $type Content type key.
	 * @return array<string, mixed>|null The definition, or null when the type is unknown or has no `listing`.
	 */
	public static function for_type( string $type ): ?array {
		$cache_key = $type . ':' . wp_cache_get_last_changed( 'terms' ) . ':' . spl_object_id( pegwork_registry() );
		if ( ! array_key_exists( $cache_key, self::$cache ) ) {
			self::$cache[ $cache_key ] = self::build( $type );
		}
		return self::$cache[ $cache_key ];
	}

	/**
	 * Builds a definition.
	 *
	 * @param string $type Content type key.
	 * @return array<string, mixed>|null
	 */
	private static function build( string $type ): ?array {
		$registry = pegwork_registry();
		$listing  = $registry->listing( $type );
		$content  = $registry->content_type( $type );
		if ( null === $listing || null === $content ) {
			return null;
		}

		$used    = array_column( $content['taxonomies'] ?? array(), 'key' );
		$public  = $registry->fields( $type, 'public' );
		$filters = array();

		$targets = array();
		foreach ( $listing['filters'] ?? array() as $filter ) {
			if ( is_array( $filter ) && isset( $filter['taxonomy'] ) && is_string( $filter['taxonomy'] ) && in_array( $filter['taxonomy'], $used, true ) && taxonomy_exists( $filter['taxonomy'] ) ) {
				$targets[] = $filter['taxonomy'];
			}
		}
		$terms = self::terms_by_taxonomy( array_values( array_unique( $targets ) ) );

		foreach ( $listing['filters'] ?? array() as $filter ) {
			if ( ! is_array( $filter ) || ! isset( $filter['key'] ) || ! is_string( $filter['key'] ) ) {
				continue;
			}
			$label = isset( $filter['label'] ) && is_string( $filter['label'] ) && '' !== $filter['label'] ? $filter['label'] : null;

			if ( isset( $filter['taxonomy'] ) && is_string( $filter['taxonomy'] ) ) {
				$taxonomy = $filter['taxonomy'];
				if ( ! in_array( $taxonomy, $used, true ) || ! taxonomy_exists( $taxonomy ) || ! is_taxonomy_viewable( $taxonomy ) ) {
					continue;
				}
				$filters[] = array(
					'key'      => $filter['key'],
					'kind'     => 'taxonomy',
					'target'   => $taxonomy,
					'multiple' => ! empty( $filter['multiple'] ),
					'label'    => $label ?? (string) ( $registry->taxonomies()[ $taxonomy ]['labels']['singular'] ?? $taxonomy ),
					'options'  => self::ordered( $terms[ $taxonomy ] ?? array(), $registry->taxonomies()[ $taxonomy ]['terms'] ?? array() ),
				);
				continue;
			}

			$field = isset( $filter['field'] ) && is_string( $filter['field'] ) ? ( $public[ $filter['field'] ] ?? null ) : null;
			if ( null === $field || null === $registry->meta_key( $type, $field['key'] ) || ! in_array( $field['type'], array( 'boolean', 'select' ), true ) ) {
				continue;
			}
			$options = array();
			foreach ( 'select' === $field['type'] ? ( $field['options'] ?? array() ) : array() as $option ) {
				if ( isset( $option['value'] ) && ( is_string( $option['value'] ) || is_int( $option['value'] ) ) ) {
					$options[] = array(
						'value' => (string) $option['value'],
						'label' => (string) ( $option['label'] ?? $option['value'] ),
					);
				}
			}
			$filters[] = array(
				'key'      => $filter['key'],
				'kind'     => $field['type'],
				'target'   => $field['key'],
				'multiple' => 'select' === $field['type'] && ! empty( $filter['multiple'] ),
				'label'    => $label ?? (string) $field['label'],
				'options'  => $options,
			);
		}

		return array(
			'type'    => $type,
			'plural'  => (string) ( $content['labels']['plural'] ?? $type ),
			'search'  => ! empty( $listing['search'] ),
			'filters' => $filters,
		);
	}

	/**
	 * Terms of several taxonomies in one query, by name, at most MAX_TERMS each.
	 *
	 * @param string[] $taxonomies Taxonomy keys.
	 * @return array<string, WP_Term[]> Terms by taxonomy.
	 */
	private static function terms_by_taxonomy( array $taxonomies ): array {
		if ( array() === $taxonomies ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomies,
				'hide_empty'             => false,
				'number'                 => self::MAX_TERMS * count( $taxonomies ),
				'orderby'                => 'name',
				'update_term_meta_cache' => false,
			)
		);

		$by = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof WP_Term && count( $by[ $term->taxonomy ] ?? array() ) < self::MAX_TERMS ) {
				$by[ $term->taxonomy ][] = $term;
			}
		}
		return $by;
	}

	/**
	 * Terms as options: the order the registry declares, then other terms by name.
	 *
	 * @param WP_Term[]                        $terms    Terms by name.
	 * @param array<int, array<string, mixed>> $declared Terms declared in the registry (`slug`, `name`).
	 * @return list<array{value: string, label: string}>
	 */
	private static function ordered( array $terms, array $declared ): array {
		$order = array_flip( array_values( array_filter( array_column( $declared, 'slug' ), 'is_string' ) ) );
		$list  = array();
		foreach ( $terms as $index => $term ) {
			$list[] = array(
				'value' => $term->slug,
				'label' => $term->name,
				'rank'  => $order[ $term->slug ] ?? count( $order ) + $index,
			);
		}
		usort( $list, static fn( array $a, array $b ): int => $a['rank'] <=> $b['rank'] );

		return array_map(
			static fn( array $option ): array => array(
				'value' => $option['value'],
				'label' => $option['label'],
			),
			$list
		);
	}
}
