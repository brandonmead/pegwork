<?php
/**
 * Integration tests: the automation engine with a frozen clock and the fake scheduler.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Schema;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Tests age rows in the module's tables and fire its (prefixed) hook constants.

/**
 * Event triggers, the executor (claim, budget, steps), the depth cap, overrides and maintenance.
 */
class AutomationEngineTest extends AutomationTestCase {

	/**
	 * Publishes a job over REST as the editor.
	 *
	 * @param int $id Post ID.
	 */
	private function publish_via_rest( $id ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/job/' . $id );
		$request->set_body_params( array( 'status' => 'publish' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Acceptance: pending to publish sets Last verified through a queued run, logged as completed.
	 */
	public function test_approval_sets_last_verified_and_logs_a_completed_run() {
		$id = $this->job( 'pending' );
		$this->publish_via_rest( $id );

		$run = $this->only_run( 'job_approved' );
		$this->assertSame( 'queued', $run['status'] );
		$this->assertSame( $id, $run['record_id'] );
		$this->assertSame( 0, $run['depth'] );
		$this->assertSame( 'record.status_changed', $run['context']['event'] );
		$this->assertNotNull( $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ), 'The run is scheduled.' );
		$this->assertSame( '', $this->value( $id, 'last_verified' ), 'The saving request runs no steps.' );

		$this->drain();

		$this->assertSame( '2026-11-02', $this->value( $id, 'last_verified' ) );
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertNotNull( $run['finished_at'] );
		$steps = $this->steps( $run['id'] );
		$this->assertCount( 2, $steps );
		$this->assertSame( array( 'verify', 'update_record', 'ok' ), array( $steps[0]['step_id'], $steps[0]['step_type'], $steps[0]['status'] ) );
		$this->assertSame( array( 'notify', 'send_email', 'ok' ), array( $steps[1]['step_id'], $steps[1]['step_type'], $steps[1]['status'] ) );
		$this->assertSame(
			array(
				'record_id' => $id,
				'keys'      => array( 'last_verified' ),
			),
			$steps[0]['summary']
		);

		// The stamp is a write at depth 1, which job_verified picks up.
		$verified = $this->only_run( 'job_verified' );
		$this->assertSame( 1, $verified['depth'] );
		$this->assertSame( 'completed', $verified['status'] );
		$this->assertSame( "$id:2026-11-02", $verified['idempotency_key'] );
		$this->assertSame( 'verified 2026-11-02', $this->value( $id, 'note' ) );
	}

	/**
	 * Only pending to publish matches the approval trigger's `when`.
	 */
	public function test_other_status_changes_do_not_start_the_approval() {
		$id = $this->job( 'draft' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array(), $this->runs( 'job_approved' ) );
	}

	/**
	 * Acceptance: a duplicate execution yields one run (the claim is atomic), also when it arrives mid-run.
	 */
	public function test_duplicate_execution_runs_the_steps_once() {
		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$run = $this->only_run( 'job_approved' );

		$reentrant = null;
		add_action(
			'pegwork_event_record_updated',
			function () use ( $run, &$reentrant ) {
				if ( null === $reentrant ) {
					$reentrant = Engine::instance()->executor()->execute( $run['id'] );
				}
			}
		);

		$this->assertSame( 'completed', Engine::instance()->executor()->execute( $run['id'] ) );
		$this->assertNull( $reentrant, 'A wake-up during the run is not claimed.' );
		$this->assertNull( Engine::instance()->executor()->execute( $run['id'] ), 'A wake-up after the run is not claimed.' );
		do_action( Engine::RUN_HOOK, $run['id'] );

		$this->assertCount( 2, $this->steps( $run['id'] ), 'Each step ran once.' );
		$this->assertCount( 1, $this->runs( 'job_approved' ) );
	}

	/**
	 * The idempotency key allows one run per rendered value.
	 */
	public function test_idempotency_key_creates_one_run_per_value() {
		$id = $this->job( 'draft' );
		Records::update( $id, array( 'last_verified' => '2026-01-01' ) );
		Records::update( $id, array( 'last_verified' => '2026-02-01' ) );
		Records::update( $id, array( 'last_verified' => '2026-01-01' ) );

		$runs = $this->runs( 'job_verified' );
		$this->assertSame( array( "$id:2026-01-01", "$id:2026-02-01" ), array_column( $runs, 'idempotency_key' ) );
		foreach ( $runs as $run ) {
			$this->assertNotNull( $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) );
		}
	}

	/**
	 * Acceptance: a self-triggering automation stops at depth 3 with a skipped, logged run and no steps.
	 */
	public function test_self_triggering_automation_stops_at_depth_three() {
		$id = $this->job( 'draft' );
		Records::update( $id, array( 'marker' => 'start' ) );
		$this->drain();

		$runs = $this->runs( 'job_loop' );
		$this->assertSame( array( 0, 1, 2, 3 ), array_column( $runs, 'depth' ) );
		$this->assertSame( array( 'completed', 'completed', 'completed', 'skipped' ), array_column( $runs, 'status' ) );
		$this->assertStringStartsWith( 'loop_depth:', $runs[3]['error'] );
		$this->assertSame( array(), $this->steps( $runs[3]['id'] ) );
		$this->assertSame( 'loop-' . $runs[2]['id'], $this->value( $id, 'marker' ) );
		$this->assertSame( array(), array_filter( $this->scheduler->jobs, fn( $job ) => Engine::RUN_HOOK === $job['hook'] ), 'Nothing left to run.' );
	}

	/**
	 * The depth cap is filterable.
	 */
	public function test_depth_cap_filter() {
		add_filter( 'pegwork_automation_max_depth', fn() => 1 );
		$id = $this->job( 'draft' );
		Records::update( $id, array( 'marker' => 'start' ) );
		$this->drain();

		$this->assertSame( array( 'completed', 'skipped' ), array_column( $this->runs( 'job_loop' ), 'status' ) );
	}

	/**
	 * The create_record output feeds a later step; a false condition completes the run with stopped_at.
	 */
	public function test_create_record_and_condition() {
		$parent = Records::create(
			'job',
			array(
				'title'  => 'Spawn',
				'note'   => 'secret-note',
				'urgent' => true,
			)
		);
		$this->drain();

		$runs = $this->runs( 'job_spawn' );
		$this->assertCount( 2, $runs, 'The parent run, then the child created by it.' );
		$this->assertSame( 'completed', $runs[0]['status'] );
		$this->assertArrayNotHasKey( 'outcome', $runs[0]['context'] );

		$child = (int) $runs[0]['context']['steps']['child']['record_id'];
		$this->assertSame( $child, $runs[1]['record_id'] );
		$this->assertSame( (float) $child, (float) $this->value( $parent, 'child_id' ) );
		$this->assertSame( 'Child of ' . $parent, $this->value( $child, 'title' ) );
		$this->assertSame( 'secret-note', $this->value( $child, 'note' ) );
		$this->assertSame( array( 'open' ), $this->value( $child, 'job_stage' ) );
		$this->assertTrue( $this->value( $child, 'urgent' ) );
		$this->assertSame( 'draft', get_post_status( $child ) );

		$this->assertSame( 'completed', $runs[1]['status'] );
		$this->assertSame( 'stopped_at:gate', $runs[1]['context']['outcome'] );
		$this->assertSame( array( 'passed' => false ), $this->steps( $runs[1]['id'] )[0]['summary'] );

		// Step summaries name keys, never values (the note is private).
		$this->assertStringNotContainsString( 'secret-note', wp_json_encode( array_column( $this->steps( $runs[0]['id'] ), 'summary' ) ) );
	}

	/**
	 * A step type the engine has no handler for (a definition newer than the module) fails the run before
	 * its first step, so no earlier step acts on part of the flow. The test removes a handler.
	 */
	public function test_unsupported_step_fails_the_run() {
		$handlers = new \ReflectionProperty( Engine::class, 'steps' );
		$steps    = $handlers->getValue( Engine::instance() );
		unset( $steps['call_webhook'] );
		$handlers->setValue( Engine::instance(), $steps );

		$id = $this->job( 'publish' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
			)
		);
		$this->drain();

		$run = $this->only_run( 'job_private' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'unsupported_step: step "', $run['error'] );
		$this->assertStringContainsString( '"call_webhook"', $run['error'] );
		$this->assertSame( array(), $this->steps( $run['id'] ), 'No step ran.' );
		$this->assertNotSame( array( 'closed' ), $this->value( $id, 'job_stage' ), 'The first step did not close the job.' );
	}

