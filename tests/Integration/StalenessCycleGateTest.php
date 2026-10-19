<?php
/**
 * Phase 4 gate: the SI for Good staleness cycle on a frozen clock.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Links\Handler;
use PegworkAutomation\Schema;
use WP_REST_Request;

require_once dirname( __DIR__ ) . '/Support/AutomationTestCase.php';

/**
 * The examples/sifg package end to end (design section 9): approval, the 12-month "still active?" email, the 30-day
 * pause with a fresh link, reactivation by the original link, a Project that replied in time, and tampered
 * and expired tokens. The clock is frozen and moved by hand; wake-ups go through the fake scheduler; emails
 * through the test suite's mock PHPMailer; clicks through the link handler as an anonymous visitor.
 */
class StalenessCycleGateTest extends AutomationTestCase {

	/**
	 * Registers examples/sifg instead of the engine fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/examples/sifg';
	}

	/**
	 * Fresh mock mailer per test.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * The whole cycle, in order, as the design's gate describes it.
	 */
	public function test_staleness_cycle() {
		// 1. 2026-11-02 09:00: a contributor submits two Projects; an editor publishes them over REST.
		$alpha = $this->submit( 'Alpha Garden', 'alpha@example.org', true );
		$beta  = $this->submit( 'Beta Library', 'beta@example.org', false );
		$this->publish( $alpha );
		$this->publish( $beta );
		$this->tick();

		$this->assertSame( '2026-11-02', $this->value( $alpha, 'last_verified' ) );
		$this->assertSame( '2026-11-02', $this->value( $beta, 'last_verified' ) );
		$mail = $this->mail_to( 'alpha@example.org' );
		$this->assertCount( 1, $mail );
		$this->assertSame( 'Alpha Garden is live on Test Blog', $mail[0]['subject'] );
		$this->assertStringContainsString( get_permalink( $alpha ), $mail[0]['body'] );
		$this->assertStringContainsString( 'Last verified: 2026-11-02', $mail[0]['body'] );

		// 2. 2027-11-01: not yet 12 months. 2027-11-02: one email each, with a link; the runs wait 30 days.
		$this->travel( '2027-11-01 09:00' );
		$this->assertCount( 2, $this->sent(), 'Nothing new a day before the anniversary.' );
		$this->assertSame( array(), $this->runs( 'sifg_staleness_check' ) );

		$this->travel( '2027-11-02 09:00' );
		$ask = $this->mail_to( 'alpha@example.org' );
		$this->assertCount( 2, $ask );
		$this->assertSame( 'Is Alpha Garden still active?', $ask[1]['subject'] );
		$alpha_link = $this->token( $ask[1]['body'] );
		$beta_link  = $this->token( $this->mail_to( 'beta@example.org' )[1]['body'] );

		$runs = $this->runs( 'sifg_staleness_check' );
		$this->assertCount( 2, $runs );
		foreach ( $runs as $run ) {
			$this->assertSame( 'waiting', $run['status'] );
			$this->assertSame( 'still_stale', $run['current_step'] );
			$this->assertSame( '2027-12-02 09:00:00', $run['resume_at'] );
		}
		$this->assertSame( "$alpha:2026-11-02", $runs[0]['idempotency_key'] );

		$this->travel( '2027-11-03 09:00' );
		$this->assertCount( 2, $this->mail_to( 'alpha@example.org' ), 'The sweep the next day sends no second email.' );
		$this->assertCount( 2, $this->runs( 'sifg_staleness_check' ) );

		// 5 (during the wait). 2027-11-15: Beta's owner opens the link, sees the confirm page, presses the button.
		$this->clock->set( '2027-11-15 10:00' );
		$page = $this->click( 'GET', $beta_link );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( '<h1>Please confirm</h1>', $page['body'] );
		$this->assertStringContainsString( 'Yes, it is still active', $page['body'] );
		$this->assertStringContainsString( 'Beta Library', $page['body'] );
		$this->assertStringContainsString( 'method="post"', $page['body'] );
		$this->assertStringNotContainsString( 'beta@example.org', $page['body'] );
		$this->assertSame( '2026-11-02', $this->value( $beta, 'last_verified' ), 'Opening the link changes nothing.' );
		$this->assertSame( array(), $this->link_runs(), 'Opening the link starts no run.' );

		$beta_nonce = $this->nonce( $page['body'] );
		$page       = $this->click( 'POST', $beta_link, $beta_nonce );
		$this->assertSame( 200, $page['status'] );
		$this->assertStringContainsString( '<h1>Thank you</h1>', $page['body'] );
		$this->assertStringContainsString( 'Your listing stays live on Test Blog.', $page['body'] );
		$this->assertSame( '2027-11-15', $this->value( $beta, 'last_verified' ) );
		$this->assertSame( array( 'active' ), $this->value( $beta, 'sifg_project_status' ) );

		$link_run = $this->only( $this->link_runs() );
		$this->assertSame( 'link', $link_run['trigger_type'] );
		$this->assertSame( $beta, $link_run['record_id'] );
		$this->assertSame( 'completed', $link_run['status'] );
		$this->assertSame( 'stopped_at:was_paused', $link_run['context']['outcome'], 'Not paused, so nothing to restore.' );
		$this->assertSame( array( 'verify', 'was_paused' ), array_column( $this->steps( $link_run['id'] ), 'step_id' ), 'Only the link\'s own steps run.' );

		$again = $this->click( 'POST', $beta_link, $beta_nonce );
		$this->assertStringContainsString( '<h1>Already done</h1>', $again['body'] );
		$this->assertStringContainsString( '<h1>Already done</h1>', $this->click( 'GET', $beta_link )['body'] );
		$this->assertCount( 1, $this->link_runs(), 'A second click runs nothing.' );

		// 3. 2027-12-02: Alpha did not reply and is paused, with a fresh link; Beta's run stops.
		$this->travel( '2027-12-02 09:00' );
		$this->assertSame( array( 'paused' ), $this->value( $alpha, 'sifg_project_status' ) );
		$this->assertSame( '2027-12-02', $this->value( $alpha, 'paused_on' ) );
		$this->assertSame( 'active', $this->value( $alpha, 'paused_from' ) );
		$this->assertTrue( $this->value( $alpha, 'paused_volunteers' ) );
		$this->assertFalse( $this->value( $alpha, 'needs_volunteers' ) );
		$this->assertSame( 'publish', Records::get( $alpha )['status'], 'Paused is the Status taxonomy; the post stays published.' );

		$tell = $this->mail_to( 'alpha@example.org' );
		$this->assertCount( 3, $tell );
		$this->assertSame( 'Alpha Garden is paused on Test Blog', $tell[2]['subject'] );
		$fresh_link = $this->token( $tell[2]['body'] );
		$this->assertNotSame( $alpha_link, $fresh_link, 'The pause notice carries a freshly minted link.' );

		[ $alpha_run, $beta_run ] = $this->runs( 'sifg_staleness_check' );
		$this->assertSame( 'completed', $alpha_run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $alpha_run['context'] );
		$this->assertSame( 'completed', $beta_run['status'] );
		$this->assertSame( 'stopped_at:still_stale', $beta_run['context']['outcome'] );
		$this->assertSame( array( 'active' ), $this->value( $beta, 'sifg_project_status' ) );
		$this->assertCount( 2, $this->mail_to( 'beta@example.org' ), 'Beta got no pause notice.' );

		wp_set_current_user( 0 );
		$public = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/projects/' . $alpha ) );
		$this->assertSame( 200, $public->get_status() );
		$paused = get_term_by( 'slug', 'paused', 'sifg_project_status' );
		$this->assertSame( array( $paused->term_id ), $public->get_data()['sifg_project_status'] );
		$json = (string) wp_json_encode( $public->get_data() );
		foreach ( array( 'alpha@example.org', 'contact_email', 'paused_from', 'paused_on', 'paused_volunteers' ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $json, "Anonymous REST shows no $hidden." );
		}

		// 4. 2028-01-15: the original link (sent 2027-11-02, valid 365 days) reactivates Alpha.
		$this->clock->set( '2028-01-15 08:30' );
		$page = $this->click( 'GET', $alpha_link );
		$this->assertStringContainsString( 'Alpha Garden', $page['body'] );
		$page = $this->click( 'POST', $alpha_link, $this->nonce( $page['body'] ) );
		$this->assertStringContainsString( '<h1>Thank you</h1>', $page['body'] );
		$this->assertSame( '2028-01-15', $this->value( $alpha, 'last_verified' ) );
		$this->assertSame( array( 'active' ), $this->value( $alpha, 'sifg_project_status' ) );
		$this->assertTrue( $this->value( $alpha, 'needs_volunteers' ) );
		$this->assertSame( '', $this->value( $alpha, 'paused_from' ) );
		$this->assertSame( '', $this->value( $alpha, 'paused_on' ) );
		$this->assertFalse( $this->value( $alpha, 'paused_volunteers' ) );
		$alpha_click = $this->link_runs()[1];
		$this->assertSame( array( 'verify', 'was_paused', 'restore' ), array_column( $this->steps( $alpha_click['id'] ), 'step_id' ) );
		$this->assertArrayNotHasKey( 'outcome', $alpha_click['context'] );

		// The next sweep leaves both alone: Last verified moved, so the next cycle starts a year later.
		$this->travel( '2028-01-16 09:00' );
		$this->assertCount( 2, array_filter( $this->runs( 'sifg_staleness_check' ), fn( $run ) => 'schedule' === $run['trigger_type'] ) );

		// 6. Tampered and expired tokens change nothing.
		$before = array( Records::get( $alpha ), Records::get( $beta ) );
		$runs   = count( $this->link_runs() );

		[ $version, $payload, $signature ] = explode( '.', $fresh_link );
		// Alpha's genuine signature on a payload naming Beta, with a later expiry.
		$claims   = array_combine( array( 'a', 'l', 'r', 'e' ), array( 'sifg_staleness_check', 'still_active', $beta, 2000000000 ) );
		$forged   = $version . '.' . rtrim( strtr( base64_encode( (string) wp_json_encode( $claims ) ), '+/', '-_' ), '=' ) . '.' . $signature; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Forging a token payload.
		$tampered = array(
			$forged,
			$version . '.' . $payload . '.' . strrev( $signature ),
			substr( $fresh_link, 0, -1 ),
			'not-a-token',
			'',
		);
		foreach ( $tampered as $bad ) {
			foreach ( array( 'GET', 'POST' ) as $method ) {
				$page = $this->click( $method, $bad, wp_create_nonce( 'pegwork_link_' . hash( 'sha256', $bad ) ) );
				$this->assertSame( 403, $page['status'] );
				$this->assertStringContainsString( '<h1>This link does not work</h1>', $page['body'] );
				$this->assertStringNotContainsString( '<form', $page['body'] );
			}
		}

		$this->clock->set( '2028-12-02 09:00' ); // The pause notice's link was sent 2027-12-02 09:00, valid 365 days.
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$page = $this->click( $method, $fresh_link, wp_create_nonce( 'pegwork_link_' . hash( 'sha256', $fresh_link ) ) );
			$this->assertSame( 410, $page['status'] );
			$this->assertStringContainsString( '<h1>This link has expired</h1>', $page['body'] );
		}
		$this->assertSame( $before, array( Records::get( $alpha ), Records::get( $beta ) ) );
		$this->assertCount( $runs, $this->link_runs() );

