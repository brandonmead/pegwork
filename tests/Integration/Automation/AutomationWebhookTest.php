<?php
/**
 * Integration tests: call_webhook (outbound), the inbound webhook endpoint, secrets.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\AutomationList;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use PegworkAutomation\Schema;
use PegworkAutomation\Webhooks\Inbound;
use PegworkAutomation\Webhooks\Secrets;
use WP_Error;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Tests read the module's tables and options to prove what is stored.

/**
 * Outbound deliveries checked by a test receiver on pre_http_request (it verifies the signature the way
 * docs/automation.md tells receivers to), retries on the frozen clock ending in a logged failure, and the
 * inbound endpoint's limits: 401, 413, 415, 429, replay, 202 with a run, duplicate deliveries, rotation.
 */
class AutomationWebhookTest extends AutomationTestCase {

	/**
	 * Requests the receiver got: url, args, verified.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $received = array();

	/**
	 * What the receiver answers: an HTTP status, or a WP_Error for "down".
	 *
	 * @var int|WP_Error
	 */
	private $answer = 200;

	/**
	 * Secret the receiver checks with; null reads the stored one at request time.
	 *
	 * @var string|null
	 */
	private $receiver_secret = null;

	/**
	 * Installs the test receiver (no real HTTP leaves the test) and clears the rate buckets.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%pegwork\\_automation\\_hook\\_%'" );
		wp_cache_flush();
		add_filter( 'pre_http_request', array( $this, 'receiver' ), 10, 3 );
	}

	/**
	 * The test receiver: verifies X-Pegwork-Signature as documented (HMAC-SHA256 of "<timestamp>.<body>"),
	 * records the request and answers $this->answer.
	 *
	 * @param mixed                $pre  Short-circuit value.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  URL.
	 * @return array<string, mixed>|WP_Error
	 */
	public function receiver( $pre, $args, $url ) {
		$headers  = $args['headers'];
		$secret   = $this->receiver_secret ?? (string) get_option( 'pegwork_automation_secrets' )['job_hook'];
		$expected = 'sha256=' . hash_hmac( 'sha256', $headers['X-Pegwork-Timestamp'] . '.' . $args['body'], $secret );

		$this->received[] = array(
			'url'      => $url,
			'args'     => $args,
			'verified' => hash_equals( $expected, $headers['X-Pegwork-Signature'] ),
			'body'     => json_decode( $args['body'], true ),
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
	}

	/**
	 * A pending job with private values and the marker that makes job_hook fire on publish.
	 *
	 * @return int Post ID.
	 */
	private function hook_job() {
		$id = Records::create(
			'job',
			array(
				'title'         => 'Hooked job',
				'marker'        => 'hook',
				'website'       => 'https://example.org/site',
				'note'          => 'secret-note',
				'contact_email' => 'owner@example.org',
				'job_stage'     => 'open',
			),
			'pending'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

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
	 * Everything the module stored about a run, as JSON.
	 *
	 * @param int $run_id Run ID.
	 */
	private function stored( $run_id ) {
		global $wpdb;
		$runs_table  = Schema::runs();
		$steps_table = Schema::steps();
		$run         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$runs_table} WHERE id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
		$steps       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$steps_table} WHERE run_id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name.
		return wp_json_encode( array( $run, $steps ) );
	}

	/**
	 * Sends an inbound request through the REST server.
	 *
	 * @param string               $key     Automation key.
	 * @param string               $body    Raw body.
	 * @param array<string, mixed> $options `secret` (default the stored one), `timestamp` (default now),
	 *                                      `sign` (default true), `type` (Content-Type), `headers`.
	 * @return \WP_REST_Response
	 */
	private function inbound( $key, $body, array $options = array() ) {
		$request = new WP_REST_Request( 'POST', '/pegwork/v1/automations/' . $key . '/webhook' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', $options['type'] ?? 'application/json' );
		if ( $options['sign'] ?? true ) {
			$timestamp = (string) ( $options['timestamp'] ?? $this->clock->now()->getTimestamp() );
			$secret    = $options['secret'] ?? Secrets::get( $key ) ?? 'none';
			$request->set_header( 'X-Pegwork-Timestamp', $timestamp );
			$request->set_header( 'X-Pegwork-Signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ) );
		}
		foreach ( $options['headers'] ?? array() as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Acceptance: the delivery is signed and a receiver verifies it; the body carries public fields only;
	 * the delivery ID names the run and step; the save itself sends nothing.
	 */
	public function test_outbound_delivery_is_signed_and_verified_by_a_receiver() {
		$id = $this->hook_job();
		$this->publish_via_rest( $id );
		$this->assertSame( array(), $this->received, 'Publishing does not wait for the webhook.' );
		$this->assertSame( 'publish', get_post_status( $id ) );

		$this->drain();
		$run = $this->only_run( 'job_hook' );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertCount( 2, $this->received );

		[ $notify, $everything ] = $this->received;
		$this->assertTrue( $notify['verified'], 'The receiver verifies the signature.' );
		$this->assertTrue( $everything['verified'] );
		$this->assertSame( 'https://receiver.example.org/jobs/' . $id, $notify['url'] );
		$this->assertSame( $run['id'] . '-notify', $notify['args']['headers']['X-Pegwork-Delivery'] );
		$this->assertSame( $run['id'] . '-everything', $everything['args']['headers']['X-Pegwork-Delivery'] );
		$this->assertSame( (string) $this->clock->now()->getTimestamp(), $notify['args']['headers']['X-Pegwork-Timestamp'] );
		$this->assertStringStartsWith( 'application/json', $notify['args']['headers']['Content-Type'] );
		$this->assertTrue( $notify['args']['reject_unsafe_urls'], 'wp_safe_remote_post blocks private addresses.' );
		$this->assertSame( 0, $notify['args']['redirection'] );
		$this->assertEquals( 5, $notify['args']['timeout'] );
		$this->assertEquals( 10, $everything['args']['timeout'] );

		$this->assertSame(
			array(
				'event'        => 'record.status_changed',
				'automation'   => 'job_hook',
				'run_id'       => (int) $run['id'],
				'delivered_at' => '2026-11-02T09:00:00Z',
				'record'       => array(
					'id'        => $id,
					'type'      => 'job',
					'status'    => 'publish',
					'permalink' => get_permalink( $id ),
					'fields'    => array(
						'title'     => 'Hooked job',
						'website'   => 'https://example.org/site',
						'job_stage' => array( 'open' ),
					),
				),
			),
			$notify['body']
		);
		$fields = $everything['body']['record']['fields'];
		$this->assertSame( array( 'title', 'last_verified', 'marker', 'child_id', 'urgent', 'website', 'job_stage' ), array_keys( $fields ), 'Without "fields": every public field and taxonomy.' );
		foreach ( $this->received as $request ) {
			$this->assertStringNotContainsString( 'secret-note', $request['args']['body'] );
			$this->assertStringNotContainsString( 'owner@example.org', $request['args']['body'] );
		}

		// The run log: host, status and delivery ID; never the secret, the signature or a body.
		$steps = $this->steps( $run['id'] );
		$this->assertSame(
			array(
				'host'     => 'receiver.example.org',
				'delivery' => $run['id'] . '-notify',
				'status'   => 200,
			),
			$steps[0]['summary']
		);
		$stored = $this->stored( $run['id'] );
		$this->assertStringNotContainsString( Secrets::peek( 'job_hook' ), $stored );
		$this->assertStringNotContainsString( $notify['args']['headers']['X-Pegwork-Signature'], $stored );
		$this->assertStringNotContainsString( 'Hooked job', wp_json_encode( $steps ) );
	}

	/**
	 * Acceptance: with the receiver down, the step retries after 1 minute, 10 minutes and 1 hour with the
	 * same delivery ID, then the run fails with a logged error; the publish itself succeeded.
	 */
	public function test_receiver_down_retries_then_logs_a_failure() {
		$this->answer = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to receiver.example.org port 443' );
		$id           = $this->hook_job();
		$this->publish_via_rest( $id );
		$this->assertSame( 'publish', get_post_status( $id ), 'Publish is unaffected by the receiver.' );

		$this->drain();
		$run = $this->only_run( 'job_hook' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertStringStartsWith( 'retrying: webhook_failed: Could not reach receiver.example.org', $run['error'] );
		$this->assertSame( '2026-11-02 09:01:00', $run['resume_at'] );

		foreach ( array( 'PT1M', 'PT10M', 'PT1H' ) as $backoff ) {
			$this->clock->advance( $backoff );
			$this->drain();
		}

		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertSame( 'webhook_failed: Could not reach receiver.example.org: cURL error 7: Failed to connect to receiver.example.org port 443 (after 4 attempts)', $run['error'] );
		$this->assertSame( array( 'retrying', 'retrying', 'retrying', 'failed' ), array_column( $this->steps( $run['id'] ), 'status' ) );
		$this->assertSame( array( 1, 2, 3, 4 ), array_map( 'intval', array_column( $this->steps( $run['id'] ), 'attempt' ) ) );
		$this->assertCount( 4, $this->received );
		$this->assertSame( array_fill( 0, 4, $run['id'] . '-notify' ), array_map( fn( $r ) => $r['args']['headers']['X-Pegwork-Delivery'], $this->received ), 'The delivery ID is stable across retries.' );
		$this->assertCount( 4, array_unique( array_map( fn( $r ) => $r['args']['headers']['X-Pegwork-Timestamp'], $this->received ) ), 'Each attempt is signed afresh.' );
	}

	/**
	 * A non-2xx answer fails the attempt (retryable) and the summary keeps the status.
	 */
	public function test_non_2xx_is_a_failed_attempt() {
		$this->answer = 503;
		$this->publish_via_rest( $this->hook_job() );
		$this->drain();

		$run = $this->only_run( 'job_hook' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'retrying: webhook_failed: receiver.example.org answered HTTP 503.', $run['error'] );
		$this->assertSame( 503, $this->steps( $run['id'] )[0]['summary']['status'] );

		// The receiver recovers: the retry delivers and the run goes on.
		$this->answer = 204;
		$this->clock->advance( 'PT1M' );
		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
	}

	/**
	 * Acceptance: rotating the secret invalidates the old signature, outbound and inbound.
	 */
	public function test_secret_rotation_invalidates_the_old_signature() {
		$old = Secrets::get( 'job_hook' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $old );
		$this->assertSame( $old, Secrets::get( 'job_hook' ), 'Generated once, then reused.' );

		$new = Secrets::rotate( 'job_hook' );
		$this->assertNotSame( $old, $new );

		$this->receiver_secret = $old;
		$this->publish_via_rest( $this->hook_job() );
		$this->drain();
		$this->assertFalse( $this->received[0]['verified'], 'A receiver holding the old secret rejects the delivery.' );
		$this->receiver_secret = $new;
		$this->received        = array();
		$this->publish_via_rest( $this->hook_job() );
		$this->drain();
		$this->assertTrue( $this->received[0]['verified'] );

		// Inbound.
		$inbound_old = Secrets::get( 'job_inbound' );
		Secrets::rotate( 'job_inbound' );
		$body = wp_json_encode( array( 'title' => 'Rotated' ) );
		$this->assertSame( 401, $this->inbound( 'job_inbound', $body, array( 'secret' => $inbound_old ) )->get_status() );
		$this->assertSame( 202, $this->inbound( 'job_inbound', $body )->get_status() );
	}

	/**
	 * Secrets: autoload off, one per automation, never in the registry or its export.
	 */
	public function test_secrets_are_stored_apart_with_autoload_off() {
		global $wpdb;
		$secret   = Secrets::get( 'job_inbound' );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Secrets::OPTION ) );
		$this->assertContains( $autoload, array( 'off', 'no' ) );
		$this->assertNotSame( $secret, Secrets::get( 'job_hook' ) );
		$this->assertStringNotContainsString( $secret, wp_json_encode( pegwork_registry()->automations() ) );
		$this->assertStringNotContainsString( $secret, (string) wp_json_encode( get_option( 'pegwork_schema' ) ) );

		$this->assertTrue( Secrets::used_by( pegwork_registry()->automation( 'job_inbound' ) ) );
		$this->assertTrue( Secrets::used_by( pegwork_registry()->automation( 'job_hook' ) ) );
		$this->assertFalse( Secrets::used_by( pegwork_registry()->automation( 'job_approved' ) ) );
	}

	/**
	 * Acceptance: a signed request gets 202 with the run ID, never the payload; the run creates the record
	 * through Records (sanitized); a repeated delivery ID creates no second run.
	 */
	public function test_inbound_202_creates_a_run_and_a_duplicate_delivery_does_not() {
		$body     = wp_json_encode(
			array(
				'title'   => '<b>From</b> n8n',
				'website' => 'https://n8n.example.org/p',
				'note'    => 'private-from-n8n',
			)
		);
		$response = $this->inbound( 'job_inbound', $body, array( 'headers' => array( 'X-Pegwork-Delivery' => 'n8n-42' ) ) );
		$this->assertSame( 202, $response->get_status() );
		$run = $this->only_run( 'job_inbound' );
		$this->assertSame(
			array(
				'run_id' => (int) $run['id'],
				'status' => 'queued',
			),
			$response->get_data()
		);
		$this->assertSame( 'webhook', $run['trigger_type'] );
		$this->assertSame( 'delivery:n8n-42', $run['idempotency_key'] );

		$again = $this->inbound( 'job_inbound', $body, array( 'headers' => array( 'X-Pegwork-Delivery' => 'n8n-42' ) ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame(
			array(
				'run_id'    => (int) $run['id'],
				'duplicate' => true,
			),
			$again->get_data()
		);
		$this->assertCount( 1, $this->runs( 'job_inbound' ), 'A repeated delivery creates no second run.' );

		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$record = Records::get( (int) $run['context']['steps']['create']['record_id'] );
		$this->assertSame( 'draft', $record['status'] );
		$this->assertSame( 'From n8n', $record['values']['title'], 'Values are sanitized by the Records API.' );
		$this->assertSame( 'https://n8n.example.org/p', $record['values']['website'] );
		$this->assertSame( 'private-from-n8n', $record['values']['note'] );

		foreach ( array( $response, $again ) as $answer ) {
			$this->assertStringNotContainsString( 'n8n.example.org', wp_json_encode( $answer->get_data() ), 'The payload is never echoed.' );
		}

		// Without a delivery ID each request is a new run.
		$this->assertSame( 202, $this->inbound( 'job_inbound', $body )->get_status() );
		$this->assertSame( 202, $this->inbound( 'job_inbound', $body )->get_status() );
		$this->assertCount( 3, $this->runs( 'job_inbound' ) );
	}

	/**
	 * A payload value the field rejects fails the run through Records validation; nothing is created.
	 */
	public function test_inbound_values_are_validated_by_records() {
		$before = wp_count_posts( 'job' )->draft;
		$this->inbound(
			'job_inbound',
			wp_json_encode(
				array(
					'title'   => 'Bad URL',
					'website' => 'javascript:alert(1)',
				)
			)
		);
		$this->drain();

		$run = $this->only_run( 'job_inbound' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'pegwork_invalid_record:', $run['error'] );
		$this->assertStringNotContainsString( 'javascript:', $run['error'] );
		wp_cache_flush();
		$this->assertSame( $before, wp_count_posts( 'job' )->draft );
	}

	/**
	 * Acceptance: bad, missing and replayed signatures, and unknown, disabled or non-webhook automations,
	 * all get the same 401 and create nothing.
	 */
	public function test_inbound_401_cases_are_indistinguishable() {
		$body    = wp_json_encode( array( 'title' => 'Nope' ) );
		$now     = $this->clock->now()->getTimestamp();
		$cases   = array(
			'bad signature'      => array( 'job_inbound', array( 'secret' => 'wrong' ) ),
			'unsigned'           => array( 'job_inbound', array( 'sign' => false ) ),
			'replayed (301 s)'   => array( 'job_inbound', array( 'timestamp' => $now - 301 ) ),
			'future (301 s)'     => array( 'job_inbound', array( 'timestamp' => $now + 301 ) ),
			'not a timestamp'    => array( 'job_inbound', array( 'timestamp' => 'yesterday' ) ),
			'unknown automation' => array( 'job_nothing', array( 'secret' => 'x' ) ),
			'disabled'           => array( 'job_inbound_off', array() ),
			'event automation'   => array( 'job_approved', array( 'secret' => 'x' ) ),
		);
		$answers = array();
		foreach ( $cases as $name => [ $key, $options ] ) {
			$response = $this->inbound( $key, $body, $options );
			$this->assertSame( 401, $response->get_status(), $name );
			$answers[ $name ] = $response->get_data();
		}
		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $answers ) ), 'Every 401 is the same answer.' );
		$this->assertSame( 'pegwork_webhook_unauthorized', $answers['bad signature']['code'] );
		$this->assertStringNotContainsString( 'Nope', wp_json_encode( $answers ) );
		$this->assertSame( array(), $this->runs( 'job_inbound' ) );
		$this->assertSame( array(), $this->runs( 'job_inbound_off' ) );
		$this->assertNull( Secrets::peek( 'job_nothing' ), 'No secret is made for unknown keys.' );
		$this->assertNull( Secrets::peek( 'job_approved' ) );

		// Inside the window, 300 s either way is accepted; a site override enabling the disabled one opens it.
		$this->assertSame( 202, $this->inbound( 'job_inbound', $body, array( 'timestamp' => $now - 300 ) )->get_status() );
		Overrides::set( 'job_inbound_off', 'on' );
		$this->assertSame( 202, $this->inbound( 'job_inbound_off', $body )->get_status() );
	}

	/**
	 * Acceptance: bodies over 64 KB get 413 before decoding, also when only Content-Length says so; a decoded
	 * payload too big to store gets 413 too. Non-JSON bodies get 415.
	 */
	public function test_inbound_413_and_415() {
		$big      = wp_json_encode( array( 'title' => str_repeat( 'a', Inbound::MAX_BYTES ) ) );
		$response = $this->inbound( 'job_inbound', $big );
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'pegwork_webhook_too_large', $response->get_data()['code'] );

		$small = wp_json_encode( array( 'title' => 'x' ) );
		$this->assertSame( 413, $this->inbound( 'job_inbound', $small, array( 'headers' => array( 'Content-Length' => (string) ( Inbound::MAX_BYTES + 1 ) ) ) )->get_status() );

		// Over 64 KB is refused even when it is not valid JSON: the size check comes before decoding.
		$this->assertSame( 413, $this->inbound( 'job_inbound', '{' . str_repeat( 'x', Inbound::MAX_BYTES ) )->get_status() );

		$stored = wp_json_encode( array( 'title' => str_repeat( 'b', Inbound::MAX_PAYLOAD ) ) );
		$this->assertLessThanOrEqual( Inbound::MAX_BYTES, strlen( $stored ) );
		$this->assertSame( 413, $this->inbound( 'job_inbound', $stored )->get_status() );

		$this->assertSame( 415, $this->inbound( 'job_inbound', $small, array( 'type' => 'text/plain' ) )->get_status() );
		$this->assertSame( 415, $this->inbound( 'job_inbound', 'title=x', array( 'type' => 'application/x-www-form-urlencoded' ) )->get_status() );
		$this->assertSame( 202, $this->inbound( 'job_inbound', $small, array( 'type' => 'application/json; charset=utf-8' ) )->get_status() );

		// Signed but not an object or array: 400, no run.
		$this->assertSame( 400, $this->inbound( 'job_inbound', '"just a string"' )->get_status() );
		$this->assertSame( 400, $this->inbound( 'job_inbound', '{not json' )->get_status() );
		$this->assertCount( 1, $this->runs( 'job_inbound' ) );
	}

	/**
	 * Acceptance: 30 requests per minute per automation, then 429 with Retry-After; other keys share a
	 * separate bucket; the next minute is open again.
	 */
	public function test_inbound_429_after_30_a_minute() {
		$body = wp_json_encode( array( 'title' => 'Burst' ) );
		for ( $i = 0; $i < Inbound::RATE; $i++ ) {
			$this->assertSame( 202, $this->inbound( 'job_inbound', $body )->get_status(), "Request $i" );
		}
		$limited = $this->inbound( 'job_inbound', $body );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( '60', $limited->get_headers()['Retry-After'] );
		$this->assertSame( 429, $this->inbound( 'job_inbound', $body, array( 'secret' => 'wrong' ) )->get_status(), 'Bad requests count too.' );
		$this->assertCount( Inbound::RATE, $this->runs( 'job_inbound' ) );

		$this->assertSame( 401, $this->inbound( 'job_nothing', $body, array( 'secret' => 'x' ) )->get_status(), 'Unknown keys have their own bucket.' );

		$this->clock->advance( 'PT1M' );
		$this->assertSame( 202, $this->inbound( 'job_inbound', $body )->get_status() );
	}

	/**
	 * The Automations list shows a webhook automation's inbound URL, never its secret.
	 */
	public function test_admin_list_shows_the_inbound_url_not_the_secret() {
		$secret = Secrets::get( 'job_inbound' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		AutomationList::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Inbound webhook', $html );
		$this->assertStringContainsString( '<code class="pegwork-endpoint">' . esc_html( rest_url( 'pegwork/v1/automations/job_inbound/webhook' ) ) . '</code>', $html );
		$this->assertStringContainsString( 'wp pegwork automation secret job_inbound', $html );
		$this->assertStringContainsString( 'When a signed request arrives', $html );
		$this->assertStringNotContainsString( $secret, $html );
	}

	/**
	 * Outbound with no record (a webhook-triggered run calling out) sends `record: null`; a URL that does
	 * not render to http(s) fails without retry.
	 */
	public function test_record_less_body_and_bad_url() {
		$engine  = Engine::instance();
		$step    = new \PegworkAutomation\Steps\CallWebhook();
		$run     = array(
			'id'           => 7,
			'automation'   => 'job_hook',
			'trigger_type' => 'webhook',
			'context'      => array(),
		);
		$context = new \PegworkAutomation\Context( $engine->clock(), null, array( 'payload' => array( 'u' => 'ftp://x' ) ) );

		$result = $step->run(
			array(
				'id'  => 'out',
				'url' => 'https://receiver.example.org/x',
			),
			$context,
			$run
		);
		$this->assertSame( 'ok', $result->status );
		$this->assertNull( $this->received[0]['body']['record'] );
		$this->assertSame( 'webhook', $this->received[0]['body']['event'] );
		$this->assertSame( '7-out', $this->received[0]['args']['headers']['X-Pegwork-Delivery'] );

		$bad = $step->run(
			array(
				'id'  => 'out',
				'url' => '{{trigger.payload.u}}',
			),
			$context,
			$run
		);
		$this->assertSame( 'failed', $bad->status );
		$this->assertFalse( $bad->retryable );
		$this->assertStringStartsWith( 'invalid_url:', $bad->error );
		$this->assertCount( 1, $this->received );
	}
}
