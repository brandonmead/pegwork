<?php
/**
 * Integration tests: reads, relate, on_error and the new templates at run time (Automation v3 PR 3).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Format;
use PegworkAutomation\Context;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Plan;
use PegworkAutomation\Schema;
use PegworkAutomation\Templates;
use PegworkAutomation\TemplateTooLarge;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests count rows in the module's tables.

/**
 * The engine_reads fixture: read outputs are references that resolve fresh after a wait and after a
 * write, relate links with events at depth 1, `call_webhook.record` sends public fields only,
 * `on_error: continue` gives `completed_with_errors`, the read limit fails a read, an empty operand
 * matches nothing, the template filters and `{{#each}}` render, and a dry run reads for real with no
 * side effect.
 */
class AutomationReadsTest extends AutomationTestCase {

	/**
	 * Requests the test receiver got: url and decoded body.
	 *
	 * @var array<int, array{url: string, body: array<string, mixed>}>
	 */
	private $requests = array();

	/**
	 * Package directory: the engine_reads fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/engine-reads';
	}

	/**
	 * A fresh mailer and a receiver that answers 200 to every outbound request.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'body' => (array) json_decode( (string) $args['body'], true ),
				);
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * A contact.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return int
	 */
	private function contact( $name, $email ) {
		$id = Records::create(
			'barn_contact',
			array(
				'name'  => $name,
				'email' => $email,
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A gift (emits record.created, which starts er_gift).
	 *
	 * @param string $name   Name.
	 * @param int    $amount Amount.
	 * @param int[]  $donors Contact IDs.
	 * @param string $status Status.
	 * @return int
	 */
	private function gift( $name, $amount, array $donors = array(), $status = 'private' ) {
		$id = Records::create(
			'barn_gift',
			array(
				'name'        => $name,
				'amount'      => $amount,
				'received_on' => '2026-11-01',
				'method'      => 'card',
				'barn_fund'   => array( 'building' ),
				'gift_donor'  => $donors,
			),
			$status
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * IDs of the records of a type linked to a record through a relationship.
	 *
	 * @param string $type         Content type read.
	 * @param string $relationship Relationship key.
	 * @param int    $of           Record at the other end.
	 * @return int[]
	 */
	private function linked( $type, $relationship, $of ) {
		$query = array(
			'related' => array(
				'relationship' => $relationship,
				'of'           => $of,
			),
		);
		return Records::find( $type, $query )['ids'];
	}

	/**
	 * Sent mail.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function mail() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * Step rows of a run by step ID (the last attempt).
	 *
	 * @param int $id Run ID.
	 * @return array<string, array<string, mixed>>
	 */
	private function rows( $id ) {
		return array_column( $this->steps( $id ), null, 'step_id' );
	}

	/**
	 * Starts a webhook run of an automation and runs it.
	 *
	 * @param string               $key     Automation key.
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed> The run afterwards.
	 */
	private function webhook( $key, array $payload ) {
		$id = Engine::instance()->triggers()->webhook( $key, pegwork_registry()->automation( $key ), $payload, null );
		$this->assertGreaterThan( 0, $id );
		$this->drain();
		return $this->run_row( $id );
	}

	/**
	 * The donor flow: the email reads the donor through the read, after the stamp it sees the written date,
	 * the task is linked through a relationship value, the context holds IDs and numbers only, and after
	 * the 30-day wait the read resolves fresh (new address, a later gift counted, listed by amount).
	 */
	public function test_outputs_are_references_read_after_write_and_fresh_after_a_wait() {
		$ada  = $this->contact( 'Ada', 'ada@example.org' );
		$gift = $this->gift( 'Gift 1', 300, array( $ada ) );
		$this->drain();

		$run = $this->only_run( 'er_gift' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'giving', $run['current_step'] );
		$this->assertSame(
			array(
				'found'     => true,
				'record_id' => $ada,
			),
			$run['context']['steps']['donor']
		);
		$this->assertSame( 1, $run['context']['reads'] );
		$this->assertStringNotContainsString( 'ada@example.org', (string) wp_json_encode( $run['context'] ), 'The context stores IDs, never read values.' );

		$rows = $this->rows( $run['id'] );
		$this->assertSame(
			array(
				'content_type' => 'barn_contact',
				'found'        => true,
				'record_id'    => $ada,
			),
			$rows['donor']['summary']
		);

		$mail = $this->mail();
		$this->assertCount( 1, $mail );
		$this->assertSame( 'ada@example.org', $mail[0]['to'][0][0] );
		$this->assertSame( 'Thank you, Ada', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Gift of $300.00 on November 1, 2026. Last gift on 2026-11-01. We call by November 3, 2026.', $mail[0]['body'] );

		$tasks = get_posts(
			array(
				'post_type'   => 'barn_task',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);
		$this->assertCount( 1, $tasks );
		$task = Records::get( $tasks[0] );
		$this->assertSame( 'Call Ada', $task['values']['name'] );
		$this->assertSame( '2026-11-04', $task['values']['due_on'] );
		$this->assertSame( array( $gift ), $this->linked( 'barn_gift', 'task_gift', $tasks[0] ) );

		// During the wait: the donor's address and name change, and a second gift arrives.
		$this->assertTrue( Records::update( $ada, array( 'email' => 'ada@new.example.org' ) ) );
		$this->clock->advance( 'P1D' );
		$this->gift( 'Gift 2', 1200, array( $ada ) );
		$this->drain();
		$this->assertCount( 2, $this->mail() );

		$this->clock->advance( 'P30D' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $run['context'] );

		$later = array_values( array_filter( $this->mail(), fn( $m ) => '2 gifts, 1,500 in all' === $m['subject'] ) );
		$this->assertCount( 2, $later, 'Both runs list both gifts.' );
		$this->assertSame( 'ada@new.example.org', $later[0]['to'][0][0] );
		$this->assertStringContainsString( '<ul><li>Gift 2</li><li>Gift 1</li></ul>', $later[0]['body'] );

		$giving = $this->rows( $run['id'] )['giving']['summary'];
		$this->assertSame( 2, $giving['count'] );
		$this->assertSame( 2, $giving['returned'] );
		$this->assertSame( array( 'amount' => 1500 ), array_map( 'intval', $giving['sum'] ) );
		$this->assertSame( 3, $run['context']['reads'] );
	}

	/**
	 * Relate adds once (a repeat reports changed: false) and emits record.updated on the "from" record at
	 * depth 1; the read webhook record sends public fields only; failures of on_error steps become
	 * outputs, and the run completes with errors, counted as a failure in the list.
	 */
	public function test_relate_webhook_record_and_on_error() {
		$ada  = $this->contact( 'Ada', 'ada@example.org' );
		$gift = $this->gift( 'Gift 1', 50 );
		$this->drain(); // er_gift stops at has_donor: no donor yet.

		$events = array();
		add_action(
			'pegwork_event',
			function ( $name, $payload ) use ( &$events ) {
				$events[] = array( $name, $payload );
			},
			10,
			2
		);

		$run = $this->webhook(
			'er_link',
			array(
				'gift_id'    => $gift,
				'contact_id' => $ada,
				'other_id'   => 0,
			)
		);
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'completed_with_errors:unlink,wrong', $run['context']['outcome'] );
		$this->assertSame( 'Completed with errors in unlink, wrong', Format::outcome( $run ) );
		$this->assertSame( array( $ada ), $this->linked( 'barn_contact', 'gift_donor', $gift ) );

		$rows = $this->rows( $run['id'] );
		$this->assertTrue( $rows['link']['summary']['changed'] );
		$this->assertFalse( $rows['again']['summary']['changed'] );
		$this->assertSame( 'failed', $rows['unlink']['status'] );
		$this->assertStringStartsWith( 'invalid_id:', (string) $rows['unlink']['error'] );
		$this->assertSame( 'failed', $rows['wrong']['status'] );
		$this->assertStringStartsWith( 'record_type:', (string) $rows['wrong']['error'] );
		$this->assertCount( 1, array_filter( $this->steps( $run['id'] ), fn( $row ) => 'wrong' === $row['step_id'] ), 'record_type is not retried.' );
		$this->assertSame(
			array(
				'failed' => true,
				'error'  => 'invalid_id',
			),
			$run['context']['steps']['unlink']
		);
		$this->assertSame( true, $run['context']['steps']['wrong']['failed'] );

		$updates = array_values( array_filter( $events, fn( $e ) => 'record.updated' === $e[0] ) );
		$this->assertCount( 1, $updates, 'One event for the one link added.' );
		$this->assertSame( $gift, $updates[0][1]['id'] );
		$this->assertContains( 'gift_donor', $updates[0][1]['changed'] );
		$this->assertSame( 1, $updates[0][1]['origin']['depth'] );
		$this->assertSame( 'er_link', $updates[0][1]['origin']['automation'] );

		$this->assertSame( array( 'https://hooks.example.org/gift', 'https://hooks.example.org/contact' ), array_column( $this->requests, 'url' ) );
		$this->assertSame( $gift, $this->requests[0]['body']['record']['id'] );
		$this->assertSame( array( 'name', 'amount', 'received_on', 'method', 'barn_fund' ), array_keys( $this->requests[0]['body']['record']['fields'] ) );
		$this->assertSame( array( 'name' => 'Ada' ), $this->requests[1]['body']['record']['fields'] );
		$this->assertStringNotContainsString( 'ada@example.org', (string) wp_json_encode( $this->requests ) );

		$mail = $this->mail();
		$this->assertCount( 1, $mail );
		$this->assertSame( 'Linked gift ' . $gift . ' to Ada', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Unlink failed: 1 (invalid_id). Wrong: record_type.', $mail[0]['body'] );

		$summary = Engine::instance()->runs()->summary( '2026-10-01 00:00:00' );
		$this->assertSame( 1, $summary['er_link']['failed'], 'A run completed with errors counts as a failure.' );
	}

	/**
	 * An empty operand matches nothing (never the contact with no email); a read past the read limit
	 * fails with read_limit, continues under on_error, and fails the run without it.
	 */
	public function test_read_limit_and_empty_operand() {
		$this->contact( 'Nobody', '' );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertIsInt( Records::create( 'barn_task', array( 'name' => 'Task ' . $i ), 'publish' ) );
		}
		add_filter( 'pegwork_automation_read_limit', fn() => 4 );

		$run  = $this->webhook( 'er_limits', array( 'email' => '' ) );
		$rows = $this->rows( $run['id'] );

		$this->assertSame(
			array(
				'content_type'  => 'barn_contact',
				'found'         => false,
				'record_id'     => null,
				'empty_operand' => 'email',
			),
			$rows['contact']['summary']
		);
		$this->assertSame( 5, $rows['open']['summary']['count'] );
		$this->assertSame( 2, $rows['open']['summary']['returned'] );
		$this->assertSame( 'failed', $rows['more']['status'] );
		$this->assertStringStartsWith( 'read_limit:', (string) $rows['more']['error'] );
		$this->assertSame( 'read_limit', $run['context']['steps']['more']['error'] );

		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'read_limit:', (string) $run['error'] );
		$this->assertSame( 2, $run['context']['reads'] );
		$this->assertCount( 4, $this->steps( $run['id'] ), 'One attempt each: read_limit is not retried.' );
	}

	/**
	 * `| add` keeps the wall-clock time across DST, `| number` formats in the site locale, `{{#each}}`
	 * renders items (escaped, skipping trashed ones, values never re-rendered) or `{{else}}`, the 64 KB cap
	 * holds, and read paths are masked in the log when private.
	 */
	public function test_template_filters_and_each_blocks() {
		update_option( 'timezone_string', 'America/New_York' );
		$this->assertSame( '2027-03-14', Templates::add( '2027-03-13', 'P1D' ) );
		$this->assertSame( '2027-03-14T09:00:00-04:00', Templates::add( '2027-03-13T09:00:00-05:00', 'P1D' ) );
		$this->assertSame( '2027-03-14T03:00:00-04:00', Templates::add( '2027-03-14T09:00:00-04:00', '-PT6H' ) );
		$this->assertSame( 'soon', Templates::add( 'soon', 'P1D' ) );
		update_option( 'timezone_string', '' );

		$this->assertSame( '1,000', Templates::number( 1000.0 ) );
		$this->assertSame( '1,234.50', Templates::number( 1234.5 ) );
		$this->assertSame( '1,234.57', Templates::number( '1234.5678', 2 ) );
		$this->assertSame( '300', Templates::number( '300', 0 ) );
		$this->assertSame( 'n/a', Templates::number( 'n/a' ) );

		$ada     = $this->contact( 'Ada', 'ada@example.org' );
		$first   = $this->gift( '<b>A & B</b>', 10, array(), 'publish' );
		$second  = $this->gift( '{{item.amount}}', 20, array(), 'publish' );
		$trashed = $this->gift( 'Gone', 30, array(), 'publish' );
		wp_trash_post( $trashed );

		$plan    = new Plan(
			array(
				array(
					'id'           => 'donor',
					'type'         => 'get_record',
					'content_type' => 'barn_contact',
					'record'       => '{{trigger.id}}',
				),
				array(
					'id'           => 'list',
					'type'         => 'find_records',
					'content_type' => 'barn_gift',
					'filter'       => array( 'all' => array() ),
				),
			)
		);
		$context = new Context(
			$this->clock,
			null,
			array(),
			array(
				'donor' => array(
					'found'     => true,
					'record_id' => $ada,
				),
				'list'  => array(
					'count' => 3,
					'ids'   => array( $first, $trashed, $second ),
					'sum'   => array( 'amount' => 60.0 ),
				),
				'none'  => array(
					'count' => 0,
					'ids'   => array(),
					'sum'   => array(),
				),
			)
		);
		$context->use_plan( $plan );

		$this->assertSame( '<i>A &amp; B</i><i>{{item.amount}}</i>', Templates::render( '{{#each steps.list.records}}<i>{{item.name}}</i>{{else}}none{{/each}}', $context, Templates::HTML ) );
		$this->assertSame( 'Gifts: none.', Templates::render( 'Gifts: {{#each steps.none.records}}{{item.name}}{{else}}none{{/each}}.', $context, Templates::TEXT ) );
		$this->assertSame( '10 20 ', Templates::render( '{{#each steps.list.records}}{{item.amount | number}} {{/each}}', $context, Templates::TEXT ) );
		$this->assertSame( '60', Templates::render( '{{steps.list.sum.amount | number}}', $context, Templates::TEXT ) );
		$this->assertSame( 'Ada ' . Templates::PRIVATE_MASK, Templates::render( '{{steps.donor.record.name}} {{steps.donor.record.email}}', $context, Templates::LOG ) );
		$this->assertSame( 'ada@example.org', Templates::value( '{{steps.donor.record.email}}', $context ) );
		$this->assertNull( $context->resolve( 'steps.list.records' ), 'A collection is not a value.' );

		$big = new Context(
			$this->clock,
			null,
			array(),
			array(
				'list' => array(
					'count' => 50,
					'ids'   => array_fill( 0, 50, $first ),
					'sum'   => array(),
				),
			)
		);
		try {
			Templates::render( '{{#each steps.list.records}}' . str_repeat( 'x', 1500 ) . '{{/each}}', $big, Templates::TEXT );
			$this->fail( 'The 64 KB cap was not enforced.' );
		} catch ( TemplateTooLarge $e ) {
			$this->assertStringContainsString( '65536', $e->getMessage() );
		}
		$this->assertSame( 1500 * 40, strlen( Templates::render( '{{#each steps.list.records}}' . str_repeat( 'x', 1500 ) . '{{/each}}', new Context( $this->clock, null, array(), array( 'list' => array( 'ids' => array_fill( 0, 40, $first ) ) ) ), Templates::TEXT ) ) );
	}

	/**
	 * A dry run reads for real (donor and gifts by ID and title, counts and sums), renders the emails
	 * with real read values, says once that reads do not see would-be writes, reports an on_error failure
	 * and continues, and has no side effect: no mail, HTTP, event, run, step, post or meta (links) change.
	 */
	public function test_dry_run_reads_with_no_side_effects() {
		$ada  = $this->contact( 'Ada', 'ada@example.org' );
		$gift = $this->gift( 'Gift 1', 300, array( $ada ) );

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

		$report = DryRun::report( 'er_gift', $gift, null );
		$steps  = array_column( $report['steps'], null, 'id' );
		$this->assertSame( 'completed', $report['outcome'] );
		$this->assertSame(
			array(
				'content_type' => 'barn_contact',
				'found'        => true,
				'record_id'    => $ada,
				'title'        => 'Ada',
			),
			$steps['donor']['detail']
		);
		$this->assertSame( sprintf( 'Get record donor: found Contact %d "Ada".', $ada ), $steps['donor']['message'] );
		$this->assertSame( array( 'a***@example.org' ), $steps['thank']['detail']['to'] );
		$this->assertStringContainsString( 'Last gift on .', $steps['thank']['detail']['text'], 'The stamp is not applied.' );
		$this->assertSame( 1, $steps['giving']['detail']['count'] );
		$this->assertSame(
			array(
				array(
					'id'    => $gift,
					'title' => 'Gift 1',
				),
			),
			$steps['giving']['detail']['records']
		);
		$this->assertStringContainsString( sprintf( '1 Gift found, 1 returned (%d "Gift 1"); sum of amount 300.', $gift ), $steps['giving']['message'] );
		$this->assertStringContainsString( 'Reads in a test see the site as it is now', $steps['giving']['message'] );
		$this->assertSame( "- Gift 1: 300\n", $steps['later']['detail']['text'] );
		$this->assertSame( '1 gifts, 300 in all', $steps['later']['detail']['subject'] );

		$report = DryRun::report(
			'er_link',
			null,
			array(
				'gift_id'    => $gift,
				'contact_id' => $ada,
				'other_id'   => 0,
			)
		);
		$steps  = array_column( $report['steps'], null, 'id' );
		$this->assertSame( 'completed_with_errors:wrong', $report['outcome'] );
		$this->assertSame( sprintf( 'Relate link: would add a gift_donor link from record %1$d to record %2$d (not applied).', $gift, $ada ), $steps['link']['message'] );
		$this->assertSame( array( 'name' ), $steps['send_donor']['detail']['fields'] );
		$this->assertSame( 'continues', $steps['wrong']['result'] );
		$this->assertStringContainsString( 'Wrong: record_type.', $steps['report']['detail']['text'] );
		$this->assertSame( 'The run would complete with errors in wrong.', array_slice( DryRun::lines( $report ), -1 )[0] );

		$this->assertSame( 0, array_sum( $calls ), (string) wp_json_encode( $calls ) );
		$this->assertSame( $before, $this->state() );
	}

	/**
	 * What a dry run must leave unchanged: run and step rows, posts, post meta (relationship links are
	 * meta rows), sent mail.
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
			'meta'  => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(meta_id, meta_key, meta_value ORDER BY meta_id)) FROM {$wpdb->postmeta}" ),
			'links' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_rel\\_%'" ),
			'sent'  => count( tests_retrieve_phpmailer_instance()->mock_sent ),
		);
	}
}
