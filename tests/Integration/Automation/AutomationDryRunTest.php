<?php
/**
 * Integration tests: editor preview and dry run (Phase 6 PR 4).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkAutomation\DryRun;
use PegworkAutomation\Editor\AutomationPost;
use PegworkAutomation\Editor\PreviewRoutes;
use PegworkAutomation\Links\Key;
use PegworkAutomation\Schema;
use PegworkAutomation\Templates;
use PegworkAutomation\Webhooks\Secrets;
use WP_REST_Request;
use WP_REST_Response;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests count rows in the module's tables.

/**
 * The dryrun fixture: previews render links and step outputs as placeholders; a dry run walks the path
 * the sample takes and reports each step, with no email, HTTP, event, run row, link key or secret; the
 * CLI and REST reports match; the routes refuse non-administrators, a missing or bad nonce and non-JSON
 * bodies, and send no-store.
 */
class AutomationDryRunTest extends AutomationTestCase {

	/**
	 * Side-effect counters.
	 *
	 * @var array<string, int>
	 */
	private $calls = array(
		'mail'  => 0,
		'http'  => 0,
		'event' => 0,
	);

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Package directory: the dryrun fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/dryrun';
	}

	/**
	 * An administrator, a fresh mailer, and counters on mail, HTTP and events (no real HTTP leaves).
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes.
	}

	/**
	 * Clears the nonce globals.
	 */
	public function tear_down() {
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
		parent::tear_down();
	}

	/**
	 * Starts counting side effects (after the fixture records exist).
	 */
	private function count_side_effects() {
		add_filter(
			'pre_wp_mail',
			function ( $pre ) {
				++$this->calls['mail'];
				return $pre;
			}
		);
		add_filter(
			'pre_http_request',
			function () {
				++$this->calls['http'];
				return new \WP_Error( 'blocked', 'No HTTP in tests.' );
			}
		);
		add_action(
			'pegwork_event',
			function () {
				++$this->calls['event'];
			}
		);
	}

	/**
	 * What a dry run must leave unchanged: rows, options, sent mail.
	 *
	 * @return array<string, mixed>
	 */
	private function state() {
		global $wpdb;
		$runs  = Schema::runs();
		$steps = Schema::steps();
		return array(
			'runs'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			'steps'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$steps}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema.
			'posts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			'meta'    => (string) $wpdb->get_var( "SELECT MD5(GROUP_CONCAT(meta_id, meta_key, meta_value ORDER BY meta_id)) FROM {$wpdb->postmeta}" ),
			'link'    => get_option( Key::OPTION, 'absent' ),
			'secrets' => get_option( Secrets::OPTION, 'absent' ),
			'sent'    => count( tests_retrieve_phpmailer_instance()->mock_sent ),
		);
	}

	/**
	 * A case record.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return int
	 */
	private function case_record( array $values ) {
		$id = Records::create( 'dr_case', $values + array( 'email' => 'ada@example.org' ), 'publish' );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A JSON POST to an editor route, through the REST server as serve_request would run it.
	 *
	 * @param string               $path Route after /pegwork/v1/automation-editor.
	 * @param array<string, mixed> $body Body.
	 * @param string               $type Content type.
	 * @return WP_REST_Response
	 */
	private function post( $path, array $body, $type = 'application/json' ) {
		$request = new WP_REST_Request( 'POST', '/' . PreviewRoutes::NAMESPACE . PreviewRoutes::BASE . $path );
		$request->set_header( 'Content-Type', $type );
		$request->set_body( (string) wp_json_encode( $body ) );
		$server   = rest_get_server();
		$response = rest_ensure_response( $server->dispatch( $request ) );
		return apply_filters( 'rest_post_dispatch', $response, $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter, as serve_request() applies it.
	}

	/**
	 * A big high-priority case takes "Big": the email is masked and rendered with a link placeholder, the
	 * webhook shows its host and fields, the wait is passed over, the update is not applied, and the
	 * run completes. Nothing is sent, requested, emitted, written or minted.
	 */
	public function test_dry_run_reports_the_path_with_no_side_effects() {
		$id = $this->case_record(
			array(
				'name'     => 'Ada & Co',
				'priority' => 'high',
				'amount'   => 300,
			)
		);
		$this->count_side_effects();
		$before = $this->state();

		$report = DryRun::report( 'dr_flow', $id, null );

		$this->assertSame( 0, array_sum( $this->calls ), (string) wp_json_encode( $this->calls ) );
		$this->assertSame( $before, $this->state() );
		$this->assertSame( 'absent', $before['link'], 'The fixture starts with no link key.' );
		$this->assertSame( 'absent', $before['secrets'] );

		$this->assertSame( 'dr_flow', $report['automation'] );
		$this->assertSame( $id, $report['record']['id'] );
		$this->assertTrue( $report['trigger']['matches'] );
		$this->assertSame( array( 'route', 'thank', 'ping', 'later', 'mark', 'still_high', 'last' ), array_column( $report['steps'], 'id' ) );
		$this->assertSame( array( 0, 1, 1, 0, 0, 0, 0 ), array_column( $report['steps'], 'depth' ) );
		$this->assertSame( array( null, 'Big', null, null, null, null, null ), array_column( $report['steps'], 'branch' ) );
		$this->assertSame( 'completed', $report['outcome'] );

		$steps = array_column( $report['steps'], null, 'id' );
		$this->assertSame(
			array(
				'branch' => 'Big',
				'index'  => 0,
			),
			$steps['route']['detail']
		);
		$this->assertSame( array( 'a***@example.org' ), $steps['thank']['detail']['to'] );
		$this->assertSame( 'Thanks, Ada & Co', $steps['thank']['detail']['subject'] );
		$this->assertSame( 'Confirm: ' . Templates::PREVIEW_LINK, $steps['thank']['detail']['text'] );
		$this->assertSame( '<p>Ada &amp; Co: <a href="[signed link]">Confirm</a></p>', $steps['thank']['detail']['html'] );
		$this->assertStringNotContainsString( 'ada@example.org', (string) wp_json_encode( $report ) );
		$this->assertSame(
			array(
				'host'   => 'hooks.example.org',
				'fields' => array( 'name', 'amount' ),
			),
			$steps['ping']['detail']
		);
		$this->assertSame( 'waits', $steps['later']['result'] );
		$this->assertStringContainsString( 'waits P30D', $steps['later']['message'] );
		$this->assertSame( (string) $id, $steps['mark']['detail']['record'] );
		$this->assertSame( array( 'marker' => 'checked 2026-11-02' ), $steps['mark']['detail']['values'] );
		$this->assertSame( '', (string) Records::get( $id )['values']['marker'], 'The update is not applied.' );
		$this->assertSame( 'ok', $steps['still_high']['result'] );
	}

	/**
	 * A normal case would not start a run (reported first), takes Otherwise, sees the create as a
	 * placeholder output, and stops at the false condition.
	 */
	public function test_trigger_mismatch_otherwise_step_outputs_and_stop() {
		$id = $this->case_record(
			array(
				'name'   => 'Bo',
				'amount' => 10,
			)
		);
		$this->count_side_effects();
		$before = $this->state();

		$result = DryRun::result( 'dr_flow', $id, null );

		$this->assertSame( $before, $this->state() );
		$this->assertSame( 0, array_sum( $this->calls ) );
		$this->assertFalse( $result['trigger']['matches'] );
		$this->assertSame( array( 'route', 'task', 'tell', 'later', 'mark', 'still_high' ), array_column( $result['steps'], 'id' ) );
		$this->assertSame( 'otherwise', $result['steps'][1]['branch'] );
		$this->assertSame( -1, $result['steps'][0]['detail']['index'] );
		$this->assertSame(
			array(
				'content_type' => 'dr_case',
				'status'       => 'draft',
				'values'       => array( 'name' => 'Follow up Bo' ),
			),
			$result['steps'][1]['detail']
		);
		$this->assertSame( 'Task [from step task]', $result['steps'][2]['detail']['subject'] );
		$this->assertSame( 'stops', $result['steps'][5]['result'] );
		$this->assertSame( 'stopped_at:still_high', $result['outcome'] );

		$this->assertSame(
			array(
				'A real trigger would not start a run for this record. The steps are tested anyway.',
				'Decision route: takes Otherwise.',
				'Otherwise:',
				'  Create record task: would create a draft dr_case with name (not applied).',
				'  Send email tell: to t***@example.org, subject "Task [from step task]" (not sent).',
				'Wait later: waits P30D. The test continues as if it had passed, against the record as it is now.',
				'Update record mark: would write marker to record ' . $id . ' (not applied).',
				'Condition still_high is false: stops here.',
				'The run would stop there.',
			),
			$result['lines']
		);
	}

	/**
	 * Webhook triggers take the pasted payload; form triggers get the record's bound values.
	 */
	public function test_webhook_and_form_payloads() {
		$id = $this->case_record(
			array(
				'name'     => 'Di',
				'priority' => 'high',
			)
		);
		$this->count_side_effects();
		$before = $this->state();

		$hook = DryRun::report(
			'dr_inbound',
			null,
			array(
				'name'  => 'Cy',
				'email' => 'cy@example.net',
			)
		);
		$this->assertNull( $hook['record'] );
		$this->assertTrue( $hook['trigger']['matches'] );
		$this->assertSame( array( 'name' => 'Cy' ), $hook['steps'][0]['detail']['values'] );
		$this->assertSame( array( 'c***@example.net' ), $hook['steps'][1]['detail']['to'] );
		$this->assertSame( 'Case [from step open] is open.', $hook['steps'][1]['detail']['text'] );

		$form = DryRun::report( 'dr_intake_notice', $id, null );
		$this->assertSame( 'New case: Di (high)', $form['steps'][0]['detail']['subject'] );
		$this->assertSame( 'ada@example.org', $form['steps'][0]['detail']['reply_to'] );

		$this->assertSame( $before, $this->state() );
		$this->assertSame( 0, array_sum( $this->calls ) );
	}

	/**
	 * The sample record must be of the bound type; unknown keys are 404.
	 */
	public function test_sample_record_and_key_errors() {
		$this->assertSame( 'pegwork_test_record', DryRun::report( 'dr_flow', null, null )->get_error_code() );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 400, DryRun::report( 'dr_flow', $page, null )->get_error_data()['status'] );
		$this->assertSame( 404, DryRun::report( 'dr_nope', null, null )->get_error_data()['status'] );
	}

	/**
	 * Preview mode: links and step outputs as placeholders, HTML escaped, plain text decoded.
	 */
	public function test_preview_placeholders() {
		$id      = $this->case_record( array( 'name' => 'Ed & "Jo"' ) );
		$trigger = pegwork_registry()->automation( 'dr_flow' )['trigger'];
		$record  = DryRun::sample_record( $trigger, $id );
		$payload = DryRun::payload( $trigger, $record, null );
		$this->assertSame( array(), $payload['changed'] );
		$this->assertSame( 'normal', $payload['old']['priority'] );
		$this->count_side_effects();
		$before = $this->state();

		$this->assertSame( 'Hi Ed & "Jo" [signed link] [from step task]', DryRun::preview( 'Hi {{record.name}} {{link.confirm}} {{steps.task.record_id}}', false, $record, $payload ) );
		$this->assertSame( 'Hi Ed &amp; &quot;Jo&quot; [signed link]', DryRun::preview( 'Hi {{record.name}} {{link.confirm}}', true, $record, $payload ) );
		$this->assertSame( 'normal', DryRun::preview( '{{trigger.old.priority}}', false, $record, $payload ) );
		$this->assertSame( $before, $this->state() );
		$this->assertSame( 0, array_sum( $this->calls ) );
	}

	/**
	 * REST preview and test: 200 for an administrator, no-store, and the test body equals the CLI's.
	 */
	public function test_rest_routes_match_the_cli() {
		$id = $this->case_record(
			array(
				'name'     => 'Fay',
				'priority' => 'high',
				'amount'   => 500,
			)
		);
		$this->count_side_effects();
		$before = $this->state();

		$preview = $this->post(
			'/preview',
			array(
				'template'  => '<a href="{{link.confirm}}">{{record.name}}</a>',
				'html'      => true,
				'trigger'   => pegwork_registry()->automation( 'dr_flow' )['trigger'],
				'record_id' => $id,
			)
		);
		$this->assertSame( 200, $preview->get_status() );
		$this->assertSame( array( 'rendered' => '<a href="[signed link]">Fay</a>' ), $preview->get_data() );
		$this->assertSame( 'no-store', $preview->get_headers()['Cache-Control'] );

		$test = $this->post(
			'/test',
			array(
				'key'       => 'dr_flow',
				'record_id' => $id,
			)
		);
		$this->assertSame( 200, $test->get_status() );
		$this->assertSame( 'no-store', $test->get_headers()['Cache-Control'] );
		$this->assertSame( AutomationCommand::test_result( 'dr_flow', array( 'record' => (string) $id ) ), $test->get_data() );
		$this->assertSame(
			(string) wp_json_encode( AutomationCommand::test_result( 'dr_flow', array( 'record' => (string) $id ) ) ),
			(string) wp_json_encode( $test->get_data() )
		);

		$hook = $this->post(
			'/test',
			array(
				'key'     => 'dr_inbound',
				'payload' => array( 'name' => 'Gus' ),
			)
		);
		$this->assertSame( AutomationCommand::test_result( 'dr_inbound', array( 'payload' => '{"name":"Gus"}' ) ), $hook->get_data() );
		$this->assertSame( 'pegwork_test_payload', AutomationCommand::test_result( 'dr_inbound', array( 'payload' => 'nope' ) )->get_error_code() );

		$this->assertSame( 400, $this->post( '/test', array( 'key' => 'dr_flow' ) )->get_status() );
		$this->assertSame( 404, $this->post( '/test', array( 'key' => 'dr_nope' ) )->get_status() );

		$this->assertSame( $before, $this->state() );
		$this->assertSame( 0, array_sum( $this->calls ) );
	}

	/**
	 * Editors, subscribers and visitors are refused on both routes; a non-JSON body is 415; refusals
	 * also send no-store.
	 */
	public function test_capability_and_content_type_refusals() {
		$body = array(
			'key'      => 'dr_inbound',
			'template' => 'x',
			'trigger'  => array( 'type' => 'webhook' ),
		);
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			foreach ( array( '/preview', '/test' ) as $path ) {
				$response = $this->post( $path, $body );
				$this->assertSame( 403, $response->get_status(), "$role $path" );
				$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->post( '/test', $body )->get_status() );

		wp_set_current_user( $this->admin );
		// The routes check the post type's meta capability, not manage_options directly.
		$deny = fn( $caps, $cap ) => AutomationPost::CAP === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 20, 2 );
		$this->assertTrue( current_user_can( 'manage_options' ) );
		$this->assertSame( 403, $this->post( '/test', $body )->get_status() );
		remove_filter( 'map_meta_cap', $deny, 20 );

		$this->assertSame( 415, $this->post( '/test', $body, 'application/x-www-form-urlencoded' )->get_status() );
		$this->assertSame( 415, $this->post( '/preview', $body, 'text/plain' )->get_status() );
		$this->assertSame( 200, $this->post( '/preview', $body )->get_status() );
	}

	/**
	 * Cookie authentication: without a nonce the request runs as no user (401); a bad nonce is refused
	 * by core (403); a valid one passes.
	 */
	public function test_cookie_nonce_refusals() {
		$body = array( 'key' => 'dr_inbound' );
		// The spy server records the X-WP-Nonce header a valid nonce sends instead of calling header().
		add_filter( 'wp_rest_server_class', fn() => 'Spy_REST_Server' );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild as the spy server.

		$GLOBALS['wp_rest_auth_cookie'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's cookie-auth flag.
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 401, $this->post( '/test', $body )->get_status() );

		wp_set_current_user( $this->admin );
		$_SERVER['HTTP_X_WP_NONCE'] = 'bad';
		$error                      = rest_cookie_check_errors( null );
		$this->assertWPError( $error );
		$this->assertSame( 'rest_cookie_invalid_nonce', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );

		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->assertTrue( rest_cookie_check_errors( null ) );
		$this->assertSame( $this->admin, get_current_user_id() );
		$this->assertSame( 200, $this->post( '/test', $body )->get_status() );
	}
}
