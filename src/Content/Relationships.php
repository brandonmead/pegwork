<?php
/**
 * Registers relationships as post meta and answers related-record queries.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use WP_Post;
use WP_Query;
use WP_REST_Request;

/**
 * Meta storage for relationships: one `_rel_{key}` row per linked ID on the "from" record.
 *
 * Registers the meta (REST-visible, integer IDs, edit_post auth), the `rel_{key}` collection
 * param on the "from" REST collection, and cleanup when a "to" record is deleted. Removing a
 * relationship from the registry never deletes its meta.
 */
final class Relationships {

	/**
	 * Meta key prefix; the meta key is the prefix plus the relationship key.
	 */
	public const META_PREFIX = '_rel_';

	/**
	 * REST collection param prefix; the param is the prefix plus the relationship key.
	 */
	public const PARAM_PREFIX = 'rel_';

	/**
	 * The registry the hooks read.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Meta keys registered by the last call, by post type, so removed relationships are unregistered.
	 *
	 * @var array<string, string[]>
	 */
	private static array $registered = array();

	/**
	 * Registers meta, REST hooks and delete cleanup for every relationship.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		self::$registry = $registry;

		$keys = array();
		foreach ( $registry->relationships() as $key => $relationship ) {
			$keys[ $relationship['from'] ][] = self::meta_key( $key );
		}

		foreach ( self::$registered as $type => $meta_keys ) {
			foreach ( array_diff( $meta_keys, $keys[ $type ] ?? array() ) as $gone ) {
				unregister_post_meta( $type, $gone );
			}
		}
		self::$registered = array();

		foreach ( $keys as $type => $meta_keys ) {
			foreach ( $meta_keys as $meta_key ) {
				if ( register_post_meta( $type, $meta_key, self::args() ) ) {
					self::$registered[ $type ][] = $meta_key;
				}
			}
			if ( ! has_filter( 'rest_' . $type . '_collection_params', array( self::class, 'collection_params' ) ) ) {
				add_filter( 'rest_' . $type . '_collection_params', array( self::class, 'collection_params' ), 10, 2 );
				add_filter( 'rest_' . $type . '_query', array( self::class, 'rest_query' ), 10, 2 );
			}
		}

		if ( ! has_action( 'deleted_post', array( self::class, 'deleted_post' ) ) ) {
			add_action( 'deleted_post', array( self::class, 'deleted_post' ), 10, 2 );
		}
	}

	/**
	 * Meta key of a relationship.
	 *
	 * @param string $key Relationship key.
	 */
	public static function meta_key( string $key ): string {
		return self::META_PREFIX . $key;
	}

	/**
	 * Picker heading of a relationship: its `label`, else the "to" type's plural label.
	 *
	 * @param Registry             $registry     Effective registry.
	 * @param array<string, mixed> $relationship Normalized relationship.
	 */
	public static function label( Registry $registry, array $relationship ): string {
		return $relationship['label'] ?? ( $registry->content_type( $relationship['to'] )['labels']['plural'] ?? $relationship['to'] );
	}

