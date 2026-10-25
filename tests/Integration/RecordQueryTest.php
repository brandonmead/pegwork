<?php
/**
 * Integration tests: Records::find() and Content\RecordQuery.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\RecordsTestCase;

require_once dirname( __DIR__ ) . '/Support/RecordsTestCase.php';

/**
 * Every operator by field type, statuses, both relationship directions, sort, limit, SQL counts and sums,
 * empty operands and query validation (docs/automation-v3-design.md sections 3.1 and 4.1).
 */
class RecordQueryTest extends RecordsTestCase {

	/**
	 * Gift IDs by name: g1 to g4 are readable by default (private, except g4 published), g5 is a draft,
	 * g6 is trashed.
	 *
	 * @var array<string, int>
	 */
	private array $g = array();

	/**
	 * Contact IDs c1 to c3.
	 *
	 * @var array<string, int>
	 */
	private array $c = array();

	/**
	 * Seeds the gifts and contacts.
	 *
	 * Table (name, amount, fee, received_on, method, anonymous, code, tags, fund, donors):
	 *
	 *        name     amount  fee   received_on  method  anonymous  code  tags              fund     donors
	 *   g1   Alpha    100     2.5   2027-03-01   card    true       A-1   monthly, matched  general  c1
	 *   g2   Bravo    9       0.25  2027-03-02   check   false      B-2   monthly           barn     c1, c2
	 *   g3   Charlie  250.75  -     -            cash    -          -     -                 -        c2
	 *   g4   Delta    -       -     2027-03-10   -       true       D-4   event             general  -     (publish)
	 *   g5   Echo     40      -     -            -       -          -     -                 -        c1    (draft)
	 *   g6   Foxtrot  1000    -     -            -       -          -     -                 -        c1    (trash)
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'c1', 'c2', 'c3' ) as $name ) {
			$this->c[ $name ] = $this->record( 'rq_contact', array( 'name' => $name ) );
		}

		$this->g['g1'] = $this->record(
			'rq_gift',
			array(
				'name'        => 'Alpha',
				'amount'      => 100,
				'fee'         => 2.5,
				'received_on' => '2027-03-01',
				'method'      => 'card',
				'anonymous'   => true,
				'donor_email' => 'a@example.org',
				'code'        => 'A-1',
				'rq_tag'      => array( 'monthly', 'matched' ),
				'rq_fund'     => array( 'general' ),
				'gift_donor'  => array( $this->c['c1'] ),
			)
		);
		$this->g['g2'] = $this->record(
			'rq_gift',
			array(
				'name'        => 'Bravo',
				'amount'      => 9,
				'fee'         => 0.25,
				'received_on' => '2027-03-02',
				'method'      => 'check',
				'anonymous'   => false,
				'donor_email' => 'b@example.org',
				'code'        => 'B-2',
				'rq_tag'      => array( 'monthly' ),
				'rq_fund'     => array( 'barn' ),
				'gift_donor'  => $this->c['c1'] . ',' . $this->c['c2'],
			)
		);
		$this->g['g3'] = $this->record(
			'rq_gift',
			array(
				'name'       => 'Charlie',
				'amount'     => 250.75,
				'method'     => 'cash',
				'gift_donor' => $this->c['c2'],
			)
		);
		$this->g['g4'] = $this->record(
			'rq_gift',
			array(
				'name'        => 'Delta',
				'received_on' => '2027-03-10',
				'anonymous'   => true,
				'code'        => 'D-4',
				'rq_tag'      => array( 'event' ),
				'rq_fund'     => array( 'general' ),
			),
			'publish'
		);
		$this->g['g5'] = $this->record(
			'rq_gift',
			array(
				'name'       => 'Echo',
				'amount'     => 40,
				'gift_donor' => array( $this->c['c1'] ),
			),
			'draft'
		);
		$this->g['g6'] = $this->record(
			'rq_gift',
			array(
				'name'       => 'Foxtrot',
				'amount'     => 1000,
				'gift_donor' => array( $this->c['c1'] ),
			)
		);
		wp_trash_post( $this->g['g6'] );
	}

	/**
	 * Finds gifts and returns their IDs.
	 *
	 * @param array $query Query; limit 50 unless given.
	 * @return int[]
	 */
	private function gifts( array $query ): array {
		$result = Records::find( 'rq_gift', $query + array( 'limit' => 50 ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result['ids'];
	}

	/**
	 * Gift IDs by name.
	 *
	 * @param string ...$names Names (g1 to g6).
	 * @return int[]
	 */
	private function ids( string ...$names ): array {
		return array_map( fn( $name ) => $this->g[ $name ], $names );
	}

	/**
	 * Gifts matching one filter leaf.
	 *
	 * @param string $field Field key.
	 * @param string $op    Operator.
	 * @param mixed  $value Operand (omitted for unary operators).
	 * @return int[]
	 */
	private function where( string $field, string $op, mixed $value = null ): array {
		$leaf = array(
			'field' => $field,
			'op'    => $op,
		);
		if ( null !== $value ) {
			$leaf['value'] = $value;
		}

		return $this->gifts( array( 'filter' => array( 'all' => array( $leaf ) ) ) );
	}

	/**
	 * Asserts a query fails with a reason.
	 *
	 * @param string $reason Expected data reason.
	 * @param array  $query  Query.
	 * @param string $type   Content type.
	 */
	private function assertInvalid( string $reason, array $query, string $type = 'rq_gift' ): void {
		$result = Records::find( $type, $query );
		$this->assertWPError( $result );
		$this->assertSame( 'pegwork_invalid_query', $result->get_error_code() );
		$this->assertSame( $reason, $result->get_error_data()['reason'], $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Number operators; ne and not_in include records with no stored value.
	 */
	public function test_number_operators() {
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'amount', 'eq', 100 ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'amount', 'eq', '100' ) );
		$this->assertSame( $this->ids( 'g3' ), $this->where( 'amount', 'eq', 250.75 ) );
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $this->where( 'amount', 'ne', 100 ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'amount', 'in', array( 9, 250.75 ) ) );
		$this->assertSame( $this->ids( 'g1', 'g3', 'g4' ), $this->where( 'amount', 'not_in', array( 9 ) ) );
		$this->assertSame( $this->ids( 'g2' ), $this->where( 'amount', 'lt', 100 ) );
		$this->assertSame( $this->ids( 'g1', 'g2' ), $this->where( 'amount', 'lte', 100 ) );
		$this->assertSame( $this->ids( 'g3' ), $this->where( 'amount', 'gt', 100 ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3' ), $this->where( 'amount', 'gte', 9 ) );
		$this->assertSame( $this->ids( 'g4' ), $this->where( 'amount', 'empty' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3' ), $this->where( 'amount', 'not_empty' ) );
	}

	/**
	 * Date operators with dates and date-times: a date field is the site-local day at midnight.
	 */
	public function test_date_operators() {
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'eq', '2027-03-01' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'lt', '2027-03-02' ) );
		$this->assertSame( $this->ids( 'g2', 'g4' ), $this->where( 'received_on', 'gte', '2027-03-02' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g4' ), $this->where( 'received_on', 'lte', '2027-03-10' ) );
		$this->assertSame( $this->ids( 'g3' ), $this->where( 'received_on', 'empty' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g4' ), $this->where( 'received_on', 'not_empty' ) );

		// 2027-03-01 (midnight) is before 09:00 that day; a date never equals a time after midnight.
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'lt', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'lte', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g2', 'g4' ), $this->where( 'received_on', 'gte', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g2', 'g4' ), $this->where( 'received_on', 'gt', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( array(), $this->where( 'received_on', 'eq', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3', 'g4' ), $this->where( 'received_on', 'ne', '2027-03-01T09:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'eq', '2027-03-01T00:00:00+00:00' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'lt', '2027-03-02T00:00:00Z' ) );
	}

	/**
	 * A date-time operand is read in the site time zone.
	 */
	public function test_date_time_operand_uses_site_time_zone() {
		update_option( 'timezone_string', 'America/New_York' );

		// 2027-03-02T03:00Z is 22:00 on 03-01 in New York.
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'received_on', 'lte', '2027-03-02T03:00:00Z' ) );
		$this->assertSame( $this->ids( 'g2', 'g4' ), $this->where( 'received_on', 'gt', '2027-03-02T03:00:00Z' ) );
	}

	/**
	 * Text, select, email (private) and URL operators. Text equality follows the database collation
	 * (case-insensitive by default).
	 */
	public function test_text_select_and_email_operators() {
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'code', 'eq', 'A-1' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'code', 'eq', 'a-1' ) );
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $this->where( 'code', 'ne', 'A-1' ) );
		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'code', 'in', array( 'A-1', 'D-4' ) ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'code', 'not_in', array( 'A-1', 'D-4' ) ) );
		$this->assertSame( $this->ids( 'g3' ), $this->where( 'code', 'empty' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g4' ), $this->where( 'code', 'not_empty' ) );

		$this->assertSame( $this->ids( 'g1' ), $this->where( 'method', 'eq', 'card' ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'method', 'in', array( 'check', 'cash' ) ) );
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $this->where( 'method', 'not_in', array( 'card' ) ) );

		$this->assertSame( $this->ids( 'g2' ), $this->where( 'donor_email', 'eq', 'b@example.org' ), 'Private fields can be filtered.' );
		$this->assertSame( $this->ids( 'g3', 'g4' ), $this->where( 'donor_email', 'empty' ) );
		$this->assertSame( array(), $this->where( 'website', 'not_empty' ) );
	}

	/**
	 * Booleans: true when stored "1"; false includes no stored value; never empty.
	 */
	public function test_boolean_operators() {
		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'anonymous', 'eq', true ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'anonymous', 'eq', false ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'anonymous', 'ne', true ) );
		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'anonymous', 'ne', false ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3', 'g4' ), $this->where( 'anonymous', 'in', array( true, false ) ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'anonymous', 'not_in', array( true ) ) );
		$this->assertSame( array(), $this->where( 'anonymous', 'empty' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3', 'g4' ), $this->where( 'anonymous', 'not_empty' ) );
	}

	/**
	 * Title-storage fields and `id` compare the post columns.
	 */
	public function test_title_and_id_operators() {
		$this->assertSame( $this->ids( 'g2' ), $this->where( 'name', 'eq', 'Bravo' ) );
		$this->assertSame( $this->ids( 'g1', 'g3', 'g4' ), $this->where( 'name', 'ne', 'Bravo' ) );
		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'name', 'in', array( 'Alpha', 'Delta' ) ) );
		$this->assertSame( $this->ids( 'g2', 'g3' ), $this->where( 'name', 'not_in', array( 'Alpha', 'Delta' ) ) );
		$this->assertSame( array(), $this->where( 'name', 'empty' ) );

