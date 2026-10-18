<?php
/**
 * Validator rules for options pages (`options-pages/<key>.json`).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry\Rules;

/**
 * An options page is one autoloaded option named by its key, edited on a generated settings screen.
 *
 * Used by Pegwork\Registry\Validator, whose helpers (props, type, key, add, ...) it calls.
 */
trait OptionsPageRules {

	/**
	 * Core option names created by populate_options() (WordPress 7.1), plus a few core options added later
	 * (`cron`, `sidebars_widgets`, ...). An options page key is its option name, so these are reserved.
	 */
	public const RESERVED_OPTION_NAMES = array(
		'active_plugins',
		'admin_email',
		'admin_email_lifespan',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_major',
		'auto_update_core_minor',
		'avatar_default',
		'avatar_rating',
		'blog_charset',
		'blog_public',
		'blogdescription',
		'blogname',
		'can_compress_scripts',
		'category_base',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_moderation',
		'comment_order',
		'comment_previously_approved',
		'comment_registration',
		'comments_notify',
		'comments_per_page',
		'cron',
		'date_format',
		'db_version',
		'default_category',
		'default_comment_status',
		'default_comments_page',
		'default_email_category',
		'default_link_category',
		'default_ping_status',
		'default_pingback_flag',
		'default_post_format',
		'default_role',
		'disallowed_keys',
		'finished_splitting_shared_terms',
		'fresh_site',
		'gmt_offset',
		'hack_file',
		'home',
		'html_type',
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'initial_db_version',
		'large_size_h',
		'large_size_w',
		'link_manager_enabled',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'moderation_keys',
		'moderation_notify',
		'page_comments',
		'page_for_posts',
		'page_on_front',
		'permalink_structure',
		'ping_sites',
		'posts_per_page',
		'posts_per_rss',
		'recently_activated',
		'recently_edited',
		'require_name_email',
		'rewrite_rules',
		'rss_use_excerpt',
		'show_avatars',
		'show_comments_cookies_opt_in',
		'show_on_front',
		'sidebars_widgets',
		'site_icon',
		'siteurl',
		'start_of_week',
		'sticky_posts',
		'stylesheet',
		'tag_base',
		'template',
		'theme_switched',
		'thread_comments',
		'thread_comments_depth',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'uploads_use_yearmonth_folders',
		'use_smilies',
		'use_trackback',
		'users_can_register',
		'widget_categories',
		'widget_rss',
		'widget_text',
	);

	/**
	 * Pattern for capability names, such as `manage_options`.
	 */
	public const CAPABILITY_PATTERN = '/^[a-z][a-z0-9_]*$/';

	/**
	 * Validates an options page.
	 *
	 * @param mixed  $data    Decoded options page.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function options_page( mixed $data, string $pointer ): void {
		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'     => fn( $v, $p ) => $this->format( $v, $p ),
				'key'        => fn( $v, $p ) => $this->option_name( $v, $p ),
				'title'      => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'menu_title' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'capability' => fn( $v, $p ) => $this->capability( $v, $p ),
				'fields'     => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->field( $item, $q, 'options_page' ) ),
			),
			array( 'format', 'key', 'title', 'fields' )
		);

		if ( $is_object ) {
			$this->unique( $data['fields'] ?? null, $pointer . '/fields', 'key' );
		}
	}

	/**
	 * Checks an options page key, which is also its option name.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function option_name( mixed $value, string $pointer ): void {
		if ( ! $this->bounded_key( $value, $pointer ) ) {
			return;
		}
		if ( in_array( $value, self::RESERVED_OPTION_NAMES, true ) || str_starts_with( $value, 'wp_' ) || str_starts_with( $value, 'pegwork_' ) ) {
			$this->add(
				'reserved_key',
				$pointer,
				/* translators: %s: key. */
				sprintf( __( '"%s" is a WordPress option name or uses a reserved prefix ("wp_", "pegwork_"); the key is the option name, so choose another.', 'pegwork' ), $value )
			);
		}
	}

	/**
	 * Checks a capability name.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function capability( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'string' ) && ! preg_match( self::CAPABILITY_PATTERN, $value ) ) {
			$this->add( 'invalid_value', $pointer, __( 'Capabilities use lowercase letters, digits and underscores, such as "manage_options".', 'pegwork' ) );
		}
	}

	/**
	 * Checks a key that is also limited to OBJECT_KEY_MAX_LENGTH characters (forms, automations, options pages).
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 * @return bool Whether the value is a well-formed key within the limit.
	 */
	private function bounded_key( mixed $value, string $pointer ): bool {
		if ( ! $this->key( $value, $pointer ) ) {
			return false;
		}
		if ( strlen( $value ) > self::OBJECT_KEY_MAX_LENGTH ) {
			/* translators: %d: maximum key length. */
			$this->add( 'key_too_long', $pointer, sprintf( __( 'Key is longer than %d characters.', 'pegwork' ), self::OBJECT_KEY_MAX_LENGTH ) );
			return false;
		}

		return true;
	}

	/**
	 * Checks that `post` fields name a defined content type.
	 *
	 * @param array<string, mixed>                $page    Valid options page.
	 * @param string                              $pointer JSON pointer of the options page.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 */
	private function options_page_references( array $page, string $pointer, array $known ): void {
		foreach ( $page['fields'] as $index => $field ) {
			if ( self::POST_TYPE_FIELD === $field['type'] && ! isset( $known['content_type'][ $field['post_type'] ] ) ) {
				$this->add(
					'unknown_content_type',
					$pointer . '/fields/' . $index . '/post_type',
					/* translators: %s: content type key. */
					sprintf( __( 'Content type "%s" is not defined.', 'pegwork' ), $field['post_type'] )
				);
			}
		}
	}
}
