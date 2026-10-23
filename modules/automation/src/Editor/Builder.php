<?php
/**
 * Maps an automation definition to builder blocks and back.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Editor;

/**
 * The builder edits a `pegwork_automation` post whose blocks map 1:1 to the step tree: each
 * `pegwork/automation-step` block's attributes are one step, each `pegwork/automation-decision` block
 * is a decision (attribute `id`) whose inner blocks are its branches, and each
 * `pegwork/automation-branch` block is a branch (`label`, `when`) or, with the editor-only attribute
 * `otherwise`, the decision's otherwise list; a branch's inner blocks are its steps. Each
 * `pegwork/automation-loop` block is a loop (`id`, `over`) whose inner blocks are its body. The post title is
 * the label. Everything else (key, description, enabled, trigger, idempotency, links, ui) is the
 * settings object, stored as JSON in post meta and preserved as it is.
 *
 * `generate()` and `compile()` are inverses for every valid definition
 * (tests/Integration/Automation/AutomationBuilderRoundTripTest.php). Mechanical: no registry lookups,
 * no generated step ids, and an empty `otherwise` is omitted.
 */
final class Builder {

	/**
	 * Step block name.
	 */
	public const STEP = 'pegwork/automation-step';

	/**
	 * Decision block name.
	 */
	public const DECISION = 'pegwork/automation-decision';

	/**
	 * Branch block name.
	 */
	public const BRANCH = 'pegwork/automation-branch';

	/**
	 * Loop block name.
	 */
	public const LOOP = 'pegwork/automation-loop';

	/**
	 * Loop keys other than `type` and `steps` (`blocks/automation-loop/block.json`).
	 */
	public const LOOP_ATTRIBUTES = array( 'id', 'over' );

	/**
	 * Step keys, in block attribute order (`blocks/automation-step/block.json`).
	 */
	public const STEP_ATTRIBUTES = array( 'id', 'type', 'to', 'reply_to', 'subject', 'text', 'html', 'record', 'content_type', 'status', 'values', 'url', 'fields', 'timeout', 'for', 'when', 'retry', 'related', 'filter', 'sort', 'limit', 'sum', 'relationship', 'action', 'from', 'on_error' );

	/**
	 * Decision keys other than `type`, `branches` and `otherwise` (`blocks/automation-decision/block.json`).
	 */
	public const DECISION_ATTRIBUTES = array( 'id' );

	/**
	 * Branch keys other than `steps` (`blocks/automation-branch/block.json`; `otherwise` is editor-only).
	 */
	public const BRANCH_ATTRIBUTES = array( 'label', 'when' );

	/**
	 * Attributes of an otherwise branch block: the editor-only flag, and a lock that keeps the branch
	 * in place (the builder keeps it last and offers Add and Remove otherwise on the toolbar).
	 */
	public const OTHERWISE = array(
		'otherwise' => true,
		'lock'      => array(
			'move'   => true,
			'remove' => true,
		),
	);

	/**
	 * Attributes the block editor adds to every block; never part of the definition.
	 */
	private const EDITOR_ATTRIBUTES = array( 'lock', 'metadata', 'className' );

	/**
	 * Definition keys that live in the post itself rather than in the settings object.
	 */
	private const POST_KEYS = array( 'format', 'label', 'steps' );

	/**
	 * Post title, block markup and settings for a definition.
	 *
	 * @param array<string, mixed> $automation Automation definition (canonical or as written).
	 * @return array{title: string, content: string, settings: array<string, mixed>}
	 */
	public static function generate( array $automation ): array {
		$steps = is_array( $automation['steps'] ?? null ) ? $automation['steps'] : array();

		return array(
			'title'    => (string) ( $automation['label'] ?? '' ),
			'content'  => implode( "\n\n", array_map( 'serialize_block', self::step_blocks( $steps ) ) ),
			'settings' => array_diff_key( $automation, array_flip( self::POST_KEYS ) ),
		);
	}

