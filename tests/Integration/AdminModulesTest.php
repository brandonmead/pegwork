<?php
/**
 * Integration tests: the Modules screen and its toggle handler.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Modules;
use Pegwork\Modules\Loader;
use RuntimeException;
use WPDieException;
use WP_UnitTestCase;

/**
 * Toggles go through Modules\Loader with capability and nonce checks; blocked actions show their reason.
 */
class AdminModulesTest extends WP_UnitTestCase {

	/**
	 * Registers the fixture manifest and an administrator.
	 */
	public function set_up() {
		parent::set_up();

		add_filter(
			'pegwork_module_manifest',
			static function ( $manifest ) {
				$base = dirname( __DIR__ ) . '/fixtures/modules/';
				return array_merge(
					$manifest,
					array(
						'sample'       => array(
							'label'       => 'Sample',
							'description' => 'A sample module.',
							'path'        => $base . 'sample',
						),
						'needs-sample' => array(
							'label'    => 'Needs sample',
							'requires' => array( 'sample' ),
							'path'     => $base . 'needs-sample',
						),
					)
				);
			}
		);
		// Capture redirects instead of sending headers and exiting.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test signal, never printed.
			}
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Renders the screen.
	 *
	 * @return string HTML.
	 */
	private function render() {
		ob_start();
		Modules::render();
		return ob_get_clean();
	}

	/**
	 * Posts a toggle form to the handler.
	 *
	 * @param string      $slug  Module slug.
	 * @param string      $op    enable or disable.
	 * @param string|null $nonce Nonce, or null for a valid one.
	 * @return string Redirect location.
	 */
	private function post( $slug, $op, $nonce = null ) {
		$fields   = array(
			'action'   => Modules::ACTION,
			'module'   => $slug,
			'op'       => $op,
			'_wpnonce' => $nonce ?? wp_create_nonce( Modules::NONCE . $slug ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;

		try {
			Modules::handle();
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Rows show label, slug, description, requirement and status, and a toggle form with a per-slug nonce.
	 */
	public function test_lists_modules_with_forms_and_blocked_reasons() {
		$html = $this->render();

		$this->assertStringContainsString( '<h1>Pegwork Modules</h1>', $html );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Modules</caption>', $html );
		$this->assertStringContainsString( '<strong>Sample</strong><br><code>sample</code>', $html );
		$this->assertStringContainsString( 'A sample module.', $html );
		$this->assertStringContainsString( '<td>Sample</td><td>Disabled</td>', $html, 'needs-sample lists its requirement by label.' );

		// Sample can be enabled: a form posting to admin-post.php with the slug's nonce.
		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( Modules::NONCE . 'sample' ) . '">', $html );
		$this->assertStringContainsString( 'Enable<span class="screen-reader-text"> Sample</span></button>', $html );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );

		// needs-sample cannot: disabled button with a visible reason tied to it.
		$this->assertStringContainsString( '<button type="button" class="button disabled" aria-disabled="true" aria-describedby="pegwork-reason-needs-sample">Enable<span class="screen-reader-text"> Needs sample</span></button>', $html );
		$this->assertStringContainsString( '<span class="pegwork-reason description" id="pegwork-reason-needs-sample">Requires Sample</span>', $html );
	}

	/**
	 * Once a dependent is enabled, its requirement's Disable is blocked with the reason.
	 */
	public function test_disable_blocked_while_required() {
		update_option( Loader::OPTION, array( 'sample', 'needs-sample' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'aria-describedby="pegwork-reason-sample">Disable<span class="screen-reader-text"> Sample</span></button>', $html );
		$this->assertStringContainsString( 'id="pegwork-reason-sample">Required by Needs sample</span>', $html );
		$this->assertStringContainsString( 'Disable<span class="screen-reader-text"> Needs sample</span></button></form>', $html );
	}

	/**
	 * With an empty manifest the table shows the empty state.
	 */
	public function test_empty_state() {
		remove_all_filters( 'pegwork_module_manifest' );
		add_filter( 'pegwork_module_manifest', '__return_empty_array' );

		$this->assertStringContainsString( 'No modules available.', $this->render() );
	}

	/**
	 * Enable then disable through the handler, each redirecting to the screen with a one-time notice.
	 */
	public function test_toggle_round_trip_with_notice() {
		$this->assertSame( Modules::url(), $this->post( 'sample', 'enable' ) );
		$this->assertSame( array( 'sample' ), Loader::enabled() );

		$html = $this->render();
		$this->assertStringContainsString( '<div class="notice notice-success is-dismissible"><p>Module Sample enabled.</p></div>', $html );
		$this->assertStringNotContainsString( 'notice-success', $this->render(), 'The notice shows once.' );

		$this->post( 'sample', 'disable' );
		$this->assertSame( array(), Loader::enabled() );
		$this->assertStringContainsString( 'Module Sample disabled.', $this->render() );
	}

	/**
	 * A bad nonce, or a nonce for another slug, is rejected before anything changes.
	 */
	public function test_bad_nonce_is_rejected() {
		foreach ( array( 'not-a-nonce', wp_create_nonce( Modules::NONCE . 'needs-sample' ) ) as $nonce ) {
			try {
				$this->post( 'sample', 'enable', $nonce );
				$this->fail( 'Expected wp_die.' );
			} catch ( WPDieException $e ) {
				$this->assertSame( array(), Loader::enabled() );
			}
		}
	}

	/**
	 * Users without manage_options are refused, even with a valid nonce.
	 */
	public function test_editor_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		try {
			$this->post( 'sample', 'enable' );
			$this->fail( 'Expected wp_die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), Loader::enabled() );
		}
	}

	/**
	 * The screen applies the Loader's rules: the same error the CLI gets, nothing stored.
	 */
	public function test_screen_and_loader_reject_alike() {
		$this->post( 'needs-sample', 'enable' );
		$this->assertSame( array(), Loader::enabled() );
		$expected = Loader::enable( 'needs-sample' )->get_error_message();
		$this->assertStringContainsString( '<div class="notice notice-error is-dismissible"><p>' . esc_html( $expected ) . '</p></div>', $this->render() );

		update_option( Loader::OPTION, array( 'sample', 'needs-sample' ) );
		$this->post( 'sample', 'disable' );
		$this->assertSame( array( 'sample', 'needs-sample' ), Loader::enabled() );
		$expected = Loader::disable( 'sample' );
		$this->assertSame( 'pegwork_module_required_by', $expected->get_error_code() );
		$this->assertStringContainsString( esc_html( $expected->get_error_message() ), $this->render() );
	}

	/**
	 * Unknown actions are refused.
	 */
	public function test_unknown_op_is_refused() {
		$this->expectException( WPDieException::class );
		$this->post( 'sample', 'delete' );
	}
}
