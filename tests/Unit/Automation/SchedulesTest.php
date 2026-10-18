<?php
/**
 * Unit tests: schedule times, durations, retry policy and the in-process scheduler.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use Pegwork\Tests\Support\FakeScheduler;
use PegworkAutomation\Clock\FrozenClock;
use PegworkAutomation\Duration;
use PegworkAutomation\Engine;
use PegworkAutomation\Retry;
use PegworkAutomation\Scheduler\InlineScheduler;
use PegworkAutomation\Schedules;
use WP_UnitTestCase;

/**
 * Next firing times in site time (DST included), duration arithmetic, retry backoff, InlineScheduler.
 */
class SchedulesTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes and the fake scheduler.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
		require_once dirname( __DIR__, 2 ) . '/Support/FakeScheduler.php';
	}

	/**
	 * Next firing of a trigger at a site time.
	 *
	 * @param array<string, mixed> $trigger Trigger.
	 * @param string               $now     Site time.
	 * @return string Site time of the next firing.
	 */
	private function next( array $trigger, $now ) {
		$schedules = new Schedules( new Engine( new FrozenClock( $now ), new FakeScheduler() ) );
		return wp_date( 'Y-m-d H:i', $schedules->next_time( $trigger + array( 'type' => 'schedule' ) ) );
	}

	/**
	 * Daily, hourly and weekly times, with and without `at`.
	 */
	public function test_next_time() {
		update_option( 'timezone_string', 'America/Chicago' );
		update_option( 'start_of_week', 1 );

		$day = array(
			'every' => 'day',
			'at'    => '09:00',
		);
		$this->assertSame( '2026-11-02 09:00', $this->next( $day, '2026-11-02 08:59' ) );
		$this->assertSame( '2026-11-03 09:00', $this->next( $day, '2026-11-02 09:00' ), 'Strictly after now.' );
		$this->assertSame( '2026-11-03 00:00', $this->next( array( 'every' => 'day' ), '2026-11-02 09:00' ), 'Default at is midnight.' );

		$hour = array(
			'every' => 'hour',
			'at'    => '07:15',
		);
		$this->assertSame( '2026-11-02 09:15', $this->next( $hour, '2026-11-02 09:00' ), 'Hourly uses the minute of at.' );
		$this->assertSame( '2026-11-02 10:15', $this->next( $hour, '2026-11-02 09:15' ) );
		$this->assertSame( '2026-11-02 10:00', $this->next( array( 'every' => 'hour' ), '2026-11-02 09:00' ) );

		$week = array(
			'every' => 'week',
			'at'    => '08:00',
		);
		$this->assertSame( '2026-11-09 08:00', $this->next( $week, '2026-11-04 12:00' ), 'Wednesday: next Monday.' );
		$this->assertSame( '2026-11-02 08:00', $this->next( $week, '2026-11-02 07:00' ), 'Monday before at: today.' );
		$this->assertSame( '2026-11-09 08:00', $this->next( $week, '2026-11-02 08:00' ), 'Monday at at: next week.' );
		update_option( 'start_of_week', 0 );
		$this->assertSame( '2026-11-08 08:00', $this->next( $week, '2026-11-04 12:00' ), 'Weeks starting Sunday.' );
	}

	/**
	 * Daily firings keep the wall-clock time across DST changes (America/Chicago, 2026-11-01 and 2027-03-14).
	 */
	public function test_next_time_across_dst() {
		update_option( 'timezone_string', 'America/Chicago' );
		$day       = array(
			'type'  => 'schedule',
			'every' => 'day',
			'at'    => '09:00',
		);
		$schedules = new Schedules( new Engine( new FrozenClock( '2026-10-31 10:00' ), new FakeScheduler() ) );
		$this->assertSame( '2026-11-01 15:00', gmdate( 'Y-m-d H:i', $schedules->next_time( $day ) ), '09:00 CST is 15:00 UTC.' );

		$schedules = new Schedules( new Engine( new FrozenClock( '2027-03-13 10:00' ), new FakeScheduler() ) );
		$this->assertSame( '2027-03-14 14:00', gmdate( 'Y-m-d H:i', $schedules->next_time( $day ) ), '09:00 CDT is 14:00 UTC.' );

		$at_two = array(
			'every' => 'day',
			'at'    => '02:30',
		);
		$this->assertSame( '2027-03-14 03:30', $this->next( $at_two, '2027-03-13 10:00' ), 'A wall time that does not exist moves forward.' );
	}

	/**
	 * Durations add in site-time calendar units and return UTC.
	 */
	public function test_duration() {
		update_option( 'timezone_string', 'America/Chicago' );
		$start = ( new FrozenClock( '2026-10-20 09:00' ) )->now();
		$this->assertSame( '2026-11-19 15:00:00', Duration::add( $start, 'P30D' )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-10-20 14:15:00', Duration::add( $start, 'PT15M' )->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'UTC', Duration::add( $start, 'P1Y' )->getTimezone()->getName() );
		$this->assertNull( Duration::add( $start, 'thirty days' ) );
	}

	/**
	 * Default and custom retry policies; the last backoff entry repeats.
	 */
	public function test_retry_policy() {
		$email = array( 'type' => 'send_email' );
		$this->assertSame( 'PT15M', Retry::delay( $email, 0 ) );
		$this->assertSame( 'PT15M', Retry::delay( $email, 1 ) );
		$this->assertNull( Retry::delay( $email, 2 ) );

		$webhook = array( 'type' => 'call_webhook' );
		$this->assertSame( array( 'PT1M', 'PT10M', 'PT1H' ), array( Retry::delay( $webhook, 0 ), Retry::delay( $webhook, 1 ), Retry::delay( $webhook, 2 ) ) );
		$this->assertNull( Retry::delay( $webhook, 3 ) );

		$this->assertNull( Retry::delay( array( 'type' => 'update_record' ), 0 ), 'Other steps do not retry by default.' );

		$custom = array(
			'type'  => 'update_record',
			'retry' => array(
				'max'     => 3,
				'backoff' => array( 'PT5M', 'PT1H' ),
			),
		);
		$this->assertSame( array( 'PT5M', 'PT1H', 'PT1H' ), array( Retry::delay( $custom, 0 ), Retry::delay( $custom, 1 ), Retry::delay( $custom, 2 ) ) );
		$this->assertNull( Retry::delay( $custom, 3 ) );

		$single = array(
			'type'  => 'wait',
			'retry' => array(
				'max'     => 1,
				'backoff' => 'PT2H',
			),
		);
		$this->assertSame( 'PT2H', Retry::delay( $single, 0 ), 'One duration for every retry.' );

		$no_backoff = array(
			'type'  => 'condition',
			'retry' => array( 'max' => 1 ),
		);
		$this->assertSame( 'PT15M', Retry::delay( $no_backoff, 0 ) );

		$off = array(
			'type'  => 'send_email',
			'retry' => array( 'max' => 0 ),
		);
		$this->assertNull( Retry::delay( $off, 0 ), 'max 0 turns retries off.' );
	}

	/**
	 * InlineScheduler holds its hooks, drains due ones in time order (including follow-ups), passes other
	 * hooks through, and hands what is left to the fallback.
	 */
	public function test_inline_scheduler() {
		$fallback = new FakeScheduler();
		$fallback->schedule( 'pegwork_test_inline', array( 1 ), 500 );
		$inline = new InlineScheduler( $fallback, array( 'pegwork_test_inline' ) );

		$this->assertTrue( $inline->schedule( 'pegwork_test_inline', array( 1 ), 100 ) );
		$this->assertNull( $fallback->next( 'pegwork_test_inline', array( 1 ) ), 'Replaces the fallback firing.' );
		$inline->schedule( 'pegwork_test_inline', array( 2 ), 50 );
		$inline->schedule( 'pegwork_test_inline', array( 3 ), 900 );
		$inline->schedule( 'pegwork_test_other', array(), 10 );
		$this->assertSame( 10, $fallback->next( 'pegwork_test_other', array() ), 'Other hooks go to the fallback.' );
		$this->assertSame( 100, $inline->next( 'pegwork_test_inline', array( 1 ) ) );

		$order = array();
		add_action(
			'pegwork_test_inline',
			function ( $n ) use ( &$order, $inline ) {
				$order[] = $n;
				if ( 2 === $n ) {
					$inline->schedule( 'pegwork_test_inline', array( 4 ), 60 );
				}
			}
		);
		$this->assertSame( 3, $inline->drain( 200 ) );
		$this->assertSame( array( 2, 4, 1 ), $order );

		$this->assertSame( 1, $inline->flush() );
		$this->assertSame( 900, $fallback->next( 'pegwork_test_inline', array( 3 ) ) );
		$this->assertSame( 0, $inline->drain( 1000 ) );

		$inline->unschedule( 'pegwork_test_inline', array( 3 ) );
		$this->assertNull( $fallback->next( 'pegwork_test_inline', array( 3 ) ) );
	}
}
