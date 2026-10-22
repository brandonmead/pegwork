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
 * entry's `email` column (the confirm field, else the form's first email item), or by the user account
 * with that address for entries it submitted while logged in (Entries::by_email()). The eraser deletes the
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
	 * Folder in the personal data export archive that holds uploaded files.
	 */
	public const ARCHIVE_DIR = 'pegwork-form-files';

	/**
	 * Hooks the exporter, eraser and policy text.
	 */
	public static function register(): void {
		if ( ! has_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) ) ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
			add_action( 'wp_privacy_personal_data_export_file_created', array( self::class, 'add_files' ), 10, 4 );
			add_action( 'admin_init', array( self::class, 'policy' ) );
		}
	}

	/**
	 * Adds the address's uploaded files to the export archive, as `pegwork-form-files/<entry>-<item>-<name>`.
	 * Hooked to wp_privacy_personal_data_export_file_created.
	 *
	 * @param string $archive  Archive path.
	 * @param string $url      Archive URL (unused).
	 * @param string $html     HTML report path (unused).
	 * @param int    $request  Request ID.
	 * @return int Files added.
	 */
	public static function add_files( $archive, $url = '', $html = '', $request = 0 ): int {
		unset( $url, $html );
		$request = wp_get_user_request( (int) $request );
		if ( ! $request instanceof \WP_User_Request || ! class_exists( \ZipArchive::class ) || ! is_string( $archive ) || ! is_file( $archive ) ) {
			return 0;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			return 0;
		}
		$added = 0;
		$page  = 0;
		do {
			$entries = Entries::by_email( $request->email, self::PAGE_SIZE, $page * self::PAGE_SIZE );
			$count   = count( $entries );
			foreach ( $entries as $entry ) {
				foreach ( (array) $entry['values'] as $key => $value ) {
					$file = Uploads::absolute( $value );
					if ( null !== $file && $zip->addFile( $file, self::ARCHIVE_DIR . '/' . $entry['id'] . '-' . $key . '-' . sanitize_file_name( (string) $value['name'] ) ) ) {
						++$added;
					}
				}
			}
			++$page;
		} while ( self::PAGE_SIZE === $count );
		$zip->close();

		return $added;
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
					$value   = $entry['values'][ $key ];
					$text    = EntryFields::text( $items[ $key ] ?? null, $value );
					$pairs[] = array(
						'name'  => $label,
						'value' => null !== Uploads::absolute( $value )
							/* translators: 1: file name, 2: folder name in the export archive. */
							? sprintf( __( '%1$s (uploaded file, included in the %2$s folder of this export)', 'pegwork' ), $text, self::ARCHIVE_DIR )
							: $text,
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
			__( 'When you submit a form on this site, we store what you entered, the time, and, if you are logged in, your user account. We do not store your IP address. To limit repeated submissions, a keyed hash of it is kept for up to a day, and a keyed hash of it combined with your answers is kept with your submission to recognise accidental double submissions.', 'pegwork' ),
			__( 'Some forms send you an email to confirm your submission. Unconfirmed submissions are deleted after 7 days. Submissions caught as spam are deleted after 30 days. Other submissions are kept for the period each form sets, or until deleted by a site administrator.', 'pegwork' ),
			__( 'Files you upload with a form are stored privately on this server, outside the public media library. Only the people who manage this site\'s forms can download them, and they are deleted with your submission.', 'pegwork' ),
			__( 'If a form creates a listing or post from your submission, that record is kept as site content. Asking us to erase your data deletes your form submissions; tell us if the record should change too.', 'pegwork' ),
			__( 'Forms that use Cloudflare Turnstile to check for spam load a script from Cloudflare, which receives information about your browser and visit; when you submit such a form, this site also sends Cloudflare your IP address to check the result. See Cloudflare\'s privacy policy: https://www.cloudflare.com/privacypolicy/', 'pegwork' ), // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A link in privacy policy text; nothing is loaded.
		);

		wp_add_privacy_policy_content( __( 'Pegwork Forms', 'pegwork' ), wp_kses_post( '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>' ) );
	}
}