	/**
	 * Builds register_post_meta() arguments: one integer row per linked ID.
	 *
	 * @return array<string, mixed>
	 */
	private static function args(): array {
		return array(
			'type'              => 'integer',
			'single'            => false,
			'sanitize_callback' => 'absint',
			'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);
	}

	/**
	 * Adds a `rel_{key}` integer param per relationship to the "from" type's REST collection.
	 * Hooked to rest_{post_type}_collection_params.
	 *
	 * @param array<string, mixed> $params    Collection params.
	 * @param \WP_Post_Type        $post_type Post type of the collection.
	 * @return array<string, mixed>
	 */
	public static function collection_params( $params, $post_type ) {
		if ( ! is_array( $params ) || ! $post_type instanceof \WP_Post_Type || null === self::$registry ) {
			return $params;
		}

		foreach ( self::from( self::$registry, $post_type->name ) as $key => $relationship ) {
			$to = self::$registry->content_type( $relationship['to'] );

			$params[ self::PARAM_PREFIX . $key ] = array(
				/* translators: %s: singular label of the linked content type. */
				'description' => sprintf( __( 'Limit results to records linked to this %s ID.', 'pegwork' ), $to['labels']['singular'] ?? $relationship['to'] ),
				'type'        => 'integer',
				'minimum'     => 1,
			);
		}

		return $params;
	}

	/**
	 * Applies `rel_{key}` params to a REST collection query. Hooked to rest_{post_type}_query.
	 *
	 * @param array<string, mixed> $args    WP_Query args.
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>
	 */
	public static function rest_query( $args, $request ) {
		if ( ! is_array( $args ) || ! $request instanceof WP_REST_Request || null === self::$registry ) {
			return $args;
		}

		$type = is_string( $args['post_type'] ?? null ) ? $args['post_type'] : '';
		foreach ( array_keys( self::from( self::$registry, $type ) ) as $key ) {
			$id = $request->get_param( self::PARAM_PREFIX . $key );
			if ( null === $id ) {
				continue;
			}
			$args['meta_query']   = is_array( $args['meta_query'] ?? null ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Relationship lookup; unindexed by design (docs/relationships.md).
			$args['meta_query'][] = self::link_clause( $key, (int) $id );
		}

		return $args;
	}

	/**
	 * Removes a deleted "to" record from every relationship that links to it. Hooked to deleted_post.
	 *
	 * @param int          $post_id Deleted post ID.
	 * @param WP_Post|null $post    Deleted post.
	 */
	public static function deleted_post( $post_id, $post = null ): void {
		if ( null === self::$registry || ! $post instanceof WP_Post ) {
			return;
		}

		foreach ( self::$registry->relationships() as $key => $relationship ) {
			if ( $relationship['to'] === $post->post_type ) {
				delete_metadata( 'post', 0, self::meta_key( $key ), (int) $post_id, true );
			}
		}
	}

	/**
	 * IDs at the other end of a relationship. Direction follows the post's type. All statuses.
	 *
	 * @param Registry    $registry Effective registry.
	 * @param int|WP_Post $post     Post or post ID.
	 * @param string      $key      Relationship key.
	 * @return int[] Sorted IDs; empty for an unknown key, a missing post or a post of neither type.
	 */
	public static function related_ids( Registry $registry, int|WP_Post $post, string $key ): array {
		$post         = get_post( $post );
		$relationship = $registry->relationships()[ $key ] ?? null;
		if ( ! $post instanceof WP_Post || null === $relationship ) {
			return array();
		}

		if ( $relationship['from'] === $post->post_type ) {
			return self::stored_ids( $post->ID, $key );
		}

		if ( $relationship['to'] === $post->post_type ) {
			return self::linking_ids( $relationship, $key, $post->ID );
		}

		return array();
	}

	/**
	 * WP_Query args for the records at the other end of a relationship. Callers add status, order and paging.
	 *
	 * @param Registry    $registry Effective registry.
	 * @param int|WP_Post $post     Post or post ID.
	 * @param string      $key      Relationship key.
	 * @return array<string, mixed> Args that match nothing for an unknown key, a missing post or a post of neither type.
	 */
	public static function query_args( Registry $registry, int|WP_Post $post, string $key ): array {
		$post         = get_post( $post );
		$relationship = $registry->relationships()[ $key ] ?? null;
		$none         = array( 'post__in' => array( 0 ) );
		if ( ! $post instanceof WP_Post || null === $relationship ) {
			return $none;
		}

		if ( $relationship['to'] === $post->post_type ) {
			return array(
				'post_type'  => $relationship['from'],
				'meta_query' => array( self::link_clause( $key, $post->ID ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Relationship lookup; unindexed by design (docs/relationships.md).
			);
		}

		if ( $relationship['from'] === $post->post_type ) {
			$ids = self::stored_ids( $post->ID, $key );

			return array(
				'post_type' => $relationship['to'],
				'post__in'  => $ids ? $ids : array( 0 ),
			);
		}

		return $none;
	}

	/**
	 * Relationships whose "from" end is the given type.
	 *
	 * @param Registry $registry Effective registry.
	 * @param string   $type     Content type key.
	 * @return array<string, array<string, mixed>> Relationships by key.
	 */
	public static function from( Registry $registry, string $type ): array {
		return array_filter( $registry->relationships(), fn( array $relationship ) => $relationship['from'] === $type );
	}

	/**
	 * Linked IDs stored on a "from" record.
	 *
	 * @param int    $post_id "From" post ID.
	 * @param string $key     Relationship key.
	 * @return int[] Sorted, unique, positive IDs.
	 */
	public static function stored_ids( int $post_id, string $key ): array {
		return self::ids( get_post_meta( $post_id, self::meta_key( $key ), false ) );
	}

	/**
	 * "From" records that link to a "to" record, in any status.
	 *
	 * @param array<string, mixed> $relationship Normalized relationship.
	 * @param string               $key          Relationship key.
	 * @param int                  $to_id        "To" post ID.
	 * @param int                  $exclude      "From" post ID to leave out, 0 for none.
	 * @param int                  $limit        Maximum IDs, -1 for all.
	 * @return int[] Sorted IDs.
	 */
	public static function linking_ids( array $relationship, string $key, int $to_id, int $exclude = 0, int $limit = -1 ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $relationship['from'],
				'post_status'            => array_keys( get_post_stati() ),
				'meta_query'             => array( self::link_clause( $key, $to_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Relationship lookup; unindexed by design (docs/relationships.md).
				'post__not_in'           => $exclude > 0 ? array( $exclude ) : array(),
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Normalizes raw values to sorted, unique, positive integer IDs.
	 *
	 * @param mixed $values Raw value or list of values.
	 * @return int[]
	 */
	public static function ids( mixed $values ): array {
		$ids = array_filter( array_map( 'absint', is_array( $values ) ? $values : array( $values ) ) );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * The meta_query clause matching "from" records linked to one ID.
	 *
	 * @param string $key Relationship key.
	 * @param int    $id  "To" post ID.
	 * @return array<string, mixed>
	 */
	private static function link_clause( string $key, int $id ): array {
		return array(
			'key'   => self::meta_key( $key ),
			'value' => $id,
			'type'  => 'NUMERIC',
		);
	}
}
