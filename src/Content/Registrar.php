<?php
/**
 * Registers the effective registry's content with WordPress.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;

/**
 * Registers post types, taxonomies, meta and record validation on every request; flushes rewrites and seeds terms
 * only when the registry hash changes.
 */
final class Registrar {

	/**
	 * Option holding the hash of the registry last synced.
	 */
	public const HASH_OPTION = 'pegwork_registry_hash';

	/**
	 * Registry waiting for a deferred sync, null when none is pending.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $pending = null;

	/**
	 * Registers content and schedules a sync when the registry changed since the last one.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		PostTypes::register( $registry );
		Taxonomies::register( $registry );
		Meta::register( $registry );
		Relationships::register( $registry );
		RecordValidator::register( $registry );

		if ( get_option( self::HASH_OPTION ) === self::hash( $registry ) ) {
			return;
		}

		// Flush after every plugin has added its rewrite rules on init.
		if ( did_action( 'wp_loaded' ) ) {
			self::sync( $registry );
			return;
		}

		if ( null === self::$pending ) {
			add_action( 'wp_loaded', array( self::class, 'run_pending' ) );
		}
		self::$pending = $registry;
	}

	/**
	 * Runs the sync scheduled by register(). Hooked to wp_loaded.
	 */
	public static function run_pending(): void {
		if ( null !== self::$pending ) {
			self::sync( self::$pending );
			self::$pending = null;
		}
	}

	/**
	 * Seeds missing terms, flushes rewrite rules and stores the registry hash.
	 *
	 * @param Registry $registry Effective registry.
	 * @return int Number of terms created.
	 */
	public static function sync( Registry $registry ): int {
		$created = Taxonomies::seed( $registry );
		flush_rewrite_rules( false );
		update_option( self::HASH_OPTION, self::hash( $registry ), true );

		return $created;
	}

	/**
	 * Hash of the effective registry. Canonical form makes it stable across requests.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function hash( Registry $registry ): string {
		return md5(
			(string) wp_json_encode(
				array(
					$registry->content_types(),
					$registry->taxonomies(),
					$registry->relationships(),
				)
			)
		);
	}
}
