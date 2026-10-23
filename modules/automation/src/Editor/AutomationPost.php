<?php
/**
 * The `pegwork_automation` post type: the builder's editing surface.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Editor;

use Pegwork\Api;
use PegworkAutomation\Admin\Screen;
use WP_Block_Editor_Context;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * A `pegwork_automation` post is never a source: the registry is. Saving in the block editor compiles
 * the post (title, step, decision and branch blocks, settings meta) into a definition and calls
 * pegwork_save_automation() (`rest_pre_insert_pegwork_automation`); a definition the registry refuses
 * refuses the save, with messages by JSON pointer. Trashing calls pegwork_delete_automation(); runs
 * stay. Opening a post regenerates it when the registry definition changed (import, file edits).
 * File-defined automations open read-only.
 *
 * Every capability maps to `pegwork_manage_automations`, which maps to `manage_options` with no filter
 * to lower it: an automation can email any address, write any record and call webhooks. Status is
 * always `private`, so core REST never serves an automation to visitors. A site automation's first
 * save stores `enabled: false` unless the settings say otherwise.
 */
final class AutomationPost {

	/**
	 * Post type key.
	 */
	public const TYPE = 'pegwork_automation';

	/**
	 * Primitive capability every post type capability maps to.
	 */
	public const CAP = 'pegwork_manage_automations';

	/**
	 * Meta: the settings object as JSON (REST, edited by the settings panel).
	 */
	public const META_SETTINGS = '_pegwork_automation_settings';

	/**
	 * Meta: the registry key this post edits; set on the first successful save, then locked.
	 */
	public const META_KEY = '_pegwork_automation_key';

	/**
	 * Meta: Builder::hash() of the registry automation the content was last saved or generated from.
	 */
	public const META_HASH = '_pegwork_automation_hash';

	/**
	 * Statuses an automation post may hold besides `private`.
	 */
	private const KEEP_STATUS = array( 'auto-draft', 'trash', 'inherit' );

	/**
	 * Keys saved during the current request, by post ID (0 for a REST create), for rest_after_insert.
	 *
	 * @var array<int, string>
	 */
	private static array $saved = array();

