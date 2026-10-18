<?php
/**
 * The pegwork/form placement block.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

use WP_Block_Type_Registry;

/**
 * `<!-- wp:pegwork/form {"form":"<key>"} /-->` renders the registry form with that key, server side
 * (in the editor too, through the block renderer). Registered only while the module is enabled.
 */
final class Block {

	/**
	 * Block name.
	 */
	public const NAME = 'pegwork/form';

	/**
	 * Registers the block type. Safe to call more than once.
	 */
	public static function register(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}

		register_block_type(
			PEGWORK_FORMS_DIR . 'blocks/form',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string Form markup, or ''.
	 */
	public static function render( $attributes ): string {
		$key = is_array( $attributes ) && is_string( $attributes['form'] ?? null ) ? $attributes['form'] : '';
		if ( '' !== $key && ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) ) {
			$key = '';
		}

		return Renderer::render( $key, time() );
	}
}
