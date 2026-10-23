<?php
/**
 * Unit tests: the decision step.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Context;
use PegworkAutomation\Steps\Decision;
use PegworkAutomation\Steps\StepResult;
use WP_UnitTestCase;

/**
 * First match wins, otherwise, no match, and the summaries the run log keeps.
 */
class DecisionTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * A context whose record has an amount.
	 *
	 * @param int $amount Amount.
	 */
	private function context( $amount ) {
		return new Context(
			new FrozenClock( '2027-03-01 09:00' ),
			array(
				'id'        => 7,
				'type'      => 'barn_gift',
				'status'    => 'publish',
				'permalink' => '',
				'values'    => array( 'amount' => $amount ),
			)
		);
	}

	/**
	 * A decision on the amount: at least 250, at least 100, then optionally otherwise.
	 *
	 * @param bool $otherwise Whether the decision has an otherwise list.
	 */
	private function decision( $otherwise ) {
		$step = array(
			'id'       => 'size',
			'type'     => 'decision',
			'branches' => array(
				array(
					'label' => 'Major gift',
					'when'  => array(
						'var'   => 'record.amount',
						'op'    => 'gte',
						'value' => 250,
					),
					'steps' => array(),
				),
				array(
					'label' => 'Mid gift',
					'when'  => array(
						'var'   => 'record.amount',
						'op'    => 'gte',
						'value' => 100,
					),
					'steps' => array(),
				),
			),
		);
		if ( $otherwise ) {
			$step['otherwise'] = array(
				array(
					'id'      => 'thank',
					'type'    => 'send_email',
					'to'      => 'a@example.org',
					'subject' => 'Thanks',
					'text'    => 'Thanks',
				),
			);
		}
		return $step;
	}

	/**
	 * Runs the decision.
	 *
	 * @param int  $amount    Amount.
	 * @param bool $otherwise Whether the decision has an otherwise list.
	 * @return StepResult
	 */
	private function run_decision( $amount, $otherwise = true ) {
		return ( new Decision() )->run( $this->decision( $otherwise ), $this->context( $amount ), array( 'id' => 1 ) );
	}

	/**
	 * Branches are tried in order: the first that holds wins, even when a later one holds too.
	 */
	public function test_first_match_wins() {
		$major = $this->run_decision( 300 );
		$this->assertSame( 'ok', $major->status );
		$this->assertSame( 0, $major->branch );
		$this->assertFalse( $major->stop );
		$this->assertNull( $major->resume_at );
		$this->assertSame( array(), $major->output );
		$this->assertSame(
			array(
				'branch' => 'Major gift',
				'index'  => 0,
			),
			$major->summary
		);

		$this->assertSame( 0, $this->run_decision( 250 )->branch, 'The boundary takes the major branch.' );
		$mid = $this->run_decision( 120 );
		$this->assertSame( 1, $mid->branch );
		$this->assertSame( 'Mid gift', $mid->summary['branch'] );
	}

	/**
	 * Without a match: otherwise (-1) when the decision has one, else no branch (null).
	 */
	public function test_otherwise_and_no_match() {
		$otherwise = $this->run_decision( 40 );
		$this->assertSame( -1, $otherwise->branch );
		$this->assertSame(
			array(
				'branch' => 'otherwise',
				'index'  => -1,
			),
			$otherwise->summary
		);

		$none = $this->run_decision( 40, false );
		$this->assertSame( 'ok', $none->status );
		$this->assertNull( $none->branch );
		$this->assertSame(
			array(
				'branch' => null,
				'index'  => null,
			),
			$none->summary
		);
	}

	/**
	 * Every other result leaves `branch` null, so only decisions steer the cursor.
	 */
	public function test_other_results_have_no_branch() {
		$this->assertNull( StepResult::ok()->branch );
		$this->assertNull( StepResult::stop()->branch );
		$this->assertNull( StepResult::fail( 'x', 'y' )->branch );
		$this->assertSame( 2, StepResult::branch( 2, array( 'branch' => 'Two' ) )->branch );
	}
}
