<?php
/**
 * Forms list table.
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
 * The Pegwork > Forms list: every registry form in load order with its key, source, received and
 * unconfirmed counts, an Entries link and a "Copy block" button. No bulk actions, no paging.
 */
final class FormsTable extends \WP_List_Table {

	/**
	 * Sets up the table for the current screen.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'pegwork_form',
				'plural'   => 'pegwork_forms',
				'ajax'     => false,
				'screen'   => get_current_screen() ?? 'pegwork_page_' . FormsAdmin::SLUG,
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
			'label'       => __( 'Form', 'pegwork' ),
			'key'         => __( 'Key', 'pegwork' ),
			'source'      => __( 'Source', 'pegwork' ),
			'received'    => __( 'Received', 'pegwork' ),
			'unconfirmed' => __( 'Unconfirmed', 'pegwork' ),
			'entries'     => __( 'Entries', 'pegwork' ),
			'block'       => __( 'Block', 'pegwork' ),
		);
	}

	/**
	 * Primary column (holds the row actions).
	 */
	protected function get_default_primary_column_name() {
		return 'label';
	}

	/**
	 * Loads every registry form with its post and counts.
	 */
	public function prepare_items() {
		$counts = Entries::counts_by_form();
		$items  = array();
		foreach ( pegwork_registry()->forms() as $key => $form ) {
			$key     = (string) $key;
			$items[] = array(
				'key'     => $key,
				'label'   => (string) $form['label'],
				'source'  => (string) pegwork_registry()->form_source( $key ),
				'post_id' => FormPost::find( $key ),
				'counts'  => $counts[ $key ] ?? array(),
			);
		}

		$this->items           = $items;
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'label' );
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No forms yet. Select "Add form" to build one.', 'pegwork' );
	}

	/**
	 * Label linked to the builder.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_label( $item ): string {
		$label = esc_html( $item['label'] );
		if ( $item['post_id'] > 0 && current_user_can( 'edit_post', $item['post_id'] ) ) {
			$label = '<a class="row-title" href="' . esc_url( (string) get_edit_post_link( $item['post_id'], 'raw' ) ) . '">' . $label . '</a>';
		}
		return '<strong>' . $label . '</strong>';
	}

	/**
	 * Row actions: Edit (or View for file forms), Duplicate for file forms, Entries.
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column.
	 * @param string               $primary     Primary column.
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$file    = FormPost::is_file_form( $item['key'] );
		$actions = array();
		if ( $item['post_id'] > 0 ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( (string) get_edit_post_link( $item['post_id'], 'raw' ) ),
				/* translators: %s: form label. */
				esc_attr( sprintf( $file ? __( 'View "%s" (read-only)', 'pegwork' ) : __( 'Edit "%s"', 'pegwork' ), $item['label'] ) ),
				$file ? esc_html__( 'View', 'pegwork' ) : esc_html__( 'Edit', 'pegwork' )
			);
		}
		if ( $file ) {
			$actions['duplicate'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( BuilderEditor::duplicate_url( $item['key'] ) ),
				/* translators: %s: form label. */
				esc_attr( sprintf( __( 'Duplicate "%s" as a site form', 'pegwork' ), $item['label'] ) ),
				esc_html__( 'Duplicate as site form', 'pegwork' )
			);
		}
		return $this->row_actions( $actions );
	}

	/**
	 * Form key, breakable after each underscore (`<wbr>` adds no character when copied).
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_key( $item ): string {
		return '<code>' . str_replace( '_', '_<wbr>', esc_html( $item['key'] ) ) . '</code>';
	}

	/**
	 * Source: site (DB) or file package (read-only).
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_source( $item ): string {
		if ( 'db' === $item['source'] ) {
			return esc_html__( 'Site', 'pegwork' );
		}
		/* translators: %s: package ID. */
		return esc_html( sprintf( __( 'File: %s (read-only)', 'pegwork' ), substr( $item['source'], 5 ) ) );
	}

	/**
	 * Received entries.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_received( $item ): string {
		return esc_html( number_format_i18n( (int) ( $item['counts']['received'] ?? 0 ) ) );
	}

	/**
	 * Entries waiting for email confirmation.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_unconfirmed( $item ): string {
		return esc_html( number_format_i18n( (int) ( $item['counts']['unconfirmed'] ?? 0 ) ) );
	}

	/**
	 * Link to the form's entries.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_entries( $item ): string {
		return sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url( EntriesAdmin::url( array( 'form' => $item['key'] ) ) ),
			/* translators: %s: form label. */
			esc_attr( sprintf( __( 'Entries of "%s"', 'pegwork' ), $item['label'] ) ),
			esc_html__( 'View entries', 'pegwork' )
		);
	}

	/**
	 * Placement markup and a Copy block button (shown once the script runs).
	 *
	 * @param array<string, mixed> $item Row.
	 */
	protected function column_block( $item ): string {
		$markup = FormsAdmin::block_markup( $item['key'] );
		return sprintf(
			'<code class="pegwork-forms__markup">%1$s</code> <button type="button" class="button button-small pegwork-forms__copy" data-block="%2$s" hidden>%3$s<span class="screen-reader-text"> %4$s</span></button>',
			esc_html( $markup ),
			esc_attr( $markup ),
			esc_html__( 'Copy block', 'pegwork' ),
			/* translators: %s: form label. */
			esc_html( sprintf( __( 'for "%s"', 'pegwork' ), $item['label'] ) )
		);
	}
}
