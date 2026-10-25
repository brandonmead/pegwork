<?php
/**
 * Integration tests: the `trash_record` step (Automation v4 PR 4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Context;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Schema;
use PegworkAutomation\Steps\TrashRecord;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests count rows in the module's tables.

/**
 * Fixture tests/fixtures/valid/engine-trash: `tr_bound` (manual on a volunteer: trash the bound record, trash
 * it again, read it, stamp it and link it, both with on_error, then an email), `tr_read` (trash the record a
 * get_record found), `tr_loop` (trash up to 50 found volunteers in a loop, then 10 more with on_error).
 * docs/automation-v4-design.md sections 2.2, 3.3, 3.7, 3.8 and 7 (`AutomationTrashTest`).
 */
class AutomationTrashTest extends AutomationTestCase {

	/**
	 * Registers the trash fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/engine-trash';
	}

	/**
	 * Fresh mock mailer.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * A volunteer.
	 *
	 * @param string $name  Name.
	 * @param string $stage Stage.
	 * @return int Record ID.
	 */
	private function volunteer( $name = 'Sam', $stage = 'new' ) {
		$id = Records::create(
			'tr_volunteer',
			array(
				'name'  => $name,
				'stage' => $stage,
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Starts a manual automation as the CLI would and runs it to the end.
	 *
	 * @param string   $key    Automation key.
	 * @param int|null $record Record ID.
	 * @return array<string, mixed> The run row.
	 */
	private function start( $key, $record = null ) {
		$result = ( new Manual() )->start( $key, $record, get_current_user_id(), 'cli' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->drain();
		return $this->run_row( $result['run'] );
	}

	/**
	 * Step rows of a run by step ID (the last row of each step).
	 *
	 * @param int $run Run ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function rows( $run ) {
		return array_column( $this->steps( $run ), null, 'step_id' );
	}

	/**
	 * The bound record goes to the trash once (events at depth 1); a second trash is `changed: false`; a read
	 * by its ID no longer finds it; update_record and relate on it fail `pegwork_record_not_found` without a
	 * retry, so the run completes with errors; templates still render its values; restore works.
	 */
	public function test_bound_target_and_later_steps() {
		$sam  = $this->volunteer();
		$gift = Records::create( 'tr_gift', array( 'name' => 'Gift' ), 'private' );
		$this->assertIsInt( $gift );

		$events = array();
		add_action(
			'pegwork_event',
			function ( $name, $payload ) use ( &$events, $sam ) {
				if ( (int) ( $payload['id'] ?? 0 ) === $sam ) {
					$events[] = array( $name, (int) ( $payload['origin']['depth'] ?? -1 ) );
				}
			},
			10,
			2
		);

		$run = $this->start( 'tr_bound', $sam );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( 'completed_with_errors:stamp,link', $run['context']['outcome'] );
		$this->assertSame( 1, $run['context']['trashed'], 'One record moved; the second trash did not count.' );
		$this->assertSame( 'trash', get_post_status( $sam ) );

		$rows = $this->rows( $run['id'] );
		$this->assertSame(
			array(
				'content_type' => 'tr_volunteer',
				'record_id'    => $sam,
				'changed'      => true,
			),
			$rows['remove']['summary']
		);
		$this->assertFalse( $rows['again']['summary']['changed'] );
		$this->assertFalse( $rows['look']['summary']['found'], 'A read by ID treats the trashed record as gone.' );
		foreach ( array( 'stamp', 'link' ) as $step ) {
			$this->assertSame( 'failed', $rows[ $step ]['status'], $step );
			$this->assertStringStartsWith( 'pegwork_record_not_found', (string) $rows[ $step ]['error'], $step );
			$this->assertSame( 1, Engine::instance()->runs()->attempts( (int) $run['id'], $step ), "$step is not retried." );
		}
		$this->assertSame( 'new', Records::get( $sam )['values']['stage'], 'Nothing was written to the trashed record.' );
		$this->assertSame( array(), Records::get( $sam )['values']['tr_volunteer_gift'] ?? array(), 'No link was added.' );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'Removed Sam', $sent[0]['subject'], 'Templates still render the trashed record.' );
		$this->assertStringContainsString( 'Found again: 0. Stamp failed: 1. Link failed: 1.', $sent[0]['body'] );

		$this->assertContains( array( 'record.status_changed', 1 ), $events, 'The trash event carries depth run depth + 1.' );
		$this->assertContains( array( 'record.updated', 1 ), $events );
		$this->assertCount( 2, $events, 'The already-trashed second step emitted nothing.' );

		$this->assertInstanceOf( \WP_Post::class, wp_untrash_post( $sam ) );
		$this->assertNotSame( 'trash', Records::get( $sam )['status'], 'Restored from the trash.' );
		$this->assertSame( 'Sam', Records::get( $sam )['values']['name'] );
		$again = $this->start( 'tr_bound', $sam );
		$this->assertSame( 'completed', $again['status'], 'A restored record can be trashed again.' );
		$this->assertSame( 'trash', get_post_status( $sam ) );
	}

	/**
	 * A read output is a target; with trash turned off (`pegwork_records_trash_enabled`, the test seam for
	 * `EMPTY_TRASH_DAYS` 0) the step fails `trash_disabled`, is not retried, and the post stays.
	 */
	public function test_read_target_and_trash_disabled() {
		$read  = $this->volunteer( 'Read me' );
		$other = $this->volunteer( 'Someone else' );

		add_filter( 'pegwork_records_trash_enabled', '__return_false' );
		$run = $this->start( 'tr_read' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'trash_disabled', (string) $run['error'] );
		$this->assertSame( 1, Engine::instance()->runs()->attempts( (int) $run['id'], 'remove' ) );
		$this->assertSame( 'private', get_post_status( $read ), 'The post is still there, untouched.' );
		remove_filter( 'pegwork_records_trash_enabled', '__return_false' );

		$run = $this->start( 'tr_read' );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( $read, $this->rows( $run['id'] )['remove']['summary']['record_id'] );
		$this->assertSame( 'trash', get_post_status( $read ) );
		$this->assertSame( 'private', get_post_status( $other ) );

		$run = $this->start( 'tr_read' );
		$this->assertSame( 'failed', $run['status'], 'Nothing found: no record to trash.' );
		$this->assertStringStartsWith( 'pegwork_record_not_found', (string) $run['error'] );
	}

	/**
	 * Loop items are targets. 55 old volunteers: the first loop moves 50, each of the next 5 fails
	 * `trash_limit` (on_error continues, no retry), and 5 stay. Without on_error the run fails at the item
	 * past the cap. The filter lowers the cap and cannot raise it past 200.
	 */
	public function test_loop_target_and_the_per_run_cap() {
		$ids = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$ids[] = $this->volunteer( 'Old ' . $i, 'old' );
		}
		$kept = $this->volunteer( 'Kept', 'placed' );

		$run = $this->start( 'tr_loop' );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( 'completed_with_errors:remove_rest', $run['context']['outcome'] );
		$this->assertSame( 50, $run['context']['trashed'] );
		$this->assertSame( array_fill( 0, 50, 'trash' ), array_map( 'get_post_status', array_slice( $ids, 0, 50 ) ) );
		$this->assertSame( array_fill( 0, 5, 'private' ), array_map( 'get_post_status', array_slice( $ids, 50 ) ) );
		$this->assertSame( 'private', get_post_status( $kept ) );

		$rest = array_values( array_filter( $this->steps( $run['id'] ), fn( $row ) => 'remove_rest' === $row['step_id'] ) );
		$this->assertCount( 5, $rest, 'One attempt per item: trash_limit is not retried.' );
		foreach ( $rest as $row ) {
			$this->assertSame( 'failed', $row['status'] );
			$this->assertStringStartsWith( 'trash_limit', (string) $row['error'] );
		}
		$this->assertSame( '50 volunteers moved to the trash', tests_retrieve_phpmailer_instance()->mock_sent[0]['subject'] );

		// The next run moves the other 5.
		$run = $this->start( 'tr_loop' );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 5, $run['context']['trashed'] );
		$this->assertSame( array_fill( 0, 5, 'trash' ), array_map( 'get_post_status', array_slice( $ids, 50 ) ) );

		// Without on_error: a lower cap stops the run at the item past it.
		$more = array( $this->volunteer( 'Old A', 'old' ), $this->volunteer( 'Old B', 'old' ), $this->volunteer( 'Old C', 'old' ) );
		$cap  = fn() => 2;
		add_filter( 'pegwork_automation_trash_limit', $cap );
		$run  = $this->start( 'tr_loop' );
		remove_filter( 'pegwork_automation_trash_limit', $cap );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'trash_limit', (string) $run['error'] );
		$this->assertSame( array( 'trash', 'trash', 'private' ), array_map( 'get_post_status', $more ) );

		$this->assertSame( 50, TrashRecord::limit() );
		$big = fn() => 500;
		add_filter( 'pegwork_automation_trash_limit', $big );
		$this->assertSame( 200, TrashRecord::limit(), 'The filter cannot raise the cap past 200.' );
		remove_filter( 'pegwork_automation_trash_limit', $big );
	}

