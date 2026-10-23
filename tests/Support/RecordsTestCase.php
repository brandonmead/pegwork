<?php
/**
 * Base class for the Records API read and link tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use Pegwork\Api\Records;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Events\RecordEvents;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * Registers tests/fixtures/records (types rq_gift, rq_contact, rq_task; taxonomies rq_tag multi and rq_fund
 * single; relationships gift_donor many_to_many from gift to contact and task_gift one_to_many, required,
 * from task to gift), seeds its terms and records every pegwork_event.
 */
abstract class RecordsTestCase extends WP_UnitTestCase {

	/**
	 * Events received on pegwork_event, as [ name, payload ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	protected array $events = array();

	/**
	 * Registers the package and the event recorder.
	 */
	public function set_up() {
		parent::set_up();
		self::reset_save_state();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__ ) . '/fixtures/records' ) );
		Plugin::init();
		Taxonomies::seed( Plugin::registry() );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.

		$this->events = array();
		add_action(
			'pegwork_event',
			function ( $name, $payload ) {
				$this->events[] = array( $name, $payload );
			},
			10,
			2
		);
	}

	/**
	 * Rebuilds the registry without the package.
	 */
	public function tear_down() {
		parent::tear_down();
		self::reset_save_state();
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * Empties RecordEvents' per-save bookkeeping (see RecordEventsTest::reset_save_state()).
	 */
	protected static function reset_save_state(): void {
		foreach ( array( 'snapshots', 'claims', 'flushed', 'deleting', 'routes', 'depths' ) as $property ) {
			( new \ReflectionProperty( RecordEvents::class, $property ) )->setValue( null, array() );
		}
	}

	/**
	 * Creates a record through the Records API and fails the test on an error.
	 *
	 * @param string $type   Content type key.
	 * @param array  $values Values.
	 * @param string $status Status.
	 * @return int Record ID.
	 */
	protected function record( string $type, array $values, string $status = 'private' ): int {
		$id = Records::create( $type, $values, $status );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		return $id;
	}

	/**
	 * Events of one name, payloads only.
	 *
	 * @param string $name Event name.
	 * @return array<int, array>
	 */
	protected function events_named( string $name ): array {
		return array_values( array_map( fn( $event ) => $event[1], array_filter( $this->events, fn( $event ) => $name === $event[0] ) ) );
	}
}
