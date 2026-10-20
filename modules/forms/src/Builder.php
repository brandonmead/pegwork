<?php
/**
 * Maps a form definition to builder blocks and back.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * The builder edits a `pegwork_form` post whose blocks map 1:1 to the definition: each
 * `pegwork/form-field` block's attributes are one item, each `pegwork/form-step` block's attributes
 * are one step (its inner blocks the step's items), and the post title is the label. Everything else
 * (key, target, access, confirm, captcha, notifications, messages, retention_days) is the settings
 * object, stored as JSON in post meta. `generate()` and `compile()` are inverses for every valid
 * definition (tests/Integration/Forms/FormBuilderRoundTripTest.php). Mechanical: no registry lookups,
 * so bound items keep only their `bind` and presentational keys.
 */
final class Builder {

	/**
	 * Field block name.
	 */
	public const FIELD = 'pegwork/form-field';

	/**
	 * Step block name.
	 */
	public const STEP = 'pegwork/form-step';

	/**
	 * Item keys, in block attribute order (`blocks/form-field/block.json`).
	 */
	public const FIELD_ATTRIBUTES = array( 'bind', 'key', 'type', 'label', 'required', 'default', 'options', 'required_when', 'show_when', 'validate', 'help', 'placeholder', 'autocomplete', 'display', 'accept', 'max_size_mb' );

	/**
	 * Step keys other than `fields`, in block attribute order (`blocks/form-step/block.json`).
	 */
	public const STEP_ATTRIBUTES = array( 'key', 'label', 'description' );

	/**
	 * Attributes the block editor adds to every block; never part of the definition.
	 */
	private const EDITOR_ATTRIBUTES = array( 'lock', 'metadata', 'className' );

	/**
	 * Definition keys that live in the post itself rather than in the settings object.
	 */
	private const POST_KEYS = array( 'format', 'label', 'fields', 'steps' );

	/**
	 * Post title, block markup and settings for a definition.
	 *
	 * @param array<string, mixed> $form Form definition (canonical or as written).
	 * @return array{title: string, content: string, settings: array<string, mixed>}
	 */
	public static function generate( array $form ): array {
		$blocks = array();
		if ( isset( $form['steps'] ) && is_array( $form['steps'] ) ) {
			foreach ( $form['steps'] as $step ) {
				$blocks[] = self::step_block( is_array( $step ) ? $step : array() );
			}
		} else {
			foreach ( ( is_array( $form['fields'] ?? null ) ? $form['fields'] : array() ) as $item ) {
				$blocks[] = self::field_block( is_array( $item ) ? $item : array() );
			}
		}

		return array(
			'title'    => (string) ( $form['label'] ?? '' ),
			'content'  => implode( "\n\n", array_map( 'serialize_block', $blocks ) ),
			'settings' => array_diff_key( $form, array_flip( self::POST_KEYS ) ),
		);
	}

	/**
	 * The definition a builder post describes, or the problems found.
	 *
	 * Missing item and step keys are filled from their labels (the editor does the same as you type).
	 * Errors are keyed by JSON pointer into the definition, like the registry validator's, so the editor
	 * can show each one at its block or setting.
	 *
	 * @param string              $title    Post title (the label).
	 * @param string              $content  Post content (block markup).
	 * @param array<mixed>|string $settings Settings object, or its JSON.
	 * @param string              $key      Locked key (the post's saved form key), or '' for a new form.
	 * @return array{definition: array<string, mixed>, errors: array<string, string>}
	 */
	public static function compile( string $title, string $content, array|string $settings, string $key = '' ): array {
		$errors = array();
		if ( is_string( $settings ) ) {
			$decoded  = '' === trim( $settings ) ? array() : json_decode( $settings, true );
			$settings = is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : ( array() === $decoded ? array() : null );
			if ( null === $settings ) {
				$errors['/'] = __( 'The form settings could not be read. Reload the editor and try again.', 'pegwork' );
				$settings    = array();
			}
		}
		$settings = array_diff_key( $settings, array_flip( self::POST_KEYS ) );

		$title = trim( $title );
		if ( '' === $title ) {
			$errors['/label'] = __( 'Add a form title. It is the form label.', 'pegwork' );
		}

		if ( '' === $key ) {
			$key = is_string( $settings['key'] ?? null ) && '' !== trim( $settings['key'] ) ? trim( $settings['key'] ) : self::slug( $title );
		}
		unset( $settings['key'] );
		if ( '' === $key ) {
			$errors['/key'] = __( 'Add a form key: lowercase letters, digits and underscores, starting with a letter.', 'pegwork' );
		}

		$definition = array(
			'format' => 1,
			'key'    => $key,
			'label'  => $title,
		) + $settings;

		$top   = self::blocks( parse_blocks( $content ), '', $errors );
		$steps = array_filter( $top, fn( array $block ) => self::STEP === $block['blockName'] );

		if ( array() === $top ) {
			$errors['/fields'] = __( 'Add at least one field.', 'pegwork' );
			$definition       += array( 'fields' => array() );
		} elseif ( array() === $steps ) {
			$definition['fields'] = self::items( $top, '/fields', $errors );
		} elseif ( count( $steps ) !== count( $top ) ) {
			$errors['/steps'] = __( 'Put every field inside a step, or remove the steps.', 'pegwork' );
			$definition      += array( 'steps' => array() );
		} else {
			$definition['steps'] = array();
			foreach ( array_values( $top ) as $index => $block ) {
				$pointer = '/steps/' . $index;
				$step    = self::attributes( $block, self::STEP_ATTRIBUTES );
				if ( ! isset( $step['key'] ) || '' === $step['key'] ) {
					$step = array( 'key' => self::slug( (string) ( $step['label'] ?? '' ) ) ) + $step;
				}
				$inner                         = self::blocks( $block['innerBlocks'] ?? array(), $pointer . '/fields', $errors );
				$step['fields']                = self::items( $inner, $pointer . '/fields', $errors );
				$definition['steps'][ $index ] = $step;
			}
		}

		return array(
			'definition' => $definition,
			'errors'     => $errors,
		);
	}

