<?php
/**
 * Integration tests: the public form API functions in core.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use Pegwork\Registry\Loader;
use WP_UnitTestCase;

/**
 * Functions pegwork_sanitize_field(), pegwork_validate_fields(), pegwork_save_form(), pegwork_delete_form().
 */
class FormApiTest extends WP_UnitTestCase {

	/**
	 * Registers the forms runtime fixture (file forms plus an options page).
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Loader::DB_OPTION );
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/forms-runtime' ) );
		Plugin::init();
	}

	/**
	 * Rebuilds the registry without the fixture.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * A valid site form.
	 *
	 * @param array $overrides Properties to change.
	 */
	private function form( $overrides = array() ) {
		return array_merge(
			array(
				'format'        => 1,
				'key'           => 'site_feedback',
				'label'         => 'Feedback',
				'fields'        => array(
					array(
						'key'      => 'email',
						'type'     => 'email',
						'label'    => 'Email',
						'required' => true,
					),
				),
				'notifications' => array(
					array(
						'key'      => 'moderator',
						'when'     => 'submitted',
						'to'       => '{option:fr_settings.moderator_email}',
						'reply_to' => '{field:email}',
						'subject'  => 'Feedback',
						'body'     => '{all_fields}',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * Per-type sanitizing of untrusted input.
	 */
	public function test_sanitize_field() {
		$option = array(
			'value' => 'a',
			'label' => 'A',
		);
		$select = array(
			'type'    => 'select',
			'options' => array( $option ),
		);
		$cases  = array(
			array( array( 'type' => 'text' ), " <b>Hi</b>\n there ", 'Hi there' ),
			array( array( 'type' => 'text' ), array( 'x' ), '' ),
			array( array( 'type' => 'textarea' ), "Line 1\nLine <i>2</i>", "Line 1\nLine 2" ),
			array( array( 'type' => 'email' ), ' ada@example.org ', 'ada@example.org' ),
			array( array( 'type' => 'email' ), 'ada@', '' ),
			array( array( 'type' => 'url' ), 'https://example.org/a?b=1', 'https://example.org/a?b=1' ),
			array( array( 'type' => 'url' ), 'javascript:alert(1)', '' ),
			array( array( 'type' => 'url' ), 'ftp://example.org', '' ),
			array( array( 'type' => 'date' ), '2026-02-28', '2026-02-28' ),
			array( array( 'type' => 'date' ), '2026-02-30', '' ),
			array( array( 'type' => 'number' ), ' 12.5 ', 12.5 ),
			array( array( 'type' => 'number' ), '7', 7 ),
			array( array( 'type' => 'number' ), 'seven', '' ),
			array( array( 'type' => 'boolean' ), '1', true ),
			array( array( 'type' => 'boolean' ), null, false ),
			array( array( 'type' => 'boolean' ), array( '1' ), false ),
			array( array( 'type' => 'file' ), 'x.php', '' ),
			array( $select, 'a', 'a' ),
			array(
				$select,
				'b',
				'',
			),
		);
		foreach ( $cases as $index => $case ) {
			$this->assertSame( $case[2], pegwork_sanitize_field( $case[0], $case[1] ), "Case $index" );
		}
	}

	/**
	 * Field rules shared with record validation; a required boolean must be checked.
	 */
	public function test_validate_fields() {
		$fields = array(
			array(
				'key'      => 'name',
				'type'     => 'text',
				'label'    => 'Name',
				'required' => true,
				'validate' => array( 'min_words' => 2 ),
			),
			array(
				'key'      => 'agree',
				'type'     => 'boolean',
				'label'    => 'I agree',
				'required' => true,
			),
			array(
				'key'           => 'why',
				'type'          => 'text',
				'label'         => 'Why',
				'required_when' => array(
					'field'  => 'agree',
					'equals' => false,
				),
			),
		);

		$this->assertTrue(
			pegwork_validate_fields(
				$fields,
				array(
					'name'  => 'Ada Lovelace',
					'agree' => true,
				)
			)
		);

		$error = pegwork_validate_fields(
			$fields,
			array(
				'name'  => 'Ada',
				'agree' => false,
			)
		);
		$this->assertWPError( $error );
		$this->assertSame( 'pegwork_invalid_fields', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame(
			array(
				'name'  => 'Name must be at least 2 words (it has 1).',
				'agree' => 'Check the box to confirm: I agree',
				'why'   => 'Why is required.',
			),
			$error->get_error_data()['fields']
		);

		$this->assertTrue( pegwork_validate_fields( $fields, array(), false ) );
	}

	/**
	 * Saving writes the canonical form to the DB source and the registry serves it; deleting removes it.
	 */
	public function test_save_and_delete_form() {
		$this->assertTrue( pegwork_save_form( $this->form() ) );
		$saved = pegwork_registry()->form( 'site_feedback' );
		$this->assertSame( 'db', pegwork_registry()->form_source( 'site_feedback' ) );
		$this->assertSame( 'anyone', $saved['access'] );
		$this->assertTrue( $saved['fields'][0]['required'] );
		$this->assertSame( array( $saved ), get_option( Loader::DB_OPTION )['forms'] );

		$this->assertTrue( pegwork_save_form( $this->form( array( 'label' => 'Feedback v2' ) ) ) );
		$this->assertSame( 'Feedback v2', pegwork_registry()->form( 'site_feedback' )['label'] );
		$this->assertCount( 1, get_option( Loader::DB_OPTION )['forms'], 'Saving again replaces in place.' );

		$this->assertTrue( pegwork_delete_form( 'site_feedback' ) );
		$this->assertNull( pegwork_registry()->form( 'site_feedback' ) );
		$this->assertSame( array(), get_option( Loader::DB_OPTION )['forms'] );

		$missing = pegwork_delete_form( 'site_feedback' );
		$this->assertSame( 'pegwork_form_not_found', $missing->get_error_code() );
	}

	/**
	 * Invalid definitions and broken references are refused with messages by JSON pointer.
	 */
	public function test_save_form_rejects_invalid_definitions() {
		$error = pegwork_save_form( $this->form( array( 'label' => '' ) ) );
		$this->assertSame( 'pegwork_invalid_form', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertArrayHasKey( '/label', $error->get_error_data()['fields'] );

		$notification       = $this->form()['notifications'][0];
		$notification['to'] = '{option:nope.moderator_email}';
		$error              = pegwork_save_form( $this->form( array( 'notifications' => array( $notification ) ) ) );
		$this->assertSame( 'pegwork_invalid_form', $error->get_error_code() );
		$this->assertArrayHasKey( '/notifications/0/to', $error->get_error_data()['fields'] );

		$this->assertFalse( get_option( Loader::DB_OPTION, false ), 'Nothing was written.' );
	}

	/**
	 * File-defined forms are read-only through the API.
	 */
	public function test_file_forms_are_read_only() {
		$error = pegwork_save_form( $this->form( array( 'key' => 'fr_contact' ) ) );
		$this->assertSame( 'pegwork_form_file_defined', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );

		$this->assertSame( 'pegwork_form_file_defined', pegwork_delete_form( 'fr_contact' )->get_error_code() );
		$this->assertSame( 'file:forms_runtime', pegwork_registry()->form_source( 'fr_contact' ) );
	}
}
