<?php
/**
 * Integration test: relationship meta, REST links, validation, reverse queries and delete cleanup.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\RecordValidator;
use Pegwork\Plugin;
use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Uses examples/sifg (story_projects, many_to_many, required) and the complete fixture
 * (event_venue, one_to_many; event_related, required with a label).
 */
class RelationshipsTest extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Registers both packages, rebuilds REST routes and logs in an editor.
	 */
	public function set_up() {
		parent::set_up();
		$root = dirname( __DIR__, 2 );
		add_action(
			'pegwork_register',
			function ( RegistryBuilder $builder ) use ( $root ) {
				$builder->add_package( $root . '/examples/sifg' );
				$builder->add_package( $root . '/tests/fixtures/valid/complete' );
			}
		);
		$this->reregister();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Rebuilds the registry without the packages.
	 */
	public function tear_down() {
		parent::tear_down();
		$this->reregister();
	}

	/**
	 * Re-runs registration and drops cached REST routes.
	 */
	private function reregister() {
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.
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
	 * Creates a post of a type.
	 *
	 * @param string $type   Post type.
	 * @param string $title  Title.
	 * @param string $status Status.
	 * @return int Post ID.
	 */
	private function post( $type, $title, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Body of a Story save.
	 *
	 * @param int[]  $projects Linked Project IDs.
	 * @param string $status   Status.
	 */
	private function story( $projects, $status = 'publish' ) {
		return array(
			'title'   => 'How the map helped',
			'content' => '<!-- wp:paragraph --><p>It helped.</p><!-- /wp:paragraph -->',
			'status'  => $status,
			'meta'    => array( '_rel_story_projects' => $projects ),
		);
	}

	/**
	 * The meta is registered on the "from" type only: multiple integer rows, positive.
	 */
	public function test_meta_registered_on_from_type() {
		$keys = get_registered_meta_keys( 'post', 'sifg_story' );

		$this->assertArrayHasKey( '_rel_story_projects', $keys );
		$this->assertFalse( $keys['_rel_story_projects']['single'] );
		$this->assertSame( 'integer', $keys['_rel_story_projects']['type'] );
		$this->assertSame( 1, $keys['_rel_story_projects']['show_in_rest']['schema']['minimum'] );
		$this->assertArrayNotHasKey( '_rel_story_projects', get_registered_meta_keys( 'post', 'sifg_project' ) );
	}

	/**
	 * A Story created with Project IDs reads them back, one meta row per ID; an update diffs rows.
	 */
	public function test_rest_round_trip() {
		$a = $this->post( 'sifg_project', 'Clean water map' );
		$b = $this->post( 'sifg_project', 'Food bank routing', 'draft' );

		$response = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $a, $b ) ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id = $response->get_data()['id'];

		$this->assertEqualsCanonicalizing( array( $a, $b ), $this->rest( 'GET', "/wp/v2/stories/$id" )->get_data()['meta']['_rel_story_projects'] );
		$this->assertCount( 2, get_post_meta( $id, '_rel_story_projects', false ) );

		$response = $this->rest( 'POST', "/wp/v2/stories/$id", array( 'meta' => array( '_rel_story_projects' => array( $b ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $b ), $response->get_data()['meta']['_rel_story_projects'] );
		$this->assertSame( array( $b ), pegwork_related_ids( $id, 'story_projects' ) );

		// Saving other fields leaves stored links in place.
		$response = $this->rest( 'POST', "/wp/v2/stories/$id", array( 'title' => 'How routing helped' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $b ), pegwork_related_ids( $id, 'story_projects' ) );
	}

	/**
	 * Publishing a Story with no Project fails with a field-level message; a draft saves.
	 */
	public function test_required_on_publish_only() {
		$response = $this->rest( 'POST', '/wp/v2/stories', $this->story( array() ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );
		$this->assertSame( array( 'story_projects' => 'At least one Project is required.' ), $response->get_data()['data']['fields'] );

		$draft = $this->rest( 'POST', '/wp/v2/stories', $this->story( array(), 'draft' ) );
		$this->assertSame( 201, $draft->get_status() );
		$id = $draft->get_data()['id'];

		$publish = $this->rest( 'POST', "/wp/v2/stories/$id", array( 'status' => 'pending' ) );
		$this->assertSame( 400, $publish->get_status() );
		$this->assertArrayHasKey( 'story_projects', $publish->get_data()['data']['fields'] );

		// Removing the last link from a published Story also fails.
		$project = $this->post( 'sifg_project', 'Tutor matching' );
		$id      = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $project ) ) )->get_data()['id'];
		$clear   = $this->rest( 'POST', "/wp/v2/stories/$id", array( 'meta' => array( '_rel_story_projects' => array() ) ) );
		$this->assertSame( 400, $clear->get_status() );
		$this->assertSame( array( $project ), pegwork_related_ids( $id, 'story_projects' ) );
	}

	/**
	 * IDs that are missing or of another type fail on every save, drafts included; a trashed target still counts.
	 */
	public function test_target_type_checked_on_every_save() {
		$page     = $this->post( 'page', 'About' );
		$response = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $page ), 'draft' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'story_projects' => "Record $page is not a Project." ), $response->get_data()['data']['fields'] );

		$response = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( 999999 ), 'draft' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );

		$response = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( 0 ), 'draft' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'story_projects' => 'Record 0 is not a Project.' ), $response->get_data()['data']['fields'] );

		$trashed = $this->post( 'sifg_project', 'Paused idea' );
		wp_trash_post( $trashed );
		$this->assertSame( 201, $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $trashed ) ) )->get_status() );
	}

	/**
	 * `one_to_many`: a "to" record is linked from at most one "from" record; re-saving your own link is fine.
	 */
	public function test_one_to_many_limit() {
		$venue = $this->post( 'demo_venue', 'Town hall' );
		$event = fn( $title ) => array(
			'title'  => $title,
			'status' => 'draft',
			'meta'   => array( '_rel_event_venue' => array( $venue ) ),
		);

		$first = $this->rest( 'POST', '/wp/v2/events', $event( 'Spring fair' ) );
		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$first_id = $first->get_data()['id'];

		$second = $this->rest( 'POST', '/wp/v2/events', $event( 'Summer fair' ) );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( array( 'event_venue' => 'Venue "Town hall" is already linked from Event "Spring fair".' ), $second->get_data()['data']['fields'] );

		$again = $this->rest( 'POST', "/wp/v2/events/$first_id", $event( 'Spring fair' ) );
		$this->assertSame( 200, $again->get_status() );

		// many_to_many has no such limit: two Events may share a related venue.
		$related = array(
			'title'  => 'Autumn fair',
			'status' => 'draft',
			'meta'   => array( '_rel_event_related' => array( $venue ) ),
		);
		$this->assertSame( 201, $this->rest( 'POST', '/wp/v2/events', $related )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', '/wp/v2/events', $related )->get_status() );

		// The same rules through the public validator.
		$this->assertWPError( pegwork_validate_record( 'demo_event', array( 'event_venue' => array( $venue ) ) ) );
		$result = RecordValidator::validate( pegwork_registry(), 'demo_event', array( 'event_venue' => array( $venue ) ), false, $first_id );
		$this->assertTrue( $result );
	}

	/**
	 * The `rel_{key}` collection param lists the Stories linked to a Project; status visibility stays core's.
	 */
	public function test_reverse_query_param() {
		$project = $this->post( 'sifg_project', 'Clean water map' );
		$other   = $this->post( 'sifg_project', 'Food bank routing' );
		$linked  = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $project, $other ) ) )->get_data()['id'];
		$draft   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $project ), 'draft' ) )->get_data()['id'];
		$this->rest( 'POST', '/wp/v2/stories', $this->story( array( $other ) ) );

		$params = $this->rest( 'OPTIONS', '/wp/v2/stories' )->get_data()['endpoints'][0]['args'];
		$this->assertSame( 'integer', $params['rel_story_projects']['type'] );

		$ids = wp_list_pluck( $this->rest( 'GET', '/wp/v2/stories', array( 'rel_story_projects' => $project ) )->get_data(), 'id' );
		$this->assertSame( array( $linked ), $ids, 'Default status is publish.' );

		$ids = wp_list_pluck(
			$this->rest(
				'GET',
				'/wp/v2/stories',
				array(
					'rel_story_projects' => $project,
					'status'             => 'publish,draft',
				)
			)->get_data(),
			'id'
		);
		$this->assertEqualsCanonicalizing( array( $linked, $draft ), $ids );

		wp_set_current_user( 0 );
		$this->assertSame( 400, $this->rest( 'GET', '/wp/v2/stories', array( 'rel_story_projects' => 'abc' ) )->get_status() );
		$anonymous = $this->rest( 'GET', '/wp/v2/stories', array( 'rel_story_projects' => $project ) )->get_data();
		$this->assertSame( array( $linked ), wp_list_pluck( $anonymous, 'id' ) );
		$this->assertGreaterThanOrEqual(
			400,
			$this->rest(
				'GET',
				'/wp/v2/stories',
				array(
					'rel_story_projects' => $project,
					'status'             => 'draft',
				)
			)->get_status(),
			'Anonymous readers cannot list drafts.'
		);
	}

	/**
	 * Links are IDs only: no private field of the linked record reaches anonymous readers, and anonymous writes fail.
	 */
	public function test_private_data_not_exposed() {
		$project = $this->post( 'sifg_project', 'Clean water map' );
		update_post_meta( $project, '_sifg_project_contact_email', 'team@example.org' );
		$story = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $project ) ) )->get_data()['id'];

		wp_set_current_user( 0 );
		$response = $this->rest( 'GET', "/wp/v2/stories/$story" );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $project ), $response->get_data()['meta']['_rel_story_projects'] );
		$this->assertStringNotContainsString( 'team@example.org', wp_json_encode( $response->get_data() ) );

		$list = $this->rest( 'GET', '/wp/v2/stories', array( 'rel_story_projects' => $project ) );
		$this->assertStringNotContainsString( 'team@example.org', wp_json_encode( $list->get_data() ) );

		$write = $this->rest( 'POST', "/wp/v2/stories/$story", array( 'meta' => array( '_rel_story_projects' => array() ) ) );
		$this->assertSame( 401, $write->get_status() );
		$this->assertSame( array( $project ), pegwork_related_ids( $story, 'story_projects' ) );

		// A contributor may not link to someone else's Story.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$write = $this->rest( 'POST', "/wp/v2/stories/$story", array( 'meta' => array( '_rel_story_projects' => array( $project ) ) ) );
		$this->assertSame( 403, $write->get_status() );
	}

	/**
	 * Deleting a "to" record removes it from every link; trashing keeps links; removing the relationship keeps meta.
	 */
	public function test_delete_cleanup() {
		$keep  = $this->post( 'sifg_project', 'Clean water map' );
		$gone  = $this->post( 'sifg_project', 'Food bank routing' );
		$one   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $keep, $gone ) ) )->get_data()['id'];
		$two   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $gone ), 'draft' ) )->get_data()['id'];
		$trash = $this->post( 'sifg_project', 'Paused idea' );
		add_post_meta( $one, '_rel_story_projects', $trash );

		wp_trash_post( $trash );
		$this->assertEqualsCanonicalizing( array( $keep, $gone, $trash ), pegwork_related_ids( $one, 'story_projects' ) );

		wp_delete_post( $gone, true );
		$this->assertEqualsCanonicalizing( array( $keep, $trash ), pegwork_related_ids( $one, 'story_projects' ) );
		$this->assertSame( array(), pegwork_related_ids( $two, 'story_projects' ) );
		$this->assertSame( array(), get_post_meta( $two, '_rel_story_projects', false ) );

		remove_all_actions( 'pegwork_register' );
		$this->reregister();
		$this->assertFalse( registered_meta_key_exists( 'post', '_rel_story_projects', 'sifg_story' ) );
		$this->assertCount( 2, get_post_meta( $one, '_rel_story_projects', false ) );
	}

	/**
	 * The Records API, which does not write links, keeps a linked Story valid on update and enforces `required` on create.
	 */
	public function test_records_api_uses_stored_links() {
		$project = $this->post( 'sifg_project', 'Clean water map' );
		$story   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $project ) ) )->get_data()['id'];

		$this->assertTrue( \Pegwork\Api\Records::update( $story, array( 'title' => 'Renamed story' ) ) );
		$this->assertSame( array( $project ), pegwork_related_ids( $story, 'story_projects' ) );

		$values  = array(
			'title' => 'New story',
			'body'  => '<p>It helped.</p>',
		);
		$created = \Pegwork\Api\Records::create( 'sifg_story', $values, 'publish' );
		$this->assertWPError( $created );
		$this->assertSame( array( 'story_projects' => 'At least one Project is required.' ), $created->get_error_data()['fields'] );
		$this->assertIsInt( \Pegwork\Api\Records::create( 'sifg_story', $values, 'draft' ) );

		$this->assertTrue( pegwork_validate_record( 'sifg_story', $values, $story ) );
		$this->assertWPError( pegwork_validate_record( 'sifg_story', $values ) );
	}

	/**
	 * The helpers work from both ends and return all statuses; query args run as WP_Query.
	 */
	public function test_related_helpers() {
		$a     = $this->post( 'sifg_project', 'Clean water map' );
		$b     = $this->post( 'sifg_project', 'Food bank routing' );
		$none  = $this->post( 'sifg_project', 'Tutor matching' );
		$one   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $b, $a ) ) )->get_data()['id'];
		$two   = $this->rest( 'POST', '/wp/v2/stories', $this->story( array( $a ), 'draft' ) )->get_data()['id'];
		$story = get_post( $one );

		$this->assertSame( array( $a, $b ), pegwork_related_ids( $story, 'story_projects' ) );
		$this->assertSame( array( $one, $two ), pegwork_related_ids( $a, 'story_projects' ) );
		$this->assertSame( array(), pegwork_related_ids( $none, 'story_projects' ) );
		$this->assertSame( array(), pegwork_related_ids( $a, 'missing' ) );
		$this->assertSame( array(), pegwork_related_ids( $this->post( 'page', 'About' ), 'story_projects' ) );

		$args = pegwork_related_query_args( $a, 'story_projects' );
		$this->assertSame( 'sifg_story', $args['post_type'] );
		$query = new WP_Query( $args + array( 'fields' => 'ids' ) );
		$this->assertSame( array( $one ), array_map( 'intval', $query->posts ), 'Caller statuses: WP_Query defaults to publish.' );

		$args = pegwork_related_query_args( $one, 'story_projects' );
		$this->assertSame(
			array(
				'post_type' => 'sifg_project',
				'post__in'  => array( $a, $b ),
			),
			$args
		);

		$empty = $this->rest( 'POST', '/wp/v2/stories', $this->story( array(), 'draft' ) )->get_data()['id'];
		$this->assertSame( array( 0 ), pegwork_related_query_args( $empty, 'story_projects' )['post__in'] );
		$this->assertSame( array( 'post__in' => array( 0 ) ), pegwork_related_query_args( $a, 'missing' ) );
		$this->assertSame( array(), ( new WP_Query( pegwork_related_query_args( $empty, 'story_projects' ) ) )->posts );
	}
}