		// Every run and step is in the log, none failed, and no token reached the log.
		global $wpdb;
		$all = array_merge( $this->runs( 'sifg_project_approved' ), $this->runs( 'sifg_staleness_check' ) );
		$this->assertCount( 2 + 2 + 2, $all );
		foreach ( $all as $run ) {
			$this->assertSame( 'completed', $run['status'], "Run {$run['id']} completed." );
			$this->assertNull( $run['error'] );
			foreach ( $this->steps( $run['id'] ) as $step ) {
				$this->assertSame( 'ok', $step['status'], "Step {$step['step_id']} of run {$run['id']} is ok." );
			}
		}
		$runs_table  = Schema::runs();
		$steps_table = Schema::steps();
		foreach ( array( $alpha_link, $beta_link, $fresh_link ) as $token ) {
			$like = '%' . $wpdb->esc_like( $token ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test reads the module's tables (fixed names).
			$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$runs_table} r LEFT JOIN {$steps_table} s ON s.run_id = r.id WHERE r.context LIKE %s OR s.summary LIKE %s", $like, $like ) );
			$this->assertSame( 0, $found, 'No token in the run log.' );
		}
	}

	/**
	 * A Project submitted by a contributor (pending).
	 *
	 * @param string $name       Name.
	 * @param string $email      Contact email.
	 * @param bool   $volunteers Needs volunteers.
	 * @return int Post ID.
	 */
	private function submit( $name, $email, $volunteers ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$id = Records::create(
			'sifg_project',
			array(
				'name'                => $name,
				'description'         => str_repeat( 'Neighbours share tools, seeds and time to grow food together. ', 6 ),
				'contact_email'       => $email,
				'needs_volunteers'    => $volunteers,
				'volunteer_link'      => $volunteers ? 'https://example.org/volunteer' : '',
				'sifg_project_type'   => array( 'nonprofit' ),
				'sifg_project_status' => array( 'active' ),
			),
			'pending'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		wp_set_current_user( $this->editor );
		return $id;
	}

	/**
	 * Publishes a Project over REST as the editor.
	 *
	 * @param int $id Post ID.
	 */
	private function publish( $id ) {
		wp_set_current_user( $this->editor );
		$request = new WP_REST_Request( 'POST', '/wp/v2/projects/' . $id );
		$request->set_body_params( array( 'status' => 'publish' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Moves the clock and fires every due schedule firing, sweep and run wake-up.
	 *
	 * @param string $at Site time.
	 */
	private function travel( $at ) {
		$this->clock->set( $at );
		$this->tick();
	}

	/**
	 * Fires due wake-ups at the clock's time.
	 */
	private function tick() {
		return $this->scheduler->drain( $this->clock->now()->getTimestamp(), array( Engine::SCHEDULE_HOOK, Engine::SWEEP_HOOK, Engine::RUN_HOOK ) );
	}

	/**
	 * A click on a link by an anonymous visitor.
	 *
	 * @param string $method GET or POST.
	 * @param string $token  Token.
	 * @param string $nonce  Form nonce (POST).
	 * @return array{status: int, body?: string, location?: string}
	 */
	private function click( $method, $token, $nonce = '' ) {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$response = Handler::respond( $method, $token, $nonce, '192.0.2.10' );
		wp_set_current_user( $user );
		return $response;
	}

	/**
	 * The nonce in a confirm page's form.
	 *
	 * @param string $body Page HTML.
	 */
	private function nonce( $body ) {
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $body, $m ), 'The confirm page has a nonce.' );
		return $m[1];
	}

	/**
	 * The token in an email body.
	 *
	 * @param string $body Email text.
	 */
	private function token( $body ) {
		$this->assertSame( 1, preg_match( '#^http://example\.org/\?pegwork_link=(v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\r?$#m', $body, $m ), "The email carries a signed link on its own line:\n$body" );
		return $m[1];
	}

	/**
	 * Every email sent so far.
	 *
	 * @return array<int, array{to: string, subject: string, body: string}>
	 */
	private function sent() {
		return array_map(
			fn( $mail ) => array(
				'to'      => $mail['to'][0][0],
				'subject' => $mail['subject'],
				'body'    => $mail['body'],
			),
			tests_retrieve_phpmailer_instance()->mock_sent
		);
	}

	/**
	 * Emails sent to an address, oldest first.
	 *
	 * @param string $address Address.
	 * @return array<int, array{to: string, subject: string, body: string}>
	 */
	private function mail_to( $address ) {
		return array_values( array_filter( $this->sent(), fn( $mail ) => $address === $mail['to'] ) );
	}

	/**
	 * Link runs of the staleness automation, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function link_runs() {
		return array_values( array_filter( $this->runs( 'sifg_staleness_check' ), fn( $run ) => 'link' === $run['trigger_type'] ) );
	}

	/**
	 * The only item of a list.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return mixed
	 */
	private function only( $items ) {
		$this->assertCount( 1, $items );
		return $items[0];
	}
}
