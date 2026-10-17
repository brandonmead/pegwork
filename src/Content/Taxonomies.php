<?php
/**
 * Registers taxonomies and seeds their terms.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;

/**
 * Maps each normalized taxonomy to register_taxonomy() and creates missing seed terms.
 */
final class Taxonomies {

	/**
	 * Taxonomies registered by the last call, so ones that leave the registry are unregistered.
	 *
	 * @var string[]
	 */
	private static array $registered = array();

	/**
	 * Taxonomies with locked terms.
	 *
	 * @var string[]
	 */
	private static array $locked = array();

	/**
	 * Whether seed() is inserting terms, which bypasses the lock.
	 *
	 * @var bool
	 */
	private static bool $seeding = false;

	/**
	 * Registers every taxonomy in the registry, attached to the content types that list it.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		$taxonomies = $registry->taxonomies();

		foreach ( array_diff( self::$registered, array_keys( $taxonomies ) ) as $gone ) {
			unregister_taxonomy( $gone );
		}
		self::$registered = array();
		self::$locked     = array_keys( array_filter( $taxonomies, fn( array $taxonomy ) => $taxonomy['locked_terms'] ) );

		if ( ! has_filter( 'pre_insert_term', array( self::class, 'guard_locked' ) ) ) {
			add_filter( 'pre_insert_term', array( self::class, 'guard_locked' ), 10, 2 );
		}

		$object_types = array();
		foreach ( $registry->content_types() as $type_key => $type ) {
			foreach ( $type['taxonomies'] as $usage ) {
				$object_types[ $usage['key'] ][] = $type_key;
			}
		}

		foreach ( $taxonomies as $key => $taxonomy ) {
			$result = register_taxonomy( $key, $object_types[ $key ] ?? array(), self::args( $taxonomy ) );
			if ( ! is_wp_error( $result ) ) {
				self::$registered[] = $key;
			}
		}
	}

	/**
	 * Builds register_taxonomy() arguments from a normalized taxonomy.
	 *
	 * @param array<string, mixed> $taxonomy Normalized taxonomy.
	 * @return array<string, mixed>
	 */
	public static function args( array $taxonomy ): array {
		$args = array(
			'labels'            => self::labels( $taxonomy['labels'] ),
			'hierarchical'      => $taxonomy['hierarchical'],
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => $taxonomy['wp']['rewrite_slug'] ?? $taxonomy['key'] ),
		);

		if ( $taxonomy['locked_terms'] ) {
			$args['capabilities'] = array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			);
		}

		return $args;
	}

	/**
	 * Blocks new terms in locked taxonomies unless the user has manage_options.
	 *
	 * Needed because core lets anyone who can assign terms create them in a non-hierarchical
	 * taxonomy (REST terms endpoint, tag box). Seeding and WP-CLI are allowed. Hooked to pre_insert_term.
	 *
	 * @param string|\WP_Error $term     Term name, or an error from an earlier filter.
	 * @param string           $taxonomy Taxonomy key.
	 * @return string|\WP_Error
	 */
	public static function guard_locked( $term, $taxonomy ) {
		if ( is_wp_error( $term ) || self::$seeding || ! in_array( $taxonomy, self::$locked, true ) ) {
			return $term;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' ) ) {
			return $term;
		}

		return new \WP_Error(
			'pegwork_locked_terms',
			__( 'Terms in this taxonomy are managed by site administrators.', 'pegwork' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Creates seed terms that do not exist yet, matched by slug. Never renames or deletes.
	 *
	 * @param Registry $registry Effective registry.
	 * @return int Number of terms created.
	 */
	public static function seed( Registry $registry ): int {
		$created       = 0;
		self::$seeding = true;
		try {
			foreach ( $registry->taxonomies() as $key => $taxonomy ) {
				if ( ! taxonomy_exists( $key ) ) {
					continue;
				}
				foreach ( $taxonomy['terms'] as $term ) {
					if ( get_term_by( 'slug', $term['slug'], $key ) ) {
						continue;
					}
					$result = wp_insert_term( $term['name'], $key, array( 'slug' => $term['slug'] ) );
					if ( ! is_wp_error( $result ) ) {
						++$created;
					}
				}
			}
		} finally {
			self::$seeding = false;
		}

		return $created;
	}

	/**
	 * Builds taxonomy labels from the singular and plural names.
	 *
	 * @param array{singular: string, plural: string} $labels Registry labels.
	 * @return array<string, string>
	 */
	private static function labels( array $labels ): array {
		$one  = $labels['singular'];
		$many = $labels['plural'];

		return array(
			'name'          => $many,
			'singular_name' => $one,
			'menu_name'     => $many,
			/* translators: %s: plural taxonomy name. */
			'all_items'     => sprintf( __( 'All %s', 'pegwork' ), $many ),
			/* translators: %s: singular taxonomy name. */
			'edit_item'     => sprintf( __( 'Edit %s', 'pegwork' ), $one ),
			/* translators: %s: singular taxonomy name. */
			'view_item'     => sprintf( __( 'View %s', 'pegwork' ), $one ),
			/* translators: %s: singular taxonomy name. */
			'update_item'   => sprintf( __( 'Update %s', 'pegwork' ), $one ),
			/* translators: %s: singular taxonomy name. */
			'add_new_item'  => sprintf( __( 'Add New %s', 'pegwork' ), $one ),
			/* translators: %s: singular taxonomy name. */
			'new_item_name' => sprintf( __( 'New %s Name', 'pegwork' ), $one ),
			/* translators: %s: plural taxonomy name. */
			'search_items'  => sprintf( __( 'Search %s', 'pegwork' ), $many ),
			/* translators: %s: plural taxonomy name. */
			'not_found'     => sprintf( __( 'No %s found.', 'pegwork' ), $many ),
		);
	}
}
