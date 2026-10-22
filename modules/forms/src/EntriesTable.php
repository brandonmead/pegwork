<?php
/**
 * Entries list table.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Pegwork > Entries list: status views, date, email, form, summary, status and linked post columns,
 * bulk actions (mark spam, not spam, delete) posted to admin-post.php, paging by link.
 *
 * Its form posts to `admin-post.php` (EntryActions); filters and search live in a separate GET form
 * printed by EntriesAdmin, so a filter never carries a nonce in its URL.
 */
final class EntriesTable extends \WP_List_Table {

	/**
	 * Entries per page.
	 */
	public const PER_PAGE = 20;

	/**
	 * Current list arguments (form, status, s, order, paged).
	 *
	 * @var array<string, string|int>
	 */
	private array $list_args;

	/**
	 * Items of each form's definition, by form key (cache for the summary column).
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $items_by_form = array();

	/**
	 * Sets up the table for the current screen.
	 *
	 * @param array<string, string|int> $list_args Current list arguments.
	 */
	public function __construct( array $list_args ) {
		$this->list_args = $list_args;
		parent::__construct(
			array(
				'singular' => 'pegwork_entry',
				// Also the nonce: WP_List_Table prints a `bulk-{plural}` nonce, which EntryActions checks.
				'plural'   => EntryActions::PLURAL,
				'ajax'     => false,
				'screen'   => get_current_screen() ?? 'pegwork_page_' . Notifications::ENTRIES_PAGE,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'date'    => __( 'Submitted', 'pegwork' ),
			'email'   => __( 'Email', 'pegwork' ),
			'form'    => __( 'Form', 'pegwork' ),
			'summary' => __( 'Summary', 'pegwork' ),
			'status'  => __( 'Status', 'pegwork' ),
			'post'    => __( 'Post', 'pegwork' ),
		);
	}

	/**
	 * Sortable columns: date only (newest first by default).
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		// Desc first; initially sorted desc (newest first), so the header shows the current order.
		return array( 'date' => array( 'date', true, '', '', 'desc' ) );
	}

	/**
	 * Primary column (holds the row actions).
	 */
	protected function get_default_primary_column_name() {
		return 'date';
	}

	/**
	 * Loads the current page of entries.
	 */
	public function prepare_items() {
		$result = Entries::query(
			array(
				'form'     => (string) $this->list_args['form'],
				'status'   => (string) $this->list_args['status'],
				'search'   => (string) $this->list_args['s'],
				'order'    => (string) $this->list_args['order'],
				'per_page' => self::PER_PAGE,
				'page'     => (int) $this->list_args['paged'],
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'date' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Status views with counts for the current form and search.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$counts  = Entries::status_counts( (string) $this->list_args['form'], (string) $this->list_args['s'] );
		$current = (string) $this->list_args['status'];
		$base    = array_intersect_key( $this->list_args, array_flip( array( 'form', 's' ) ) );
		$labels  = array(
			/* translators: %s: number of entries. */
			'all'         => _nx_noop( 'All <span class="count">(%s)</span>', 'All <span class="count">(%s)</span>', 'entries', 'pegwork' ),
			/* translators: %s: number of entries. */
			'received'    => _nx_noop( 'Received <span class="count">(%s)</span>', 'Received <span class="count">(%s)</span>', 'entries', 'pegwork' ),
			/* translators: %s: number of entries. */
			'unconfirmed' => _nx_noop( 'Unconfirmed <span class="count">(%s)</span>', 'Unconfirmed <span class="count">(%s)</span>', 'entries', 'pegwork' ),
			/* translators: %s: number of entries. */
			'processing'  => _nx_noop( 'Processing <span class="count">(%s)</span>', 'Processing <span class="count">(%s)</span>', 'entries', 'pegwork' ),
			/* translators: %s: number of entries. */
			'failed'      => _nx_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'entries', 'pegwork' ),
			/* translators: %s: number of entries. */
			'spam'        => _nx_noop( 'Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', 'entries', 'pegwork' ),
		);

		$counts['all'] = array_sum( $counts ) - $counts['spam'];

		$views = array();
		foreach ( $labels as $status => $label ) {
			if ( 'processing' === $status && 0 === $counts['processing'] && 'processing' !== $current ) {
				continue; // Transient state; listed only while an entry is in it.
			}
			$text             = sprintf( translate_nooped_plural( $label, $counts[ $status ], 'pegwork' ), number_format_i18n( $counts[ $status ] ) );
			$aria             = $status === $current ? ' class="current" aria-current="page"' : '';
			$views[ $status ] = '<a href="' . esc_url( EntriesAdmin::url( $base + array( 'status' => $status ) ) ) . '"' . $aria . '>' . $text . '</a>';
		}
		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		// The spam view lists only spam; every other view lists none.
		$actions = 'spam' === $this->list_args['status']
			? array( 'not_spam' => __( 'Not spam', 'pegwork' ) )
			: array( 'spam' => __( 'Mark as spam', 'pegwork' ) );

		$actions['delete'] = __( 'Delete permanently', 'pegwork' );
		return $actions;
	}

	/**
	 * Bulk action control, top only. Named `op` (the form's `action` field selects the admin-post hook).
	 *
	 * @param string $which `top` or `bottom`.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<label for="pegwork-entries-bulk" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'pegwork' ) . '</label>';
		echo '<select name="op" id="pegwork-entries-bulk">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'pegwork' ) . '</option>';
		foreach ( $this->get_bulk_actions() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button action" id="pegwork-entries-apply">' . esc_html__( 'Apply', 'pegwork' ) . '</button>';
	}

	/**
	 * The form filter beside the bulk actions (top only). Its controls belong to the GET filter form.
	 *
	 * @param string $which `top` or `bottom`.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		EntriesAdmin::print_form_filter( (string) $this->list_args['form'] );
		echo '</div>';
	}

	/**
	 * Message when there is nothing to list.
	 */
	public function no_items() {
		$filtered = '' !== $this->list_args['form'] || '' !== $this->list_args['s'] || 'all' !== $this->list_args['status'];
		if ( $filtered ) {
			esc_html_e( 'No entries match these filters.', 'pegwork' );
			return;
		}
		esc_html_e( 'No entries yet. Entries appear here when someone submits a form.', 'pegwork' );
	}

	/**
	 * Row checkbox with a visible-to-screen-readers label.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_cb( $item ) {
		$id = (int) $item['id'];
		return '<label class="label-covers-full-cell" for="pegwork-entry-cb-' . $id . '"><span class="screen-reader-text">'
			/* translators: %d: entry ID. */
			. esc_html( sprintf( __( 'Select entry %d', 'pegwork' ), $id ) )
			. '</span></label><input id="pegwork-entry-cb-' . $id . '" type="checkbox" name="entry[]" value="' . $id . '" />';
	}

	/**
	 * Submitted date, linked to the entry.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_date( $item ) {
		return '<strong><a class="row-title" href="' . esc_url( EntriesAdmin::url( array( 'entry' => (int) $item['id'] ) ) ) . '">' . esc_html( EntryFields::date( (string) $item['created_at'] ) ) . '</a></strong>';
	}

	/**
	 * Row actions under the date: View and Delete (the delete link opens the confirmation screen).
	 *
	 * @param array<string, mixed> $item        Entry.
	 * @param string               $column_name Column.
	 * @param string               $primary     Primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id   = (int) $item['id'];
		$back = array_diff_key( $this->list_args, array( 'paged' => true ) );

		$actions = array(
			'view'   => '<a href="' . esc_url( EntriesAdmin::url( array( 'entry' => $id ) ) ) . '">' . esc_html__( 'View', 'pegwork' )
				/* translators: %d: entry ID. */
				. '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'entry %d', 'pegwork' ), $id ) ) . '</span></a>',
			'delete' => '<a class="submitdelete" href="' . esc_url( EntriesAdmin::url( $back + array( 'delete' => (string) $id ) ) ) . '">' . esc_html__( 'Delete', 'pegwork' )
				/* translators: %d: entry ID. */
				. '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'entry %d', 'pegwork' ), $id ) ) . '</span></a>',
		);
		return $this->row_actions( $actions );
	}

	/**
	 * Email column.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_email( $item ) {
		return '' === $item['email'] ? self::none() : esc_html( (string) $item['email'] );
	}

	/**
	 * Form column.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_form( $item ) {
		return esc_html( EntryFields::form_label( (string) $item['form_key'] ) );
	}

	/**
	 * Up to two text values of the entry, shortened.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_summary( $item ) {
		$key = (string) $item['form_key'];
		if ( ! isset( $this->items_by_form[ $key ] ) ) {
			$this->items_by_form[ $key ] = EntryFields::items( pegwork_registry()->form( $key ) );
		}

		$parts = array();
		foreach ( (array) $item['values'] as $value_key => $value ) {
			$field = $this->items_by_form[ $key ][ $value_key ] ?? null;
			$type  = $field['type'] ?? ( is_string( $value ) ? 'text' : '' );
			if ( ! in_array( $type, array( 'text', 'textarea', 'rich_text' ), true ) || ! is_string( $value ) || '' === trim( $value ) ) {
				continue;
			}
			// Plain text (values are never HTML); shortened, not stripped, so "<b>" shows as typed.
			$parts[] = mb_strimwidth( preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? '', 0, 60, '...' );
			if ( count( $parts ) >= 2 ) {
				break;
			}
		}
		return array() === $parts ? self::none() : esc_html( implode( ' / ', $parts ) );
	}

	/**
	 * Status column.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_status( $item ) {
		return esc_html( EntryFields::status_label( (string) $item['status'] ) );
	}

	/**
	 * Linked post: title (edit link when allowed) and post status.
	 *
	 * @param array<string, mixed> $item Entry.
	 */
	protected function column_post( $item ) {
		return EntriesAdmin::post_link( $item['post_id'] );
	}

	/**
	 * Text for an empty cell, announced as "None".
	 */
	private static function none(): string {
		return '<span aria-hidden="true">-</span><span class="screen-reader-text">' . esc_html__( 'None', 'pegwork' ) . '</span>';
	}
}
