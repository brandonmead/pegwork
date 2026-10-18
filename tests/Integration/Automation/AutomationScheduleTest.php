<?php
/**
 * Integration tests: schedule triggers, sweeps, waits and manual runs with time travel.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use WP_Error;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * The job_sweep fixture (daily at 09:00, stale published jobs, one email per Last verified value, wait
 * 30 days, close if still stale) on the frozen clock and the fake scheduler.
 */
class AutomationScheduleTest extends AutomationTestCase {

	/**
	 * Fresh mock mailer per test.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * Subjects of the emails sent so far, without the hourly job_digest.
	 *
	 * @return string[]
	 */
	private function subjects() {
		$subjects = array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
		return array_values( array_filter( $subjects, fn( $subject ) => ! str_starts_with( $subject, 'Digest' ) ) );
	}

	/**
	 * A job with a Last verified date and a contact.
	 *
	 * @param string $last_verified Date.
	 * @param string $status        Status.
	 * @param string $title         Title.
	 * @param string $stage         Stage slug.
	 * @return int Post ID.
	 */
	private function stale( $last_verified = '2025-11-01', $status = 'publish', $title = 'Old job', $stage = 'open' ) {
		$id = Records::create(
			'job',
			array(
				'title'         => $title,
				'last_verified' => $last_verified,
				'contact_email' => 'owner@example.org',
				'job_stage'     => array( $stage ),
			),
			$status
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Fires due schedule firings and runs at the clock's time.
	 */
	private function tick() {
		return $this->scheduler->drain( $this->clock->now()->getTimestamp(), array( Engine::SCHEDULE_HOOK, Engine::SWEEP_HOOK, Engine::RUN_HOOK ) );
	}

	/**
	 * Boot schedules each enabled schedule automation at its next time in site time.
	 */
	public function test_boot_schedules_each_enabled_schedule() {
		$this->assertSame( strtotime( '2026-11-03 09:00:00 UTC' ), $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ), 'At 09:00, the next daily 09:00 is tomorrow.' );
		$this->assertSame( strtotime( '2026-11-02 09:30:00 UTC' ), $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_digest' ) ) );

		// A firing drifts later than the definition gives (the definition moved): ensure() pulls it in.
		$this->scheduler->schedule( Engine::SCHEDULE_HOOK, array( 'job_digest' ), strtotime( '2026-11-09 00:00:00 UTC' ) );
		Engine::instance()->schedules()->ensure();
		$this->assertSame( strtotime( '2026-11-02 09:30:00 UTC' ), $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_digest' ) ) );
	}

	/**
	 * Acceptance: exactly one email per record per cycle. Sweeping twice sends once; a new Last verified
	 * value starts the next cycle, which sends again.
	 */
	public function test_sweep_sends_one_email_per_record_per_cycle() {
		$stale = $this->stale();
		$fresh = $this->stale( '2026-06-01', 'publish', 'Fresh job' );
		$this->stale( '2025-11-01', 'draft', 'Draft job' );
		$this->stale( '2025-11-01', 'publish', 'Closed job', 'closed' );

		$this->clock->set( '2026-11-03 09:00' );
		$this->tick();

		$run = $this->only_run( 'job_sweep' );
		$this->assertSame( $stale, $run['record_id'] );
		$this->assertSame( 'schedule', $run['trigger_type'] );
		$this->assertSame( "$stale:2025-11-01", $run['idempotency_key'] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( array( 'Is Old job still active?' ), $this->subjects() );
		$this->assertSame( strtotime( '2026-11-04 09:00:00 UTC' ), $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ), 'The firing rescheduled itself.' );

		// The same day again, and the next day: no second run, no second email.
		$again = Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->assertSame( array(), $again['runs'] );
		$this->assertSame( 1, $again['duplicates'] );
		$this->clock->advance( 'P1D' );
		$this->tick();
		$this->assertCount( 1, $this->runs( 'job_sweep' ) );
		$this->assertCount( 1, $this->subjects() );

		// Re-verified during the wait: the run stops at still_stale, the job stays open.
		$this->clock->set( '2026-11-10 12:00' );
		$this->assertTrue( Records::update( $stale, array( 'last_verified' => '2026-11-10' ) ) );
		$this->clock->set( '2026-12-03 09:00' );
		$this->tick();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:still_stale', $run['context']['outcome'] );
		$this->assertSame( array( 'open' ), $this->value( $stale, 'job_stage' ) );
		$this->assertCount( 1, $this->subjects() );

		// A year after the new Last verified: the next cycle sends again. The fresh job would be stale by
		// then too, and it has served its purpose.
		wp_delete_post( $fresh, true );
		$this->clock->set( '2027-11-09 09:00' );
		$this->tick();
		$this->assertCount( 1, $this->runs( 'job_sweep' ), 'Not stale yet on 2027-11-09.' );
		$this->clock->set( '2027-11-10 09:00' );
		$this->tick();
		$runs = $this->runs( 'job_sweep' );
		$this->assertCount( 2, $runs );
		$this->assertSame( "$stale:2026-11-10", $runs[1]['idempotency_key'] );
		$this->assertSame( array( 'Is Old job still active?', 'Is Old job still active?' ), $this->subjects() );
	}

	/**
	 * Acceptance: a wait of P30D resumes after the frozen clock passes resume_at, and not before.
	 */
	public function test_wait_resumes_after_thirty_days_and_not_before() {
		$id = $this->stale();
		Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->drain();

		$run = $this->only_run( 'job_sweep' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'still_stale', $run['current_step'] );
		$this->assertSame( '2026-12-02 09:00:00', $run['resume_at'] );
		$this->assertSame( strtotime( '2026-12-02 09:00:00 UTC' ), $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) );

		$this->clock->set( '2026-12-02 08:59' );
		$this->drain();
		$this->assertNull( Engine::instance()->executor()->execute( $run['id'] ), 'An early wake-up does not claim the run.' );
		$this->assertSame( array(), Engine::instance()->maintenance()->resume( true ), 'Nothing is due for tick either.' );
		$this->assertSame( 'waiting', $this->run_row( $run['id'] )['status'] );
		$this->assertCount( 1, $this->subjects() );

		$this->clock->set( '2026-12-02 09:00' );
		$this->drain();
		$run = $this->run_row( $run['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $run['context'] );
		$this->assertSame( array( 'closed' ), $this->value( $id, 'job_stage' ) );
		$this->assertSame( array( 'Is Old job still active?', 'Old job is closed' ), $this->subjects() );

		$steps = $this->steps( $run['id'] );
		$this->assertSame( array( 'ask', 'grace', 'still_stale', 'close', 'tell' ), array_column( $steps, 'step_id' ) );
		$this->assertSame(
			array(
				'for'       => 'P30D',
				'resume_at' => '2026-12-02 09:00:00',
			),
			$steps[1]['summary']
		);
		$this->assertSame( '2026-12-02 09:00:00', $steps[2]['started_at'] );

		// Closed: later sweeps leave it alone.
		$this->clock->set( '2027-01-02 09:00' );
		$this->assertSame( 0, Engine::instance()->schedules()->fire( 'job_sweep' )['matched'] );
	}

	/**
	 * A wait across a DST change keeps the site's wall-clock time.
	 */
	public function test_wait_keeps_wall_clock_time_across_dst() {
		update_option( 'timezone_string', 'America/Chicago' );
		$this->clock->set( '2026-10-20 09:00' );
		$this->stale( '2025-10-01' );
		Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->drain();
		$this->assertSame( '2026-11-19 15:00:00', $this->only_run( 'job_sweep' )['resume_at'], '09:00 CST is 15:00 UTC (it was 14:00 UTC before 2026-11-01).' );
	}

	/**
	 * The max_runs cap stops one sweep; the excess is logged as skipped and picked up by the next sweep.
	 */
	public function test_max_runs_caps_a_sweep() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->stale( '2025-11-01', 'publish', "Job $i" );
		}

		$first = Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->assertCount( 3, $first['runs'] );
		$this->assertTrue( $first['capped'] );
		$skipped = Engine::instance()->runs()->find( 'job_sweep', 'skipped' );
		$this->assertCount( 1, $skipped );
		$this->assertStringStartsWith( 'max_runs: the sweep stopped after 3 runs', $skipped[0]['error'] );

		$second = Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->assertCount( 2, $second['runs'] );
		$this->assertSame( 3, $second['duplicates'] );
		$this->assertFalse( $second['capped'] );
	}

	/**
	 * A sweep that uses up the time budget continues in a chained wake-up from the last ID.
	 */
	public function test_sweep_continues_after_the_time_budget() {
		$ids  = self::factory()->post->create_many(
			50,
			array(
				'post_type'   => 'job',
				'post_status' => 'publish',
			)
		);
		$last = $this->stale();
		add_filter( 'pegwork_automation_time_budget', '__return_zero' );

		$first = Engine::instance()->schedules()->fire( 'job_sweep' );
		$this->assertTrue( $first['continued'] );
		$this->assertSame( array(), $first['runs'] );
		$this->assertSame( strtotime( '2026-11-02 09:00:00 UTC' ), $this->scheduler->next( Engine::SWEEP_HOOK, array( 'job_sweep', max( $ids ), 0 ) ) );

		$this->scheduler->drain( $this->clock->now()->getTimestamp(), array( Engine::SWEEP_HOOK ) );
		$this->assertSame( $last, $this->only_run( 'job_sweep' )['record_id'] );
	}

	/**
	 * A disabled schedule creates nothing and is not rescheduled; enabling it schedules it again on boot.
	 */
	public function test_disabled_schedule_is_not_rescheduled() {
		$this->stale();
		Overrides::set( 'job_sweep', 'off' );
		$this->clock->set( '2026-11-03 09:00' );
		$this->tick();
		$this->assertSame( array(), $this->runs( 'job_sweep' ) );
		$this->assertNull( $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ) );

		Overrides::set( 'job_sweep', 'on' );
		Engine::register();
		$this->assertSame( strtotime( '2026-11-04 09:00:00 UTC' ), $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ) );
	}

