<?php
/**
 * Pegwork Overview screen.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

use Pegwork\Registry\Status;

/**
 * Read-only view of the effective registry, with shadowed DB entries and rejected sources.
 *
 * All data comes from Registry\Status, which `wp pegwork schema list|status` also read.
 */
final class Overview {

	/**
	 * Prints the screen. Menu callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'pegwork' ), 403 );
		}

		$status = Status::current();
		$none   = __( 'None registered. Add a schema package (see the extension API docs).', 'pegwork' );

		echo '<div class="wrap pegwork-admin">';
		echo '<h1>' . esc_html__( 'Pegwork Overview', 'pegwork' ) . '</h1>';
		echo '<p>' . esc_html__( 'The schema registered on this site: file packages first, then site-local entries stored in the database. This screen is read-only.', 'pegwork' ) . '</p>';

		self::content_types( $status->content_types(), $none );

		self::section(
			'pegwork-taxonomies',
			__( 'Taxonomies', 'pegwork' ),
			array(
				'key'    => __( 'Key', 'pegwork' ),
				'label'  => __( 'Label', 'pegwork' ),
				'terms'  => __( 'Seed terms', 'pegwork' ),
				'locked' => __( 'Locked', 'pegwork' ),
				'source' => __( 'Source', 'pegwork' ),
			),
			array_map(
				fn( array $row ) => array(
					'locked' => self::yes_no( $row['locked'] ),
					'terms'  => number_format_i18n( $row['terms'] ),
				) + $row,
				$status->taxonomies()
			),
			$none
		);

		self::section(
			'pegwork-relationships',
			__( 'Relationships', 'pegwork' ),
			array(
				'key'         => __( 'Key', 'pegwork' ),
				'from'        => __( 'From', 'pegwork' ),
				'to'          => __( 'To', 'pegwork' ),
				'cardinality' => __( 'Cardinality', 'pegwork' ),
				'required'    => __( 'Required', 'pegwork' ),
				'source'      => __( 'Source', 'pegwork' ),
			),
			array_map(
				fn( array $row ) => array( 'required' => self::yes_no( $row['required'] ) ) + $row,
				$status->relationships()
			),
			$none
		);

		self::section(
			'pegwork-options-pages',
			__( 'Options pages', 'pegwork' ),
			array(
				'key'        => __( 'Key (option name)', 'pegwork' ),
				'title'      => __( 'Title', 'pegwork' ),
				'menu_title' => __( 'Menu item', 'pegwork' ),
				'capability' => __( 'Capability', 'pegwork' ),
				'fields'     => __( 'Fields', 'pegwork' ),
				'source'     => __( 'Source', 'pegwork' ),
			),
			array_map(
				fn( array $row ) => array(
					'fields' => sprintf(
						/* translators: 1: number of fields, 2: number of private fields. */
						__( '%1$s (%2$s private)', 'pegwork' ),
						number_format_i18n( $row['fields'] ),
						number_format_i18n( $row['private'] )
					),
				) + $row,
				$status->options_pages()
			),
			$none,
			__( 'Each page is one settings screen in this menu, saved as one option named by its key.', 'pegwork' )
		);

		self::section(
			'pegwork-shadowed',
			__( 'Shadowed DB entries', 'pegwork' ),
			array(
				'kind' => __( 'Kind', 'pegwork' ),
				'key'  => __( 'Key', 'pegwork' ),
				'by'   => __( 'Shadowed by package', 'pegwork' ),
			),
			$status->shadowed(),
			__( 'No shadowed DB entries.', 'pegwork' ),
			__( 'These site-local entries use a key that a file package already defines, so the file wins and they are ignored.', 'pegwork' )
		);

		$errors = array();
		foreach ( $status->rejected() as $group ) {
			foreach ( $group['errors'] as $error ) {
				// Short paths on screen; `wp pegwork schema status` prints the absolute ones.
				$error['file'] = $status->display_path( $error['file'] );
				$errors[]      = array( 'source' => $status->display_path( $group['source'] ) ) + $error;
			}
		}
		self::section(
			'pegwork-rejected',
			__( 'Rejected packages and DB entries', 'pegwork' ),
			array(
				'source'  => __( 'Source', 'pegwork' ),
				'file'    => __( 'File', 'pegwork' ),
				'pointer' => __( 'Pointer', 'pegwork' ),
				'message' => __( 'Error', 'pegwork' ),
			),
			$errors,
			__( 'No rejected packages or DB entries.', 'pegwork' ),
			__( 'A package with any error is not registered at all; a DB entry with an error is skipped on its own.', 'pegwork' )
		);

		echo '<p class="pegwork-cli-note">';
		printf(
			/* translators: 1: CLI command, 2: CLI command, 3: CLI command. */
			esc_html__( 'To change the schema, edit the package files or import a package with %1$s. %2$s and %3$s print the same information.', 'pegwork' ),
			'<code>wp pegwork schema import &lt;dir&gt;</code>',
			'<code>wp pegwork schema list</code>',
			'<code>wp pegwork schema status</code>'
		);
		echo '</p></div>';
	}

	/**
	 * Prints the content types table and one expandable field list per type.
	 *
	 * @param array<int, array<string, mixed>> $types Rows from Status::content_types().
	 * @param string                           $none  Empty state text.
	 */
	private static function content_types( array $types, string $none ): void {
		self::section(
			'pegwork-content-types',
			__( 'Content types', 'pegwork' ),
			array(
				'key'        => __( 'Key', 'pegwork' ),
				'label'      => __( 'Label', 'pegwork' ),
				'rest_base'  => __( 'REST base', 'pegwork' ),
				'fields'     => __( 'Fields', 'pegwork' ),
				'taxonomies' => __( 'Taxonomies', 'pegwork' ),
				'source'     => __( 'Source', 'pegwork' ),
			),
			array_map(
				fn( array $row ) => array(
					'fields'     => number_format_i18n( count( $row['fields'] ) ),
					'taxonomies' => implode( ', ', $row['taxonomies'] ),
				) + $row,
				$types
			),
			$none
		);

		foreach ( $types as $type ) {
			echo '<details class="pegwork-fields" id="' . esc_attr( 'pegwork-fields-' . $type['key'] ) . '">';
			echo '<summary>' . esc_html(
				sprintf(
					/* translators: 1: content type label, 2: content type key, 3: number of fields. */
					_n( 'Fields of %1$s (%2$s): %3$s field', 'Fields of %1$s (%2$s): %3$s fields', count( $type['fields'] ), 'pegwork' ),
					$type['label'],
					$type['key'],
					number_format_i18n( count( $type['fields'] ) )
				)
			) . '</summary>';
			self::table(
				sprintf(
					/* translators: %s: content type label. */
					__( 'Fields of %s', 'pegwork' ),
					$type['label']
				),
				array(
					'key'        => __( 'Key', 'pegwork' ),
					'type'       => __( 'Type', 'pegwork' ),
					'label'      => __( 'Label', 'pegwork' ),
					'required'   => __( 'Required', 'pegwork' ),
					'visibility' => __( 'Visibility', 'pegwork' ),
					'stored_as'  => __( 'Meta key or column', 'pegwork' ),
				),
				array_map(
					fn( array $field ) => array(
						'required'   => self::yes_no( $field['required'] ),
						'visibility' => 'private' === $field['visibility'] ? __( 'Private', 'pegwork' ) : __( 'Public', 'pegwork' ),
					) + $field,
					$type['fields']
				),
				__( 'No fields.', 'pegwork' )
			);
			echo '</details>';
		}
	}

	/**
	 * Prints one section: heading, optional description, table.
	 *
	 * @param string                           $id          Heading id.
	 * @param string                           $title       Heading and table caption.
	 * @param array<string, string>            $columns     Column key => header.
	 * @param array<int, array<string, mixed>> $rows        Rows keyed by column.
	 * @param string                           $none        Empty state text.
	 * @param string                           $description Text under the heading, shown when there are rows.
	 */
	private static function section( string $id, string $title, array $columns, array $rows, string $none, string $description = '' ): void {
		echo '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
		if ( $rows && '' !== $description ) {
			echo '<p>' . esc_html( $description ) . '</p>';
		}
		self::table( $title, $columns, $rows, $none );
	}

	/**
	 * Prints a striped table whose first column is the row header.
	 *
	 * @param string                           $caption Table caption (screen readers).
	 * @param array<string, string>            $columns Column key => header.
	 * @param array<int, array<string, mixed>> $rows    Rows keyed by column.
	 * @param string                           $none    Empty state text.
	 */
	private static function table( string $caption, array $columns, array $rows, string $none ): void {
		// Not wp-list-table: its small-screen CSS stacks cells without their headers.
		echo '<table class="widefat striped pegwork-table">';
		echo '<caption class="screen-reader-text">' . esc_html( $caption ) . '</caption>';
		echo '<thead><tr>';
		foreach ( $columns as $key => $header ) {
			echo '<th scope="col" class="' . esc_attr( 'column-' . $key ) . '">' . esc_html( $header ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr class="no-items"><td colspan="' . esc_attr( (string) count( $columns ) ) . '">' . esc_html( $none ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>';
			$first = true;
			foreach ( array_keys( $columns ) as $key ) {
				$value = (string) ( $row[ $key ] ?? '' );
				if ( in_array( $key, array( 'source', 'file', 'pointer' ), true ) && '' !== $value ) {
					// Paths and JSON pointers may break only after a slash (CSS: overflow-wrap:break-word).
					$cell = '<code class="pegwork-path">' . str_replace( '/', '/<wbr>', esc_html( $value ) ) . '</code>';
				} elseif ( in_array( $key, array( 'key', 'by', 'stored_as', 'rest_base', 'capability' ), true ) && '' !== $value ) {
					$cell = '<code>' . esc_html( $value ) . '</code>';
				} else {
					$cell = esc_html( $value );
				}
				$tag = $first ? 'th scope="row"' : 'td';
				echo '<' . $tag . ' class="' . esc_attr( 'column-' . $key ) . '">' . $cell . '</' . ( $first ? 'th' : 'td' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag is a literal; $cell is escaped above.
				$first = false;
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Yes or No.
	 *
	 * @param bool $value Value.
	 */
	private static function yes_no( bool $value ): string {
		return $value ? __( 'Yes', 'pegwork' ) : __( 'No', 'pegwork' );
	}
}
