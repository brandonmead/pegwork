<?php
/**
 * Entry values as labeled text.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Columns and display text for stored entry values, shared by the entry screen, CSV export and the
 * privacy exporter. Columns come from the current definition (bound items resolved to their field or
 * taxonomy label) plus any key found in the entries that the definition no longer has.
 */
final class EntryFields {

	/**
	 * Column label by value key, in form order, then extra keys from the entries in first-seen order.
	 *
	 * @param array<string, mixed>|null        $form    Canonical form, or null when deleted.
	 * @param array<int, array<string, mixed>> $entries Entries with decoded `values`.
	 * @return array<string, string>
	 */
	public static function columns( ?array $form, array $entries ): array {
		$columns = array();
		foreach ( self::items( $form ) as $key => $item ) {
			$columns[ $key ] = (string) $item['label'];
		}
		foreach ( $entries as $entry ) {
			foreach ( array_keys( (array) ( $entry['values'] ?? array() ) ) as $key ) {
				$key = (string) $key;
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = $key;
				}
			}
		}
		return $columns;
	}

	/**
	 * Items by value key (bound items resolved by Definition), each with a `label`.
	 *
	 * @param array<string, mixed>|null $form Canonical form, or null.
	 * @return array<string, array<string, mixed>>
	 */
	public static function items( ?array $form ): array {
		if ( null === $form ) {
			return array();
		}

		$items = array();
		foreach ( Definition::items_by_key( $form ) as $key => $item ) {
			$items[ (string) $key ] = $item + array( 'label' => (string) $key );
		}
		return $items;
	}

	/**
	 * A stored value as plain text: Yes/No for booleans, option labels for selects, lists joined by
	 * commas, a file by its name.
	 *
	 * @param array<string, mixed>|null $item  Item, or null for a key the definition no longer has.
	 * @param mixed                     $value Stored value.
	 */
	public static function text( ?array $item, mixed $value ): string {
		if ( is_array( $value ) ) {
			if ( isset( $value['name'] ) && is_scalar( $value['name'] ) ) {
				return (string) $value['name'];
			}
			$parts = array();
			foreach ( $value as $part ) {
				$parts[] = self::text( $item, $part );
			}
			return implode( ', ', array_filter( $parts, 'strlen' ) );
		}
		if ( null === $item ) {
			if ( is_bool( $value ) ) {
				return $value ? __( 'Yes', 'pegwork' ) : __( 'No', 'pegwork' );
			}
			return is_scalar( $value ) ? Notifications::plain( (string) $value ) : '';
		}
		return Notifications::display( $item, $value );
	}

	/**
	 * Label of an entry status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'received'    => __( 'Received', 'pegwork' ),
			'unconfirmed' => __( 'Unconfirmed', 'pegwork' ),
			'processing'  => __( 'Processing', 'pegwork' ),
			'failed'      => __( 'Failed', 'pegwork' ),
			'spam'        => __( 'Spam', 'pegwork' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Label of an entry's form: the registry label, else the key marked as deleted.
	 *
	 * @param string $key Form key.
	 */
	public static function form_label( string $key ): string {
		$form = pegwork_registry()->form( $key );
		/* translators: %s: form key. */
		return null !== $form ? (string) $form['label'] : sprintf( __( '%s (deleted form)', 'pegwork' ), $key );
	}

	/**
	 * A UTC `Y-m-d H:i:s` time in site time and format, or '' when empty.
	 *
	 * @param string|null $utc UTC time.
	 */
	public static function date( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}
		$time = strtotime( $utc . ' UTC' );
		return false === $time ? '' : (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
