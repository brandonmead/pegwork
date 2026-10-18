<?php
/**
 * Record mapping: bound form items to a record of the target content type.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Records;
use WP_Error;

/**
 * Builds record values from a submission's bound items, validates them with pegwork_validate_record()
 * (the content type's rules, defined once), and creates or updates the record through
 * Pegwork\Api\Records, which sanitizes, validates again and emits the record event.
 *
 * Type and status come only from the definition: `target.content_type`, and `target.status` for a
 * create (an update keeps the record's status). Only bound keys reach the record; unbound items stay on
 * the entry. A `post_content` field is written as `core/paragraph` blocks (blank lines split
 * paragraphs, text escaped). Writes run as the submitter (the entry's user, else anonymous), so the
 * author and content filters are the submitter's, never those of whoever confirms.
 */
final class RecordWriter {

	/**
	 * Record values by registry key from submitted values: field values, and term slug lists by taxonomy.
	 *
	 * @param array<string, mixed> $form   Canonical form with a target.
	 * @param array<string, mixed> $values Collected values by item key.
	 * @param bool                 $write  Whether to convert `post_content` text to paragraph blocks (for writing).
	 * @return array<string, mixed>
	 */
	public static function values( array $form, array $values, bool $write ): array {
		$type   = (string) Definition::target_type( $form );
		$fields = pegwork_registry()->fields( $type );
		$record = array();

		foreach ( Definition::items( $form ) as $item ) {
			$key = (string) $item['key'];
			if ( ! isset( $item['bind'] ) || ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $values[ $key ];

			if ( isset( $item['bind']['taxonomy'] ) ) {
				$slugs          = is_array( $value ) ? $value : ( is_string( $value ) && '' !== $value ? array( $value ) : array() );
				$record[ $key ] = array_values( array_map( 'strval', $slugs ) );
				continue;
			}

			$storage = $fields[ $key ]['wp']['storage'] ?? null;
			if ( $write && 'post_content' === $storage && is_string( $value ) ) {
				$value = self::paragraphs( $value );
			}
			$record[ $key ] = $value;
		}

		return $record;
	}

	/**
	 * Record validation errors by key (bound item keys, plus any other field of the type that fails).
	 *
	 * For an update, the record's stored values fill the keys the form does not send.
	 *
	 * @param array<string, mixed> $form      Canonical form with a target.
	 * @param array<string, mixed> $values    Collected values by item key.
	 * @param int                  $record_id Record being updated, 0 for a create.
	 * @return array<string, string>
	 */
	public static function errors( array $form, array $values, int $record_id = 0 ): array {
		$type    = (string) Definition::target_type( $form );
		$current = $record_id > 0 ? ( Records::get( $record_id )['values'] ?? array() ) : array();
		$result  = pegwork_validate_record( $type, array_merge( $current, self::values( $form, $values, false ) ), $record_id );
		if ( true === $result ) {
			return array();
		}
		$data = $result->get_error_data();

		return is_array( $data['fields'] ?? null ) ? array_map( 'strval', $data['fields'] ) : array( '' => $result->get_error_message() );
	}

	/**
	 * Creates (status from the definition, default pending) or updates (status kept) the target record.
	 *
	 * @param array<string, mixed> $form      Canonical form with a target.
	 * @param array<string, mixed> $values    Collected values by item key.
	 * @param int                  $user_id   Submitter (0 for anonymous); the write runs as this user.
	 * @param int                  $record_id Record to update, 0 to create.
	 * @return int|WP_Error Record ID.
	 */
	public static function write( array $form, array $values, int $user_id, int $record_id = 0 ): int|WP_Error {
		$record = self::values( $form, $values, true );

		return self::as_user(
			$user_id,
			function () use ( $form, $record, $record_id ) {
				if ( $record_id > 0 ) {
					$updated = Records::update( $record_id, $record );
					return is_wp_error( $updated ) ? $updated : $record_id;
				}
				$status = (string) ( $form['target']['status'] ?? 'pending' );
				return Records::create( (string) Definition::target_type( $form ), $record, $status );
			}
		);
	}

	/**
	 * Creates the record as a draft, so a submission that no longer validates is not lost. Keys the
	 * draft still rejects (an unknown term, a value of the wrong type) are dropped and creation is retried once.
	 *
	 * @param array<string, mixed> $form    Canonical form with a create target.
	 * @param array<string, mixed> $values  Collected values by item key.
	 * @param int                  $user_id Submitter (0 for anonymous).
	 * @return int|null Draft ID, or null when even the reduced draft fails.
	 */
	public static function draft( array $form, array $values, int $user_id ): ?int {
		$type   = (string) Definition::target_type( $form );
		$record = self::values( $form, $values, true );

		return self::as_user(
			$user_id,
			function () use ( $type, $record ) {
				$id = Records::create( $type, $record, 'draft' );
				if ( is_wp_error( $id ) ) {
					$rejected = $id->get_error_data()['fields'] ?? array();
					$id       = is_array( $rejected ) && array() !== $rejected ? Records::create( $type, array_diff_key( $record, $rejected ), 'draft' ) : $id;
				}
				return is_int( $id ) ? $id : null;
			}
		);
	}

	/**
	 * Whether the current user may update this record with this form: it exists, has the target type,
	 * and the user can edit it.
	 *
	 * @param array<string, mixed> $form      Canonical update form.
	 * @param int                  $record_id Record ID.
	 */
	public static function editable( array $form, int $record_id ): bool {
		$post = $record_id > 0 ? get_post( $record_id ) : null;

		return $post instanceof \WP_Post
			&& Definition::target_type( $form ) === $post->post_type
			&& 'trash' !== $post->post_status
			&& current_user_can( 'edit_post', $record_id );
	}

	/**
	 * Form text for an update form from the record: bound items only, booleans as '1' or '', a single
	 * taxonomy as its slug, a multi taxonomy as a slug list, `post_content` back to plain text.
	 *
	 * @param array<string, mixed> $form      Canonical update form.
	 * @param int                  $record_id Record ID (already checked with editable()).
	 * @return array<string, string|string[]>
	 */
	public static function prefill( array $form, int $record_id ): array {
		$record = Records::get( $record_id );
		if ( null === $record ) {
			return array();
		}
		$fields = pegwork_registry()->fields( (string) Definition::target_type( $form ) );
		$text   = array();

		foreach ( Definition::items( $form ) as $item ) {
			$key = (string) $item['key'];
			if ( ! isset( $item['bind'] ) || ! array_key_exists( $key, $record['values'] ) ) {
				continue;
			}
			$value = $record['values'][ $key ];
			if ( isset( $item['bind']['taxonomy'] ) ) {
				$slugs        = array_values( array_map( 'strval', (array) $value ) );
				$text[ $key ] = empty( $item['multiple'] ) ? ( $slugs[0] ?? '' ) : $slugs;
				continue;
			}
			if ( 'post_content' === ( $fields[ $key ]['wp']['storage'] ?? null ) ) {
				$value = self::plain_text( (string) $value );
			}
			$text[ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_scalar( $value ) ? (string) $value : '' );
		}

		return $text;
	}

	/**
	 * Block content as plain text: paragraphs separated by blank lines, `<br>` as line breaks, tags removed.
	 *
	 * @param string $content Post content.
	 */
	public static function plain_text( string $content ): string {
		$text = (string) preg_replace( array( '/<!--.*?-->/s', '/<br\s*\/?>/i', '/<\/p>/i' ), array( '', "\n", "\n\n" ), $content );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\n[ \t]*\n(?:[ \t]*\n)+/', "\n\n", $text ) );
	}

	/**
	 * Plain text as `core/paragraph` blocks: paragraphs split on blank lines, single line breaks kept
	 * as `<br>`, `<`, `>` and `&` escaped (existing entities are kept as they are).
	 *
	 * @param string $text Plain text.
	 */
	public static function paragraphs( string $text ): string {
		$text   = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$blocks = array();
		foreach ( preg_split( '/\n[ \t]*\n/', trim( $text ) ) as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			// No double encoding: the textarea sanitizer already turned a lone "<" into "&lt;".
			$escaped  = htmlspecialchars( $paragraph, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . str_replace( "\n", '<br>', $escaped ) . "</p>\n<!-- /wp:paragraph -->";
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Runs a callback as another user, then restores the current one.
	 *
	 * @param int      $user_id  User ID, 0 for anonymous.
	 * @param callable $callback Callback.
	 * @return mixed The callback's result.
	 */
	private static function as_user( int $user_id, callable $callback ): mixed {
		$previous = get_current_user_id();
		if ( $previous === $user_id ) {
			return $callback();
		}

		wp_set_current_user( $user_id );
		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}
}
