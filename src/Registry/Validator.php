<?php
/**
 * Schema validator: the runtime truth for registry files.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

use Pegwork\Content\Meta;
use Pegwork\Content\Relationships;
use Pegwork\Registry\Rules\AutomationRules;
use Pegwork\Registry\Rules\FormRules;
use Pegwork\Registry\Rules\ListingRules;
use Pegwork\Registry\Rules\OptionsPageRules;

/**
 * Validates decoded registry objects (manifest, content type, taxonomy, relationship, form, automation, options page).
 *
 * Works on PHP arrays as decoded by json_decode( ..., true ) or read from the DB option.
 * `schema/registry.schema.json` describes the same per-file rules; checks that need more than
 * one object (references, duplicate keys) exist only here. The rules for the optional sections
 * live in traits under `Rules/`, one per section.
 */
final class Validator {

	use AutomationRules;
	use FormRules;
	use ListingRules;
	use OptionsPageRules;

	/**
	 * Current file format.
	 */
	public const FORMAT = 1;

	/**
	 * Pattern for every key and package id.
	 */
	public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

	/**
	 * Pattern for term slugs.
	 */
	public const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * Pattern for package versions (semver).
	 */
	public const SEMVER_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/';

	/**
	 * Pattern for date values (Y-m-d).
	 */
	public const DATE_PATTERN = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';

	/**
	 * Meta key override (`wp.meta_key`): letters, digits and underscores, optionally after a leading underscore.
	 */
	public const META_KEY_PATTERN = '/^_?[A-Za-z][A-Za-z0-9_]*$/';

	/**
	 * Property names that look like PHP callables. Never allowed anywhere.
	 */
	public const CALLABLE_PATTERN = '/(callback|_cb|_class)$/';

	/**
	 * Content type field keys that collide with record properties: `status` in record event `changed` lists
	 * (docs/events.md), and `id`, `status`, `type` in automation `record.*` paths.
	 */
	public const RESERVED_FIELD_KEYS = array( 'id', 'status', 'type' );

	/**
	 * WordPress limit for post type keys.
	 */
	public const POST_TYPE_MAX_LENGTH = 20;

	/**
	 * WordPress limit for taxonomy keys.
	 */
	public const TAXONOMY_MAX_LENGTH = 32;

	/**
	 * Limit for form, automation and options page keys.
	 */
	public const OBJECT_KEY_MAX_LENGTH = 64;

	/**
	 * Phase 1 field types.
	 */
	public const FIELD_TYPES = array( 'text', 'textarea', 'rich_text', 'url', 'email', 'boolean', 'date', 'number', 'select' );

	/**
	 * Field type holding one post ID. Options pages only; content types link records with relationships.
	 */
	public const POST_TYPE_FIELD = 'post';

	/**
	 * Field type for one uploaded file. Unbound form fields only.
	 */
	public const FILE_FIELD = 'file';

	/**
	 * Field types whose values are strings.
	 */
	public const STRING_TYPES = array( 'text', 'textarea', 'rich_text', 'url', 'email' );

	/**
	 * Validation rules that apply to string types and to numbers.
	 */
	public const STRING_RULES = array( 'min_length', 'max_length', 'min_words', 'max_words', 'pattern' );
	public const NUMBER_RULES = array( 'min', 'max' );

	/**
	 * WordPress query vars, core post types and core taxonomies. A content type or taxonomy
	 * with one of these keys breaks queries or collides with core. Keys starting `wp_` are also reserved.
	 */
	public const RESERVED_KEYS = array(
		'action',
		'attachment',
		'attachment_id',
		'author',
		'author_name',
		'calendar',
		'cat',
		'category',
		'category__and',
		'category__in',
		'category__not_in',
		'category_name',
		'comments_per_page',
		'comments_popup',
		'cpage',
		'custom',
		'custom_css',
		'customize_changeset',
		'customize_messenger_channel',
		'customized',
		'day',
		'debug',
		'embed',
		'error',
		'exact',
		'favicon',
		'feed',
		'fields',
		'hour',
		'link_category',
		'm',
		'minute',
		'monthnum',
		'more',
		'name',
		'nav_menu',
		'nav_menu_item',
		'nonce',
		'nopaging',
		'oembed_cache',
		'offset',
		'order',
		'orderby',
		'p',
		'page',
		'page_id',
		'paged',
		'pagename',
		'pb',
		'perm',
		'post',
		'post__in',
		'post__not_in',
		'post_format',
		'post_mime_type',
		'post_parent',
		'post_parent__in',
		'post_parent__not_in',
		'post_status',
		'post_tag',
		'post_type',
		'posts',
		'posts_per_archive_page',
		'posts_per_page',
		'preview',
		'rest_route',
		'revision',
		'robots',
		's',
		'search',
		'second',
		'sentence',
		'showposts',
		'sitemap',
		'static',
		'status',
		'subpost',
		'subpost_id',
		'tag',
		'tag__and',
		'tag__in',
		'tag__not_in',
		'tag_id',
		'tag_slug__and',
		'tag_slug__in',
		'taxonomy',
		'tb',
		'term',
		'terms',
		'theme',
		'title',
		'type',
		'types',
		'user_request',
		'w',
		'withcomments',
		'withoutcomments',
		'year',
	);

