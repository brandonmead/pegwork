<?php
/**
 * Admin notices for schema errors.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Admin;

use Pegwork\Plugin;

/**
 * Shows registry load errors to administrators.
 */
final class Notices {

	/**
	 * Errors listed in the notice; the rest are counted and left to the CLI.
	 */
	private const MAX_LISTED = 10;

	/**
	 * Prints the schema error notice, if any. Hooked to admin_notices.
	 */
	public static function render(): void {
		$errors = Plugin::result()->errors;
		if ( ! $errors || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: number of errors. */
				_n(
					'Pegwork found %d schema error. Packages with errors are not registered.',
					'Pegwork found %d schema errors. Packages with errors are not registered.',
					count( $errors ),
					'pegwork'
				),
				count( $errors )
			)
		);
		echo '</p><ul>';
		foreach ( array_slice( $errors, 0, self::MAX_LISTED ) as $error ) {
			echo '<li><code>' . esc_html( (string) $error ) . '</code></li>';
		}
		echo '</ul><p>' . esc_html__( 'Run "wp pegwork schema validate" for the full list.', 'pegwork' ) . '</p></div>';
	}
}
