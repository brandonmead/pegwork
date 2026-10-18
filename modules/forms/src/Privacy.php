<?php
/**
 * Personal data exporter, eraser and privacy policy text.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Registers form entries with Tools > Export Personal Data and Erase Personal Data, matched by the
 * entry's `email` column (the confirm field, else the form's first email item). The eraser deletes the
 * entries and reports records created from them as retained: those are directory records, and editing
 * them is a person's decision. Adds suggested text to Settings > Privacy.
 */
final class Privacy {

	/**
	 * Exporter and eraser ID.
	 */
	public const ID = 'pegwork-forms';

	/**
	 * Entries per exporter or eraser page.
	 */
	public const PAGE_SIZE = 50;

	/**
	 * Hooks the exporter, eraser and policy text.
	 */
	public static function register(): void {
		if ( ! has_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) ) ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
			add_action( 'admin_init', array( self::class, 'policy' ) );
		}
	}

	/**
	 * Adds the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function exporters( $exporters ): array {
		$exporters             = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Pegwork form entries', 'pegwork' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function erasers( $erasers ): array {
		$erasers             = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Pegwork form entries', 'pegwork' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exports one page of an address's entries: form, entry ID, dates, status, linked post and every value.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$entries = Entries::by_email( (string) $email, self::PAGE_SIZE, ( max( 1, (int) $page ) - 1 ) * self::PAGE_SIZE );
		$data    = array();

		foreach ( $entries as $entry ) {
			$form  = pegwork_registry()->form( (string) $entry['form_key'] );
			$items = EntryFields::items( $form );
			$pairs = array(
				array(
					'name'  => __( 'Form', 'pegwork' ),
					'value' => EntryFields::form_label( (string) $entry['form_key'] ),
				),
				array(
					'name'  => __( 'Entry ID', 'pegwork' ),
					'value' => (string) $entry['id'],
				),
				array(
					'name'  => __( 'Submitted', 'pegwork' ),
					'value' => EntryFields::date( (string) $entry['created_at'] ),
				),
				array(
					'name'  => __( 'Status', 'pegwork' ),
					'value' => EntryFields::status_label( (string) $entry['status'] ),
				),
			);
			if ( ! empty( $entry['confirmed_at'] ) ) {
				$pairs[] = array(
					'name'  => __( 'Confirmed', 'pegwork' ),
					'value' => EntryFields::date( (string) $entry['confirmed_at'] ),
				);
			}
			if ( null !== $entry['post_id'] && 'publish' === get_post_status( $entry['post_id'] ) ) {
				$pairs[] = array(
					'name'  => __( 'Published record', 'pegwork' ),
					'value' => (string) get_permalink( $entry['post_id'] ),
				);
			}
			foreach ( EntryFields::columns( $form, array( $entry ) ) as $key => $label ) {
				if ( array_key_exists( $key, $entry['values'] ) ) {
					$pairs[] = array(
						'name'  => $label,
						'value' => EntryFields::text( $items[ $key ] ?? null, $entry['values'][ $key ] ),
					);
				}
			}

			$data[] = array(
				'group_id'    => 'pegwork-form-entries',
				'group_label' => __( 'Form entries', 'pegwork' ),
				'item_id'     => 'pegwork-entry-' . $entry['id'],
				'data'        => $pairs,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $entries ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Deletes one batch of an address's entries. Each call starts from the first remaining entry, since
	 * the previous batch is gone.
	 *
	 * @param string $email Address.
	 * @param int    $page  Page (unused: see above).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		unset( $page );
		$entries  = Entries::by_email( (string) $email, self::PAGE_SIZE );
		$messages = array();
		$retained = false;

		foreach ( $entries as $entry ) {
			$post = null !== $entry['post_id'] ? get_post( $entry['post_id'] ) : null;
			if ( $post instanceof \WP_Post ) {
				$retained   = true;
				$messages[] = sprintf(
					/* translators: 1: entry ID, 2: post title, 3: post ID. */
					__( 'Entry %1$d was deleted. The record created from it, "%2$s" (ID %3$d), was kept: edit or delete it yourself if it holds this person\'s data.', 'pegwork' ),
					$entry['id'],
					wp_strip_all_tags( get_the_title( $post ) ),
					$post->ID
				);
			}
		}
		$removed = Entries::delete( array_column( $entries, 'id' ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $entries ) < self::PAGE_SIZE || 0 === $removed,
		);
	}

	/**
	 * Adds suggested privacy policy text. Hooked to admin_init.
	 */
	public static function policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$paragraphs = array(
			__( 'When you submit a form on this site, we store what you entered, the time, and, if you are logged in, your user account. We do not store your IP address; a keyed hash of it is kept for a few minutes to limit repeated submissions.', 'pegwork' ),
			__( 'Some forms send you an email to confirm your submission. Unconfirmed submissions are deleted after 7 days. Submissions caught as spam are deleted after 30 days. Other submissions are kept for the period each form sets, or until deleted by a site administrator.', 'pegwork' ),
			__( 'If a form creates a listing or post from your submission, that record is kept as site content. Asking us to erase your data deletes your form submissions; tell us if the record should change too.', 'pegwork' ),
			__( 'Forms that use Cloudflare Turnstile to check for spam load a script from Cloudflare, which receives information about your browser and visit. See Cloudflare\'s privacy policy: https://www.cloudflare.com/privacypolicy/', 'pegwork' ),
		);

		wp_add_privacy_policy_content( __( 'Pegwork Forms', 'pegwork' ), wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' ) );
	}
}
