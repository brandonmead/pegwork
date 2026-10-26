<?php
/**
 * Integration tests: bulk "Run automation" on core list tables.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Api\Throttle;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\BulkRun;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Overrides;
use PegworkAutomation\StartRoute;
use PegworkAutomation\Triggers;
use RuntimeException;
use WPDieException;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Automation v4 PR 7 (docs/automation-v4-design.md sections 4 and 7, `AutomationBulkTest`). Fixture
 * tests/fixtures/valid/manual: `man_thanks` (gift, editors, `when` amount > 0, one email), `man_receipt`
 * (gift, administrators) and `man_report` (no record); the trash exclusion adds
 * tests/fixtures/valid/engine-trash (`tr_bound`, volunteers, editors).
 */
class AutomationBulkTest extends AutomationTestCase {

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
	 * Fresh mail mock, an administrator, and a redirect that ends the handler with a catchable signal.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test signal, never printed.
			}
		);
	}

	/**
	 * Clears request globals.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * A gift.
	 *
	 * @param int    $amount Amount.
	 * @param string $name   Title.
	 * @return int Record ID.
	 */
	private function gift( $amount = 50, $name = 'Gift from Ada' ) {
		$id = Records::create(
			'man_gift',
			array(
				'name'        => $name,
				'amount'      => $amount,
				'donor_email' => 'ada@example.org',
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * The confirmation screen's HTML.
	 *
	 * @param string $key Automation key.
	 * @param int[]  $ids Record IDs.
	 * @return string
	 */
	private function screen( $key, array $ids ) {
		$_GET = array(
			'page'       => BulkRun::PAGE,
			'automation' => $key,
			'records'    => implode( ',', $ids ),
			'back'       => '/wp-admin/edit.php?post_type=man_gift&m=202611', // Core's referer is a path.
		);
		ob_start();
		try {
			BulkRun::render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Posts the confirmation form; returns where it redirected.
	 *
	 * @param string      $key     Automation key.
	 * @param int[]       $ids     Record IDs.
	 * @param string      $request Request ID.
	 * @param string|null $nonce   Nonce (null for a valid one).
	 * @return string
	 */
	private function submit( $key, array $ids, $request = 'bulk-request-1', $nonce = null ) {
		$fields   = array(
			'action'     => BulkRun::POST,
			'automation' => $key,
			'records'    => implode( ',', $ids ),
			'request'    => $request,
			'back'       => admin_url( 'edit.php?post_type=man_gift&m=202611' ),
			'_wpnonce'   => $nonce ?? wp_create_nonce( BulkRun::NONCE . $key ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;
		try {
			BulkRun::submit();
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * The list notice the next list screen prints.
	 *
	 * @return string
	 */
	private function notice() {
		set_current_screen( 'edit-man_gift' );
		ob_start();
		BulkRun::notice();
		return (string) ob_get_clean();
	}

	/**
	 * The code of the wp_die() a callable ends in.
	 *
	 * @param callable $call Call.
	 * @return int
	 */
	private function dies( callable $call ) {
		ob_start();
		try {
			$call();
		} catch ( WPDieException $e ) {
			return (int) $e->getCode();
		} finally {
			ob_end_clean();
		}
		$this->fail( 'Expected wp_die().' );
	}

	/**
	 * The Bulk actions menu: administrators see every eligible automation of the type, editors the
	 * `editors` ones, subscribers none; disabled automations, automations without a content type and other
	 * types are not offered.
	 */
	public function test_menu_per_role() {
		$core = array( 'edit' => 'Edit' );

		wp_set_current_user( $this->admin );
		$this->assertSame(
			array(
				'edit'                          => 'Edit',
				BulkRun::ACTION . 'man_receipt' => 'Run: Mark receipted',
				BulkRun::ACTION . 'man_thanks'  => 'Run: Resend thank-you',
			),
			BulkRun::bulk_actions( $core, 'man_gift' )
		);
		$this->assertSame( $core, BulkRun::bulk_actions( $core, 'man_note' ) );

		wp_set_current_user( $this->editor );
		$this->assertSame( array( 'man_thanks' ), array_keys( BulkRun::automations( 'man_gift' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( $core, BulkRun::bulk_actions( $core, 'man_gift' ) );

		wp_set_current_user( $this->admin );
		$this->assertTrue( ! ( Overrides::toggle( 'man_thanks', 'off' ) instanceof \WP_Error ) );
		$this->assertSame( array( 'man_receipt' ), array_keys( BulkRun::automations( 'man_gift' ) ), 'A disabled automation is not offered.' );

		// load-edit.php hooks the menu and the handler for each type with an eligible automation.
		BulkRun::hook_list();
		$this->assertTrue( has_filter( 'bulk_actions-edit-man_gift' ) );
		$this->assertTrue( has_filter( 'handle_bulk_actions-edit-man_gift' ) );
		$this->assertFalse( has_filter( 'bulk_actions-edit-man_note' ) );
	}

	/**
	 * Step 1 (after core's `bulk-posts` nonce, which edit.php checks before it fires the handler filter):
	 * other actions pass through; 51 IDs go back to the list with "Select at most 50 records" and start
	 * nothing; up to 50 redirect to the confirmation screen; an automation the user may not run is refused.
	 */
	public function test_step_one() {
		wp_set_current_user( $this->editor );
		$list  = admin_url( 'edit.php?post_type=man_gift&paged=1' );
		$gifts = array( $this->gift(), $this->gift(), $this->gift() );

		$this->assertSame( $list, BulkRun::handle( $list, 'trash', $gifts, 'man_gift' ) );

		$this->assertSame( $list, BulkRun::handle( $list, BulkRun::ACTION . 'man_thanks', range( 1, 51 ), 'man_gift' ) );
		$this->assertStringContainsString( 'Select at most 50 records to run an automation on. Nothing was started.', $this->notice() );

		$url = BulkRun::handle( $list, BulkRun::ACTION . 'man_thanks', array_merge( $gifts, array( 'x', -4, $gifts[0] ) ), 'man_gift' );
		$this->assertStringStartsWith( admin_url( 'admin.php?page=' . BulkRun::PAGE ), $url );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'man_thanks', $query['automation'] );
		$this->assertSame( implode( ',', $gifts ), $query['records'], 'Integers only, each once.' );
		$this->assertSame( $list, $query['back'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $query['request'], 'One request ID per Apply.' );

		$this->assertSame( $list, BulkRun::handle( $list, BulkRun::ACTION . 'man_receipt', $gifts, 'man_gift' ), 'Editors cannot run an administrators automation.' );
		$this->assertStringContainsString( 'Sorry, you are not allowed to run this automation.', $this->notice() );
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );
	}

	/**
	 * The confirmation screen: heading, caption, row headers, "Will run", "Does not apply" (its `when`),
	 * "You cannot run it on this record" (no `edit_post`), titles only with `read_post`, a button counting
	 * the records that will run, Cancel back to the filtered list, the nonce and a request ID. Its GET
	 * starts nothing. Subscribers and unknown keys get 403.
	 */
	public function test_confirmation_screen() {
		$run     = $this->gift( 50, 'Gift from Ada' );
		$zero    = $this->gift( 0, 'Gift from Bo' );
		$locked  = $this->gift( 70, 'Gift from Cy' );
		$private = $this->gift( 90, 'Secret gift' );
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap, $user, $args ) use ( $locked, $private ) {
				if ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $locked ) {
					return array( 'do_not_allow' );
				}
				if ( 'read_post' === $cap && (int) ( $args[0] ?? 0 ) === $private ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			},
			10,
			4
		);
		wp_set_current_user( $this->editor );

		$missing = $private + 1000;
		$html    = $this->screen( 'man_thanks', array( $run, $zero, $locked, $private, $missing ) );
		$this->assertStringContainsString( '<h1 tabindex="-1" autofocus>Run &quot;Resend thank-you&quot; on 5 Gifts?</h1>', $html );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Selected Gifts</caption>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Gift from Ada</th><td>Private</td><td>Will run</td></tr>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Gift from Bo</th><td>Private</td><td>Does not apply</td></tr>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Gift from Cy</th><td>Private</td><td>You cannot run it on this record</td></tr>', $html );
		// QA SEC-3: an unreadable record looks exactly like a missing one (no title, no status, cannot run).
		$this->assertStringContainsString( '<tr><th scope="row">Gift ' . $private . '</th><td></td><td>You cannot run it on this record</td></tr>', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Gift ' . $missing . '</th><td></td><td>You cannot run it on this record</td></tr>', $html );
		$this->assertStringNotContainsString( 'Secret gift', $html, 'No title without read_post.' );
		$this->assertStringContainsString( '<button type="submit" class="button button-primary">Run on 1 Gift</button>', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'edit.php?post_type=man_gift&m=202611' ) ) . '">Cancel</a>', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertMatchesRegularExpression( '/name="request" value="[0-9a-f-]{36}"/', $html, 'Without one from step 1, a new request ID.' );
		$this->assertSame( array(), $this->runs( 'man_thanks' ), 'The GET starts nothing.' );
		$_GET['request'] = 'apply-0001';
		ob_start();
		BulkRun::render();
		$this->assertStringContainsString( 'name="request" value="apply-0001"', (string) ob_get_clean(), 'A reload keeps the request of the Apply.' );

		$this->assertStringContainsString( 'It runs on none of these records.', $this->screen( 'man_thanks', array( $zero ) ) );
		$this->assertStringContainsString( 'Select at most 50 records', $this->screen( 'man_thanks', range( 1, 51 ) ) );

		$this->assertSame( 403, $this->dies( fn() => $this->screen( 'man_receipt', array( $run ) ) ), 'Editors on an administrators automation.' );
		$this->assertSame( 403, $this->dies( fn() => $this->screen( 'man_report', array( $run ) ) ), 'No content type: not a bulk automation.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->dies( fn() => $this->screen( 'man_thanks', array( $run ) ) ) );
		$this->assertSame( 403, $this->dies( fn() => $this->submit( 'man_thanks', array( $run ) ) ) );
	}

	/**
	 * The POST: a bad nonce dies (403) and starts nothing; each record that will run gets one run with
	 * source `bulk` and the key `manual:<user>:<request>-<record>`; "Does not apply" and "You cannot run it"
	 * start nothing; the notice counts them; a resubmit of the same screen starts nothing new; the whole
	 * request is one throttle hit, and past 10 a minute the POST is 429.
	 */
	public function test_post_keys_dedupe_and_throttle() {
		$a      = $this->gift( 50, 'Gift from Ada' );
		$b      = $this->gift( 80, 'Gift from Bea' );
		$zero   = $this->gift( 0, 'Gift from Bo' );
		$locked = $this->gift( 70, 'Gift from Cy' );
		add_filter(
			'map_meta_cap',
			fn( $caps, $cap, $user, $args ) => 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $locked ? array( 'do_not_allow' ) : $caps,
			10,
			4
		);
		wp_set_current_user( $this->editor );

		$this->assertSame( 403, $this->dies( fn() => $this->submit( 'man_thanks', array( $a ), 'bulk-request-1', 'bad' ) ) );
		$this->assertSame( array(), $this->runs( 'man_thanks' ) );

		$back = $this->submit( 'man_thanks', array( $a, $b, $zero, $locked ) );
		$this->assertSame( admin_url( 'edit.php?post_type=man_gift&m=202611' ), $back, 'Back to the filtered list.' );
		$runs = $this->runs( 'man_thanks' );
		$this->assertSame( array( $a, $b ), array_map( fn( $run ) => (int) $run['record_id'], $runs ) );
		foreach ( $runs as $run ) {
			$this->assertSame( 'manual', $run['trigger_type'] );
			$this->assertSame( 'bulk', $run['context']['trigger']['source'] );
			$this->assertSame( $this->editor, $run['context']['trigger']['user_id'] );
			$this->assertSame( Triggers::request_key( $this->editor, 'bulk-request-1-' . $run['record_id'] ), $run['idempotency_key'] );
			$this->assertSame( 'manual:' . $this->editor . ':bulk-request-1-' . $run['record_id'], $run['idempotency_key'] );
		}
		$notice = $this->notice();
		$this->assertStringContainsString( 'Started 2 runs. 1 did not apply. You cannot run it on 1 record.', $notice );
		$this->assertStringNotContainsString( 'View runs', $notice, 'Editors get no run links.' );
		$this->assertSame( '', $this->notice(), 'One-time.' );

		$this->submit( 'man_thanks', array( $a, $b, $zero, $locked ) );
		$this->assertCount( 2, $this->runs( 'man_thanks' ), 'A resubmit of the same screen starts nothing new.' );
		$this->assertStringContainsString( 'Started 0 runs. 1 did not apply. You cannot run it on 1 record. 2 were already started from this screen.', $this->notice() );

		$this->drain();
		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent, 'One email per run.' );

		// Two requests so far, each one hit of the per-user bucket: 8 more fit, the next is 429.
		$now = $this->clock->now()->getTimestamp();
		for ( $i = 0; $i < StartRoute::RATE - 2; $i++ ) {
			$this->assertSame( 0, Throttle::take( 'automation|manual|' . $this->editor, StartRoute::RATE, StartRoute::WINDOW, $now ), "Hit $i" );
		}
		$this->assertSame( 429, $this->dies( fn() => $this->submit( 'man_thanks', array( $a ), 'bulk-request-2' ) ) );
		$this->assertCount( 2, $this->runs( 'man_thanks' ) );
		$this->clock->advance( 'PT61S' );
		$this->submit( 'man_thanks', array( $a ), 'bulk-request-2' );
		$this->assertCount( 3, $this->runs( 'man_thanks' ) );

		$this->assertSame( admin_url( 'edit.php?post_type=man_gift&m=202611' ), $this->submit( 'man_thanks', range( 1, 51 ), 'bulk-request-3' ) );
		$this->assertStringContainsString( 'Nothing was started.', $this->notice() );
		$this->assertCount( 3, $this->runs( 'man_thanks' ) );
	}

	/**
	 * Administrators run any eligible automation, get "View runs" (the run log filtered to the automation),
	 * and the Automations list says staff can also run it on selected records.
	 */
	public function test_admin_notice_link_and_list_row() {
		wp_set_current_user( $this->admin );
		$gift = $this->gift();
		$this->submit( 'man_receipt', array( $gift ) );
		$this->assertCount( 1, $this->runs( 'man_receipt' ) );
		$notice = $this->notice();
		$this->assertStringContainsString( 'Started 1 run.', $notice );
		$this->assertStringContainsString(
			'<a href="' . esc_url(
				Screen::url(
					array(
						'view'       => 'log',
						'automation' => 'man_receipt',
					)
				)
			) . '">View runs</a>',
			$notice
		);

		$_GET = array();
		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $html, 'Staff can also run it on selected Gifts' ), 'man_thanks and man_receipt, not man_report.' );
	}

	/**
	 * An automation that can trash (Manual::trashes()) is never offered in bulk, not even to administrators;
	 * its screen and POST are refused (403) and start nothing; the list row does not offer it.
	 */
	public function test_trash_automations_are_not_offered() {
		add_action( 'pegwork_register', fn( $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/engine-trash' ) );
		\Pegwork\Plugin::init();
		$sam = Records::create( 'tr_volunteer', array( 'name' => 'Sam' ), 'private' );
		$this->assertIsInt( $sam );

		foreach ( array( $this->editor, $this->admin ) as $user ) {
			wp_set_current_user( $user );
			$this->assertTrue( StartRoute::can_run( 'tr_bound', $sam ), 'The record editor still offers it.' );
			$this->assertSame( array(), BulkRun::automations( 'tr_volunteer' ) );
			$this->assertSame( array(), BulkRun::bulk_actions( array(), 'tr_volunteer' ) );
			$this->assertSame( 403, $this->dies( fn() => $this->screen( 'tr_bound', array( $sam ) ) ) );
			$this->assertSame( 403, $this->dies( fn() => $this->submit( 'tr_bound', array( $sam ) ) ) );
		}
		$this->assertSame( array(), $this->runs( 'tr_bound' ) );
		$this->assertSame( 'private', get_post_status( $sam ) );

		$_GET = array();
		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'Staff can also run it on selected Volunteers', $html );
	}

	/**
	 * The notice texts.
	 */
	public function test_summary_texts() {
		$this->assertSame(
			'Started 10 runs. 2 did not apply.',
			BulkRun::summary(
				array(
					'started'   => 10,
					'duplicate' => 0,
					'skipped'   => 2,
					'cannot'    => 0,
				)
			)
		);
		$this->assertSame(
			'Started 1 run. You cannot run it on 3 records. 1 was already started from this screen.',
			BulkRun::summary(
				array(
					'started'   => 1,
					'duplicate' => 1,
					'skipped'   => 0,
					'cannot'    => 3,
				)
			)
		);
	}
}
