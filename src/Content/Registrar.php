<?php
/**
 * Registers the effective registry's content with WordPress.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use Pegwork\Events\RecordEvents;
use Pegwork\Options\BindingSource;
use Pegwork\Options\Pages;

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
	 * Transient set for an hour after heal_rewrites() flushes, so missing rules never cause a flush on every request.
	 */
	public const HEAL_TRANSIENT = 'pegwork_rewrite_healed';

	/**
	 * Registry waiting for a deferred sync, null when none is pending.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $pending = null;

	/**
	 * Registry registered last, null before the first register().
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registered = null;

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
		RecordEvents::register( $registry );
		Pages::register( $registry );
		BindingSource::register( $registry );

		self::$registered = $registry;
		if ( ! has_action( 'wp_loaded', array( self::class, 'heal_rewrites' ) ) ) {
			add_action( 'wp_loaded', array( self::class, 'heal_rewrites' ), 20 );
		}

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
	 * Flushes rewrite rules once when the saved rules lack the rules of a publicly queryable Pegwork post type or
	 * taxonomy, as after a flush in a request where Pegwork's content was not registered (plugin deactivated, a
	 * schema error, another plugin flushing early). Pretty permalinks only. After a flush it waits an hour
	 * (HEAL_TRANSIENT) before flushing again, so rules that another plugin keeps removing cost one flush per
	 * hour, not one per request. Hooked to wp_loaded, after run_pending().
	 */
	public static function heal_rewrites(): void {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof \WP_Rewrite || ! $wp_rewrite->using_permalinks() ) {
			return;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return; // WordPress rebuilds empty rules itself on the next request it parses.
		}

		$queries = implode( "\n", array_filter( $rules, 'is_string' ) );
		$missing = false;
		foreach ( self::query_vars() as $var ) {
			if ( ! str_contains( $queries, '?' . $var . '=' ) && ! str_contains( $queries, '&' . $var . '=' ) ) {
				$missing = true;
				break;
			}
		}
		if ( ! $missing || false !== get_transient( self::HEAL_TRANSIENT ) ) {
			return;
		}

		set_transient( self::HEAL_TRANSIENT, 1, HOUR_IN_SECONDS );
		flush_rewrite_rules( false );
	}

	/**
	 * Query vars of the registered, publicly queryable Pegwork post types and taxonomies that have rewrite rules.
	 *
	 * @return string[]
	 */
	private static function query_vars(): array {
		$registry = self::$registered ?? new Registry();
		$objects  = array();
		foreach ( array_keys( $registry->content_types() ) as $key ) {
			$objects[] = get_post_type_object( $key );
		}
		foreach ( array_keys( $registry->taxonomies() ) as $key ) {
			$objects[] = get_taxonomy( $key );
		}

		$vars = array();
		foreach ( $objects as $object ) {
			if ( is_object( $object ) && ! empty( $object->publicly_queryable ) && false !== $object->rewrite && is_string( $object->query_var ) && '' !== $object->query_var ) {
				$vars[] = $object->query_var;
			}
		}

		return $vars;
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
