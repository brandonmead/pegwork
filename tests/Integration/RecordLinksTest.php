<?php
/**
 * Integration tests: Records::relate(), unrelate() and relationship keys in create() and update().
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Records;
use Pegwork\Content\Relationships;
use Pegwork\Tests\Support\RecordsTestCase;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/Support/RecordsTestCase.php';

/**
 * Link writes validate like a save of the "from" record (target type, one_to_many, required) and emit one
 * record.updated on it with the relationship key in `changed` and the previous IDs in `old`
 * (docs/automation-v3-design.md section 4.1).
 */
class RecordLinksTest extends RecordsTestCase {

	/**
	 * Asserts a write failed with a message on the relationship key.
	 *
	 * @param mixed  $result   Result.
	 * @param string $key      Relationship key.
	 * @param string $contains Text the message contains.
	 */
	private function assertLinkError( $result, string $key, string $contains ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'pegwork_invalid_record', $result->get_error_code() );
		$this->assertStringContainsString( $contains, $result->get_error_data()['fields'][ $key ] ?? '' );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Relate adds one link and emits record.updated on the "from" record; relating again changes nothing.
	 */
	public function test_relate_and_unrelate_emit_record_updated() {
		$c1   = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$c2   = $this->record( 'rq_contact', array( 'name' => 'Bea' ) );
		$gift = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );

		$this->events = array();
		$origin       = array(
			'automation' => 'barn_flow',
			'run_id'     => 12,
			'depth'      => 1,
		);
		$this->assertTrue( Records::relate( 'gift_donor', $gift, $c2, $origin ) );
		$this->assertTrue( Records::relate( 'gift_donor', $gift, $c1 ) );
		$this->assertSame( array( $c1, $c2 ), Relationships::stored_ids( $gift, 'gift_donor' ) );

		$updated = $this->events_named( 'record.updated' );
		$this->assertCount( 2, $this->events, 'One event per link write, nothing else.' );
		$this->assertSame( $gift, $updated[0]['id'] );
		$this->assertSame( 'rq_gift', $updated[0]['type'] );
		$this->assertSame( array( 'gift_donor' ), $updated[0]['changed'] );
		$this->assertSame( array( 'gift_donor' => array() ), $updated[0]['old'] );
		$this->assertSame(
			array(
				'source'     => 'records_api',
				'automation' => 'barn_flow',
				'run_id'     => 12,
				'depth'      => 1,
			),
			$updated[0]['origin']
		);
		$this->assertSame( array( 'gift_donor' => array( $c2 ) ), $updated[1]['old'] );
		$this->assertSame( 'records_api', $updated[1]['origin']['source'] );

		$this->events = array();
		$this->assertFalse( Records::relate( 'gift_donor', $gift, $c1 ), 'Already linked.' );
		$this->assertFalse( Records::unrelate( 'gift_donor', $gift, 999999 ), 'Not linked.' );
		$this->assertSame( array(), $this->events );

