<?php
/**
 * Emits core record events for registry content types.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Events;

use Pegwork\Api\Events;
use Pegwork\Api\Registry;
use Pegwork\Content\RecordValues;
use WP_Post;
use WP_REST_Request;

/**
 * Turns post saves into `record.created`, `record.updated`, `record.status_changed` and `record.deleted`.
 *
 * Snapshot and diff: a snapshot (status plus stored values) is taken once per post per logical
 * save and consumed when the save emits, so one save emits one created or updated event, followed
 * by status_changed when the status moved. REST saves snapshot on rest_pre_insert_{type} and emit
 * on rest_after_insert_{type}, after meta and terms are written; PHP saves snapshot on
 * pre_post_update and emit on wp_after_insert_post; the Records API claims its post and emits on
 * the wp_after_insert_post it fires itself. Auto-drafts, revisions, autosaves and other post types
 * emit nothing. A bare update_post_meta() outside a save emits nothing.
 */
final class RecordEvents {

	/**
	 * Status reported as old_status when a record is created.
	 */
	public const NEW_STATUS = 'new';

	/**
	 * The registry whose content types emit events.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Snapshots of saves in progress, by post ID: `status` and `values`.
	 *
	 * @var array<int, array{status: string, values: array<string, mixed>}>
	 */
	private static array $snapshots = array();

	/**
	 * Origins of Records API writes in progress, by post ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $claims = array();

	/**
	 * Posts whose REST save already emitted, so the controller's wp_after_insert_post does not emit again.
	 *
	 * @var array<int, true>
	 */
	private static array $flushed = array();

	/**
	 * Statuses of posts being permanently deleted, by post ID.
	 *
	 * @var array<int, string>
	 */
	private static array $deleting = array();

	/**
	 * Routes of the REST requests being dispatched, innermost last.
	 *
	 * @var string[]
	 */
	private static array $routes = array();

	/**
	 * Origin depths of the record events being dispatched, innermost last.
	 *
	 * @var int[]
	 */
	private static array $depths = array();

