<?php
/**
 * Manual trigger: the REST start route, the list's Run now, the CLI and the record editor section.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Actions;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Editor\RecordActions;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use PegworkAutomation\StartRoute;
use RuntimeException;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Fixture tests/fixtures/valid/manual: `man_thanks` (gift, editors, `when` amount > 0, one email),
 * `man_receipt` (gift, administrators) and `man_report` (no record, Run now on the list).
 * docs/automation-v3-design.md sections 3.3 and 7, section 8 `AutomationManualTest`.
 */
class AutomationManualTest extends AutomationTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Registers the manual fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/manual';
	}

	/**
	 * Fresh mock mailer and an administrator; the editor from the base class is current.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * A gift.
	 *
	 * @param int $amount Amount.
	 * @return int Record ID.
	 */
	private function gift( $amount = 50 ) {
		$id = Records::create(
			'man_gift',
			array(
				'name'        => 'Gift from Ada',
				'amount'      => $amount,
				'donor_email' => 'ada@example.org',
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Posts to the start route as the current user.
	 *
	 * @param string            $key     Automation key.
	 * @param int               $record  Record ID (0 for none).
	 * @param string|null       $request Request ID (null for a fresh one).
	 * @param string|false|null $nonce   Nonce (null for a valid one, false for none).
	 * @return \WP_REST_Response
	 */
	private function start( $key, $record, $request = null, $nonce = null ) {
		$rest = new WP_REST_Request( 'POST', '/pegwork/v1/automations/' . $key . '/start' );
		$rest->set_body_params(
			array(
				'record_id' => $record,
				'request'   => $request ?? wp_generate_uuid4(),
			)
		);
		if ( false !== $nonce ) {
			$rest->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		}
		return rest_do_request( $rest );
	}

	/**
	 * Error code of a response.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return string
	 */
	private function code( $response ) {
		return (string) ( $response->get_data()['code'] ?? '' );
	}

	/**
	 * Subscribers get 403 on every automation; so do editors on an `admins` automation.
	 */
	public function test_403_for_subscribers_and_for_editors_on_admins() {
		$gift = $this->gift();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( 'man_thanks', 'man_receipt' ) as $key ) {
			$this->assertSame( 403, $this->start( $key, $gift )->get_status(), $key );
		}
		$this->assertSame( 403, $this->start( 'man_report', 0 )->get_status() );

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, $this->start( 'man_receipt', $gift )->get_status() );
		$this->assertSame( 403, $this->start( 'man_report', 0 )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->start( 'man_thanks', $gift )->get_status(), 'Logged out.' );

		$this->assertSame( array(), $this->runs( 'man_thanks' ) );
		$this->assertSame( array(), $this->runs( 'man_receipt' ) );
		$this->assertSame( array(), $this->runs( 'man_report' ) );
	}

	/**
	 * An editor starts an `editors` automation on a gift they can edit: 202, one queued run binding the
	 * gift, payload `user_id` and `source`; it sends one email. A record of another type is refused, and so
	 * is a gift the editor cannot edit (another author's gift, with the editor's edit_others_posts removed).
	 */
	public function test_editor_allowed_on_an_editable_record_and_refused_on_another_type() {
		$gift     = $this->gift( 75 );
		$response = $this->start( 'man_thanks', $gift );
		$this->assertSame( 202, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertFalse( $data['duplicate'] );
		$this->assertSame( 'Resend thank-you started. It runs in the background.', $data['message'] );
		$this->assertNull( $data['url'], 'Only administrators get a link to the run.' );

		$run = $this->only_run( 'man_thanks' );
		$this->assertSame( $data['run'], (int) $run['id'] );
		$this->assertSame( 'manual', $run['trigger_type'] );
		$this->assertSame( $gift, (int) $run['record_id'] );
		$this->assertSame( $this->editor, $run['context']['trigger']['user_id'] );
		$this->assertSame( 'editor', $run['context']['trigger']['source'] );

		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $data['run'] )['status'] );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'ada@example.org', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'Sent again by user ' . $this->editor . ' from editor.', $sent[0]['body'] );

		$note     = Records::create( 'man_note', array( 'name' => 'A note' ), 'private' );
		$response = $this->start( 'man_thanks', $note );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_record_type', $this->code( $response ) );

		$other = self::factory()->post->create(
			array(
				'post_type'   => 'man_gift',
				'post_status' => 'private',
				'post_title'  => 'Someone else\'s gift',
				'post_author' => $this->admin,
			)
		);
		$role  = get_role( 'editor' );
		$role->remove_cap( 'edit_others_posts' );
		$role->remove_cap( 'edit_private_posts' );
		$role->remove_cap( 'edit_others_pages' );
		$role->remove_cap( 'edit_private_pages' );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->editor );
		try {
			$this->assertSame( 403, $this->start( 'man_thanks', $other )->get_status() );
		} finally {
			foreach ( array( 'edit_others_posts', 'edit_private_posts', 'edit_others_pages', 'edit_private_pages' ) as $cap ) {
				$role->add_cap( $cap );
			}
		}
		$this->assertCount( 1, $this->runs( 'man_thanks' ) );
	}

	/**
	 * Administrators may start every manual automation and get a link to the run.
	 */
	public function test_admin_gets_the_run_link() {
		wp_set_current_user( $this->admin );
		$gift     = $this->gift();
		$response = $this->start( 'man_receipt', $gift );
		$this->assertSame( 202, $response->get_status() );
		$run = $response->get_data()['run'];
		$this->assertSame(
			Screen::url(
				array(
					'view' => 'run',
					'run'  => $run,
				)
			),
			$response->get_data()['url']
		);

		$this->assertSame( 202, $this->start( 'man_thanks', $gift )->get_status() );
		$this->assertSame( 202, $this->start( 'man_report', 0 )->get_status() );
		$this->assertSame( 'pegwork_no_record', $this->code( $this->start( 'man_receipt', 0 ) ) );
		$this->assertSame( 'pegwork_no_record', $this->code( $this->start( 'man_report', $gift ) ) );
		$this->assertSame( 404, $this->start( 'man_receipt', 999999 )->get_status() );
	}

	/**
	 * The REST cookie nonce is required: none, or a wrong one, is 403 and starts nothing.
	 */
	public function test_nonce() {
		$gift = $this->gift();
		foreach ( array( false, 'not-a-nonce' ) as $nonce ) {
			$response = $this->start( 'man_thanks', $gift, null, $nonce );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'rest_cookie_invalid_nonce', $this->code( $response ) );
		}
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );
	}

	/**
	 * Ten starts a minute per user, then 429 with Retry-After; the window frees up after a minute; another
	 * user has their own count.
	 */
	public function test_throttle() {
		$gift = $this->gift();
		for ( $i = 0; $i < StartRoute::RATE; $i++ ) {
			$this->assertSame( 202, $this->start( 'man_thanks', $gift )->get_status(), "Start $i" );
		}
		$response = $this->start( 'man_thanks', $gift );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'pegwork_rate_limited', $this->code( $response ) );
		$this->assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
		$this->assertCount( StartRoute::RATE, $this->runs( 'man_thanks' ) );

		wp_set_current_user( $this->admin );
		$this->assertSame( 202, $this->start( 'man_thanks', $gift )->get_status() );

		wp_set_current_user( $this->editor );
		$this->clock->advance( 'PT61S' );
		$this->assertSame( 202, $this->start( 'man_thanks', $gift )->get_status() );
	}

	/**
	 * A double submit of one dialog (same request ID) starts one run: 202, then 200 with the same run.
	 * Another dialog (another request ID) starts another run; the same ID from another user is theirs.
	 */
	public function test_double_submit_dedupe() {
		$gift   = $this->gift();
		$first  = $this->start( 'man_thanks', $gift, 'dialog-0001' );
		$second = $this->start( 'man_thanks', $gift, 'dialog-0001' );
		$this->assertSame( 202, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['duplicate'] );
		$this->assertSame( $first->get_data()['run'], $second->get_data()['run'] );
		$this->assertCount( 1, $this->runs( 'man_thanks' ) );
		$this->assertSame( 'manual:' . $this->editor . ':dialog-0001', $this->only_run( 'man_thanks' )['idempotency_key'] );

		$this->assertSame( 202, $this->start( 'man_thanks', $gift, 'dialog-0002' )->get_status() );
		wp_set_current_user( $this->admin );
		$this->assertSame( 202, $this->start( 'man_thanks', $gift, 'dialog-0001' )->get_status() );
		$this->assertCount( 3, $this->runs( 'man_thanks' ) );

		$this->assertSame( 400, $this->start( 'man_thanks', $gift, 'short' )->get_status(), 'Request IDs are 8 to 64 letters, digits and hyphens.' );
	}

	/**
	 * A false `when` is refused with "This automation does not apply to this record" and starts nothing.
	 */
	public function test_when_refusal() {
		$gift     = $this->gift( 0 );
		$response = $this->start( 'man_thanks', $gift );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'automation_not_applicable', $this->code( $response ) );
		$this->assertSame( 'This automation does not apply to this record.', $response->get_data()['message'] );
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );
	}

	/**
	 * A disabled automation is refused (409) and no longer listed on the record editor; unknown and
	 * non-manual keys get the same 403 as a refused user, so the route does not reveal which keys exist.
	 */
	public function test_disabled_and_unknown() {
		$gift = $this->gift();
		wp_set_current_user( $this->admin );
		Overrides::toggle( 'man_thanks', 'off' );
		$response = $this->start( 'man_thanks', $gift );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'automation_disabled', $this->code( $response ) );
		$this->assertSame( array( 'man_receipt' ), wp_list_pluck( RecordActions::automations( get_post( $gift ) ), 'key' ) );

		$this->assertSame( 403, $this->start( 'nope', $gift )->get_status() );
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );
	}

	/**
	 * The record editor lists the automations for the record's type that the user may start: editors see
	 * `editors` ones only; administrators see all; other types see none.
	 */
	public function test_record_editor_lists_what_the_user_may_run() {
		$gift = get_post( $this->gift() );
		$this->assertSame(
			array(
				array(
					'key'    => 'man_thanks',
					'button' => 'Resend thank-you',
					'path'   => '/pegwork/v1/automations/man_thanks/start',
				),
			),
			RecordActions::automations( $gift )
		);

		wp_set_current_user( $this->admin );
		$this->assertSame( array( 'man_receipt', 'man_thanks' ), wp_list_pluck( RecordActions::automations( $gift ), 'key' ), 'Registry order (files load sorted).' );
		$this->assertSame( array(), RecordActions::automations( get_post( Records::create( 'man_note', array( 'name' => 'A note' ), 'private' ) ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), RecordActions::automations( $gift ) );
	}

	/**
	 * The list's Run now starts a no-record manual automation (source `list`), once per page view's request
	 * ID, and redirects to the run; the list shows Run now for it only, and who can run each.
	 */
	public function test_list_run_now() {
		wp_set_current_user( $this->admin );
		$_GET = array();
		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'name="automation" value="man_report"><input type="hidden" name="request"' ) );
		$this->assertStringNotContainsString( 'name="automation" value="man_thanks"><input type="hidden" name="request"', $html );
		$this->assertStringContainsString( 'Started by staff: &quot;Send giving report&quot; (Run now)', $html );
		$this->assertStringContainsString( 'Editors can run', $html );
		$this->assertStringContainsString( 'When staff start it', $html );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test signal, never printed.
			}
		);
		$post = function ( $key, $request ) {
			$fields   = array(
				'action'     => Actions::RUN,
				'automation' => $key,
				'request'    => $request,
				'_wpnonce'   => wp_create_nonce( Actions::RUN . '_' . $key ),
			);
			$_POST    = $fields;
			$_REQUEST = $fields;
			try {
				Actions::run();
			} catch ( RuntimeException $e ) {
				return substr( $e->getMessage(), strlen( 'redirect:' ) );
			}
			$this->fail( 'The handler did not redirect.' );
		};

		$location = $post( 'man_report', 'list-request-1' );
		$run      = $this->only_run( 'man_report' );
		$this->assertSame(
			Screen::url(
				array(
					'view' => 'run',
					'run'  => (int) $run['id'],
				)
			),
			$location
		);
		$this->assertNull( $run['record_id'] );
		$this->assertSame( 'list', $run['context']['trigger']['source'] );
		$this->assertSame( $this->admin, $run['context']['trigger']['user_id'] );

		$post( 'man_report', 'list-request-1' );
		$this->assertCount( 1, $this->runs( 'man_report' ), 'A double submit starts one run.' );

		$this->assertSame( Screen::url(), $post( 'man_thanks', 'list-request-2' ), 'A manual automation with a content type needs a record.' );
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );

		$this->drain();
		$this->assertSame( 'completed', $this->run_row( (int) $run['id'] )['status'] );
		$this->assertSame( '0 gifts', tests_retrieve_phpmailer_instance()->mock_sent[0]['subject'] );
	}

	/**
	 * `wp pegwork automation run` starts manual automations: source `cli`, the record type checked, `when`
	 * checked, and a no-record one without --record.
	 */
	public function test_cli_run() {
		wp_set_current_user( $this->admin );
		$gift   = $this->gift();
		$manual = new Manual( $this->clock );
		$result = $manual->run( 'man_thanks', $gift );
		$manual->finish();
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['runs'] );
		$run = $this->run_row( $result['runs'][0] );
		$this->assertSame( 'cli', $run['context']['trigger']['source'] );
		$this->assertSame( 'completed', $run['status'], 'A clock implies running in process.' );

		$this->assertSame( 'pegwork_no_record', ( new Manual() )->run( 'man_thanks' )->get_error_code() );
		$this->assertSame( 'pegwork_record_type', ( new Manual() )->run( 'man_thanks', Records::create( 'man_note', array( 'name' => 'N' ), 'private' ) )->get_error_code() );
		$this->assertSame( 'automation_not_applicable', ( new Manual() )->run( 'man_thanks', $this->gift( 0 ) )->get_error_code() );

		$result = ( new Manual() )->run( 'man_report' );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['runs'] );
		$this->assertSame( 'pegwork_no_record', ( new Manual() )->run( 'man_report', $gift )->get_error_code() );
		$this->assertCount( 2, Engine::instance()->runs()->find( null, null, 100 ) );
	}
}
