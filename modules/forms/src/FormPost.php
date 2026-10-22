<?php
/**
 * The `pegwork_form` post type: the builder's editing surface.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api;
use WP_Block_Editor_Context;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * A `pegwork_form` post is never a source: the registry is. Saving in the block editor compiles the
 * post (title, field and step blocks, settings meta) into a definition and calls pegwork_save_form()
 * (`rest_pre_insert_pegwork_form`); a definition the registry refuses refuses the save, with messages
 * by JSON pointer. Trashing calls pegwork_delete_form(); entries stay. Opening a post regenerates it
 * when the registry definition changed (import, file edits). File-defined forms open read-only.
 *
 * Every capability maps to `pegwork_manage_forms`, which maps to Module::capability() (filter
 * `pegwork_forms_capability`, default `manage_options`). Status is always `private`, so core REST
 * never serves a form to visitors.
 */
final class FormPost {

	/**
	 * Post type key.
	 */
	public const TYPE = 'pegwork_form';

	/**
	 * Primitive capability every post type capability maps to.
	 */
	public const CAP = 'pegwork_manage_forms';

	/**
	 * Meta: the settings object as JSON (REST, edited by the settings panel).
	 */
	public const META_SETTINGS = '_pegwork_form_settings';

	/**
	 * Meta: the registry key this post edits; set on the first successful save, then locked.
	 */
	public const META_KEY = '_pegwork_form_key';

	/**
	 * Meta: Definition::hash() of the registry form the content was last saved or generated from.
	 */
	public const META_HASH = '_pegwork_form_hash';

	/**
	 * Statuses a form post may hold besides `private`.
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
					'name'                     => __( 'Forms', 'pegwork' ),
					'singular_name'            => __( 'Form', 'pegwork' ),
					'add_new'                  => __( 'Add form', 'pegwork' ),
					'add_new_item'             => __( 'Add form', 'pegwork' ),
					'edit_item'                => __( 'Edit form', 'pegwork' ),
					'new_item'                 => __( 'New form', 'pegwork' ),
					'view_item'                => __( 'View form', 'pegwork' ),
					'search_items'             => __( 'Search forms', 'pegwork' ),
					'not_found'                => __( 'No forms found.', 'pegwork' ),
					'not_found_in_trash'       => __( 'No forms found in Trash.', 'pegwork' ),
					'all_items'                => __( 'Forms', 'pegwork' ),
					'item_published'           => __( 'Form saved.', 'pegwork' ),
					'item_published_privately' => __( 'Form saved.', 'pegwork' ),
					'item_updated'             => __( 'Form saved.', 'pegwork' ),
					'item_reverted_to_draft'   => __( 'Form saved.', 'pegwork' ),
				),
				'description'         => __( 'Pegwork form definitions, edited in the block editor. The registry is the source.', 'pegwork' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => true,
				'rest_base'           => 'pegwork-forms',
				'query_var'           => false,
				'rewrite'             => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'custom-fields' ),
				'map_meta_cap'        => true,
				'capability_type'     => array( 'pegwork_form', 'pegwork_forms' ),
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
	 * Maps `pegwork_manage_forms` to the forms capability, and refuses deleting file-defined forms.
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
			if ( '' !== $key && self::is_file_form( $key ) ) {
				return array( 'do_not_allow' );
			}
		}
		if ( ! in_array( self::CAP, $caps, true ) ) {
			return $caps;
		}

		return array_values( array_unique( array_map( fn( $c ) => self::CAP === $c ? Module::capability() : $c, $caps ) ) );
	}

	/**
	 * Forces `private` on every saved form post (drafts, publish, pending, future), and no password.
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
	 * Restores a trashed form as private (core would restore it as a draft).
	 *
	 * @param string $status  Status to restore.
	 * @param int    $post_id Post ID.
	 */
	public static function untrash_status( $status, $post_id ): string {
		return self::TYPE === get_post_type( (int) $post_id ) ? 'private' : (string) $status;
	}