	/**
	 * The handler refuses a record of another type (`record_type`) and any schedule sweep run
	 * (`trash_in_sweep`, the runtime guard for a definition from a newer registry), neither retried, and
	 * the record stays.
	 */
	public function test_record_type_and_sweep_guard() {
		$gift    = Records::create( 'tr_gift', array( 'name' => 'Gift' ), 'private' );
		$sam     = $this->volunteer();
		$step    = array(
			'id'           => 'remove',
			'type'         => 'trash_record',
			'content_type' => 'tr_volunteer',
		);
		$run     = array(
			'id'           => 1,
			'automation'   => 'tr_bound',
			'depth'        => 0,
			'trigger_type' => 'manual',
			'record_id'    => $gift,
		);
		$handler = new TrashRecord();

		$result = $handler->run( $step, new Context( $this->clock, Records::get( $gift ) ), $run );
		$this->assertStringStartsWith( 'record_type:', (string) $result->error );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 'private', get_post_status( $gift ) );

		$sweep  = array(
			'trigger_type' => 'schedule',
			'record_id'    => $sam,
		) + $run;
		$result = $handler->run( $step, new Context( $this->clock, Records::get( $sam ) ), $sweep );
		$this->assertStringStartsWith( 'trash_in_sweep:', (string) $result->error );
		$this->assertFalse( $result->retryable );
		$this->assertSame( 'private', get_post_status( $sam ) );
	}

	/**
	 * The dry run lists each record it would move, counts toward the cap (the items past 50 would fail
	 * `trash_limit`), says later reads see the site as it is now, and changes nothing: no mail, HTTP or
	 * event, and run, step, post and meta rows as before.
	 */
	public function test_dry_run_changes_nothing() {
		$sam = $this->volunteer();
		for ( $i = 1; $i <= 52; $i++ ) {
			$this->volunteer( 'Old ' . $i, 'old' );
		}

		$calls = array(
			'mail'  => 0,
			'http'  => 0,
			'event' => 0,
		);
		add_filter(
			'pre_wp_mail',
			function ( $pre ) use ( &$calls ) {
				++$calls['mail'];
				return $pre;
			}
		);
		add_filter(
			'pre_http_request',
			function ( $pre ) use ( &$calls ) {
				++$calls['http'];
				return $pre;
			},
			1
		);
		add_action(
			'pegwork_event',
			function () use ( &$calls ) {
				++$calls['event'];
			}
		);
		$before = $this->state();

		$report = DryRun::report( 'tr_bound', $sam, null );
		$steps  = array_column( $report['steps'], null, 'id' );
		$this->assertSame( sprintf( 'Move to trash remove: Volunteer %d "Sam" (not applied).', $sam ), $steps['remove']['message'] );
		$this->assertTrue( $steps['remove']['detail']['changed'] );

		$report = DryRun::report( 'tr_loop', null, null );
		$this->assertSame( 'completed_with_errors:remove_rest', $report['outcome'] );
		$lines = DryRun::lines( $report );
		$this->assertCount( 3, array_filter( $lines, fn( $line ) => str_contains( $line, 'Move to trash remove: Volunteer ' ) && str_ends_with( $line, '(not applied).' ) ), 'The first three items in full.' );
		$fails = array_filter( $report['steps'], fn( $entry ) => 'remove_rest' === $entry['id'] && 'continues' === $entry['result'] );
		$this->assertCount( 10, $fails, 'The rest find sees the site as it is now; every item is past the cap.' );
		$this->assertStringContainsString( 'would fail: the run would already have moved 50 records to the trash', (string) array_values( $fails )[0]['message'] );
		$this->assertStringContainsString( 'Reads in a test see the site as it is now', implode( "\n", $lines ) );

		$this->assertSame( 0, array_sum( $calls ), (string) wp_json_encode( $calls ) );
		$this->assertSame( $before, $this->state() );
	}

	/**
	 * What a dry run must leave unchanged.
	 *
	 * @return array<string, mixed>
	 */
	private function state() {
		global $wpdb;
		$runs  = Schema::runs();
		$steps = Schema::steps();
		return array(
			'runs'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			'steps' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$steps}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'meta'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
			'trash' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s", 'trash' ) ),
			'sent'  => count( tests_retrieve_phpmailer_instance()->mock_sent ),
		);
	}
}