	/**
	 * Registers the post type, its meta and hooks. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! post_type_exists( self::TYPE ) ) {
			self::register_type();
		}
		if ( has_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ) ) ) {
			return;
		}

		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( self::class, 'force_private' ), 10, 2 );
		add_filter( 'wp_untrash_post_status', array( self::class, 'untrash_status' ), 10, 2 );
		add_filter( 'rest_pre_insert_' . self::TYPE, array( self::class, 'pre_insert' ), 10, 2 );
		add_action( 'rest_after_insert_' . self::TYPE, array( self::class, 'after_insert' ), 10, 2 );
		add_action( 'trashed_post', array( self::class, 'trashed' ) );
		add_action( 'before_delete_post', array( self::class, 'before_delete' ), 10, 2 );
		add_action( 'untrashed_post', array( self::class, 'untrashed' ) );
		add_action( 'load-post.php', array( self::class, 'load_editor' ) );
		add_action( 'load-edit.php', array( self::class, 'redirect_list' ) );
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_block_types' ), 20, 2 );
		add_filter( 'block_editor_settings_all', array( self::class, 'editor_settings' ), 10, 2 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ) );
	}

	/**
	 * Registers the post type and the settings meta.
	 */
	private static function register_type(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'                     => __( 'Automations', 'pegwork' ),
					'singular_name'            => __( 'Automation', 'pegwork' ),
					'add_new'                  => __( 'Add automation', 'pegwork' ),
					'add_new_item'             => __( 'Add automation', 'pegwork' ),
					'edit_item'                => __( 'Edit automation', 'pegwork' ),
					'new_item'                 => __( 'New automation', 'pegwork' ),
					'view_item'                => __( 'View automation', 'pegwork' ),
					'search_items'             => __( 'Search automations', 'pegwork' ),
					'not_found'                => __( 'No automations found.', 'pegwork' ),
					'not_found_in_trash'       => __( 'No automations found in Trash.', 'pegwork' ),
					'all_items'                => __( 'Automations', 'pegwork' ),
					'item_published'           => __( 'Automation saved.', 'pegwork' ),
					'item_published_privately' => __( 'Automation saved.', 'pegwork' ),
					'item_updated'             => __( 'Automation saved.', 'pegwork' ),
					'item_reverted_to_draft'   => __( 'Automation saved.', 'pegwork' ),
				),
				'description'         => __( 'Pegwork automation definitions, edited in the block editor. The registry is the source.', 'pegwork' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'rest_base'           => 'pegwork-automations',
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'custom-fields' ),
				'map_meta_cap'        => true,
				'capability_type'     => array( 'pegwork_automation', 'pegwork_automations' ),
				'capabilities'        => array(
					'edit_posts'             => self::CAP,
					'edit_others_posts'      => self::CAP,
					'edit_private_posts'     => self::CAP,
					'edit_published_posts'   => self::CAP,
					'publish_posts'          => self::CAP,
					'read_private_posts'     => self::CAP,
					'delete_posts'           => self::CAP,
					'delete_private_posts'   => self::CAP,
					'delete_published_posts' => self::CAP,
					'delete_others_posts'    => self::CAP,
					'create_posts'           => self::CAP,
					'read'                   => self::CAP,
				),
			)
		);

		register_post_meta(
			self::TYPE,
			self::META_SETTINGS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'auth_callback'     => static fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', (int) $post_id ),
			)
		);
	}

	/**
	 * Keeps the settings meta a JSON object string (anything else becomes '').
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_settings( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $value : '';
	}

	/**
	 * Maps `pegwork_manage_automations` to `manage_options`, and refuses deleting file automations.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Context (post ID for post capabilities).
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ): array {
		$caps = is_array( $caps ) ? $caps : array();
		if ( 'delete_post' === $cap && isset( $args[0] ) && self::TYPE === get_post_type( (int) $args[0] ) ) {
			$key = self::key_of( (int) $args[0] );
			if ( '' !== $key && self::is_file_automation( $key ) ) {
				return array( 'do_not_allow' );
			}
		}
		if ( ! in_array( self::CAP, $caps, true ) ) {
			return $caps;
		}

		return array_values( array_unique( array_map( fn( $c ) => self::CAP === $c ? Screen::CAPABILITY : $c, $caps ) ) );
	}

	/**
	 * Forces `private` on every saved automation post, and no password.
	 *
	 * @param array<string, mixed> $data    Post data for the database (slashed).
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function force_private( $data, $postarr ): array {
		unset( $postarr );
		$data = is_array( $data ) ? $data : array();
		if ( self::TYPE === ( $data['post_type'] ?? '' ) && ! in_array( $data['post_status'] ?? '', self::KEEP_STATUS, true ) ) {
			$data['post_status']   = 'private';
			$data['post_password'] = '';
		}
		return $data;
	}

	/**
	 * Restores a trashed automation as private (core would restore it as a draft).
	 *
	 * @param string $status  Status to restore.
	 * @param int    $post_id Post ID.
	 */
	public static function untrash_status( $status, $post_id ): string {
		return self::TYPE === get_post_type( (int) $post_id ) ? 'private' : (string) $status;
	}

	/**
	 * Compiles the post being saved and saves the definition; a refused definition refuses the save.
	 * Hooked to rest_pre_insert_pegwork_automation. Autosaves are never compiled.
	 *
	 * @param \stdClass|WP_Error $prepared Post prepared for the database.
	 * @param WP_REST_Request    $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public static function pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! $request instanceof WP_REST_Request || str_contains( $request->get_route(), '/autosaves' ) ) {
			return $prepared;
		}
		if ( 'trash' === ( $prepared->post_status ?? '' ) ) {
			return $prepared;
		}

		$post_id  = (int) ( $prepared->ID ?? 0 );
		$existing = $post_id > 0 ? get_post( $post_id ) : null;
		$title    = isset( $prepared->post_title ) ? (string) $prepared->post_title : ( $existing ? $existing->post_title : '' );
		$content  = isset( $prepared->post_content ) ? (string) $prepared->post_content : ( $existing ? $existing->post_content : '' );
		$meta     = $request->get_param( 'meta' );
		$settings = is_array( $meta ) && array_key_exists( self::META_SETTINGS, $meta ) ? (string) $meta[ self::META_SETTINGS ] : ( $post_id > 0 ? (string) get_post_meta( $post_id, self::META_SETTINGS, true ) : '' );
		$locked   = $post_id > 0 ? self::key_of( $post_id ) : '';

		$result = self::save( $title, $content, $settings, $locked, $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::$saved[ $post_id ] = $result;
		return $prepared;
	}

	/**
	 * Compiles a post's parts and saves the definition through pegwork_save_automation().
	 *
	 * @param string $title    Title (unslashed).
	 * @param string $content  Block markup (unslashed).
	 * @param string $settings Settings JSON.
	 * @param string $locked   The post's locked key, or ''.
	 * @param int    $post_id  Post ID, 0 when new.
	 * @return string|WP_Error The saved key, or `pegwork_invalid_automation` (400, or 409 for a file
	 *                         key) with messages by pointer in data `fields`.
	 */
	public static function save( string $title, string $content, string $settings, string $locked, int $post_id ): string|WP_Error {
		$compiled   = Builder::compile( $title, $content, $settings, $locked );
		$errors     = $compiled['errors'];
		$definition = $compiled['definition'];
		$key        = (string) $definition['key'];

		if ( '' === $locked && ! array_key_exists( 'enabled', $definition ) ) {
			$definition['enabled'] = false;
		}
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$definition = self::kses( $definition );
		}

		if ( array() === $errors && '' === $locked && '' !== $key && 'db' === pegwork_registry()->automation_source( $key ) ) {
			$holder = self::find( $key );
			if ( $holder !== $post_id ) {
				/* translators: %s: automation key. */
				$errors['/key'] = sprintf( __( 'An automation with key "%s" already exists. Choose another key.', 'pegwork' ), $key );
			}
		}
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		$saved = pegwork_save_automation( $definition );
		if ( is_wp_error( $saved ) ) {
			$data = $saved->get_error_data();
			if ( 'pegwork_automation_file_defined' === $saved->get_error_code() ) {
				return new WP_Error(
					'pegwork_invalid_automation',
					$saved->get_error_message(),
					array(
						'status' => 409,
						'fields' => array( '/key' => $saved->get_error_message() ),
					)
				);
			}
			return new WP_Error(
				'pegwork_invalid_automation',
				$saved->get_error_message(),
				array(
					'status' => 400,
					'fields' => is_array( $data['fields'] ?? null ) ? $data['fields'] : array(),
				)
			);
		}

		return $key;
	}

	/**
	 * Passes every email `html` template (steps, branches) through wp_kses_post(), for users without
	 * `unfiltered_html`. Link steps cannot send email.
	 *
	 * @param array<string, mixed> $definition Compiled definition.
	 * @return array<string, mixed>
	 */
	private static function kses( array $definition ): array {
		$walk = static function ( array $steps ) use ( &$walk ): array {
			foreach ( $steps as $index => $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				if ( isset( $step['html'] ) && is_string( $step['html'] ) ) {
					$steps[ $index ]['html'] = wp_kses_post( $step['html'] );
				}
				foreach ( is_array( $step['branches'] ?? null ) ? $step['branches'] : array() as $b => $branch ) {
					if ( is_array( $branch ) && is_array( $branch['steps'] ?? null ) ) {
						$steps[ $index ]['branches'][ $b ]['steps'] = $walk( $branch['steps'] );
					}
				}
				if ( is_array( $step['otherwise'] ?? null ) ) {
					$steps[ $index ]['otherwise'] = $walk( $step['otherwise'] );
				}
			}
			return $steps;
		};
		if ( is_array( $definition['steps'] ?? null ) ) {
			$definition['steps'] = $walk( $definition['steps'] );
		}
		return $definition;
	}

	/**
	 * Records the saved key and hash, and stores the canonical settings. Hooked to rest_after_insert.
	 *
	 * @param WP_Post         $post    Saved post.
	 * @param WP_REST_Request $request Request.
	 */
	public static function after_insert( $post, $request ): void {
		unset( $request );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$key = self::$saved[ $post->ID ] ?? self::$saved[0] ?? null;
		unset( self::$saved[ $post->ID ], self::$saved[0] );
		if ( null === $key ) {
			return;
		}
		self::link( $post->ID, $key );
	}

	/**
	 * Marks a post as editing a registry automation: key, hash and canonical settings meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Automation key.
	 */
	private static function link( int $post_id, string $key ): void {
		$automation = pegwork_registry()->automation( $key );
		if ( null === $automation ) {
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $key );
		update_post_meta( $post_id, self::META_HASH, Builder::hash( $automation ) );
		update_post_meta( $post_id, self::META_SETTINGS, wp_slash( self::settings_json( Builder::generate( $automation )['settings'] ) ) );
	}

	/**
	 * Deletes the definition of a trashed site automation (runs stay). Hooked to trashed_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function trashed( $post_id ): void {
		self::delete_definition( (int) $post_id );
	}

	/**
	 * Deletes the definition when an automation post is deleted without passing through the trash.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public static function before_delete( $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && 'trash' === $post->post_status ) {
			return;
		}
		self::delete_definition( (int) $post_id );
	}

	/**
	 * Removes a post's DB definition, if it is a site automation.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function delete_definition( int $post_id ): void {
		if ( self::TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$key = self::key_of( $post_id );
		if ( '' !== $key && 'db' === pegwork_registry()->automation_source( $key ) ) {
			pegwork_delete_automation( $key );
		}
	}

	/**
	 * Saves a restored automation again, so it is back in the registry when it still compiles.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function untrashed( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type ) {
			return;
		}
		$key = self::key_of( $post->ID );
		if ( '' === $key || null !== pegwork_registry()->automation( $key ) ) {
			return;
		}
		$saved = self::save( $post->post_title, $post->post_content, (string) get_post_meta( $post->ID, self::META_SETTINGS, true ), $key, $post->ID );
		if ( is_string( $saved ) ) {
			self::link( $post->ID, $saved );
		}
	}

	/**
	 * Before the editor loads an automation: regenerates the post when its registry definition
	 * changed. Hooked to load-post.php.
	 */
	public static function load_editor(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which post is opened; nothing is changed on the user's behalf.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post_id > 0 && self::TYPE === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
			self::sync( $post_id );
		}
	}

	/**
	 * Rewrites a post from its registry definition when the definition hash differs.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the post was rewritten.
	 */
	public static function sync( int $post_id ): bool {
		$key        = self::key_of( $post_id );
		$automation = '' === $key ? null : pegwork_registry()->automation( $key );
		if ( null === $automation || get_post_meta( $post_id, self::META_HASH, true ) === Builder::hash( $automation ) ) {
			return false;
		}

		$generated = Builder::generate( $automation );
		// Regeneration is not an edit: keep the modified date ("Last edited" in the editor).
		$post = get_post( $post_id );
		$keep = static function ( $data, $postarr ) use ( $post_id, $post ) {
			if ( $post instanceof WP_Post && (int) ( $postarr['ID'] ?? 0 ) === $post_id ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $keep, 99, 2 );
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => $generated['title'],
					'post_content' => $generated['content'],
				)
			)
		);
		remove_filter( 'wp_insert_post_data', $keep, 99 );
		self::link( $post_id, $key );
		return true;
	}

	/**
	 * Creates a post for every registry automation that has none (file automations, imports,
	 * `pegwork_save_automation()` calls).
	 *
	 * @return int Posts created.
	 */
	public static function ensure_posts(): int {
		$created = 0;
		$held    = self::keys_with_posts();
		foreach ( pegwork_registry()->automations() as $key => $automation ) {
			if ( isset( $held[ (string) $key ] ) ) {
				continue;
			}
			$generated = Builder::generate( $automation );
			$post_id   = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => self::TYPE,
						'post_status'  => 'private',
						'post_title'   => $generated['title'],
						'post_content' => $generated['content'],
					)
				),
				true
			);
			if ( is_int( $post_id ) && $post_id > 0 ) {
				self::link( $post_id, (string) $key );
				++$created;
			}
		}
		return $created;
	}

	/**
	 * The post ID editing an automation key (not trashed), or 0.
	 *
	 * @param string $key Automation key.
	 */
	public static function find( string $key ): int {
		$held = self::keys_with_posts();
		return $held[ $key ] ?? 0;
	}

	/**
	 * Post IDs by automation key, for every automation post outside the trash (oldest wins on a duplicate).
	 *
	 * @return array<string, int>
	 */
	public static function keys_with_posts(): array {
		$ids  = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'private', 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of automation posts; the key lives in meta.
			)
		);
		$held = array();
		foreach ( $ids as $id ) {
			$key = self::key_of( (int) $id );
			if ( '' !== $key && ! isset( $held[ $key ] ) ) {
				$held[ $key ] = (int) $id;
			}
		}
		return $held;
	}

	/**
	 * The locked automation key of a post, or ''.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function key_of( int $post_id ): string {
		$key = get_post_meta( $post_id, self::META_KEY, true );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether a key belongs to a file package (read-only here).
	 *
	 * @param string $key Automation key.
	 */
	public static function is_file_automation( string $key ): bool {
		$source = pegwork_registry()->automation_source( $key );
		return null !== $source && 'db' !== $source;
	}

	/**
	 * Settings as stored in meta.
	 *
	 * @param array<string, mixed> $settings Settings object.
	 */
	public static function settings_json( array $settings ): string {
		return (string) wp_json_encode( (object) $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Only automation steps, decisions, branches and loops in an automation post's editor.
	 *
	 * @param bool|string[]           $allowed Allowed block types.
	 * @param WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public static function allowed_block_types( $allowed, $context ) {
		if ( $context instanceof WP_Block_Editor_Context && $context->post instanceof WP_Post && self::TYPE === $context->post->post_type ) {
			return array( Builder::STEP, Builder::DECISION, Builder::BRANCH, Builder::LOOP );
		}
		return $allowed;
	}

	/**
	 * Locks every block of a file-defined automation (no inserting, moving or removing).
	 *
	 * @param array<string, mixed>    $settings Editor settings.
	 * @param WP_Block_Editor_Context $context  Editor context.
	 * @return array<string, mixed>
	 */
	public static function editor_settings( $settings, $context ): array {
		$settings = is_array( $settings ) ? $settings : array();
		if ( $context instanceof WP_Block_Editor_Context && $context->post instanceof WP_Post && self::TYPE === $context->post->post_type ) {
			$key = self::key_of( $context->post->ID );
			if ( '' !== $key && self::is_file_automation( $key ) ) {
				$settings['templateLock']  = 'all';
				$settings['canLockBlocks'] = false;
			}
		}
		return $settings;
	}

	/**
	 * Sends the core list screen of automation posts to Pegwork > Automations. Hooked to load-edit.php.
	 */
	public static function redirect_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::TYPE === $type && ! isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request.
			wp_safe_redirect( Screen::url() );
			exit;
		}
	}

	/**
	 * Highlights Pegwork in the admin menu on automation editor screens.
	 *
	 * @param string $parent_file Parent menu slug.
	 */
	public static function parent_file( $parent_file ): string {
		return self::on_editor_screen() ? Api\ADMIN_MENU : (string) $parent_file;
	}

	/**
	 * Highlights Pegwork > Automations on automation editor screens.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 */
	public static function submenu_file( $submenu_file ): ?string {
		return self::on_editor_screen() ? Screen::SLUG : $submenu_file;
	}

	/**
	 * Whether the current admin screen edits an automation post.
	 */
	public static function on_editor_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && self::TYPE === $screen->post_type && 'post' === $screen->base;
	}

	/**
	 * A refused save.
	 *
	 * @param array<string, string> $errors Messages by pointer.
	 */
	private static function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'pegwork_invalid_automation',
			/* translators: %s: validation messages. */
			sprintf( __( 'This automation cannot be saved: %s', 'pegwork' ), implode( ' ', $errors ) ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
}
