<?php
/**
 * Schema importer: upserts a package into the `pegwork_schema` option.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use Pegwork\Api\Registry;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;

/**
 * Imports a package directory (usually an export) into the DB source.
 *
 * The package passes the same validator as a file package. Entries are upserted by kind and key in canonical
 * form, so importing the same package twice changes nothing. With prune, DB entries the package does not
 * define are removed; file packages are never touched. Terms, posts and meta are never deleted.
 */
final class Importer {

	/**
	 * Option property holding the normalized manifest of the last imported package; export reuses it.
	 */
	public const MANIFEST = 'manifest';

	/**
	 * Plans an import without touching WordPress state.
	 *
	 * @param string                              $dir   Package directory.
	 * @param array<string, mixed>                $db    Current value of the `pegwork_schema` option.
	 * @param array<string, array<string, mixed>> $known Keys defined outside the DB source: [kind][key] => anything.
	 *                                                   References may resolve to these, to the package or to kept DB entries.
	 * @param bool                                $prune Whether to remove DB entries the package does not define.
	 */
	public static function plan( string $dir, array $db, array $known = array(), bool $prune = false ): ImportPlan {
		$package = Loader::read_package( $dir );
		if ( $package['errors'] ) {
			return new ImportPlan( $package['errors'] );
		}

		$incoming = array_fill_keys( array_keys( Loader::SECTIONS ), array() );
		foreach ( $package['objects'] as $object ) {
			$incoming[ $object['kind'] ][ $object['key'] ] = Normalizer::normalize( $object['kind'], $object['data'] );
			$known[ $object['kind'] ][ $object['key'] ]    = true;
		}

		if ( ! $prune ) {
			foreach ( Loader::SECTIONS as $kind => $section ) {
				foreach ( self::entries( $db, $section ) as $entry ) {
					if ( array() === Validator::validate( $kind, $entry, Loader::DB_SOURCE ) ) {
						$known[ $kind ][ $entry['key'] ] = true;
					}
				}
			}
		}

		$errors = array();
		foreach ( $package['objects'] as $object ) {
			array_push( $errors, ...Validator::references( $object['kind'], $object['data'], $object['file'], $known, $object['pointer'] ) );
		}
		if ( $errors ) {
			return new ImportPlan( $errors );
		}

		$changes  = array();
		$manifest = Normalizer::normalize( 'manifest', $package['manifest_data'] );
		$stored   = $db[ self::MANIFEST ] ?? null;
		if ( $stored !== $manifest ) {
			$changes[] = array(
				'action' => null === $stored ? 'add' : 'update',
				'kind'   => 'manifest',
				'key'    => $manifest['id'],
			);
		}

		$option = array();
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$option[ $section ] = self::merge( $kind, self::entries( $db, $section ), $incoming[ $kind ], $prune, $changes );
		}
		$option[ self::MANIFEST ] = $manifest;