	/**
	 * The definition a builder post describes, or the problems found.
	 *
	 * Errors are keyed by JSON pointer into the definition, like the registry validator's, so the
	 * editor can show each one at its block or setting.
	 *
	 * @param string              $title    Post title (the label).
	 * @param string              $content  Post content (block markup).
	 * @param array<mixed>|string $settings Settings object, or its JSON.
	 * @param string              $key      Locked key (the post's saved automation key), or '' for a new one.
	 * @return array{definition: array<string, mixed>, errors: array<string, string>}
	 */
	public static function compile( string $title, string $content, array|string $settings, string $key = '' ): array {
		$errors = array();
		if ( is_string( $settings ) ) {
			$decoded  = '' === trim( $settings ) ? array() : json_decode( $settings, true );
			$settings = is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : ( array() === $decoded ? array() : null );
			if ( null === $settings ) {
				$errors['/'] = __( 'The automation settings could not be read. Reload the editor and try again.', 'pegwork' );
				$settings    = array();
			}
		}
		$settings = array_diff_key( $settings, array_flip( self::POST_KEYS ) );

		$title = trim( $title );
		if ( '' === $title ) {
			$errors['/label'] = __( 'Add an automation title. It is the automation label.', 'pegwork' );
		}

		if ( '' === $key ) {
			$key = is_string( $settings['key'] ?? null ) && '' !== trim( $settings['key'] ) ? trim( $settings['key'] ) : self::slug( $title );
		}
		unset( $settings['key'] );
		if ( '' === $key ) {
			$errors['/key'] = __( 'Add an automation key: lowercase letters, digits and underscores, starting with a letter.', 'pegwork' );
		}

		$steps = self::steps( parse_blocks( $content ), '/steps', $errors );
		if ( array() === $steps && ! isset( $errors['/steps'] ) ) {
			$errors['/steps'] = __( 'Add at least one step.', 'pegwork' );
		}

		return array(
			'definition' => array(
				'format' => 1,
				'key'    => $key,
				'label'  => $title,
			) + $settings + array( 'steps' => $steps ),
			'errors'     => $errors,
		);
	}

	/**
	 * Hash of a definition, to tell whether a post still matches the registry.
	 *
	 * @param array<string, mixed> $automation Canonical automation.
	 */
	public static function hash( array $automation ): string {
		return hash( 'sha256', (string) wp_json_encode( $automation ) );
	}

	/**
	 * An automation key from a label: lowercase ASCII letters, digits and underscores, starting with a
	 * letter, at most 64 characters. '' when nothing usable remains.
	 *
	 * @param string $label Label.
	 */
	public static function slug( string $label ): string {
		$slug = strtolower( remove_accents( $label ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $slug ), '_' );
		if ( '' !== $slug && ! ctype_alpha( $slug[0] ) ) {
			$slug = 'a_' . $slug;
		}

		return rtrim( substr( $slug, 0, 64 ), '_' );
	}

