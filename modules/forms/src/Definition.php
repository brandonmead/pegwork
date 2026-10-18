<?php
/**
 * Helpers over a canonical form definition.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Flattens items, evaluates `show_when`, and reports what this release cannot run yet.
 */
final class Definition {

	/**
	 * Every item of a form in display order (steps flattened).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $form ): array {
		if ( isset( $form['fields'] ) ) {
			return array_values( $form['fields'] );
		}

		$items = array();
		foreach ( $form['steps'] ?? array() as $step ) {
			foreach ( $step['fields'] ?? array() as $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Items by key.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @return array<string, array<string, mixed>>
	 */
	public static function items_by_key( array $form ): array {
		$by_key = array();
		foreach ( self::items( $form ) as $item ) {
			if ( isset( $item['key'] ) ) {
				$by_key[ $item['key'] ] = $item;
			}
		}
		return $by_key;
	}

	/**
	 * Why this release cannot run a form, or null when it can.
	 *
	 * Record mapping (`target`, bound items), email confirmation, Turnstile and file fields arrive in
	 * later releases; until then such forms fail closed: they render nothing for visitors and their
	 * submissions are ignored.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function unsupported( array $form ): ?string {
		if ( isset( $form['target'] ) || isset( $form['confirm'] ) ) {
			return __( 'it creates or updates records or needs email confirmation, which this version of Pegwork Forms does not handle yet', 'pegwork' );
		}
		if ( isset( $form['captcha'] ) ) {
			return __( 'it uses Turnstile, which this version of Pegwork Forms does not support yet', 'pegwork' );
		}
		foreach ( self::items( $form ) as $item ) {
			if ( isset( $item['bind'] ) || 'file' === ( $item['type'] ?? '' ) ) {
				return __( 'it has a bound or file field, which this version of Pegwork Forms does not support yet', 'pegwork' );
			}
		}
		return null;
	}

	/**
	 * Whether an item's `show_when` holds for these values (true without a condition).
	 *
	 * @param array<string, mixed> $item   Item.
	 * @param array<string, mixed> $values Sanitized values of the items shown so far, by key.
	 */
	public static function shown( array $item, array $values ): bool {
		$condition = $item['show_when'] ?? null;
		if ( ! is_array( $condition ) || ! isset( $condition['field'] ) ) {
			return true;
		}
		$value  = $values[ $condition['field'] ] ?? null;
		$equals = $condition['equals'] ?? null;

		if ( is_bool( $equals ) ) {
			return $equals === (bool) $value;
		}
		if ( is_int( $equals ) || is_float( $equals ) ) {
			return is_numeric( $value ) && (float) $value === (float) $equals;
		}
		return null !== $value && (string) $value === (string) $equals;
	}

	/**
	 * Help text that states an item's condition, for the page without JavaScript.
	 *
	 * @param array<string, mixed>                $item   Item.
	 * @param array<string, array<string, mixed>> $by_key Items by key.
	 */
	public static function condition_text( array $item, array $by_key ): string {
		$condition = $item['show_when'] ?? null;
		if ( ! is_array( $condition ) || ! isset( $by_key[ $condition['field'] ?? '' ] ) ) {
			return '';
		}
		$other  = $by_key[ $condition['field'] ];
		$equals = $condition['equals'] ?? null;

		if ( 'boolean' === ( $other['type'] ?? '' ) ) {
			return $equals
				/* translators: %s: label of a checkbox. */
				? sprintf( __( 'Only needed if "%s" is checked.', 'pegwork' ), $other['label'] )
				/* translators: %s: label of a checkbox. */
				: sprintf( __( 'Only needed if "%s" is not checked.', 'pegwork' ), $other['label'] );
		}

		$shown = is_scalar( $equals ) ? (string) $equals : '';
		foreach ( $other['options'] ?? array() as $option ) {
			if ( (string) $option['value'] === $shown ) {
				$shown = (string) $option['label'];
			}
		}

		/* translators: 1: label of another field, 2: the value that makes this field needed. */
		return sprintf( __( 'Only needed if "%1$s" is "%2$s".', 'pegwork' ), $other['label'], $shown );
	}

	/**
	 * Key of the item whose value fills the entry's `email` column: the confirm field, else the first email item.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function email_key( array $form ): ?string {
		if ( isset( $form['confirm']['field'] ) ) {
			return (string) $form['confirm']['field'];
		}
		foreach ( self::items( $form ) as $item ) {
			if ( 'email' === ( $item['type'] ?? '' ) && isset( $item['key'] ) ) {
				return (string) $item['key'];
			}
		}
		return null;
	}

	/**
	 * Hash of the definition, stored with each entry.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function hash( array $form ): string {
		return hash( 'sha256', (string) wp_json_encode( $form ) );
	}
}
