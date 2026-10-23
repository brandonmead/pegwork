<?php
/**
 * Integration tests: loops at run time (Automation v3 PR 4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\LoopsTestCase;
use PegworkAutomation\Admin\Format;
use PegworkAutomation\Admin\RunLog;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Runs;
use PegworkAutomation\Schema;

require_once dirname( __DIR__, 2 ) . '/Support/LoopsTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tests rebuild and read the module's tables.

/**
 * The digest of worked example c end to end, the same emails under a zero time budget, retries inside a
 * loop (defaults off, explicit kept, Retry from the log resumes the item), per-item delivery IDs, the read
 * and step limits, `definition_changed` mid-loop, the run detail and CLI grouping by item, the dry run's
 * loop report, the digest time budget, and a Schema 1 site upgrading with its waiting run intact.
 */
class AutomationLoopTest extends LoopsTestCase {

	/**
	 * Fires the digest once and runs it.
	 *
	 * @return array<string, mixed> The newest digest run.
	 */
	private function digest() {
		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$this->drain();
		$runs = $this->runs( 'barn_tasks_digest' );
		return end( $runs );
	}

	/**
	 * Worked example c: T4 (overdue, no assignee) then T1 in due order; T4's reminder fails `no_recipient`
	 * and the loop goes on (`on_error`); both are stamped; the summary lists both with "2 tasks due"; the
	 * outcome is `completed_with_errors:remind`; a second firing the same day creates nothing; a week later
	 * T3 is due too.
	 */
	public function test_digest_worked_example() {
		$t1 = $this->task( 'Call Ada', 't1@example.org', '2026-11-04' );
		$this->task( 'Send receipts', 't2@example.org', '2026-11-05', true );
		$t3 = $this->task( 'Plan gala', 't3@example.org', '2026-11-20' );
		$t4 = $this->task( 'Thank Bo' );
		Records::update( $t4, array( 'due_on' => '2026-10-30' ) );

		$run = $this->digest();
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'completed_with_errors:remind', $run['context']['outcome'] );
		$this->assertSame( array( 'due', 'each_task', 'remind#1:failed', 'stamp#1', 'remind#2', 'stamp#2', 'summary' ), $this->trace( $run['id'] ) );
		$this->assertStringStartsWith( 'no_recipient:', $this->steps( $run['id'] )[2]['error'] );
		$this->assertSame( array( $t4, $t1 ), $this->steps( $run['id'] )[1]['summary']['ids'] );
		$this->assertSame( array( 'count' => 2 ), $run['context']['steps']['each_task'] );
		$this->assertArrayNotHasKey( 'loops', $run['context'] );

