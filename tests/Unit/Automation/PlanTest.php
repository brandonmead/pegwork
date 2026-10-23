<?php
/**
 * Unit tests: the step tree a run walks.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use PegworkAutomation\Plan;
use WP_UnitTestCase;

/**
 * Next pointers across nesting, branch first ids, empty branches, unknown ids and flat lists.
 */
class PlanTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * A step of a type.
	 *
	 * @param string $id   Step id.
	 * @param string $type Step type.
	 * @return array<string, mixed>
	 */
	private static function step( $id, $type = 'send_email' ) {
		return array(
			'id'   => $id,
			'type' => $type,
		);
	}

	/**
	 * A decision.
	 *
	 * @param string     $id        Step id.
	 * @param array      $branches  Label => steps.
	 * @param array|null $otherwise Otherwise steps, or null for none.
	 * @return array<string, mixed>
	 */
	private static function decision( $id, $branches, $otherwise = null ) {
		$step = array(
			'id'       => $id,
			'type'     => 'decision',
			'branches' => array(),
		);
		foreach ( $branches as $label => $steps ) {
			$step['branches'][] = array(
				'label' => (string) $label,
				'when'  => array(
					'var' => 'record.id',
					'op'  => 'not_empty',
				),
				'steps' => $steps,
			);
		}
		if ( null !== $otherwise ) {
			$step['otherwise'] = $otherwise;
		}
		return $step;
	}

	/**
	 * The volunteer intake shape: a decision, shared steps, a wait, a decision with a nested decision.
	 *
	 * @return Plan
	 */
	private function tree() {
		return new Plan(
			array(
				self::decision(
					'route',
					array(
						'Events' => array( self::step( 'to_events', 'update_record' ) ),
						'Garden' => array( self::step( 'to_garden', 'update_record' ), self::step( 'garden_mail' ) ),
					),
					array( self::step( 'to_manager', 'update_record' ) )
				),
				self::step( 'notify' ),
				self::step( 'grace', 'wait' ),
				self::decision(
					'check',
					array(
						'Not contacted yet' => array(
							self::step( 'remind' ),
							self::decision(
								'inner',
								array(
									'Empty' => array(),
									'Deep'  => array( self::step( 'deepest' ) ),
								)
							),
						),
					)
				),
			)
		);
	}

	/**
	 * Next is the following sibling, the enclosing decision's next after a branch's last step, or null.
	 */
	public function test_next_pointers_across_nesting() {
		$plan = $this->tree();

		$this->assertSame( 'route', $plan->first() );
		$this->assertSame( 'notify', $plan->next( 'route' ), 'A decision that branches nowhere continues after itself.' );
		$this->assertSame( 'notify', $plan->next( 'to_events' ) );
		$this->assertSame( 'garden_mail', $plan->next( 'to_garden' ) );
		$this->assertSame( 'notify', $plan->next( 'garden_mail' ) );
		$this->assertSame( 'notify', $plan->next( 'to_manager' ) );
		$this->assertSame( 'grace', $plan->next( 'notify' ) );
		$this->assertSame( 'check', $plan->next( 'grace' ) );
		$this->assertSame( 'inner', $plan->next( 'remind' ) );
		$this->assertNull( $plan->next( 'inner' ), 'The last step of the last branch of the last decision ends the tree.' );
		$this->assertNull( $plan->next( 'deepest' ) );
		$this->assertNull( $plan->next( 'check' ) );

		$this->assertSame( array( 'route', 'to_events', 'to_garden', 'garden_mail', 'to_manager', 'notify', 'grace', 'check', 'remind', 'inner', 'deepest' ), $plan->ids() );
		$this->assertSame( 0, $plan->depth( 'route' ) );
		$this->assertSame( 1, $plan->depth( 'garden_mail' ) );
		$this->assertSame( 1, $plan->depth( 'inner' ) );
		$this->assertSame( 2, $plan->depth( 'deepest' ) );
	}

	/**
	 * Branch first ids; empty branches and no match continue after the decision.
	 */
	public function test_branch_first_ids_and_empty_branches() {
		$plan = $this->tree();

		$this->assertSame( 'to_events', $plan->branch_first( 'route', 0 ) );
		$this->assertSame( 'to_garden', $plan->branch_first( 'route', 1 ) );
		$this->assertSame( 'to_manager', $plan->branch_first( 'route', -1 ) );
		$this->assertNull( $plan->branch_first( 'inner', 0 ), 'Empty branch.' );
		$this->assertNull( $plan->branch_first( 'check', -1 ), 'No otherwise.' );

		$this->assertSame( 'to_garden', $plan->after_branch( 'route', 1 ) );
		$this->assertSame( 'to_manager', $plan->after_branch( 'route', -1 ) );
		$this->assertSame( 'notify', $plan->after_branch( 'route', null ), 'No match.' );
		$this->assertSame( 'notify', $plan->after_branch( 'route', 7 ), 'Not a branch.' );
		$this->assertNull( $plan->after_branch( 'inner', 0 ), 'An empty branch at the end of the tree ends it.' );
		$this->assertSame( 'deepest', $plan->after_branch( 'inner', 1 ) );

		$this->assertSame(
			array(
				'decision' => 'route',
				'branch'   => 1,
				'label'    => 'Garden',
				'first'    => true,
			),
			$plan->location( 'to_garden' )
		);
		$this->assertFalse( $plan->location( 'garden_mail' )['first'] );
		$this->assertSame( 'otherwise', $plan->location( 'to_manager' )['label'] );
		$this->assertSame( -1, $plan->location( 'to_manager' )['branch'] );
		$this->assertNull( $plan->location( 'notify' ), 'Top-level steps are in no branch.' );
	}

	/**
	 * Unknown ids have no position; a flat Phase 4 list is a tree of depth 0; types include branches.
	 */
	public function test_unknown_ids_flat_lists_and_types() {
		$plan = $this->tree();
		$this->assertFalse( $plan->has( 'gone' ) );
		$this->assertNull( $plan->next( 'gone' ) );
		$this->assertNull( $plan->depth( 'gone' ) );
		$this->assertNull( $plan->step( 'gone' ) );
		$this->assertNull( $plan->location( 'gone' ) );
		$this->assertSame( 'wait', $plan->step( 'grace' )['type'] );

		$flat = new Plan( array( self::step( 'a' ), self::step( 'b', 'wait' ), self::step( 'c' ) ) );
		$this->assertSame( 'a', $flat->first() );
		$this->assertSame( 'b', $flat->next( 'a' ) );
		$this->assertSame( 'c', $flat->next( 'b' ) );
		$this->assertNull( $flat->next( 'c' ) );
		$this->assertSame( array( 0, 0, 0 ), array_map( array( $flat, 'depth' ), $flat->ids() ) );

		$this->assertNull( ( new Plan( array() ) )->first() );

		$this->assertSame( array( 'decision', 'update_record', 'send_email', 'wait' ), Plan::types( array( $this->tree()->step( 'route' ), self::step( 'n' ), self::step( 'w', 'wait' ) ) ) );
		$this->assertContains( 'call_webhook', Plan::types( array( self::decision( 'd', array( 'A' => array( self::step( 'hook', 'call_webhook' ) ) ) ) ) ) );
	}

	/**
	 * A loop over a find's records.
	 *
	 * @param string $id    Step id.
	 * @param array  $steps Body.
	 * @return array<string, mixed>
	 */
	private static function loop( $id, $steps ) {
		return array(
			'id'    => $id,
			'type'  => 'loop',
			'over'  => 'steps.due.records',
			'steps' => $steps,
		);
	}

	/**
	 * Loop next pointers: the last body step (also at the end of a branch inside the body) returns to the
	 * loop, the loop's own next is the step after it; body_first, loop_of, depth and over.
	 */
	public function test_loop_next_pointers_body_first_and_loop_of() {
		$steps = array(
			self::step( 'due', 'find_records' ),
			self::loop(
				'each',
				array(
					self::step( 'has', 'condition' ),
					self::decision(
						'route',
						array( 'Gift' => array( self::step( 'hook', 'call_webhook' ) ) ),
						array( self::step( 'mail' ) )
					),
				)
			),
			self::step( 'summary' ),
		);
		$plan  = new Plan( $steps );

		$this->assertSame( array( 'due', 'each', 'has', 'route', 'hook', 'mail', 'summary' ), $plan->ids() );
		$this->assertSame( 'each', $plan->next( 'due' ) );
		$this->assertSame( 'summary', $plan->next( 'each' ), 'After its last item the loop continues after itself.' );
		$this->assertSame( 'has', $plan->body_first( 'each' ) );
		$this->assertSame( 'route', $plan->next( 'has' ) );
		$this->assertSame( 'each', $plan->next( 'route' ), 'A decision that branches nowhere at the end of the body returns to the loop.' );
		$this->assertSame( 'each', $plan->next( 'hook' ), 'The end of a branch at the end of the body returns to the loop.' );
		$this->assertSame( 'each', $plan->next( 'mail' ) );
		$this->assertSame( 'each', $plan->after_branch( 'route', null ) );
		$this->assertNull( $plan->next( 'summary' ) );

		$this->assertSame( 'each', $plan->loop_of( 'has' ) );
		$this->assertSame( 'each', $plan->loop_of( 'hook' ), 'At any depth inside the body.' );
		$this->assertNull( $plan->loop_of( 'each' ), 'A loop is not inside itself.' );
		$this->assertNull( $plan->loop_of( 'summary' ) );
		$this->assertNull( $plan->loop_of( 'gone' ) );
		$this->assertTrue( $plan->is_loop( 'each' ) );
		$this->assertFalse( $plan->is_loop( 'route' ) );
		$this->assertNull( $plan->body_first( 'route' ) );
		$this->assertSame( 'due', $plan->over( 'each' ) );
		$this->assertNull( $plan->over( 'route' ) );

		$this->assertSame( array( 0, 0, 1, 1, 2, 2, 0 ), array_map( array( $plan, 'depth' ), $plan->ids() ) );
		$this->assertNull( $plan->location( 'has' ), 'A body step is in no branch.' );
		$this->assertSame( 'route', $plan->location( 'hook' )['decision'] );
		$this->assertSame( array( 'find_records', 'loop', 'condition', 'decision', 'call_webhook', 'send_email' ), Plan::types( $steps ), 'Types include loop bodies.' );
	}

	/**
	 * A loop inside a branch: its body returns to it, and after it the cursor continues after the decision;
	 * a loop last in the tree ends it.
	 */
	public function test_loops_inside_branches() {
		$plan = new Plan(
			array(
				self::step( 'due', 'find_records' ),
				self::decision(
					'any',
					array( 'Some' => array( self::loop( 'each', array( self::step( 'remind' ), self::step( 'stamp', 'update_record' ) ) ) ) ),
					array( self::step( 'none' ) )
				),
				self::step( 'summary' ),
			)
		);
		$this->assertSame( 'each', $plan->branch_first( 'any', 0 ) );
		$this->assertSame( 'remind', $plan->body_first( 'each' ) );
		$this->assertSame( 'stamp', $plan->next( 'remind' ) );
		$this->assertSame( 'each', $plan->next( 'stamp' ) );
		$this->assertSame( 'summary', $plan->next( 'each' ), 'After the loop the branch ends: next of the decision.' );
		$this->assertSame( 1, $plan->depth( 'each' ) );
		$this->assertSame( 2, $plan->depth( 'stamp' ) );
		$this->assertSame( 'each', $plan->loop_of( 'stamp' ) );
		$this->assertNull( $plan->loop_of( 'none' ) );
		$this->assertTrue( $plan->location( 'each' )['first'] );

		$last = new Plan( array( self::step( 'due', 'find_records' ), self::loop( 'each', array( self::step( 'remind' ) ) ) ) );
		$this->assertSame( 'each', $last->next( 'remind' ) );
		$this->assertNull( $last->next( 'each' ) );
	}

	/**
	 * A repeated id (never valid) keeps its first position.
	 */
	public function test_repeated_id_keeps_first_position() {
		$plan = new Plan( array( self::step( 'a' ), self::step( 'b' ), self::step( 'a', 'wait' ) ) );
		$this->assertSame( array( 'a', 'b' ), $plan->ids() );
		$this->assertSame( 'send_email', $plan->step( 'a' )['type'] );
		$this->assertNull( $plan->next( 'b' ) );
	}
}
