<?php
/**
 * Pegwork > Entries screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use Pegwork\Api;

/**
 * The Entries screen (`admin.php?page=pegwork-entries`): the list (EntriesTable), one entry
 * (`&entry=<id>`, the URL `{entry:admin_url}` links to) and the delete confirmation (`&delete=<ids>`).
 * Every screen and action requires the forms capability (filter `pegwork_forms_capability`, default
 * `manage_options`): entries are personal data.
 */
final class EntriesAdmin {

	/**
	 * Submenu position under Pegwork (`docs/extension-api.md`: Forms 20, Entries 21).
	 */
	public const POSITION = 21;

	/**
	 * Transient prefix for the notice shown after an action; the user ID is appended.
	 */
	private const NOTICE = 'pegwork_entries_notice_';

	/**
	 * ID of the GET form holding search and the form filter.
	 */
	public const FILTER_FORM = 'pegwork-entries-filter';

	/**
	 * Column widths of the entry screen's label/value tables (no stylesheet needed).
	 */
	private const COLUMNS = '<colgroup><col style="width:30%"><col></colgroup>';

	/**
	 * Styles core lacks: a destructive button (red, as core's `button-link-delete`), outlined on the entry
	 * screen and filled on the delete confirmation, with a visible focus ring.
	 */
	private const STYLE = '.pegwork-entries .button.pegwork-button-delete{color:#b32d2e;border-color:#b32d2e;background:#fff;}'
		. '.pegwork-entries .button.pegwork-button-delete:hover{color:#fff;border-color:#8a2424;background:#b32d2e;}'
		. '.pegwork-entries .button.pegwork-button-delete.button-primary{color:#fff;border-color:#b32d2e;background:#b32d2e;}'
		. '.pegwork-entries .button.pegwork-button-delete.button-primary:hover{border-color:#8a2424;background:#8a2424;}'
		. '.pegwork-entries .button.pegwork-button-delete:focus{border-color:#b32d2e;box-shadow:0 0 0 1px #fff,0 0 0 3px #b32d2e;outline:2px solid transparent;}'
		// Core hides top table actions at 782px; here they hold the only bulk actions and the form filter (needed for CSV export).
		. '@media screen and (max-width:782px){.pegwork-entries .tablenav.top{height:auto;}.pegwork-entries .tablenav.top .actions{display:block;margin:0 0 8px;}}';

	/**
	 * List arguments and their defaults, read from the query string (and echoed through action forms).
	 */
	private const LIST_DEFAULTS = array(
		'form'   => '',
		'status' => 'all',
		's'      => '',
		'order'  => '',
		'paged'  => 1,
	);

	/**
	 * Hooks the submenu.
	 */
	public static function register(): void {
		if ( ! has_action( 'admin_menu', array( self::class, 'menu' ) ) ) {
			add_action( 'admin_menu', array( self::class, 'menu' ) );
		}
	}

	/**
	 * Adds Pegwork > Entries. Hooked to admin_menu at the default priority.
	 */
	public static function menu(): void {
		add_submenu_page(
			Api\ADMIN_MENU,
			__( 'Entries', 'pegwork' ),
			__( 'Entries', 'pegwork' ),
			Module::capability(),
			Notifications::ENTRIES_PAGE,
			array( self::class, 'render' ),
			self::POSITION
		);
	}

