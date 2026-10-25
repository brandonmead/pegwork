<?php
/**
 * Integration tests: the automation save API and editing surface.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Registry\Loader;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Editor\AutomationPost;
use PegworkAutomation\Editor\Builder;
use PegworkAutomation\Editor\BuilderEditor;
use WP_Block_Editor_Context;
use WP_REST_Request;
use WPDieException;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * The functions pegwork_save_automation() and pegwork_delete_automation(); the `pegwork_automation` post type,
 * compile on REST save, refusals by pointer (400) and for file keys (409), kses without
 * `unfiltered_html`, trash, regeneration, file automations read-only with Duplicate, the list rows,
 * and that only administrators get in.
 */
class AutomationBuilderTest extends AutomationTestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Subscriber ID.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * An administrator, a subscriber, no DB definitions.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Loader::DB_OPTION );
		\Pegwork\Plugin::init();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Drops the DB definitions and request globals.
	 */
	public function tear_down() {
		delete_option( Loader::DB_OPTION );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * A valid site automation on the fixture's `job` type.
	 *
	 * @param array $overrides Top-level keys to replace.
	 */
	private function definition( $overrides = array() ) {
		return array_merge(
			array(
				'format'  => 1,
				'key'     => 'site_ping',
				'label'   => 'Site ping',
				'trigger' => array(
					'type'         => 'event',
					'event'        => 'record.created',
					'content_type' => 'job',
				),
				'steps'   => array(
					array(
						'id'        => 'route',
						'type'      => 'decision',
						'branches'  => array(
							array(
								'label' => 'Urgent',
								'when'  => array(
									'var'   => 'record.urgent',
									'op'    => 'eq',
									'value' => true,
								),
								'steps' => array(
									array(
										'id'     => 'mark',
										'type'   => 'update_record',
										'values' => array( 'marker' => 'urgent {{record.title}}' ),
									),
								),
							),
						),
						'otherwise' => array(
							array(
								'id'   => 'later',
								'type' => 'wait',
								'for'  => 'P1D',
							),
						),
					),
					array(
						'id'      => 'tell',
						'type'    => 'send_email',
						'to'      => 'desk@example.org',
						'subject' => 'New job {{record.title}}',
						'text'    => 'Marker: {{record.marker}}',
						'html'    => '<p>Marker: {{record.marker}}</p>',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * Saves an automation post through REST.
	 *
	 * @param array    $params  Body parameters.
	 * @param int|null $post_id Post to update, or null to create.
	 */
	private function rest_save( $params, $post_id = null ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/pegwork-automations' . ( $post_id ? '/' . $post_id : '' ) );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * REST body parameters for a definition, as the editor would send them.
	 *
	 * @param array $definition Definition.
	 */
	private function params( $definition ) {
		$generated = Builder::generate( $definition );
		return array(
			'title'   => $generated['title'],
			'content' => $generated['content'],
			'status'  => 'publish',
			'meta'    => array( AutomationPost::META_SETTINGS => AutomationPost::settings_json( $generated['settings'] ) ),
		);
	}

	/**
	 * Creates a site automation through REST and returns its post ID.
	 *
	 * @param array $definition Definition.
	 */
	private function create( $definition = null ) {
		$response = $this->rest_save( $this->params( $definition ?? $this->definition() ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['id'];
	}

	/**
	 * The API saves in canonical form, in place by key, and deletes; runs are not its business.
	 */
	public function test_save_and_delete_api() {
		$this->assertTrue( pegwork_save_automation( $this->definition() ) );
		$this->assertSame( 'db', pegwork_registry()->automation_source( 'site_ping' ) );
		$this->assertTrue( pegwork_registry()->automation( 'site_ping' )['enabled'], 'The API keeps the normalizer default.' );

		$stored = get_option( Loader::DB_OPTION )['automations'];
		$this->assertCount( 1, $stored );
		$this->assertSame( pegwork_registry()->automation( 'site_ping' ), $stored[0], 'Stored canonical.' );

		$this->assertTrue( pegwork_save_automation( $this->definition( array( 'label' => 'Site ping v2' ) ) ) );
		$this->assertCount( 1, get_option( Loader::DB_OPTION )['automations'], 'Replaced in place.' );
		$this->assertSame( 'Site ping v2', pegwork_registry()->automation( 'site_ping' )['label'] );

		$this->assertTrue( pegwork_delete_automation( 'site_ping' ) );
		$this->assertNull( pegwork_registry()->automation( 'site_ping' ) );
		$missing = pegwork_delete_automation( 'site_ping' );
		$this->assertSame( 'pegwork_automation_not_found', $missing->get_error_code() );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		// Forms in the same option are untouched by automation writes.
		$this->assertSame( array(), get_option( Loader::DB_OPTION )['automations'] );
		$this->assertArrayNotHasKey( 'forms', get_option( Loader::DB_OPTION ) );
	}

	/**
	 * Refusals: 400 with messages by JSON pointer (shape, nested references), 409 for file keys.
	 */
	public function test_save_api_refusals() {
		$error = pegwork_save_automation( $this->definition( array( 'label' => '' ) ) );
		$this->assertSame( 'pegwork_invalid_automation', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertArrayHasKey( '/label', $error->get_error_data()['fields'] );

		$definition = $this->definition();
		$definition['steps'][0]['branches'][0]['steps'][0]['values'] = array( 'marker' => '{{record.nope}}' );
		$error = pegwork_save_automation( $definition );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( array( '/steps/0/branches/0/steps/0/values/marker' ), array_keys( $error->get_error_data()['fields'] ) );

		$trigger = array(
			'type'         => 'event',
			'event'        => 'record.created',
			'content_type' => 'nope',
		);
		$error   = pegwork_save_automation( $this->definition( array( 'trigger' => $trigger ) ) );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertArrayHasKey( '/trigger/content_type', $error->get_error_data()['fields'] );

		$error = pegwork_save_automation( $this->definition( array( 'key' => 'job_mail' ) ) );
		$this->assertSame( 'pegwork_automation_file_defined', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'pegwork_automation_file_defined', pegwork_delete_automation( 'job_mail' )->get_error_code() );
		$this->assertStringStartsWith( 'file:', pegwork_registry()->automation_source( 'job_mail' ) );
		$this->assertFalse( get_option( Loader::DB_OPTION, false ), 'Nothing was written.' );
	}

	/**
	 * Automation v4: deleting a started automation, or saving it so it can no longer be started, is refused
	 * with 409 `pegwork_automation_in_use` naming the callers, and changes nothing; without callers it works.
	 */
	public function test_started_automation_is_in_use() {
		$target = array(
			'format'  => 1,
			'key'     => 'site_target',
			'label'   => 'Site target',
			'trigger' => array(
				'type'   => 'manual',
				'button' => 'Notify',
			),
			'steps'   => array(
				array(
					'id'      => 'tell',
					'type'    => 'send_email',
					'to'      => 'desk@example.org',
					'subject' => 'Hello',
					'text'    => 'Hello',
				),
			),
		);
		$caller = $this->definition(
			array(
				'key'   => 'site_caller',
				'steps' => array(
					array(
						'id'         => 'notify',
						'type'       => 'start_automation',
						'automation' => 'site_target',
					),
				),
			)
		);
		$this->assertTrue( pegwork_save_automation( $target ) );
		$this->assertTrue( pegwork_save_automation( $caller ) );
		$stored = get_option( Loader::DB_OPTION );

		$error = pegwork_delete_automation( 'site_target' );
		$this->assertSame( 'pegwork_automation_in_use', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( array( 'site_caller' ), $error->get_error_data()['automations'] );
		$this->assertSame( $stored, get_option( Loader::DB_OPTION ), 'Nothing was removed.' );
		$this->assertNotNull( pegwork_registry()->automation( 'site_caller' ) );

		$event = array_merge( $target, array( 'trigger' => $this->definition()['trigger'] ) );
		$error = pegwork_save_automation( $event );
		$this->assertSame( 'pegwork_automation_in_use', $error->get_error_code() );
		$this->assertSame( $stored, get_option( Loader::DB_OPTION ), 'Nothing was saved.' );
		$this->assertSame( 'manual', pegwork_registry()->automation( 'site_target' )['trigger']['type'] );

		$this->assertTrue( pegwork_save_automation( array_merge( $target, array( 'label' => 'Site target v2' ) ) ), 'A change that keeps it startable saves.' );
		$this->assertTrue( pegwork_delete_automation( 'site_caller' ) );
		$this->assertTrue( pegwork_delete_automation( 'site_target' ) );
	}

	/**
	 * The post type is private UI only; every capability maps to manage_options, with no filter.
	 */
	public function test_post_type_and_capabilities() {
		$type = get_post_type_object( AutomationPost::TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->show_ui );
		$this->assertFalse( $type->show_in_menu );
		$this->assertTrue( $type->show_in_rest );
		$this->assertFalse( post_type_supports( AutomationPost::TYPE, 'revisions' ) );
		foreach ( array( 'edit_posts', 'create_posts', 'publish_posts', 'delete_posts', 'read_private_posts', 'edit_others_posts' ) as $cap ) {
			$this->assertSame( AutomationPost::CAP, $type->cap->{$cap}, $cap );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => AutomationPost::TYPE ) );
		$this->assertSame( 'private', get_post_status( $post_id ), 'Status is forced private.' );
		$this->assertTrue( user_can( $this->admin, AutomationPost::CAP ) );
		$this->assertTrue( user_can( $this->admin, 'edit_post', $post_id ) );
		foreach ( array( $this->editor, $this->subscriber ) as $user ) {
			$this->assertFalse( user_can( $user, AutomationPost::CAP ) );
			$this->assertFalse( user_can( $user, $type->cap->create_posts ) );
			$this->assertFalse( user_can( $user, 'edit_post', $post_id ) );
			$this->assertFalse( user_can( $user, 'read_post', $post_id ) );
		}
	}

	/**
	 * A REST save compiles the post and writes the DB definition, disabled on its first save.
	 */
	public function test_rest_save_writes_definition() {
		$post_id    = $this->create();
		$automation = pegwork_registry()->automation( 'site_ping' );

		$this->assertNotNull( $automation );
		$this->assertSame( 'db', pegwork_registry()->automation_source( 'site_ping' ) );
		$this->assertFalse( $automation['enabled'], 'New site automations start disabled.' );
		$this->assertSame( 'decision', $automation['steps'][0]['type'] );
		$this->assertSame( 'later', $automation['steps'][0]['otherwise'][0]['id'] );
		$this->assertSame( 'private', get_post_status( $post_id ) );
		$this->assertSame( 'site_ping', AutomationPost::key_of( $post_id ) );
		$this->assertSame( Builder::hash( $automation ), get_post_meta( $post_id, AutomationPost::META_HASH, true ) );
		$this->assertSame( $post_id, AutomationPost::find( 'site_ping' ) );
		$settings = json_decode( get_post_meta( $post_id, AutomationPost::META_SETTINGS, true ), true );
		$this->assertSame( 'site_ping', $settings['key'] );
		$this->assertFalse( $settings['enabled'], 'Settings meta holds the canonical settings.' );

		// Later saves keep what the settings say; the key stays locked.
		$params = $this->params( $this->definition( array( 'enabled' => true ) ) );

		$params['meta'][ AutomationPost::META_SETTINGS ] = str_replace( '"site_ping"', '"other_key"', $params['meta'][ AutomationPost::META_SETTINGS ] );
		$this->assertSame( 200, $this->rest_save( $params, $post_id )->get_status() );
		$this->assertTrue( pegwork_registry()->automation( 'site_ping' )['enabled'] );
		$this->assertNull( pegwork_registry()->automation( 'other_key' ) );

		// A second post cannot take an existing key.
		$response = $this->rest_save( $this->params( $this->definition() ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( '/key', $response->get_data()['data']['fields'] );
	}

	/**
	 * An invalid definition refuses the save with messages by pointer, and nothing changes.
	 */
	public function test_invalid_save_is_refused_with_pointers() {
		$definition = $this->definition();

		$definition['steps'][1]['subject'] = 'Bad {{record.nope}}';

		$response = $this->rest_save( $this->params( $definition ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pegwork_invalid_automation', $data['code'] );
		$this->assertSame( array( '/steps/1/subject' ), array_keys( $data['data']['fields'] ) );
		$this->assertNull( pegwork_registry()->automation( 'site_ping' ) );

		// A builder-level problem (a branch outside a decision) is refused before the API.
		$params            = $this->params( $this->definition() );
		$params['content'] = '<!-- wp:pegwork/automation-branch {"label":"Loose"} /-->';
		$response          = $this->rest_save( $params );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( '/steps', $response->get_data()['data']['fields'] );
	}

	/**
	 * File keys are refused with 409, both on a new post and on the file automation's own post.
	 */
	public function test_file_keys_conflict() {
		$response = $this->rest_save( $this->params( $this->definition( array( 'key' => 'job_mail' ) ) ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_automation', $response->get_data()['code'] );
		$this->assertArrayHasKey( '/key', $response->get_data()['data']['fields'] );

		AutomationPost::ensure_posts();
		$post_id = AutomationPost::find( 'job_mail' );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 409, $this->rest_save( array( 'title' => 'Changed' ), $post_id )->get_status() );
		$this->assertStringStartsWith( 'Marked urgent', pegwork_registry()->automation( 'job_mail' )['label'] );
	}

	/**
	 * Editors, subscribers and visitors get nothing from the REST routes or the screens.
	 */
	public function test_rest_and_screens_deny_editors_and_subscribers() {
		$post_id = $this->create();

		foreach ( array( $this->editor, $this->subscriber ) as $user ) {
			wp_set_current_user( $user );
			$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/pegwork-automations/' . $post_id ) )->get_status() );
			$this->assertSame( 403, $this->rest_save( array( 'title' => 'Mine' ), $post_id )->get_status() );
			$this->assertSame( 403, $this->rest_save( $this->params( $this->definition( array( 'key' => 'mine' ) ) ) )->get_status() );
			$delete = new WP_REST_Request( 'DELETE', '/wp/v2/pegwork-automations/' . $post_id );
			$this->assertSame( 403, rest_get_server()->dispatch( $delete )->get_status() );
			try {
				Screen::render();
				$this->fail( 'The list must refuse this user.' );
			} catch ( WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage() );
			}
		}
		$this->assertNull( pegwork_registry()->automation( 'mine' ) );
		$this->assertSame( 'Site ping', pegwork_registry()->automation( 'site_ping' )['label'] );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/pegwork-automations/' . $post_id ) )->get_status() );
	}

	/**
	 * Without `unfiltered_html`, email HTML (at any depth) passes through wp_kses_post().
	 */
	public function test_html_is_filtered_without_unfiltered_html() {
		$definition = $this->definition();

		$definition['steps'][1]['html']                   = '<p onclick="x()">Hi <script>alert(1)</script>{{record.title}}</p>';
		$definition['steps'][0]['branches'][0]['steps'][] = array(
			'id'      => 'inner_mail',
			'type'    => 'send_email',
			'to'      => 'desk@example.org',
			'subject' => 'Inner',
			'text'    => 'Inner',
			'html'    => '<a href="javascript:alert(1)">x</a>',
		);

		$deny = static fn( $caps, $cap ) => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$this->create( $definition );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$steps = pegwork_registry()->automation( 'site_ping' )['steps'];
		$this->assertSame( '<p>Hi alert(1){{record.title}}</p>', $steps[1]['html'] );
		$this->assertSame( '<a href="alert(1)">x</a>', $steps[0]['branches'][0]['steps'][1]['html'] );

		// With unfiltered_html, the template is kept as written.
		$definition['key'] = 'site_raw';
		$this->create( $definition );
		$this->assertStringContainsString( '<script>', pegwork_registry()->automation( 'site_raw' )['steps'][1]['html'] );
	}

	/**
	 * Trash deletes the definition (runs stay); restoring saves it again.
	 */
	public function test_trash_and_restore() {
		$post_id = $this->create();

		wp_trash_post( $post_id );
		$this->assertNull( pegwork_registry()->automation( 'site_ping' ) );

		wp_untrash_post( $post_id );
		$this->assertSame( 'private', get_post_status( $post_id ) );
		$this->assertNotNull( pegwork_registry()->automation( 'site_ping' ) );

		wp_delete_post( $post_id, true );
		$this->assertNull( pegwork_registry()->automation( 'site_ping' ) );
	}

	/**
	 * Opening a post regenerates it when the definition changed elsewhere (import, API, file edit),
	 * keeping the modified date.
	 */
	public function test_sync_by_hash() {
		$post_id = $this->create();
		$this->assertFalse( AutomationPost::sync( $post_id ), 'Unchanged definition: nothing to do.' );

		$automation            = pegwork_registry()->automation( 'site_ping' );
		$automation['label']   = 'Site ping (imported)';
		$automation['steps'][] = array(
			'id'   => 'pause',
			'type' => 'wait',
			'for'  => 'PT1H',
		);
		$this->assertTrue( pegwork_save_automation( $automation ) );

		global $wpdb;
		$old = array(
			'post_modified'     => '2026-01-02 03:04:05',
			'post_modified_gmt' => '2026-01-02 03:04:05',
		);
		$wpdb->update( $wpdb->posts, $old, array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Sets an old modified date.
		clean_post_cache( $post_id );

		$_GET = array( 'post' => (string) $post_id );
		AutomationPost::load_editor();
		$post = get_post( $post_id );
		$this->assertSame( '2026-01-02 03:04:05', $post->post_modified_gmt, 'Regeneration is not an edit.' );
		$this->assertSame( 'Site ping (imported)', $post->post_title );
		$this->assertSame( Builder::generate( pegwork_registry()->automation( 'site_ping' ) )['content'], $post->post_content );
		$this->assertSame( Builder::hash( pegwork_registry()->automation( 'site_ping' ) ), get_post_meta( $post_id, AutomationPost::META_HASH, true ) );
		$this->assertFalse( AutomationPost::sync( $post_id ) );
	}

	/**
	 * The list creates missing posts and shows Add automation, Edit (site), View and Duplicate (file).
	 * File automations are read-only in the editor.
	 */
	public function test_list_rows_and_read_only_file_automations() {
		$site_id = $this->create();
		$_GET    = array();
		ob_start();
		Screen::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'post-new.php?post_type=pegwork_automation', $html );
		$this->assertStringContainsString( '>Add automation</a>', $html );
		foreach ( array_keys( pegwork_registry()->automations() ) as $key ) {
			$this->assertGreaterThan( 0, AutomationPost::find( $key ), $key );
		}
		$this->assertSame( 0, AutomationPost::ensure_posts(), 'A second visit creates nothing.' );

		$file_id = AutomationPost::find( 'job_mail' );
		$this->assertStringContainsString( 'post.php?post=' . $site_id . '&#038;action=edit">Edit<span class="screen-reader-text"> Site ping</span>', $html );
		$this->assertStringContainsString( 'post.php?post=' . $file_id . '&#038;action=edit">View<span class="screen-reader-text"> Marked urgent', $html );
		$this->assertStringContainsString( 'pegwork_duplicate=job_mail', $html );
		$this->assertStringContainsString( 'Duplicate as site automation<span class="screen-reader-text"> Marked urgent', $html );
		$this->assertStringNotContainsString( 'pegwork_duplicate=site_ping', $html, 'Site automations are edited, not duplicated.' );

		// Read-only: no deleting, blocks locked, the inserter hidden, Duplicate in the config.
		$this->assertFalse( current_user_can( 'delete_post', $file_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $site_id ) );
		$context  = new WP_Block_Editor_Context( array( 'post' => get_post( $file_id ) ) );
		$settings = AutomationPost::editor_settings( array(), $context );
		$this->assertSame( 'all', $settings['templateLock'] );
		$this->assertArrayNotHasKey( 'templateLock', AutomationPost::editor_settings( array(), new WP_Block_Editor_Context( array( 'post' => get_post( $site_id ) ) ) ) );
		$this->assertSame( array( Builder::STEP, Builder::DECISION, Builder::BRANCH, Builder::LOOP ), AutomationPost::allowed_block_types( true, $context ) );
		$this->assertTrue( AutomationPost::allowed_block_types( true, new WP_Block_Editor_Context( array( 'post' => self::factory()->post->create_and_get() ) ) ) );

		set_current_screen( AutomationPost::TYPE );
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- post.php sets the edited post as $post; body_class() reads it with get_post().
		$GLOBALS['post'] = get_post( $file_id );
		$this->assertStringContainsString( 'pegwork-automation-readonly', BuilderEditor::body_class( 'x' ) );
		$GLOBALS['post'] = get_post( $site_id );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertSame( 'x', BuilderEditor::body_class( 'x' ) );
		unset( $GLOBALS['post'] );

		$config = BuilderEditor::config( get_post( $file_id ) );
		$this->assertTrue( $config['readOnly'] );
		$this->assertSame( 'engine', $config['package'] );
		$this->assertStringContainsString( 'pegwork_duplicate=job_mail', $config['duplicateUrl'] );
		$this->assertFalse( BuilderEditor::config( get_post( $site_id ) )['readOnly'] );

		// The blocks are registered for the editor and render nothing elsewhere.
		$this->assertSame( '', trim( do_blocks( Builder::generate( pegwork_registry()->automation( 'site_ping' ) )['content'] ) ) );
	}

	/**
	 * Add automation starts disabled; Duplicate prefills a disabled copy under a free key; a bad nonce stops.
	 */
	public function test_add_and_duplicate_prefill() {
		$_GET = array( 'post_type' => AutomationPost::TYPE );
		BuilderEditor::prepare_new();
		$post = get_default_post_to_edit( AutomationPost::TYPE, true );
		$this->assertSame( array( 'enabled' => false ), json_decode( get_post_meta( $post->ID, AutomationPost::META_SETTINGS, true ), true ) );

		$_GET = array(
			'post_type'         => AutomationPost::TYPE,
			'pegwork_duplicate' => 'job_mail',
			'_wpnonce'          => wp_create_nonce( BuilderEditor::DUPLICATE_NONCE ),
		);
		BuilderEditor::prepare_new();
		$post = get_default_post_to_edit( AutomationPost::TYPE, true );

		$source = pegwork_registry()->automation( 'job_mail' );
		$this->assertSame( $source['label'] . ' (copy)', $post->post_title );
		$this->assertSame( Builder::generate( $source )['content'], $post->post_content );
		$settings = json_decode( get_post_meta( $post->ID, AutomationPost::META_SETTINGS, true ), true );
		$this->assertSame( 'job_mail_copy', $settings['key'] );
		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( $source['trigger'], $settings['trigger'] );
		$this->assertSame( '', AutomationPost::key_of( $post->ID ), 'Nothing is saved or locked yet.' );

		// Saving the prefilled copy makes a disabled site automation.
		$response = $this->rest_save(
			array(
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => 'publish',
				'meta'    => array( AutomationPost::META_SETTINGS => get_post_meta( $post->ID, AutomationPost::META_SETTINGS, true ) ),
			),
			$post->ID
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'db', pegwork_registry()->automation_source( 'job_mail_copy' ) );
		$this->assertFalse( pegwork_registry()->automation( 'job_mail_copy' )['enabled'] );
		$this->assertSame( 'job_mail_copy_2', BuilderEditor::free_key( 'job_mail_copy' ) );

		wp_set_current_user( $this->editor );
		$_GET['_wpnonce'] = wp_create_nonce( BuilderEditor::DUPLICATE_NONCE );
		try {
			BuilderEditor::prepare_new();
			$this->fail( 'Editors cannot duplicate.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'expired', $e->getMessage() );
		}

		wp_set_current_user( $this->admin );
		$_GET['_wpnonce'] = 'bad';
		$this->expectException( WPDieException::class );
		BuilderEditor::prepare_new();
	}
}
