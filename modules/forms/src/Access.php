<?php
/**
 * Form access rule.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * `access`: `anyone` (default) or `{ capability }`, checked for the current user on render and on submit.
 */
final class Access {

	/**
	 * Whether the current user may use a form.
	 *
	 * @param array<string, mixed> $form Canonical form.
	 */
	public static function allowed( array $form ): bool {
		$access = $form['access'] ?? 'anyone';
		if ( 'anyone' === $access ) {
			return true;
		}
		$capability = is_array( $access ) && is_string( $access['capability'] ?? null ) ? $access['capability'] : '';

		return '' !== $capability && current_user_can( $capability );
	}
}
