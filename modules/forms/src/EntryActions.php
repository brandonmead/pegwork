<?php
/**
 * Entry actions: spam, not spam, delete, create post anyway.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api\Records;
use WP_Error;

/**
 * The operations behind the entries screen, and its one `admin-post.php` handler (capability, nonce,
 * operation, notice, redirect). Delete arrives only from the confirmation screen.
 */
final class EntryActions {

	/**
	 * `admin-post.php` action.
	 */
	public const ACTION = 'pegwork_entries';

	/**
	 * The list table's `plural`; WP_List_Table prints a `bulk-{plural}` nonce in the list form.
	 */
	public const PLURAL = 'pegwork_entries';

	/**
	 * Nonce action of every entry action form (the list table's bulk nonce).
	 */
	public const NONCE = 'bulk-' . self::PLURAL;

	/**
	 * Operations the handler accepts.
	 */
	public const OPS = array( 'spam', 'not_spam', 'delete', 'create_post' );

	/**
	 * Statuses that "Create post anyway" accepts (spam must be marked not spam first).
	 */
	private const POSTABLE = array( 'received', 'unconfirmed', 'failed' );

	/**
	 * Registers the handler.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) ) ) {
			add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		}
	}

	/**
	 * Handles a POST from the list, the entry screen or the delete confirmation. Hooked to admin_post_pegwork_entries.
	 *
	 * Bulk delete without `confirmed` redirects to the confirmation screen instead of deleting.
	 */
	public static function handle(): void {
		EntriesAdmin::authorize();
		EntriesAdmin::verify_nonce( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$op        = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$ids       = isset( $_POST['entry'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['entry'] ) ) : array();
		$confirmed = ! empty( $_POST['confirmed'] );
		$single    = isset( $_POST['return_entry'] ) ? absint( $_POST['return_entry'] ) : 0;
		$back      = EntriesAdmin::list_args_from( $_POST );
		// phpcs:enable
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( ! in_array( $op, self::OPS, true ) ) {
			// No action: pressing Enter in the page number field submits the list form, so just page.
			if ( array() !== $ids ) {
				EntriesAdmin::notice( 'error', __( 'Choose an action to apply.', 'pegwork' ) );
			}
			self::redirect( EntriesAdmin::url( $back ) );
		}
		if ( array() === $ids ) {
			EntriesAdmin::notice( 'error', __( 'Select at least one entry.', 'pegwork' ) );
			self::redirect( EntriesAdmin::url( $back ) );
		}

		switch ( $op ) {
			case 'spam':
				$count = self::mark_spam( $ids );
				/* translators: %d: number of entries. */
				EntriesAdmin::notice( 'success', sprintf( _n( '%d entry marked as spam.', '%d entries marked as spam.', $count, 'pegwork' ), $count ) );
				break;

			case 'not_spam':
				$count = self::mark_not_spam( $ids );
				/* translators: %d: number of entries. */
				EntriesAdmin::notice( 'success', sprintf( _n( '%d entry marked as not spam.', '%d entries marked as not spam.', $count, 'pegwork' ), $count ) );
				break;

			case 'delete':
				if ( ! $confirmed ) {
					self::redirect(
						EntriesAdmin::url(
							$back + array(
								'delete'       => implode( ',', $ids ),
								'return_entry' => $single,
							)
						)
					);
				}
				$count = Entries::delete( $ids );
				/* translators: %d: number of entries. */
				EntriesAdmin::notice( 'success', sprintf( _n( '%d entry permanently deleted.', '%d entries permanently deleted.', $count, 'pegwork' ), $count ) );
				$single = 0;
				break;

			case 'create_post':
				$result = self::create_post( $ids[0] );
				if ( $result instanceof WP_Error ) {
					EntriesAdmin::notice( 'error', $result->get_error_message() );
				} else {
					EntriesAdmin::notice(
						'success',
						/* translators: %s: post title. */
						sprintf( __( 'Draft "%s" created from the entry.', 'pegwork' ), wp_strip_all_tags( get_the_title( $result ) ) ),
						get_edit_post_link( $result, 'raw' ) ?? ''
					);
				}
				break;
		}

		self::redirect( EntriesAdmin::url( $single > 0 ? array( 'entry' => $single ) : $back ) );
	}

	/**
	 * Marks entries as spam (any status but processing, which a confirmation holds).
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Entries changed.
	 */
	public static function mark_spam( array $ids ): int {
		return Entries::change_status( $ids, array( 'received', 'unconfirmed', 'failed' ), 'spam' );
	}

	/**
	 * Marks spam entries as not spam: they become `received` (never mailed, no record; "Create post
	 * anyway" can make one).
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Entries changed.
	 */
	public static function mark_not_spam( array $ids ): int {
		return Entries::change_status( $ids, array( 'spam' ), 'received' );
	}

	/**
	 * Content type "Create post anyway" makes a Draft of, or null when the entry's form has none.
	 *
	 * The form's `target.content_type` for `create` forms; entry-only forms (Share a story) get one from
	 * the `pegwork_forms_draft_type` filter. Must be a registry content type.
	 *
	 * @param array<string, mixed>|null $form Canonical form, or null when deleted.
	 */
	public static function draft_type( ?array $form ): ?string {
		if ( null === $form ) {
			return null;
		}
		$target = $form['target'] ?? null;
		$type   = is_array( $target ) && 'update' !== ( $target['action'] ?? 'create' ) ? (string) ( $target['content_type'] ?? '' ) : '';

		/**
		 * Filters the content type that "Create post anyway" makes a Draft of for a form's entries.
		 *
		 * @param string|null          $type Content type key, or null for none. Default: `target.content_type` of create forms.
		 * @param array<string, mixed> $form Canonical form.
		 */
		$type = apply_filters( 'pegwork_forms_draft_type', '' !== $type ? $type : null, $form );

		return is_string( $type ) && null !== pegwork_registry()->content_type( $type ) ? $type : null;
	}

	/**
	 * Whether "Create post anyway" applies to an entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 */
	public static function can_create_post( array $entry ): bool {
		return null === $entry['post_id']
			&& in_array( $entry['status'], self::POSTABLE, true )
			&& null !== self::draft_type( pegwork_registry()->form( (string) $entry['form_key'] ) );
	}

	/**
	 * Creates a Draft of the form's content type from an entry's values and links it.
	 *
	 * Values go through Pegwork\Api\Records (sanitized and type-checked; drafts skip completeness rules).
	 * Keys the type does not have are left out, and so is any value the type rejects, so an admin always
	 * gets a draft to finish. The entry is claimed first (status `processing`), so a second click or a
	 * submitter confirming at the same moment cannot create a second post; it ends `received`.
	 *
	 * @param int $id Entry ID.
	 * @return int|WP_Error Post ID.
	 */
	public static function create_post( int $id ): int|WP_Error {
		$entry = Entries::get( $id );
		if ( null === $entry ) {
			return new WP_Error( 'pegwork_entry_not_found', __( 'That entry no longer exists.', 'pegwork' ) );
		}
		if ( ! self::can_create_post( $entry ) ) {
			return new WP_Error( 'pegwork_entry_not_postable', __( 'A post cannot be created from this entry: it already has one, is spam, or its form has no content type.', 'pegwork' ) );
		}

		$type   = (string) self::draft_type( pegwork_registry()->form( (string) $entry['form_key'] ) );
		$object = get_post_type_object( $type );
		if ( null === $object || ! current_user_can( $object->cap->create_posts ) ) {
			return new WP_Error( 'pegwork_entry_cannot_create', __( 'You are not allowed to create posts of this type.', 'pegwork' ) );
		}

		$previous = (string) $entry['status'];
		if ( 1 !== Entries::change_status( array( $id ), array( $previous ), 'processing' ) ) {
			return new WP_Error( 'pegwork_entry_busy', __( 'This entry changed in the meantime. Reload and try again.', 'pegwork' ) );
		}

		$known  = array_merge( array_keys( pegwork_registry()->fields( $type ) ), array_column( pegwork_registry()->content_type( $type )['taxonomies'], 'key' ) );
		$values = array_intersect_key( (array) $entry['values'], array_flip( $known ) );

		$post_id = Records::create( $type, $values );
		if ( $post_id instanceof WP_Error && 'pegwork_invalid_record' === $post_id->get_error_code() ) {
			$rejected = array_keys( (array) ( $post_id->get_error_data()['fields'] ?? array() ) );
			$post_id  = Records::create( $type, array_diff_key( $values, array_flip( $rejected ) ) );
		}
		if ( $post_id instanceof WP_Error ) {
			Entries::change_status( array( $id ), array( 'processing' ), $previous );
			return $post_id;
		}

		$meta                 = (array) $entry['meta'];
		$meta['post_created'] = array(
			'by'   => get_current_user_id(),
			'at'   => gmdate( 'Y-m-d H:i:s' ),
			'from' => $previous,
		);
		Entries::update_meta( $id, $meta );
		Entries::link_post( $id, $post_id, 'received' );

		return $post_id;
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url Admin URL.
	 * @return never
	 */
	private static function redirect( string $url ): never {
		wp_safe_redirect( $url, 303 );
		exit;
	}
}
