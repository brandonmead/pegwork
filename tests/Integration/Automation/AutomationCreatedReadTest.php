<?php
/**
 * Integration tests: reads by creation time (`created`) run in the engine.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Context;
use PegworkAutomation\DryRun;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Steps\Reads;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Automation v4 PR 7 (docs/automation-v4-design.md section 2.5): the module resolves a read's `created`
 * operands (`Y-m-d`, `{ date, offset }`, `{ var }`) to site days and passes them to core, so worked example (b)
 * `barn_volunteer_retention` of the `v4` fixture runs: it finds applications older than two years by
 * creation time and trashes them within the cap of 50 a run.
 */
class AutomationCreatedReadTest extends AutomationTestCase {

	/**
	 * Registers the v4 fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/v4';
	}

	/**
	 * Site in New York, the volunteer manager's address, a fresh mail mock.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'barn_settings', array( 'volunteer_manager' => 'manager@example.org' ) );
		reset_phpmailer_instance();
	}

	/**
	 * Restores the time zone.
	 */
	public function tear_down() {
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * A volunteer application created at a site time.
	 *
	 * @param string $created Site time, `Y-m-d H:i:s`.
	 * @param string $stage   Stage.
	 * @return int Record ID.
	 */
	private function volunteer( $created, $stage = 'new' ) {
		global $wpdb;
		$id = Records::create(
			'barn_volunteer',
			array(
				'name'     => 'Applicant ' . $created,
				'email'    => 'applicant@example.org',
				'interest' => 'garden',
				'stage'    => $stage,
			),
			'pending'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Backdate the creation time.
			$wpdb->posts,
			array(
				'post_date'     => $created,
				'post_date_gmt' => get_gmt_from_date( $created ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		return $id;
	}

	/**
	 * Fires the retention schedule once, in process, as `wp pegwork automation run --now` does.
	 *
	 * @return array<string, mixed> The run row.
	 */
	private function fire() {
		$manual = new Manual( $this->clock );
		$result = $manual->run( 'barn_volunteer_retention' );
		$manual->finish();
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertCount( 1, $result['runs'] );
		return $this->run_row( $result['runs'][0] );
	}

	/**
	 * Worked example (b): 52 old applications, one old but placed, one recent, and one created just after
	 * the cutoff day starts (site time). The first day moves 50 to the trash (the find's limit and the cap),
	 * the next day the other 2 plus the one the moving cutoff now reaches; placed and recent stay.
	 */
	public function test_retention_example_runs_and_trashes_within_the_cap() {
		$this->assertNull( Engine::instance()->unsupported( pegwork_registry()->automation( 'barn_volunteer_retention' ) ) );

		$old = array();
		for ( $i = 0; $i < 52; $i++ ) {
			$old[] = $this->volunteer( '2024-06-01 10:00:00' );
		}
		$placed   = $this->volunteer( '2024-06-01 10:00:00', 'placed' );
		$recent   = $this->volunteer( '2025-06-01 10:00:00' );
		$boundary = $this->volunteer( '2024-11-02 00:30:00' ); // 04:30 UTC: on the cutoff day in New York.

		$run = $this->fire();
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( 50, $run['context']['trashed'] );
		$find = array_values( array_filter( $this->steps( $run['id'] ), fn( $row ) => 'old' === $row['step_id'] ) )[0];
		$this->assertSame( array( 'before' => '2024-11-02' ), $find['summary']['created'], 'Today minus two years, in site time.' );
		$this->assertSame( 52, $find['summary']['count'] );
		$this->assertSame( array_fill( 0, 50, 'trash' ), array_map( 'get_post_status', array_slice( $old, 0, 50 ) ) );
		$this->assertSame( array( 'pending', 'pending' ), array_map( 'get_post_status', array_slice( $old, 50 ) ) );
		$this->assertSame( array( 'pending', 'pending', 'pending' ), array_map( 'get_post_status', array( $placed, $recent, $boundary ) ) );
		$mail = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $mail );
		$this->assertSame( '50 volunteer applications moved to the trash', $mail[0]['subject'] );

		$this->clock->advance( 'P1D' );
		$run = $this->fire();
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( 3, $run['context']['trashed'] );
		$this->assertSame( array( 'trash', 'trash', 'trash' ), array_map( 'get_post_status', array( $old[50], $old[51], $boundary ) ) );
		$this->assertSame( array( 'pending', 'pending' ), array_map( 'get_post_status', array( $placed, $recent ) ) );
	}

	/**
	 * The operands resolve in the module: a literal day stays, `{ date, offset }` and `{ var }` resolve to site
	 * days (a datetime becomes its New York day), an empty `{ var }` stays '' and matches nothing, and a value
	 * that is not a date reaches core, which refuses it.
	 */
	public function test_operands_resolve_to_site_days() {
		$context = new Context( $this->clock, null, array( 'when' => '2026-01-01T03:00:00+00:00' ) );
		$this->assertSame( array( 'before' => '2025-01-01' ), Reads::created( array( 'before' => '2025-01-01' ), $context ) );
		$this->assertSame(
			array(
				'before' => '2026-10-02',
				'after'  => '2025-12-31',
			),
			Reads::created(
				array(
					'before' => array(
						'date'   => 'today',
						'offset' => '-P1M',
					),
					'after'  => array( 'var' => 'trigger.when' ),
				),
				$context
			),
			'03:00 UTC on January 1 is still December 31 in New York.'
		);
		$this->assertSame( array( 'after' => '' ), Reads::created( array( 'after' => array( 'var' => 'trigger.missing' ) ), $context ) );

		$old = $this->volunteer( '2024-06-01 10:00:00' );
		$this->assertSame( array( $old ), Records::find( 'barn_volunteer', array( 'status' => array( 'pending' ) ) + array( 'created' => Reads::created( array( 'before' => '2025-01-01' ), $context ) ) )['ids'] );
		$this->assertSame( array(), Records::find( 'barn_volunteer', array( 'status' => array( 'pending' ) ) + array( 'created' => Reads::created( array( 'after' => array( 'var' => 'trigger.missing' ) ), $context ) ) )['ids'] );
		$this->assertWPError( Records::find( 'barn_volunteer', array( 'created' => Reads::created( array( 'before' => 'soon' ), $context ) ) ) );
	}

	/**
	 * The dry run shows the resolved cutoff on the read line and in its detail, and moves nothing.
	 */
	public function test_dry_run_shows_created() {
		$old    = $this->volunteer( '2024-06-01 10:00:00' );
		$report = DryRun::report( 'barn_volunteer_retention', null, null );
		$steps  = array_column( $report['steps'], null, 'id' );
		$this->assertSame( array( 'before' => '2024-11-02' ), $steps['old']['detail']['created'] );
		$this->assertStringContainsString( 'Find records old: 1 Volunteer found, 1 returned', $steps['old']['message'] );
		$this->assertStringContainsString( 'Created before November 2, 2024.', $steps['old']['message'] );
		$this->assertSame( 'pending', get_post_status( $old ) );
		$this->assertSame( array(), $this->runs( 'barn_volunteer_retention' ) );
	}
}