	/**
	 * A form key from a label: lowercase ASCII letters, digits and underscores, starting with a letter,
	 * at most 64 characters. '' when nothing usable remains. Same rule as the editor's `slugify()`.
	 *
	 * @param string $label Label.
	 */
	public static function slug( string $label ): string {
		$slug = strtolower( remove_accents( $label ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $slug ), '_' );
		if ( '' !== $slug && ! ctype_alpha( $slug[0] ) ) {
			$slug = 'f_' . $slug;
		}

		return rtrim( substr( $slug, 0, 64 ), '_' );
	}

	/**
	 * Pegwork blocks among parsed blocks; anything else is reported (whitespace between blocks is ignored).
	 *
	 * @param array<int, array<string, mixed>> $parsed  Parsed blocks.
	 * @param string                           $pointer Pointer of the list the blocks belong to ('' for the top).
	 * @param array<string, string>            $errors  Errors, by pointer (appended).
	 * @return array<int, array<string, mixed>>
	 */
	private static function blocks( array $parsed, string $pointer, array &$errors ): array {
		$blocks = array();
		foreach ( $parsed as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$errors[ '' === $pointer ? '/' : $pointer ] = __( 'A form can hold only form fields and steps. Remove other content.', 'pegwork' );
				}
				continue;
			}
			if ( self::FIELD !== $name && self::STEP !== $name ) {
				$errors[ '' === $pointer ? '/' : $pointer ] = __( 'A form can hold only form fields and steps. Remove other content.', 'pegwork' );
				continue;
			}
			$blocks[] = $block;
		}
		return $blocks;
	}

	/**
	 * Items from field blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks  Field blocks.
	 * @param string                           $pointer Pointer of the list.
	 * @param array<string, string>            $errors  Errors, by pointer (appended).
	 * @return array<int, array<string, mixed>>
	 */
	private static function items( array $blocks, string $pointer, array &$errors ): array {
		$items = array();
		foreach ( array_values( $blocks ) as $index => $block ) {
			if ( self::FIELD !== $block['blockName'] ) {
				$errors[ $pointer . '/' . $index ] = __( 'Steps cannot be nested.', 'pegwork' );
				continue;
			}
			$item = self::attributes( $block, self::FIELD_ATTRIBUTES );
			if ( isset( $item['bind'] ) ) {
				if ( '' === ( $item['label'] ?? null ) ) {
					unset( $item['label'] );
				}
			} else {
				if ( ! isset( $item['label'] ) || '' === trim( (string) $item['label'] ) ) {
					$errors[ $pointer . '/' . $index . '/label' ] = __( 'Add a label.', 'pegwork' );
				}
				if ( ! isset( $item['key'] ) || '' === $item['key'] ) {
					$item = array( 'key' => self::slug( (string) ( $item['label'] ?? '' ) ) ) + $item;
				}
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * A block's definition attributes: editor-only attributes dropped, nulls dropped, order kept.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param string[]             $known Attribute names in block.json order.
	 * @return array<string, mixed>
	 */
	private static function attributes( array $block, array $known ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$attrs = array_diff_key( $attrs, array_flip( self::EDITOR_ATTRIBUTES ) );
		$attrs = array_filter( $attrs, fn( $value ) => null !== $value );

		// Known attributes first, in schema order; unknown ones after, so the validator names them.
		return array_merge( array_intersect_key( array_flip( $known ), $attrs ), $attrs );
	}

	/**
	 * A field block for an item.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed> Block in parse_blocks() shape.
	 */
	private static function field_block( array $item ): array {
		return array(
			'blockName'    => self::FIELD,
			'attrs'        => self::ordered( $item, self::FIELD_ATTRIBUTES ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	/**
	 * A step block for a step, its items as inner blocks.
	 *
	 * @param array<string, mixed> $step Step.
	 * @return array<string, mixed> Block in parse_blocks() shape.
	 */
	private static function step_block( array $step ): array {
		$inner   = array_map( array( self::class, 'field_block' ), array_map( fn( $item ) => is_array( $item ) ? $item : array(), is_array( $step['fields'] ?? null ) ? $step['fields'] : array() ) );
		$content = array( "\n" );
		foreach ( array_keys( $inner ) as $index ) {
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}
			$content[] = null;
		}
		$content[] = "\n";
		unset( $step['fields'] );

		return array(
			'blockName'    => self::STEP,
			'attrs'        => self::ordered( $step, self::STEP_ATTRIBUTES ),
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $content, 'is_string' ) ),
			'innerContent' => $content,
		);
	}

	/**
	 * Values in block attribute order; unknown keys kept after.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string[]             $order  Attribute names.
	 * @return array<string, mixed>
	 */
	private static function ordered( array $values, array $order ): array {
		$sorted = array();
		foreach ( $order as $name ) {
			if ( array_key_exists( $name, $values ) ) {
				$sorted[ $name ] = $values[ $name ];
			}
		}
		return $sorted + $values;
	}
}
