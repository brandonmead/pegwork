<?php
/**
 * Validates records against the registry before REST saves them.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use Pegwork\Api\Registry;
use WP_Error;
use WP_REST_Request;

/**
 * Enforces required, required_when, validate rules, taxonomy usage and relationship links on records.
 *
 * Completeness rules apply only when the target status is publish, pending or future, so drafts
 * may be incomplete. Single-select term counts, relationship target types and `one_to_many`
 * limits apply to every save.
 */
final class RecordValidator {

	/**
	 * Statuses that require a complete record.
	 */
	public const COMPLETE_STATUSES = array( 'publish', 'pending', 'future' );

	/**
	 * Whitespace as JavaScript's `\s` defines it, so word counts match the editor panel.
	 */
	private const WHITESPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

	/**
	 * The registry the hooks validate against.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $registry = null;

	/**
	 * Hooks rest_pre_insert_{post_type} for every content type.
	 *
	 * @param Registry $registry Effective registry.
	 */
	public static function register( Registry $registry ): void {
		self::$registry = $registry;
		foreach ( array_keys( $registry->content_types() ) as $type ) {
			if ( ! has_filter( 'rest_pre_insert_' . $type, array( self::class, 'pre_insert' ) ) ) {
				add_filter( 'rest_pre_insert_' . $type, array( self::class, 'pre_insert' ), 10, 2 );
			}
		}
	}