	/**
	 * A step list from parsed blocks. Blocks that cannot be steps are reported at the list's pointer and
	 * skipped, so indexes count only steps and decisions (whitespace between blocks is ignored).
	 *
	 * @param array<int, array<string, mixed>> $parsed  Parsed blocks.
	 * @param string                           $pointer Pointer of the list.
	 * @param array<string, string>            $errors  Errors, by pointer (appended).
	 * @return list<array<string, mixed>>
	 */
	private static function steps( array $parsed, string $pointer, array &$errors ): array {
		$steps = array();
		foreach ( $parsed as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$errors[ $pointer ] = __( 'An automation can hold only steps and decisions. Remove other content.', 'pegwork' );
				}
				continue;
			}
			if ( self::BRANCH === $name ) {
				$errors[ $pointer ] = __( 'A branch must be inside a decision.', 'pegwork' );
				continue;
			}
			$at = $pointer . '/' . count( $steps );
			if ( self::STEP === $name ) {
				$steps[] = self::attributes( $block, self::STEP_ATTRIBUTES );
			} elseif ( self::DECISION === $name ) {
				$steps[] = self::decision( $block, $at, $errors );
			} elseif ( self::LOOP === $name ) {
				$loop    = self::attributes( $block, self::LOOP_ATTRIBUTES );
				$steps[] = array_intersect_key( $loop, array( 'id' => true ) ) + array( 'type' => 'loop' ) + $loop + array( 'steps' => self::steps( $block['innerBlocks'] ?? array(), $at . '/steps', $errors ) );
			} else {
				$errors[ $pointer ] = __( 'An automation can hold only steps and decisions. Remove other content.', 'pegwork' );
			}
		}
		return $steps;
	}

	/**
	 * A decision from its block: branches from branch blocks, the otherwise branch last.
	 *
	 * @param array<string, mixed>  $block   Parsed decision block.
	 * @param string                $pointer Pointer of the decision.
	 * @param array<string, string> $errors  Errors, by pointer (appended).
	 * @return array<string, mixed>
	 */
	private static function decision( array $block, string $pointer, array &$errors ): array {
		$decision  = self::attributes( $block, self::DECISION_ATTRIBUTES );
		$decision  = array_intersect_key( $decision, array( 'id' => true ) ) + array( 'type' => 'decision' ) + $decision;
		$branches  = array();
		$otherwise = null;
		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			$name = $inner['blockName'] ?? null;
			if ( null === $name ) {
				continue;
			}
			if ( self::BRANCH !== $name ) {
				$errors[ $pointer . '/branches' ] = __( 'A decision can hold only branches. Put steps inside a branch.', 'pegwork' );
				continue;
			}
			$attrs = is_array( $inner['attrs'] ?? null ) ? $inner['attrs'] : array();
			if ( true === ( $attrs['otherwise'] ?? false ) ) {
				if ( null !== $otherwise ) {
					$errors[ $pointer . '/otherwise' ] = __( 'A decision can have only one Otherwise branch.', 'pegwork' );
					continue;
				}
				$otherwise = self::steps( $inner['innerBlocks'] ?? array(), $pointer . '/otherwise', $errors );
				continue;
			}
			if ( null !== $otherwise ) {
				$errors[ $pointer . '/otherwise' ] = __( 'The Otherwise branch must come last.', 'pegwork' );
			}
			$at         = $pointer . '/branches/' . count( $branches );
			$branch     = self::attributes( $inner, self::BRANCH_ATTRIBUTES );
			$branches[] = $branch + array( 'steps' => self::steps( $inner['innerBlocks'] ?? array(), $at . '/steps', $errors ) );
		}

		$decision['branches'] = $branches;
		if ( null !== $otherwise && array() !== $otherwise ) {
			$decision['otherwise'] = $otherwise;
		}
		return $decision;
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
		$attrs = array_diff_key( $attrs, array_flip( array_merge( self::EDITOR_ATTRIBUTES, array( 'otherwise' ) ) ) );
		$attrs = array_filter( $attrs, fn( $value ) => null !== $value );

		// Known attributes first, in schema order; unknown ones after, so the validator names them.
		return array_merge( array_intersect_key( array_flip( $known ), $attrs ), $attrs );
	}

	/**
	 * Blocks for a step list.
	 *
	 * @param array<int, mixed> $steps Steps.
	 * @return list<array<string, mixed>> Blocks in parse_blocks() shape.
	 */
	private static function step_blocks( array $steps ): array {
		$blocks = array();
		foreach ( $steps as $step ) {
			$step     = is_array( $step ) ? $step : array();
			$type     = $step['type'] ?? null;
			$blocks[] = match ( true ) {
				'decision' === $type => self::decision_block( $step ),
				'loop' === $type     => self::block(
					self::LOOP,
					self::ordered( array_diff_key( $step, array_flip( array( 'type', 'steps' ) ) ), self::LOOP_ATTRIBUTES ),
					self::step_blocks( is_array( $step['steps'] ?? null ) ? $step['steps'] : array() )
				),
				default              => self::block( self::STEP, self::ordered( $step, self::STEP_ATTRIBUTES ) ),
			};
		}
		return $blocks;
	}

	/**
	 * A decision block: its branches, then an otherwise branch when the otherwise list has steps.
	 *
	 * @param array<string, mixed> $step Decision step.
	 * @return array<string, mixed> Block in parse_blocks() shape.
	 */
	private static function decision_block( array $step ): array {
		$inner = array();
		foreach ( is_array( $step['branches'] ?? null ) ? $step['branches'] : array() as $branch ) {
			$branch  = is_array( $branch ) ? $branch : array();
			$steps   = is_array( $branch['steps'] ?? null ) ? $branch['steps'] : array();
			$inner[] = self::block( self::BRANCH, self::ordered( array_diff_key( $branch, array( 'steps' => true ) ), self::BRANCH_ATTRIBUTES ), self::step_blocks( $steps ) );
		}
		if ( is_array( $step['otherwise'] ?? null ) && array() !== $step['otherwise'] ) {
			$inner[] = self::block( self::BRANCH, self::OTHERWISE, self::step_blocks( $step['otherwise'] ) );
		}
		$attrs = array_diff_key( $step, array_flip( array( 'type', 'branches', 'otherwise' ) ) );

		return self::block( self::DECISION, self::ordered( $attrs, self::DECISION_ATTRIBUTES ), $inner );
	}

	/**
	 * A block in parse_blocks() shape, inner blocks on their own lines.
	 *
	 * @param string                     $name  Block name.
	 * @param array<string, mixed>       $attrs Attributes.
	 * @param list<array<string, mixed>> $inner Inner blocks.
	 * @return array<string, mixed>
	 */
	private static function block( string $name, array $attrs, array $inner = array() ): array {
		$content = array();
		if ( array() !== $inner ) {
			$content[] = "\n";
			foreach ( array_keys( $inner ) as $index ) {
				if ( $index > 0 ) {
					$content[] = "\n\n";
				}
				$content[] = null;
			}
			$content[] = "\n";
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
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
