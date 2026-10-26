<?php
/**
 * Integration tests: the start_automation step (Automation v4 PR 5).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use Pegwork\Tests\Support\FailingScheduler;
use PegworkAutomation\Admin\RunLog;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/FailingScheduler.php';

/**
 * Automation v4 PR 5 (docs/automation-v4-design.md sections 2.3, 3.4, 3.6, 3.7): a start_automation step queues a run of a
 * manual automation at depth + 1 with the key `start:<run>-<step>[-<iteration>]` (which wins over the
 * target's own idempotency), reports `started`, and never starts a second child on a retry or resume.
 */
class AutomationStartTest extends AutomationTestCase {

	/**
	 * Scheduler wrapper.
	 *
	 * @var FailingScheduler
	 */
	private $failing;

	/**
	 * Registers the start fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/engine-start';
	}

	/**
	 * Boots with the failing wrapper around the fake scheduler.
	 */
	protected function boot() {
		parent::boot();
		$this->failing = new FailingScheduler( $this->scheduler );
		Engine::use( $this->clock, $this->failing );
	}

	/**
	 * Resets the mail mock.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->assertSame( array(), array_map( 'strval', \Pegwork\Plugin::result()->errors ) );
	}

	/**
	 * Creates a gift (record.created starts st_thanks unless it is disabled).
	 *
	 * @param int $amount Amount.
	 * @return int Record ID.
	 */
	private function gift( $amount = 300 ) {
		$id = Records::create(
			'st_gift',
			array(
				'name'   => 'Gift of ' . $amount,
				'amount' => $amount,
			),
			'publish'
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Subjects of the mail sent so far.
	 *
	 * @return string[]
	 */
	private function subjects() {
		return array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
	}

	/**
	 * The step rows of a run for one step.
	 *
	 * @param int    $run  Run ID.
	 * @param string $step Step ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( $run, $step ) {
		return array_values( array_filter( $this->steps( $run ), fn( $row ) => $step === $row['step_id'] ) );
	}

	/**
	 * Staff start of a manual automation.
	 *
	 * @param string   $key    Automation key.
	 * @param int|null $record Record ID.
	 * @return int Run ID.
	 */
	private function start( $key, $record ) {
		$result = ( new Manual() )->start( $key, $record, $this->editor, 'editor' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result['run'];
	}

	/**
	 * The child is queued at depth + 1 with the parent's user, source `automation` and `context.parent`, the
	 * parent goes on to its wait without waiting for the child, and the key wins over the target's own.
	 */
	public function test_child_runs_at_depth_plus_one_and_parent_continues() {
		$gift = $this->gift();
		$this->drain();

		$parent = $this->only_run( 'st_thanks' );
		$child  = $this->only_run( 'st_receipt' );
		$this->assertSame( 'waiting', $parent['status'], 'The parent went on to its 30-day wait.' );
		$this->assertSame( 'follow_up', $parent['current_step'] );
		$this->assertSame( 'completed', $child['status'] );
		$this->assertSame( 'manual', $child['trigger_type'] );
		$this->assertSame( 1, (int) $child['depth'] );
		$this->assertSame( $gift, (int) $child['record_id'] );
		$this->assertSame( 'start:' . $parent['id'] . '-receipt', $child['idempotency_key'] );
		$this->assertSame(
			array(
				'user_id' => $this->editor,
				'source'  => 'automation',
				'origin'  => array(
					'source' => 'automation',
					'depth'  => 1,
				),
			),
			$child['context']['trigger']
		);
		$this->assertSame(
			array(
				'run'        => (int) $parent['id'],
				'automation' => 'st_thanks',
				'step'       => 'receipt',
			),
			$child['context']['parent']
		);
		$this->assertSame( 1, $parent['context']['starts'] );
		$this->assertTrue( $parent['context']['steps']['receipt']['started'] );

		$row = $this->rows( $parent['id'], 'receipt' );
		$this->assertCount( 1, $row );
		$this->assertSame(
			array(
				'automation' => 'st_receipt',
				'record_id'  => $gift,
				'started'    => true,
				'run'        => (int) $child['id'],
			),
			$row[0]['summary']
		);
		$this->assertSame( array( 'Thanks for gift ' . $gift, 'Receipt for gift ' . $gift ), $this->subjects(), 'Asynchronous: the parent went on before the child ran.' );
		$this->assertStringContainsString( 'started by user ' . $this->editor . ' from automation', tests_retrieve_phpmailer_instance()->mock_sent[1]['body'] );

		// The target's own idempotency ("receipt-<id>") does not dedupe chained starts: a staff start uses it,
		// and another parent's start uses its own key.
		$this->start( 'st_receipt', $gift );
		$resend = $this->start( 'st_resend', $gift );
		$this->drain();
		$keys = array_column( $this->runs( 'st_receipt' ), 'idempotency_key' );
		$this->assertSame( array( 'start:' . $parent['id'] . '-receipt', 'receipt-' . $gift, 'start:' . $resend . '-receipt' ), $keys );
		$this->assertSame( 'completed', $this->run_row( $resend )['status'] );

		// The child without a content type gets no record.
		$note = $this->only_run( 'st_note' );
		$this->assertNull( $note['record_id'] );
		$this->assertSame( 'start:' . $resend . '-note', $note['idempotency_key'] );
		$this->assertContains( 'Note from user ' . $this->editor, $this->subjects() );
	}

	/**
	 * A false `when` on the target starts nothing and is not a failure; the depth cap logs a skipped run.
	 */
	public function test_when_false_and_depth_cap_report_started_false() {
		$this->gift( 0 );
		$this->drain();

		$parent = $this->only_run( 'st_thanks' );
		$this->assertSame( 'waiting', $parent['status'] );
		$this->assertSame( array(), $this->runs( 'st_receipt' ), 'Nothing started.' );
		$this->assertFalse( $parent['context']['steps']['receipt']['started'] );
		$this->assertSame(
			array(
				'automation' => 'st_receipt',
				'record_id'  => (int) $parent['record_id'],
				'started'    => false,
				'skipped'    => 'not_applicable',
			),
			$this->rows( $parent['id'], 'receipt' )[0]['summary']
		);
		$this->assertArrayNotHasKey( 'starts', $parent['context'] );

		// A thanks run at depth 2 (as through the inbound webhook path one level further) logs a skipped receipt.
		global $wpdb;
		$gift = $this->gift();
		$runs = $this->runs( 'st_thanks' );
		$deep = (int) end( $runs )['id'];
		$wpdb->update( \PegworkAutomation\Schema::runs(), array( 'depth' => 2 ), array( 'id' => $deep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$this->drain();

		$skipped = $this->only_run( 'st_receipt' );
		$this->assertSame( 'skipped', $skipped['status'] );
		$this->assertSame( 3, (int) $skipped['depth'] );
		$this->assertSame( 'loop_depth: trigger depth 3 reached the limit of 3.', $skipped['error'] );
		$this->assertSame( $deep, $skipped['context']['parent']['run'] );
		$summary = $this->rows( $deep, 'receipt' )[0]['summary'];
		$this->assertFalse( $summary['started'] );
		$this->assertSame( 'loop_depth', $summary['skipped'] );
		$this->assertSame( (int) $skipped['id'], $summary['run'] );
		$this->assertSame( 'waiting', $this->run_row( $deep )['status'] );
		$this->assertNotContains( 'Receipt for gift ' . $gift, $this->subjects() );
	}

	/**
	 * A disabled target and a trashed record fail the step without retry.
	 */
	public function test_disabled_target_and_trashed_record_fail() {
		Overrides::set( 'st_receipt', 'off' );
		$this->gift();
		$this->drain();

		$parent = $this->only_run( 'st_thanks' );
		$this->assertSame( 'failed', $parent['status'] );
		$this->assertStringStartsWith( 'automation_disabled: ', $parent['error'] );
		$this->assertCount( 1, $this->rows( $parent['id'], 'receipt' ), 'No retry.' );
		$this->assertSame( array(), $this->runs( 'st_receipt' ) );
		$this->assertSame( array(), $this->subjects(), 'The run stopped there.' );

		Overrides::set( 'st_receipt', 'on' );
		Overrides::set( 'st_thanks', 'off' );
		$gift   = $this->gift();
		$resend = $this->start( 'st_resend', $gift );
		wp_trash_post( $gift );
		$this->drain();
		$this->assertSame( 'failed', $this->run_row( $resend )['status'] );
		$this->assertStringStartsWith( 'pegwork_record_not_found: ', $this->run_row( $resend )['error'] );
		$this->assertSame( array(), $this->runs( 'st_receipt' ) );
	}

	/**
	 * The step's retry policy: the first attempt ends after the child's insert; the retry finds the child by
	 * its key, schedules its wake-up again, and starts no second one.
	 */
	public function test_retry_starts_no_second_child() {
		Overrides::set( 'st_thanks', 'off' );
		$gift                      = $this->gift();
		$this->failing->automation = 'st_receipt';
		$this->failing->fail_after = 0;
		$resend                    = $this->start( 'st_resend', $gift );
		$this->drain();

		$this->assertSame( 'waiting', $this->run_row( $resend )['status'] );
		$child = $this->only_run( 'st_receipt' );
		$this->assertSame( 'queued', $child['status'], 'Inserted, never woken.' );

		$this->clock->advance( 'PT5M' );
		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $resend )['status'] );
		$this->assertSame( 'completed', $this->only_run( 'st_receipt' )['status'], 'The same child ran.' );
		$rows = $this->rows( $resend, 'receipt' );
		$this->assertSame( array( 'retrying', 'ok' ), array_column( $rows, 'status' ) );
		$this->assertSame( (int) $child['id'], $rows[1]['summary']['run'] );
		$this->assertTrue( $rows[1]['summary']['started'] );
		$this->assertSame( 1, count( array_keys( $this->subjects(), 'Receipt for gift ' . $gift, true ) ) );
		$this->assertSame( 2, $this->run_row( $resend )['context']['starts'], 'The receipt and the note, each counted once.' );
	}

	/**
	 * Retry from the log after an interrupted start step starts no second child either.
	 */
	public function test_interrupt_retry_starts_no_second_child() {
		$this->failing->automation = 'st_receipt';
		$this->failing->fail_after = 0;
		$gift                      = $this->gift();
		$this->drain();

		$parent = $this->only_run( 'st_thanks' );
		$this->assertSame( 'failed', $parent['status'] );
		$this->assertStringStartsWith( 'exception: RuntimeException', $parent['error'] );
		$this->assertCount( 1, $this->runs( 'st_receipt' ) );

		$this->assertTrue( ( new Manual() )->retry( (int) $parent['id'] ) );
		$this->drain();
		$this->assertSame( 'waiting', $this->run_row( $parent['id'] )['status'] );
		$child = $this->only_run( 'st_receipt' );
		$this->assertSame( 'completed', $child['status'] );
		$this->assertSame( array( 'Thanks for gift ' . $gift, 'Receipt for gift ' . $gift ), $this->subjects() );
		$this->assertSame( 1, $this->run_row( $parent['id'] )['context']['starts'] );
	}

	/**
	 * Inside a loop each item starts one child (the key carries the iteration); a failure at item 2 and Retry
	 * from the log resume at item 2 without a second child for it.
	 */
	public function test_loop_iterations_each_start_one_child() {
		Overrides::set( 'st_thanks', 'off' );
		$gifts                     = array( $this->gift( 10 ), $this->gift( 20 ), $this->gift( 30 ) );
		$this->failing->automation = 'st_receipt';
		$this->failing->fail_after = 1;
		$parent                    = $this->start( 'st_each', null );
		$this->drain();

		$this->assertSame( 'failed', $this->run_row( $parent )['status'] );
		$this->assertCount( 2, $this->runs( 'st_receipt' ), 'Item 2 inserted its child before the request ended.' );

		$this->assertTrue( ( new Manual() )->retry( $parent ) );
		$this->drain();

		$this->assertSame( 'completed', $this->run_row( $parent )['status'] );
		$children = $this->runs( 'st_receipt' );
		$this->assertSame(
			array( 'start:' . $parent . '-receipt-1', 'start:' . $parent . '-receipt-2', 'start:' . $parent . '-receipt-3' ),
			array_column( $children, 'idempotency_key' )
		);
		$this->assertSame( $gifts, array_map( 'intval', array_column( $children, 'record_id' ) ) );
		$this->assertSame( array( 'completed', 'completed', 'completed' ), array_column( $children, 'status' ) );
		$this->assertSame( 2, $children[1]['context']['parent']['iteration'] );
		$receipts = array_values( array_filter( $this->subjects(), fn( $subject ) => str_starts_with( $subject, 'Receipt for gift ' ) ) );
		$this->assertCount( 3, $receipts );
		$this->assertContains( 'Receipts for 3 gifts', $this->subjects() );
		$this->assertSame( 3, $this->run_row( $parent )['context']['starts'] );
	}

	/**
	 * Past the per-run start limit the step fails `start_limit` without retry.
	 */
	public function test_start_limit() {
		Overrides::set( 'st_thanks', 'off' );
		$this->gift( 10 );
		$this->gift( 20 );
		$this->gift( 30 );
		add_filter( 'pegwork_automation_start_limit', fn() => 2 );
		$parent = $this->start( 'st_each', null );
		$this->drain();

		$run = $this->run_row( $parent );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'start_limit: This run already started 2 automations, the most one run may start.', $run['error'] );
		$this->assertCount( 2, $this->runs( 'st_receipt' ) );
		$this->assertSame( 2, $run['context']['starts'] );
	}

	/**
	 * The run detail links the child from the parent's step row and the parent from the child's details, for
	 * administrators; other users see the run numbers without links.
	 */
	public function test_run_detail_links_both_ways() {
		$this->gift();
		$this->drain();
		$parent = (int) $this->only_run( 'st_thanks' )['id'];
		$child  = (int) $this->only_run( 'st_receipt' )['id'];

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render( $child );
		$this->assertStringContainsString( '<th scope="row">Started by</th>', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( RunLog::run_url( $parent ) ) . '">Run ' . $parent . '</a> of Thank the donor of a new gift (step <code>receipt</code>)', $html );
		$this->assertStringContainsString( '<a href="' . esc_url( RunLog::run_url( $child ) ) . '">Run ' . $child . '</a>', $this->render( $parent ) );
		$this->assertStringNotContainsString( 'Started by', $this->render( $parent ) );

		wp_set_current_user( $this->editor );
		$this->assertStringNotContainsString( esc_url( RunLog::run_url( $parent ) ), $this->render( $child ) );
		$this->assertStringContainsString( 'Run ' . $parent . ' of Thank the donor', $this->render( $child ) );
	}

	/**
	 * The test run says what the start would do and starts nothing: no run row, no mail.
	 */
	public function test_dry_run_lines() {
		Overrides::set( 'st_thanks', 'off' );
		$gift  = $this->gift();
		$zero  = $this->gift( 0 );
		$lines = fn( $key, $id ) => DryRun::result( $key, $id, null )['lines'];

		$this->assertContains( 'Start st_receipt "Send receipt" for this Gift: it applies (not started). Test it from its own editor.', $lines( 'st_thanks', $gift ) );
		$this->assertContains( 'Start st_receipt "Send receipt" for this Gift: it does not apply (its Only when is false).', $lines( 'st_thanks', $zero ) );
		$this->assertContains( 'Start st_note "Send note": it applies (not started). Test it from its own editor.', $lines( 'st_resend', $gift ) );
		$this->assertSame( 'Thanks for gift ' . $gift, DryRun::result( 'st_thanks', $gift, null )['steps'][1]['detail']['subject'] );

		add_filter( 'pegwork_automation_max_depth', fn() => 1 );
		$this->assertContains( 'Start st_receipt "Send receipt" for this Gift: it would be skipped (depth limit).', $lines( 'st_thanks', $gift ) );
		remove_all_filters( 'pegwork_automation_max_depth' );

		Overrides::set( 'st_receipt', 'off' );
		$this->assertContains( 'Start receipt: would fail: Automation Send the receipt for a gift is disabled on this site; enable it first.', $lines( 'st_thanks', $gift ) );

		Overrides::set( 'st_receipt', 'on' );
		$each = $lines( 'st_each', null );
		$this->assertContains( '  Start st_receipt "Send receipt" for Gift ' . $gift . ': it applies (not started). Test it from its own editor.', $each, 'A loop item is a real record.' );
		$this->assertContains( '  Start st_receipt "Send receipt" for Gift ' . $zero . ': it does not apply (its Only when is false).', $each );

		$this->assertSame( array(), $this->runs( 'st_receipt' ) );
		$this->assertSame( array(), $this->runs( 'st_note' ) );
		$this->assertSame( array(), $this->subjects() );
	}

	/**
	 * Renders the run detail.
	 *
	 * @param int $id Run ID.
	 * @return string HTML.
	 */
	private function render( $id ) {
		ob_start();
		try {
			RunLog::render_run( $id );
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}
}