	/**
	 * Compiles the post being saved and saves the definition; a refused definition refuses the save.
	 * Hooked to rest_pre_insert_pegwork_form. Autosaves are never compiled.
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
	 * Compiles a post's parts and saves the definition through pegwork_save_form().
	 *
	 * @param string $title    Title (unslashed).
	 * @param string $content  Block markup (unslashed).
	 * @param string $settings Settings JSON.
	 * @param string $locked   The post's locked key, or ''.
	 * @param int    $post_id  Post ID, 0 when new.
	 * @return string|WP_Error The saved key, or `pegwork_invalid_form` (400) with messages by pointer in data `fields`.
	 */
	public static function save( string $title, string $content, string $settings, string $locked, int $post_id ): string|WP_Error {
		$compiled = Builder::compile( $title, $content, $settings, $locked );
		$errors   = $compiled['errors'];
		$key      = (string) $compiled['definition']['key'];

		if ( array() === $errors && '' === $locked && '' !== $key && 'db' === pegwork_registry()->form_source( $key ) ) {
			$holder = self::find( $key );
			if ( $holder !== $post_id ) {
				/* translators: %s: form key. */
				$errors['/key'] = sprintf( __( 'A form with key "%s" already exists. Choose another key.', 'pegwork' ), $key );
			}
		}
		if ( array() !== $errors ) {
			return self::invalid( $errors );
		}

		$saved = pegwork_save_form( $compiled['definition'] );
		if ( is_wp_error( $saved ) ) {
			$data = $saved->get_error_data();
			if ( 'pegwork_form_file_defined' === $saved->get_error_code() ) {
				return new WP_Error(
					'pegwork_invalid_form',
					$saved->get_error_message(),
					array(
						'status' => 409,
						'fields' => array( '/key' => $saved->get_error_message() ),
					)
				);
			}
			return new WP_Error(
				'pegwork_invalid_form',
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
	 * Marks a post as editing a registry form: key, hash and canonical settings meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Form key.
	 */
	private static function link( int $post_id, string $key ): void {
		$form = pegwork_registry()->form( $key );
		if ( null === $form ) {
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $key );
		update_post_meta( $post_id, self::META_HASH, Definition::hash( $form ) );
		update_post_meta( $post_id, self::META_SETTINGS, wp_slash( self::settings_json( Builder::generate( $form )['settings'] ) ) );
	}

	/**
	 * Deletes the definition of a trashed site form (entries stay). Hooked to trashed_post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function trashed( $post_id ): void {
		self::delete_definition( (int) $post_id );
	}

	/**
	 * Deletes the definition when a form post is deleted without passing through the trash.
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
	 * Removes a post's DB definition, if it is a site form.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function delete_definition( int $post_id ): void {
		if ( self::TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$key = self::key_of( $post_id );
		if ( '' !== $key && 'db' === pegwork_registry()->form_source( $key ) ) {
			pegwork_delete_form( $key );
		}
	}

	/**
	 * Saves a restored form again, so it is back in the registry when it still compiles.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function untrashed( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type ) {
			return;
		}
		$key = self::key_of( $post->ID );
		if ( '' === $key || null !== pegwork_registry()->form( $key ) ) {
			return;
		}
		$saved = self::save( $post->post_title, $post->post_content, (string) get_post_meta( $post->ID, self::META_SETTINGS, true ), $key, $post->ID );
		if ( is_string( $saved ) ) {
			self::link( $post->ID, $saved );
		}
	}

	/**
	 * Before the editor loads a form: regenerates the post when its registry definition changed.
	 * Hooked to load-post.php.
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
		$key  = self::key_of( $post_id );
		$form = '' === $key ? null : pegwork_registry()->form( $key );
		if ( null === $form || get_post_meta( $post_id, self::META_HASH, true ) === Definition::hash( $form ) ) {
			return false;
		}

		$generated = Builder::generate( $form );
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
	 * Creates a post for every registry form that has none (file forms, imports, `pegwork_save_form()` calls).
	 *
	 * @return int Posts created.
	 */
	public static function ensure_posts(): int {
		$created = 0;
		$held    = self::keys_with_posts();
		foreach ( pegwork_registry()->forms() as $key => $form ) {
			if ( isset( $held[ (string) $key ] ) ) {
				continue;
			}
			$generated = Builder::generate( $form );
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
	 * The post ID editing a form key (not trashed), or 0.
	 *
	 * @param string $key Form key.
	 */
	public static function find( string $key ): int {
		$held = self::keys_with_posts();
		return $held[ $key ] ?? 0;
	}

	/**
	 * Post IDs by form key, for every form post outside the trash (oldest wins on a duplicate).
	 *
	 * @return array<string, int>
	 */
	private static function keys_with_posts(): array {
		$ids  = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => array( 'private', 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of form posts; the key lives in meta.
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
	 * The locked form key of a post, or ''.
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
	 * @param string $key Form key.
	 */
	public static function is_file_form( string $key ): bool {
		$source = pegwork_registry()->form_source( $key );
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
	 * Only form fields and steps in a form post's editor.
	 *
	 * @param bool|string[]           $allowed Allowed block types.
	 * @param WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public static function allowed_block_types( $allowed, $context ) {
		if ( $context instanceof WP_Block_Editor_Context && $context->post instanceof WP_Post && self::TYPE === $context->post->post_type ) {
			return array( Builder::FIELD, Builder::STEP );
		}
		return $allowed;
	}

	/**
	 * Locks every block of a file-defined form (no inserting, moving or removing).
	 *
	 * @param array<string, mixed>    $settings Editor settings.
	 * @param WP_Block_Editor_Context $context  Editor context.
	 * @return array<string, mixed>
	 */
	public static function editor_settings( $settings, $context ): array {
		$settings = is_array( $settings ) ? $settings : array();
		if ( $context instanceof WP_Block_Editor_Context && $context->post instanceof WP_Post && self::TYPE === $context->post->post_type ) {
			$key = self::key_of( $context->post->ID );
			if ( '' !== $key && self::is_file_form( $key ) ) {
				$settings['templateLock']  = 'all';
				$settings['canLockBlocks'] = false;
			}
		}
		return $settings;
	}

	/**
	 * Sends the core list screen of form posts to Pegwork > Forms. Hooked to load-edit.php.
	 */
	public static function redirect_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		if ( self::TYPE === $type && ! isset( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request.
			wp_safe_redirect( FormsAdmin::url() );
			exit;
		}
	}

	/**
	 * Highlights Pegwork in the admin menu on form editor screens.
	 *
	 * @param string $parent_file Parent menu slug.
	 */
	public static function parent_file( $parent_file ): string {
		return self::on_form_screen() ? Api\ADMIN_MENU : (string) $parent_file;
	}

	/**
	 * Highlights Pegwork > Forms on form editor screens.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 */
	public static function submenu_file( $submenu_file ): ?string {
		return self::on_form_screen() ? FormsAdmin::SLUG : $submenu_file;
	}

	/**
	 * Whether the current admin screen edits a form post.
	 */
	public static function on_form_screen(): bool {
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
			'pegwork_invalid_form',
			/* translators: %s: validation messages. */
			sprintf( __( 'This form cannot be saved: %s', 'pegwork' ), implode( ' ', $errors ) ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}
}