	/**
	 * Merges stored values with the request and validates. Hooked to rest_pre_insert_{post_type}.
	 *
	 * @param \stdClass|WP_Error $prepared Post prepared for the database.
	 * @param WP_REST_Request    $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public static function pre_insert( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! $request instanceof WP_REST_Request || str_contains( $request->get_route(), '/autosaves' ) ) {
			return $prepared;
		}

		$type = $prepared->post_type ?? null;
		$type = is_string( $type ) ? $type : (string) get_post_type( (int) ( $prepared->ID ?? 0 ) );
		if ( null === self::$registry || null === self::$registry->content_type( $type ) ) {
			return $prepared;
		}

		$existing = empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );
		$status   = $prepared->post_status ?? ( $existing ? $existing->post_status : 'draft' );
		$values   = self::merge( $type, $prepared, $request, $existing );

		$result = self::validate( self::$registry, $type, $values, in_array( $status, self::COMPLETE_STATUSES, true ), (int) ( $prepared->ID ?? 0 ) );

		return true === $result ? $prepared : $result;
	}

	/**
	 * Validates field values and term lists for a content type.
	 *
	 * @param Registry             $registry Effective registry.
	 * @param string               $type     Content type key.
	 * @param array<string, mixed> $values   Field values by field key; term ID or slug lists by taxonomy key;
	 *                                       linked ID lists by relationship key (missing means no links).
	 * @param bool                 $complete Whether completeness rules apply (target status publish, pending or future).
	 * @param int                  $post_id  ID of the record being saved, 0 for a new one; its own links do not count
	 *                                       against `one_to_many` limits.
	 * @return true|WP_Error WP_Error with status 400 and per-field messages in data `fields`.
	 */
	public static function validate( Registry $registry, string $type, array $values, bool $complete = true, int $post_id = 0 ): bool|WP_Error {
		$content_type = $registry->content_type( $type );
		if ( null === $content_type ) {
			/* translators: %s: content type key. */
			return new WP_Error( 'pegwork_unknown_type', sprintf( __( 'Unknown content type "%s".', 'pegwork' ), $type ), array( 'status' => 400 ) );
		}

		$fields = $content_type['fields'];
		$values = array_merge( $values, self::cast_all( $fields, $values ) );
		$errors = array();

		foreach ( $fields as $field ) {
			$message = self::check_field( $field, $values, $complete );
			if ( null !== $message ) {
				$errors[ $field['key'] ] = $message;
			}
		}

		$taxonomies = $registry->taxonomies();
		foreach ( $content_type['taxonomies'] as $usage ) {
			$terms   = $values[ $usage['key'] ] ?? array();
			$count   = is_array( $terms ) ? count( array_filter( $terms ) ) : 0;
			$labels  = $taxonomies[ $usage['key'] ]['labels'] ?? array( 'singular' => $usage['key'] );
			$message = null;
			if ( 'single' === $usage['select'] && $count > 1 ) {
				/* translators: %s: taxonomy label. */
				$message = sprintf( __( '%s allows only one term.', 'pegwork' ), $labels['singular'] );
			} elseif ( $complete && $usage['required'] && 0 === $count ) {
				/* translators: %s: taxonomy label. */
				$message = sprintf( __( '%s is required.', 'pegwork' ), $labels['singular'] );
			}
			if ( null !== $message ) {
				$errors[ $usage['key'] ] = $message;
			}
		}

		foreach ( array_keys( Relationships::from( $registry, $type ) ) as $key ) {
			$message = self::check_relationship( $registry, $key, $values[ $key ] ?? array(), $complete, $post_id );
			if ( null !== $message ) {
				$errors[ $key ] = $message;
			}
		}

		if ( array() === $errors ) {
			return true;
		}

		return new WP_Error(
			'pegwork_invalid_record',
			/* translators: %s: per-field error messages. */
			sprintf( __( 'This record cannot be saved: %s', 'pegwork' ), implode( ' ', $errors ) ),
			array(
				'status' => 400,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Checks the links a "from" record would hold for one relationship. Returns the error message, or null when they pass.
	 *
	 * Every save: each ID is an existing record (trashed counts) of the "to" type, and for
	 * `one_to_many` no ID is already linked from another "from" record. Complete saves: a
	 * `required` relationship has at least one link.
	 *
	 * @param Registry $registry Effective registry.
	 * @param string   $key      Relationship key.
	 * @param mixed    $value    Linked IDs: a list, one ID, or null/empty for none.
	 * @param bool     $complete Whether completeness rules apply.
	 * @param int      $post_id  ID of the "from" record being saved, 0 for a new one.
	 */
	public static function check_relationship( Registry $registry, string $key, mixed $value, bool $complete, int $post_id = 0 ): ?string {
		$relationship = $registry->relationships()[ $key ] ?? null;
		if ( null === $relationship ) {
			return null;
		}

		$to_label = $registry->content_type( $relationship['to'] )['labels']['singular'] ?? $relationship['to'];
		$items    = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
		$linked   = array();
		foreach ( $items as $item ) {
			$id   = is_int( $item ) || ( is_string( $item ) && ctype_digit( $item ) ) ? (int) $item : 0;
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post || $relationship['to'] !== $post->post_type ) {
				/* translators: 1: record ID as given, 2: singular label of the linked content type. */
				return sprintf( __( 'Record %1$s is not a %2$s.', 'pegwork' ), is_scalar( $item ) ? (string) $item : '?', $to_label );
			}
			$linked[ $id ] = $post;
		}

		if ( 'one_to_many' === $relationship['cardinality'] ) {
			$from_label = $registry->content_type( $relationship['from'] )['labels']['singular'] ?? $relationship['from'];
			foreach ( $linked as $id => $post ) {
				$other = Relationships::linking_ids( $relationship, $key, $id, $post_id, 1 );
				if ( $other ) {
					return sprintf(
						/* translators: 1: linked type label, 2: linked record title, 3: linking type label, 4: linking record title. */
						__( '%1$s "%2$s" is already linked from %3$s "%4$s".', 'pegwork' ),
						$to_label,
						self::title( $post ),
						$from_label,
						self::title( get_post( $other[0] ) )
					);
				}
			}
		}

		if ( $complete && $relationship['required'] && array() === $linked ) {
			/* translators: %s: singular label of the linked content type. */
			return sprintf( __( 'At least one %s is required.', 'pegwork' ), $to_label );
		}

		return null;
	}

	/**
	 * Title of a record for an error message: its title when the current user may read it, else its ID.
	 *
	 * @param \WP_Post|null $post Record.
	 */
	private static function title( ?\WP_Post $post ): string {
		if ( null === $post ) {
			return '';
		}
		if ( '' === $post->post_title || ! current_user_can( 'read_post', $post->ID ) ) {
			/* translators: %d: post ID. */
			return sprintf( __( '#%d', 'pegwork' ), $post->ID );
		}

		return $post->post_title;
	}

	/**
	 * Counts words the way the editor panel does: block comments and tags become spaces, `&nbsp;`
	 * becomes a space, then the trimmed text is split on whitespace.
	 *
	 * @param mixed $content Text or post content.
	 */
	public static function count_words( mixed $content ): int {
		$text = self::plain_text( $content );

		return '' === $text ? 0 : count( (array) preg_split( '/' . self::WHITESPACE . '+/u', $text ) );
	}

	/**
	 * Checks one field. Returns its error message, or null when it passes.
	 *
	 * @param array<string, mixed> $field    Normalized field.
	 * @param array<string, mixed> $values   Cast values by field key.
	 * @param bool                 $complete Whether completeness rules apply.
	 */
	private static function check_field( array $field, array $values, bool $complete ): ?string {
		if ( ! $complete ) {
			return null;
		}

		$label = $field['label'];
		$value = $values[ $field['key'] ] ?? null;
		$empty = self::is_empty( $field, $value );

		$condition = $field['required_when'] ?? null;
		$required  = $field['required'] || ( is_array( $condition ) && ( $values[ $condition['field'] ] ?? null ) === $condition['equals'] );
		if ( $empty ) {
			/* translators: %s: field label. */
			return $required ? sprintf( __( '%s is required.', 'pegwork' ), $label ) : null;
		}

		$rules = $field['validate'] ?? array();
		$text  = is_string( $value ) ? ( 'rich_text' === $field['type'] ? self::plain_text( $value ) : $value ) : '';

		if ( isset( $rules['min_length'] ) && mb_strlen( $text ) < $rules['min_length'] ) {
			/* translators: 1: field label, 2: character count. */
			return sprintf( __( '%1$s must be at least %2$d characters.', 'pegwork' ), $label, $rules['min_length'] );
		}
		if ( isset( $rules['max_length'] ) && mb_strlen( $text ) > $rules['max_length'] ) {
			/* translators: 1: field label, 2: character count. */
			return sprintf( __( '%1$s must be at most %2$d characters.', 'pegwork' ), $label, $rules['max_length'] );
		}
		if ( isset( $rules['min_words'] ) || isset( $rules['max_words'] ) ) {
			$words = self::count_words( $value );
			if ( isset( $rules['min_words'] ) && $words < $rules['min_words'] ) {
				/* translators: 1: field label, 2: minimum words, 3: current words. */
				return sprintf( __( '%1$s must be at least %2$d words (it has %3$d).', 'pegwork' ), $label, $rules['min_words'], $words );
			}
			if ( isset( $rules['max_words'] ) && $words > $rules['max_words'] ) {
				/* translators: 1: field label, 2: maximum words, 3: current words. */
				return sprintf( __( '%1$s must be at most %2$d words (it has %3$d).', 'pegwork' ), $label, $rules['max_words'], $words );
			}
		}
		if ( isset( $rules['min'] ) && is_numeric( $value ) && $value < $rules['min'] ) {
			/* translators: 1: field label, 2: minimum value. */
			return sprintf( __( '%1$s must be at least %2$s.', 'pegwork' ), $label, $rules['min'] );
		}
		if ( isset( $rules['max'] ) && is_numeric( $value ) && $value > $rules['max'] ) {
			/* translators: 1: field label, 2: maximum value. */
			return sprintf( __( '%1$s must be at most %2$s.', 'pegwork' ), $label, $rules['max'] );
		}
		if ( isset( $rules['pattern'] ) && ! preg_match( '#' . str_replace( '#', '\#', $rules['pattern'] ) . '#u', $text ) ) {
			/* translators: %s: field label. */
			return sprintf( __( '%s is not in the expected format.', 'pegwork' ), $label );
		}

		return null;
	}

	/**
	 * Whether a cast value counts as empty. False is a value for booleans.
	 *
	 * @param array<string, mixed> $field Normalized field.
	 * @param mixed                $value Cast value.
	 */
	private static function is_empty( array $field, mixed $value ): bool {
		if ( null === $value || array() === $value ) {
			return true;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}

		return 'rich_text' === $field['type'] ? '' === self::plain_text( $value ) : '' === trim( $value );
	}

	/**
	 * Casts every field value to its type, so stored strings compare like request values.
	 *
	 * @param array<int, array<string, mixed>> $fields Normalized fields.
	 * @param array<string, mixed>             $values Raw values by field key.
	 * @return array<string, mixed>
	 */
	private static function cast_all( array $fields, array $values ): array {
		$cast = array();
		foreach ( $fields as $field ) {
			$value = $values[ $field['key'] ] ?? null;
			if ( null === $value || '' === $value ) {
				$cast[ $field['key'] ] = null;
				continue;
			}
			$cast[ $field['key'] ] = match ( $field['type'] ) {
				'boolean' => rest_sanitize_boolean( $value ),
				'number'  => is_numeric( $value ) ? 0 + $value : $value,
				default   => $value,
			};
		}

		return $cast;
	}

	/**
	 * Text with block comments, tags and non-breaking space entities replaced by spaces, trimmed.
	 *
	 * @param mixed $content Text or post content.
	 */
	private static function plain_text( mixed $content ): string {
		if ( ! is_string( $content ) ) {
			return '';
		}
		$text = (string) preg_replace( array( '/<!--.*?-->/s', '/<[^>]*>/', '/&nbsp;|&#160;/i' ), ' ', $content );

		return (string) preg_replace( '/^' . self::WHITESPACE . '+|' . self::WHITESPACE . '+$/u', '', $text );
	}

	/**
	 * Field values and term lists for a REST save: request values over stored ones.
	 *
	 * @param string          $type     Content type key.
	 * @param \stdClass       $prepared Post prepared for the database.
	 * @param WP_REST_Request $request  Request.
	 * @param \WP_Post|null   $existing Stored post on update.
	 * @return array<string, mixed>
	 */
	private static function merge( string $type, \stdClass $prepared, WP_REST_Request $request, ?\WP_Post $existing ): array {
		$meta   = $request->get_param( 'meta' );
		$meta   = is_array( $meta ) ? $meta : array();
		$values = array();

		foreach ( self::$registry->fields( $type ) as $key => $field ) {
			$storage = $field['wp']['storage'] ?? null;
			if ( null !== $storage ) {
				$values[ $key ] = $prepared->$storage ?? ( $existing ? $existing->$storage : '' );
				continue;
			}

			$meta_key = Meta::key( $type, $field );
			if ( array_key_exists( $meta_key, $meta ) ) {
				$values[ $key ] = $meta[ $meta_key ];
			} elseif ( $existing && metadata_exists( 'post', $existing->ID, $meta_key ) ) {
				$values[ $key ] = get_post_meta( $existing->ID, $meta_key, true );
			} else {
				$values[ $key ] = $field['default'] ?? null;
			}
		}

		foreach ( self::$registry->content_type( $type )['taxonomies'] as $usage ) {
			$taxonomy = get_taxonomy( $usage['key'] );
			$base     = $taxonomy && is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $usage['key'];
			$terms    = $request->get_param( $base );
			if ( null === $terms && $existing ) {
				$terms = wp_get_object_terms( $existing->ID, $usage['key'], array( 'fields' => 'ids' ) );
			}
			$values[ $usage['key'] ] = is_array( $terms ) ? $terms : ( null === $terms || is_wp_error( $terms ) ? array() : array( $terms ) );
		}

		foreach ( array_keys( Relationships::from( self::$registry, $type ) ) as $key ) {
			$meta_key       = Relationships::meta_key( $key );
			$values[ $key ] = array_key_exists( $meta_key, $meta )
				? $meta[ $meta_key ]
				: ( $existing ? Relationships::stored_ids( $existing->ID, $key ) : array() );
		}

		return $values;
	}
}