	/**
	 * Acceptance: --now is refused in production; other environments read datetimes and offsets.
	 */
	public function test_now_is_refused_in_production() {
		$refused = Manual::clock( '+12months', 'production' );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'pegwork_now_refused', $refused->get_error_code() );
		if ( 'production' === wp_get_environment_type() ) {
			$this->assertSame( 'pegwork_now_refused', Manual::clock( '2027-11-02 09:00' )->get_error_code(), 'The default reads wp_get_environment_type().' );
		}

		$this->assertSame( '2027-11-02 09:00:00', Manual::clock( '2027-11-02 09:00', 'local' )->now()->format( 'Y-m-d H:i:s' ) );
		$year = Manual::clock( '+12months', 'staging' )->now()->getTimestamp();
		$this->assertEqualsWithDelta( strtotime( '+12 months' ), $year, 5 );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, Manual::clock( '+P30D', 'development' )->now()->getTimestamp(), 5 );
		$this->assertSame( 'pegwork_invalid_now', Manual::clock( 'next blue moon', 'local' )->get_error_code() );
	}

	/**
	 * `run --now` runs a sweep and its runs in process on the given clock without moving the regular
	 * schedule; `tick --now` a month later resumes the wait; later wake-ups go to the site scheduler.
	 */
	public function test_manual_run_and_tick_with_time_travel() {
		$id = $this->stale( '2026-11-01' );
		$this->drain(); // The record.created runs (job_spawn), so tick below only sees the wait.
		$regular = $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) );

		$manual = new Manual( new FrozenClock( '2026-11-02 09:00' ) );
		$this->assertSame( 0, $manual->run( 'job_sweep' )['matched'], 'Not stale on the real test date.' );
		$manual->finish();

		$manual = new Manual( new FrozenClock( '2027-11-02 09:00' ) );
		$result = $manual->run( 'job_sweep' );
		$this->assertCount( 1, $result['runs'] );
		$done = $manual->finish();
		$this->assertSame(
			array(
				'fired'  => 1,
				'handed' => 1,
			),
			$done,
			'The run executed in process; its wait wake-up went to the site scheduler.'
		);
		$run = $this->run_row( $result['runs'][0] );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( '2027-12-02 09:00:00', $run['resume_at'] );
		$this->assertSame( strtotime( '2027-12-02 09:00:00 UTC' ), $this->scheduler->next( Engine::RUN_HOOK, array( $run['id'] ) ) );
		$this->assertSame( $regular, $this->scheduler->next( Engine::SCHEDULE_HOOK, array( 'job_sweep' ) ), 'The regular schedule did not move.' );
		$this->assertSame( $this->clock, Engine::instance()->clock(), 'The engine is restored.' );
		$this->assertCount( 1, $this->subjects() );

		$manual = new Manual( new FrozenClock( '2027-12-01 09:00' ) );
		$this->assertSame( array(), $manual->tick() );
		$manual->finish();

		$manual = new Manual( new FrozenClock( '2027-12-02 09:00' ) );
		$this->assertSame( array( $run['id'] ), $manual->tick() );
		$manual->finish();
		$this->assertSame( 'completed', $this->run_row( $run['id'] )['status'] );
		$this->assertSame( array( 'closed' ), $this->value( $id, 'job_stage' ) );
		$this->assertCount( 2, $this->subjects() );
	}

	/**
	 * `run` checks its arguments; an event automation runs against --record with trigger type manual.
	 */
	public function test_manual_run_arguments_and_event_automation() {
		$manual = new Manual( null, true );
		$this->assertSame( 'pegwork_unknown_automation', $manual->run( 'nope' )->get_error_code() );
		$this->assertSame( 'pegwork_no_record', $manual->run( 'job_approved' )->get_error_code() );
		$this->assertSame( 'pegwork_no_record', $manual->run( 'job_digest', 5 )->get_error_code() );
		$this->assertSame( 'pegwork_record_not_found', $manual->run( 'job_approved', 999999 )->get_error_code() );
		$this->assertSame( 'automation_disabled', $manual->run( 'job_disabled', 1 )->get_error_code() );

		$id     = $this->stale( '2026-01-01', 'publish', 'Manual job' );
		$result = $manual->run( 'job_approved', $id );
		$this->assertCount( 1, $result['runs'] );
		$manual->finish();

		$run = $this->only_run( 'job_approved' );
		$this->assertSame( 'manual', $run['trigger_type'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'cli', $run['context']['trigger']['origin']['source'] );
		$this->assertSame( '2026-11-02', $this->value( $id, 'last_verified' ) );
		$this->assertSame( array( 'Manual job is live on Test Blog' ), $this->subjects() );

		// --record narrows a sweep to one record (filters still apply).
		$other  = $this->stale( '2025-11-01', 'publish', 'Other job' );
		$manual = new Manual( null, true );
		$this->assertSame( 0, $manual->run( 'job_sweep', $id )['matched'] );
		$this->assertCount( 1, $manual->run( 'job_sweep', $other )['runs'] );
		$manual->finish();
	}
}
