<?php
/**
 * The record editor's "Run an automation" section.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Editor;

use PegworkAutomation\Overrides;
use PegworkAutomation\StartRoute;
use WP_Post;

/**
 * On the block editor of a record whose type has enabled `manual` automations with a `content_type` that
 * the current user may start (StartRoute::can_run()), enqueues `automation-actions.js` with the list of
 * them. The script fills the `pegwork-editor-panel-after` slot at the end of the Pegwork Details panel
 * (docs/editor-panel.md) with one button per automation; each opens a confirmation dialog and posts to
 * StartRoute. Nothing loads on other screens.
 */
final class RecordActions {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'pegwork-automation-actions';

	/**
	 * Hooks the enqueue. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) ) ) {
			add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * Enqueues the script on a record editor with at least one automation the user may start.
	 */
	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = get_post();
		if ( null === $screen || '' === $screen->post_type || ! $post instanceof WP_Post || $post->post_type !== $screen->post_type ) {
			return;
		}
		$automations = self::automations( $post );
		$asset_file  = PEGWORK_DIR . 'assets/build/automation-actions.asset.php';
		if ( array() === $automations || ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/build/automation-actions.js', PEGWORK_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_add_inline_script( self::HANDLE, 'window.pegworkAutomationActions = ' . wp_json_encode( array( 'automations' => $automations ) ) . ';', 'before' );
		wp_set_script_translations( self::HANDLE, 'pegwork' );
	}

	/**
	 * The enabled manual automations on a record's type that the current user may start, in registry order.
	 *
	 * @param WP_Post $post Record.
	 * @return array<int, array{key: string, button: string, path: string}>
	 */
	public static function automations( WP_Post $post ): array {
		$out = array();
		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			$trigger = $definition['trigger'];
			if ( 'manual' !== $trigger['type'] || ( $trigger['content_type'] ?? null ) !== $post->post_type || ! Overrides::enabled( $definition ) ) {
				continue;
			}
			if ( ! StartRoute::can_run( (string) $key, $post->ID ) ) {
				continue;
			}
			$out[] = array(
				'key'    => (string) $key,
				'button' => (string) $trigger['button'],
				'path'   => StartRoute::path( (string) $key ),
			);
		}
		return $out;
	}
}
