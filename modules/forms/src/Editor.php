<?php
/**
 * Block editor script for the forms module.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Enqueues the pegwork/form edit UI (a form picker plus the server-rendered preview) with the list of
 * forms (key, label, and whether this release can show it). Labels only; no field data.
 */
final class Editor {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'pegwork-forms-editor';

	/**
	 * Registers the hook. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Enqueues the script in every block editor when it is built.
	 */
	public static function enqueue(): void {
		$asset_file = PEGWORK_DIR . 'assets/build/forms-editor.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/forms-editor.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkForms = ' . wp_json_encode( self::config() ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * The editor config.
	 *
	 * @return array{forms: array<int, array{key: string, label: string, supported: bool}>}
	 */
	public static function config(): array {
		$forms = array();
		foreach ( pegwork_registry()->forms() as $key => $form ) {
			$forms[] = array(
				'key'       => (string) $key,
				'label'     => (string) $form['label'],
				'supported' => null === Definition::unsupported( $form ),
			);
		}

		return array( 'forms' => $forms );
	}
}
