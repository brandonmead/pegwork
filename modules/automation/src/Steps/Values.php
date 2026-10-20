<?php
/**
 * Renders a step's `values` map.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Templates;

/**
 * Turns `values` (literals or templates) into Records API input for a content type. Field values are
 * cast and validated by `Pegwork\Api\Records`; taxonomy values become slug lists here.
 */
final class Values {

	/**
	 * Rendered values by key.
	 *
	 * @param array<string, mixed> $values  Values map from the definition.
	 * @param Context              $context Run context.
	 * @param string               $type    Target content type key.
	 * @return array<string, mixed>
	 */
	public static function render( array $values, Context $context, string $type ): array {
		$definition = pegwork_registry()->content_type( $type );
		$taxonomies = array_column( $definition['taxonomies'] ?? array(), 'key' );
		$out        = array();

		foreach ( $values as $key => $value ) {
			$rendered = Templates::value( $value, $context );
			if ( in_array( $key, $taxonomies, true ) ) {
				if ( ! is_array( $rendered ) ) {
					$rendered = array_values( array_filter( array_map( 'trim', explode( ',', (string) $rendered ) ), 'strlen' ) );
				}
			} elseif ( is_array( $rendered ) ) {
				// No field type holds a list: a taxonomy copied into a field (`paused_from`) keeps its slugs as text.
				$rendered = implode( ',', array_map( fn( $item ) => is_scalar( $item ) ? (string) $item : '', $rendered ) );
			}
			$out[ (string) $key ] = $rendered;
		}
		return $out;
	}
}