		return new ImportPlan( array(), $changes, $option );
	}

	/**
	 * Imports a package into this site: writes the option, re-runs registration and creates missing seed terms.
	 *
	 * Registration changes take effect at once in this request and on every later init (the registry hash
	 * changes, so rewrite rules are flushed). With dry run nothing is written and the plan lists the seed
	 * terms that would be created.
	 *
	 * @param string $dir     Package directory.
	 * @param bool   $prune   Whether to remove DB entries the package does not define.
	 * @param bool   $dry_run Whether to only report what would change.
	 */
	public static function import( string $dir, bool $prune = false, bool $dry_run = false ): ImportPlan {
		$known = array();
		foreach ( Plugin::result()->sources as $kind => $keys ) {
			foreach ( $keys as $key => $from ) {
				if ( Loader::DB_SOURCE !== $from ) {
					$known[ $kind ][ $key ] = true;
				}
			}
		}

		$db   = get_option( Loader::DB_OPTION, array() );
		$plan = self::plan( $dir, is_array( $db ) ? $db : array(), $known, $prune );
		if ( ! $plan->is_valid() ) {
			return $plan;
		}

		$next    = Loader::load( Plugin::packages(), $plan->option );
		$missing = self::missing_terms( $next->registry );
		if ( $dry_run ) {
			return $plan->with( $missing, $next->shadowed );
		}

		if ( $plan->changes ) {
			update_option( Loader::DB_OPTION, $plan->option, true );
		}
		Plugin::init();
		Taxonomies::seed( Plugin::registry() );

		$left    = self::missing_terms( Plugin::registry() );
		$created = array_values( array_filter( $missing, fn( array $term ) => ! in_array( $term, $left, true ) ) );

		return $plan->with( $created, Plugin::result()->shadowed );
	}

	/**
	 * Seed terms of the registry's taxonomies that do not exist yet, matched by slug.
	 *
	 * Works for taxonomies that are not registered in this request, so a dry run can report them.
	 *
	 * @param Registry      $registry Effective registry.
	 * @param callable|null $exists   Term check `( string $taxonomy, string $slug ): bool`; defaults to a DB lookup.
	 * @return array<int, array<string, string>> Each has taxonomy and slug, in registry order.
	 */
	public static function missing_terms( Registry $registry, ?callable $exists = null ): array {
		$exists ??= fn( string $taxonomy, string $slug ): bool => self::term_exists( $taxonomy, $slug );
		$missing = array();

		foreach ( $registry->taxonomies() as $key => $taxonomy ) {
			foreach ( $taxonomy['terms'] as $term ) {
				if ( ! $exists( $key, $term['slug'] ) ) {
					$missing[] = array(
						'taxonomy' => $key,
						'slug'     => $term['slug'],
					);
				}
			}
		}

		return $missing;
	}

	/**
	 * Upserts one kind's incoming objects into its DB entries, recording each change.
	 *
	 * Entries keep their order; replaced entries stay in place and new ones are appended in package order.
	 * Later DB entries that repeat an imported key are dropped, since the loader would reject them.
	 *
	 * @param string                              $kind     Object kind.
	 * @param array<int, mixed>                   $entries  Current DB entries.
	 * @param array<string, array<string, mixed>> $incoming Canonical objects from the package, by key.
	 * @param bool                                $prune    Whether to remove entries the package does not define.
	 * @param array<int, array<string, string>>   $changes  Changes, appended to.
	 * @return array<int, mixed> New DB entries.
	 */
	private static function merge( string $kind, array $entries, array $incoming, bool $prune, array &$changes ): array {
		$next     = array();
		$replaced = array();

		foreach ( $entries as $index => $entry ) {
			$key = is_array( $entry ) && isset( $entry['key'] ) && is_string( $entry['key'] ) ? $entry['key'] : null;

			if ( null !== $key && isset( $incoming[ $key ] ) && ! isset( $replaced[ $key ] ) ) {
				if ( $entry !== $incoming[ $key ] ) {
					$changes[] = array(
						'action' => 'update',
						'kind'   => $kind,
						'key'    => $key,
					);
				}
				$next[]           = $incoming[ $key ];
				$replaced[ $key ] = true;
				continue;
			}

			if ( $prune || ( null !== $key && isset( $incoming[ $key ] ) ) ) {
				$changes[] = array(
					'action' => 'remove',
					'kind'   => $kind,
					'key'    => $key ?? '#' . $index,
				);
				continue;
			}

			$next[] = $entry;
		}

		foreach ( $incoming as $key => $object ) {
			if ( ! isset( $replaced[ $key ] ) ) {
				$changes[] = array(
					'action' => 'add',
					'kind'   => $kind,
					'key'    => $key,
				);
				$next[]    = $object;
			}
		}

		return $next;
	}

	/**
	 * One section of the option as a list, or an empty list when it is missing or malformed.
	 *
	 * @param array<string, mixed> $db      Option value.
	 * @param string               $section Section name.
	 * @return array<int, mixed>
	 */
	private static function entries( array $db, string $section ): array {
		$entries = $db[ $section ] ?? array();

		return is_array( $entries ) && array_is_list( $entries ) ? $entries : array();
	}

	/**
	 * Whether a term with this slug exists in this taxonomy, registered or not.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 */
	private static function term_exists( string $taxonomy, string $slug ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- term_exists() and get_term_by() need a registered taxonomy; a dry run checks before registration.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s AND t.slug = %s LIMIT 1", $taxonomy, $slug ) );

		return null !== $found;
	}
}
