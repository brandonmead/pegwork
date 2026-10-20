<?php
/**
 * Helpers over a canonical form definition.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Flattens and resolves items, evaluates `show_when`, and reports what this release cannot run yet.
 *
 * Bound items (`{ "bind": { "field": k } }`, `{ "bind": { "taxonomy": k } }`) resolve against the
 * target content type in the registry, so type, label, required, conditions, rules and options are
 * defined once. Only presentational keys (`label`, `help`, `placeholder`, `autocomplete`, `display`)
 * come from the form. A resolved item keeps its `bind`.
 */
final class Definition {

	/**
	 * Registry field keys a bound field item inherits.
	 */
	private const INHERITED = array( 'key', 'type', 'label', 'required', 'required_when', 'show_when', 'validate', 'options', 'default' );

	/**
	 * Presentational keys a bound item may set.
	 */
	private const PRESENTATION = array( 'label', 'help', 'placeholder', 'autocomplete', 'display' );

	/**
	 * Every item of a form in display order (steps flattened), bound items resolved.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $form ): array {
		$items = array();
		foreach ( self::sections( $form ) as $section ) {
			foreach ( $section['fields'] as $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Steps with their items resolved, or one unnamed section holding `fields`.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @return array<int, array{label: string, description: string, fields: array<int, array<string, mixed>>, step: bool}>
	 */
	public static function sections( array $form ): array {
		if ( isset( $form['fields'] ) ) {
			return array(
				array(
					'label'       => '',
					'description' => '',
					'fields'      => self::resolve_list( $form, $form['fields'] ),
					'step'        => false,
				),
			);
		}

		$sections = array();
		foreach ( $form['steps'] ?? array() as $step ) {
			$sections[] = array(
				'label'       => (string) $step['label'],
				'description' => (string) ( $step['description'] ?? '' ),
				'fields'      => self::resolve_list( $form, $step['fields'] ?? array() ),
				'step'        => true,
			);
		}
		return $sections;
	}

	/**
	 * Resolves a list of items, dropping bound items that no longer resolve.
	 *
	 * @param array<string, mixed>             $form  Canonical form.
	 * @param array<int, array<string, mixed>> $items Items as defined.
	 * @return array<int, array<string, mixed>>
	 */
	private static function resolve_list( array $form, array $items ): array {
		$resolved = array();
		foreach ( $items as $item ) {
			$item = isset( $item['bind'] ) ? self::resolve( $form, $item ) : $item;
			if ( null !== $item ) {
				$resolved[] = $item;
			}
		}
		return $resolved;
	}

	/**
	 * A bound item as a full item, or null when its field or taxonomy is not on the target type.
	 *
	 * Fields: the registry field's type and rules (a `rich_text` field is entered as plain text, type
	 * `textarea`, and written as paragraph blocks). Taxonomies: type `select` with the existing terms as
	 * options (public input never creates terms), `multiple` for multi-select taxonomies, `display`
	 * defaulting to `select` (single) or `checkboxes` (multi).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 * @param array<string, mixed> $item Bound item.
	 * @return array<string, mixed>|null
	 */
	public static function resolve( array $form, array $item ): ?array {
		$type = self::target_type( $form );
		if ( null === $type || null === pegwork_registry()->content_type( $type ) ) {
			return null;
		}
		$registry     = pegwork_registry();
		$presentation = array_intersect_key( $item, array_flip( self::PRESENTATION ) );

		if ( isset( $item['bind']['field'] ) ) {
			$field = $registry->fields( $type )[ $item['bind']['field'] ] ?? null;
			if ( null === $field ) {
				return null;
			}
			$resolved = array_intersect_key( $field, array_flip( self::INHERITED ) ) + array( 'required' => false );
			if ( 'rich_text' === $resolved['type'] ) {
				$resolved['type'] = 'textarea';
			}
			return array_merge( $resolved, $presentation, array( 'bind' => array( 'field' => $field['key'] ) ) );
		}

		$taxonomy = (string) ( $item['bind']['taxonomy'] ?? '' );
		$usage    = null;
		foreach ( $registry->content_type( $type )['taxonomies'] as $candidate ) {
			if ( $candidate['key'] === $taxonomy ) {
				$usage = $candidate;
			}
		}
		if ( null === $usage ) {
			return null;
		}
		$multiple   = 'multi' === $usage['select'];
		$definition = $registry->taxonomies()[ $taxonomy ] ?? array();
		$labels     = $definition['labels'] ?? array();

		return array_merge(
			array(
				'key'      => $taxonomy,
				'type'     => 'select',
				'label'    => (string) ( $multiple ? ( $labels['plural'] ?? $taxonomy ) : ( $labels['singular'] ?? $taxonomy ) ),
				'required' => (bool) $usage['required'],
				'options'  => self::term_options( $taxonomy, $definition['terms'] ?? array() ),
				'multiple' => $multiple,
				'display'  => $multiple ? 'checkboxes' : 'select',
			),
			$presentation,
			array( 'bind' => array( 'taxonomy' => $taxonomy ) )
		);
	}