		$this->assertSame( $this->ids( 'g2' ), $this->where( 'id', 'eq', $this->g['g2'] ) );
		$this->assertSame( $this->ids( 'g1', 'g3' ), $this->where( 'id', 'in', $this->ids( 'g1', 'g3' ) ) );
		$this->assertSame( $this->ids( 'g3', 'g4' ), $this->where( 'id', 'gt', $this->g['g2'] ) );
		$this->assertSame( $this->ids( 'g1', 'g2' ), $this->where( 'id', 'lte', (string) $this->g['g2'] ) );
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $this->where( 'id', 'not_in', $this->ids( 'g1' ) ) );
		$this->assertSame( array(), $this->where( 'id', 'empty' ) );
	}

	/**
	 * Taxonomy operators on slugs; `eq` is set equality.
	 */
	public function test_taxonomy_operators() {
		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'rq_tag', 'in', array( 'matched', 'event' ) ) );
		$this->assertSame( $this->ids( 'g1', 'g2' ), $this->where( 'rq_tag', 'contains', 'monthly' ) );
		$this->assertSame( $this->ids( 'g3', 'g4' ), $this->where( 'rq_tag', 'not_in', array( 'monthly' ) ) );
		$this->assertSame( $this->ids( 'g2' ), $this->where( 'rq_tag', 'eq', array( 'monthly' ) ) );
		$this->assertSame( $this->ids( 'g2' ), $this->where( 'rq_tag', 'eq', 'monthly' ) );
		$this->assertSame( $this->ids( 'g1' ), $this->where( 'rq_tag', 'eq', array( 'matched', 'monthly' ) ) );
		$this->assertSame( $this->ids( 'g1', 'g3', 'g4' ), $this->where( 'rq_tag', 'ne', array( 'monthly' ) ) );
		$this->assertSame( $this->ids( 'g3' ), $this->where( 'rq_tag', 'empty' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g4' ), $this->where( 'rq_tag', 'not_empty' ) );
		$this->assertSame( array(), $this->where( 'rq_tag', 'in', array( 'no-such-term' ) ) );

		$this->assertSame( $this->ids( 'g1', 'g4' ), $this->where( 'rq_fund', 'eq', 'general' ) );
		$single = array(
			'field' => 'rq_fund',
			'op'    => 'contains',
			'value' => 'general',
		);
		$order  = array(
			'field' => 'rq_tag',
			'op'    => 'gt',
			'value' => 'a',
		);
		$this->assertInvalid( 'condition_type_mismatch', array( 'filter' => array( 'all' => array( $single ) ) ) );
		$this->assertInvalid( 'condition_type_mismatch', array( 'filter' => array( 'all' => array( $order ) ) ) );
	}

	/**
	 * `all` and `any` groups mix post columns, meta and terms.
	 */
	public function test_groups() {
		$any = array(
			array(
				'field' => 'name',
				'op'    => 'eq',
				'value' => 'Charlie',
			),
			array(
				'field' => 'amount',
				'op'    => 'eq',
				'value' => 9,
			),
			array(
				'field' => 'rq_tag',
				'op'    => 'contains',
				'value' => 'event',
			),
		);
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $this->gifts( array( 'filter' => array( 'any' => $any ) ) ) );

		$all = array(
			array(
				'field' => 'anonymous',
				'op'    => 'eq',
				'value' => true,
			),
			array(
				'field' => 'rq_fund',
				'op'    => 'eq',
				'value' => 'general',
			),
			array(
				'field' => 'received_on',
				'op'    => 'lt',
				'value' => '2027-03-05',
			),
		);
		$this->assertSame( $this->ids( 'g1' ), $this->gifts( array( 'filter' => array( 'all' => $all ) ) ) );
	}

	/**
	 * An empty operand makes the whole read match nothing and is reported.
	 */
	public function test_empty_operand_matches_nothing() {
		foreach ( array( null, '', array() ) as $empty ) {
			$result = Records::find(
				'rq_gift',
				array(
					'filter' => array(
						'any' => array(
							array(
								'field' => 'name',
								'op'    => 'ne',
								'value' => 'Nobody',
							),
							array(
								'field' => 'donor_email',
								'op'    => array() === $empty ? 'in' : 'eq',
								'value' => $empty,
							),
						),
					),
					'count'  => true,
					'sum'    => array( 'amount' ),
				)
			);
			$this->assertSame(
				array(
					'ids'           => array(),
					'count'         => 0,
					'sums'          => array( 'amount' => 0.0 ),
					'empty_operand' => 'donor_email',
				),
				$result
			);
		}

		$unary = array(
			'field' => 'donor_email',
			'op'    => 'empty',
		);
		$this->assertNull( Records::find( 'rq_gift', array( 'filter' => array( 'all' => array( $unary ) ) ) )['empty_operand'], 'empty and not_empty take no operand.' );
	}

	/**
	 * Default statuses private and publish; drafts only when listed; trash never.
	 */
	public function test_statuses() {
		$all = $this->ids( 'g1', 'g2', 'g3', 'g4' );
		$this->assertSame( $all, $this->gifts( array() ) );
		$this->assertSame( $this->ids( 'g4' ), $this->gifts( array( 'status' => array( 'publish' ) ) ) );
		$this->assertSame( $this->ids( 'g5' ), $this->gifts( array( 'status' => array( 'draft' ) ) ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3', 'g4', 'g5' ), $this->gifts( array( 'status' => array( 'publish', 'draft', 'private' ) ) ) );
		$this->assertInvalid( 'invalid_value', array( 'status' => array( 'trash' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'status' => array( 'auto-draft' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'status' => array() ) );
		$this->assertInvalid( 'invalid_value', array( 'status' => 'publish' ) );
	}

	/**
	 * From a "to" record: the "from" records linking to it. From a "from" record: its linked records.
	 */
	public function test_relationship_both_directions() {
		$donor_gifts = Records::find(
			'rq_gift',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $this->c['c1'],
				),
				'count'   => true,
				'sum'     => array( 'amount' ),
			)
		);
		$this->assertSame( $this->ids( 'g1', 'g2' ), $donor_gifts['ids'], 'The draft and the trashed gift are not read.' );
		$this->assertSame( 2, $donor_gifts['count'] );
		$this->assertSame( array( 'amount' => 109.0 ), $donor_gifts['sums'] );

		$drafts = Records::find(
			'rq_gift',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => (string) $this->c['c1'],
				),
				'status'  => array( 'draft', 'private', 'publish' ),
			)
		);
		$this->assertSame( $this->ids( 'g1', 'g2', 'g5' ), $drafts['ids'] );

		$donors = Records::find(
			'rq_contact',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $this->g['g2'],
				),
			)
		);
		$this->assertSame( array( $this->c['c1'], $this->c['c2'] ), $donors['ids'] );

		$related = fn( $type, $of ) => Records::find(
			$type,
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $of,
				),
				'count'   => true,
			)
		);
		$this->assertSame( array(), $related( 'rq_gift', $this->c['c3'] )['ids'], 'A contact with no gifts.' );
		$this->assertSame( 0, $related( 'rq_contact', $this->g['g4'] )['count'], 'A gift with no donor.' );
		$this->assertSame( array(), $related( 'rq_gift', $this->g['g1'] )['ids'], 'An "of" record of the same type is not at the other end.' );
		$this->assertSame( array(), $related( 'rq_gift', 0 )['ids'] );
		$this->assertSame( array(), $related( 'rq_gift', 999999 )['ids'] );
		$this->assertSame( array(), $related( 'rq_contact', $this->g['g6'] )['ids'], 'A trashed "of" record matches nothing.' );

		$this->assertInvalid(
			'unknown_relationship',
			array(
				'related' => array(
					'relationship' => 'nope',
					'of'           => 1,
				),
			)
		);
		$this->assertInvalid(
			'relationship_end',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $this->g['g1'],
				),
			),
			'rq_task'
		);
	}

	/**
	 * A relationship and a filter combine.
	 */
	public function test_relationship_with_filter() {
		$result = Records::find(
			'rq_gift',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $this->c['c2'],
				),
				'filter'  => array(
					'all' => array(
						array(
							'field' => 'amount',
							'op'    => 'gt',
							'value' => 10,
						),
					),
				),
				'count'   => true,
			)
		);
		$this->assertSame( $this->ids( 'g3' ), $result['ids'] );
		$this->assertSame( 1, $result['count'] );
	}

	/**
	 * Sort by date, number, title and id; empty values last; numbers numerically.
	 */
	public function test_sort() {
		$sort = fn( $field, $order ) => $this->gifts(
			array(
				'sort' => array(
					'field' => $field,
					'order' => $order,
				),
			)
		);
		$this->assertSame( $this->ids( 'g4', 'g2', 'g1', 'g3' ), $sort( 'received_on', 'desc' ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g4', 'g3' ), $sort( 'received_on', 'asc' ) );
		$this->assertSame( $this->ids( 'g2', 'g1', 'g3', 'g4' ), $sort( 'amount', 'asc' ) );
		$this->assertSame( $this->ids( 'g3', 'g1', 'g2', 'g4' ), $sort( 'amount', 'desc' ) );
		$this->assertSame( $this->ids( 'g4', 'g3', 'g2', 'g1' ), $sort( 'name', 'desc' ) );
		$this->assertSame( $this->ids( 'g4', 'g3', 'g2', 'g1' ), $sort( 'id', 'desc' ) );
		$this->assertSame( $this->ids( 'g1', 'g3', 'g2', 'g4' ), $sort( 'method', 'asc' ) );

		foreach ( array( 'anonymous', 'story', 'note', 'website', 'rq_tag', 'nope' ) as $field ) {
			$this->assertInvalid(
				'filter_field',
				array(
					'sort' => array(
						'field' => $field,
					),
				)
			);
		}
		$this->assertInvalid(
			'invalid_value',
			array(
				'sort' => array(
					'field' => 'id',
					'order' => 'up',
				),
			)
		);
	}

	/**
	 * Limit 0 loads no IDs but still counts and sums; the default is 10; 51 is too many.
	 */
	public function test_limit() {
		$first = Records::find(
			'rq_gift',
			array(
				'sort'  => array( 'field' => 'amount' ),
				'limit' => 2,
				'count' => true,
			)
		);
		$this->assertSame( $this->ids( 'g2', 'g1' ), $first['ids'] );
		$this->assertSame( 4, $first['count'], 'The count covers every match, not the returned IDs.' );

		$totals = Records::find(
			'rq_gift',
			array(
				'limit' => 0,
				'count' => true,
				'sum'   => array( 'amount', 'fee' ),
			)
		);
		$this->assertSame(
			array(
				'ids'           => array(),
				'count'         => 4,
				'sums'          => array(
					'amount' => 359.75,
					'fee'    => 2.75,
				),
				'empty_operand' => null,
			),
			$totals
		);

		$this->assertNull( Records::find( 'rq_gift', array() )['count'], 'No count unless asked.' );

		for ( $i = 0; $i < 12; $i++ ) {
			$this->record( 'rq_contact', array( 'name' => 'More ' . $i ) );
		}
		$this->assertCount( 10, Records::find( 'rq_contact', array() )['ids'] );
		$this->assertCount( 15, Records::find( 'rq_contact', array( 'limit' => 50 ) )['ids'] );

		$this->assertInvalid( 'invalid_value', array( 'limit' => 51 ) );
		$this->assertInvalid( 'invalid_value', array( 'limit' => -1 ) );
		$this->assertInvalid( 'invalid_value', array( 'limit' => '5' ) );
	}

	/**
	 * SQL sums equal PHP sums over the same records, including decimals and records with no value.
	 */
	public function test_sums_equal_php_sums() {
		mt_srand( 7 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Deterministic fixture.
		for ( $i = 0; $i < 40; $i++ ) {
			$values = array( 'name' => 'Sum ' . $i );
			if ( 0 !== $i % 7 ) {
				$values['amount'] = mt_rand( 1, 500000 ) / 100; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Deterministic fixture.
			}
			$values['fee'] = $i;
			$this->record( 'rq_gift', $values, 0 === $i % 3 ? 'publish' : 'private' );
		}

		$query  = array(
			'filter' => array(
				'all' => array(
					array(
						'field' => 'fee',
						'op'    => 'gte',
						'value' => 10,
					),
				),
			),
			'limit'  => 50,
			'count'  => true,
			'sum'    => array( 'amount', 'fee' ),
		);
		$result = Records::find( 'rq_gift', $query );

		$amount = 0.0;
		$fee    = 0.0;
		foreach ( $result['ids'] as $id ) {
			$values  = Records::get( $id )['values'];
			$amount += (float) $values['amount'];
			$fee    += (float) $values['fee'];
		}
		$this->assertSame( 30, $result['count'] );
		$this->assertCount( 30, $result['ids'] );
		$this->assertEqualsWithDelta( $amount, $result['sums']['amount'], 0.00001 );
		$this->assertEqualsWithDelta( $fee, $result['sums']['fee'], 0.00001 );

		$none = Records::find(
			'rq_gift',
			array(
				'filter' => array(
					'all' => array(
						array(
							'field' => 'amount',
							'op'    => 'gt',
							'value' => 1000000,
						),
					),
				),
				'sum'    => array( 'amount' ),
			)
		);
		$this->assertSame( array( 'amount' => 0.0 ), $none['sums'] );
	}

	/**
	 * Invalid queries fail with a reason, before any SQL runs.
	 */
	public function test_invalid_queries() {
		$leaf = fn( $field, $op, $value = 'x' ) => array(
			'filter' => array(
				'all' => array(
					array(
						'field' => $field,
						'op'    => $op,
						'value' => $value,
					),
				),
			),
		);

		$this->assertInvalid( 'unknown_content_type', array(), 'nope' );
		$this->assertInvalid( 'unknown_content_type', array(), 'post' );
		$this->assertInvalid( 'unknown_property', array( 'offset' => 10 ) );
		$this->assertInvalid( 'unknown_field', $leaf( 'nope', 'eq' ) );
		$this->assertInvalid( 'unknown_field', $leaf( 'status', 'eq', 'publish' ) );
		$this->assertInvalid( 'filter_field', $leaf( 'note', 'eq' ) );
		$this->assertInvalid( 'filter_field', $leaf( 'story', 'eq' ) );
		$this->assertInvalid( 'invalid_value', $leaf( 'code', 'changed' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'code', 'lt' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'method', 'gte', 'card' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'code', 'contains' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'amount', 'eq', 'ten' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'amount', 'eq', true ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'amount', 'in', 5 ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'received_on', 'eq', 'tomorrow' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'anonymous', 'eq', 'perhaps' ) );
		$this->assertInvalid( 'condition_type_mismatch', $leaf( 'code', 'eq', array( 'a' => 1 ) ) );

		$eleven = array_fill(
			0,
			11,
			array(
				'field' => 'amount',
				'op'    => 'gt',
				'value' => 1,
			)
		);
		$this->assertInvalid( 'condition_too_complex', array( 'filter' => array( 'all' => $eleven ) ) );
		$this->assertInvalid( 'condition_too_complex', array( 'filter' => array( 'all' => array() ) ) );
		$this->assertInvalid( 'condition_too_complex', array( 'filter' => array( 'none' => array( $eleven[0] ) ) ) );
		$this->assertInvalid(
			'condition_too_complex',
			array(
				'filter' => array(
					'all' => array( $eleven[0] ),
					'any' => array( $eleven[0] ),
				),
			)
		);

		$this->assertInvalid( 'filter_field', array( 'sum' => array( 'code' ) ) );
		$this->assertInvalid( 'filter_field', array( 'sum' => array( 'nope' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'sum' => array( 'amount', 'fee', 'amount', 'fee', 'amount', 'fee' ) ) );
	}

	/**
	 * Sets a record's creation time.
	 *
	 * @param string $name  Gift name (g1 to g6).
	 * @param string $gmt   `post_date_gmt`, or the zero date of a never-published draft.
	 * @param string $local `post_date`.
	 */
	private function created_at( string $name, string $gmt, string $local ): void {
		global $wpdb;
		$dates = array(
			'post_date_gmt' => $gmt,
			'post_date'     => $local,
		);
		$wpdb->update( $wpdb->posts, $dates, array( 'ID' => $this->g[ $name ] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		clean_post_cache( $this->g[ $name ] );
	}

	/**
	 * `created` compares the creation time with whole site-time days: `before` is strict, `after` starts at the
	 * next day. New York; the boundaries are the first and last second of 2027-03-01 (EST, UTC-5).
	 */
	public function test_created_before_and_after_at_day_boundaries() {
		update_option( 'timezone_string', 'America/New_York' );
		$this->created_at( 'g1', '2027-03-01 04:59:59', '2027-02-28 23:59:59' );
		$this->created_at( 'g2', '2027-03-01 05:00:00', '2027-03-01 00:00:00' );
		$this->created_at( 'g3', '2027-03-02 04:59:59', '2027-03-01 23:59:59' );
		$this->created_at( 'g4', '2027-03-02 05:00:00', '2027-03-02 00:00:00' );

		$created = fn( array $bounds ) => $this->gifts( array( 'created' => $bounds ) );
		$this->assertSame( $this->ids( 'g1' ), $created( array( 'before' => '2027-03-01' ) ) );
		$this->assertSame( $this->ids( 'g1', 'g2', 'g3' ), $created( array( 'before' => '2027-03-02' ) ) );
		$this->assertSame( $this->ids( 'g2', 'g3', 'g4' ), $created( array( 'after' => '2027-02-28' ) ) );
		$this->assertSame( $this->ids( 'g4' ), $created( array( 'after' => '2027-03-01' ) ) );
		$this->assertSame(
			$this->ids( 'g2', 'g3' ),
			$created(
				array(
					'after'  => '2027-02-28',
					'before' => '2027-03-02',
				)
			),
			'Both bounds: created on 2027-03-01.'
		);
		$this->assertSame(
			array(),
			$created(
				array(
					'after'  => '2027-03-01',
					'before' => '2027-03-01',
				)
			)
		);

		// DST starts 2027-03-14: that day starts at 05:00 UTC (EST), the next at 04:00 UTC (EDT).
		$this->created_at( 'g1', '2027-03-14 04:59:59', '2027-03-13 23:59:59' );
		$this->created_at( 'g2', '2027-03-14 05:00:00', '2027-03-14 00:00:00' );
		$this->created_at( 'g3', '2027-03-15 03:59:59', '2027-03-14 23:59:59' );
		$this->created_at( 'g4', '2027-03-15 04:00:00', '2027-03-15 00:00:00' );
		$this->assertSame( $this->ids( 'g1' ), $created( array( 'before' => '2027-03-14' ) ) );
		$this->assertSame( $this->ids( 'g4' ), $created( array( 'after' => '2027-03-14' ) ) );

		// A draft never published has a zero post_date_gmt: its site-time post_date is compared.
		$this->created_at( 'g5', '0000-00-00 00:00:00', '2027-03-01 12:00:00' );
		$drafts = fn( array $bounds ) => $this->gifts(
			array(
				'created' => $bounds,
				'status'  => array( 'draft' ),
			)
		);
		$this->assertSame( array(), $drafts( array( 'before' => '2027-03-01' ) ), 'A zero GMT date is not "before" every day.' );
		$this->assertSame( $this->ids( 'g5' ), $drafts( array( 'before' => '2027-03-02' ) ) );
		$this->assertSame( $this->ids( 'g5' ), $drafts( array( 'after' => '2027-02-28' ) ) );
		$this->assertSame( array(), $drafts( array( 'after' => '2027-03-01' ) ) );
	}

	/**
	 * `created` narrows the same WHERE as the filter, so counts and sums cover every match; an empty bound
	 * matches nothing; bad shapes are invalid.
	 */
	public function test_created_with_count_sum_filter_and_validation() {
		$this->created_at( 'g1', '2025-01-10 12:00:00', '2025-01-10 12:00:00' );
		$this->created_at( 'g2', '2025-06-10 12:00:00', '2025-06-10 12:00:00' );
		$this->created_at( 'g3', '2027-01-10 12:00:00', '2027-01-10 12:00:00' );
		$this->created_at( 'g4', '2027-01-10 12:00:00', '2027-01-10 12:00:00' );

		$result = Records::find(
			'rq_gift',
			array(
				'created' => array( 'before' => '2026-01-01' ),
				'limit'   => 0,
				'count'   => true,
				'sum'     => array( 'amount', 'fee' ),
			)
		);
		$this->assertSame(
			array(
				'ids'           => array(),
				'count'         => 2,
				'sums'          => array(
					'amount' => 109.0,
					'fee'    => 2.75,
				),
				'empty_operand' => null,
			),
			$result
		);

		$result = Records::find(
			'rq_gift',
			array(
				'created' => array( 'after' => '2025-01-10' ),
				'filter'  => array(
					'all' => array(
						array(
							'field' => 'amount',
							'op'    => 'gt',
							'value' => 1,
						),
					),
				),
				'count'   => true,
				'sum'     => array( 'amount' ),
			)
		);
		$this->assertSame( $this->ids( 'g2', 'g3' ), $result['ids'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertEqualsWithDelta( 259.75, $result['sums']['amount'], 0.00001 );

		foreach ( array( null, '' ) as $empty ) {
			$this->assertSame(
				array(
					'ids'           => array(),
					'count'         => 0,
					'sums'          => array( 'amount' => 0.0 ),
					'empty_operand' => 'created',
				),
				Records::find(
					'rq_gift',
					array(
						'created' => array(
							'after'  => '2020-01-01',
							'before' => $empty,
						),
						'count'   => true,
						'sum'     => array( 'amount' ),
					)
				)
			);
		}

		$this->assertInvalid( 'invalid_value', array( 'created' => array() ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => '2026-01-01' ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'on' => '2026-01-01' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'before' => 'tomorrow' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'before' => '2026-02-30' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'before' => '2026-01-01T00:00:00Z' ) ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'after' => 20260101 ) ) );
		$this->assertInvalid( 'invalid_value', array( 'created' => array( 'after' => array( 'date' => 'today' ) ) ) );
	}

	/**
	 * Reads see only registry content types and never write anything.
	 */
	public function test_reads_have_no_side_effects() {
		global $wpdb;
		$before       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test count.
		$this->events = array();

		Records::find(
			'rq_gift',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $this->c['c1'],
				),
				'count'   => true,
				'sum'     => array( 'amount' ),
			)
		);

		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test count.
		$this->assertSame( array(), $this->events );
	}
}