		$mail = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 2, $mail );
		$this->assertSame( 't1@example.org', $mail[0]['to'][0][0] );
		$this->assertSame( 'Due November 4, 2026: Call Ada', $mail[0]['subject'] );
		$this->assertSame( self::DEVELOPMENT, $mail[1]['to'][0][0] );
		$this->assertSame( '2 tasks due by November 9, 2026', $mail[1]['subject'] );
		$this->assertStringContainsString( "- Thank Bo, due October 30, 2026, no assignee\n- Call Ada, due November 4, 2026, t1@example.org\n", str_replace( "\r\n", "\n", $mail[1]['body'] ) );
		$this->assertSame( '2026-11-02', Records::get( $t4 )['values']['reminded_on'], 'The failed reminder does not stop the stamp.' );
		$this->assertSame( '2026-11-02', Records::get( $t1 )['values']['reminded_on'] );

		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$this->drain();
		$this->assertCount( 1, $this->runs( 'barn_tasks_digest' ), 'Idempotency: a second firing the same day creates nothing.' );

		$this->clock->set( '2026-11-16 08:00' );
		$later = $this->digest();
		$this->assertCount( 2, $this->runs( 'barn_tasks_digest' ) );
		$this->assertSame( array( $t4, $t1, $t3 ), $this->steps( $later['id'] )[1]['summary']['ids'] );
		$this->assertSame( '3 tasks due by November 23, 2026', tests_retrieve_phpmailer_instance()->mock_sent[4]['subject'] );
	}

	/**
	 * Time budget 0 (one step per wake-up, requeued at the cursor, loop items included) sends the same
	 * emails, in the same order, with no duplicates.
	 */
	public function test_zero_time_budget_gives_the_same_emails() {
		$expected = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four', 'Five' ) as $i => $name ) {
			$due = sprintf( '2026-11-%02d', 3 + $i );
			$this->task( $name, strtolower( $name ) . '@example.org', $due );
			$expected[] = strtolower( $name ) . '@example.org Due ' . wp_date( 'F j, Y', strtotime( $due . ' 12:00' ) ) . ': ' . $name;
		}
		$expected[] = self::DEVELOPMENT . ' 5 tasks due by November 9, 2026';

		add_filter( 'pegwork_automation_time_budget', fn() => 0 );
		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$wakeups = $this->drain();

		$run = $this->only_run( 'barn_tasks_digest' );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'completed', $run['context']['outcome'] ?? 'completed' );
		$this->assertGreaterThanOrEqual( 12, $wakeups, 'Requeued between steps, inside the loop too.' );
		$this->assertSame( $expected, array_map( fn( $mail ) => $mail['to'] . ' ' . $mail['subject'], $this->sent() ) );
		$this->assertSame( array( 'due', 'each_task', 'remind#1', 'stamp#1', 'remind#2', 'stamp#2', 'remind#3', 'stamp#3', 'remind#4', 'stamp#4', 'remind#5', 'stamp#5', 'summary' ), $this->trace( $run['id'] ) );
	}

	/**
	 * Inside a body the type's default retries are off: a webhook that fails fails the run at once (no
	 * `retrying` row, not "after n attempts"); Retry from the log resumes at the failed item, earlier items
	 * are not repeated, attempts count per step and item, and the delivery ID carries the item number and
	 * is the same on the retried attempt.
	 */
	public function test_default_retries_off_and_retry_from_the_log_resumes_the_item() {
		$ada  = $this->task( 'Ada', 'ada@example.org' );
		$gift = $this->gift( 'Gift for Cy' );
		$cy   = $this->task( 'Cy', 'cy@example.org', '2026-11-04', false, array( $gift ) );

		$this->status = 500;
		$id           = $this->start_items();
		$this->drain();

		$run = $this->run_row( $id );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringNotContainsString( 'attempts', (string) $run['error'] );
		$this->assertSame( array( 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1', 'stamp#1', 'has_email#2', 'gift#2', 'route#2', 'notify#2:failed' ), $this->trace( $id ) );
		$this->assertSame( 'notify', $run['current_step'] );
		$this->assertSame(
			array(
				'ids'   => array( $ada, $cy ),
				'index' => 1,
			),
			$run['context']['loops']['each']
		);
		$this->assertCount( 1, $this->requests );

		$this->status = 200;
		$this->assertTrue( ( new Manual() )->retry( $id ) );
		$this->drain();

		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1', 'stamp#1', 'has_email#2', 'gift#2', 'route#2', 'notify#2:failed', 'notify#2', 'stamp#2', 'after' ), $this->trace( $id ) );
		$this->assertSame( array( 1, 2 ), array_map( 'intval', array_column( array_slice( $this->steps( $id ), 10, 2 ), 'attempt' ) ) );
		$this->assertSame( array( 'ada@example.org', self::DEVELOPMENT ), array_column( $this->sent(), 'to' ), 'Ada was not emailed again.' );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( array( $id . '-notify-2', $id . '-notify-2' ), array_map( fn( $r ) => $r['headers']['X-Pegwork-Delivery'], $this->requests ) );
		$this->assertSame( $cy, $this->requests[1]['body']['record']['id'] );
		$this->assertSame( $id . '-notify-2', $this->steps( $id )[11]['summary']['delivery'] );
	}

	/**
	 * An explicit `retry` in a body still applies: the run waits at the same step and item, and resumes it.
	 */
	public function test_explicit_retry_resumes_the_same_item() {
		$ada   = $this->task( 'Ada', 'ada@example.org' );
		$bo    = $this->task( 'Bo', 'bo@example.org' );
		$fails = 1;
		add_filter(
			'pre_wp_mail',
			function ( $pre ) use ( &$fails ) {
				return $fails-- > 0 ? false : $pre;
			}
		);

		$id = $this->start_items();
		$this->drain();
		$run = $this->run_row( $id );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'mail', $run['current_step'] );
		$this->assertSame( 0, $run['context']['loops']['each']['index'] );
		$this->assertSame( '2026-11-02 09:05:00', $run['resume_at'] );

		$this->clock->advance( 'PT5M' );
		$this->drain();
		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame(
			array( 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1:retrying', 'mail#1', 'stamp#1', 'has_email#2', 'gift#2', 'route#2', 'mail#2', 'stamp#2', 'after' ),
			$this->trace( $id )
		);
		$this->assertSame( array( 'ada@example.org', 'bo@example.org', self::DEVELOPMENT ), array_column( $this->sent(), 'to' ) );
		$this->assertSame( array( $ada, $bo ), $this->steps( $id )[1]['summary']['ids'] );
	}

	/**
	 * Loop items count toward the read limit (the find's IDs plus the snapshot), and the step limit has a
	 * filter.
	 */
	public function test_read_and_step_limits() {
		$this->task( 'Ada', 'ada@example.org' );
		$this->task( 'Bo', 'bo@example.org' );

		add_filter( 'pegwork_automation_read_limit', fn() => 3 );
		$id = $this->start_items();
		$this->drain();
		$run = $this->run_row( $id );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'read_limit: This read returned 2 records; the run has read 2 of at most 3.', (string) $run['error'] );
		$this->assertSame( array( 'open', 'each:failed' ), $this->trace( $id ) );
		remove_all_filters( 'pegwork_automation_read_limit' );

		add_filter( 'pegwork_automation_step_limit', fn() => 4 );
		$id = $this->start_items();
		$this->drain();
		$this->assertSame( 'step_limit: more than 4 step attempts.', $this->run_row( $id )['error'] );
		$this->assertCount( 4, $this->steps( $id ) );
	}

	/**
	 * A definition edit that no longer fits the stored loop state fails the run `definition_changed`: the
	 * cursor's step is gone, the cursor is now inside a loop it has no state for, or the state is for a
	 * loop no longer around the cursor.
	 */
	public function test_definition_changed_mid_loop() {
		global $wpdb;
		$this->task( 'Ada', 'ada@example.org' );
		$this->task( 'Bo', 'bo@example.org' );
		add_filter( 'pegwork_automation_time_budget', fn() => 0 );

		$cases = array(
			'step "gone" is no longer in the automation.' => fn( $run ) => array( 'gone', $run['context'] ),
			'step "gift" is now inside loop "each".'      => fn( $run ) => array( 'gift', array_diff_key( $run['context'], array( 'loops' => true ) ) ),
			'loop "each" is no longer around step "after".' => fn( $run ) => array( 'after', $run['context'] ),
		);
		foreach ( $cases as $message => $edit ) {
			$id = $this->start_items();
			for ( $i = 0; $i < 3; $i++ ) {
				Engine::instance()->executor()->execute( $id );
			}
			$run = $this->run_row( $id );
			$this->assertSame( 'gift', $run['current_step'] );
			$this->assertArrayHasKey( 'each', $run['context']['loops'] );

			[ $step, $context ] = $edit( $run );
			$wpdb->update(
				Schema::runs(),
				array(
					'current_step' => $step,
					'context'      => Runs::encode_context( $context ),
				),
				array( 'id' => $id )
			);
			$rows = count( $this->steps( $id ) );
			$this->assertSame( 'failed', Engine::instance()->executor()->execute( $id ) );
			$this->assertSame( 'definition_changed: ' . $message, $this->run_row( $id )['error'] );
			$this->assertCount( $rows, $this->steps( $id ), 'Nothing ran.' );
		}
	}

	/**
	 * The run detail and `log --run` group body rows under "Item k of n: Task #id (title)"; the run detail
	 * names read records live; titles show only to a viewer who can read the record.
	 */
	public function test_run_detail_and_cli_group_rows_by_item() {
		$ada  = $this->task( 'Ada', 'ada@example.org' );
		$gift = $this->gift( 'Gift for Bo' );
		$bo   = $this->task( 'Bo', 'bo@example.org', '2026-11-04', false, array( $gift ) );
		$id   = $this->start_items();
		$this->drain();
		$run = $this->run_row( $id );
		$this->assertSame( 'completed', $run['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		RunLog::render_run( $id );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<tr class="pegwork-branch-row pegwork-item-row"><td colspan="7" class="pegwork-depth-0"><strong>Item 1 of 2: Task #' . $ada . ' (Ada)</strong></td></tr>', $html );
		$this->assertStringContainsString( '<strong>Item 2 of 2: Task #' . $bo . ' (Bo)</strong>', $html );
		$this->assertSame( 2, substr_count( $html, 'pegwork-item-row' ) );
		$this->assertSame( 2, substr_count( $html, 'Otherwise branch of' ) + substr_count( $html, 'Branch <strong>Has a gift</strong>' ), 'Each item gets its branch heading.' );
		$this->assertStringContainsString( 'Read: Task #' . $ada . ' (Ada), Task #' . $bo . ' (Bo)', $html );
		$this->assertStringContainsString( 'Read: Gift #' . $gift . ' (Gift for Bo)', $html );
		$this->assertMatchesRegularExpression( '#pegwork-depth-1"><code>has_email</code>#', $html, 'Body rows are indented one level.' );

		$rows = AutomationCommand::step_rows( $run, $this->steps( $id ), true );
		$this->assertSame( 'Item 1 of 2: Task #' . $ada . ' (Ada)', $rows[2]['step'] );
		$this->assertSame( '  has_email', $rows[3]['step'] );
		$this->assertSame( 1, $rows[3]['iteration'] );
		$this->assertSame( 'Item 2 of 2: Task #' . $bo . ' (Bo)', $rows[8]['step'] );
		$this->assertSame( '    notify', $rows[12]['step'] );
		$plain = AutomationCommand::step_rows( $run, $this->steps( $id ), false );
		$this->assertCount( count( $this->steps( $id ) ), $plain, 'Other formats have no item lines.' );
		$this->assertSame( array( '', '', 1, 1, 1, 1, 1, 2, 2, 2, 2, 2, '' ), array_column( $plain, 'iteration' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 'Gift #' . $gift, Format::record_name( $gift ), 'No title for a viewer who cannot read the private gift.' );
		$this->assertSame( 'Item 2 of 2: Task #' . $bo, Format::item( $this->steps( $id )[1]['summary'], 2 ), 'Tasks are not public: no title without a user who can read them.' );
		$this->assertSame( 'Item 3 of 2', Format::item( $this->steps( $id )[1]['summary'], 3 ) );
	}

	/**
	 * The dry run iterates every item with real reads, shows the first 3 in full under item headings and
	 * then "and 2 more items, same steps", and sends, writes and logs nothing.
	 */
	public function test_dry_run_loop_report() {
		global $wpdb;
		$ids = array();
		foreach ( array( 'Ann', 'Ben', 'Cat', 'Dan', 'Eve' ) as $name ) {
			$ids[] = $this->task( $name, strtolower( $name ) . '@example.org' );
		}
		$this->task( 'Fay' ); // No email: skipped in a real run, and here.
		$runs  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::runs() ) );
		$steps = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::steps() ) );

		$report = DryRun::result( 'lp_items', null, array() );
		$this->assertIsArray( $report );
		$this->assertSame( 'completed', $report['outcome'] );
		$this->assertSame( 'Loop each: repeats its steps for 6 Tasks.', $report['steps'][1]['message'] );
		$this->assertSame(
			array( 'open', 'each', 'has_email', 'gift', 'route', 'mail', 'stamp', 'has_email', 'gift', 'route', 'mail', 'stamp', 'has_email', 'gift', 'route', 'mail', 'stamp', 'each', 'after' ),
			array_column( $report['steps'], 'id' )
		);
		$this->assertSame( 'and 3 more items, same steps.', $report['steps'][17]['message'] );
		$this->assertSame( 1, $report['steps'][17]['depth'] );
		$this->assertSame( 'Send email after: to d***@example.org, subject "Went through 6 of 6 open tasks" (not sent).', $report['steps'][18]['message'] );
		$this->assertSame(
			array(
				'index' => 2,
				'count' => 6,
				'id'    => $ids[1],
				'title' => 'Ben',
				'label' => 'Task',
			),
			$report['steps'][7]['item']
		);
		$this->assertContains( 'Item 1 of 6: Task ' . $ids[0] . ' "Ann":', $report['lines'] );
		$this->assertContains( '    Send email mail: to a***@example.org, subject "Task 1: Ann" (not sent).', $report['lines'], 'Body depth 1, the otherwise branch depth 2.' );
		$this->assertContains( '    Send email mail: to c***@example.org, subject "Task 3: Cat" (not sent).', $report['lines'] );
		$this->assertNotContains( 'Item 4 of 6: Task ' . $ids[3] . ' "Dan":', $report['lines'] );

		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertSame( array(), $this->requests );
		$this->assertSame( $runs, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::runs() ) ) );
		$this->assertSame( $steps, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::steps() ) ) );
		$this->assertSame( '', (string) Records::get( $ids[0] )['values']['reminded_on'] );

		// A later item's failure is still shown, with its heading; a skipped item past the first 3 is not.
		$this->task( 'Gus', 'gus@example.org', '2026-11-04', false, array( $this->gift( 'Gift for Gus' ) ) );
		add_filter( 'pegwork_automation_read_limit', fn() => 14 ); // 7 found, 7 in the snapshot, then Gus's gift is one too many.
		$report = DryRun::report( 'lp_items', null, array() );
		$this->assertSame( 'failed_at:gift', $report['outcome'] );
		$last = end( $report['steps'] );
		$this->assertSame( 'fails', $last['result'] );
		$this->assertSame( 7, $last['item']['index'], 'The failing item is shown with its heading.' );
		$skip = array_values( array_filter( $report['steps'], fn( $entry ) => 'skips' === $entry['result'] ) );
		$this->assertCount( 0, $skip, 'Fay (item 6) is past the items shown in full.' );
	}

	/**
	 * The digest's design budget (section 8): a 50-item wake-up takes under 5 s.
	 */
	public function test_fifty_item_digest_meets_the_time_budget() {
		for ( $i = 1; $i <= 50; $i++ ) {
			$this->task( 'Task ' . $i, 'assignee' . $i . '@example.org', '2026-11-0' . ( 1 + $i % 8 ) );
		}
		$start = microtime( true );
		$run   = $this->digest();
		$taken = microtime( true ) - $start;

		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 51, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertCount( 103, $this->steps( $run['id'] ), 'find, loop, 50 times remind and stamp, summary.' );
		$this->assertLessThan( 5.0, $taken, sprintf( 'The 50-item digest took %.2f s.', $taken ) );
		if ( getenv( 'PEGWORK_PERF_REPORT' ) ) {
			fwrite( STDERR, sprintf( "\n50-item digest wake-up: %.3f s, %d step rows\n", $taken, count( $this->steps( $run['id'] ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Perf note for the PR.
		}
	}

	/**
	 * A Schema 1 site upgrades to 2 on boot: `iteration` is added, a waiting Phase 6 shaped run and its
	 * step row are kept as they were, and the run resumes (loop rows get iterations, the old row stays null).
	 *
	 * The Schema 1 tables are temporary tables that shadow the real ones for this test only (the suite turns
	 * CREATE and DROP TABLE into temporary ones). dbDelta's ALTER TABLE commits the test transaction even on
	 * a temporary table, so nothing is written to real tables before it (the set-up user and option are
	 * removed first), and the upgraded option is committed right after it; everything later rolls back.
	 */
	public function test_schema_1_site_upgrades_with_waiting_runs_intact() {
		global $wpdb;
		$runs  = Schema::runs();
		$steps = Schema::steps();
		wp_delete_user( $this->editor );
		delete_option( 'barn_settings' );

		$charset = $wpdb->get_charset_collate();
		$wpdb->query( "CREATE TABLE {$runs} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, automation varchar(64) NOT NULL, trigger_type varchar(16) NOT NULL, record_id bigint(20) unsigned DEFAULT NULL, status varchar(16) NOT NULL, current_step varchar(64) NOT NULL DEFAULT '', resume_at datetime DEFAULT NULL, depth tinyint(3) unsigned NOT NULL DEFAULT 0, idempotency_key varchar(191) DEFAULT NULL, definition_hash char(32) NOT NULL DEFAULT '', context longtext NOT NULL, error text DEFAULT NULL, created_at datetime NOT NULL, claimed_at datetime DEFAULT NULL, started_at datetime DEFAULT NULL, finished_at datetime DEFAULT NULL, PRIMARY KEY  (id), KEY status_resume (status,resume_at), KEY automation_created (automation,created_at), KEY record_id (record_id), UNIQUE KEY automation_key (automation,idempotency_key)) {$charset}" );
		$wpdb->query( "CREATE TABLE {$steps} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, run_id bigint(20) unsigned NOT NULL, step_id varchar(64) NOT NULL, step_type varchar(32) NOT NULL, attempt smallint(5) unsigned NOT NULL DEFAULT 1, status varchar(16) NOT NULL, started_at datetime NOT NULL, duration_ms int(10) unsigned NOT NULL DEFAULT 0, summary text DEFAULT NULL, error text DEFAULT NULL, PRIMARY KEY  (id), KEY run_id (run_id)) {$charset}" );
		try {
			$this->assertNotContains( 'iteration', $wpdb->get_col( "DESCRIBE {$steps}" ), 'Schema 1 tables are in place.' );
			$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ) );

			$id = Engine::instance()->runs()->insert(
				array(
					'automation'      => 'lp_items',
					'trigger_type'    => 'webhook',
					'status'          => 'waiting',
					'record_id'       => null,
					'depth'           => 0,
					'definition_hash' => '',
					'current_step'    => 'open',
					'context'         => array(
						'trigger' => array( 'payload' => array( 'source' => 'phase 6' ) ),
						'steps'   => array(),
						'retries' => array( 'open' => 1 ),
					),
				)
			);
			$wpdb->update( $runs, array( 'resume_at' => '2026-11-02 08:00:00' ), array( 'id' => $id ) );
			$wpdb->insert(
				$steps,
				array(
					'run_id'      => $id,
					'step_id'     => 'open',
					'step_type'   => 'find_records',
					'attempt'     => 1,
					'status'      => 'retrying',
					'started_at'  => '2026-11-02 07:45:00',
					'duration_ms' => 3,
					'error'       => 'read_invalid: before the upgrade',
				)
			);
			$before = $this->run_row( $id );
			$row    = $wpdb->get_row( "SELECT * FROM {$steps}", ARRAY_A );

			update_option( Schema::OPTION, '1' );
			$this->assertTrue( Schema::maybe_install(), 'A Schema 1 site upgrades on boot.' );
			$wpdb->query( 'COMMIT' ); // Keep the option at 2 for later tests (ALTER TABLE committed the 1).

			$this->assertSame( '2', get_option( Schema::OPTION ) );
			$this->assertContains( 'iteration', $wpdb->get_col( "DESCRIBE {$steps}" ) );
			$this->assertSame( $before, $this->run_row( $id ), 'The waiting run is untouched.' );
			$this->assertSame( $row + array( 'iteration' => null ), $wpdb->get_row( "SELECT * FROM {$steps}", ARRAY_A ), 'The old step row is kept, with no iteration.' );
			$this->assertFalse( Schema::maybe_install() );

			// Resume on Schema 2.
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
			update_option( 'barn_settings', array( 'development_email' => self::DEVELOPMENT ) );
			$ada = $this->task( 'Ada', 'ada@example.org' );
			$bo  = $this->task( 'Bo', 'bo@example.org' );
			Engine::instance()->maintenance()->resume( true );
			$this->drain();

			$run = $this->run_row( $id );
			$this->assertSame( 'completed', $run['status'] );
			$this->assertSame( array( 'open:retrying', 'open', 'each', 'has_email#1', 'gift#1', 'route#1', 'mail#1', 'stamp#1', 'has_email#2', 'gift#2', 'route#2', 'mail#2', 'stamp#2', 'after' ), $this->trace( $id ) );
			$this->assertSame( 2, (int) $this->steps( $id )[1]['attempt'], 'Attempts outside loops still count the pre-upgrade row.' );
			$this->assertNull( $this->steps( $id )[0]['iteration'] );
			$this->assertSame( array( $ada, $bo ), $this->steps( $id )[2]['summary']['ids'] );
			$this->assertSame( 'Went through 2 of 2 open tasks', $this->sent()[2]['subject'] );
		} finally {
			$wpdb->query( "DROP TABLE {$steps}" ); // Temporary drops: the real tables are back.
			$wpdb->query( "DROP TABLE {$runs}" );
		}
		$this->assertContains( 'iteration', $wpdb->get_col( "DESCRIBE {$steps}" ) );
		$this->assertNull( Engine::instance()->runs()->get( $id ), 'The shadow run is gone with its table.' );
	}
}