	/**
	 * The target content type key, or null for an entry-only form.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function target_type( array $form ): ?string {
		$type = $form['target']['content_type'] ?? null;

		return is_string( $type ) && '' !== $type ? $type : null;
	}

	/**
	 * Whether a form updates an existing record (`target.action: update`).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function updates( array $form ): bool {
		return null !== self::target_type( $form ) && 'update' === ( $form['target']['action'] ?? 'create' );
	}

	/**
	 * Existing terms of a taxonomy as select options (value: slug, label: name): the registry's term
	 * order first, then any other terms by name.
	 *
	 * @param string                           $taxonomy Taxonomy key.
	 * @param array<int, array<string, mixed>> $defined  Registry terms (`slug`, `name`).
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function term_options( string $taxonomy, array $defined ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$by_slug = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_slug[ $term->slug ] = $term->name;
			}
		}

		$options = array();
		foreach ( $defined as $term ) {
			$slug = (string) ( $term['slug'] ?? '' );
			if ( isset( $by_slug[ $slug ] ) ) {
				$options[] = array(
					'value' => $slug,
					'label' => $by_slug[ $slug ],
				);
				unset( $by_slug[ $slug ] );
			}
		}
		foreach ( $by_slug as $slug => $name ) {
			$options[] = array(
				'value' => (string) $slug,
				'label' => $name,
			);
		}

		return $options;
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
	 * Why a form cannot run on this site, or null when it can.
	 *
	 * Such forms fail closed: they render nothing for visitors and their submissions are ignored. A
	 * Turnstile form without both wp-config keys, and a target type missing from the registry (the
	 * registry validator prevents this), fail closed.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function unsupported( array $form ): ?string {
		if ( isset( $form['captcha'] ) && ( ! Turnstile::enabled( $form ) || null === Turnstile::keys() ) ) {
			return __( 'it uses Cloudflare Turnstile, and PEGWORK_TURNSTILE_SITE_KEY and PEGWORK_TURNSTILE_SECRET_KEY are not both set in wp-config.php', 'pegwork' );
		}
		$type = self::target_type( $form );
		if ( null !== $type && null === pegwork_registry()->content_type( $type ) ) {
			/* translators: %s: content type key. */
			return sprintf( __( 'its target content type "%s" is not defined', 'pegwork' ), $type );
		}
		return null;
	}

	/**
	 * Whether a form has a file item (the form then posts `multipart/form-data`).
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function has_files( array $form ): bool {
		foreach ( self::items( $form ) as $item ) {
			if ( 'file' === ( $item['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
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

		if ( is_array( $value ) ) {
			if ( is_bool( $equals ) ) {
				return ( array() !== $value ) === $equals;
			}
			return in_array( (string) $equals, array_map( 'strval', $value ), true );
		}
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
