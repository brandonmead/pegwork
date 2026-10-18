<?php
/**
 * Base class for entries admin, export, privacy and retention tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use PegworkForms\Entries;
use RuntimeException;

require_once __DIR__ . '/FormsTestCase.php';

/**
 * Adds tests/fixtures/valid/forms-entries (types fe_project and fe_story, forms fe_project with a target and
 * fe_story entry-only with retention_days 30) to the forms runtime package, logs in an administrator,
 * and turns redirects into exceptions.
 */
abstract class EntriesTestCase extends FormsTestCase {

	/**
	 * Registers the extra package, an administrator and the redirect catcher.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__ ) . '/fixtures/valid/forms-entries' ) );
		Plugin::init();

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
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Inserts an entry.
	 *
	 * @param array $overrides Entry fields (`values`, `status`, `email`, `form_key`, `created_at`, `meta`).
	 * @return int Entry ID.
	 */
	protected function entry( $overrides = array() ) {
		$id = Entries::insert(
			array_merge(
				array(
					'form_key'    => 'fr_contact',
					'status'      => 'received',
					'email'       => 'ada@example.org',
					'values'      => array(
						'name'    => 'Ada Lovelace',
						'email'   => 'Ada@Example.org',
						'message' => 'Hello there.',
						'consent' => true,
					),
					'meta'        => array(),
					'fingerprint' => str_repeat( 'a', 64 ),
				),
				$overrides
			)
		);
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Logs in a new user with a role.
	 *
	 * @param string $role Role.
	 */
	protected function login_as( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Calls a handler and returns the redirect location it ends with.
	 *
	 * @param callable $handler Handler.
	 * @return string Location.
	 */
	protected function redirect_of( $handler ) {
		try {
			$handler();
		} catch ( RuntimeException $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'Expected a redirect.' );
	}

	/**
	 * Calls a callable and returns the wp_die response code, or 0 when it redirected or returned instead.
	 *
	 * @param callable $callback Callback.
	 * @return int HTTP status passed to wp_die().
	 */
	protected function die_code( $callback ) {
		$level = ob_get_level();
		ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			return (int) $e->getCode();
		} catch ( RuntimeException $e ) {
			return 0;
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		return 0;
	}
}