	/**
	 * Errors found so far.
	 *
	 * @var ValidationError[]
	 */
	private array $errors = array();

	/**
	 * Creates a validator for one source file.
	 *
	 * @param string $file Source file path, used in error context.
	 */
	private function __construct( private string $file ) {
	}

	/**
	 * Validates one decoded object.
	 *
	 * @param string $kind    One of `manifest`, `content_type`, `taxonomy`, `relationship`, `form`, `automation`, `options_page`.
	 * @param mixed  $data    Decoded object.
	 * @param string $file    Source file path for error context.
	 * @param string $pointer JSON pointer of the object within the source, empty for a whole file.
	 * @return ValidationError[] Empty when valid.
	 */
	public static function validate( string $kind, mixed $data, string $file, string $pointer = '' ): array {
		$validator = new self( $file );
		$validator->check_callables( $data, $pointer );

		match ( $kind ) {
			'manifest'     => $validator->manifest( $data, $pointer ),
			'content_type' => $validator->content_type( $data, $pointer ),
			'taxonomy'     => $validator->taxonomy( $data, $pointer ),
			'relationship' => $validator->relationship( $data, $pointer ),
			'form'         => $validator->form( $data, $pointer ),
			'automation'   => $validator->automation( $data, $pointer ),
			'options_page' => $validator->options_page( $data, $pointer ),
		};

		return $validator->errors;
	}

	/**
	 * Checks that a valid object's references resolve, and the rules that need other objects (for example
	 * that a relationship's `_rel_{key}` meta key is not a field meta key on its "from" type).
	 *
	 * `$known` maps kind => key => the defined object in canonical form. A value that is not an array
	 * (for example `true`) only proves the key exists; rules that need the object's contents are skipped for it.
	 *
	 * @param string                              $kind    Object kind; manifests and taxonomies have no references.
	 * @param array<string, mixed>                $data    Object that passed validate().
	 * @param string                              $file    Source file path for error context.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key (non-array values skip content checks).
	 * @param string                              $pointer JSON pointer of the object within the source.
	 * @return ValidationError[] Empty when every reference resolves.
	 */
	public static function references( string $kind, array $data, string $file, array $known, string $pointer = '' ): array {
		$validator = new self( $file );

		if ( 'content_type' === $kind ) {
			foreach ( $data['taxonomies'] ?? array() as $index => $usage ) {
				if ( ! isset( $known['taxonomy'][ $usage['key'] ] ) ) {
					$validator->add(
						'unknown_taxonomy',
						$pointer . '/taxonomies/' . $index . '/key',
						/* translators: %s: taxonomy key. */
						sprintf( __( 'Taxonomy "%s" is not defined.', 'pegwork' ), $usage['key'] )
					);
				}
			}
		}

		if ( 'relationship' === $kind ) {
			foreach ( array( 'from', 'to' ) as $end ) {
				if ( ! isset( $known['content_type'][ $data[ $end ] ] ) ) {
					$validator->add(
						'unknown_content_type',
						$pointer . '/' . $end,
						/* translators: %s: content type key. */
						sprintf( __( 'Content type "%s" is not defined.', 'pegwork' ), $data[ $end ] )
					);
				}
			}

			$from     = $known['content_type'][ $data['from'] ] ?? null;
			$fields   = is_array( $from ) && is_array( $from['fields'] ?? null ) && isset( $data['key'] ) ? $from['fields'] : array();
			$meta_key = Relationships::META_PREFIX . ( $data['key'] ?? '' );
			foreach ( $fields as $field ) {
				if ( Meta::is_meta( $field ) && Meta::key( $data['from'], $field ) === $meta_key ) {
					$validator->add(
						'meta_key_conflict',
						$pointer . '/key',
						/* translators: 1: meta key, 2: field key, 3: content type key. */
						sprintf( __( 'Meta key "%1$s" is already used by field "%2$s" on content type "%3$s".', 'pegwork' ), $meta_key, $field['key'], $data['from'] )
					);
				}
			}
		}

		match ( $kind ) {
			'content_type' => $validator->listing_references( $data, $pointer, $known ),
			'form'         => $validator->form_references( $data, $pointer, $known ),
			'automation'   => $validator->automation_references( $data, $pointer, $known ),
			'options_page' => $validator->options_page_references( $data, $pointer, $known ),
			default        => null,
		};

		return $validator->errors;
	}

