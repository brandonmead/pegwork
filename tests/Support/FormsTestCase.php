<?php
/**
 * Base class for forms module integration tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use PegworkForms\Renderer;
use PegworkForms\Schema;
use PegworkForms\Submission;
use PegworkForms\Token;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/modules/forms/module.php'; // Registers the module autoloader.

/**
 * Registers tests/fixtures/valid/forms-runtime, installs the entries table once per class, boots the
 * module, and resets the test mailer, rate limits and per-request state before each test.
 */
abstract class FormsTestCase extends WP_UnitTestCase {

	/**
	 * Fixed clock for tokens and rate limits.
	 */
	protected const NOW = 1_800_000_000;

	/**
	 * Events emitted during the test: list of [ name, payload ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	protected $events = array();

	/**
	 * Loads the module classes and creates the table (DDL outside the per-test transaction).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		$module = require dirname( __DIR__, 2 ) . '/modules/forms/module.php';
		Schema::install();
		$module->boot();
	}

	/**
	 * Registers the fixture package and resets state.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__ ) . '/fixtures/valid/forms-runtime' ) );
		Plugin::init();

		$module = require dirname( __DIR__, 2 ) . '/modules/forms/module.php';
		$module->boot();

		Submission::reset();
		Renderer::reset();
		reset_phpmailer_instance();
		$this->events = array();
		add_action(
			'pegwork_event',
			function ( $name, $payload ) {
				$this->events[] = array( $name, $payload );
			},
			10,
			2
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$_SERVER['REQUEST_URI'] = '/contact/';
		unset( $_GET['pegwork_sent'] );
	}

	/**
	 * Rebuilds the registry without the test package.
	 */
	public function tear_down() {
		unset( $_GET['pegwork_sent'], $_POST['pegwork'], $_POST['pegwork_form'] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * A valid set of contact form values.
	 *
	 * @param array $overrides Values to change.
	 * @return array<string, string>
	 */
	protected function contact_values( $overrides = array() ) {
		return array_merge(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'Ada@Example.org',
				'topic'   => 'talks',
				'website' => '',
				'message' => 'Hello there, I would like to give a talk.',
				'consent' => '1',
			),
			$overrides
		);
	}

	/**
	 * Control fields as the renderer would post them, rendered `$age` seconds before NOW.
	 *
	 * @param string $form      Form key.
	 * @param int    $age       Seconds since render.
	 * @param array  $overrides Control fields to change.
	 * @return array<string, string>
	 */
	protected function control( $form = 'fr_contact', $age = 30, $overrides = array() ) {
		$control = array(
			'key'   => $form,
			'token' => Token::create( $form, self::NOW - $age ),
			'url'   => '',
		);
		if ( is_user_logged_in() ) {
			$control['nonce'] = wp_create_nonce( Renderer::nonce_action( $form ) );
		}
		return array_merge( $control, $overrides );
	}

	/**
	 * Runs the pipeline for a form.
	 *
	 * @param array  $values  Posted values.
	 * @param string $form    Form key.
	 * @param array  $control Control overrides.
	 * @param int    $age     Seconds since render.
	 * @param int    $now     Clock.
	 * @return array Outcome.
	 */
	protected function submit( $values, $form = 'fr_contact', $control = array(), $age = 30, $now = self::NOW ) {
		return Submission::process( pegwork_registry()->form( $form ), $values, $this->control( $form, $age, $control ), $now, 0 );
	}

	/**
	 * Number of stored entries, optionally by status.
	 *
	 * @param string|null $status Status.
	 */
	protected function count_entries( $status = null ) {
		global $wpdb;
		$table = Schema::table();
		if ( null === $status ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test table.
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test table.
	}

	/**
	 * Emails sent through the test mailer.
	 *
	 * @return array<int, object>
	 */
	protected function sent_mail() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}
}
