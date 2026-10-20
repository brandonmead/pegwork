<?php
/**
 * Integration tests: the builder (pegwork_form posts, save through the API, Forms list).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use Pegwork\Registry\Loader;
use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Builder;
use PegworkForms\BuilderEditor;
use PegworkForms\Entries;
use PegworkForms\FormPost;
use PegworkForms\FormsAdmin;
use WP_Block_Editor_Context;
use WP_REST_Request;
use WPDieException;

require_once dirname( __DIR__, 2 ) . '/Support/FormsTestCase.php';

/**
 * The `pegwork_form` post type, compile on REST save, refusals by pointer, trash, regeneration, file
 * forms read-only with Duplicate, the Forms list, and that only the forms capability gets in.
 */
class FormBuilderTest extends FormsTestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Editor ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Adds the forms-entries package (type fe_project for bound items), users, fresh REST routes.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Loader::DB_OPTION );
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/forms-entries' ) );
		Plugin::init();
		$module = require dirname( __DIR__, 3 ) . '/modules/forms/module.php';
		$module->boot();

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the form post type.
	}

	/**
	 * Drops the DB forms and the REST server.
	 */
	public function tear_down() {
		delete_option( Loader::DB_OPTION );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes.
		$_GET                      = array();
		parent::tear_down();
	}

	/**
	 * Builder markup for two unbound fields.
	 *
	 * @param string $second_label Label of the second field.
	 */
	private function content( $second_label = 'Message' ) {
		return '<!-- wp:pegwork/form-field {"type":"email","label":"Email","required":true} /-->'
			. "\n\n" . '<!-- wp:pegwork/form-field {"type":"textarea","label":"' . $second_label . '"} /-->';
	}

	/**
	 * Saves a form post through REST.
	 *
	 * @param array    $params  Body parameters.
	 * @param int|null $post_id Post to update, or null to create.
	 */
	private function rest_save( $params, $post_id = null ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/pegwork-forms' . ( $post_id ? '/' . $post_id : '' ) );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Creates a site form through REST and returns its post ID.
	 *
	 * @param string $title    Title.
	 * @param array  $settings Settings.
	 */
	private function create_form( $title = 'Feedback', $settings = array() ) {
		$response = $this->rest_save(
			array(
				'title'   => $title,
				'content' => $this->content(),
				'status'  => 'publish',
				'meta'    => array( FormPost::META_SETTINGS => wp_json_encode( (object) $settings ) ),
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data()['id'];
	}

	/**
	 * The post type is private UI only, every capability maps to the forms capability.
	 */
	public function test_post_type_and_capabilities() {
		$type = get_post_type_object( FormPost::TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->show_ui );
		$this->assertFalse( $type->show_in_menu );
		$this->assertTrue( $type->show_in_rest );
		foreach ( array( 'edit_posts', 'create_posts', 'publish_posts', 'delete_posts', 'read_private_posts', 'edit_others_posts' ) as $cap ) {
			$this->assertSame( FormPost::CAP, $type->cap->{$cap}, $cap );
		}

		$this->assertTrue( user_can( $this->admin, $type->cap->create_posts ) );
		$this->assertFalse( user_can( $this->editor, $type->cap->create_posts ) );

		$post_id = self::factory()->post->create( array( 'post_type' => FormPost::TYPE ) );
		$this->assertTrue( user_can( $this->admin, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $this->editor, 'edit_post', $post_id ) );
		$this->assertFalse( user_can( $this->editor, 'read_post', $post_id ) );
		$this->assertSame( 'private', get_post_status( $post_id ), 'Status is forced private.' );

		// The filter widens it.
		add_filter( 'pegwork_forms_capability', fn() => 'edit_others_posts' );
		$this->assertTrue( user_can( $this->editor, 'edit_post', $post_id ) );
	}

	/**
	 * A REST save compiles the post and writes the DB definition; the key comes from the title.
	 */
	public function test_rest_save_writes_definition() {
		$post_id = $this->create_form(
			'Feedback form',
			array(
				'notifications'  => array(
					array(
						'key'     => 'admin',
						'when'    => 'submitted',
						'to'      => '{admin_email}',
						'subject' => 'New feedback',
						'body'    => '{all_fields}',
					),
				),
				'retention_days' => 30,
			)
		);

		$form = pegwork_registry()->form( 'feedback_form' );
		$this->assertNotNull( $form );
		$this->assertSame( 'db', pegwork_registry()->form_source( 'feedback_form' ) );
		$this->assertSame( 'Feedback form', $form['label'] );
		$this->assertSame( array( 'email', 'message' ), array_column( $form['fields'], 'key' ) );
		$this->assertSame( 30, $form['retention_days'] );
		$this->assertSame( 'private', get_post_status( $post_id ) );
		$this->assertSame( 'feedback_form', get_post_meta( $post_id, FormPost::META_KEY, true ) );
		$this->assertSame( FormPost::find( 'feedback_form' ), $post_id );

		$settings = json_decode( get_post_meta( $post_id, FormPost::META_SETTINGS, true ), true );
		$this->assertSame( 'feedback_form', $settings['key'] );
		$this->assertSame( 'anyone', $settings['access'], 'Settings meta holds the canonical settings.' );
	}

	/**
	 * An invalid definition refuses the save with messages by pointer, and nothing changes.
	 */
	public function test_invalid_save_is_refused_with_field_messages() {
		$response = $this->rest_save(
			array(
				'title'   => 'Twice',
				'content' => '<!-- wp:pegwork/form-field {"type":"email","label":"Email"} /-->'
					. '<!-- wp:pegwork/form-field {"type":"email","label":"Email"} /-->',
				'status'  => 'publish',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pegwork_invalid_form', $data['code'] );
		$this->assertArrayHasKey( '/fields/1/key', $data['data']['fields'] );
		$this->assertStringContainsString( 'appears more than once', $data['data']['fields']['/fields/1/key'] );
		$this->assertNull( pegwork_registry()->form( 'twice' ) );

		// A builder-level problem (a field without a label) is refused before the API.
		$response = $this->rest_save(
			array(
				'title'   => 'Unlabeled',
				'content' => '<!-- wp:pegwork/form-field {"type":"text"} /-->',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( '/fields/0/label', $response->get_data()['data']['fields'] );
	}

	/**
	 * The key is locked after the first save; a second post cannot take an existing key; file keys are refused.
	 */
	public function test_key_lock_collision_and_file_keys() {
		$post_id  = $this->create_form( 'Feedback' );
		$response = $this->rest_save(
			array(
				'title' => 'Renamed',
				'meta'  => array( FormPost::META_SETTINGS => '{"key":"other"}' ),
			),
			$post_id
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed', pegwork_registry()->form( 'feedback' )['label'] );
		$this->assertNull( pegwork_registry()->form( 'other' ) );

		$response = $this->rest_save(
			array(
				'title'   => 'Feedback',
				'content' => $this->content(),
				'status'  => 'publish',
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( '/key', $response->get_data()['data']['fields'] );

		$response = $this->rest_save(
			array(
				'title'   => 'Mine now',
				'content' => $this->content(),
				'status'  => 'publish',
				'meta'    => array( FormPost::META_SETTINGS => '{"key":"fr_contact"}' ),
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_form', $response->get_data()['code'] );
		$this->assertArrayHasKey( '/key', $response->get_data()['data']['fields'] );
		$this->assertSame( 'file:forms_runtime', pegwork_registry()->form_source( 'fr_contact' ) );
	}

	/**
	 * Bound items save against the target type; registry errors come back by pointer.
	 */
	public function test_bound_items() {
		$settings = '{"target":{"content_type":"fe_project"},"access":{"capability":"read"}}';
		$content  = '<!-- wp:pegwork/form-field {"bind":{"field":"title"}} /-->'
			. '<!-- wp:pegwork/form-field {"bind":{"taxonomy":"fe_kind"},"display":"radio"} /-->';

		$response = $this->rest_save(
			array(
				'title'   => 'Suggest',
				'content' => $content,
				'status'  => 'publish',
				'meta'    => array( FormPost::META_SETTINGS => $settings ),
			)
		);
		$this->assertSame( 400, $response->get_status(), 'contact_email is required on fe_project.' );
		$this->assertSame( 'pegwork_invalid_form', $response->get_data()['code'] );
		$this->assertNotEmpty( $response->get_data()['data']['fields'] );

		$response = $this->rest_save(
			array(
				'title'   => 'Suggest',
				'content' => $content . '<!-- wp:pegwork/form-field {"bind":{"field":"contact_email"},"help":"Private."} /-->',
				'status'  => 'publish',
				'meta'    => array( FormPost::META_SETTINGS => $settings ),
			)
		);
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$form = pegwork_registry()->form( 'suggest' );
		$this->assertSame( array( 'field' => 'contact_email' ), $form['fields'][2]['bind'] );
		$this->assertSame( 'pending', $form['target']['status'] );
	}

	/**
	 * Editors and visitors get nothing from the REST routes.
	 */
	public function test_rest_denies_editors_and_visitors() {
		$post_id = $this->create_form();

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/pegwork-forms/' . $post_id ) )->get_status() );
		$this->assertSame( 403, $this->rest_save( array( 'title' => 'Mine' ), $post_id )->get_status() );
		$this->assertSame( 403, $this->rest_save( array( 'title' => 'New' ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/pegwork-forms/' . $post_id ) )->get_status() );
		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/pegwork-forms' ) );
		$this->assertContains( $list->get_status(), array( 200, 400, 401 ) );
		if ( 200 === $list->get_status() ) {
			$this->assertSame( array(), $list->get_data() );
		}
	}

	/**
	 * Trash deletes the definition (entries stay); restoring saves it again.
	 */
	public function test_trash_and_restore() {
		$post_id = $this->create_form();
		$entry   = Entries::insert(
			array(
				'form_key'    => 'feedback',
				'status'      => 'received',
				'values'      => array( 'email' => 'a@example.org' ),
				'fingerprint' => str_repeat( 'a', 64 ),
			)
		);

		wp_trash_post( $post_id );
		$this->assertNull( pegwork_registry()->form( 'feedback' ) );
		$this->assertNotNull( Entries::get( $entry ), 'Entries stay.' );

		wp_untrash_post( $post_id );
		$this->assertSame( 'private', get_post_status( $post_id ) );
		$this->assertNotNull( pegwork_registry()->form( 'feedback' ) );

		wp_delete_post( $post_id, true );
		$this->assertNull( pegwork_registry()->form( 'feedback' ) );
	}

	/**
	 * Opening a post regenerates it when the definition changed elsewhere (import, API, file edit).
	 */
	public function test_sync_regenerates_changed_definitions() {
		$post_id = $this->create_form();
		$this->assertFalse( FormPost::sync( $post_id ), 'Unchanged definition: nothing to do.' );

		$form             = pegwork_registry()->form( 'feedback' );
		$form['label']    = 'Feedback (imported)';
		$form['fields'][] = array(
			'key'   => 'phone',
			'type'  => 'text',
			'label' => 'Phone',
		);
		$this->assertTrue( pegwork_save_form( $form ) );

		global $wpdb;
		$old = array(
			'post_modified'     => '2026-01-02 03:04:05',
			'post_modified_gmt' => '2026-01-02 03:04:05',
		);
		$wpdb->update( $wpdb->posts, $old, array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Sets an old modified date.
		clean_post_cache( $post_id );

		$this->assertTrue( FormPost::sync( $post_id ) );
		$post = get_post( $post_id );
		$this->assertSame( '2026-01-02 03:04:05', $post->post_modified_gmt, 'Regeneration is not an edit: "Last edited" stays.' );
		$this->assertSame( 'Feedback (imported)', $post->post_title );
		$this->assertStringContainsString( '"key":"phone"', $post->post_content );
		$this->assertSame( Builder::generate( pegwork_registry()->form( 'feedback' ) )['content'], $post->post_content );
		$this->assertFalse( FormPost::sync( $post_id ) );
	}

	/**
	 * Visiting the Forms list creates posts for forms without one; file forms are read-only.
	 */
	public function test_forms_list_and_file_forms() {
		set_current_screen( 'pegwork_page_' . FormsAdmin::SLUG );
		Entries::insert(
			array(
				'form_key'    => 'fr_contact',
				'status'      => 'unconfirmed',
				'values'      => array(),
				'fingerprint' => str_repeat( 'b', 64 ),
			)
		);
		ob_start();
		FormsAdmin::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Contact us', $html );
		$this->assertStringContainsString( '<code>fr_<wbr>contact</code>', $html, 'Keys break after an underscore only.' );
		$this->assertStringContainsString( 'File: forms_runtime (read-only)', $html );
		$this->assertStringContainsString( 'Duplicate as site form', $html );
		$this->assertStringContainsString( esc_attr( '<!-- wp:pegwork/form {"form":"fr_contact"} /-->' ), $html );
		$this->assertStringContainsString( 'page=pegwork-entries&#038;form=fr_contact', $html );
		$this->assertStringContainsString( 'post-new.php?post_type=pegwork_form', $html );

		$post_id = FormPost::find( 'fr_contact' );
		$this->assertGreaterThan( 0, $post_id );
		foreach ( array_keys( pegwork_registry()->forms() ) as $key ) {
			$this->assertGreaterThan( 0, FormPost::find( $key ), $key );
		}
		$this->assertSame( 0, FormPost::ensure_posts(), 'A second visit creates nothing.' );

		// Read-only: no deleting, blocks locked, saves refused, Duplicate in the config.
		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );
		$settings = FormPost::editor_settings( array(), new WP_Block_Editor_Context( array( 'post' => get_post( $post_id ) ) ) );
		$this->assertSame( 'all', $settings['templateLock'] );
		set_current_screen( FormPost::TYPE );
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- post.php sets the edited post as $post; body_class() reads it with get_post().
		$GLOBALS['post'] = get_post( $post_id );
		$this->assertStringContainsString( 'pegwork-form-readonly', BuilderEditor::body_class( 'x' ), 'Read-only styles: no inserter, no "Last edited".' );
		$GLOBALS['post'] = get_post( $this->create_form() );
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertSame( 'x', BuilderEditor::body_class( 'x' ), 'Site forms stay editable.' );
		unset( $GLOBALS['post'] );
		set_current_screen( 'pegwork_page_' . FormsAdmin::SLUG );
		$config = BuilderEditor::config( get_post( $post_id ) );
		$this->assertTrue( $config['readOnly'] );
		$this->assertSame( 'forms_runtime', $config['package'] );
		$this->assertStringContainsString( 'pegwork_duplicate=fr_contact', $config['duplicateUrl'] );
		$this->assertSame( 409, $this->rest_save( array( 'title' => 'Changed' ), $post_id )->get_status() );

		$this->assertSame(
			array( Builder::FIELD, Builder::STEP ),
			FormPost::allowed_block_types( true, new WP_Block_Editor_Context( array( 'post' => get_post( $post_id ) ) ) )
		);
	}

	/**
	 * Editors cannot open the Forms list.
	 */
	public function test_forms_list_denies_editors() {
		wp_set_current_user( $this->editor );
		$this->expectException( WPDieException::class );
		FormsAdmin::render();
	}

	/**
	 * Forms sits above Entries in the Pegwork menu, for the forms capability only.
	 */
	public function test_menu_order() {
		global $submenu;
		set_current_screen( 'dashboard' );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rebuilt by admin_menu below.
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired to build the menu.
		$slugs = array_column( $submenu['pegwork'] ?? array(), 2 );
		$this->assertContains( FormsAdmin::SLUG, $slugs );
		$this->assertLessThan( array_search( 'pegwork-entries', $slugs, true ), array_search( FormsAdmin::SLUG, $slugs, true ) );
	}

	/**
	 * Duplicate prefills a new form under a free key; a bad nonce stops.
	 */
	public function test_duplicate_prefills_a_new_form() {
		$_GET = array(
			'post_type'         => FormPost::TYPE,
			'pegwork_duplicate' => 'fr_contact',
			'_wpnonce'          => wp_create_nonce( BuilderEditor::DUPLICATE_NONCE ),
		);
		BuilderEditor::prepare_duplicate();
		$post = get_default_post_to_edit( FormPost::TYPE, true );

		$this->assertSame( 'Contact us (copy)', $post->post_title );
		$this->assertSame( Builder::generate( pegwork_registry()->form( 'fr_contact' ) )['content'], $post->post_content );
		$settings = json_decode( get_post_meta( $post->ID, FormPost::META_SETTINGS, true ), true );
		$this->assertSame( 'fr_contact_copy', $settings['key'] );
		$this->assertSame( '', FormPost::key_of( $post->ID ), 'Nothing is saved or locked yet.' );

		$_GET['_wpnonce'] = 'bad';
		$this->expectException( WPDieException::class );
		BuilderEditor::prepare_duplicate();
	}

	/**
	 * The builder config lists registry types with bound field data and the Turnstile state.
	 */
	public function test_builder_config() {
		$post_id = $this->create_form();
		$config  = BuilderEditor::config( get_post( $post_id ) );

		$this->assertFalse( $config['readOnly'] );
		$this->assertSame( 'feedback', $config['lockedKey'] );
		$this->assertTrue( $config['turnstile'], 'FormsTestCase defines both keys.' );
		self::without_turnstile_keys( 'secret' );
		$this->assertFalse( BuilderEditor::config( get_post( $post_id ) )['turnstile'] );
		$types = array_column( $config['types'], null, 'key' );
		$this->assertSame( 'Project', $types['fe_project']['label'] );
		$this->assertSame( array( 'title', 'website', 'contact_email' ), array_column( $types['fe_project']['fields'], 'key' ) );
		$this->assertTrue( $types['fe_project']['fields'][2]['private'] );
		$this->assertSame( 'fe_kind', $types['fe_project']['taxonomies'][0]['key'] );
	}
}
