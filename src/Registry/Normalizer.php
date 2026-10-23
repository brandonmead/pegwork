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
	 * @param string               $kind One of `manifest`, `content_type`, `taxonomy`, `relationship`, `form`, `automation`, `options_page`.
	 * @param array<string, mixed> $data Valid object.
	 * @return array<string, mixed> Canonical object.
	 */
	public static function normalize( string $kind, array $data ): array {
		$data = match ( $kind ) {
			'content_type' => self::content_type( $data ),
			'form'         => self::form( $data ),
			'automation'   => self::automation( $data ),
			'options_page' => self::options_page( $data ),
			'taxonomy'     => self::without_empty(
				$data + array(
					'hierarchical' => false,
					'locked_terms' => false,
					'terms'        => array(),
				),
				array( 'wp' )
			),
			'relationship' => $data + array(
				'required' => false,
				'storage'  => 'meta',
			),
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

		$data['fields'] = array_map( array( self::class, 'field' ), $data['fields'] );

		if ( isset( $data['listing'] ) ) {
			$selects = array_column( $data['taxonomies'], 'select', 'key' );
			$listing = $data['listing'] + array(
				'search'  => false,
				'filters' => array(),
			);

			$listing['filters'] = array_map(
				fn( array $filter ) => $filter + array( 'multiple' => isset( $filter['taxonomy'] ) && 'multi' === ( $selects[ $filter['taxonomy'] ] ?? 'multi' ) ),
				$listing['filters']
			);
			$data['listing']    = $listing;
		}

		return self::without_empty( $data, array( 'wp' ) );
	}

	/**
	 * Fills content type and options page field defaults.
	 *
	 * @param array<string, mixed> $field Valid field.
	 * @return array<string, mixed>
	 */
	private static function field( array $field ): array {
		return self::without_empty(
			$field + array(
				'required'   => false,
				'visibility' => 'public',
			),
			array( 'validate', 'wp' )
		);
	}

	/**
	 * Fills form defaults: access, notifications, target action and status, confirmation expiry, unbound `required`.
	 *
	 * Target status defaults to `pending` for `create` only; an `update` without a status keeps the record's status.
	 *
	 * @param array<string, mixed> $data Valid form.
	 * @return array<string, mixed>
	 */
	private static function form( array $data ): array {
		$data += array(
			'access'        => 'anyone',
			'notifications' => array(),
		);

		if ( isset( $data['target'] ) ) {
			$data['target'] += array( 'action' => 'create' );
			if ( 'create' === $data['target']['action'] ) {
				$data['target'] += array( 'status' => 'pending' );
			}
		}
		if ( isset( $data['confirm'] ) ) {
			$data['confirm'] += array( 'expires_hours' => 72 );
		}

		$items = fn( array $entries ) => array_map(
			fn( array $item ) => array_key_exists( 'bind', $item ) ? $item : self::without_empty( $item + array( 'required' => false ), array( 'validate' ) ),
			$entries
		);
		if ( isset( $data['fields'] ) ) {
			$data['fields'] = $items( $data['fields'] );
		}
		if ( isset( $data['steps'] ) ) {
			$data['steps'] = array_map(
				function ( array $step ) use ( $items ) {
					$step['fields'] = $items( $step['fields'] );
					return $step;
				},
				$data['steps']
			);
		}

		return self::without_empty( $data, array( 'messages' ) );
	}

	/**
	 * Fills automation defaults: enabled, sweep status and run cap, create_record status, link confirmation.
	 *
	 * Retry defaults are the engine's policy and are not written into the definition.
	 *
	 * @param array<string, mixed> $data Valid automation.
	 * @return array<string, mixed>
	 */
	private static function automation( array $data ): array {
		$data += array( 'enabled' => true );

		if ( isset( $data['trigger']['for_each'] ) ) {
			$data['trigger']['for_each'] += array(
				'max_runs' => 200,
				'status'   => array( 'publish' ),
			);
		}

		$data['steps'] = self::steps( $data['steps'] );

		foreach ( $data['links'] ?? array() as $name => $link ) {
			$link['steps']          = self::steps( $link['steps'] );
			$data['links'][ $name ] = $link + array( 'confirm' => true );
		}

		return self::without_empty( $data, array( 'links', 'ui' ) );
	}

	/**
	 * Fills step defaults in a step list and, recursively, in its decisions' branches; drops an empty
	 * `otherwise`. List order is kept.
	 *
	 * @param list<array<string, mixed>> $steps Valid steps.
	 * @return list<array<string, mixed>>
	 */
	private static function steps( array $steps ): array {
		return array_map(
			function ( array $step ): array {
				if ( 'create_record' === $step['type'] ) {
					return $step + array( 'status' => 'draft' );
				}
				if ( 'decision' === $step['type'] ) {
					foreach ( $step['branches'] as $index => $branch ) {
						$step['branches'][ $index ]['steps'] = self::steps( $branch['steps'] );
					}
					if ( isset( $step['otherwise'] ) ) {
						$step['otherwise'] = self::steps( $step['otherwise'] );
					}
					return self::without_empty( $step, array( 'otherwise' ) );
				}
				return $step;
			},
			$steps
		);
	}

	/**
	 * Fills options page defaults: capability and field defaults. `menu_title` is resolved at runtime.
	 *
	 * @param array<string, mixed> $data Valid options page.
	 * @return array<string, mixed>
	 */
	private static function options_page( array $data ): array {
		$data += array( 'capability' => 'manage_options' );

		$data['fields'] = array_map( array( self::class, 'field' ), $data['fields'] );

		return $data;
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
