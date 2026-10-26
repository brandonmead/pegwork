<?php
/**
 * Integration test: record writes outside the block editor keep registry rules (QA C1, C4, C5, C6) and REST
 * view responses keep meta of protected and unreadable records hidden (QA SEC-2, SEC-4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Notices;
use Pegwork\Api\Records;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Uses examples/sifg: Project requires name, a 50-word description, contact email, a type and a status.
 */
class RecordIntegrityTest extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Registers examples/sifg and logs in an editor.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		Plugin::init();
		Taxonomies::seed( Plugin::registry() );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Drops request globals and the registry.
	 */
	public function tear_down() {
		unset( $_REQUEST['bulk_edit'], $_POST['action'] );
		set_current_screen( 'front' );
		parent::tear_down();
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * Values of a complete Project.
	 *
	 * @param string $name Name.
	 */
	private function complete( string $name ): array {
		return array(
			'name'                => $name,
			'description'         => '<p>' . implode( ' ', array_fill( 0, 60, 'word' ) ) . '</p>',
			'contact_email'       => 'team@example.org',
			'website'             => 'https://example.org',
			'sifg_project_type'   => array( 'nonprofit' ),
			'sifg_project_status' => array( 'active' ),
		);
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 */
	private function rest( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * QA C1: Bulk Edit to Published keeps incomplete records at their old status and says so; complete ones publish.
	 */
	public function test_bulk_edit_does_not_publish_incomplete_records() {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$incomplete = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'draft',
				'post_title'  => 'Half done',
			)
		);
		$complete   = Records::create( 'sifg_project', $this->complete( 'All there' ), 'draft' );
		$this->assertIsInt( $complete );

		set_current_screen( 'edit-sifg_project' );
		$_REQUEST['bulk_edit'] = 'Update';
		bulk_edit_posts(
			array(
				'post_type' => 'sifg_project',
				'post'      => array( $incomplete, $complete ),
				'_status'   => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $incomplete ) );
		$this->assertSame( 'publish', get_post_status( $complete ) );

		ob_start();
		Notices::render();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'Half done', $notice );
		$this->assertStringNotContainsString( 'All there', $notice );

		ob_start();
		Notices::render();
		$this->assertStringNotContainsString( 'Half done', (string) ob_get_clean(), 'The notice shows once.' );
	}

	/**
	 * QA C1: the admin-edit check leaves other saves alone (programmatic inserts keep working).
	 */
	public function test_programmatic_insert_is_not_checked() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * QA C4: an impossible date is rejected, not saved as empty.
	 */
	public function test_rest_rejects_impossible_date() {
		$id = Records::create( 'sifg_project', $this->complete( 'Dated' ) + array( 'last_verified' => '2026-01-02' ), 'publish' );
		$this->assertIsInt( $id );

		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_last_verified' => '2026-02-30' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'last_verified', $response->get_data()['data']['fields'] );
		$this->assertSame( '2026-01-02', get_post_meta( $id, '_sifg_project_last_verified', true ) );
	}

	/**
	 * QA C5: url fields take web addresses only, through REST and the Records API.
	 */
	public function test_url_fields_take_web_addresses_only() {
		$id = Records::create( 'sifg_project', $this->complete( 'Linked' ), 'publish' );
		$this->assertIsInt( $id );

		foreach ( array( 'ftp://example.org/file', 'mailto:a@example.org', 'https://user:pw@example.org', 'notaurl' ) as $bad ) {
			$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_website' => $bad ) ) );
			$this->assertSame( 400, $response->get_status(), $bad );
			$this->assertSame( 'https://example.org', get_post_meta( $id, '_sifg_project_website', true ), $bad );

			$result = Records::update( $id, array( 'website' => $bad ) );
			$this->assertWPError( $result, $bad );
			$this->assertSame( 'pegwork_invalid_record', $result->get_error_code() );
		}

		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_website' => 'https://example.com/a b' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://example.com/a%20b', get_post_meta( $id, '_sifg_project_website', true ) );
		$this->assertTrue( Records::update( $id, array( 'website' => 'example.net' ) ) );
		$this->assertSame( 'https://example.net', get_post_meta( $id, '_sifg_project_website', true ) );
	}

	/**
	 * QA C6: create() refuses internal statuses; update() refuses trashed records and auto-drafts.
	 */
	public function test_records_api_refuses_internal_statuses_and_trashed_records() {
		foreach ( array( 'trash', 'inherit', 'auto-draft' ) as $status ) {
			$result = Records::create( 'sifg_project', array( 'name' => 'Odd' ), $status );
			$this->assertWPError( $result, $status );
			$this->assertSame( 'pegwork_invalid_status', $result->get_error_code(), $status );
		}

		$id = Records::create( 'sifg_project', array( 'name' => 'Binned' ), 'draft' );
		wp_trash_post( $id );
		$events = 0;
		add_action(
			'pegwork_event',
			function () use ( &$events ) {
				++$events;
			}
		);
		$result = Records::update( $id, array( 'who_for' => 'Nobody' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'pegwork_record_not_found', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( $id, '_sifg_project_who_for', true ) );
		$this->assertSame( 0, $events );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'auto-draft',
			)
		);
		$this->assertSame( 'pegwork_record_not_found', Records::update( $draft, array( 'who_for' => 'x' ) )->get_error_code() );
	}

	/**
	 * QA SEC-2: the REST view of a password-protected record hides Pegwork meta and links until the password is given.
	 */
	public function test_password_protected_record_hides_pegwork_meta() {
		$project = Records::create( 'sifg_project', $this->complete( 'Guarded' ), 'publish' );
		wp_update_post(
			array(
				'ID'            => $project,
				'post_password' => 'sesame',
			)
		);
		$story = Records::create(
			'sifg_story',
			array(
				'title'          => 'Guarded story',
				'body'           => '<p>Text</p>',
				'story_projects' => array( $project ),
			),
			'publish'
		);
		wp_update_post(
			array(
				'ID'            => $story,
				'post_password' => 'sesame',
			)
		);
		update_post_meta( $project, 'other_plugin_meta', 'kept' );

		wp_set_current_user( 0 );
		$meta = $this->rest( 'GET', "/wp/v2/projects/$project" )->get_data()['meta'];
		$this->assertArrayNotHasKey( '_sifg_project_website', $meta );
		$this->assertArrayNotHasKey( '_sifg_project_needs_volunteers', $meta );
		$this->assertArrayNotHasKey( '_rel_story_projects', $this->rest( 'GET', "/wp/v2/stories/$story" )->get_data()['meta'] ?? array() );

		$list = $this->rest( 'GET', '/wp/v2/projects' )->get_data();
		$this->assertStringNotContainsString( '_sifg_project_website', (string) wp_json_encode( $list ) );

		$meta = $this->rest( 'GET', "/wp/v2/projects/$project", array( 'password' => 'sesame' ) )->get_data()['meta'];
		$this->assertSame( 'https://example.org', $meta['_sifg_project_website'] );

		wp_set_current_user( $this->editor );
		$meta = $this->rest( 'GET', "/wp/v2/projects/$project", array( 'context' => 'edit' ) )->get_data()['meta'];
		$this->assertSame( 'https://example.org', $meta['_sifg_project_website'] );
	}

	/**
	 * QA SEC-4: in view context, a record's links list only records the reader may read.
	 */
	public function test_view_context_hides_links_to_unreadable_records() {
		$public  = Records::create( 'sifg_project', $this->complete( 'Open' ), 'publish' );
		$private = Records::create( 'sifg_project', $this->complete( 'Closed' ), 'private' );
		$story   = Records::create(
			'sifg_story',
			array(
				'title'          => 'Both',
				'body'           => '<p>Text</p>',
				'story_projects' => array( $public, $private ),
			),
			'publish'
		);

		wp_set_current_user( 0 );
		$this->assertSame( array( $public ), $this->rest( 'GET', "/wp/v2/stories/$story" )->get_data()['meta']['_rel_story_projects'] );
		$list = $this->rest( 'GET', '/wp/v2/stories' )->get_data();
		$this->assertSame( array( $public ), $list[0]['meta']['_rel_story_projects'] );

		wp_set_current_user( $this->editor );
		$this->assertSame( array( $public, $private ), $this->rest( 'GET', "/wp/v2/stories/$story" )->get_data()['meta']['_rel_story_projects'] );
		$this->assertSame( array( $public, $private ), $this->rest( 'GET', "/wp/v2/stories/$story", array( 'context' => 'edit' ) )->get_data()['meta']['_rel_story_projects'] );
	}
}
