<?php
/**
 * Integration tests: decisions and form binding in the engine (Phase 6).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Events;
use Pegwork\Api\Records;
use Pegwork\Plugin;
use Pegwork\Registry\Loader;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkAutomation\Engine;
use PegworkAutomation\Executor;
use PegworkAutomation\Manual;
use PegworkAutomation\Webhooks\Secrets;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests prepare step rows in the module's tables.

/**
 * The branching fixture with a frozen clock, the fake scheduler and the mock mailer: each path runs only its
 * steps; waits, retries, the time budget, stops and the step limit work inside branches; definition edits;
 * webhook delivery ids; form binding; writes to the bound record are read back in the same wake-up; the run
 * detail and `log --run` show the path.
 */
class AutomationBranchTest extends AutomationTestCase {

	/**
	 * Requests the test receiver got.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $received = array();

	/**
	 * What the receiver answers: an HTTP status, or a WP_Error.
	 *
	 * @var int|WP_Error
	 */
	private $answer = 200;

	/**
	 * Package directory: the branching fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/branching';
	}

	/**
	 * Resets the mailer and installs a webhook receiver (no real HTTP leaves the test).
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%pegwork\\_automation\\_hook\\_%'" );
		wp_cache_flush();
		reset_phpmailer_instance();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->received[] = array(
					'url'  => $url,
					'args' => $args,
					'body' => json_decode( (string) $args['body'], true ),
				);
				if ( $this->answer instanceof WP_Error ) {
					return $this->answer;
				}
				return array(
					'headers'  => array(),
					'body'     => 'ok',
					'response' => array(
						'code'    => $this->answer,
						'message' => get_status_header_desc( $this->answer ),
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Creates a help request (emits record.created).
	 *
	 * @param string $scenario Scenario: which br_* automation starts.
	 * @param array  $values   More values.
	 * @return int Record ID.
	 */
	private function request( $scenario, array $values = array() ) {
		$id = Records::create(
			'help_request',
			$values + array(
				'name'     => 'Ada ' . $scenario,
				'email'    => 'ada@example.org',
				'scenario' => $scenario,
			),
			'publish'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Step ids of a run's attempts, oldest first.
	 *
	 * @param int $run_id Run ID.
	 * @return string[]
	 */
	private function path( $run_id ) {
		return array_column( $this->steps( $run_id ), 'step_id' );
	}

	/**
	 * Recipients of the mails sent so far, in order.
	 *
	 * @return string[]
	 */
	private function recipients() {
		return array_map( fn( $mail ) => $mail['to'][0][0], tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * The run of an automation for a record.
	 *
	 * @param string $automation Automation key.
	 * @param int    $record     Record ID.
	 * @return array<string, mixed>
	 */
	private function run_for( $automation, $record ) {
		$runs = array_values( array_filter( $this->runs( $automation ), fn( $run ) => $record === $run['record_id'] ) );
		$this->assertCount( 1, $runs, "One $automation run for record $record." );
		return $runs[0];
	}

	/**
	 * A form event payload.
	 *
	 * @param string   $form   Form key.
	 * @param int|null $record Record ID the form wrote, or null.
	 * @param array    $values Values.
	 * @return array<string, mixed>
	 */
	private function form_payload( $form, $record, array $values = array() ) {
		return array(
			'form'      => $form,
			'entry_id'  => 11,
			'record_id' => $record,
			'values'    => $values,
		);
	}

	/**
	 * Each path runs only its steps: a branch, an empty branch, otherwise; then the run continues after the
	 * decision. The decision's summary names the path.
	 */
	public function test_each_path_runs_only_its_steps() {
		$high   = $this->request( 'paths', array( 'priority' => 'high' ) );
		$low    = $this->request( 'paths', array( 'priority' => 'low' ) );
		$normal = $this->request( 'paths', array( 'priority' => 'normal' ) );
		$this->request( 'other' );
		$this->drain();

		$this->assertCount( 3, $this->runs( 'br_paths' ), 'The trigger when picks the scenario.' );
		$runs = array(
			'high'   => $this->run_for( 'br_paths', $high ),
			'low'    => $this->run_for( 'br_paths', $low ),
			'normal' => $this->run_for( 'br_paths', $normal ),
		);
		$this->assertSame( array( 'route', 'notify_high', 'routed' ), $this->path( $runs['high']['id'] ) );
		$this->assertSame( array( 'route', 'routed' ), $this->path( $runs['low']['id'] ), 'An empty branch continues after the decision.' );
		$this->assertSame( array( 'route', 'notify_team', 'routed' ), $this->path( $runs['normal']['id'] ) );

		$summaries = array_map( fn( $run ) => $this->steps( $run['id'] )[0]['summary'], $runs );
		$this->assertSame(
			array(
				'high'   => array(
					'branch' => 'High',
					'index'  => 0,
				),
				'low'    => array(
					'branch' => 'Low',
					'index'  => 1,
				),
				'normal' => array(
					'branch' => 'otherwise',
					'index'  => -1,
				),
			),
			$summaries
		);
		foreach ( $runs as $run ) {
			$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
			$this->assertSame( 'routed', $this->value( $run['record_id'], 'notes' ) );
		}
		$this->assertSame( array( 'urgent@example.org', 'team@example.org' ), $this->recipients() );
	}

	/**
	 * Three nesting levels; a record created inside a branch is usable later in that branch; a write in a
	 * nested otherwise is read by the step after the outer decision in the same wake-up; no match at any level
	 * continues after the outer decision.
	 */
	public function test_nested_decisions_and_outputs_inside_a_branch() {
		$big   = $this->request( 'deep', array( 'amount' => 500 ) );
		$mid   = $this->request( 'deep', array( 'amount' => 50 ) );
		$small = $this->request( 'deep', array( 'amount' => 5 ) );
		$none  = $this->request( 'deep', array( 'amount' => 0 ) );
		$this->drain();

		$run = $this->run_for( 'br_deep', $big );
		$this->assertSame( array( 'level_1', 'level_2', 'level_3', 'make_task', 'task_mail', 'deep_done' ), $this->path( $run['id'] ) );
		$task = $this->run_row( $run['id'] )['context']['steps']['make_task']['record_id'];
		$this->assertSame( 'help_task', get_post_type( $task ) );
		$this->assertSame( 'draft', get_post_status( $task ), 'The normalized default status, filled inside the branch.' );
		$this->assertSame( $big, Records::get( $task )['values']['related'] );
		$this->assertSame( 'Task ' . $task, tests_retrieve_phpmailer_instance()->mock_sent[0]['subject'] );

		$this->assertSame( array( 'level_1', 'level_2', 'level_3', 'small_note', 'deep_done' ), $this->path( $this->run_for( 'br_deep', $mid )['id'] ) );
		$this->assertSame( array( 'level_1', 'level_2', 'deep_done' ), $this->path( $this->run_for( 'br_deep', $small )['id'] ) );
		$this->assertSame( array( 'level_1', 'deep_done' ), $this->path( $this->run_for( 'br_deep', $none )['id'] ) );

		$done = array_values( array_filter( tests_retrieve_phpmailer_instance()->mock_sent, fn( $mail ) => 'Done: Ada deep' === $mail['subject'] ) );
		$this->assertCount( 4, $done );
		$this->assertSame( 'Notes: over 10', trim( $done[1]['body'] ), 'The write inside the nested otherwise is read after the outer decision.' );
	}

	/**
	 * A wait inside a branch resumes at the next step inside it; a wait last in the branch resumes after the
	 * decision; the decision is not evaluated again on resume.
	 */
	public function test_waits_inside_a_branch() {
		$id = $this->request( 'wait' );
		$this->drain();
		$run = $this->only_run( 'br_wait' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'check', $run['current_step'] );

		$this->clock->advance( 'P1D' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'still_new', $run['current_step'], 'The wait inside the branch resumes at the next step inside it.' );
		$this->assertSame( '2026-11-05 09:00:00', $run['resume_at'] );
		$this->assertSame( array( 'team@example.org' ), $this->recipients() );

		$this->clock->advance( 'P2D' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'wrap', $run['current_step'], 'The wait last in the branch resumes after the decision.' );
		$this->assertSame( array( 'team@example.org', 'manager@example.org' ), $this->recipients() );

		$this->clock->advance( 'P1D' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'hold', 'check', 'remind', 'hold_2', 'still_new', 'escalate', 'hold_3', 'wrap' ), $this->path( $run['id'] ) );
		$this->assertSame( 1, count( array_keys( $this->path( $run['id'] ), 'check', true ) ), 'The decision ran once.' );
		$this->assertSame( 'checked', $this->value( $id, 'notes' ) );
	}

	/**
	 * A false condition inside a branch ends the whole run; a decision with no match and no otherwise continues
	 * after itself.
	 */
	public function test_stop_inside_a_branch_and_no_match() {
		$stopped   = $this->request( 'wait' );
		$contacted = $this->request( 'wait' );
		$this->drain();
		Records::update( $contacted, array( 'stage' => 'contacted' ) );

		$this->clock->advance( 'P1D' );
		$this->drain();
		$this->assertSame( array( 'hold', 'check', 'wrap' ), $this->path( $this->run_for( 'br_wait', $contacted )['id'] ) );
		$this->assertSame(
			array(
				'branch' => null,
				'index'  => null,
			),
			$this->steps( $this->run_for( 'br_wait', $contacted )['id'] )[1]['summary']
		);
		$this->assertSame( 'completed', $this->run_for( 'br_wait', $contacted )['status'] );

		Records::update( $stopped, array( 'stage' => 'contacted' ) );
		$this->clock->advance( 'P2D' );
		$this->drain();
		$run = $this->run_for( 'br_wait', $stopped );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:still_new', $run['context']['outcome'] );
		$this->assertSame( array( 'hold', 'check', 'remind', 'hold_2', 'still_new' ), $this->path( $run['id'] ) );
		$this->assertSame( '', $this->value( $stopped, 'notes' ), 'Nothing after the stop ran.' );
	}

	/**
	 * A retry inside a branch repeats the same step with the same delivery id, then continues after the decision.
	 */
	public function test_retry_inside_a_branch() {
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		$this->request( 'hook', array( 'priority' => 'high' ) );
		$this->drain();
		$run = $this->only_run( 'br_hook' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'hook_high', $run['current_step'] );
		$this->assertSame( '2026-11-02 09:05:00', $run['resume_at'] );

		$this->answer = 200;
		$this->clock->advance( 'PT5M' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'hook_route', 'hook_high', 'hook_high', 'hook_done' ), $this->path( $run['id'] ) );
		$this->assertSame( array( 'ok', 'retrying', 'ok', 'ok' ), array_column( $this->steps( $run['id'] ), 'status' ) );
		$this->assertSame( array( $run['id'] . '-hook_high', $run['id'] . '-hook_high' ), array_map( fn( $r ) => $r['args']['headers']['X-Pegwork-Delivery'], $this->received ) );
		$this->assertArrayNotHasKey( 'retries', $run['context'] );
	}

	/**
	 * Webhook delivery ids stay unique per step across branches; the secret command sees a branch's webhook.
	 */
	public function test_webhook_delivery_ids_and_secrets_in_branches() {
		$high  = $this->request( 'hook', array( 'priority' => 'high' ) );
		$other = $this->request( 'hook', array( 'priority' => 'low' ) );
		$this->drain();

		$high_run  = $this->run_for( 'br_hook', $high );
		$other_run = $this->run_for( 'br_hook', $other );
		$this->assertSame(
			array(
				'https://hooks.example.org/high'  => $high_run['id'] . '-hook_high',
				'https://hooks.example.org/other' => $other_run['id'] . '-hook_other',
			),
			array_column( array_map( fn( $r ) => array( $r['url'], $r['args']['headers']['X-Pegwork-Delivery'] ), $this->received ), 1, 0 )
		);
		$this->assertSame( array( 'name', 'priority' ), array_keys( $this->received[0]['body']['record']['fields'] ) );
		$this->assertSame( array( 'name' ), array_keys( $this->received[1]['body']['record']['fields'] ) );
		$this->assertTrue( Secrets::used_by( pegwork_registry()->automation( 'br_hook' ) ), 'A call_webhook inside a branch uses the secret.' );
		$this->assertFalse( Secrets::used_by( pegwork_registry()->automation( 'br_paths' ) ) );
	}

	/**
	 * A zero time budget runs one step per wake-up and takes the same path as an uninterrupted run.
	 */
	public function test_zero_time_budget_takes_the_same_path() {
		add_filter( 'pegwork_automation_time_budget', fn() => 0 );
		$id = $this->request( 'deep', array( 'amount' => 500 ) );
		$this->drain();

		$run = $this->only_run( 'br_deep' );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'level_1', 'level_2', 'level_3', 'make_task', 'task_mail', 'deep_done' ), $this->path( $run['id'] ) );
		$wakeups = array_filter( $this->scheduler->fired, fn( $job ) => array( $run['id'] ) === $job[1] );
		$this->assertCount( 6, $wakeups, 'One step per wake-up; each re-queue names the next step in the tree.' );
		$tasks = get_posts(
			array(
				'post_type'   => 'help_task',
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $tasks, 'The task was created once.' );
		$this->assertSame( $id, Records::get( $tasks[0]->ID )['values']['related'] );
	}

	/**
	 * The step limit counts attempts inside branches, decisions included.
	 */
	public function test_step_limit_inside_a_branch() {
		$runs = Engine::instance()->runs();
		$id   = $runs->insert(
			array(
				'automation'      => 'br_paths',
				'trigger_type'    => 'event',
				'status'          => 'queued',
				'record_id'       => $this->request( 'none', array( 'priority' => 'high' ) ),
				'depth'           => 0,
				'definition_hash' => '',
				'current_step'    => 'notify_high',
				'context'         => array(),
			)
		);
		for ( $i = 0; $i < Executor::MAX_STEPS; $i++ ) {
			$runs->add_step(
				array(
					'run_id'      => $id,
					'step_id'     => 'route',
					'step_type'   => 'decision',
					'attempt'     => $i + 1,
					'status'      => 'ok',
					'started_at'  => $runs->stamp(),
					'duration_ms' => 0,
					'summary'     => array(),
					'error'       => null,
				)
			);
		}

		$this->assertSame( 'failed', Engine::instance()->executor()->execute( $id ) );
		$this->assertStringStartsWith( 'step_limit:', $this->run_row( $id )['error'] );
		$this->assertSame( array(), $this->recipients() );
	}

	/**
	 * A waiting step that was removed fails the run; a step that moved into a branch continues at its new
	 * position and then follows the new tree.
	 */
	public function test_removed_and_moved_steps() {
		$runs    = Engine::instance()->runs();
		$removed = $runs->insert(
			array(
				'automation'      => 'br_wait',
				'trigger_type'    => 'event',
				'status'          => 'queued',
				'record_id'       => $this->request( 'none' ),
				'depth'           => 0,
				'definition_hash' => '',
				'current_step'    => 'gone',
				'context'         => array(),
			)
		);
		$this->assertSame( 'failed', Engine::instance()->executor()->execute( $removed ) );
		$this->assertSame( 'definition_changed: step "gone" is no longer in the automation.', $this->run_row( $removed )['error'] );

		$mail    = fn( $step ) => array(
			'id'      => $step,
			'type'    => 'send_email',
			'to'      => $step . '@example.org',
			'subject' => $step,
			'text'    => $step,
		);
		$trigger = array(
			'type'         => 'event',
			'event'        => 'record.created',
			'content_type' => 'help_request',
			'when'         => array(
				'var'   => 'record.scenario',
				'op'    => 'eq',
				'value' => 'moved',
			),
		);
		$before  = array(
			'format'  => 1,
			'key'     => 'br_moved',
			'label'   => 'Moved steps',
			'trigger' => $trigger,
			'steps'   => array(
				array(
					'id'   => 'pause',
					'type' => 'wait',
					'for'  => 'P1D',
				),
				$mail( 'later' ),
				$mail( 'last' ),
			),
		);
		$after   = $before;

		// The mail moves into a branch of a new decision, after a new step.
		$after['steps'] = array(
			array(
				'id'   => 'pause',
				'type' => 'wait',
				'for'  => 'P1D',
			),
			array(
				'id'       => 'gate',
				'type'     => 'decision',
				'branches' => array(
					array(
						'label' => 'Always',
						'when'  => array(
							'var' => 'record.id',
							'op'  => 'not_empty',
						),
						'steps' => array( $mail( 'early' ), $mail( 'later' ), $mail( 'inside' ) ),
					),
				),
			),
			$mail( 'last' ),
		);

		update_option( Loader::DB_OPTION, array( 'automations' => array( $before ) ) );
		Plugin::init();
		$this->assertNotNull( pegwork_registry()->automation( 'br_moved' ) );
		$this->request( 'moved' );
		$this->drain();
		$run = $this->only_run( 'br_moved' );
		$this->assertSame( 'later', $run['current_step'] );

		update_option( Loader::DB_OPTION, array( 'automations' => array( $after ) ) );
		Plugin::init();
		$this->assertSame( array(), Plugin::result()->errors );
		$this->clock->advance( 'P1D' );
		$this->drain();

		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'pause', 'later', 'inside', 'last' ), $this->path( $run['id'] ), 'The decision before it is not evaluated: the id fixes the path.' );
		$this->assertSame( array( 'later@example.org', 'inside@example.org', 'last@example.org' ), $this->recipients() );
	}

	/**
	 * A run waiting in a Phase 4 shaped (flat) definition resumes as before, and the flat automation runs
	 * the same way end to end.
	 */
	public function test_phase_4_shaped_waiting_run_resumes() {
		$record  = $this->request( 'none' );
		$runs    = Engine::instance()->runs();
		$context = array(
			'event'   => 'record.created',
			'trigger' => array(
				'id'   => $record,
				'type' => 'help_request',
			),
		);
		$id      = $runs->insert(
			array(
				'automation'      => 'br_flat',
				'trigger_type'    => 'event',
				'status'          => 'waiting',
				'record_id'       => $record,
				'depth'           => 0,
				'definition_hash' => '',
				'current_step'    => 'second',
				'context'         => $context,
			)
		);
		$runs->wait( $id, 'second', new \DateTimeImmutable( '2020-01-01 09:00:00', new \DateTimeZone( 'UTC' ) ), $context );
		$this->assertSame( 'completed', Engine::instance()->executor()->execute( $id ) );
		$this->assertSame( array( 'second' ), $this->path( $id ) );

		$this->request( 'flat' );
		$this->drain();
		$this->clock->advance( 'P1D' );
		$this->drain();
		$flat = array_values( array_filter( $this->runs( 'br_flat' ), fn( $run ) => $run['id'] !== $id ) )[0];
		$this->assertSame( 'completed', $flat['status'] );
		$this->assertSame( array( 'first', 'pause', 'second' ), $this->path( $flat['id'] ) );
	}

	/**
	 * Form binding: form.submitted of a form without confirm and form.confirmed bind the record the form wrote;
	 * an entry-only form runs unbound; a binding event without a record is skipped; run --record checks the type.
	 */
	public function test_form_binding() {
		$lead = $this->request( 'none', array( 'priority' => 'high' ) );
		$team = $this->request( 'none' );
		Events::emit( 'form.submitted', $this->form_payload( 'help_signup', $lead, array( 'priority' => 'high' ) ) );
		Events::emit( 'form.submitted', $this->form_payload( 'help_signup', $team, array( 'priority' => 'normal' ) ) );
		$this->drain();

		$run = $this->run_for( 'br_signup', $lead );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'assign', 'to_lead', 'notify' ), $this->path( $run['id'] ) );
		$this->assertSame( array( 'assign', 'to_team', 'notify' ), $this->path( $this->run_for( 'br_signup', $team )['id'] ) );
		$this->assertSame( array( 'lead@example.org', 'team@example.org' ), $this->recipients() );
		$this->assertStringContainsString( 'Reply-To: ada@example.org', tests_retrieve_phpmailer_instance()->mock_sent[0]['header'] );
		$this->assertStringContainsString( 'Ada none (high): ' . admin_url( 'post.php?post=' . $lead . '&action=edit' ), tests_retrieve_phpmailer_instance()->mock_sent[0]['body'] );

		// Confirmed: bound. The confirm form's form.submitted starts nothing here (br_confirmed listens on confirmed).
		$confirmed = $this->request( 'none', array( 'name' => 'Grace' ) );
		Events::emit( 'form.submitted', $this->form_payload( 'help_confirm', null ) );
		Events::emit( 'form.confirmed', $this->form_payload( 'help_confirm', $confirmed ) );
		$this->drain();
		$this->assertSame( 'completed', $this->only_run( 'br_confirmed' )['status'] );
		$this->assertSame( $confirmed, $this->only_run( 'br_confirmed' )['record_id'] );
		$this->assertSame( 'confirmed by Grace', $this->value( $confirmed, 'notes' ) );

		// Entry-only form: unbound, runs on the payload.
		Events::emit( 'form.submitted', $this->form_payload( 'help_note', null, array( 'message' => 'Hello there' ) ) );
		$this->drain();
		$note = $this->only_run( 'br_note' );
		$this->assertNull( $note['record_id'] );
		$this->assertSame( 'completed', $note['status'] );
		$this->assertSame( 'Hello there', trim( end( tests_retrieve_phpmailer_instance()->mock_sent )['body'] ) );

		// A binding form event without a record is logged as skipped.
		$ids = Engine::instance()->triggers()->handle( 'form.submitted', $this->form_payload( 'help_signup', null ) );
		$this->assertCount( 1, $ids );
		$skipped = $this->run_row( $ids[0] );
		$this->assertSame( 'skipped', $skipped['status'] );
		$this->assertStringStartsWith( 'no_record:', $skipped['error'] );
		$this->assertNull( $skipped['record_id'] );

		// run --record: a form-bound automation runs on its form's target type.
		$task  = Records::create( 'help_task', array( 'name' => 'Task' ), 'publish' );
		$wrong = ( new Manual() )->run( 'br_signup', $task );
		$this->assertInstanceOf( WP_Error::class, $wrong );
		$this->assertSame( 'pegwork_record_type', $wrong->get_error_code() );
		$this->assertStringContainsString( 'runs on help_request', $wrong->get_error_message() );
		$this->assertCount( 1, ( new Manual() )->run( 'br_signup', $lead )['runs'] );
	}

	/**
	 * After a write to the bound record, later steps in the same wake-up read the written values.
	 */
	public function test_write_to_bound_record_is_read_in_the_same_wakeup() {
		$id = $this->request( 'none', array( 'priority' => 'high' ) );
		Events::emit( 'form.submitted', $this->form_payload( 'help_signup', $id, array( 'priority' => 'high' ) ) );
		$this->assertSame( 1, $this->drain(), 'One wake-up runs the whole flow.' );

		$run = $this->only_run( 'br_signup' );
		$this->assertSame( array( 'assign', 'to_lead', 'notify' ), $this->path( $run['id'] ) );
		$this->assertSame( 'lead@example.org', $this->value( $id, 'coordinator' ) );
		$this->assertSame( array( 'lead@example.org' ), $this->recipients(), 'notify read the coordinator to_lead wrote.' );
	}

	/**
	 * The run detail indents rows by depth and labels each branch; the decision summary names the path; rows of
	 * removed steps show flat. `log --run` indents step ids in table output only. Flat runs look as before.
	 */
	public function test_run_detail_and_cli_log_show_the_path() {
		$this->request( 'wait' );
		$this->drain();
		foreach ( array( 'P1D', 'P2D', 'P1D' ) as $wait ) {
			$this->clock->advance( $wait );
			$this->drain();
		}
		$run = $this->only_run( 'br_wait' );
		Engine::instance()->runs()->add_step(
			array(
				'run_id'      => $run['id'],
				'step_id'     => 'retired',
				'step_type'   => 'send_email',
				'attempt'     => 1,
				'status'      => 'ok',
				'started_at'  => Engine::instance()->runs()->stamp(),
				'duration_ms' => 0,
				'summary'     => array(),
				'error'       => null,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'view' => 'run',
			'run'  => (string) $run['id'],
		);
		ob_start();
		try {
			Screen::render();
		} finally {
			$html = ob_get_clean();
		}

		$this->assertStringContainsString( '<th scope="row"><code>hold</code><span class="pegwork-meta">wait</span></th>', $html, 'Top-level rows are unchanged.' );
		$this->assertStringContainsString( '<td>Took branch <strong>Not contacted yet</strong></td>', $html );
		$this->assertStringContainsString( '<tr class="pegwork-branch-row"><td colspan="7" class="pegwork-depth-0">Branch <strong>Not contacted yet</strong> of <code>check</code></td></tr><tr><th scope="row" class="pegwork-depth-1"><code>remind</code>', $html );
		$this->assertSame( 1, substr_count( $html, 'pegwork-branch-row' ), 'One label: the branch is entered once.' );
		$this->assertStringContainsString( '<th scope="row" class="pegwork-depth-1"><code>still_new</code>', $html );
		$this->assertStringContainsString( '<th scope="row"><code>wrap</code>', $html );
		$this->assertStringContainsString( '<code>retired</code><span class="pegwork-meta">send_email</span><span class="pegwork-meta">(no longer in this automation)</span>', $html );

		$rows = AutomationCommand::step_rows( $run, $this->steps( $run['id'] ), true );
		$this->assertSame( array( 'hold', 'check', '  remind', '  hold_2', '  still_new', '  escalate', '  hold_3', 'wrap', 'retired (no longer in this automation)' ), array_column( $rows, 'step' ) );
		$this->assertSame( '{"branch":"Not contacted yet","index":0}', $rows[1]['summary'] );
		$this->assertSame( $this->path( $run['id'] ), array_column( AutomationCommand::step_rows( $run, $this->steps( $run['id'] ), false ), 'step' ) );
	}
}
