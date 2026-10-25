<?php
/**
 * Unit tests: date wait targets, the 2-year clamp and horizon, and the wording.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use DateTimeImmutable;
use DateTimeZone;
use PegworkAutomation\WaitUntil;
use WP_UnitTestCase;

/**
 * WaitUntil (docs/automation-v4-design.md section 3.2): the target is the date at `at` in site time, moved by
 * the signed offset. The site is in New York, where DST starts 2027-03-14 02:00 and ends 2027-11-07 02:00.
 */
class WaitUntilTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * Site time zone New York.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
	}

	/**
	 * A UTC time as `Y-m-d H:i`.
	 *
	 * @param DateTimeImmutable|null $time Time.
	 * @return string|null
	 */
	private static function utc( $time ) {
		return null === $time ? null : $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i' );
	}

	/**
	 * The target from the date, `at` and the offset, on both sides of the DST change.
	 */
	public function test_target_from_date_at_and_offset() {
		$this->assertSame( '2027-03-20 13:00', self::utc( WaitUntil::target( '2027-03-20', '09:00' ) ), 'EDT: 09:00 is 13:00 UTC.' );
		$this->assertSame( '2027-03-10 14:00', self::utc( WaitUntil::target( '2027-03-10', '09:00' ) ), 'EST: 09:00 is 14:00 UTC.' );
		$this->assertSame( '2027-03-17 13:00', self::utc( WaitUntil::target( '2027-03-20', '09:00', '-P3D' ) ), 'Three days before, at 09:00 EDT.' );
		$this->assertSame( '2027-03-12 14:00', self::utc( WaitUntil::target( '2027-03-15', '09:00', '-P3D' ) ), 'Across the DST start, the day offset keeps 09:00 (EST).' );
		$this->assertSame( '2027-11-08 14:00', self::utc( WaitUntil::target( '2027-11-05', '09:00', '+P3D' ) ), 'Across the DST end, the day offset keeps 09:00 (EST).' );
		$this->assertSame( '2027-03-20 04:00', self::utc( WaitUntil::target( '2027-03-20', '00:00' ) ), 'Midnight.' );
		$this->assertSame( '2027-04-20 13:00', self::utc( WaitUntil::target( '2027-03-20', '09:00', 'P1M' ) ), 'An unsigned offset moves later.' );
		$this->assertSame( '2027-03-20 13:00', self::utc( WaitUntil::target( '2027-03-20', '09:00', 'P0D' ) ), 'A zero offset.' );
	}

	/**
	 * Time-unit offsets are elapsed time (PHP's DateTime), so they cross the DST change by real hours.
	 */
	public function test_negative_and_time_unit_offsets() {
		$this->assertSame( '2027-03-20 11:00', self::utc( WaitUntil::target( '2027-03-20', '09:00', '-PT2H' ) ) );
		$this->assertSame( '2027-03-20 15:30', self::utc( WaitUntil::target( '2027-03-20', '09:00', '+PT2H30M' ) ) );
		$this->assertSame( '2027-03-14 05:30', self::utc( WaitUntil::target( '2027-03-14', '03:30', '-PT2H' ) ), '03:30 EDT minus two real hours is 00:30 EST.' );
		$this->assertSame( '2027-03-14 08:00', self::utc( WaitUntil::target( '2027-03-13', '23:00', '+PT4H' ) ), '23:00 EST plus four real hours is 04:00 EDT.' );
		$this->assertSame( '2027-03-12 12:00', self::utc( WaitUntil::target( '2027-03-15', '09:00', '-P2DT26H' ) ), 'Days in site time, then hours elapsed.' );
	}

	/**
	 * Values that are not dates give no target (the run waits for a date).
	 */
	public function test_no_target_without_a_date() {
		foreach ( array( null, '', '2027-02-30', '20.3.2027', '2027-03-20T09:00', 42 ) as $value ) {
			$this->assertNull( WaitUntil::target( $value, '09:00' ), wp_json_encode( $value ) );
		}
		$this->assertNull( WaitUntil::target( '2027-03-20', '25:00' ), 'A bad time of day.' );
	}

	/**
	 * One wait spans at most two years; an empty date waits at most two years from the first arrival.
	 */
	public function test_clamp_and_horizon() {
		$now = new DateTimeImmutable( '2027-03-01 15:00', new DateTimeZone( 'UTC' ) );
		$this->assertSame( '2027-03-17 13:00', self::utc( WaitUntil::clamp( WaitUntil::target( '2027-03-20', '09:00', '-P3D' ), $now ) ), 'A near target is kept.' );
		$this->assertSame( '2029-03-01 15:00', self::utc( WaitUntil::clamp( WaitUntil::target( '2031-01-01', '09:00' ), $now ) ), 'A far target is clamped to two years.' );
		$this->assertSame( '2029-03-01 15:00', self::utc( WaitUntil::horizon( $now ) ) );
		$this->assertSame( '2029-06-01 14:00', self::utc( WaitUntil::horizon( new DateTimeImmutable( '2027-06-01 14:00', new DateTimeZone( 'UTC' ) ) ) ), 'Two calendar years in site time (EDT to EDT).' );
	}

	/**
	 * The wording of a wait, and the key a var names.
	 */
	public function test_describe_and_key() {
		$this->assertSame( '3 days before Due on at 09:00', WaitUntil::describe( array( 'offset' => '-P3D', 'at' => '09:00' ), 'Due on' ) );
		$this->assertSame( '2 hours after due_on at 07:30', WaitUntil::describe( array( 'offset' => '+PT2H', 'at' => '07:30' ), 'due_on' ) );
		$this->assertSame( '7 days before due_on at 09:00', WaitUntil::describe( array( 'offset' => '-P1W' ), 'due_on' ), 'Weeks read as days.' );
		$this->assertSame( '1 day, 2 hours after due_on at 09:00', WaitUntil::describe( array( 'offset' => 'P1DT2H' ), 'due_on' ) );
		$this->assertSame( 'due_on at 09:00', WaitUntil::describe( array(), 'due_on' ) );
		$this->assertSame( 'due_on', WaitUntil::key( 'record.due_on' ) );
		$this->assertSame( '', WaitUntil::key( 'steps.gift.record.due_on' ) );
	}
}
