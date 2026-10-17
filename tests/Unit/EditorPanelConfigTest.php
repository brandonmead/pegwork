<?php
/**
 * Unit test of the editor panel config builder and allowed-blocks logic.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit;

use Pegwork\Admin\EditorPanel;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests EditorPanel::build_config() and EditorPanel::allowed_blocks_for().
 *
 * The fixtures are shared with the JS unit tests, so both sides hold to one contract.
 */
class EditorPanelConfigTest extends TestCase {

	/**
	 * Decodes a fixture from tests/fixtures/editor-panel.
	 *
	 * @param string $name File name.
	 * @return array<string, mixed> Decoded JSON.
	 */
	private function fixture( $name ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		return json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/editor-panel/' . $name ), true );
	}

	/**
	 * The sifg_project example builds the documented config.
	 */
	public function test_builds_sifg_project_config() {
		$registry = $this->fixture( 'registry.json' );

		$this->assertSame(
			$this->fixture( 'config.json' ),
			EditorPanel::build_config( $registry['content_types'], $registry['taxonomies'] )
		);
	}

	/**
	 * A list of content types (not keyed) uses each type's own key.
	 */
	public function test_uses_type_key_for_lists() {
		$config = EditorPanel::build_config(
			array(
				array(
					'key'    => 'book',
					'fields' => array(
						array(
							'key'  => 'isbn',
							'type' => 'text',
						),
					),
				),
			)
		);

		$this->assertSame( array( 'book' ), array_keys( $config['types'] ) );
		$this->assertSame( '_book_isbn', $config['types']['book']['fields'][0]['meta_key'] );
		$this->assertSame( 'isbn', $config['types']['book']['fields'][0]['label'] );
		$this->assertSame( array(), $config['types']['book']['taxonomies'] );
	}

	/**
	 * A `wp.meta_key` override replaces the default meta key.
	 */
	public function test_meta_key_override() {
		$config = EditorPanel::build_config(
			array(
				'book' => array(
					'key'    => 'book',
					'fields' => array(
						array(
							'key'  => 'isbn',
							'type' => 'text',
							'wp'   => array( 'meta_key' => 'legacy_isbn' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Fixture, not a query.
						),
					),
				),
			)
		);

		$this->assertSame( 'legacy_isbn', $config['types']['book']['fields'][0]['meta_key'] );
	}

	/**
	 * A taxonomy without a definition falls back to its key for label and rest_base.
	 */
	public function test_taxonomy_without_definition() {
		$config = EditorPanel::build_config(
			array(
				'book' => array(
					'key'        => 'book',
					'taxonomies' => array( array( 'key' => 'genre' ) ),
				),
			)
		);

		$this->assertSame(
			array(
				'key'       => 'genre',
				'rest_base' => 'genre',
				'select'    => 'multi',
				'required'  => false,
				'label'     => 'genre',
			),
			$config['types']['book']['taxonomies'][0]
		);
	}

	/**
	 * Cases: allowed so far, content type, expected result.
	 *
	 * @return array<string, array{mixed, array<string, mixed>|null, mixed}>
	 */
	public function data_allowed_blocks() {
		$declared = array( 'wp' => array( 'allowed_blocks' => array( 'core/paragraph', 'core/list', 'core/list-item', 'core/heading' ) ) );

		return array(
			'all allowed, declared'       => array( true, $declared, array( 'core/paragraph', 'core/list', 'core/list-item', 'core/heading' ) ),
			'list allowed, declared'      => array( array( 'core/heading', 'core/image', 'core/paragraph' ), $declared, array( 'core/heading', 'core/paragraph' ) ),
			'none allowed, declared'      => array( false, $declared, false ),
			'all allowed, not declared'   => array( true, array( 'wp' => array() ), true ),
			'list allowed, unknown type'  => array( array( 'core/image' ), null, array( 'core/image' ) ),
			'all allowed, empty declared' => array( true, array( 'wp' => array( 'allowed_blocks' => array() ) ), true ),
		);
	}

	/**
	 * Declared blocks narrow the allowed list; nothing changes without a declaration.
	 *
	 * @dataProvider data_allowed_blocks
	 *
	 * @param mixed                     $allowed      Allowed so far.
	 * @param array<string, mixed>|null $content_type Content type.
	 * @param mixed                     $expected     Expected result.
	 */
	public function test_allowed_blocks_for( $allowed, $content_type, $expected ) {
		$this->assertSame( $expected, EditorPanel::allowed_blocks_for( $allowed, $content_type ) );
	}
}
