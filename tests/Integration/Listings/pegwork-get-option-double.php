<?php
/**
 * Test double for pegwork_get_option() while the options pages code is not on this branch.
 *
 * Loaded only when the function does not exist. Follows docs/content-builder-design.md: the options page key
 * is the option name, the option holds an array of values by field key, and a `post` field returns the ID.
 *
 * @package Pegwork
 */

if ( ! function_exists( 'pegwork_get_option' ) ) {
	/**
	 * A stored options page value.
	 *
	 * @param string $page  Options page key.
	 * @param string $field Field key.
	 * @return mixed The value, or null.
	 */
	function pegwork_get_option( string $page, string $field ): mixed {
		$values = get_option( $page );
		if ( ! is_array( $values ) || ! array_key_exists( $field, $values ) ) {
			return null;
		}
		return is_numeric( $values[ $field ] ) ? (int) $values[ $field ] : $values[ $field ];
	}
}
