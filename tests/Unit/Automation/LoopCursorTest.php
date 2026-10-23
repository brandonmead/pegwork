<?php
/**
 * Tests: the loop cursor (Automation v3 PR 4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use Pegwork\Tests\Support\LoopsTestCase;
use PegworkAutomation\Engine;

require_once dirname( __DIR__, 2 ) . '/Support/LoopsTestCase.php';

/**
 * `current_step` plus `context.loops` is the whole cursor: a loop starts (snapshot and one row), advances
 * (no row, no attempt), ends on an empty collection, skips an item on a false condition, and skips an item
 * deleted since the snapshot. A zero time budget makes each wake-up run one step, so every position of
 * the cursor is stored and read back.
 */
class LoopCursorTest extends LoopsTestCase {

	/**
	 * One step per wake-up.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'pegwork_automation_time_budget', fn() => 0 );
	}

	/**
	 * Executes one wake-up of a run.
	 *
	 * @param int $id Run ID.
	 * @return array<string, mixed> The run afterwards.
	 */
	private function wake( $id ) {
		Engine::instance()->executor()->execute( $id );
		return $this->run_row( $id );
	}

	/**
	 * Start stores the snapshot at index 0 and logs one row; the end of the body returns the cursor to the
	 * loop; the advance writes no row and is followed by the next item's first step in the same wake-up;
	 * after the last item the state is gone and `count` is an output.
	 */
	public function test_start_and_advance() {
		$ada  = $this->task( 'Ada', 'ada@example.org' );
		$bo   = $this->task( 'Bo', 'bo@example.org' );
		$id   = $this->start_items();
		$item = fn( $run ) => $run['context']['loops']['each'] ?? null;

		$run = $this->wake( $id );
		$this->assertSame( 'each', $run['current_step'], 'The find ran; the loop is next.' );
		$this->assertNull( $item( $run ) );

		$run = $this->wake( $id );
		$this->assertSame(
			array(
				'ids'   => array( $ada, $bo ),
				'index' => 0,
			),
			$item( $run )
		);
		$this->assertSame( 'has_email', $run['current_step'], 'The loop row is a step: the budget requeues before the body.' );
		$this->assertSame( array( 'open', 'each' ), $this->trace( $id ) );
		$this->assertSame(
			array(
				'items' => 2,
				'ids'   => array( $ada, $bo ),
			),
			$this->steps( $id )[1]['summary']
		);

		foreach ( array( 'gift', 'route', 'mail', 'stamp' ) as $next ) {
			$run = $this->wake( $id );
			$this->assertSame( $next, $run['current_step'] );
			$this->assertSame( 0, $item( $run )['index'] );
		}
		$run = $this->wake( $id );
		$this->assertSame( 'each', $run['current_step'], 'The last body step returns to the loop.' );
		$this->assertSame( 0, $item( $run )['index'], 'The advance happens when the cursor arrives, not before.' );
		$rows = count( $this->steps( $id ) );

		$run = $this->wake( $id );
		$this->assertSame( 1, $item( $run )['index'] );
		$this->assertSame( 'gift', $run['current_step'], 'The advance is not a step: the next item\'s first step ran in the same wake-up.' );
		$this->assertCount( $rows + 1, $this->steps( $id ) );
		$this->assertSame( 2, $this->steps( $id )[ $rows ]['iteration'] );

		for ( $i = 0; $i < 10 && 'completed' !== $run['status']; $i++ ) {
			$run = $this->wake( $id );
		}
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'loops', $run['context'], 'The state is removed when the loop ends.' );
		$this->assertSame( array( 'count' => 2 ), $run['context']['steps']['each'] );
		$this->assertSame(
			array( 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1', 'stamp#1', 'has_email#2', 'gift#2', 'route#2', 'mail#2', 'stamp#2', 'after' ),
			$this->trace( $id )
		);
		$this->assertSame(
			array(
				array(
					'to'      => 'ada@example.org',
					'subject' => 'Task 1: Ada',
				),
				array(
					'to'      => 'bo@example.org',
					'subject' => 'Task 2: Bo',
				),
				array(
					'to'      => self::DEVELOPMENT,
					'subject' => 'Went through 2 of 2 open tasks',
				),
			),
			$this->sent()
		);
	}