		$this->assertTrue( Records::unrelate( 'gift_donor', $gift, $c2 ) );
		$this->assertSame( array( $c1 ), Relationships::stored_ids( $gift, 'gift_donor' ) );
		$updated = $this->events_named( 'record.updated' );
		$this->assertCount( 1, $updated );
		$this->assertSame( array( 'gift_donor' ), $updated[0]['changed'] );
		$this->assertSame( array( 'gift_donor' => array( $c1, $c2 ) ), $updated[0]['old'] );
	}

	/**
	 * The "to" record must exist and be of the "to" type; the "from" record must be of the "from" type.
	 */
	public function test_relate_rejects_wrong_types() {
		$contact = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$gift    = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );
		$task    = $this->record( 'rq_task', array( 'name' => 'Call' ), 'draft' );

		$this->events = array();
		$this->assertLinkError( Records::relate( 'gift_donor', $gift, $task ), 'gift_donor', 'is not a Contact' );
		$this->assertLinkError( Records::relate( 'gift_donor', $gift, 999999 ), 'gift_donor', 'is not a Contact' );
		$this->assertLinkError( Records::relate( 'gift_donor', $gift, 0 ), 'gift_donor', 'is not a Contact' );
		$this->assertLinkError( Records::relate( 'gift_donor', $gift, -1 * $contact ), 'gift_donor', 'is not a Contact' );
		$this->assertLinkError( Records::relate( 'gift_donor', $contact, $gift ), 'gift_donor', 'is not a Gift' );
		$this->assertLinkError( Records::unrelate( 'gift_donor', 999999, $contact ), 'gift_donor', 'is not a Gift' );

		$unknown = Records::relate( 'nope', $gift, $contact );
		$this->assertWPError( $unknown );
		$this->assertSame( 'pegwork_unknown_relationship', $unknown->get_error_code() );

		$this->assertSame( array(), Relationships::stored_ids( $gift, 'gift_donor' ) );
		$this->assertSame( array(), $this->events );
	}

	/**
	 * Cardinality one_to_many: a "to" record linked from one "from" record cannot be linked from another.
	 */
	public function test_relate_enforces_one_to_many() {
		$gift   = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );
		$first  = $this->record( 'rq_task', array( 'name' => 'Call' ), 'draft' );
		$second = $this->record( 'rq_task', array( 'name' => 'Write' ), 'draft' );

		$this->assertTrue( Records::relate( 'task_gift', $first, $gift ) );
		$this->assertLinkError( Records::relate( 'task_gift', $second, $gift ), 'task_gift', 'is already linked from Task' );
		$this->assertFalse( Records::relate( 'task_gift', $first, $gift ), 'Its own link does not count against it.' );

		$this->assertTrue( Records::unrelate( 'task_gift', $first, $gift ) );
		$this->assertTrue( Records::relate( 'task_gift', $second, $gift ) );
	}

	/**
	 * A required relationship: a published "from" record keeps at least one link; drafts may have none.
	 */
	public function test_relate_enforces_required_on_complete_statuses() {
		$gift  = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );
		$other = $this->record( 'rq_gift', array( 'name' => 'Other' ) );

		$draft = $this->record( 'rq_task', array( 'name' => 'Call' ), 'draft' );
		$this->assertTrue( Records::relate( 'task_gift', $draft, $gift ) );
		$this->assertTrue( Records::unrelate( 'task_gift', $draft, $gift ), 'A draft may have no link.' );

		$published = Records::create( 'rq_task', array( 'name' => 'Write' ), 'publish' );
		$this->assertLinkError( $published, 'task_gift', 'At least one Gift is required' );

		$published = $this->record(
			'rq_task',
			array(
				'name'      => 'Write',
				'task_gift' => array( $gift ),
			),
			'publish'
		);
		$this->assertTrue( Records::relate( 'task_gift', $published, $other ) );
		$this->assertTrue( Records::unrelate( 'task_gift', $published, $gift ) );
		$this->assertLinkError( Records::unrelate( 'task_gift', $published, $other ), 'task_gift', 'At least one Gift is required' );
		$this->assertSame( array( $other ), Relationships::stored_ids( $published, 'task_gift' ) );
	}

	/**
	 * Records::create() accepts relationship keys as one ID, comma-separated IDs or a list, and stores them before
	 * record.created fires.
	 */
	public function test_create_with_relationship_keys() {
		$c1 = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$c2 = $this->record( 'rq_contact', array( 'name' => 'Bea' ) );

		$seen = null;
		add_action(
			'pegwork_event_record_created',
			function ( $event ) use ( &$seen ) {
				$seen = Relationships::stored_ids( $event['id'], 'gift_donor' );
			}
		);

		$one = $this->record(
			'rq_gift',
			array(
				'name'       => 'One',
				'gift_donor' => (string) $c2,
			)
		);
		$this->assertSame( array( $c2 ), Relationships::stored_ids( $one, 'gift_donor' ) );
		$this->assertSame( array( $c2 ), $seen, 'Links are stored before record.created.' );

		$csv = $this->record(
			'rq_gift',
			array(
				'name'       => 'Two',
				'gift_donor' => " {$c2}, {$c1} ,",
			)
		);
		$this->assertSame( array( $c1, $c2 ), Relationships::stored_ids( $csv, 'gift_donor' ) );

		$list = $this->record(
			'rq_gift',
			array(
				'name'       => 'Three',
				'gift_donor' => array( $c1, (string) $c2, $c1 ),
			)
		);
		$this->assertSame( array( $c1, $c2 ), Relationships::stored_ids( $list, 'gift_donor' ) );

		$int = $this->record(
			'rq_gift',
			array(
				'name'       => 'Four',
				'gift_donor' => $c1,
			)
		);
		$this->assertSame( array( $c1 ), Relationships::stored_ids( $int, 'gift_donor' ) );

		$this->assertLinkError(
			Records::create(
				'rq_gift',
				array(
					'name'       => 'Bad',
					'gift_donor' => "{$c1},abc",
				)
			),
			'gift_donor',
			'Record abc is not a Contact'
		);
		$this->assertLinkError(
			Records::create(
				'rq_gift',
				array(
					'name'       => 'Bad',
					'gift_donor' => array( $one ),
				)
			),
			'gift_donor',
			'is not a Contact'
		);

		$to_end = Records::create(
			'rq_contact',
			array(
				'name'       => 'Cy',
				'gift_donor' => array( $one ),
			)
		);
		$this->assertWPError( $to_end );
		$this->assertStringContainsString( 'Unknown field or taxonomy "gift_donor"', $to_end->get_error_data()['fields']['gift_donor'] );
	}

	/**
	 * Records::update() replaces links (empty clears) and reports the key in the one record.updated, with fields.
	 */
	public function test_update_with_relationship_keys() {
		$c1   = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$c2   = $this->record( 'rq_contact', array( 'name' => 'Bea' ) );
		$gift = $this->record(
			'rq_gift',
			array(
				'name'       => 'Gift',
				'amount'     => 5,
				'gift_donor' => array( $c1 ),
			)
		);

		$this->events = array();
		$this->assertTrue(
			Records::update(
				$gift,
				array(
					'amount'     => 6,
					'gift_donor' => array( $c2 ),
				),
				array( 'automation' => 'barn_flow' )
			)
		);
		$this->assertSame( array( $c2 ), Relationships::stored_ids( $gift, 'gift_donor' ) );
		$updated = $this->events_named( 'record.updated' );
		$this->assertCount( 1, $updated );
		$this->assertSame( array( 'amount', 'gift_donor' ), $updated[0]['changed'] );
		$this->assertSame(
			array(
				'amount'     => 5,
				'gift_donor' => array( $c1 ),
			),
			$updated[0]['old']
		);
		$this->assertSame( 'barn_flow', $updated[0]['origin']['automation'] );

		$this->events = array();
		$this->assertTrue( Records::update( $gift, array( 'amount' => 7 ) ) );
		$this->assertSame( array( $c2 ), Relationships::stored_ids( $gift, 'gift_donor' ), 'Links not given are kept.' );
		$this->assertSame( array( 'amount' ), $this->events_named( 'record.updated' )[0]['changed'] );

		$this->events = array();
		$this->assertTrue( Records::update( $gift, array( 'gift_donor' => '' ) ) );
		$this->assertSame( array(), Relationships::stored_ids( $gift, 'gift_donor' ) );
		$this->assertSame( array( 'gift_donor' => array( $c2 ) ), $this->events_named( 'record.updated' )[0]['old'] );

		$this->events = array();
		$this->assertTrue( Records::update( $gift, array( 'gift_donor' => array() ) ) );
		$this->assertSame( array(), $this->events, 'An unchanged set emits nothing.' );

		$this->assertLinkError( Records::update( $gift, array( 'gift_donor' => array( $gift ) ) ), 'gift_donor', 'is not a Contact' );
		$this->assertSame( array(), Relationships::stored_ids( $gift, 'gift_donor' ) );
	}

	/**
	 * Records::update() applies one_to_many and required through the same validator.
	 */
	public function test_update_validates_one_to_many_and_required() {
		$gift  = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );
		$task  = $this->record(
			'rq_task',
			array(
				'name'      => 'Call',
				'task_gift' => $gift,
			),
			'publish'
		);
		$other = $this->record( 'rq_task', array( 'name' => 'Write' ), 'draft' );

		$this->assertLinkError( Records::update( $other, array( 'task_gift' => $gift ) ), 'task_gift', 'is already linked from Task' );
		$this->assertLinkError( Records::update( $task, array( 'task_gift' => '' ) ), 'task_gift', 'At least one Gift is required' );
		$this->assertTrue( Records::update( $task, array( 'task_gift' => array( $gift ) ) ) );
		$this->assertSame( array( $gift ), Relationships::stored_ids( $task, 'task_gift' ) );
	}

	/**
	 * A REST save that changes only links now emits record.updated with the relationship key.
	 */
	public function test_rest_link_change_emits_record_updated() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$c1   = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$gift = $this->record( 'rq_gift', array( 'name' => 'Gift' ) );

		$this->events = array();
		$request      = new WP_REST_Request( 'POST', '/wp/v2/rq_gift/' . $gift );
		$request->set_param( 'meta', array( '_rel_gift_donor' => array( $c1 ) ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$updated = $this->events_named( 'record.updated' );
		$this->assertCount( 1, $updated );
		$this->assertSame( array( 'gift_donor' ), $updated[0]['changed'] );
		$this->assertSame( array( 'gift_donor' => array() ), $updated[0]['old'] );
		$this->assertSame( 'rest', $updated[0]['origin']['source'] );
	}

	/**
	 * Records::get() is unchanged: values hold fields and taxonomies, not links.
	 */
	public function test_get_does_not_carry_links() {
		$c1   = $this->record( 'rq_contact', array( 'name' => 'Ada' ) );
		$gift = $this->record(
			'rq_gift',
			array(
				'name'       => 'Gift',
				'gift_donor' => array( $c1 ),
			)
		);

		$this->assertArrayNotHasKey( 'gift_donor', Records::get( $gift )['values'] );
	}
}
