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
	 * Cloudflare's published Turnstile test site key that always passes (not a secret).
	 */
	public const TURNSTILE_SITE_KEY = '1x00000000000000000000AA';

	/**
	 * Cloudflare's published Turnstile test secret key that always passes (not a secret).
	 */
	public const TURNSTILE_SECRET_KEY = '1x0000000000000000000000000000000AA';

	/**
	 * Uploads base directory for this test (a temporary folder, removed in tear_down()).
	 *
	 * @var string
	 */
	protected $uploads_dir = '';

	/**
	 * Registers the fixture package and resets state.
	 */
	public function set_up() {
		parent::set_up();
		// Constants cannot be undefined, so every forms test runs with Cloudflare's test keys (as a
		// Local site does) regardless of test order; without_turnstile_keys() covers missing keys.
		defined( 'PEGWORK_TURNSTILE_SITE_KEY' ) || define( 'PEGWORK_TURNSTILE_SITE_KEY', self::TURNSTILE_SITE_KEY );
		defined( 'PEGWORK_TURNSTILE_SECRET_KEY' ) || define( 'PEGWORK_TURNSTILE_SECRET_KEY', self::TURNSTILE_SECRET_KEY );
		self::turnstile_constants( 'PEGWORK_TURNSTILE_SITE_KEY', 'PEGWORK_TURNSTILE_SECRET_KEY' );
		// Uploads go to a temporary folder, never into the WordPress copy under test.
		$this->uploads_dir = untrailingslashit( get_temp_dir() ) . '/pegwork-uploads-' . wp_generate_password( 8, false );
		add_filter(
			'upload_dir',
			function ( $uploads ) {
				$uploads['basedir'] = $this->uploads_dir;
				$uploads['baseurl'] = 'http://example.org/wp-content/uploads';
				$uploads['path']    = $this->uploads_dir . $uploads['subdir'];
				$uploads['url']     = $uploads['baseurl'] . $uploads['subdir'];
				$uploads['error']   = false;
				return $uploads;
			},
			1
		);
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
		unset( $_GET['pegwork_sent'], $_GET['pegwork_held'], $_POST['pegwork'], $_POST['pegwork_form'], $_FILES['pegwork'] );
		self::remove_tree( $this->uploads_dir );
		self::turnstile_constants( 'PEGWORK_TURNSTILE_SITE_KEY', 'PEGWORK_TURNSTILE_SECRET_KEY' );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Makes Turnstile read its keys from undefined constants (both, or only the site or secret key).
	 *
	 * @param string $which `both`, `site` or `secret`: which key goes missing.
	 */
	protected static function without_turnstile_keys( $which = 'both' ) {
		self::turnstile_constants(
			'secret' === $which ? 'PEGWORK_TURNSTILE_SITE_KEY' : 'PEGWORK_TESTS_UNDEFINED_SITE_KEY',
			'site' === $which ? 'PEGWORK_TURNSTILE_SECRET_KEY' : 'PEGWORK_TESTS_UNDEFINED_SECRET_KEY'
		);
	}

	/**
	 * Points Turnstile's private constant names.
	 *
	 * @param string $site   Site key constant.
	 * @param string $secret Secret key constant.
	 */
	protected static function turnstile_constants( $site, $secret ) {
		$property = new \ReflectionProperty( \PegworkForms\Turnstile::class, 'constants' );
		$property->setValue(
			null,
			array(
				'site'   => $site,
				'secret' => $secret,
			)
		);
	}

	/**
	 * Deletes a temporary folder and everything in it.
	 *
	 * @param string $dir Folder.
	 */
	protected static function remove_tree( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of a temporary folder.
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
	}

	/**
	 * Writes a temporary file to stand in for an upload.
	 *
	 * @param string $name    Client file name.
	 * @param string $content Bytes.
	 * @return array{name: string, tmp_name: string, error: int, size: int}
	 */
	protected function upload( $name, $content ) {
		$tmp = wp_tempnam( 'pegwork-upload' );
		file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test file.
		return array(
			'name'     => $name,
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * A minimal PDF.
	 */
	protected static function pdf_bytes() {
		return "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
	}

	/**
	 * A 1x1 PNG.
	 */
	protected static function png_bytes() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test image.
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
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
