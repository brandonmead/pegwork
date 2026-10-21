<?php
/**
 * Integration tests: the view script's enqueue and the config it reads.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Renderer;
use PegworkForms\View;

require_once dirname( __DIR__, 2 ) . '/Support/FormsTestCase.php';

/**
 * The script loads only where a form renders, as a classic script with translations, and each form
 * carries its rules in `data-pegwork-config`.
 */
class FormsViewTest extends FormsTestCase {

	/**
	 * Starts each test with the script registered (CI runs PHPUnit without built assets) and not enqueued.
	 */
	public function set_up() {
		parent::set_up();
		wp_dequeue_script( View::HANDLE );
		if ( ! wp_script_is( View::HANDLE, 'registered' ) ) {
			wp_register_script( View::HANDLE, 'http://example.org/forms-view.js', array( 'wp-i18n' ), '1', true );
		}
	}

	/**
	 * Cleans up the enqueue and the success flag.
	 */
	public function tear_down() {
		wp_dequeue_script( View::HANDLE );
		unset( $_GET['pegwork_sent'] );
		parent::tear_down();
	}

	/**
	 * Renders a block.
	 *
	 * @param string $form Form key.
	 */
	private function block( $form ) {
		return do_blocks( '<!-- wp:pegwork/form {"form":"' . $form . '"} /-->' );
	}

	/**
	 * The decoded config attribute of the first form in some markup.
	 *
	 * @param string $html Markup.
	 * @return array<string, mixed>
	 */
	private function config( $html ) {
		$this->assertSame( 1, preg_match( '/data-pegwork-config="([^"]*)"/', $html, $match ) );
		$config = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
		$this->assertIsArray( $config );
		return $config;
	}

	/**
	 * Only a rendered form enqueues the script: not an unknown key, not the success state.
	 */
	public function test_enqueued_only_where_a_form_renders() {
		$this->assertSame( '', $this->block( 'nope' ) );
		$this->assertFalse( wp_script_is( View::HANDLE, 'enqueued' ), 'Unknown form.' );

		$_GET['pegwork_sent'] = 'fr_contact';
		$this->assertStringContainsString( 'role="status"', $this->block( 'fr_contact' ) );
		$this->assertFalse( wp_script_is( View::HANDLE, 'enqueued' ), 'Success state.' );
		unset( $_GET['pegwork_sent'] );

		Renderer::reset();
		$this->assertStringContainsString( '<form class="pegwork-form__form"', $this->block( 'fr_contact' ) );
		$this->assertTrue( wp_script_is( View::HANDLE, 'enqueued' ) );
	}

	/**
	 * When built, the script is a classic footer script depending on wp-i18n, with the pegwork translations.
	 */
	public function test_registered_as_a_translated_classic_script() {
		if ( ! is_readable( PEGWORK_DIR . 'assets/build/forms-view.asset.php' ) ) {
			$this->markTestSkipped( 'Assets are not built (npm run build).' );
		}
		wp_deregister_script( View::HANDLE );
		View::register();

		$script = wp_scripts()->registered[ View::HANDLE ];
		$this->assertStringEndsWith( 'assets/build/forms-view.js', $script->src );
		$this->assertContains( 'wp-i18n', $script->deps );
		$this->assertSame( 'pegwork', $script->textdomain );
		$this->assertSame( 1, $script->extra['group'] ?? null, 'In the footer.' );
		$this->assertArrayNotHasKey( 'type', $script->extra, 'Not a module.' );
	}

	/**
	 * Items carry control IDs, flags, conditions and hint rules; conditional items carry their plain help.
	 */
	public function test_config_for_a_single_section_form() {
		$config = $this->config( $this->block( 'fr_contact' ) );

		$this->assertArrayNotHasKey( 'steps', $config );
		$by_key = array_column( $config['items'], null, 'key' );
		$this->assertSame( array( 'name', 'email', 'topic', 'website', 'subscribe', 'frequency', 'message', 'consent' ), array_keys( $by_key ) );
		$this->assertSame(
			array(
				'key'      => 'name',
				'type'     => 'text',
				'label'    => 'Name',
				'control'  => 'pegwork-fr_contact-name',
				'required' => true,
			),
			$by_key['name']
		);
		$this->assertSame( 'pegwork-fr_contact-topic-0', $by_key['topic']['control'] );
		$this->assertTrue( $by_key['topic']['group'] );
		$this->assertSame(
			array(
				'key'           => 'frequency',
				'type'          => 'select',
				'label'         => 'How often',
				'control'       => 'pegwork-fr_contact-frequency',
				'show_when'     => array(
					'field'  => 'subscribe',
					'equals' => true,
				),
				'required_when' => array(
					'field'  => 'subscribe',
					'equals' => true,
				),
				'help'          => '',
			),
			$by_key['frequency']
		);
		$this->assertSame( array( 'max_length' => 200 ), $by_key['message']['validate'] );
		$this->assertArrayNotHasKey( 'help', $by_key['message'], 'Help without a condition stays in the markup only.' );
	}

	/**
	 * Steps list their labels and item keys in order.
	 */
	public function test_config_for_steps() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$config = $this->config( $this->block( 'fr_members' ) );

		$this->assertSame(
			array(
				array(
					'label' => 'About you',
					'items' => array( 'nickname' ),
				),
				array(
					'label' => 'More',
					'items' => array( 'age', 'start' ),
				),
			),
			$config['steps']
		);
		$this->assertSame( 'number', array_column( $config['items'], null, 'key' )['age']['type'] );
	}
}