	/**
	 * Stops with a 403 unless the current user has the forms capability.
	 */
	public static function authorize(): void {
		if ( ! current_user_can( Module::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage form entries.', 'pegwork' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stops with a 403 unless the request carries a valid `_wpnonce` for an action.
	 *
	 * @param string $action Nonce action.
	 */
	public static function verify_nonce( string $action ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces are compared, not stored or printed.
		$nonce = isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ? wp_unslash( $_REQUEST['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Go back, reload the page and try again.', 'pegwork' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * URL of the screen with arguments; defaults and empty values are left out.
	 *
	 * @param array<string, string|int> $args List arguments, `entry` or `delete`.
	 */
	public static function url( array $args = array() ): string {
		$query = array( 'page' => Notifications::ENTRIES_PAGE );
		foreach ( $args as $key => $value ) {
			if ( '' === (string) $value || 0 === $value || ( self::LIST_DEFAULTS[ $key ] ?? null ) === $value ) {
				continue;
			}
			$query[ $key ] = $value;
		}
		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Sanitized list arguments (every key of LIST_DEFAULTS) plus `entry` when given.
	 *
	 * @param array<string, mixed> $source `$_GET` or `$_POST`, slashed.
	 * @return array<string, string|int>
	 */
	public static function list_args_from( array $source ): array {
		$raw  = wp_unslash( $source );
		$args = array(
			'form'   => is_string( $raw['form'] ?? null ) ? sanitize_key( $raw['form'] ) : '',
			'status' => self::view_status( is_string( $raw['status'] ?? null ) ? sanitize_key( $raw['status'] ) : 'all' ),
			's'      => is_string( $raw['s'] ?? null ) ? trim( sanitize_text_field( $raw['s'] ) ) : '',
			'order'  => 'asc' === ( $raw['order'] ?? '' ) ? 'asc' : '',
			'paged'  => max( 1, is_scalar( $raw['paged'] ?? null ) ? absint( $raw['paged'] ) : 1 ),
		);
		return $args;
	}

	/**
	 * A view status: `all` or one of Entries::STATUSES.
	 *
	 * @param string $status Requested status.
	 */
	public static function view_status( string $status ): string {
		return in_array( $status, Entries::STATUSES, true ) ? $status : 'all';
	}

	/**
	 * Stores a notice for the current user's next screen.
	 *
	 * @param string $type    `success` or `error`.
	 * @param string $message Plain text.
	 * @param string $link    Optional URL of an "Edit draft" link.
	 */
	public static function notice( string $type, string $message, string $link = '' ): void {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'link'    => $link,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Prints the screen. Menu callback.
	 */
	public static function render(): void {
		self::authorize();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screens; actions verify their own nonces.
		$entry  = isset( $_GET['entry'] ) ? absint( $_GET['entry'] ) : 0;
		$delete = isset( $_GET['delete'] ) && is_string( $_GET['delete'] ) ? sanitize_text_field( wp_unslash( $_GET['delete'] ) ) : '';
		$return = isset( $_GET['return_entry'] ) ? absint( $_GET['return_entry'] ) : 0;
		$args   = self::list_args_from( $_GET );
		// phpcs:enable

		echo '<style>' . self::STYLE . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A constant.
		echo '<div class="wrap pegwork-entries">';
		if ( $entry > 0 ) {
			self::render_entry( $entry );
		} elseif ( '' !== $delete ) {
			self::render_delete( array_values( array_filter( array_map( 'absint', explode( ',', $delete ) ) ) ), $args, $return );
		} else {
			self::render_list( $args );
		}
		echo '</div>';
	}

	/**
	 * Linked post as HTML: title (edit link when allowed) and status, a note when deleted, else "None".
	 *
	 * @param int|null $post_id Post ID.
	 */
	public static function post_link( ?int $post_id ): string {
		if ( null === $post_id ) {
			return '<span aria-hidden="true">-</span><span class="screen-reader-text">' . esc_html__( 'None', 'pegwork' ) . '</span>';
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			/* translators: %d: post ID. */
			return esc_html( sprintf( __( 'Post %d (deleted)', 'pegwork' ), $post_id ) );
		}

		$title  = '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'pegwork' );
		$status = get_post_status_object( $post->post_status );
		$label  = esc_html( wp_strip_all_tags( $title ) );
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$label = '<a href="' . esc_url( (string) get_edit_post_link( $post->ID, 'raw' ) ) . '">' . $label . '</a>';
		}
		return $label . ' (' . esc_html( null !== $status ? (string) $status->label : $post->post_status ) . ')';
	}

	/**
	 * The list: heading, notice, views, filter and search form, the table in the action form.
	 *
	 * @param array<string, string|int> $args List arguments.
	 */
	private static function render_list( array $args ): void {
		$table = new EntriesTable( $args );
		$table->prepare_items();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Entries', 'pegwork' ) . '</h1>';
		if ( '' !== $args['form'] && $table->has_items() ) {
			echo ' <a class="page-title-action" href="' . esc_url( Export::url( (string) $args['form'], (string) $args['status'], (string) $args['s'] ) ) . '">' . esc_html__( 'Export CSV', 'pegwork' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		self::print_notice();

		$table->views();

		// Filters and search: a GET form of their own, so their URLs never carry the action nonce. The
		// form filter sits in the table's top bar (EntriesTable::extra_tablenav) and joins this form
		// through the `form` attribute.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" id="' . esc_attr( self::FILTER_FORM ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Notifications::ENTRIES_PAGE ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( (string) $args['status'] ) . '">';
		$table->search_box( __( 'Search by email', 'pegwork' ), 'pegwork-entries' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="pegwork-entries-list">';
		echo '<input type="hidden" name="action" value="' . esc_attr( EntryActions::ACTION ) . '">';
		foreach ( array( 'form', 'status', 's', 'order' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $args[ $key ] ) . '">';
		}
		$table->display();
		echo '</form>';

		if ( '' === $args['form'] && $table->has_items() ) {
			echo '<p class="description">' . esc_html__( 'To export entries as CSV, choose a form and select Filter.', 'pegwork' ) . '</p>';
		}
	}

	/**
	 * One entry: values, details, actions.
	 *
	 * @param int $id Entry ID.
	 */
	private static function render_entry( int $id ): void {
		$entry = Entries::get( $id );
		$back  = '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to entries', 'pegwork' ) . '</a></p>';

		if ( null === $entry ) {
			echo '<h1>' . esc_html__( 'Entry not found', 'pegwork' ) . '</h1>';
			self::print_notice();
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This entry does not exist. It may have been deleted.', 'pegwork' ) . '</p></div>';
			echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			return;
		}

		$form  = pegwork_registry()->form( (string) $entry['form_key'] );
		$items = EntryFields::items( $form );

		/* translators: %d: entry ID. */
		echo '<h1>' . esc_html( sprintf( __( 'Entry %d', 'pegwork' ), $id ) ) . '</h1>';
		self::print_notice();
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.

		echo '<h2>' . esc_html__( 'Submitted values', 'pegwork' ) . '</h2>';
		echo '<table class="widefat striped fixed pegwork-entry-values">' . self::COLUMNS . '<tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant markup.
		$columns = EntryFields::columns( $form, array( $entry ) );
		if ( array() === $columns ) {
			echo '<tr><td>' . esc_html__( 'This entry has no values.', 'pegwork' ) . '</td></tr>';
		}
		foreach ( $columns as $key => $label ) {
			$value = $entry['values'][ $key ] ?? null;
			$text  = array_key_exists( $key, $entry['values'] ) ? EntryFields::text( $items[ $key ] ?? null, $value ) : '';
			$cell  = '' === $text ? '<span class="description">' . esc_html__( '(empty)', 'pegwork' ) . '</span>' : nl2br( esc_html( $text ) );
			if ( '' !== $text && is_array( $value ) && isset( $value['name'], $value['size'] ) ) {
				$cell = self::file_cell( $id, (string) $key, $value, $text );
			}
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $cell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cell is escaped above.
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Details', 'pegwork' ) . '</h2>';
		echo '<table class="widefat striped fixed pegwork-entry-details">' . self::COLUMNS . '<tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant markup.
		foreach ( self::details( $entry ) as $label => $html ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- details() escapes every value.
		}
		echo '</tbody></table>';

		self::render_entry_actions( $entry, $form );
	}

	/**
	 * An uploaded file's cell: its name linked to the private download, then size and type; or the
	 * name with why there is nothing to download (spam entries never keep files).
	 *
	 * @param int                  $entry_id Entry ID.
	 * @param string               $key      Item key.
	 * @param array<string, mixed> $value    Stored file value.
	 * @param string               $name     File name as text.
	 * @return string Escaped HTML.
	 */
	private static function file_cell( int $entry_id, string $key, array $value, string $name ): string {
		$size = size_format( (int) $value['size'] );
		$size = false === $size ? '' : $size;
		$mime = is_string( $value['mime'] ?? null ) ? $value['mime'] : '';

		if ( null !== Uploads::absolute( $value ) ) {
			// Underlined: next to plain text, a link must not rely on color alone (WCAG 1.4.1).
			return '<a href="' . esc_url( Files::url( $entry_id, $key ) ) . '" download style="text-decoration:underline">' . esc_html( $name ) . '</a> <span class="description">('
				/* translators: 1: file size, such as "120 KB", 2: file type, such as "application/pdf". */
				. esc_html( sprintf( __( '%1$s, %2$s', 'pegwork' ), $size, $mime ) ) . ')</span>';
		}
		$why = Uploads::is_stored( $value ) ? __( 'the file is missing from the server', 'pegwork' ) : __( 'the file was not kept', 'pegwork' );

		return esc_html( $name ) . ' <span class="description">(' . esc_html( $why ) . ')</span>';
	}

	/**
	 * Detail rows of an entry: label => escaped HTML.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return array<string, string>
	 */
	private static function details( array $entry ): array {
		$meta = (array) $entry['meta'];
		$rows = array(
			__( 'Form', 'pegwork' )      => esc_html( EntryFields::form_label( (string) $entry['form_key'] ) ) . ' <code>' . esc_html( (string) $entry['form_key'] ) . '</code>',
			__( 'Status', 'pegwork' )    => esc_html( EntryFields::status_label( (string) $entry['status'] ) ),
			__( 'Email', 'pegwork' )     => '' !== $entry['email'] ? esc_html( (string) $entry['email'] ) : esc_html__( 'None', 'pegwork' ),
			__( 'Submitted', 'pegwork' ) => esc_html( EntryFields::date( (string) $entry['created_at'] ) ),
		);
		if ( ! empty( $entry['confirmed_at'] ) ) {
			$rows[ __( 'Confirmed', 'pegwork' ) ] = esc_html( EntryFields::date( (string) $entry['confirmed_at'] ) );
		}
		$rows[ __( 'Post', 'pegwork' ) ] = self::post_link( $entry['post_id'] );

		if ( isset( $meta['post_created'] ) && is_array( $meta['post_created'] ) ) {
			$user = get_userdata( (int) ( $meta['post_created']['by'] ?? 0 ) );

			$rows[ __( 'Post created by', 'pegwork' ) ] = esc_html(
				sprintf(
					/* translators: 1: user display name, 2: date. */
					__( '%1$s, %2$s (Create post anyway)', 'pegwork' ),
					$user ? $user->display_name : __( 'Unknown user', 'pegwork' ),
					EntryFields::date( (string) ( $meta['post_created']['at'] ?? '' ) )
				)
			);
		}

		if ( null !== $entry['user_id'] ) {
			$user = get_userdata( $entry['user_id'] );

			$rows[ __( 'Submitted by', 'pegwork' ) ] = $user
				? ( current_user_can( 'edit_user', $user->ID ) ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : esc_html( $user->display_name ) )
				/* translators: %d: user ID. */
				: esc_html( sprintf( __( 'User %d (deleted)', 'pegwork' ), $entry['user_id'] ) );
		}

		$page_id = (int) ( $meta['page_id'] ?? 0 );
		if ( $page_id > 0 && get_post( $page_id ) ) {
			$rows[ __( 'Page', 'pegwork' ) ] = '<a href="' . esc_url( (string) get_permalink( $page_id ) ) . '">' . esc_html( wp_strip_all_tags( get_the_title( $page_id ) ) ) . '</a>';
		}

		if ( isset( $meta['notifications'] ) && is_array( $meta['notifications'] ) && array() !== $meta['notifications'] ) {
			$lines = array();
			foreach ( $meta['notifications'] as $key => $result ) {
				$lines[] = esc_html( $key . ': ' . ( is_scalar( $result ) ? (string) $result : '' ) );
			}
			$rows[ __( 'Notifications', 'pegwork' ) ] = implode( '<br>', $lines );
		}

		if ( isset( $meta['spam'] ) && is_array( $meta['spam'] ) && array() !== $meta['spam'] ) {
			$rows[ __( 'Spam reasons', 'pegwork' ) ] = esc_html( implode( ', ', array_map( 'strval', array_filter( $meta['spam'], 'is_scalar' ) ) ) );
		}

		if ( isset( $meta['errors'] ) && is_array( $meta['errors'] ) && array() !== $meta['errors'] ) {
			$lines = array();
			foreach ( $meta['errors'] as $key => $message ) {
				// Key '' is a whole-form error: no "key: " prefix.
				$lines[] = esc_html( ( is_string( $key ) && '' !== $key ? $key . ': ' : '' ) .( is_scalar( $message ) ? (string) $message : (string) wp_json_encode( $message ) ) );
			}
			$rows[ __( 'Errors', 'pegwork' ) ] = implode( '<br>', $lines );
		}

		return $rows;
	}

	/**
	 * Action buttons for one entry: create post anyway, spam or not spam, delete.
	 *
	 * @param array<string, mixed>      $entry Entry.
	 * @param array<string, mixed>|null $form  Canonical form, or null when deleted.
	 */
	private static function render_entry_actions( array $entry, ?array $form ): void {
		$id = (int) $entry['id'];

		echo '<h2>' . esc_html__( 'Actions', 'pegwork' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pegwork-entry-actions">';
		echo '<input type="hidden" name="action" value="' . esc_attr( EntryActions::ACTION ) . '">';
		echo '<input type="hidden" name="entry[]" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="return_entry" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( EntryActions::NONCE, '_wpnonce', true );

		echo '<p>';
		if ( EntryActions::can_create_post( $entry ) ) {
			$type   = (string) EntryActions::draft_type( $form );
			$object = get_post_type_object( $type );
			$name   = null !== $object ? (string) $object->labels->singular_name : $type;
			echo '<button type="submit" class="button button-primary" name="op" value="create_post" aria-describedby="pegwork-entry-create-help">' . esc_html__( 'Create post anyway', 'pegwork' ) . '</button> ';
		}
		if ( 'spam' === $entry['status'] ) {
			echo '<button type="submit" class="button" name="op" value="not_spam">' . esc_html__( 'Not spam', 'pegwork' ) . '</button> ';
		} elseif ( 'processing' !== $entry['status'] ) {
			echo '<button type="submit" class="button" name="op" value="spam">' . esc_html__( 'Mark as spam', 'pegwork' ) . '</button> ';
		}
		echo '<a class="button pegwork-button-delete" href="' . esc_url(
			self::url(
				array(
					'delete'       => (string) $id,
					'return_entry' => $id,
				)
			)
		) . '">' . esc_html__( 'Delete permanently', 'pegwork' ) . '</a>';
		echo '</p>';

		if ( isset( $name ) ) {
			/* translators: %s: content type name. */
			echo '<p class="description" id="pegwork-entry-create-help">' . esc_html( sprintf( __( 'Creates a Draft %s from this entry\'s values and links it here. Use it when a submitter lost the confirmation email, or to publish an entry-only submission.', 'pegwork' ), $name ) ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * Delete confirmation: what will be deleted, a confirm button and a way back.
	 *
	 * @param int[]                     $ids     Entry IDs.
	 * @param array<string, string|int> $args    List arguments to return to.
	 * @param int                       $back_to Entry whose screen Cancel returns to, or 0 for the list.
	 */
	private static function render_delete( array $ids, array $args, int $back_to ): void {
		$entries = array_values( array_filter( array_map( array( Entries::class, 'get' ), array_slice( array_unique( $ids ), 0, EntriesTable::PER_PAGE * 5 ) ) ) );
		$cancel  = self::url( $back_to > 0 ? array( 'entry' => $back_to ) : $args );

		echo '<h1>' . esc_html( _n( 'Delete entry', 'Delete entries', max( 1, count( $entries ) ), 'pegwork' ) ) . '</h1>';
		if ( array() === $entries ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'These entries no longer exist.', 'pegwork' ) . '</p></div>';
			echo '<p><a href="' . esc_url( self::url( $args ) ) . '">' . esc_html__( 'Back to entries', 'pegwork' ) . '</a></p>';
			return;
		}

		echo '<p>' . esc_html( _n( 'This entry will be permanently deleted. This cannot be undone. A post created from it is kept.', 'These entries will be permanently deleted. This cannot be undone. Posts created from them are kept.', count( $entries ), 'pegwork' ) ) . '</p>';
		echo '<ul class="ul-disc">';
		foreach ( $entries as $entry ) {
			$parts = array_filter(
				array(
					/* translators: %d: entry ID. */
					sprintf( __( 'Entry %d', 'pegwork' ), $entry['id'] ),
					EntryFields::form_label( (string) $entry['form_key'] ),
					(string) $entry['email'],
					EntryFields::date( (string) $entry['created_at'] ),
				)
			);
			echo '<li>' . esc_html( implode( ', ', $parts ) ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( EntryActions::ACTION ) . '">';
		echo '<input type="hidden" name="op" value="delete">';
		echo '<input type="hidden" name="confirmed" value="1">';
		foreach ( $entries as $entry ) {
			echo '<input type="hidden" name="entry[]" value="' . esc_attr( (string) $entry['id'] ) . '">';
		}
		foreach ( array( 'form', 'status', 's', 'order' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $args[ $key ] ) . '">';
		}
		wp_nonce_field( EntryActions::NONCE, '_wpnonce', true );
		echo '<p><button type="submit" class="button button-primary pegwork-button-delete" id="pegwork-entries-delete-confirm">' . esc_html__( 'Delete permanently', 'pegwork' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( $cancel ) . '">' . esc_html__( 'Cancel', 'pegwork' ) . '</a></p>';
		echo '</form>';
	}

	/**
	 * Prints the form filter (a select and a Filter button that belong to the GET filter form).
	 *
	 * @param string $current Selected form key, or ''.
	 */
	public static function print_form_filter( string $current ): void {
		$owner = ' form="' . esc_attr( self::FILTER_FORM ) . '"';
		echo '<label for="pegwork-entries-form" class="screen-reader-text">' . esc_html__( 'Filter by form', 'pegwork' ) . '</label>';
		echo '<select name="form" id="pegwork-entries-form"' . $owner . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $owner is escaped above.
		echo '<option value="">' . esc_html__( 'All forms', 'pegwork' ) . '</option>';
		foreach ( self::form_choices() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button" id="pegwork-entries-filter-submit"' . $owner . '>' . esc_html__( 'Filter', 'pegwork' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $owner is escaped above.
	}

	/**
	 * Forms for the filter: registry forms by label, then keys that only entries still use.
	 *
	 * @return array<string, string>
	 */
	private static function form_choices(): array {
		$choices = array();
		foreach ( pegwork_registry()->forms() as $key => $form ) {
			$choices[ (string) $key ] = (string) $form['label'];
		}
		foreach ( Entries::form_keys() as $key ) {
			if ( ! isset( $choices[ $key ] ) ) {
				$choices[ $key ] = EntryFields::form_label( $key );
			}
		}
		return $choices;
	}

	/**
	 * Prints and clears the current user's notice from the last action, if any.
	 */
	private static function print_notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		$class = 'success' === $notice['type'] ? 'notice-success' : 'notice-error';
		$link  = '' !== (string) ( $notice['link'] ?? '' ) ? ' <a href="' . esc_url( (string) $notice['link'] ) . '">' . esc_html__( 'Edit the draft', 'pegwork' ) . '</a>' : '';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is escaped above.
	}
}
