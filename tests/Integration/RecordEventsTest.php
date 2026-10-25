<?php
/**
 * Integration tests: the event bus, core record events and the Records API.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Events;
use Pegwork\Api\Records;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Events\RecordEvents;
use Pegwork\Modules\Loader;
use Pegwork\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Saves of registry records emit exactly one event per logical save, with correct diffs and origins.
 */
class RecordEventsTest extends WP_UnitTestCase {

	/**
	 * Events received on pegwork_event, as [ name, payload ].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $events = array();

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Registers examples/sifg, rebuilds REST routes, logs in an editor and records events.
	 */
	public function set_up() {
		parent::set_up();
		self::reset_save_state();
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );

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
	 * Empties RecordEvents' per-save bookkeeping. A test that dies mid-save (core on a newer PHP than it
	 * supports throws a deprecation as an error) never reaches the hooks that clear it, and post IDs are
	 * reused after the rollback, so a stale entry would silence a later test's events.
	 */
	private static function reset_save_state(): void {
		foreach ( array( 'snapshots', 'claims', 'flushed', 'deleting', 'routes', 'depths' ) as $property ) {
			( new \ReflectionProperty( RecordEvents::class, $property ) )->setValue( null, array() );
		}
	}

	/**
	 * Names of the events received so far.
	 *
	 * @return string[]
	 */
	private function names() {
		return array_column( $this->events, 0 );
	}

	/**
	 * Payload of the only event received with a name.
	 *
	 * @param string $name Event name.
	 */
	private function only( $name ) {
		$payloads = array_column( array_filter( $this->events, fn( $event ) => $name === $event[0] ), 1 );
		$this->assertCount( 1, $payloads, "Exactly one $name expected." );

		return $payloads[0];
	}

	/**
	 * Text of a given number of words.
	 *
	 * @param int    $count Words.
	 * @param string $word  Word to repeat.
	 */
	private function words( $count, $word = 'word' ) {
		return implode( ' ', array_fill( 0, $count, $word ) );
	}

	/**
	 * Term ID by slug.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 */
	private function term( $taxonomy, $slug ) {
		return get_term_by( 'slug', $slug, $taxonomy )->term_id;
	}

	/**
	 * Body of a valid Project, with overrides.
	 *
	 * @param array $overrides Top-level request params to replace.
	 * @param array $meta      Meta values to merge.
	 */
	private function project( $overrides = array(), $meta = array() ) {
		return array_merge(
			array(
				'title'               => 'Clean water map',
				'content'             => '<!-- wp:paragraph --><p>' . $this->words( 60 ) . '</p><!-- /wp:paragraph -->',
				'status'              => 'publish',
				'sifg_project_type'   => array( $this->term( 'sifg_project_type', 'nonprofit' ) ),
				'sifg_project_status' => array( $this->term( 'sifg_project_status', 'active' ) ),
				'meta'                => array_merge(
					array(
						'_sifg_project_contact_email'    => 'team@example.org',
						'_sifg_project_needs_volunteers' => false,
					),
					$meta
				),
			),
			$overrides
		);
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Body or query params.
	 */
	private function rest( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Creates a Project through REST and clears the recorded events.
	 *
	 * @param array $overrides Top-level request params to replace.
	 * @return int Post ID.
	 */
	private function create_project( $overrides = array() ) {
		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project( $overrides ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->events = array();

		return $response->get_data()['id'];
	}

	/**
	 * Block editor flow: the auto-draft is silent, the first save is created, the next is one updated
	 * covering title, content, meta and terms written in the same request.
	 */
	public function test_block_editor_create_then_update() {
		$id = wp_insert_post(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'auto-draft',
				'post_title'  => 'Auto Draft',
			)
		);
		$this->assertSame( array(), $this->events, 'Auto-drafts emit nothing.' );

		$response = $this->rest( 'POST', "/wp/v2/projects/$id", $this->project( array( 'status' => 'draft' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'record.created', 'record.status_changed' ), $this->names() );
		$created = $this->only( 'record.created' );
		$this->assertSame(
			array(
				'id'      => $id,
				'type'    => 'sifg_project',
				'status'  => 'draft',
				'user_id' => $this->editor,
				'origin'  => array(
					'source' => 'rest',
					'depth'  => 0,
				),
			),
			$created
		);
		$this->assertSame( 'new', $this->only( 'record.status_changed' )['old_status'] );
		$this->assertSame( 'draft', $this->only( 'record.status_changed' )['new_status'] );

		$this->events = array();
		$response     = $this->rest(
			'POST',
			"/wp/v2/projects/$id",
			array(
				'title'       => 'Clean water atlas',
				'content'     => $this->words( 60, 'river' ),
				'sifg_impact' => array( $this->term( 'sifg_impact', 'sdg-06' ), $this->term( 'sifg_impact', 'sdg-03' ) ),
				'meta'        => array( '_sifg_project_website' => 'https://example.org' ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'record.updated' ), $this->names() );

		$updated = $this->only( 'record.updated' );
		$this->assertSame( array( 'name', 'description', 'website', 'sifg_impact' ), $updated['changed'] );
		$this->assertSame( 'Clean water map', $updated['old']['name'] );
		$this->assertStringContainsString( 'word word', $updated['old']['description'] );
		$this->assertSame( '', $updated['old']['website'] );
		$this->assertSame( array(), $updated['old']['sifg_impact'] );
		$this->assertSame( 'draft', $updated['status'] );
		$this->assertSame( 'rest', $updated['origin']['source'] );
		$this->assertSame( array( 'sdg-03', 'sdg-06' ), Records::get( $id )['values']['sifg_impact'] );
	}

	/**
	 * Creating through the collection route emits created; a save that changes nothing emits nothing.
	 */
	public function test_rest_create_and_unchanged_save() {
		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project() );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( array( 'record.created', 'record.status_changed' ), $this->names() );
		$this->assertSame( 'publish', $this->only( 'record.created' )['status'] );

		$id           = $response->get_data()['id'];
		$this->events = array();
		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'title' => 'Clean water map' ) )->get_status() );
		$this->assertSame( array(), $this->events );
	}

	/**
	 * Pending to publish emits updated (changed status) then status_changed with old and new.
	 */
	public function test_pending_to_publish() {
		$id = $this->create_project( array( 'status' => 'pending' ) );

		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'status' => 'publish' ) )->get_status() );
		$this->assertSame( array( 'record.updated', 'record.status_changed' ), $this->names() );
		$this->assertSame( array( 'status' ), $this->only( 'record.updated' )['changed'] );
		$this->assertSame( array( 'status' => 'pending' ), $this->only( 'record.updated' )['old'] );

		$changed = $this->only( 'record.status_changed' );
		$this->assertSame( 'pending', $changed['old_status'] );
		$this->assertSame( 'publish', $changed['new_status'] );
		$this->assertSame( $id, $changed['id'] );
		$this->assertSame( $this->editor, $changed['user_id'] );
	}

	/**
	 * A rejected REST save emits nothing and leaves no snapshot behind for the next save.
	 */
	public function test_rejected_save_emits_nothing() {
		$id = $this->create_project();

		$this->assertSame( 400, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'content' => $this->words( 5 ) ) )->get_status() );
		$this->assertSame( array(), $this->events );

		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'title' => 'Renamed' ) )->get_status() );
		$this->assertSame( array( 'name' ), $this->only( 'record.updated' )['changed'] );
	}

	/**
	 * Trash is status_changed to trash; permanent delete emits record.deleted.
	 */
	public function test_trash_and_delete() {
		$id = $this->create_project();

		$this->assertSame( 200, $this->rest( 'DELETE', "/wp/v2/projects/$id" )->get_status() );
		$this->assertSame( array( 'record.updated', 'record.status_changed' ), $this->names() );
		$this->assertSame( 'trash', $this->only( 'record.status_changed' )['new_status'] );
		$this->assertSame( 'publish', $this->only( 'record.status_changed' )['old_status'] );
		$this->assertSame( 'rest', $this->only( 'record.status_changed' )['origin']['source'] );

		$this->events = array();
		$this->assertSame( 200, $this->rest( 'DELETE', "/wp/v2/projects/$id", array( 'force' => true ) )->get_status() );
		$this->assertSame( array( 'record.deleted' ), $this->names() );
		$this->assertSame(
			array(
				'id'      => $id,
				'type'    => 'sifg_project',
				'status'  => 'trash',
				'user_id' => $this->editor,
				'origin'  => array(
					'source' => 'rest',
					'depth'  => 0,
				),
			),
			$this->only( 'record.deleted' )
		);

		$this->events = array();
		$draft        = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'auto-draft',
			)
		);
		wp_delete_post( $draft, true );
		$this->assertSame( array(), $this->events, 'Deleting an auto-draft emits nothing.' );
	}

	/**
	 * Autosaves emit nothing, both for a draft (which autosave updates in place) and a published record (a revision).
	 */
	public function test_autosaves_emit_nothing() {
		$draft = $this->create_project( array( 'status' => 'draft' ) );
		$this->assertSame( $this->editor, (int) get_post( $draft )->post_author );

		$response = $this->rest(
			'POST',
			"/wp/v2/projects/$draft/autosaves",
			array(
				'title'   => 'Autosaved title',
				'content' => $this->words( 70 ),
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'Autosaved title', get_post( $draft )->post_title, 'Draft autosaves update the post itself.' );
		$this->assertSame( array(), $this->events );

		$published = $this->create_project();
		$response  = $this->rest( 'POST', "/wp/v2/projects/$published/autosaves", array( 'title' => 'Autosaved title' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->events );

		// Saves after an autosave still emit.
		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$draft", array( 'title' => 'Real title' ) )->get_status() );
		$this->assertSame( array( 'name' ), $this->only( 'record.updated' )['changed'] );
	}

	/**
	 * PHP saves emit with source php, including meta and terms written by wp_update_post().
	 */
	public function test_php_save() {
		$id = $this->create_project();

		wp_update_post(
			array(
				'ID'         => $id,
				'meta_input' => array( '_sifg_project_who_for' => 'Rural schools' ),
			)
		);
		$updated = $this->only( 'record.updated' );
		$this->assertSame( array( 'who_for' ), $updated['changed'] );
		$this->assertSame(
			array(
				'source' => 'php',
				'depth'  => 0,
			),
			$updated['origin']
		);

		// Scheduled publishing (wp_publish_post) takes no pre_post_update snapshot; the status move still emits.
		$future = $this->create_project(
			array(
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		wp_publish_post( $future );
		$this->assertSame( array( 'record.updated', 'record.status_changed' ), $this->names() );
		$this->assertSame( array( 'status' ), $this->only( 'record.updated' )['changed'] );
		$this->assertSame( 'future', $this->only( 'record.status_changed' )['old_status'] );
	}

	/**
	 * Non-registry post types emit nothing.
	 */
	public function test_other_post_types_emit_nothing() {
		$id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		wp_trash_post( $id );
		wp_delete_post( $id, true );

		$page = $this->rest(
			'POST',
			'/wp/v2/pages',
			array(
				'title'  => 'About',
				'status' => 'publish',
			)
		);
		$this->assertSame( 201, $page->get_status() );
		$this->assertSame( array(), $this->events );
	}

	/**
	 * Records API: get, create and update by registry key, one event each, with the caller's origin.
	 */
	public function test_records_api() {
		$id = Records::create(
			'sifg_project',
			array(
				'name'                => 'Seed library',
				'description'         => $this->words( 55 ),
				'contact_email'       => 'seeds@example.org',
				'needs_volunteers'    => false,
				'sifg_project_type'   => array( 'nonprofit' ),
				'sifg_project_status' => 'active',
			),
			'publish',
			array(
				'automation' => 'importer',
				'run_id'     => 3,
				'depth'      => 2,
			)
		);
		$this->assertIsInt( $id );
		$this->assertSame( array( 'record.created', 'record.status_changed' ), $this->names() );
		$this->assertSame(
			array(
				'source'     => 'records_api',
				'automation' => 'importer',
				'run_id'     => 3,
				'depth'      => 2,
			),
			$this->only( 'record.created' )['origin']
		);

		$record = Records::get( $id );
		$this->assertSame( 'sifg_project', $record['type'] );
		$this->assertSame( 'publish', $record['status'] );
		$this->assertSame( 'Seed library', $record['values']['name'] );
		$this->assertSame( 'seeds@example.org', $record['values']['contact_email'] );
		$this->assertFalse( $record['values']['needs_volunteers'] );
		$this->assertSame( array( 'active' ), $record['values']['sifg_project_status'] );
		$this->assertSame( get_permalink( $id ), $record['permalink'] );

		$this->events = array();
		$this->assertTrue(
			Records::update(
				$id,
				array(
					'last_verified'       => '2026-10-18',
					'sifg_project_status' => array( 'paused' ),
					'sifg_impact'         => array( 'sdg-02' ),
				)
			)
		);
		$this->assertSame( array( 'record.updated' ), $this->names() );
		$updated = $this->only( 'record.updated' );
		$this->assertSame( array( 'last_verified', 'sifg_project_status', 'sifg_impact' ), $updated['changed'] );
		$this->assertSame(
			array(
				'last_verified'       => '',
				'sifg_project_status' => array( 'active' ),
				'sifg_impact'         => array(),
			),
			$updated['old']
		);
		$this->assertSame(
			array(
				'source' => 'records_api',
				'depth'  => 0,
			),
			$updated['origin']
		);
		$this->assertSame( '2026-10-18', Records::get( $id )['values']['last_verified'] );

		$this->events = array();
		$this->assertTrue( Records::update( $id, array( 'last_verified' => '2026-10-18' ) ) );
		$this->assertSame( array(), $this->events, 'A write that changes nothing emits nothing.' );

		$this->assertNull( Records::get( self::factory()->post->create() ) );
		$this->assertNull( Records::get( 999999 ) );
	}

	/**
	 * Records API writes are validated: invalid writes change nothing and emit nothing.
	 */
	public function test_records_api_validation() {
		$id = $this->create_project();

		$cases = array(
			'contact_email'       => array( 'contact_email' => '' ),
			'description'         => array( 'description' => $this->words( 3 ) ),
			'website'             => array( 'website' => 'javascript:alert(1)' ),
			'last_verified'       => array( 'last_verified' => '2026-02-30' ),
			'sifg_project_type'   => array( 'sifg_project_type' => array( 'nonprofit', 'corporation' ) ),
			'sifg_impact'         => array( 'sifg_impact' => array( 'not-a-term' ) ),
			'unknown'             => array( 'unknown' => 'x' ),
			'sifg_project_status' => array( 'sifg_project_status' => array( 'invented' ) ),
		);
		foreach ( $cases as $key => $values ) {
			$result = Records::update( $id, $values );
			$this->assertWPError( $result, $key );
			$this->assertSame( 'pegwork_invalid_record', $result->get_error_code(), $key );
			$this->assertArrayHasKey( $key, $result->get_error_data()['fields'], $key );
		}
		$this->assertSame( array(), $this->events );
		$this->assertEmpty( term_exists( 'invented', 'sifg_project_status' ) );
		$this->assertSame( 'team@example.org', Records::get( $id )['values']['contact_email'] );

		// Drafts may be incomplete.
		$draft = Records::create( 'sifg_project', array( 'name' => 'Rough idea' ) );
		$this->assertIsInt( $draft );
		$this->assertSame( 'draft', get_post_status( $draft ) );

		$this->assertSame( 'pegwork_unknown_type', Records::create( 'nope', array() )->get_error_code() );
		$this->assertSame( 'pegwork_invalid_status', Records::create( 'sifg_project', array(), 'bogus' )->get_error_code() );
		$this->assertSame( 'pegwork_record_not_found', Records::update( 999999, array() )->get_error_code() );
	}

	/**
	 * Records::trash() (API 0.4.0): record.updated (`changed` = status) and record.status_changed to trash, both
	 * with the caller's origin; false when already trashed; restorable.
	 */
	public function test_records_api_trash() {
		$id     = $this->create_project();
		$origin = array(
			'automation' => 'retention',
			'run_id'     => 9,
			'depth'      => 2,
		);

		$this->assertTrue( Records::trash( $id, $origin ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
		$this->assertSame( array( 'record.updated', 'record.status_changed' ), $this->names() );
		$expected = array( 'source' => 'records_api' ) + $origin;
		$updated  = $this->only( 'record.updated' );
		$this->assertSame( array( 'status' ), $updated['changed'] );
		$this->assertSame( array( 'status' => 'publish' ), $updated['old'] );
		$this->assertSame( $expected, $updated['origin'] );
		$changed = $this->only( 'record.status_changed' );
		$this->assertSame( 'publish', $changed['old_status'] );
		$this->assertSame( 'trash', $changed['new_status'] );
		$this->assertSame( $expected, $changed['origin'] );

		$this->events = array();
		$this->assertFalse( Records::trash( $id, $origin ), 'Already in the trash.' );
		$this->assertSame( array(), $this->events );

		wp_untrash_post( $id );
		$this->assertSame( 'draft', get_post_status( $id ), 'Core restores to draft since WordPress 5.6.' );
		$this->assertNotNull( Records::get( $id ) );
	}

	/**
	 * A listener trashing through Records::trash() gets the dispatched event's depth plus 1.
	 */
	public function test_records_api_trash_from_listener_carries_depth() {
		$id = $this->create_project();
		add_action(
			'pegwork_event_record_updated',
			function ( $payload ) use ( $id ) {
				if ( $id === $payload['id'] && 'trash' !== $payload['status'] ) {
					Records::trash( $id, array( 'automation' => 'cleanup' ) );
				}
			}
		);

		$this->assertTrue( Records::update( $id, array( 'last_verified' => '2026-10-18' ) ) );
		$this->assertSame( array( 'record.updated', 'record.updated', 'record.status_changed' ), $this->names() );
		$this->assertSame(
			array(
				'source'     => 'records_api',
				'automation' => 'cleanup',
				'depth'      => 1,
			),
			$this->only( 'record.status_changed' )['origin']
		);
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	/**
	 * Records::trash() refuses when the site keeps no trash (`trash_disabled`, the post untouched and no
	 * event), and for anything that is not a registry record. EMPTY_TRASH_DAYS is a constant, so the test
	 * turns trash off through the pegwork_records_trash_enabled filter, which Records::trash() checks together
	 * with the constant; a filter returning true cannot turn trash on when the constant is 0.
	 */
	public function test_records_api_trash_refusals() {
		$id = $this->create_project();

		add_filter( 'pegwork_records_trash_enabled', '__return_false' );
		$result = Records::trash( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'trash_disabled', $result->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ), 'The post is left as it was.' );
		$this->assertSame( '', get_post_meta( $id, '_wp_trash_meta_status', true ) );
		$this->assertSame( array(), $this->events );
		remove_filter( 'pegwork_records_trash_enabled', '__return_false' );

		add_filter( 'pre_trash_post', '__return_false' );
		$this->assertSame( 'pegwork_trash_failed', Records::trash( $id )->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ) );
		remove_filter( 'pre_trash_post', '__return_false' );
		$this->assertSame( array(), $this->events );

		$auto_draft = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'auto-draft',
			)
		);
		foreach ( array( 999999, self::factory()->post->create(), $auto_draft ) as $other ) {
			$this->assertSame( 'pegwork_record_not_found', Records::trash( $other )->get_error_code() );
		}
		$this->assertSame( 'auto-draft', get_post_status( $auto_draft ) );
	}

	/**
	 * A module listening on pegwork_event_record_updated writes through Records::update; the nested
	 * event carries depth 1 and the module's origin.
	 */
	public function test_listener_module_carries_depth() {
		add_filter(
			'pegwork_module_manifest',
			fn( $manifest ) => $manifest + array(
				'record-listener' => array(
					'label' => 'Record listener',
					'path'  => dirname( __DIR__ ) . '/fixtures/modules/record-listener',
				),
			)
		);
		update_option( 'pegwork_modules', array( 'record-listener' ) );
		$results = array();
		add_action(
			'pegwork_fixture_listener_result',
			function ( $result ) use ( &$results ) {
				$results[] = $result;
			}
		);
		$this->assertSame( array( 'record-listener' ), Loader::load() );
		Loader::boot();

		$id = $this->create_project();
		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_website' => 'https://example.org' ) ) )->get_status() );

		$this->assertSame( array( true ), $results );
		$this->assertSame( array( 'record.updated', 'record.updated' ), $this->names() );
		[ $outer, $inner ] = array_column( $this->events, 1 );
		$this->assertSame( array( 'website' ), $outer['changed'] );
		$this->assertSame(
			array(
				'source' => 'rest',
				'depth'  => 0,
			),
			$outer['origin']
		);
		$this->assertSame( array( 'last_verified' ), $inner['changed'] );
		$this->assertSame(
			array(
				'source'     => 'records_api',
				'automation' => 'fixture_stamp',
				'run_id'     => 7,
				'depth'      => 1,
			),
			$inner['origin']
		);
		$this->assertSame( '2026-10-18', Records::get( $id )['values']['last_verified'] );
	}

	/**
	 * Private values travel in payloads (server side) but nothing is output and REST stays private.
	 */
	public function test_private_values_stay_server_side() {
		$this->expectOutputString( '' );
		$id = $this->create_project();

		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_contact_email' => 'new@example.org' ) ) )->get_status() );
		$updated = $this->only( 'record.updated' );
		$this->assertSame( array( 'contact_email' ), $updated['changed'] );
		$this->assertSame( 'team@example.org', $updated['old']['contact_email'] );

		wp_set_current_user( 0 );
		$public = wp_json_encode( $this->rest( 'GET', "/wp/v2/projects/$id" )->get_data() );
		$this->assertStringNotContainsString( 'new@example.org', $public );
		$this->assertStringNotContainsString( 'team@example.org', $public );
	}

	/**
	 * Emit fires the generic and the per-name action; invalid names are rejected.
	 */
	public function test_emit_contract() {
		$specific = array();
		add_action(
			'pegwork_event_form_submitted',
			function ( $payload ) use ( &$specific ) {
				$specific[] = $payload;
			}
		);

		Events::emit( 'form.submitted', array( 'form' => 'contact' ) );
		$this->assertSame( array( array( 'form.submitted', array( 'form' => 'contact' ) ) ), $this->events );
		$this->assertSame( array( array( 'form' => 'contact' ) ), $specific );

		$this->setExpectedIncorrectUsage( 'Pegwork\Api\Events::emit' );
		$this->events = array();
		foreach ( array( 'record', 'Record.updated', 'record.', 'record..x', '1x.y', 'a.b-c' ) as $name ) {
			Events::emit( $name, array() );
		}
		$this->assertSame( array(), $this->events );
	}
}
