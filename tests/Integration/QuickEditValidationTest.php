<?php
/**
 * Integration test: Quick Edit (admin-ajax inline-save) cannot publish an incomplete record (QA C1).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

require_once ABSPATH . 'wp-admin/includes/ajax-actions.php'; // WP_Ajax_UnitTestCase registers only handlers that exist.

/**
 * Runs the real inline-save handler against examples/sifg Projects.
 *
 * @group ajax
 */
class QuickEditValidationTest extends WP_Ajax_UnitTestCase {

	/**
	 * Registers examples/sifg and logs in an editor.
	 */
	public function set_up() {
		parent::set_up();
		// The base class adds core's handler before the first test, but the hook backup may predate it.
		if ( ! has_action( 'wp_ajax_inline-save', 'wp_ajax_inline_save' ) ) {
			add_action( 'wp_ajax_inline-save', 'wp_ajax_inline_save', 1 );
		}
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		Plugin::init();
		Taxonomies::seed( Plugin::registry() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Drops the registry.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Posts an inline-save for a record.
	 *
	 * @param int    $id     Post ID.
	 * @param string $status Target status.
	 * @param array  $extra  More fields.
	 * @return string Response body.
	 */
	private function quick_edit( int $id, string $status, array $extra = array() ): string {
		$post  = get_post( $id );
		$_POST = array_merge(
			array(
				'action'       => 'inline-save',
				'_inline_edit' => wp_create_nonce( 'inlineeditnonce' ),
				'post_ID'      => $id,
				'post_type'    => 'sifg_project',
				'post_title'   => $post->post_title,
				'post_name'    => $post->post_name,
				'_status'      => $status,
				'screen'       => 'edit-sifg_project',
				'post_view'    => 'list',
				'mm'           => '01',
				'jj'           => '02',
				'aa'           => '2026',
				'hh'           => '10',
				'mn'           => '00',
				'ss'           => '00',
			),
			$extra
		);
		try {
			$this->_handleAjax( 'inline-save' );
		} catch ( WPAjaxDieStopException $e ) {
			return $e->getMessage();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		return $this->_last_response;
	}

	/**
	 * An incomplete draft stays a draft and the response names what is missing; the row is not returned.
	 */
	public function test_quick_edit_refuses_to_publish_incomplete_record() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'draft',
				'post_title'  => 'Half done',
			)
		);

		$response = $this->quick_edit( $id, 'publish' );

		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertStringNotContainsString( '<tr', $response );
		$this->assertStringContainsString( 'Contact email is required.', $response );
	}

	/**
	 * Quick Edit may still save an incomplete draft as a draft.
	 */
	public function test_quick_edit_saves_incomplete_draft() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'draft',
				'post_title'  => 'Half done',
			)
		);

		$response = $this->quick_edit( $id, 'draft', array( 'post_title' => 'Still half done' ) );

		$this->assertStringContainsString( '<tr', $response );
		$this->assertSame( 'Still half done', get_post( $id )->post_title );
	}
}