	/**
	 * An empty collection logs the loop row with 0 items, runs no body step and outputs `count` 0.
	 */
	public function test_empty_collection() {
		$this->task( 'Done already', 'done@example.org', '2026-11-04', true );
		$id = $this->start_items();
		$this->drain();

		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'open', 'each', 'after' ), $this->trace( $id ) );
		$this->assertSame(
			array(
				'items' => 0,
				'ids'   => array(),
			),
			$this->steps( $id )[1]['summary']
		);
		$this->assertSame( array( 'count' => 0 ), $run['context']['steps']['each'] );
		$this->assertSame( 'Went through 0 of 0 open tasks', $this->sent()[0]['subject'] );
	}

	/**
	 * A false condition in the body skips the rest of that item (`passed: false, skipped_item: true`), and
	 * the loop goes on: the run is not stopped.
	 */
	public function test_false_condition_skips_the_item() {
		$this->task( 'No email' );
		$bo = $this->task( 'Bo', 'bo@example.org' );
		$id = $this->start_items();
		$this->drain();

		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $run['context'], 'Not stopped_at.' );
		$this->assertSame( array( 'open', 'each', 'has_email#1', 'has_email#2', 'gift#2', 'route#2', 'mail#2', 'stamp#2', 'after' ), $this->trace( $id ) );
		$this->assertSame(
			array(
				'passed'       => false,
				'skipped_item' => true,
			),
			$this->steps( $id )[2]['summary']
		);
		$this->assertSame( array( 'count' => 2 ), $run['context']['steps']['each'] );
		$this->assertSame( array( 'bo@example.org', self::DEVELOPMENT ), array_column( $this->sent(), 'to' ) );
		$this->assertSame( '', (string) \Pegwork\Api\Records::get( $this->steps( $id )[1]['summary']['ids'][0] )['values']['reminded_on'], 'The skipped item was not stamped.' );
		$this->assertSame( '2026-11-02', \Pegwork\Api\Records::get( $bo )['values']['reminded_on'] );
	}

	/**
	 * An item trashed or deleted after the snapshot is skipped with a loop row `{ item, skipped_item }` in
	 * its iteration; the others run.
	 */
	public function test_deleted_item_is_skipped() {
		$ada   = $this->task( 'Ada', 'ada@example.org' );
		$gone  = $this->task( 'Trashed', 'trashed@example.org' );
		$later = $this->task( 'Deleted', 'deleted@example.org' );
		$cy    = $this->task( 'Cy', 'cy@example.org' );
		$id    = $this->start_items();
		$this->wake( $id );
		$this->wake( $id );
		$this->assertSame( 0, $this->run_row( $id )['context']['loops']['each']['index'] );

		wp_trash_post( $gone );
		wp_delete_post( $later, true );
		$this->drain();

		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame(
			array( 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1', 'stamp#1', 'each#2', 'each#3', 'has_email#4', 'gift#4', 'route#4', 'mail#4', 'stamp#4', 'after' ),
			$this->trace( $id )
		);
		$rows = $this->steps( $id );
		$this->assertSame(
			array(
				'item'         => $gone,
				'skipped_item' => true,
			),
			$rows[7]['summary']
		);
		$this->assertSame( $later, $rows[8]['summary']['item'] );
		$this->assertSame( array( 'ada@example.org', 'cy@example.org', self::DEVELOPMENT ), array_column( $this->sent(), 'to' ) );
		$this->assertSame( 'Went through 4 of 4 open tasks', $this->sent()[2]['subject'], '`count` is the items in the snapshot; the find counted at its time.' );
		$this->assertSame( array( $ada, $gone, $later, $cy ), $rows[1]['summary']['ids'] );
	}
}