	/**
	 * Hooks the save, delete and REST lifecycle. Safe to call on every init.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		self::$registry = $registry;

		foreach ( array_keys( $registry->content_types() ) as $type ) {
			// After RecordValidator (priority 10), so a rejected save takes no snapshot.
			self::hook( 'rest_pre_insert_' . $type, 'rest_pre_insert', 1000, 2 );
			self::hook( 'rest_after_insert_' . $type, 'rest_after_insert', 10, 3 );
		}

		self::hook( 'pre_post_update', 'pre_post_update', 10, 1 );
		self::hook( 'wp_after_insert_post', 'after_insert', 10, 4 );
		self::hook( 'before_delete_post', 'before_delete', 10, 2 );
		self::hook( 'deleted_post', 'deleted', 10, 2 );
		self::hook( 'rest_request_before_callbacks', 'rest_enter', 10, 3 );
		self::hook( 'rest_request_after_callbacks', 'rest_leave', 10, 1 );
	}

	/**
	 * Takes a snapshot before a REST update writes anything. Hooked to rest_pre_insert_{type}.
	 *
	 * @param \stdClass|\WP_Error $prepared Post prepared for the database.
	 * @param WP_REST_Request     $request  Request.
	 * @return \stdClass|\WP_Error Unchanged.
	 */
	public static function rest_pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! $request instanceof WP_REST_Request || str_contains( $request->get_route(), '/autosaves' ) || empty( $prepared->ID ) ) {
			return $prepared;
		}

		$post = get_post( (int) $prepared->ID );
		if ( $post && self::tracked( $post ) ) {
			self::snapshot( $post );
		}

		return $prepared;
	}

	/**
	 * Emits once a REST save has written the post, its meta and its terms. Hooked to rest_after_insert_{type}.
	 *
	 * @param WP_Post         $post     Saved post.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether the request created the post.
	 */
	public static function rest_after_insert( $post, $request, $creating ): void {
		if ( ! $post instanceof WP_Post || ! self::tracked( $post ) ) {
			return;
		}
		if ( $request instanceof WP_REST_Request && str_contains( $request->get_route(), '/autosaves' ) ) {
			return;
		}

		self::flush( $post, (bool) $creating, $post->post_status );
		// Marked after the listeners ran, so PHP saves they make still emit.
		self::$flushed[ $post->ID ] = true;
	}

	/**
	 * Takes a snapshot before a PHP update writes anything. Hooked to pre_post_update.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function pre_post_update( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post && self::tracked( $post ) ) {
			self::snapshot( $post );
		}
	}

	/**
	 * Emits after a PHP save or a Records API write. Hooked to wp_after_insert_post.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Saved post.
	 * @param bool         $update      Whether an existing post was updated.
	 * @param WP_Post|null $post_before Post before the save, null when created.
	 */
	public static function after_insert( $post_id, $post, $update, $post_before ): void {
		$id = (int) $post_id;
		if ( ! $post instanceof WP_Post || ! self::tracked( $post ) ) {
			return;
		}

		if ( ! isset( self::$claims[ $id ] ) && isset( self::$flushed[ $id ] ) ) {
			unset( self::$flushed[ $id ] );
			return;
		}

		self::flush( $post, ! $update, $post_before instanceof WP_Post ? $post_before->post_status : null );
	}

	/**
	 * Remembers the status of a post about to be permanently deleted. Hooked to before_delete_post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function before_delete( $post_id, $post ): void {
		if ( $post instanceof WP_Post && self::tracked( $post ) && 'auto-draft' !== $post->post_status ) {
			self::$deleting[ (int) $post_id ] = $post->post_status;
		}
	}

	/**
	 * Emits record.deleted once a post is gone. Hooked to deleted_post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Deleted post.
	 */
	public static function deleted( $post_id, $post ): void {
		$id = (int) $post_id;
		if ( ! isset( self::$deleting[ $id ] ) || ! $post instanceof WP_Post ) {
			return;
		}
		$status = self::$deleting[ $id ];
		unset( self::$deleting[ $id ], self::$snapshots[ $id ], self::$flushed[ $id ] );

		self::dispatch(
			'record.deleted',
			array(
				'id'      => $id,
				'type'    => $post->post_type,
				'status'  => $status,
				'user_id' => get_current_user_id(),
				'origin'  => self::origin( $id ),
			)
		);
	}

	/**
	 * Notes the route of a REST request being dispatched. Hooked to rest_request_before_callbacks.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed Unchanged.
	 */
	public static function rest_enter( $response, $handler, $request ) {
		self::$routes[] = $request instanceof WP_REST_Request ? $request->get_route() : '';
		return $response;
	}

	/**
	 * Drops the route of a REST request that finished. Hooked to rest_request_after_callbacks.
	 *
	 * @param mixed $response Response.
	 * @return mixed Unchanged.
	 */
	public static function rest_leave( $response ) {
		array_pop( self::$routes );
		return $response;
	}

	/**
	 * Claims a post for a Records API write: snapshots it (setting aside any outer save's snapshot)
	 * and records the write's origin. Pair with release().
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $origin Caller origin (`automation`, `run_id`, `depth`).
	 * @param bool                 $exists Whether the post already exists (update) rather than being created.
	 * @return array{status: string, values: array<string, mixed>}|null The outer snapshot set aside, if any.
	 */
	public static function claim( int $id, array $origin, bool $exists ): ?array {
		$outer = self::$snapshots[ $id ] ?? null;
		unset( self::$snapshots[ $id ] );

		$post = $exists ? get_post( $id ) : null;
		if ( $post ) {
			self::snapshot( $post );
		}
		self::$claims[ $id ] = $origin;

		return $outer;
	}

	/**
	 * Ends a Records API write, restoring the outer snapshot claim() set aside.
	 *
	 * @param int                                                      $id    Post ID.
	 * @param array{status: string, values: array<string, mixed>}|null $outer Return value of claim().
	 */
	public static function release( int $id, ?array $outer ): void {
		unset( self::$claims[ $id ], self::$snapshots[ $id ] );
		if ( null !== $outer ) {
			self::$snapshots[ $id ] = $outer;
		}
	}

	/**
	 * Whether a post belongs to a registry content type and is not a revision or an autosave.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function tracked( WP_Post $post ): bool {
		if ( null === self::$registry || null === self::$registry->content_type( $post->post_type ) ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		foreach ( self::$routes as $route ) {
			if ( str_contains( $route, '/autosaves' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Takes a post's snapshot unless this logical save already has one.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function snapshot( WP_Post $post ): void {
		if ( isset( self::$snapshots[ $post->ID ] ) || null === self::$registry ) {
			return;
		}
		self::$snapshots[ $post->ID ] = array(
			'status' => $post->post_status,
			'values' => RecordValues::read( self::$registry, $post ),
		);
	}

	/**
	 * Consumes a save's snapshot and emits its events.
	 *
	 * @param WP_Post     $post        Saved post.
	 * @param bool        $created     Whether the save inserted the post.
	 * @param string|null $status_before Status before the save when no snapshot was taken (for example wp_publish_post()).
	 */
	private static function flush( WP_Post $post, bool $created, ?string $status_before ): void {
		$id       = $post->ID;
		$snapshot = self::$snapshots[ $id ] ?? null;
		unset( self::$snapshots[ $id ] );

		$origin = self::origin( $id );
		$status = $post->post_status;
		if ( 'auto-draft' === $status ) {
			return;
		}

		$created = $created || 'auto-draft' === ( $snapshot['status'] ?? $status_before );
		$base    = array(
			'id'   => $id,
			'type' => $post->post_type,
		);
		$tail    = array(
			'user_id' => get_current_user_id(),
			'origin'  => $origin,
		);

		if ( $created ) {
			self::dispatch( 'record.created', $base + array( 'status' => $status ) + $tail );
			self::dispatch( 'record.status_changed', $base + self::transition( self::NEW_STATUS, $status ) + $tail );
			return;
		}

		$current = RecordValues::read( self::$registry, $post );
		$before  = $snapshot ?? array(
			'status' => $status_before ?? $status,
			'values' => $current,
		);
		$changed = array();
		$old     = array();
		foreach ( $current as $key => $value ) {
			$previous = $before['values'][ $key ] ?? null;
			if ( $previous !== $value ) {
				$changed[]   = $key;
				$old[ $key ] = $previous;
			}
		}
		if ( $before['status'] !== $status ) {
			$changed[]     = 'status';
			$old['status'] = $before['status'];
		}
		if ( array() === $changed ) {
			return;
		}

		self::dispatch(
			'record.updated',
			$base + array(
				'status'  => $status,
				'changed' => $changed,
				'old'     => $old,
			) + $tail
		);
		if ( $before['status'] !== $status ) {
			self::dispatch( 'record.status_changed', $base + self::transition( $before['status'], $status ) + $tail );
		}
	}

	/**
	 * Status transition payload fields.
	 *
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return array{old_status: string, new_status: string}
	 */
	private static function transition( string $old_status, string $new_status ): array {
		return array(
			'old_status' => $old_status,
			'new_status' => $new_status,
		);
	}

	/**
	 * Origin of a save, consuming a Records API claim. Depth is the depth of the record event being
	 * dispatched plus 1 (0 outside any listener); a claim's own depth wins when it is higher.
	 *
	 * @param int $id Post ID.
	 * @return array<string, mixed> `source` (rest, php or records_api), optional `automation` and `run_id`, `depth`.
	 */
	private static function origin( int $id ): array {
		$depth = array() === self::$depths ? 0 : (int) end( self::$depths ) + 1;

		if ( ! isset( self::$claims[ $id ] ) ) {
			return array(
				'source' => array() === self::$routes ? 'php' : 'rest',
				'depth'  => $depth,
			);
		}

		$claim = self::$claims[ $id ];
		unset( self::$claims[ $id ] );
		$origin = array( 'source' => 'records_api' );
		foreach ( array( 'automation', 'run_id' ) as $key ) {
			if ( isset( $claim[ $key ] ) && is_scalar( $claim[ $key ] ) ) {
				$origin[ $key ] = $claim[ $key ];
			}
		}
		$origin['depth'] = max( $depth, (int) ( $claim['depth'] ?? 0 ) );

		return $origin;
	}

	/**
	 * Emits a record event, tracking its depth for writes its listeners make.
	 *
	 * @param string               $name    Event name.
	 * @param array<string, mixed> $payload Payload with `origin.depth`.
	 */
	private static function dispatch( string $name, array $payload ): void {
		self::$depths[] = (int) $payload['origin']['depth'];
		try {
			Events::emit( $name, $payload );
		} finally {
			array_pop( self::$depths );
		}
	}

	/**
	 * Adds a hook once (the test suite restores hooks between tests, so guard with has_filter).
	 *
	 * @param string $hook     Hook name.
	 * @param string $method   Method of this class.
	 * @param int    $priority Priority.
	 * @param int    $args     Accepted arguments.
	 */
	private static function hook( string $hook, string $method, int $priority, int $args ): void {
		$callback = array( self::class, $method );
		if ( false === has_filter( $hook, $callback ) ) {
			add_filter( $hook, $callback, $priority, $args );
		}
	}
}
