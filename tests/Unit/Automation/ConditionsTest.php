<?php
/**
 * Unit tests: condition interpreter, templates and the frozen clock.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Conditions;
use PegworkAutomation\Context;
use PegworkAutomation\Templates;
use WP_UnitTestCase;

/**
 * Every operator, operands, groups, paths and merge tags against a hand-built context.
 */
class ConditionsTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * A context with a record, a record.updated payload and one step output; clock 2027-11-02 09:00 UTC.
	 *
	 * @param FrozenClock|null $clock Clock.
	 */
	private function context( $clock = null ) {
		$record  = array(
			'id'        => 42,
			'type'      => 'job',
			'status'    => 'publish',
			'permalink' => 'https://example.org/job/42/',
			'values'    => array(
				'title'         => 'Clean water',
				'last_verified' => '2026-11-02',
				'seats'         => 12,
				'urgent'        => false,
				'note'          => '',
				'stage'         => array( 'open' ),
				'tags'          => array( 'a', 'b' ),
			),
		);
		$trigger = array(
			'id'      => 42,
			'changed' => array( 'seats', 'status' ),
			'old'     => array(
				'seats'  => 10,
				'status' => 'pending',
			),
			'origin'  => array( 'depth' => 1 ),
		);
		return new Context( $clock ?? new FrozenClock( '2027-11-02 09:00' ), $record, $trigger, array( 'child' => array( 'record_id' => 7 ) ), 5 );
	}

	/**
	 * Evaluates a leaf.
	 *
	 * @param string $path  Path.
	 * @param string $op    Operator.
	 * @param mixed  $value Operand (omit with null for unary).
	 */
	private function leaf( $path, $op, $value = null ) {
		$node = array(
			'var' => $path,
			'op'  => $op,
		);
		if ( null !== $value ) {
			$node['value'] = $value;
		}
		return Conditions::evaluate( $node, $this->context() );
	}

	/**
	 * Equality on scalars, numbers, booleans and single-item lists.
	 */
	public function test_eq_and_ne() {
		$this->assertTrue( $this->leaf( 'record.title', 'eq', 'Clean water' ) );
		$this->assertFalse( $this->leaf( 'record.title', 'eq', 'clean water' ) );
		$this->assertTrue( $this->leaf( 'record.seats', 'eq', '12' ) );
		$this->assertTrue( $this->leaf( 'record.urgent', 'eq', false ) );
		$this->assertTrue( $this->leaf( 'record.urgent', 'ne', true ) );
		$this->assertTrue( $this->leaf( 'record.stage', 'eq', 'open' ), 'A one-term list equals its slug.' );
		$this->assertFalse( $this->leaf( 'record.tags', 'eq', 'a' ) );
		$this->assertTrue( $this->leaf( 'record.note', 'eq', '' ) );
		$this->assertTrue( $this->leaf( 'trigger.old.status', 'eq', 'pending' ) );
		$this->assertTrue( $this->leaf( 'record.seats', 'ne', array( 'var' => 'trigger.old.seats' ) ) );
	}

	/**
	 * Lists and membership.
	 */
	public function test_in_not_in_contains() {
		$this->assertTrue( $this->leaf( 'record.stage', 'in', array( 'open', 'closed' ) ) );
		$this->assertFalse( $this->leaf( 'record.stage', 'not_in', array( 'open' ) ) );
		$this->assertTrue( $this->leaf( 'record.title', 'in', array( 'Clean water' ) ) );
		$this->assertTrue( $this->leaf( 'record.tags', 'contains', 'b' ) );
		$this->assertFalse( $this->leaf( 'record.tags', 'contains', 'c' ) );
		$this->assertFalse( $this->leaf( 'record.title', 'contains', 'Clean' ), 'contains is for lists.' );
	}

	/**
	 * Order operators on numbers and dates, with date operands.
	 */
	public function test_order_and_dates() {
		$this->assertTrue( $this->leaf( 'record.seats', 'gt', 10 ) );
		$this->assertTrue( $this->leaf( 'record.seats', 'lte', 12 ) );
		$this->assertFalse( $this->leaf( 'record.seats', 'lt', 12 ) );
		$this->assertTrue( $this->leaf( 'record.seats', 'gte', '9' ), 'Numeric, not string, order.' );

		$year_ago = array(
			'date'   => 'today',
			'offset' => '-P12M',
		);
		$this->assertTrue( $this->leaf( 'record.last_verified', 'lte', $year_ago ), '2026-11-02 is 12 months before 2027-11-02.' );
		$this->assertFalse( $this->leaf( 'record.last_verified', 'lt', $year_ago ) );
		$this->assertTrue( $this->leaf( 'record.last_verified', 'lt', array( 'date' => 'now' ) ) );
		$this->assertFalse( $this->leaf( 'record.note', 'lt', array( 'date' => 'today' ) ), 'Empty values never order.' );
	}

	/**
	 * Unary operators (empty, not_empty, changed) and groups.
	 */
	public function test_unary_and_groups() {
		$this->assertTrue( $this->leaf( 'record.note', 'empty' ) );
		$this->assertTrue( $this->leaf( 'record.missing', 'empty' ) );
		$this->assertTrue( $this->leaf( 'record.stage', 'not_empty' ) );
		$this->assertTrue( $this->leaf( 'record.seats', 'changed' ) );
		$this->assertFalse( $this->leaf( 'record.title', 'changed' ) );

		$context = $this->context();
		$yes     = array(
			'var'   => 'record.id',
			'op'    => 'eq',
			'value' => 42,
		);
		$no      = array(
			'var' => 'record.title',
			'op'  => 'empty',
		);
		$this->assertTrue( Conditions::evaluate( array( 'all' => array( $yes, array( 'not' => $no ) ) ), $context ) );
		$this->assertFalse( Conditions::evaluate( array( 'all' => array( $yes, $no ) ), $context ) );
		$this->assertTrue( Conditions::evaluate( array( 'any' => array( $no, $yes ) ), $context ) );
		$this->assertFalse( Conditions::evaluate( array( 'any' => array( $no ) ), $context ) );
		$this->assertFalse( Conditions::evaluate( array( 'op' => 'eq' ), $context ), 'Malformed nodes fail closed.' );
	}

	/**
	 * Today and date operands follow site time.
	 */
	public function test_today_is_site_time() {
		update_option( 'timezone_string', 'America/Chicago' );
		$context = $this->context( new FrozenClock( new \DateTimeImmutable( '2027-11-03 02:00', new \DateTimeZone( 'UTC' ) ) ) );
		$this->assertSame( '2027-11-02', $context->today() );
		$this->assertSame( '2027-11-02', Conditions::operand( array( 'date' => 'today' ), $context ) );
		$this->assertSame( '2027-11-02T21:00:00-05:00', Conditions::operand( array( 'date' => 'now' ), $context ) );
		$this->assertSame(
			'2027-11-02T20:00:00-05:00',
			Conditions::operand(
				array(
					'date'   => 'now',
					'offset' => '-PT1H',
				),
				$context
			)
		);
	}

	/**
	 * Merge tags: paths, defaults, raw single-tag values.
	 */
	public function test_templates() {
		$context = $this->context();
		$this->assertSame( 'Clean water #42 run 5', Templates::render( '{{record.title}} #{{ record.id }} run {{run.id}}', $context ) );
		$this->assertSame( 'none', Templates::render( '{{record.note | default:"none"}}', $context ) );
		$this->assertSame( 'a,b', Templates::render( '{{record.tags}}', $context ) );
		$this->assertSame( '0', Templates::render( '{{record.urgent}}', $context ) );
		$this->assertSame( '2027-11-02', Templates::render( '{{today}}', $context ) );
		$this->assertSame( '7', Templates::render( '{{steps.child.record_id}}', $context ) );
		$this->assertSame( 'https://example.org/job/42/', Templates::render( '{{record.permalink}}', $context ) );
		$this->assertStringContainsString( 'post.php?post=42&action=edit', Templates::render( '{{record.edit_url}}', $context ) );
		$this->assertSame( get_bloginfo( 'name' ), Templates::render( '{{site.name}}', $context ) );
		$this->assertSame( '', Templates::render( '{{record.nope}}', $context ) );

		$this->assertSame( array( 'a', 'b' ), Templates::value( '{{record.tags}}', $context ) );
		$this->assertFalse( Templates::value( '{{ record.urgent }}', $context ) );
		$this->assertSame( 'active', Templates::value( '{{record.note | default:"active"}}', $context ) );
		$this->assertSame( 12, Templates::value( 12, $context ) );
		$this->assertSame( 'x-12', Templates::value( 'x-{{record.seats}}', $context ) );
	}

	/**
	 * Preview modes: links and step outputs are placeholders (even when the context has outputs), the
	 * rest renders as text or escaped HTML, and nothing creates the link key.
	 */
	public function test_templates_preview_mode() {
		$context = $this->context();
		$this->assertSame( 'Clean water [signed link] [from step child] 10', Templates::render( '{{record.title}} {{link.confirm}} {{steps.child.record_id}} {{trigger.old.seats}}', $context, Templates::PREVIEW ) );
		$this->assertSame( '<a href="[signed link]">[from step child]</a>', Templates::render( '<a href="{{link.confirm}}">{{steps.child.record_id}}</a>', $context, Templates::PREVIEW_HTML ) );
		$this->assertSame( 'a, b', Templates::render( '{{record.tags}}', $context, Templates::PREVIEW ) );
		$this->assertSame( 'none', Templates::render( '{{record.note | default:"none"}}', $context, Templates::PREVIEW_HTML ) );
		$this->assertFalse( get_option( 'pegwork_link_secret' ) );
	}

	/**
	 * The frozen clock moves only when told, in site-time calendar units.
	 */
	public function test_frozen_clock() {
		update_option( 'timezone_string', 'America/Chicago' );
		$clock = new FrozenClock( '2026-10-31 09:00' );
		$this->assertSame( '2026-10-31 14:00:00', $clock->now()->format( 'Y-m-d H:i:s' ), 'CDT is UTC-5.' );

		$clock->advance( 'P2D' );
		$this->assertSame( '2026-11-02 15:00:00', $clock->now()->format( 'Y-m-d H:i:s' ), 'Still 09:00 local after the DST change (CST is UTC-6).' );

		$clock->advance( '-PT1H' );
		$this->assertSame( '2026-11-02 14:00:00', $clock->now()->format( 'Y-m-d H:i:s' ) );

		$clock->advance( 'P12M' );
		$this->assertSame( '2027-11-02', $clock->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' ) );
	}
}
