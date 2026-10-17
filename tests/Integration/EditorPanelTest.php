<?php
/**
 * Integration test of the editor panel hooks.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\EditorPanel;
use WP_UnitTestCase;

/**
 * The panel hooks are wired and stay inert without a config.
 */
class EditorPanelTest extends WP_UnitTestCase {

	/**
	 * Resets the screen and the panel script.
	 */
	public function tear_down() {
		wp_dequeue_script( EditorPanel::HANDLE );
		wp_deregister_script( EditorPanel::HANDLE );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Boot registered the enqueue action and the allowed-blocks filter.
	 */
	public function test_hooks_are_registered() {
		$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', array( EditorPanel::class, 'enqueue' ) ) );
		$this->assertSame( 10, has_filter( 'allowed_block_types_all', array( EditorPanel::class, 'allowed_block_types' ) ) );
	}

	/**
	 * With no content types in the config, nothing is enqueued.
	 */
	public function test_enqueues_nothing_without_config() {
		set_current_screen( 'post' );

		EditorPanel::enqueue();

		$this->assertFalse( wp_script_is( EditorPanel::HANDLE, 'enqueued' ) );
	}

	/**
	 * Without the registry, allowed blocks pass through unchanged.
	 */
	public function test_allowed_blocks_unchanged_without_registry() {
		if ( function_exists( 'pegwork_registry' ) ) {
			$this->markTestSkipped( 'Registry present; covered by the unit test of allowed_blocks_for().' );
		}

		$post    = self::factory()->post->create_and_get();
		$context = new \WP_Block_Editor_Context( array( 'post' => $post ) );

		$this->assertTrue( EditorPanel::allowed_block_types( true, $context ) );
		$this->assertSame( array( 'core/image' ), EditorPanel::allowed_block_types( array( 'core/image' ), $context ) );
	}

	/**
	 * The pegwork_editor_panel_config filter adjusts the config.
	 */
	public function test_config_filter() {
		if ( ! function_exists( 'pegwork_registry' ) ) {
			$this->assertSame( array( 'types' => array() ), EditorPanel::config() );
			return;
		}

		$extra = array(
			'fields'     => array(),
			'taxonomies' => array(),
		);
		$add   = static function ( $config ) use ( $extra ) {
			$config['types']['pegwork_test'] = $extra;
			return $config;
		};
		add_filter( 'pegwork_editor_panel_config', $add );

		$config = EditorPanel::config();
		remove_filter( 'pegwork_editor_panel_config', $add );

		$this->assertSame( $extra, $config['types']['pegwork_test'] );
	}
}