	/**
	 * Appends a JSON pointer segment (RFC 6901 escaping).
	 *
	 * @param string     $pointer Parent pointer.
	 * @param int|string $segment Property name or list index.
	 */
	public static function pointer( string $pointer, int|string $segment ): string {
		return $pointer . '/' . str_replace( array( '~', '/' ), array( '~0', '~1' ), (string) $segment );
	}

	/**
	 * Validates a package manifest.
	 *
	 * @param mixed  $data    Decoded manifest.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function manifest( mixed $data, string $pointer ): void {
		$this->props(
			$data,
			$pointer,
			array(
				'format'   => fn( $v, $p ) => $this->format( $v, $p ),
				'id'       => fn( $v, $p ) => $this->key( $v, $p ),
				'version'  => fn( $v, $p ) => $this->version( $v, $p ),
				'label'    => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'requires' => fn( $v, $p ) => $this->map_of_strings( $v, $p ),
			),
			array( 'format', 'id', 'version', 'label' )
		);
	}

	/**
	 * Validates a content type, its fields and its taxonomy usage.
	 *
	 * @param mixed  $data    Decoded content type.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function content_type( mixed $data, string $pointer ): void {
		$strings = fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->type( $item, $q, 'string' ) );

		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'     => fn( $v, $p ) => $this->format( $v, $p ),
				'key'        => fn( $v, $p ) => $this->object_key( $v, $p, self::POST_TYPE_MAX_LENGTH ),
				'labels'     => fn( $v, $p ) => $this->labels( $v, $p ),
				'public'     => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'taxonomies' => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->taxonomy_usage( $item, $q ) ),
				'fields'     => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->field( $item, $q ) ),
				'listing'    => fn( $v, $p ) => $this->listing( $v, $p ),
				'wp'         => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array(
						'rest_base'       => fn( $w, $q ) => $this->type( $w, $q, 'string' ),
						'rewrite_slug'    => fn( $w, $q ) => $this->type( $w, $q, 'string' ),
						'has_archive'     => fn( $w, $q ) => $this->type( $w, $q, 'boolean' ),
						'menu_icon'       => fn( $w, $q ) => $this->type( $w, $q, 'string' ),
						'supports'        => $strings,
						'capability_type' => fn( $w, $q ) => $this->type( $w, $q, 'string' ),
						'allowed_blocks'  => $strings,
					)
				),
			),
			array( 'format', 'key', 'labels' )
		);

		if ( ! $is_object ) {
			return;
		}

		$this->unique( $data['taxonomies'] ?? null, $pointer . '/taxonomies', 'key' );
		$this->listing_targets( $data, $pointer );

		$fields = $data['fields'] ?? null;
		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			return;
		}

		$this->unique( $fields, $pointer . '/fields', 'key' );

		$field_keys = array_column( array_filter( $fields, 'is_array' ), 'key' );
		foreach ( $fields as $index => $field ) {
			foreach ( array( 'required_when', 'show_when' ) as $condition ) {
				$target = is_array( $field ) && is_array( $field[ $condition ] ?? null ) ? ( $field[ $condition ]['field'] ?? null ) : null;
				if ( is_string( $target ) && ! in_array( $target, $field_keys, true ) ) {
					$this->add(
						'unknown_field',
						$pointer . '/fields/' . $index . '/' . $condition . '/field',
						/* translators: %s: field key. */
						sprintf( __( 'Field "%s" is not defined on this content type.', 'pegwork' ), $target )
					);
				}
			}
		}
	}

	/**
	 * Field types allowed in a context.
	 *
	 * @param string $context `content_type`, `options_page` or `form` (unbound form fields).
	 * @return string[]
	 */
	public static function field_types( string $context = 'content_type' ): array {
		return match ( $context ) {
			'options_page' => array_merge( self::FIELD_TYPES, array( self::POST_TYPE_FIELD ) ),
			'form'         => array_merge( self::FIELD_TYPES, array( self::FILE_FIELD ) ),
			default        => self::FIELD_TYPES,
		};
	}

	/**
	 * Validates one field.
	 *
	 * The same rules serve content type fields, options page fields and unbound form fields; the context
	 * decides the allowed types and the context-only properties.
	 *
	 * @param mixed  $field   Decoded field.
	 * @param string $pointer JSON pointer of the field.
	 * @param string $context `content_type`, `options_page` or `form`.
	 */
	private function field( mixed $field, string $pointer, string $context = 'content_type' ): void {
		$string = fn( $v, $p ) => $this->type( $v, $p, 'string' );
		$checks = array(
			'key'           => function ( $v, $p ) use ( $context ) {
				if ( $this->key( $v, $p ) && 'content_type' === $context && in_array( $v, self::RESERVED_FIELD_KEYS, true ) ) {
					$this->add(
						'reserved_field_key',
						$p,
						/* translators: %s: field key. */
						sprintf( __( 'Field key "%s" is reserved: record events and automation paths use it for the post\'s own id, status and type.', 'pegwork' ), $v )
					);
				}
			},
			'type'          => fn( $v, $p ) => $this->field_type( $v, $p, $context ),
			'label'         => $string,
			'required'      => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
			'default'       => fn() => null, // Checked below against the field type.
			'options'       => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->option( $item, $q ) ),
			'required_when' => fn( $v, $p ) => $this->condition( $v, $p ),
			'show_when'     => fn( $v, $p ) => $this->condition( $v, $p ),
			'validate'      => fn( $v, $p ) => $this->rules( $v, $p ),
		);

		if ( 'form' !== $context ) {
			$checks['visibility'] = fn( $v, $p ) => $this->enum( $v, $p, array( 'public', 'private' ) );
		}
		if ( 'content_type' === $context ) {
			$checks['wp'] = fn( $v, $p ) => $this->props(
				$v,
				$p,
				array(
					'storage'  => fn( $w, $q ) => $this->enum( $w, $q, array( 'post_title', 'post_content' ) ),
					'meta_key' => fn( $w, $q ) => $this->meta_key( $w, $q ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Schema property name, not a query.
				)
			);
		}
		if ( 'options_page' === $context ) {
			$checks['post_type'] = fn( $v, $p ) => $this->key( $v, $p );
			foreach ( array( 'required_when', 'show_when', 'wp' ) as $name ) {
				$checks[ $name ] = fn( $v, $p ) => $this->add(
					'not_allowed_in_options_page',
					$p,
					/* translators: %s: property name. */
					sprintf( __( '"%s" is not allowed in options page fields (settings screens have no conditions or storage mapping).', 'pegwork' ), $name )
				);
			}
		}
		if ( 'form' === $context ) {
			$checks += $this->form_presentation_checks();

			$checks['accept']      = fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->extension( $item, $q ) );
			$checks['max_size_mb'] = function ( $v, $p ) {
				if ( $this->type( $v, $p, 'number' ) && $v <= 0 ) {
					$this->add( 'invalid_value', $p, __( '"max_size_mb" must be greater than 0.', 'pegwork' ) );
				}
			};
		}

		$is_object = $this->props( $field, $pointer, $checks, array( 'key', 'type', 'label' ) );

		$type = $field['type'] ?? null;
		if ( ! $is_object || ! in_array( $type, self::field_types( $context ), true ) ) {
			return;
		}

		if ( self::POST_TYPE_FIELD === $type && ! array_key_exists( 'post_type', $field ) ) {
			$this->add( 'missing_property', $pointer, __( 'Post fields need "post_type".', 'pegwork' ) );
		}
		if ( 'options_page' === $context && self::POST_TYPE_FIELD !== $type && array_key_exists( 'post_type', $field ) ) {
			$this->add( 'unknown_property', $pointer . '/post_type', __( '"post_type" is only allowed on post fields.', 'pegwork' ) );
		}
		if ( self::FILE_FIELD === $type && ! array_key_exists( 'accept', $field ) ) {
			$this->add( 'missing_property', $pointer, __( 'File fields need "accept" (the allowed file extensions).', 'pegwork' ) );
		}
		foreach ( array( 'accept', 'max_size_mb' ) as $name ) {
			if ( 'form' === $context && self::FILE_FIELD !== $type && array_key_exists( $name, $field ) ) {
				/* translators: %s: property name. */
				$this->add( 'unknown_property', $pointer . '/' . $name, sprintf( __( '"%s" is only allowed on file fields.', 'pegwork' ), $name ) );
			}
		}
		if ( 'form' === $context && isset( $field['display'] ) ) {
			$this->form_display( $field['display'], 'select' === $type ? 'single' : null, $pointer . '/display' );
		}

		$wp = is_array( $field['wp'] ?? null ) ? $field['wp'] : array();
		if ( isset( $wp['storage'], $wp['meta_key'] ) ) {
			$this->add( 'invalid_meta_key', $pointer . '/wp/meta_key', __( '"meta_key" applies only to meta storage.', 'pegwork' ) );
		}
		if ( is_string( $wp['meta_key'] ?? null ) && 'private' === ( $field['visibility'] ?? null ) && ! str_starts_with( $wp['meta_key'], '_' ) ) {
			$this->add( 'invalid_meta_key', $pointer . '/wp/meta_key', __( 'Private fields need a protected meta key (starting with "_").', 'pegwork' ) );
		}

		if ( 'select' === $type && ! array_key_exists( 'options', $field ) ) {
			$this->add( 'missing_property', $pointer, __( 'Select fields need "options".', 'pegwork' ) );
		}
		if ( 'select' !== $type && array_key_exists( 'options', $field ) ) {
			$this->add( 'unknown_property', $pointer . '/options', __( '"options" is only allowed on select fields.', 'pegwork' ) );
		}
		$this->unique( $field['options'] ?? null, $pointer . '/options', 'value' );

		if ( array_key_exists( 'default', $field ) && ! self::default_matches( $type, $field['default'] ) ) {
			$this->add(
				'invalid_default',
				$pointer . '/default',
				/* translators: %s: field type. */
				sprintf( __( 'Default does not match field type "%s".', 'pegwork' ), $type )
			);
		}

		$rules = $field['validate'] ?? null;
		if ( is_array( $rules ) ) {
			$applicable = match ( true ) {
				in_array( $type, self::STRING_TYPES, true ) => self::STRING_RULES,
				'number' === $type => self::NUMBER_RULES,
				default => array(),
			};
			foreach ( array_keys( $rules ) as $rule ) {
				$known = in_array( $rule, self::STRING_RULES, true ) || in_array( $rule, self::NUMBER_RULES, true );
				if ( $known && ! in_array( $rule, $applicable, true ) ) {
					$this->add(
						'validate_not_applicable',
						self::pointer( $pointer . '/validate', $rule ),
						/* translators: 1: validation rule, 2: field type. */
						sprintf( __( 'Rule "%1$s" does not apply to %2$s fields.', 'pegwork' ), $rule, $type )
					);
				}
			}
		}
	}

	/**
	 * Checks a field type for its context.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 * @param string $context `content_type`, `options_page` or `form`.
	 */
	private function field_type( mixed $value, string $pointer, string $context ): void {
		if ( self::POST_TYPE_FIELD === $value && 'options_page' !== $context ) {
			$this->add( 'post_field_not_allowed', $pointer, __( 'Field type "post" is only allowed on options pages. Link records with a relationship instead.', 'pegwork' ) );
			return;
		}

		$this->enum( $value, $pointer, self::field_types( $context ) );
	}

	/**
	 * Validates a taxonomy and its seed terms.
	 *
	 * @param mixed  $data    Decoded taxonomy.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function taxonomy( mixed $data, string $pointer ): void {
		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'       => fn( $v, $p ) => $this->format( $v, $p ),
				'key'          => fn( $v, $p ) => $this->object_key( $v, $p, self::TAXONOMY_MAX_LENGTH ),
				'labels'       => fn( $v, $p ) => $this->labels( $v, $p ),
				'hierarchical' => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'locked_terms' => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'terms'        => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->term( $item, $q ) ),
				'wp'           => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array( 'rewrite_slug' => fn( $w, $q ) => $this->type( $w, $q, 'string' ) )
				),
			),
			array( 'format', 'key', 'labels' )
		);

		if ( $is_object ) {
			$this->unique( $data['terms'] ?? null, $pointer . '/terms', 'slug' );
		}
	}

	/**
	 * Validates a relationship. `from` and `to` must differ (self links need a direction model,
	 * Phase 5), a rule JSON Schema cannot express.
	 *
	 * @param mixed  $data    Decoded relationship.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function relationship( mixed $data, string $pointer ): void {
		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'      => fn( $v, $p ) => $this->format( $v, $p ),
				'key'         => fn( $v, $p ) => $this->key( $v, $p ),
				'from'        => fn( $v, $p ) => $this->key( $v, $p ),
				'to'          => fn( $v, $p ) => $this->key( $v, $p ),
				'cardinality' => fn( $v, $p ) => $this->enum( $v, $p, array( 'one_to_many', 'many_to_many' ) ),
				'storage'     => fn( $v, $p ) => $this->storage( $v, $p ),
				'label'       => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'required'    => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
			),
			array( 'format', 'key', 'from', 'to', 'cardinality' )
		);

		if ( $is_object && is_string( $data['from'] ?? null ) && ( $data['to'] ?? null ) === $data['from'] ) {
			$this->add( 'self_relationship', $pointer . '/to', __( 'A relationship must link two different content types.', 'pegwork' ) );
		}
	}

	/**
	 * Checks a relationship's `storage`. `table` is reserved for Phase 5 and rejected until then,
	 * so nothing registers as a silent no-op.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function storage( mixed $value, string $pointer ): void {
		if ( 'table' === $value ) {
			$this->add( 'storage_not_supported', $pointer, __( 'Table storage is not supported yet; use "meta".', 'pegwork' ) );
			return;
		}
		$this->enum( $value, $pointer, array( 'meta' ) );
	}

	/**
	 * Validates a taxonomy usage entry on a content type.
	 *
	 * @param mixed  $usage   Decoded entry.
	 * @param string $pointer JSON pointer of the entry.
	 */
	private function taxonomy_usage( mixed $usage, string $pointer ): void {
		$this->props(
			$usage,
			$pointer,
			array(
				'key'      => fn( $v, $p ) => $this->key( $v, $p ),
				'select'   => fn( $v, $p ) => $this->enum( $v, $p, array( 'single', 'multi' ) ),
				'required' => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
			),
			array( 'key' )
		);
	}

	/**
	 * Validates a `labels` object.
	 *
	 * @param mixed  $labels  Decoded labels.
	 * @param string $pointer JSON pointer of the labels.
	 */
	private function labels( mixed $labels, string $pointer ): void {
		$this->props(
			$labels,
			$pointer,
			array(
				'singular' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'plural'   => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
			),
			array( 'singular', 'plural' )
		);
	}

	/**
	 * Validates a select option.
	 *
	 * @param mixed  $option  Decoded option.
	 * @param string $pointer JSON pointer of the option.
	 */
	private function option( mixed $option, string $pointer ): void {
		$this->props(
			$option,
			$pointer,
			array(
				'value' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
				'label' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
			),
			array( 'value', 'label' )
		);
	}

	/**
	 * Validates a `required_when` or `show_when` condition.
	 *
	 * @param mixed  $condition Decoded condition.
	 * @param string $pointer   JSON pointer of the condition.
	 */
	private function condition( mixed $condition, string $pointer ): void {
		$this->props(
			$condition,
			$pointer,
			array(
				'field'  => fn( $v, $p ) => $this->key( $v, $p ),
				'equals' => fn( $v, $p ) => $this->type( $v, $p, 'scalar' ),
			),
			array( 'field', 'equals' )
		);
	}

	/**
	 * Validates a `validate` object.
	 *
	 * @param mixed  $rules   Decoded rules.
	 * @param string $pointer JSON pointer of the rules.
	 */
	private function rules( mixed $rules, string $pointer ): void {
		$count  = fn( $v, $p ) => $this->type( $v, $p, 'count' );
		$number = fn( $v, $p ) => $this->type( $v, $p, 'number' );

		$this->props(
			$rules,
			$pointer,
			array(
				'min_length' => $count,
				'max_length' => $count,
				'min_words'  => $count,
				'max_words'  => $count,
				'min'        => $number,
				'max'        => $number,
				'pattern'    => fn( $v, $p ) => $this->pattern( $v, $p ),
			)
		);
	}

	/**
	 * Validates a seed term.
	 *
	 * @param mixed  $term    Decoded term.
	 * @param string $pointer JSON pointer of the term.
	 */
	private function term( mixed $term, string $pointer ): void {
		$this->props(
			$term,
			$pointer,
			array(
				'slug' => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::SLUG_PATTERN, $v ) ) {
						$this->add( 'invalid_slug', $p, __( 'Term slugs use lowercase letters, digits and single hyphens.', 'pegwork' ) );
					}
				},
				'name' => fn( $v, $p ) => $this->type( $v, $p, 'string' ),
			),
			array( 'slug', 'name' )
		);
	}

	/**
	 * Checks that a value is an object with the required properties and no unknown ones,
	 * then runs the check for each present property.
	 *
	 * @param mixed                   $value    Value to check.
	 * @param string                  $pointer  JSON pointer of the value.
	 * @param array<string, callable> $checks   Allowed properties and their checks, called with (value, pointer).
	 * @param string[]                $required Required property names.
	 * @return bool Whether the value is an object.
	 */
	private function props( mixed $value, string $pointer, array $checks, array $required = array() ): bool {
		if ( ! $this->type( $value, $pointer, 'object' ) ) {
			return false;
		}

		foreach ( $required as $name ) {
			if ( ! array_key_exists( $name, $value ) ) {
				/* translators: %s: property name. */
				$this->add( 'missing_property', $pointer, sprintf( __( 'Missing required property "%s".', 'pegwork' ), $name ) );
			}
		}

		foreach ( $value as $name => $child ) {
			$child_pointer = self::pointer( $pointer, $name );
			if ( ! isset( $checks[ $name ] ) ) {
				/* translators: %s: property name. */
				$this->add( 'unknown_property', $child_pointer, sprintf( __( 'Unknown property "%s".', 'pegwork' ), $name ) );
				continue;
			}
			$checks[ $name ]( $child, $child_pointer );
		}

		return true;
	}

	/**
	 * Checks a basic JSON type.
	 *
	 * An empty PHP array counts as both an empty object and an empty list, since decoding to
	 * arrays cannot tell `{}` from `[]`.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 * @param string $type    `object`, `list`, `string` (non-empty), `boolean`, `count` (integer >= 0), `number` or `scalar`.
	 * @return bool Whether the value has the type.
	 */
	private function type( mixed $value, string $pointer, string $type ): bool {
		[ $ok, $expected ] = match ( $type ) {
			'object'  => array( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ), __( 'an object', 'pegwork' ) ),
			'list'    => array( is_array( $value ) && array_is_list( $value ), __( 'a list', 'pegwork' ) ),
			'string'  => array( is_string( $value ) && '' !== $value, __( 'a non-empty string', 'pegwork' ) ),
			'boolean' => array( is_bool( $value ), __( 'true or false', 'pegwork' ) ),
			'count'   => array( is_int( $value ) && $value >= 0, __( 'a non-negative integer', 'pegwork' ) ),
			'number'  => array( is_int( $value ) || is_float( $value ), __( 'a number', 'pegwork' ) ),
			'scalar'  => array( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ), __( 'a string, number or boolean', 'pegwork' ) ),
		};

		if ( ! $ok ) {
			/* translators: %s: expected type, such as "a non-empty string". */
			$this->add( 'invalid_type', $pointer, sprintf( __( 'Expected %s.', 'pegwork' ), $expected ) );
		}

		return $ok;
	}

	/**
	 * Checks that a value is one of a fixed set.
	 *
	 * @param mixed    $value   Value to check.
	 * @param string   $pointer JSON pointer of the value.
	 * @param string[] $allowed Allowed values.
	 */
	private function enum( mixed $value, string $pointer, array $allowed ): void {
		if ( ! in_array( $value, $allowed, true ) ) {
			/* translators: %s: comma-separated allowed values. */
			$this->add( 'invalid_value', $pointer, sprintf( __( 'Expected one of: %s.', 'pegwork' ), implode( ', ', $allowed ) ) );
		}
	}

	/**
	 * Checks the `format` property.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function format( mixed $value, string $pointer ): void {
		if ( ! is_int( $value ) ) {
			$this->type( $value, $pointer, 'count' );
		} elseif ( self::FORMAT !== $value ) {
			/* translators: 1: format found, 2: supported format. */
			$this->add( 'unsupported_format', $pointer, sprintf( __( 'Format %1$d is not supported; expected %2$d.', 'pegwork' ), $value, self::FORMAT ) );
		}
	}

	/**
	 * Checks a `wp.meta_key` override.
	 *
	 * @param mixed  $value   Decoded value.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function meta_key( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'string' ) && ! preg_match( self::META_KEY_PATTERN, $value ) ) {
			$this->add( 'invalid_meta_key', $pointer, __( 'Meta keys use letters, digits and underscores, optionally after one leading underscore.', 'pegwork' ) );
		}
	}

	/**
	 * Checks a key or reference against the key pattern.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 * @return bool Whether the value is a well-formed key.
	 */
	private function key( mixed $value, string $pointer ): bool {
		if ( ! $this->type( $value, $pointer, 'string' ) ) {
			return false;
		}
		if ( ! preg_match( self::KEY_PATTERN, $value ) ) {
			$this->add( 'invalid_key', $pointer, __( 'Keys use lowercase letters, digits and underscores, and start with a letter.', 'pegwork' ) );
			return false;
		}

		return true;
	}

	/**
	 * Checks a content type or taxonomy key: pattern, length and reserved terms.
	 *
	 * @param mixed  $value      Value to check.
	 * @param string $pointer    JSON pointer of the value.
	 * @param int    $max_length Maximum length.
	 */
	private function object_key( mixed $value, string $pointer, int $max_length ): void {
		if ( ! $this->key( $value, $pointer ) ) {
			return;
		}
		if ( strlen( $value ) > $max_length ) {
			/* translators: %d: maximum key length. */
			$this->add( 'key_too_long', $pointer, sprintf( __( 'Key is longer than %d characters.', 'pegwork' ), $max_length ) );
		}
		if ( in_array( $value, self::RESERVED_KEYS, true ) || str_starts_with( $value, 'wp_' ) ) {
			/* translators: %s: key. */
			$this->add( 'reserved_key', $pointer, sprintf( __( '"%s" is reserved by WordPress.', 'pegwork' ), $value ) );
		}
	}

	/**
	 * Checks a semver version string.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function version( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'string' ) && ! preg_match( self::SEMVER_PATTERN, $value ) ) {
			$this->add( 'invalid_version', $pointer, __( 'Version must be semver, such as 1.2.0.', 'pegwork' ) );
		}
	}

	/**
	 * Checks a regular expression string.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function pattern( mixed $value, string $pointer ): void {
		if ( ! $this->type( $value, $pointer, 'string' ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid pattern is reported as a schema error, not a PHP warning.
		if ( false === @preg_match( '#' . str_replace( '#', '\#', $value ) . '#u', '' ) ) {
			$this->add( 'invalid_pattern', $pointer, __( 'Pattern is not a valid regular expression.', 'pegwork' ) );
		}
	}

	/**
	 * Checks an object whose values are all non-empty strings.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function map_of_strings( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'object' ) ) {
			foreach ( $value as $name => $child ) {
				$this->type( $child, self::pointer( $pointer, $name ), 'string' );
			}
		}
	}

	/**
	 * Checks a list and each of its items.
	 *
	 * @param mixed    $value   Value to check.
	 * @param string   $pointer JSON pointer of the value.
	 * @param callable $item    Check for each item, called with (item, pointer).
	 */
	private function list_of( mixed $value, string $pointer, callable $item ): void {
		if ( $this->type( $value, $pointer, 'list' ) ) {
			foreach ( $value as $index => $child ) {
				$item( $child, $pointer . '/' . $index );
			}
		}
	}

	/**
	 * Reports list items whose property repeats an earlier item's value.
	 *
	 * @param mixed  $items    List to check; anything else is ignored.
	 * @param string $pointer  JSON pointer of the list.
	 * @param string $property Property that must be unique.
	 */
	private function unique( mixed $items, string $pointer, string $property ): void {
		if ( ! is_array( $items ) || ! array_is_list( $items ) ) {
			return;
		}
		$seen = array();
		foreach ( $items as $index => $item ) {
			$value = is_array( $item ) ? ( $item[ $property ] ?? null ) : null;
			if ( ! is_string( $value ) ) {
				continue;
			}
			if ( isset( $seen[ $value ] ) ) {
				/* translators: %s: duplicated value. */
				$this->add( 'duplicate_key', self::pointer( $pointer . '/' . $index, $property ), sprintf( __( 'Duplicate "%s".', 'pegwork' ), $value ) );
			}
			$seen[ $value ] = true;
		}
	}

	/**
	 * Reports objects anywhere in the value and property names that look like callables.
	 *
	 * @param mixed  $value   Value to scan.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function check_callables( mixed $value, string $pointer ): void {
		if ( is_object( $value ) ) {
			$this->add( 'callable_not_allowed', $pointer, __( 'PHP objects and callables are not allowed in a schema.', 'pegwork' ) );
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $name => $child ) {
			$child_pointer = self::pointer( $pointer, $name );
			if ( is_string( $name ) && preg_match( self::CALLABLE_PATTERN, $name ) ) {
				/* translators: %s: property name. */
				$this->add( 'callable_not_allowed', $child_pointer, sprintf( __( '"%s" looks like a PHP callable; callables are not allowed in a schema.', 'pegwork' ), $name ) );
			}
			$this->check_callables( $child, $child_pointer );
		}
	}

	/**
	 * Whether a default value suits the field type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Default value.
	 */
	private static function default_matches( string $type, mixed $value ): bool {
		return match ( $type ) {
			self::POST_TYPE_FIELD, self::FILE_FIELD => false, // Post IDs and uploads have no portable default.
			'boolean' => is_bool( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'date'    => is_string( $value ) && 1 === preg_match( self::DATE_PATTERN, $value ),
			default   => is_string( $value ),
		};
	}

	/**
	 * Records an error.
	 *
	 * @param string $code    Machine code.
	 * @param string $pointer JSON pointer.
	 * @param string $message Readable message.
	 */
	private function add( string $code, string $pointer, string $message ): void {
		$this->errors[] = new ValidationError( $code, $message, $this->file, $pointer );
	}
}