	/**
	 * A record deleted for good before the run wakes stops the run before any step (QA E3): completed,
	 * outcome `no_record`, no step rows.
	 */
	public function test_missing_record_stops_the_run() {
		$id     = $this->job( 'draft' );
		$run_id = Engine::instance()->runs()->insert(
			array(
				'automation'      => 'job_approved',
				'trigger_type'    => 'event',
				'record_id'       => $id,
				'status'          => 'queued',
				'depth'           => 0,
				'definition_hash' => '',
				'context'         => array(),
			)
		);
		wp_delete_post( $id, true );

		$this->assertSame( 'completed', Engine::instance()->executor()->execute( $run_id ) );
		$this->assertSame( 'no_record', $this->run_row( $run_id )['context']['outcome'] );
		$this->assertNull( $this->run_row( $run_id )['error'] );
		$this->assertSame( array(), $this->steps( $run_id ) );
	}

	/**
	 * Acceptance: a disabled automation does nothing; the site override wins over JSON both ways.
	 */
	public function test_disabled_automation_does_nothing_and_overrides_win() {
		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( array(), $this->runs( 'job_disabled' ), 'enabled: false in JSON.' );

		Overrides::set( 'job_disabled', 'on' );
		Overrides::set( 'job_approved', 'off' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'pending',
			)
		);
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		$this->drain();

