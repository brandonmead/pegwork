<?php
/**
 * Schema normalizer: produces the canonical form of a valid registry object.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry;

/**
 * Fills defaults, drops empty optional objects and sorts object keys. List order is kept.
 *
 * The output is canonical: normalizing it again returns it unchanged, and export (PR 6) writes it.
 */
final class Normalizer {

	/**
	 * Normalizes one object that passed validation.
	 *
	 * @param string               $kind One of `manifest`, `content_type`, `taxonomy`, `relationship`.
	 * @param array<string, mixed> $data Valid object.
	 * @return array<string, mixed> Canonical object.
	 */
	public static function normalize( string $kind, array $data ): array {
		$data = match ( $kind ) {
			'content_type' => self::content_type( $data ),
			'taxonomy'     => self::without_empty(
				$data + array(
					'hierarchical' => false,
					'locked_terms' => false,
					'terms'        => array(),
				),
				array( 'wp' )
			),
			'relationship' => $data + array( 'storage' => 'meta' ),
			default        => self::without_empty( $data, array( 'requires' ) ),
		};

		return self::sort( $data );
	}

	/**
	 * Fills content type, field and taxonomy usage defaults.
	 *
	 * @param array<string, mixed> $data Valid content type.
	 * @return array<string, mixed>
	 */
	private static function content_type( array $data ): array {
		$data += array(
			'public'     => true,
			'taxonomies' => array(),
			'fields'     => array(),
		);

		$data['taxonomies'] = array_map(
			fn( array $usage ) => $usage + array(
				'select'   => 'multi',
				'required' => false,
			),
			$data['taxonomies']
		);

		$data['fields'] = array_map(
			fn( array $field ) => self::without_empty(
				$field + array(
					'required'   => false,
					'visibility' => 'public',
				),
				array( 'validate', 'wp' )
			),
			$data['fields']
		);

		return self::without_empty( $data, array( 'wp' ) );
	}

	/**
	 * Removes optional object properties that are empty, so the canonical form never holds `{}`.
	 *
	 * @param array<string, mixed> $data  Object.
	 * @param string[]             $names Optional object properties.
	 * @return array<string, mixed>
	 */
	private static function without_empty( array $data, array $names ): array {
		foreach ( $names as $name ) {
			if ( array() === ( $data[ $name ] ?? null ) ) {
				unset( $data[ $name ] );
			}
		}

		return $data;
	}

	/**
	 * Sorts object keys recursively; list order is kept.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private static function sort( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( self::class, 'sort' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}

		return $value;
	}
}
