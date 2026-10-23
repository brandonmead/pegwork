<?php
/**
 * Integration tests: Pegwork > Automations (list, toggles, Run now, run log, run detail, Retry).
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The menu test drives core's admin menu globals.

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Admin\Menu;
use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Actions;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Engine;
use PegworkAutomation\Overrides;
use RuntimeException;
use WPDieException;

use const Pegwork\Api\ADMIN_MENU;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Every view and action needs manage_options and a per-row nonce; actions call what the CLI calls.
 */
class AutomationAdminTest extends AutomationTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Fresh mock mailer, an administrator, and redirects captured instead of sent.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
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
	 * Renders a view of the screen.
	 *
	 * @param array<string, string|int> $query Query arguments (`view`, `run`, `automation`, `status`, `paged`).
	 * @return string HTML.
	 */
	private function render( array $query = array() ) {
		$_GET = array_map( 'strval', $query );
		ob_start();
		try {
			Screen::render();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	/**
	 * Posts an action form to its handler.
	 *
	 * @param string                $action Actions::TOGGLE, RUN or RETRY.
	 * @param array<string, string> $fields Form fields (`automation`, `op`, `run`).
	 * @param string|null           $nonce  Nonce, or null for the valid one.
	 * @return string Redirect location.
	 */
	private function post( $action, array $fields, $nonce = null ) {
		$subject  = Actions::RETRY === $action ? $fields['run'] : $fields['automation'];
		$fields  += array(
			'action'   => $action,
			'_wpnonce' => $nonce ?? wp_create_nonce( $action . '_' . $subject ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;

		$handlers = array(
			Actions::TOGGLE => array( Actions::class, 'toggle' ),
			Actions::RUN    => array( Actions::class, 'run' ),
			Actions::RETRY  => array( Actions::class, 'retry' ),
		);
		try {
			call_user_func( $handlers[ $action ] );
		} catch ( RuntimeException $e ) {
			return substr( $e->getMessage(), strlen( 'redirect:' ) );
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * A job created through the Records API.
	 *
	 * @param array<string, mixed> $values Values.
	 * @param string               $status Status.
	 * @return int Post ID.
	 */
	private function record( array $values, $status = 'publish' ) {
		$id = Records::create( 'job', $values + array( 'title' => 'A job' ), $status );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A failed job_mail run (no recipient), with the private note set on its record.
	 *
	 * @return array{0: int, 1: int} Run ID and record ID.
	 */
	private function failed_run() {
		$id = $this->record( array( 'note' => 'secret-note-42' ) );
		Records::update( $id, array( 'urgent' => true ) );
		$this->drain();
		$run = $this->only_run( 'job_mail' );
		$this->assertSame( 'failed', $run['status'] );
		return array( $run['id'], $id );
	}

	/**
	 * The submenu is added under Pegwork with manage_options, between Overview and Modules.
	 */
	public function test_submenu() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['submenu']           = array();
		$GLOBALS['menu']              = array();
		$GLOBALS['_registered_pages'] = array();
		$GLOBALS['_parent_pages']     = array();
		$GLOBALS['admin_page_hooks']  = array();

		Menu::add_menu();
		Screen::add_menu();
		Menu::add_modules();

		$items = $GLOBALS['submenu'][ ADMIN_MENU ];
		$this->assertSame( array( ADMIN_MENU, Screen::SLUG, Menu::MODULES_SLUG ), array_column( $items, 2 ) );
		$this->assertSame( array( 'Automations', 'manage_options', Screen::SLUG, 'Pegwork Automations' ), $items[1] );

		$GLOBALS['submenu'] = array();
		$GLOBALS['menu']    = array();
	}

	/**
	 * The list shows each automation with its trigger, source, enabled state, last run, next run and actions.
	 */
	public function test_list() {
		Overrides::set( 'job_sweep', 'off' );
		$this->failed_run();
		$html = $this->render();

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Automations</h1>', $html );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Automations</caption>', $html );
		$this->assertStringContainsString( '<strong>Hourly digest with no record and a custom retry policy</strong><br><code>job_digest</code><span class="pegwork-meta">File package engine</span>', $html );
		$this->assertStringContainsString( '<td>Every hour at 30 minutes past</td>', $html );
		$this->assertStringContainsString( '<td>Every day at 09:00, for each job</td>', $html );
		$this->assertStringContainsString( '<td>On record.status_changed (job)</td>', $html );

		// Enabled state: JSON default and site override.
		$this->assertStringContainsString( 'Disabled</span><span class="pegwork-meta">JSON default on, site override off</span>', $html );
		$this->assertStringContainsString( 'Disabled</span><span class="pegwork-meta">JSON default off, no site override</span>', $html, 'job_disabled.' );
		$this->assertStringContainsString( 'Enabled</span><span class="pegwork-meta">JSON default on, no site override</span>', $html );

		// Last run, with failures in 7 days; never-run automations say so.
		$this->assertStringContainsString( 'pegwork-status-failed">Failed</span> <time datetime="2026-11-02T09:00:00+00:00">', $html );
		$this->assertStringContainsString( '1 failed in the last 7 days', $html );
		$this->assertStringContainsString( 'Never run', $html );

		// Next run: the pending schedule firing (boot scheduled job_digest for 09:30 UTC).
		$this->assertStringContainsString( '<time datetime="2026-11-02T09:30:00+00:00">', $html );
		$this->assertStringContainsString( 'None while disabled', $html );
		$this->assertStringContainsString( 'When its event fires', $html );

		// Actions: per-row nonces, Run now on enabled schedules only, a visible reason on disabled ones.
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( Actions::TOGGLE . '_job_digest' ) . '"><input type="hidden" name="automation" value="job_digest"><input type="hidden" name="op" value="disable"><button type="submit" class="button">Disable<span class="screen-reader-text"> Hourly digest', $html );
		$this->assertStringContainsString( 'value="' . wp_create_nonce( Actions::RUN . '_job_digest' ) . '"><input type="hidden" name="automation" value="job_digest"><button type="submit" class="button">Run now', $html );
		$this->assertStringContainsString( 'aria-describedby="pegwork-run-reason-job_sweep">Run now<span class="screen-reader-text"> Yearly still-active check', $html );
		$this->assertStringContainsString( 'id="pegwork-run-reason-job_sweep">Enable it to run it now.</span>', $html );
		$this->assertStringNotContainsString( 'value="' . wp_create_nonce( Actions::RUN . '_job_approved' ) . '"', $html, 'No Run now on event automations.' );
		$this->assertStringContainsString( 'Enable<span class="screen-reader-text"> Disabled in JSON', $html );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );
		$this->assertStringNotContainsString( 'secret-note-42', $html );
	}

	/**
	 * Empty states: no automations, no runs, no matching runs, unknown run.
	 */
	public function test_empty_states() {
		$this->assertStringContainsString( 'No runs yet.', $this->render( array( 'view' => 'log' ) ) );
		$this->assertStringContainsString(
			'No runs match these filters.',
			$this->render(
				array(
					'view'   => 'log',
					'status' => 'failed',
				)
			)
		);
		$html = $this->render(
			array(
				'view' => 'run',
				'run'  => 999,
			)
		);
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Run not found</h1>', $html );

		remove_all_actions( 'pegwork_register' );
		\Pegwork\Plugin::init();
		$this->assertStringContainsString( 'No automations registered.', $this->render() );
	}

	/**
	 * Enable/Disable sets the same override as `wp pegwork automation enable|disable` (Overrides::toggle),
	 * with a one-time notice; an unknown key gets the CLI's error and changes nothing.
	 */
	public function test_toggle_matches_the_cli() {
		$location = $this->post(
			Actions::TOGGLE,
			array(
				'automation' => 'job_approved',
				'op'         => 'disable',
			)
		);
		$this->assertSame( Screen::url(), $location );
		$this->assertSame( array( 'job_approved' => 'off' ), get_option( Overrides::OPTION ) );
		$this->assertFalse( Overrides::enabled( pegwork_registry()->automation( 'job_approved' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( '<div class="notice notice-success is-dismissible"><p>Automation Approval: stamp Last verified and email the contact disabled for this site.', $html );
		$this->assertStringNotContainsString( 'notice-success', $this->render(), 'The notice shows once.' );

		// The CLI path writes the identical option.
		$admin = get_option( Overrides::OPTION );
		delete_option( Overrides::OPTION );
		$this->assertTrue( Overrides::toggle( 'job_approved', 'off' ) );
		$this->assertSame( $admin, get_option( Overrides::OPTION ) );

		$this->post(
			Actions::TOGGLE,
			array(
				'automation' => 'job_disabled',
				'op'         => 'enable',
			)
		);
		$this->assertSame(
			array(
				'job_approved' => 'off',
				'job_disabled' => 'on',
			),
			get_option( Overrides::OPTION )
		);

		$this->post(
			Actions::TOGGLE,
			array(
				'automation' => 'nope',
				'op'         => 'enable',
			)
		);
		$this->assertArrayNotHasKey( 'nope', get_option( Overrides::OPTION ) );
		$this->assertStringContainsString( '<div class="notice notice-error is-dismissible"><p>' . Overrides::toggle( 'nope', 'on' )->get_error_message() . '</p></div>', $this->render() );
	}

	/**
	 * Run now on a schedule creates a logged run (queued, then executed by the wake-up) and does not move
	 * its regular schedule; event and disabled automations are refused with the reason.
	 */
	public function test_run_now() {
		$next     = $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_digest' ) );
		$location = $this->post( Actions::RUN, array( 'automation' => 'job_digest' ) );
		$this->assertSame(
			Screen::url(
				array(
					'view'       => 'log',
					'automation' => 'job_digest',
				)
			),
			$location
		);

		$run = $this->only_run( 'job_digest' );
		$this->assertSame( 'queued', $run['status'] );
		$this->assertSame( 'schedule', $run['trigger_type'] );
		$this->assertSame( $next, $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_digest' ) ), 'The regular schedule does not move.' );
		$this->assertNotNull( $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) );

		$html = $this->render(
			array(
				'view'       => 'log',
				'automation' => 'job_digest',
			)
		);
		$this->assertStringContainsString( 'Hourly digest with no record and a custom retry policy: matched 1, created 1 run.', $html );
		$this->assertStringContainsString( '>Run ' . $run['id'] . '</a></th>', $html );
		$this->assertStringContainsString( 'pegwork-status-queued">Queued</span>', $html );

		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
		$this->assertCount( 1, tests_retrieve_phpmailer_instance()->mock_sent );

		// Event automation: refused, nothing created.
		$this->post( Actions::RUN, array( 'automation' => 'job_approved' ) );
		$this->assertSame( array(), $this->runs( 'job_approved' ) );
		$this->assertStringContainsString( 'Run now is for schedule automations and staff automations without a record.', $this->render() );

		// Disabled: Manual::run()'s refusal, as the CLI gets it.
		Overrides::set( 'job_sweep', 'off' );
		$this->post( Actions::RUN, array( 'automation' => 'job_sweep' ) );
		$this->assertSame( array(), $this->runs( 'job_sweep' ) );
		$this->assertStringContainsString( 'Automation job_sweep is disabled on this site; enable it first.', $this->render() );
	}

	/**
	 * The run detail lists each step attempt; Retry resumes a failed run at the failed step, as
	 * `wp pegwork automation retry` does, and is offered on failed runs only. No private value is shown.
	 */
	public function test_run_detail_and_retry() {
		[ $run_id, $record ] = $this->failed_run();
		$query               = array(
			'view' => 'run',
			'run'  => $run_id,
		);

		$html = $this->render( $query );
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Run ' . $run_id . '</h1>', $html );
		$this->assertStringContainsString( '<th scope="row">Error</th><td>no_recipient: The recipient list rendered empty.</td>', $html );
		$this->assertStringContainsString( 'Event <code>record.updated</code>', $html );
		$this->assertStringContainsString( '>#' . $record . ' A job</a>', $html );
		$this->assertStringContainsString( '<th scope="row"><code>send</code><span class="pegwork-meta">send_email</span></th><td>1</td><td><span class="pegwork-status pegwork-status-failed">Failed</span></td>', $html );
		$this->assertStringContainsString( '<td>no_recipient: The recipient list rendered empty.</td></tr>', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( Actions::RETRY . '_' . $run_id ) . '"><input type="hidden" name="run" value="' . $run_id . '">', $html );
		$this->assertStringContainsString( '>Retry</button>', $html );

		// Fix the cause, then retry from the screen.
		$this->assertTrue( Records::update( $record, array( 'contact_email' => 'owner@example.org' ) ) );
		$location = $this->post( Actions::RETRY, array( 'run' => (string) $run_id ) );
		$this->assertSame( Screen::url( $query ), $location );
		$this->assertSame( 'queued', $this->run_row( $run_id )['status'] );
		$this->assertStringContainsString( 'Run ' . $run_id . ' queued again from the step that failed.', $this->render( $query ) );

		$this->drain();
		$this->assertSame( 'completed', $this->run_row( $run_id )['status'] );
		$html = $this->render( $query );
		$this->assertStringNotContainsString( '>Retry</button>', $html, 'Retry only on failed runs.' );
		$this->assertStringContainsString( '<li><code>to</code> o***@example.org</li>', $html );
		$this->assertStringContainsString( '<li><code>subject</code> Urgent: A job ([private])</li>', $html );
		$this->assertStringContainsString( '<li><code>html</code> yes</li>', $html );

		// Retrying a run that is not failed gets Manual::retry()'s refusal.
		$this->post( Actions::RETRY, array( 'run' => (string) $run_id ) );
		$this->assertStringContainsString( 'only failed runs can be retried.', $this->render( $query ) );

		// Private values never reach any view.
		foreach ( array( $this->render( $query ), $this->render( array( 'view' => 'log' ) ), $this->render() ) as $view ) {
			$this->assertStringNotContainsString( 'secret-note-42', $view );
			$this->assertStringNotContainsString( 'owner@example.org', $view );
		}
	}

	/**
	 * The log filters by automation and status and pages 20 runs at a time, newest first.
	 */
	public function test_log_filters_and_pages() {
		$runs = Engine::instance()->runs();
		for ( $i = 1; $i <= 23; $i++ ) {
			$runs->insert(
				array(
					'automation'      => 0 === $i % 2 ? 'job_digest' : 'job_sweep',
					'trigger_type'    => 'schedule',
					'status'          => 0 === $i % 5 ? 'failed' : 'completed',
					'depth'           => 0,
					'definition_hash' => str_repeat( 'a', 32 ),
					'context'         => array( 'trigger' => array( 'old' => array( 'note' => 'secret-in-context' ) ) ),
					'error'           => 0 === $i % 5 ? 'mail_failed: x' : null,
				)
			);
		}

		$html = $this->render( array( 'view' => 'log' ) );
		$this->assertSame( 20, substr_count( $html, '<th scope="row"><a href=' ) );
		$this->assertStringContainsString( '<p class="pegwork-count">23 runs</p>', $html );
		$this->assertStringContainsString( 'Page 1 of 2', $html );
		$this->assertStringContainsString( 'aria-label="Run log pages"', $html );
		$this->assertStringContainsString( 'paged=2', $html );
		$this->assertStringNotContainsString( 'secret-in-context', $html, 'Run contexts are never printed.' );

		$html = $this->render(
			array(
				'view'  => 'log',
				'paged' => 2,
			)
		);
		$this->assertStringContainsString( 'Page 2 of 2', $html );
		$this->assertSame( 3, substr_count( $html, '<th scope="row"><a href=' ) );

		$html = $this->render(
			array(
				'view'   => 'log',
				'status' => 'failed',
			)
		);
		$this->assertStringContainsString( '<p class="pegwork-count">4 runs</p>', $html );
		$this->assertStringContainsString( '<option value="failed" selected=\'selected\'>Failed</option>', $html );
		$this->assertStringNotContainsString( 'Page 1 of', $html );

		$html = $this->render(
			array(
				'view'       => 'log',
				'automation' => 'job_digest',
				'status'     => 'failed',
			)
		);
		$this->assertStringContainsString( '<p class="pegwork-count">2 runs</p>', $html );
		$this->assertStringContainsString( '<label for="pegwork-filter-automation">Automation</label>', $html );
		$this->assertStringContainsString( '<label for="pegwork-filter-status">Status</label>', $html );
	}

	/**
	 * Editors and subscribers get 403 on every view and every action, even with valid nonces; nothing changes.
	 */
	public function test_capability_on_every_view_and_action() {
		[ $run_id ] = $this->failed_run();

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			foreach ( array( array(), array( 'view' => 'log' ), array( 'view' => 'run', 'run' => $run_id ) ) as $query ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact list of queries.
				try {
					$this->render( $query );
					$this->fail( "$role: expected wp_die on the view." );
				} catch ( WPDieException $e ) {
					$this->assertSame( 403, $e->getCode(), $role );
				}
			}

			$posts = array(
				array(
					Actions::TOGGLE,
					array(
						'automation' => 'job_approved',
						'op'         => 'disable',
					),
				),
				array( Actions::RUN, array( 'automation' => 'job_digest' ) ),
				array( Actions::RETRY, array( 'run' => (string) $run_id ) ),
			);
			foreach ( $posts as [ $action, $fields ] ) {
				try {
					$this->post( $action, $fields );
					$this->fail( "$role: expected wp_die on $action." );
				} catch ( WPDieException $e ) {
					$this->assertSame( 403, $e->getCode(), "$role $action" );
				}
			}
		}

		$this->assertFalse( get_option( Overrides::OPTION, false ) );
		$this->assertSame( array(), $this->runs( 'job_digest' ) );
		$this->assertSame( 'failed', $this->run_row( $run_id )['status'] );
	}

	/**
	 * A bad nonce, or one for another row, is refused before anything changes.
	 */
	public function test_nonce_on_every_action() {
		[ $run_id ] = $this->failed_run();

		$cases = array(
			array(
				Actions::TOGGLE,
				array(
					'automation' => 'job_approved',
					'op'         => 'disable',
				),
				wp_create_nonce( Actions::TOGGLE . '_job_digest' ),
			),
			array( Actions::RUN, array( 'automation' => 'job_digest' ), wp_create_nonce( Actions::RUN . '_job_sweep' ) ),
			array( Actions::RETRY, array( 'run' => (string) $run_id ), wp_create_nonce( Actions::RETRY . '_' . ( $run_id + 1 ) ) ),
		);
		foreach ( $cases as [ $action, $fields, $other ] ) {
			foreach ( array( 'not-a-nonce', $other ) as $nonce ) {
				try {
					$this->post( $action, $fields, $nonce );
					$this->fail( "Expected wp_die on $action." );
				} catch ( WPDieException $e ) {
					$this->assertSame( 403, $e->getCode(), $action );
				}
			}
		}

		$this->assertFalse( get_option( Overrides::OPTION, false ) );
		$this->assertSame( array(), $this->runs( 'job_digest' ) );
		$this->assertSame( 'failed', $this->run_row( $run_id )['status'] );
	}
}