		$this->assertSame( array(), $this->runs( 'job_approved' ) );
		$this->assertSame( '', $this->value( $id, 'last_verified' ) );
		$this->assertSame( array( 'completed', 'completed' ), array_column( $this->runs( 'job_disabled' ), 'status' ) );
		$this->assertSame( 'disabled ran', $this->value( $id, 'note' ) );
	}

	/**
	 * A run queued before its automation was disabled is cancelled when it wakes, without steps.
	 */
	public function test_run_queued_before_disable_is_cancelled() {
		$id = $this->job( 'pending' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		Overrides::set( 'job_approved', 'off' );
		$this->drain();

		$run = $this->only_run( 'job_approved' );
		$this->assertSame( 'cancelled', $run['status'] );
		$this->assertStringStartsWith( 'automation_disabled:', $run['error'] );
		$this->assertSame( array(), $this->steps( $run['id'] ) );
		$this->assertSame( '', $this->value( $id, 'last_verified' ) );
	}

	/**
	 * Runs of a removed automation are cancelled; a removed current step fails the run.
	 */
	public function test_removed_automation_and_changed_definition() {
		$runs = Engine::instance()->runs();
		$base = array(
			'trigger_type'    => 'event',
			'status'          => 'queued',
			'depth'           => 0,
			'definition_hash' => '',
			'context'         => array(),
		);

		$gone = $runs->insert( $base + array( 'automation' => 'gone' ) );
		$this->assertSame( 'cancelled', Engine::instance()->executor()->execute( $gone ) );
		$this->assertStringStartsWith( 'automation_removed:', $this->run_row( $gone )['error'] );

		$moved = $runs->insert(
			$base + array(
				'automation'   => 'job_approved',
				'current_step' => 'renamed',
				'record_id'    => $this->job( 'publish' ),
			)
		);
		$this->assertSame( 'failed', Engine::instance()->executor()->execute( $moved ) );
		$this->assertStringStartsWith( 'definition_changed:', $this->run_row( $moved )['error'] );
	}

	/**
	 * A zero time budget runs one step per wake-up and re-queues the rest.
	 */
	public function test_time_budget_requeues_between_steps() {
		add_filter( 'pegwork_automation_time_budget', fn() => 0 );
		Records::create( 'job', array( 'title' => 'Spawn' ) );
		$this->drain();

		$parent = $this->runs( 'job_spawn' )[0];
		$this->assertSame( 'completed', $parent['status'] );
		$this->assertSame( array( 'gate', 'child', 'link' ), array_column( $this->steps( $parent['id'] ), 'step_id' ) );
		$wakeups = array_filter( $this->scheduler->fired, fn( $job ) => array( $parent['id'] ) === $job[1] );
		$this->assertCount( 3, $wakeups );
	}

	/**
	 * Acceptance: maintenance resumes runs whose wake-up was lost, fails stuck runs, and reschedules itself.
	 */
	public function test_maintenance_resumes_orphaned_runs() {
		global $wpdb;
		$runs = Engine::instance()->runs();
		$id   = $this->job( 'publish' );
		$base = array(
			'automation'      => 'job_approved',
			'trigger_type'    => 'event',
			'record_id'       => $id,
			'depth'           => 0,
			'definition_hash' => '',
			'context'         => array(),
		);

		// Queued, then the wake-up was lost (never scheduled).
		$orphan = $runs->insert( $base + array( 'status' => 'queued' ) );
		$recent = $runs->insert( $base + array( 'status' => 'queued' ) );
		$this->scheduler->schedule( Engine::RUN_HOOK, array( $recent ), $this->clock->now()->getTimestamp() + 3600 );
		$waiting = $runs->insert( $base + array( 'status' => 'queued' ) );
		$wpdb->update(
			Schema::runs(),
			array(
				'status'    => 'waiting',
				'resume_at' => '2026-11-02 10:00:00',
			),
			array( 'id' => $waiting )
		);
		$stuck = $runs->insert( $base + array( 'status' => 'queued' ) );
		$this->assertTrue( $runs->claim( $stuck ) );

		$this->clock->advance( 'PT1H' );
		$result = Engine::instance()->maintenance()->run();

		$this->assertSame( 2, $result['resumed'], 'The orphan and the due wait; the one with a pending wake-up is left alone.' );
		$this->assertSame( 1, $result['interrupted'] );
		$this->assertSame( 'failed', $this->run_row( $stuck )['status'] );
		$this->assertStringStartsWith( 'interrupted:', $this->run_row( $stuck )['error'] );

		$this->scheduler->unschedule( Engine::RUN_HOOK, array( $recent ) );
		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $orphan )['status'] );
		$this->assertSame( 'completed', $this->run_row( $waiting )['status'] );
		$this->assertSame( '2026-11-02', $this->value( $id, 'last_verified' ) );
		$this->assertSame( 'queued', $this->run_row( $recent )['status'] );

		$this->assertSame( strtotime( '2026-11-03 03:00:00 UTC' ), $this->scheduler->next( Engine::MAINTENANCE_HOOK, array() ) );
	}

	/**
	 * Boot schedules the daily maintenance job once, at the next 03:00 site time.
	 */
	public function test_boot_schedules_maintenance_in_site_time() {
		update_option( 'timezone_string', 'America/Chicago' );
		$this->boot();
		// 2026-11-02 09:00 UTC is 03:00 in Chicago (CST, after the 2026-11-01 DST change): the next one is tomorrow.
		$this->assertSame( strtotime( '2026-11-03 09:00:00 UTC' ), $this->scheduler->next( Engine::MAINTENANCE_HOOK, array() ) );
	}

	/**
	 * Retention prunes finished runs (and steps) after 90 days, failed after 180; open runs never.
	 */
	public function test_retention_prunes_old_runs_only() {
		global $wpdb;
		$runs = Engine::instance()->runs();
		$make = function ( $status, $days ) use ( $runs, $wpdb ) {
			$id = $runs->insert(
				array(
					'automation'      => 'job_approved',
					'trigger_type'    => 'event',
					'status'          => $status,
					'depth'           => 0,
					'definition_hash' => '',
					'context'         => array(),
				)
			);
			$runs->add_step(
				array(
					'run_id'      => $id,
					'step_id'     => 'verify',
					'step_type'   => 'update_record',
					'status'      => 'ok',
					'started_at'  => '2026-01-01 00:00:00',
					'duration_ms' => 1,
				)
			);
			$old = gmdate( 'Y-m-d H:i:s', strtotime( "2026-11-02 09:00:00 UTC -{$days} days" ) );
			$wpdb->update(
				Schema::runs(),
				array(
					'created_at'  => $old,
					'finished_at' => 'queued' === $status ? null : $old,
				),
				array( 'id' => $id )
			);
			return $id;
		};

		$keep = array( $make( 'completed', 89 ), $make( 'failed', 91 ), $make( 'queued', 400 ) );
		$drop = array( $make( 'completed', 91 ), $make( 'skipped', 100 ), $make( 'cancelled', 91 ), $make( 'failed', 181 ) );

		$this->assertSame( 4, Engine::instance()->maintenance()->run()['pruned'] );
		foreach ( $keep as $id ) {
			$this->assertNotNull( $this->run_row( $id ) );
		}
		foreach ( $drop as $id ) {
			$this->assertNull( $this->run_row( $id ) );
			$this->assertSame( array(), $this->steps( $id ) );
		}

		update_option( 'pegwork_automation_retention_days', array( 'done' => 30 ) );
		$this->assertSame( 1, Engine::instance()->maintenance()->run()['pruned'] );
	}

	/**
	 * The tables exist with the design's columns, and a matching version skips dbDelta.
	 */
	public function test_tables_are_installed_and_versioned() {
		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::runs() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		$this->assertSame(
			array( 'id', 'automation', 'trigger_type', 'record_id', 'status', 'current_step', 'resume_at', 'depth', 'idempotency_key', 'definition_hash', 'context', 'error', 'created_at', 'claimed_at', 'started_at', 'finished_at' ),
			$columns
		);
		// Order-free: a fresh install puts `iteration` (Schema 2) after `attempt`, an upgrade from 1 appends it.
		$this->assertEqualsCanonicalizing(
			array( 'id', 'run_id', 'step_id', 'step_type', 'attempt', 'iteration', 'status', 'started_at', 'duration_ms', 'summary', 'error' ),
			$wpdb->get_col( 'DESCRIBE ' . Schema::steps() ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		);
		$this->assertSame( '2', Schema::VERSION );
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
		$this->assertFalse( Schema::maybe_install() );
	}

	/**
	 * An oversized trigger payload is reduced to its scalar keys and origin.
	 */
	public function test_oversized_trigger_payload_is_truncated() {
		$id = Engine::instance()->runs()->insert(
			array(
				'automation'      => 'job_approved',
				'trigger_type'    => 'event',
				'status'          => 'skipped',
				'depth'           => 0,
				'definition_hash' => '',
				'context'         => array(
					'trigger' => array(
						'id'     => 7,
						'old'    => array( 'note' => str_repeat( 'x', 20000 ) ),
						'origin' => array( 'depth' => 0 ),
					),
				),
			)
		);
		$this->assertSame(
			array(
				'_truncated' => true,
				'id'         => 7,
				'origin'     => array( 'depth' => 0 ),
			),
			$this->run_row( $id )['context']['trigger']
		);
	}
}
