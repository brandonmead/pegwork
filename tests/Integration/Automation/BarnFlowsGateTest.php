<?php
/**
 * Phase 6, Automation v3 and Automation v4 gate: the BarnCRM flows on simulated time.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\BulkRun;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Overrides;
use PegworkAutomation\Schema;
use PegworkAutomation\Webhooks\Secrets;
use RuntimeException;
use PegworkForms\Schema as FormsSchema;
use PegworkForms\Submission;
use PegworkForms\Token;
use WP_REST_Request;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';
require_once dirname( __DIR__, 3 ) . '/modules/forms/module.php'; // Registers the forms module autoloader.

/**
 * Runs `tests/fixtures/valid/barn` (design `docs/automation-v3-design.md` section 8, "Simulated-time gate
 * BarnFlowsGateTest", and the volunteer timeline of `docs/phase-6-design.md` section 11) with a frozen clock
 * in site time, the fake scheduler and the mock mailer. The site is in New York, so the donor flow's 30-day
 * wait crosses the start of daylight saving time (2027-03-14) and must still end at 09:00 site time. Gifts
 * are linked to their donor through `gift_donor`, as staff or the inbound webhook record them. Volunteers
 * sign up through the real form pipeline as visitors.
 *
 * Automation v4 (`docs/automation-v4-design.md` section 7, "Gate BarnFlowsGateTest, v4 timelines"): the due
 * reminder, retention, shared receipt and review timelines. The v3 timelines run with the v4 automations that
 * are not part of their flow turned off (only_v3()), so they pass unchanged except for the receipt each
 * thanked gift now starts.
 */
class BarnFlowsGateTest extends AutomationTestCase {

	/**
	 * Options page values.
	 */
	private const SETTINGS = array(
		'development_email'  => 'development@barn.example',
		'events_coordinator' => 'events@barn.example',
		'garden_coordinator' => 'garden@barn.example',
		'volunteer_manager'  => 'manager@barn.example',
		'donate_url'         => 'https://barn.example/donate',
	);

	/**
	 * Subject of the major-gift thank-you (email A).
	 */
	private const EMAIL_A = 'Thank you for your generous gift';

	/**
	 * Subject of the thank-you for other gifts (email B).
	 */
	private const EMAIL_B = 'Thank you for your gift';

	/**
	 * Subject of the first-gift follow-up.
	 */
	private const FOLLOW_UP = 'What your gift made possible';

	/**
	 * Steps of a donor run up to the wait, by branch.
	 */
	private const MAJOR_PATH = array( 'donor', 'has_donor', 'size', 'thank_major', 'call_task', 'stamp_donor', 'receipt', 'gifts', 'first_gift', 'grace' );

	/**
	 * Steps of a donor run up to the wait, otherwise branch.
	 */
	private const OTHER_PATH = array( 'donor', 'has_donor', 'size', 'thank', 'stamp_donor', 'receipt', 'gifts', 'first_gift', 'grace' );

	/**
	 * The v4 automations a v3 timeline does not exercise (the receipt is part of the donor flow).
	 */
	private const V4_OTHERS = array( 'barn_task_due_reminder', 'barn_volunteer_retention', 'barn_volunteer_review' );

	/**
	 * Mails already checked: assertions read only the ones sent since.
	 *
	 * @var int
	 */
	private $seen = 0;

	/**
	 * Installs the forms module's table too (DDL outside the per-test transaction).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		FormsSchema::install();
	}

	/**
	 * Package directory: the BarnCRM proof fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/barn';
	}

	/**
	 * Site in New York with the settings filled in; forms module booted; mock mailer reset.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'gmt_offset', '' );
		update_option( 'barn_settings', self::SETTINGS );
		( require dirname( __DIR__, 3 ) . '/modules/forms/module.php' )->boot();
		Submission::reset();
		reset_phpmailer_instance();
		$this->seen             = 0;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
	}

	/**
	 * Restores the request globals.
	 */
	public function tear_down() {
		unset( $_GET['view'], $_GET['run'] );
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Donor acknowledgment and first-gift follow-up, one automation (design section 8, donor steps 1 to 5).
	 */
	public function test_donor_timeline() {
		$this->only_v3();
		$ada   = $this->contact( 'Ada Lovelace', 'ada@donor.example' );
		$grace = $this->contact( 'Grace Hopper', 'grace@donor.example' );
		$carl  = $this->contact( 'Carl Sagan', 'carl@donor.example' );

		// 1. 2027-03-01 09:00: G1 (Ada, 300) takes the major branch; G2 (Grace, 40) the other.
		$this->clock->set( '2027-03-01 09:00' );
		$g1  = $this->gift( 300, $ada );
		$run = $this->thanks_run( $g1 );
		$this->assertSame( self::MAJOR_PATH, $this->path( $run ) );
		$this->assertSame(
			array(
				'branch' => 'Major gift (250 or more)',
				'index'  => 0,
			),
			$this->step_row( $run, 'size' )['summary']
		);
		$mail = $this->thanked_with_receipt( 'ada@donor.example', self::EMAIL_A );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $mail['header'] );
		$this->assertStringContainsString( 'Dear Ada Lovelace,', $mail['body'] );
		$this->assertStringContainsString( 'Thank you for your gift of $300 on March 1, 2027. Our director will call you', $mail['body'] );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $mail['body'] );
		$tasks = $this->tasks( $g1 );
		$this->assertCount( 1, $tasks, 'The major gift creates one call task, linked to the gift.' );
		$this->assertSame(
			array(
				'name'           => 'Thank-you call: Ada Lovelace',
				'due_on'         => '2027-03-03',
				'assignee_email' => self::SETTINGS['development_email'],
			),
			array_intersect_key( $tasks[0]['values'], array_flip( array( 'name', 'due_on', 'assignee_email' ) ) )
		);
		$this->assertSame( 'publish', $tasks[0]['status'] );
		$this->assertSame( '2027-03-01', $this->value( $ada, 'last_gift_on' ) );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'gifts_later', $run['current_step'] );
		$this->assertSame( '2027-03-31 13:00:00', $run['resume_at'], '30 days later at 09:00 New York time, after the DST change (UTC-4).' );
		$this->assertSame( 0, (int) $run['depth'] );

		$g2  = $this->gift( 40, $grace );
		$run = $this->thanks_run( $g2 );
		$this->assertSame( self::OTHER_PATH, $this->path( $run ) );
		$this->assertSame(
			array(
				'branch' => 'otherwise',
				'index'  => -1,
			),
			$this->step_row( $run, 'size' )['summary']
		);
		$mail = $this->thanked_with_receipt( 'grace@donor.example', self::EMAIL_B );
		$this->assertStringNotContainsString( 'Reply-To:', $mail['header'] );
		$this->assertStringContainsString( 'Thank you for your gift of $40 on March 1, 2027.', $mail['body'] );
		$this->assertSame( array(), $this->tasks( $g2 ), 'A gift under 250 creates no task.' );
		$this->assertSame( 'waiting', $run['status'] );

		// 2. Same day: Carl gives twice. Exactly 250 takes the major branch; the first run waits, the second
		// counts two gifts and stops at first_gift (the Phase 6 same-day limit is gone).
		$c1  = $this->gift( 250, $carl );
		$run = $this->thanks_run( $c1 );
		$this->assertSame( self::MAJOR_PATH, $this->path( $run ) );
		$this->thanked_with_receipt( 'carl@donor.example', self::EMAIL_A );
		$this->assertCount( 1, $this->tasks( $c1 ) );
		$this->assertSame( 'waiting', $run['status'] );
		$carl_first = $run;
		$c2         = $this->gift( 20, $carl );
		$run        = $this->thanks_run( $c2 );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:first_gift', $run['context']['outcome'] );
		$this->assertSame( array_slice( self::OTHER_PATH, 0, 8 ), $this->path( $run ) );
		$this->assertSame( 2, $this->step_row( $run, 'gifts' )['summary']['count'] );
		$this->thanked_with_receipt( 'carl@donor.example', self::EMAIL_B );

		// 3. 2027-03-10: G3 (Ada, 75) is thanked; its run stops at first_gift.
		$this->clock->set( '2027-03-10 09:00' );
		$g3  = $this->gift( 75, $ada );
		$run = $this->thanks_run( $g3 );
		$this->assertSame( 'stopped_at:first_gift', $run['context']['outcome'] );
		$mail = $this->thanked_with_receipt( 'ada@donor.example', self::EMAIL_B );
		$this->assertStringContainsString( 'Thank you for your gift of $75 on March 10, 2027.', $mail['body'] );
		$this->assertSame( '2027-03-10', $this->value( $ada, 'last_gift_on' ) );

		// Grace corrects her address during the wait: the donor is re-read after it.
		$this->clock->set( '2027-03-20 12:00' );
		$this->assertTrue( Records::update( $grace, array( 'email' => 'grace@new.example' ) ) );

		// 4. Nothing is sent on 2027-03-30 or before 09:00 on 2027-03-31; then Ada's and Carl's first runs
		// see their later gift, and Grace gets the one follow-up.
		$this->clock->set( '2027-03-30 09:00' );
		$this->assertSame( 0, $this->drain() );
		$this->clock->set( '2027-03-31 08:59' );
		$this->assertSame( 0, $this->drain(), 'Not before 09:00 site time.' );
		$this->assertSame( array(), $this->new_mail() );
		$this->clock->set( '2027-03-31 09:00' );
		$this->assertSame( 3, $this->drain() );
		foreach ( array( $this->thanks_run( $g1 ), $carl_first ) as $run ) {
			$run = $this->run_row( $run['id'] );
			$this->assertSame( 'completed', $run['status'] );
			$this->assertSame( 'stopped_at:no_second_gift', $run['context']['outcome'] );
			$this->assertSame( 2, $this->step_row( $run, 'gifts_later' )['summary']['count'] );
		}
		$run = $this->run_row( $this->thanks_run( $g2 )['id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $run['context'] );
		$this->assertSame( array_merge( self::OTHER_PATH, array( 'gifts_later', 'no_second_gift', 'follow_up' ) ), $this->path( $run ) );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'grace@new.example', self::FOLLOW_UP ), array( $mail['to'][0][0], $mail['subject'] ), 'The corrected address.' );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $mail['header'] );
		$this->assertStringContainsString( 'Dear Grace Hopper,', $mail['body'] );
		$this->assertStringContainsString( 'If you would like to give again: ' . self::SETTINGS['donate_url'], $mail['body'] );

		// Grace's path in `log --run` and the run detail: the branch step indented, no private value shown.
		$rows = AutomationCommand::step_rows( $run, $this->steps( $run['id'] ), true );
		$this->assertSame( array( 'donor', 'has_donor', 'size', '  thank', 'stamp_donor', 'receipt', 'gifts', 'first_gift', 'grace', 'gifts_later', 'no_second_gift', 'follow_up' ), array_column( $rows, 'step' ) );
		$html = $this->run_detail( $run['id'] );
		$this->assertStringContainsString( 'Read: Contact #' . $grace . ' (Grace Hopper)', $html, 'The read line names the donor read.' );
		$this->assertStringContainsString( 'Otherwise branch of <code>size</code></td></tr><tr><th scope="row" class="pegwork-depth-1"><code>thank</code>', $html );
		$this->assertStringNotContainsString( 'grace@new.example', $html, 'No private value in the run detail.' );

		// 5. A gift with no donor link stops at has_donor with no email (behavior change, open question 8).
		$anonymous = $this->gift( 20, null );
		$run       = $this->thanks_run( $anonymous );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:has_donor', $run['context']['outcome'] );
		$this->assertSame( array( 'donor', 'has_donor' ), $this->path( $run ) );
		$this->assertSame( array(), $this->new_mail() );

		// Totals: five thank-yous each with its receipt and one follow-up, two call tasks, no milestone,
		// nothing failed or waiting.
		$subjects = array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
		$receipt  = $this->receipt_subject();
		$this->assertSame( array( self::EMAIL_A, $receipt, self::EMAIL_B, $receipt, self::EMAIL_A, $receipt, self::EMAIL_B, $receipt, self::EMAIL_B, $receipt, self::FOLLOW_UP ), $subjects );
		$this->assertCount( 2, $this->tasks() );
		$this->assertCount( 5, $this->runs( 'barn_gift_receipt' ), 'One receipt run per thanked gift, none for the gift without a donor.' );
		$all = array_merge( $this->runs( 'barn_gift_thanks' ), $this->runs( 'barn_lifetime_milestone' ) );
		$this->assertCount( 12, $all, 'Each of the six gifts starts both gift automations.' );
		$all = array_merge( $all, $this->runs( 'barn_gift_receipt' ) );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( array_map( fn( $run ) => $this->run_row( $run['id'] ), $all ), 'status' ) ) ) );
	}

	/**
	 * Lifetime giving reaches 1,000 (design section 8, milestone steps 1 to 3).
	 */
	public function test_milestone_timeline() {
		$this->only_v3();
		$mo   = $this->contact( 'Mo Ibrahim', 'mo@donor.example' );
		$zora = $this->contact( 'Zora Neale', 'zora@donor.example' );

		// 1. 2027-04-01 to 04-03: 600, 300 and 100. The third gift reaches 1,000: one email, then the stamp.
		$days = array(
			'2027-04-01' => 600,
			'2027-04-02' => 300,
		);
		foreach ( $days as $day => $amount ) {
			$this->clock->set( "$day 10:00" );
			$run = $this->milestone_run( $this->gift( $amount, $mo ) );
			$this->assertSame( 'stopped_at:reached', $run['context']['outcome'] );
		}
		$this->clock->set( '2027-04-03 10:00' );
		$run = $this->milestone_run( $this->gift( 100, $mo ) );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $run['context'] );
		$this->assertSame( array( 'donor', 'has_donor', 'not_yet', 'giving', 'reached', 'stamp', 'celebrate' ), $this->path( $run ) );
		$this->assertSame( 3, $this->step_row( $run, 'giving' )['summary']['count'] );
		$milestones = $this->milestone_mail();
		$this->assertCount( 1, $milestones );
		$this->assertSame( array( 'mo@donor.example', 'Thank you for 3 gifts to ' . get_bloginfo( 'name' ) ), array( $milestones[0]['to'][0][0], $milestones[0]['subject'] ) );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $milestones[0]['header'] );
		$this->assertStringContainsString( 'Your gifts to ' . get_bloginfo( 'name' ) . ' now total $1,000.00. Thank you', $milestones[0]['body'] );
		$this->assertSame( '2027-04-03', $this->value( $mo, 'milestone_1000_on' ) );

		// 2. 2027-04-04: a further gift stops at not_yet; no second milestone email.
		$this->clock->set( '2027-04-04 10:00' );
		$run = $this->milestone_run( $this->gift( 50, $mo ) );
		$this->assertSame( 'stopped_at:not_yet', $run['context']['outcome'] );
		$this->assertCount( 1, $this->milestone_mail() );

		// 3. Zora: a trashed 500 gift and a draft 500 gift are not counted, so a live 500 gift sends nothing.
		$trashed = $this->gift( 500, $zora );
		$this->assertInstanceOf( \WP_Post::class, wp_trash_post( $trashed ) );
		$this->gift( 500, $zora, 'draft' );
		$run = $this->milestone_run( $this->gift( 500, $zora ) );
		$this->assertSame( 'stopped_at:reached', $run['context']['outcome'] );
		$giving = $this->step_row( $run, 'giving' )['summary'];
		$this->assertSame( 1, $giving['count'] );
		$this->assertSame( array( 'amount' => 500 ), array_map( 'intval', $giving['sum'] ) );
		$this->assertCount( 1, $this->milestone_mail() );
		$this->assertSame( '', (string) $this->value( $zora, 'milestone_1000_on' ) );
	}

	/**
	 * Monday digest of tasks due (design section 8, digest steps 1 to 5).
	 */
	public function test_digest_timeline() {
		$this->only_v3();
		// 1. T1 (due 06-08), T2 (due 06-10, done), T3 (due 06-20), T4 (due 06-01, open, no assignee email).
		$t1 = $this->task( 'Call Ada', '2027-06-08', 'ada.task@barn.example' );
		$this->task( 'Send receipts', '2027-06-10', 'receipts@barn.example', true );
		$t3 = $this->task( 'Plan the gala', '2027-06-20', 'gala@barn.example' );
		$t4 = $this->task( 'Thank Bo', '2027-06-01', null );

		// 2. Monday 2027-06-07 08:00: T4 then T1; T4's reminder fails and the loop goes on; both stamped.
		$this->clock->set( '2027-06-07 08:00' );
		$this->assertSame( '1', wp_date( 'N', $this->clock->now()->getTimestamp() ), 'A Monday.' );
		$run = $this->digest();
		$this->assertSame( array( 'due', 'each_task', 'remind#1:failed', 'stamp#1', 'remind#2', 'stamp#2', 'summary' ), $this->trace( $run ) );
		$this->assertSame( array( $t4, $t1 ), $this->step_row( $run, 'each_task' )['summary']['ids'] );
		$this->assertStringStartsWith( 'no_recipient:', $this->steps( $run['id'] )[2]['error'] );
		$this->assertSame( '2027-06-07', $this->value( $t4, 'reminded_on' ), 'The failed reminder does not stop the stamp.' );
		$this->assertSame( '2027-06-07', $this->value( $t1, 'reminded_on' ) );
		$mails = $this->new_mail();
		$this->assertCount( 2, $mails );
		$this->assertSame( array( 'ada.task@barn.example', 'Due June 8, 2027: Call Ada' ), array( $mails[0]['to'][0][0], $mails[0]['subject'] ) );
		$this->assertStringContainsString( 'Call Ada is due June 8, 2027. Mark it done when finished: ' . admin_url( 'post.php?post=' . $t1 . '&action=edit' ), $mails[0]['body'] );

		// 3. The summary lists T4 and T1 in due order.
		$this->assertSame( array( self::SETTINGS['development_email'], '2 tasks due by June 14, 2027' ), array( $mails[1]['to'][0][0], $mails[1]['subject'] ) );
		$this->assertStringContainsString( "Open tasks due by June 14, 2027 (the first 50 by due date):\n\n- Thank Bo, due June 1, 2027, no assignee\n- Call Ada, due June 8, 2027, ada.task@barn.example\n", str_replace( "\r\n", "\n", $mails[1]['body'] ) );

		// 4. The outcome names the failed step.
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'completed_with_errors:remind', $run['context']['outcome'] );

		// 5. A second firing the same day creates nothing; on 2027-06-14 T3 is included.
		$this->clock->set( '2027-06-07 15:00' );
		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$this->drain();
		$this->assertCount( 1, $this->runs( 'barn_tasks_digest' ), 'Idempotency: tasks:{{today}}.' );
		$this->assertSame( array(), $this->new_mail() );
		$this->clock->set( '2027-06-14 08:00' );
		$later = $this->digest();
		$this->assertCount( 2, $this->runs( 'barn_tasks_digest' ) );
		$this->assertSame( array( $t4, $t1, $t3 ), $this->step_row( $later, 'each_task' )['summary']['ids'] );
		$mails = $this->new_mail();
		$this->assertSame(
			array( 'Due June 8, 2027: Call Ada', 'Due June 20, 2027: Plan the gala', '3 tasks due by June 21, 2027' ),
			array_column( $mails, 'subject' ),
			'T4 still has no assignee: its reminder fails again; T1 and T3 are reminded; then the summary.'
		);
	}

	/**
	 * The donation platform webhook finds or creates the contact and records a linked gift, which the
	 * thank-you flow (depth 1) then acknowledges; staff resend the thank-you from the gift (design section 8,
	 * inbound; worked example d).
	 */
	public function test_inbound_and_manual() {
		$this->only_v3();
		$this->clock->set( '2027-05-10 11:00' );

		// A new email: one contact, one gift linked to it, thanked at depth 1.
		$response = $this->inbound(
			array(
				'email'  => 'nia@donor.example',
				'name'   => 'Nia Long',
				'amount' => 120,
			)
		);
		$this->assertSame( 202, $response->get_status() );
		$this->drain();
		$run = $this->run_row( $response->get_data()['run_id'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'has_email', 'existing', 'new_contact', 'add_contact', 'contact', 'add_gift' ), $this->path( $run ) );
		$contacts = $this->contacts_by_email( 'nia@donor.example' );
		$this->assertCount( 1, $contacts );
		$this->assertSame( 'private', Records::get( $contacts[0] )['status'] );
		$gifts = Records::find(
			'barn_gift',
			array(
				'related' => array(
					'relationship' => 'gift_donor',
					'of'           => $contacts[0],
				),
			)
		)['ids'];
		$this->assertCount( 1, $gifts );
		$gift = Records::get( $gifts[0] );
		$this->assertSame( array( 'Gift from Nia Long', 120, '2027-05-10' ), array( $gift['values']['name'], (int) $gift['values']['amount'], $gift['values']['received_on'] ) );
		$this->assertSame( 'private', $gift['status'] );
		$thanks = $this->thanks_run( $gifts[0] );
		$this->assertSame( 1, (int) $thanks['depth'], 'Started by add_gift.' );
		$this->assertSame( self::OTHER_PATH, $this->path( $thanks ) );
		$receipt = $this->run_for( 'barn_gift_receipt', $gifts[0] );
		$this->assertSame( 2, (int) $receipt['depth'], 'The inbound path: webhook at 0, thanks at 1, receipt at 2.' );
		$this->assertSame( 'completed', $receipt['status'] );
		$mail = $this->thanked_with_receipt( 'nia@donor.example', self::EMAIL_B );
		$this->assertStringContainsString( 'Dear Nia Long,', $mail['body'] );
		$this->assertStringContainsString( 'Thank you for your gift of $120 on May 10, 2027.', $mail['body'] );

		// The same email again reuses the contact; its gift is her second.
		$again = $this->inbound(
			array(
				'email'  => 'nia@donor.example',
				'name'   => 'Nia Long',
				'amount' => 80,
			)
		);
		$this->drain();
		$this->assertSame( array( 'has_email', 'existing', 'new_contact', 'contact', 'add_gift' ), $this->path( $this->run_row( $again->get_data()['run_id'] ) ) );
		$this->assertCount( 1, $this->contacts_by_email( 'nia@donor.example' ) );
		$this->assertSame( 2, Records::find( 'barn_gift', array( 'count' => true ) )['count'] );
		$this->thanked_with_receipt( 'nia@donor.example', self::EMAIL_B );

		// An empty email stops at has_email and creates nothing.
		$empty = $this->inbound(
			array(
				'email'  => '',
				'name'   => 'Nobody',
				'amount' => 5,
			)
		);
		$this->drain();
		$this->assertSame( 'stopped_at:has_email', $this->run_row( $empty->get_data()['run_id'] )['context']['outcome'] );
		$this->assertSame( 1, Records::find( 'barn_contact', array( 'count' => true ) )['count'] );
		$this->assertSame( 2, Records::find( 'barn_gift', array( 'count' => true ) )['count'] );
		$this->assertSame( array(), $this->new_mail() );

		// Staff resend the thank-you from the first gift (an editor, from the record editor).
		$started = ( new Manual() )->start( 'barn_resend_thanks', $gifts[0], $this->editor, 'editor' );
		$this->assertIsArray( $started );
		$this->drain();
		$run = $this->run_row( $started['run'] );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( array( 'donor', 'has_donor', 'thank' ), $this->path( $run ) );
		$this->assertSame( 'editor', $run['context']['trigger']['source'] );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'nia@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Thank you again for your gift of $120 on May 10, 2027.', $mail['body'] );
	}

	/**
	 * Volunteer intake (design `docs/phase-6-design.md` section 11, volunteer steps 1 to 6; unchanged).
	 */
	public function test_volunteer_timeline() {
		$this->only_v3();
		// 1. 2027-05-03 10:00, form submissions: routed, one notice to the right coordinator.
		$this->clock->set( '2027-05-03 10:00' );
		$v1       = $this->sign_up( 'Vera Garden', 'vera@volunteer.example', 'garden' );
		$v2       = $this->sign_up( 'Evan Events', 'evan@volunteer.example', 'events' );
		$v3       = $this->sign_up( 'Olga Office', 'olga@volunteer.example', 'office' );
		$expected = array(
			array( $v1, 'Garden', 'to_garden', self::SETTINGS['garden_coordinator'], 'vera@volunteer.example', 'Vera Garden', 'garden' ),
			array( $v2, 'Events', 'to_events', self::SETTINGS['events_coordinator'], 'evan@volunteer.example', 'Evan Events', 'events' ),
			array( $v3, 'otherwise', 'to_manager', self::SETTINGS['volunteer_manager'], 'olga@volunteer.example', 'Olga Office', 'office' ),
		);
		$mails    = $this->new_mail();
		$this->assertCount( 3, $mails, 'One notice per volunteer.' );
		$runs = array();
		foreach ( $expected as $i => list( $id, $branch, $step, $coordinator, $email, $name, $interest ) ) {
			$this->assertSame( 'pending', get_post_status( $id ) );
			$this->assertSame( 'new', $this->value( $id, 'stage' ), 'The stage default.' );
			$this->assertSame( $coordinator, $this->value( $id, 'coordinator_email' ) );
			$runs[ $id ] = $this->run_for( 'barn_volunteer_intake', $id );
			$this->assertSame( 'event', $runs[ $id ]['trigger_type'] );
			$this->assertSame( 'waiting', $runs[ $id ]['status'] );
			$this->assertSame( 'check', $runs[ $id ]['current_step'] );
			$this->assertSame( '2027-05-05 14:00:00', $runs[ $id ]['resume_at'], 'Two days at 10:00 New York time (UTC-4).' );
			$this->assertSame( array( 'route', $step, 'notify', 'grace' ), $this->path( $runs[ $id ] ) );
			$this->assertSame( $branch, $this->steps( $runs[ $id ]['id'] )[0]['summary']['branch'] );

			$this->assertSame( array( $coordinator, "New volunteer: $name" ), array( $mails[ $i ]['to'][0][0], $mails[ $i ]['subject'] ) );
			$this->assertStringContainsString( "Reply-To: $email", $mails[ $i ]['header'] );
			$this->assertStringContainsString( "$name signed up to help with $interest. Please reach out within two days, then set Stage to Contacted: " . admin_url( 'post.php?post=' . $id . '&action=edit' ), $mails[ $i ]['body'] );
		}

		// 2. 2027-05-04: Evan's coordinator reaches out.
		$this->clock->set( '2027-05-04 15:00' );
		$this->assertTrue( Records::update( $v2, array( 'stage' => 'contacted' ) ) );
		$this->assertSame( 0, $this->drain() );

		// 3. 2027-05-05: reminders for Vera and Olga; Evan's run completes without one.
		$this->clock->set( '2027-05-05 10:00' );
		$this->assertSame( 3, $this->drain() );
		$mails = $this->new_mail();
		$this->assertSame(
			array(
				array( self::SETTINGS['garden_coordinator'], 'Reminder: Vera Garden is waiting to hear from you' ),
				array( self::SETTINGS['volunteer_manager'], 'Reminder: Olga Office is waiting to hear from you' ),
			),
			array_map( fn( $mail ) => array( $mail['to'][0][0], $mail['subject'] ), $mails )
		);
		$this->assertStringContainsString( 'Vera Garden signed up two days ago and is still marked New: ' . admin_url( 'post.php?post=' . $v1 . '&action=edit' ), $mails[0]['body'] );
		$evan = $this->run_row( $runs[ $v2 ]['id'] );
		$this->assertSame( 'completed', $evan['status'] );
		$this->assertArrayNotHasKey( 'outcome', $evan['context'] );
		$this->assertSame( array( 'route', 'to_events', 'notify', 'grace', 'check' ), $this->path( $evan ) );
		$this->assertSame(
			array(
				'branch' => null,
				'index'  => null,
			),
			$this->steps( $evan['id'] )[4]['summary'],
			'Contacted: no branch matches and the run continues after the decision (to its end).'
		);
		foreach ( array( $v1, $v3 ) as $id ) {
			$run = $this->run_row( $runs[ $id ]['id'] );
			$this->assertSame( 'waiting', $run['status'] );
			$this->assertSame( 'still_new', $run['current_step'] );
			$this->assertSame( '2027-05-08 14:00:00', $run['resume_at'] );
		}

		// 4. 2027-05-06: Olga is contacted.
		$this->clock->set( '2027-05-06 11:00' );
		$this->assertTrue( Records::update( $v3, array( 'stage' => 'contacted' ) ) );

		// 5. 2027-05-08: Vera escalates to the volunteer manager; Olga's run stops at still_new.
		$this->clock->set( '2027-05-08 10:00' );
		$this->assertSame( 2, $this->drain() );
		$mail = $this->only_new_mail();
		$this->assertSame( array( self::SETTINGS['volunteer_manager'], 'Volunteer not contacted after five days: Vera Garden' ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Vera Garden (garden) has not been contacted. Coordinator: ' . self::SETTINGS['garden_coordinator'] . '. ' . admin_url( 'post.php?post=' . $v1 . '&action=edit' ), $mail['body'] );
		$vera = $this->run_row( $runs[ $v1 ]['id'] );
		$this->assertSame( 'completed', $vera['status'] );
		$this->assertArrayNotHasKey( 'outcome', $vera['context'] );
		$olga = $this->run_row( $runs[ $v3 ]['id'] );
		$this->assertSame( 'completed', $olga['status'] );
		$this->assertSame( 'stopped_at:still_new', $olga['context']['outcome'] );
		$this->assertSame( array( 'route', 'to_manager', 'notify', 'grace', 'check', 'remind', 'grace_2', 'still_new' ), $this->path( $olga ) );

		// 6. Vera's log: the path with branch labels, indented in the run detail and in `log --run`.
		$steps = $this->steps( $vera['id'] );
		$this->assertSame( array( 'route', 'to_garden', 'notify', 'grace', 'check', 'remind', 'grace_2', 'still_new', 'escalate' ), $this->path( $vera ) );
		$this->assertSame( array( 'ok' ), array_values( array_unique( array_column( $steps, 'status' ) ) ) );
		$by_id = array_column( $steps, null, 'step_id' );
		$this->assertSame( 'Garden', $by_id['route']['summary']['branch'] );
		$this->assertSame( 'Not contacted yet', $by_id['check']['summary']['branch'] );
		$this->assertTrue( $by_id['still_new']['summary']['passed'] );

		$rows = AutomationCommand::step_rows( $vera, $steps, true );
		$this->assertSame( array( 'route', '  to_garden', 'notify', 'grace', 'check', '  remind', '  grace_2', '  still_new', '  escalate' ), array_column( $rows, 'step' ) );
		$this->assertSame( '{"branch":"Garden","index":1}', $rows[0]['summary'] );
		$this->assertSame( '{"branch":"Not contacted yet","index":0}', $rows[4]['summary'] );

		$html = $this->run_detail( $vera['id'] );
		$this->assertStringContainsString( 'Branch <strong>Garden</strong> of <code>route</code></td></tr><tr><th scope="row" class="pegwork-depth-1"><code>to_garden</code>', $html );
		$this->assertStringContainsString( '<th scope="row"><code>notify</code>', $html );
		$this->assertStringContainsString( 'Branch <strong>Not contacted yet</strong> of <code>check</code></td></tr><tr><th scope="row" class="pegwork-depth-1"><code>remind</code>', $html );
		foreach ( array( 'grace_2', 'still_new', 'escalate' ) as $id ) {
			$this->assertStringContainsString( '<th scope="row" class="pegwork-depth-1"><code>' . $id . '</code>', $html );
		}
		$this->assertSame( 2, substr_count( $html, 'pegwork-branch-row' ) );
		$this->assertStringNotContainsString( 'vera@volunteer.example', $html, 'No private value in the run detail.' );

		// Totals: three notices, two reminders, one escalation; every run completed.
		$this->assertCount( 6, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( array_map( fn( $run ) => $this->run_row( $run['id'] ), $this->runs( 'barn_volunteer_intake' ) ), 'status' ) ) ) );
		$this->assertCount( 3, $this->runs( 'barn_volunteer_intake' ) );
	}

	/**
	 * Due reminder, `barn_task_due_reminder` (v4 design section 7, due reminder steps 1 to 11). Tasks are
	 * created 2027-03-01 10:00 New York time. DST starts 2027-03-14, so 09:00 site time is 14:00 UTC before it
	 * and 13:00 UTC after it.
	 */
	public function test_due_reminder_timeline() {
		$this->clock->set( '2027-03-01 10:00' );
		$due = array(
			'T1'  => '2027-03-20',
			'T2'  => '2027-03-12',
			'T3'  => '2027-03-10',
			'T3b' => '2027-03-10',
			'T4'  => '',
			'T5'  => '2027-03-20',
			'T6'  => '2027-03-02',
			'T7'  => '2026-12-01',
			'T8'  => '2027-03-20',
			'T9'  => '2027-03-20',
		);
		$t   = array();
		foreach ( $due as $name => $date ) {
			$t[ $name ] = $this->task( $name, $date, strtolower( $name ) . '@barn.example' );
		}
		$this->drain();
		$state = fn( $name ) => $this->reminder_state( $t[ $name ] );

		// 1. T1 waits until 03-17 09:00 EDT, the cursor on the wait.
		$this->assertSame( array( 'waiting', 'before_due', '2027-03-17 13:00:00', null ), $state( 'T1' ) );
		$this->assertSame( array( 'waiting', 'before_due', '2027-03-09 14:00:00', null ), $state( 'T2' ), '09:00 EST.' );
		// 5. T4 has no due date: it parks for up to two years from its first arrival.
		$this->assertSame( array( 'waiting', 'before_due', '2029-03-01 15:00:00', null ), $state( 'T4' ) );
		// 7. T6's target (02-27 09:00) has passed: reminded at once.
		$this->assertSame( array( 'completed', 'stamp', null, null ), $state( 'T6' ) );
		// 8. T7, imported overdue: continues, stops at still_open, no email.
		$this->assertSame( 'stopped_at:still_open', $state( 'T7' )[3] );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 't6@barn.example', 'Due March 2, 2027: T6' ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'T6 is due March 2, 2027. Mark it done when finished: ' . admin_url( 'post.php?post=' . $t['T6'] . '&action=edit' ), $mail['body'] );
		$this->assertSame( '2027-03-01', $this->value( $t['T6'], 'reminded_on' ) );

		// 2. 03-05 10:00: T2 moved to 03-07 is nudged and reminded at once (the new target has passed).
		$this->clock->set( '2027-03-05 10:00' );
		$this->edit_task( $t['T2'], array( 'due_on' => '2027-03-07' ) );
		$this->assertSame( 'completed', $state( 'T2' )[0] );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 't2@barn.example', 'Due March 7, 2027: T2' ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertSame( '2027-03-05', $this->value( $t['T2'], 'reminded_on' ) );
		// 3. T3 moved to 03-31: waits until 03-28 09:00.
		$this->edit_task( $t['T3'], array( 'due_on' => '2027-03-31' ) );
		$this->assertSame( array( 'waiting', 'before_due', '2027-03-28 13:00:00', null ), $state( 'T3' ) );
		// 4. T3b: the same move written straight to post meta (no event, no nudge).
		update_post_meta( $t['T3b'], '_barn_task_due_on', '2027-03-31' );
		$this->drain();
		$this->assertSame( '2027-03-07 14:00:00', $state( 'T3b' )[2] );
		// 11. A record.updated of T1's name leaves its resume time unchanged.
		$this->edit_task( $t['T1'], array( 'name' => 'T1 renamed' ) );
		$this->assertSame( '2027-03-17 13:00:00', $state( 'T1' )[2] );
		$this->assertSame( array(), $this->new_mail() );

		// 3 and 4. 03-07: nothing for T3; T3b wakes on its original time, checks the date again, waits for 03-28.
		$this->go( '2027-03-07 12:00' );
		$this->assertSame( array( 'waiting', 'before_due', '2027-03-28 13:00:00', null ), $state( 'T3b' ) );
		$this->assertSame( '2027-03-28 13:00:00', $state( 'T3' )[2] );
		$this->assertSame( array(), $this->new_mail() );

		// 6. 03-10: T5's due date cleared: parked (the horizon counts from the first arrival), no email.
		$this->clock->set( '2027-03-10 10:00' );
		$this->edit_task( $t['T5'], array( 'due_on' => '' ) );
		$this->assertSame( array( 'waiting', 'before_due', '2029-03-01 15:00:00', null ), $state( 'T5' ) );
		// 10. T9 trashed: the status change nudges its run, which stops (no_record).
		$this->assertTrue( Records::trash( $t['T9'] ) );
		$this->drain();
		$this->assertSame( array( 'completed', 'stopped_at:before_due' ), array( $state( 'T9' )[0], $state( 'T9' )[3] ) );
		$this->assertTrue( $this->wait_summaries( $t['T9'] )[1]['no_record'] );

		// 9. 03-15: T8 marked done (not a waited field, no nudge).
		$this->clock->set( '2027-03-15 10:00' );
		$this->edit_task( $t['T8'], array( 'done' => true ) );
		$this->assertSame( '2027-03-17 13:00:00', $state( 'T8' )[2] );
		$this->assertCount( 1, $this->wait_summaries( $t['T8'] ), 'Not woken.' );

		// 1. Nothing on 03-16 or at 08:59 on 03-17; at 09:00 one reminder for T1, stamped. 9. T8 stops at
		// still_open.
		$this->go( '2027-03-16 09:00' );
		$this->go( '2027-03-17 08:59' );
		$this->assertSame( array(), $this->new_mail() );
		$this->go( '2027-03-17 09:00' );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 't1@barn.example', 'Due March 20, 2027: T1 renamed' ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertSame( '2027-03-17', $this->value( $t['T1'], 'reminded_on' ) );
		$this->assertSame( array( 'completed', 'stamp', null, null ), $state( 'T1' ) );
		$this->assertSame( 'stopped_at:still_open', $state( 'T8' )[3] );
		$this->assertSame( 'waiting', $state( 'T5' )[0] );

		// 5. 03-20: T4 gets a due date of 04-10: it waits until 04-07 09:00.
		$this->clock->set( '2027-03-20 10:00' );
		$this->edit_task( $t['T4'], array( 'due_on' => '2027-04-10' ) );
		$this->assertSame( array( 'waiting', 'before_due', '2027-04-07 13:00:00', null ), $state( 'T4' ) );

		// 3 and 4. 03-28 09:00: T3 and T3b are reminded.
		$this->go( '2027-03-28 08:59' );
		$this->assertSame( array(), $this->new_mail() );
		$this->go( '2027-03-28 09:00' );
		$to = array_map( fn( $mail ) => $mail['to'][0][0], $this->new_mail() );
		sort( $to );
		$this->assertSame( array( 't3@barn.example', 't3b@barn.example' ), $to );

		// 5. 04-07 09:00: T4 is reminded.
		$this->go( '2027-04-07 08:59' );
		$this->assertSame( array(), $this->new_mail() );
		$this->go( '2027-04-07 09:00' );
		$this->assertSame( 'Due April 10, 2027: T4', $this->only_new_mail()['subject'] );

		// 6. 2029-03-01 10:00: T5, still without a date, stops at the wait (no_date), with no email.
		$this->go( '2029-03-01 09:59' );
		$this->assertSame( 'waiting', $state( 'T5' )[0] );
		$this->go( '2029-03-01 10:00' );
		$this->assertSame( array( 'completed', 'stopped_at:before_due' ), array( $state( 'T5' )[0], $state( 'T5' )[3] ) );
		$rows = $this->wait_summaries( $t['T5'] );
		$this->assertTrue( end( $rows )['no_date'] );

		// Totals: six reminders (T6, T2, T1, T3, T3b, T4), one run per task, every run completed.
		$this->assertSame( array(), $this->new_mail() );
		$this->assertCount( 6, tests_retrieve_phpmailer_instance()->mock_sent );
		$runs = $this->runs( 'barn_task_due_reminder' );
		$this->assertCount( 10, $runs );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( array_map( fn( $run ) => $this->run_row( $run['id'] ), $runs ), 'status' ) ) ) );
	}

	/**
	 * Retention, `barn_volunteer_retention` (v4 design section 7, retention steps 1 to 6): applications
	 * created 2027-05-03 and 2027-06-05; the schedule fires at 03:30 site time two years later.
	 */
	public function test_retention_timeline() {
		// 1. 55 applications created 2027-05-03 with stage new, 1 placed, 1 created 2027-06-05.
		$old = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$old[] = $this->volunteer( 'Applicant ' . $i, '2027-05-03 10:00:00' );
		}
		$placed = $this->volunteer( 'Placed applicant', '2027-05-03 10:00:00', 'placed' );
		$june   = $this->volunteer( 'June applicant', '2027-06-05 10:00:00' );
		$all    = array_merge( $old, array( $placed, $june ) );

		// 5. With trash turned off (EMPTY_TRASH_DAYS 0; the filter is the test seam for the constant) the first
		// item fails trash_disabled, the run fails, and every row remains.
		$this->clock->set( '2029-05-31 03:30' );
		add_filter( 'pegwork_records_trash_enabled', '__return_false' );
		$run = $this->retention();
		remove_filter( 'pegwork_records_trash_enabled', '__return_false' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'trash_disabled', (string) $run['error'] );
		$this->assertSame( array( 'old', 'any_old', 'each_old', 'remove#1:failed' ), $this->trace( $run ) );
		$this->assertSame( array( 'pending' ), array_values( array_unique( array_map( 'get_post_status', $all ) ) ) );
		$this->assertSame( array(), $this->new_mail() );

		// 2. 2029-06-01 03:30: the first 50 by ID go to the trash; the report says "50 of 55".
		$this->clock->set( '2029-06-01 03:30' );
		$run = $this->retention();
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$this->assertSame( 50, $run['context']['trashed'] );
		$this->assertSame( array( 'before' => '2027-06-01' ), $this->step_row( $run, 'old' )['summary']['created'] );
		$this->assertSame( array_fill( 0, 50, 'trash' ), array_map( 'get_post_status', array_slice( $old, 0, 50 ) ) );
		$this->assertSame( array_fill( 0, 7, 'pending' ), array_map( 'get_post_status', array_merge( array_slice( $old, 50 ), array( $placed, $june ) ) ) );
		$mail = $this->only_new_mail();
		$this->assertSame( array( self::SETTINGS['volunteer_manager'], '50 volunteer applications moved to the trash' ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( '50 of 55 applications older than two years (not placed) were moved to the trash today.', $mail['body'] );

		// 4. A second firing the same day creates nothing.
		$this->clock->set( '2029-06-01 15:00' );
		Engine::instance()->schedules()->fire( 'barn_volunteer_retention' );
		$this->drain();
		$this->assertCount( 2, $this->runs( 'barn_volunteer_retention' ), 'Idempotency: retention:{{today}}.' );
		$this->assertSame( array(), $this->new_mail() );

		// 2. 06-02: the other 5 go; the placed one stays.
		$this->clock->set( '2029-06-02 03:30' );
		$run = $this->retention();
		$this->assertSame( 5, $run['context']['trashed'] );
		$this->assertSame( array_fill( 0, 5, 'trash' ), array_map( 'get_post_status', array_slice( $old, 50 ) ) );
		$this->assertStringContainsString( '5 of 5 applications', $this->only_new_mail()['body'] );

		// 06-05: nothing is old enough yet, so the run stops at any_old with no report.
		$this->clock->set( '2029-06-05 03:30' );
		$this->assertSame( 'stopped_at:any_old', $this->retention()['context']['outcome'] );
		$this->assertSame( array(), $this->new_mail() );

		// 3. 2029-06-06: the 2027-06-05 application goes.
		$this->clock->set( '2029-06-06 03:30' );
		$run = $this->retention();
		$this->assertSame( 1, $run['context']['trashed'] );
		$this->assertSame( array( 'pending', 'trash' ), array( get_post_status( $placed ), get_post_status( $june ) ) );
		$this->assertSame( '1 volunteer applications moved to the trash', $this->only_new_mail()['subject'] );

		// 6. A restored application can be read again (and is not found by retention until trashed again).
		$this->assertInstanceOf( \WP_Post::class, wp_untrash_post( $old[0] ) );
		$this->assertNotSame( 'trash', get_post_status( $old[0] ) );
		$this->assertSame( 'Applicant 1', Records::get( $old[0] )['values']['name'] );
		$this->assertSame( 'new', $this->value( $old[0], 'stage' ) );
	}

	/**
	 * Shared receipt, `barn_gift_thanks` plus `barn_gift_receipt` (v4 design section 7, receipt steps 1, 2, 3,
	 * 5 and 6; step 4, the inbound path, is in test_inbound_and_manual()).
	 */
	public function test_receipt_timeline() {
		Overrides::set( 'barn_task_due_reminder', 'off' );
		$ada   = $this->contact( 'Ada Lovelace', 'ada@donor.example' );
		$grace = $this->contact( 'Grace Hopper', 'grace@donor.example' );

		// 1. A gift of 300 on 2027-03-01 sends email A and, from a child run at depth 1, the receipt.
		$this->clock->set( '2027-03-01 09:00' );
		$gift    = $this->gift( 300, $ada );
		$thanks  = $this->thanks_run( $gift );
		$receipt = $this->run_for( 'barn_gift_receipt', $gift );
		$this->assertSame( array( 'completed', 1, 'automation' ), array( $receipt['status'], (int) $receipt['depth'], $receipt['context']['trigger']['source'] ) );
		$this->assertSame( (int) $thanks['id'], $receipt['context']['parent']['run'] );
		$this->assertSame( array( 'donor', 'has_donor', 'send' ), $this->path( $receipt ) );
		$summary = $this->step_row( $thanks, 'receipt' )['summary'];
		$this->assertTrue( $summary['started'] );
		$this->assertSame( (int) $receipt['id'], $summary['run'] );
		$this->assertSame( 'start:' . $thanks['id'] . '-receipt', $receipt['idempotency_key'] );
		$mail = $this->new_mail()[1];
		$this->assertSame( array( 'ada@donor.example', $this->receipt_subject() ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $mail['header'] );
		$this->assertStringContainsString( 'Receipt for your gift of $300.00 received on March 1, 2027.', $mail['body'] );

		// 2. The parent still waits until 03-31.
		$this->assertSame( array( 'waiting', 'gifts_later', '2027-03-31 13:00:00' ), array( $thanks['status'], $thanks['current_step'], $thanks['resume_at'] ) );

		// 3. A gift of 0 is thanked; the receipt does not apply (`started: false`), so no receipt.
		$zero   = $this->gift( 0, $grace );
		$thanks = $this->thanks_run( $zero );
		$this->assertSame( self::OTHER_PATH, $this->path( $thanks ) );
		$summary = $this->step_row( $thanks, 'receipt' )['summary'];
		$this->assertSame( array( false, 'not_applicable' ), array( $summary['started'], $summary['skipped'] ) );
		$this->assertSame( array(), array_filter( $this->runs( 'barn_gift_receipt' ), fn( $run ) => $zero === $run['record_id'] ) );
		$this->assertSame( self::EMAIL_B, $this->only_new_mail()['subject'] );

		// 5. A thanks run forced to depth 2 logs a skipped receipt run at depth 3 (loop_depth), and sends no receipt.
		global $wpdb;
		$deep = Records::create(
			'barn_gift',
			array(
				'name'        => 'Gift of 90',
				'amount'      => 90,
				'received_on' => '2027-03-01',
				'gift_donor'  => $grace,
			),
			'private'
		);
		$this->assertIsInt( $deep );
		$thanks = $this->thanks_run( $deep );
		$wpdb->update( Schema::runs(), array( 'depth' => 2 ), array( 'id' => $thanks['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test setup.
		$this->drain();
		$skipped = $this->run_for( 'barn_gift_receipt', $deep );
		$this->assertSame( array( 'skipped', 3 ), array( $skipped['status'], (int) $skipped['depth'] ) );
		$this->assertSame( 'loop_depth: trigger depth 3 reached the limit of 3.', $skipped['error'] );
		$summary = $this->step_row( $this->run_row( $thanks['id'] ), 'receipt' )['summary'];
		$this->assertSame( array( false, 'loop_depth', (int) $skipped['id'] ), array( $summary['started'], $summary['skipped'], $summary['run'] ) );
		$this->assertSame( array( self::EMAIL_B ), array_column( $this->new_mail(), 'subject' ) );

		// 6. Staff "Send receipt" on a gift sends one receipt.
		$started = ( new Manual() )->start( 'barn_gift_receipt', $gift, $this->editor, 'editor' );
		$this->assertIsArray( $started );
		$this->drain();
		$this->assertSame( 'editor', $this->run_row( $started['run'] )['context']['trigger']['source'] );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'ada@donor.example', $this->receipt_subject() ), array( $mail['to'][0][0], $mail['subject'] ) );

		// 6. Bulk Run on 3 gifts (an editor, from Gifts) sends one receipt each.
		$bulk = array( $this->gift( 25, $grace ), $this->gift( 35, $grace ), $this->gift( 45, $grace ) );
		$this->new_mail();
		$this->assertArrayHasKey( BulkRun::ACTION . 'barn_gift_receipt', BulkRun::bulk_actions( array(), 'barn_gift' ) );
		$this->bulk_run( 'barn_gift_receipt', $bulk );
		$this->drain();
		set_current_screen( 'edit-barn_gift' );
		ob_start();
		BulkRun::notice();
		$this->assertStringContainsString( 'Started 3 runs.', (string) ob_get_clean() );
		$mails = $this->new_mail();
		$this->assertSame( array_fill( 0, 3, $this->receipt_subject() ), array_column( $mails, 'subject' ) );
		$this->assertStringContainsString( 'Receipt for your gift of $35.00', $mails[1]['body'] );
		foreach ( $bulk as $id ) {
			$runs = array_values( array_filter( $this->runs( 'barn_gift_receipt' ), fn( $run ) => $id === $run['record_id'] ) );
			$this->assertSame( array( 'automation', 'bulk' ), array_map( fn( $run ) => $run['context']['trigger']['source'], $runs ), 'One from the thanks run, one from bulk.' );
		}
	}

	/**
	 * Review alert, `barn_volunteer_review` (v4 design section 7): two editors, one administrator and one
	 * author. A sign-up sends one email To the volunteer manager, Bcc both editors; the log shows the role
	 * and its count, never a member's address.
	 */
	public function test_review_timeline() {
		$this->clock->set( '2027-05-03 10:00' );
		$editors = array(
			get_userdata( $this->editor )->user_email,
			get_userdata(
				self::factory()->user->create(
					array(
						'role'       => 'editor',
						'user_email' => 'second.editor@barn.example',
					)
				)
			)->user_email,
		);
		sort( $editors );
		self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'admin@barn.example',
			)
		);
		self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'author@barn.example',
			)
		);

		$id      = $this->sign_up( 'Rosa Parks', 'rosa@volunteer.example', 'garden', 2 );
		$mails   = $this->new_mail();
		$subject = 'Volunteer application to review: Rosa Parks';
		$this->assertSame( array( 'New volunteer: Rosa Parks', $subject ), array_column( $mails, 'subject' ), 'The intake notice and one review email.' );
		$review = $mails[1];
		$this->assertSame( array( array( self::SETTINGS['volunteer_manager'], '' ) ), $review['to'] );
		$bcc = array_map( fn( $pair ) => $pair[0], $review['bcc'] );
		sort( $bcc );
		$this->assertSame( $editors, $bcc, 'Both editors in Bcc; the administrator and the author get nothing.' );
		$this->assertStringContainsString( 'Rosa Parks applied to help with garden. Review it here: ' . admin_url( 'post.php?post=' . $id . '&action=edit' ), $review['body'] );

		$run = $this->run_for( 'barn_volunteer_review', $id );
		$this->assertSame( 'completed', $run['status'], (string) $run['error'] );
		$to = $this->step_row( $run, 'notify' )['summary']['to'];
		$this->assertContains( 'role:editor (2)', $to );
		$html = $this->run_detail( $run['id'] );
		$this->assertStringContainsString( 'role:editor (2)', $html );
		foreach ( $editors as $address ) {
			$this->assertStringNotContainsString( $address, (string) wp_json_encode( $to ) );
			$this->assertStringNotContainsString( $address, $html );
		}
	}

	/**
	 * Turns off the v4 automations a v3 timeline does not exercise.
	 */
	private function only_v3() {
		foreach ( self::V4_OTHERS as $key ) {
			Overrides::set( $key, 'off' );
		}
	}

	/**
	 * Subject of the shared receipt.
	 *
	 * @return string
	 */
	private function receipt_subject() {
		return 'Your receipt from ' . get_bloginfo( 'name' );
	}

	/**
	 * The two mails a thanked gift sends since the last call: the thank-you, then the receipt from the child run.
	 *
	 * @param string $email   Donor address.
	 * @param string $subject Thank-you subject.
	 * @return array<string, mixed> The thank-you mail.
	 */
	private function thanked_with_receipt( $email, $subject ) {
		$mails = $this->new_mail();
		$this->assertSame(
			array( array( $email, $subject ), array( $email, $this->receipt_subject() ) ),
			array_map( fn( $mail ) => array( $mail['to'][0][0], $mail['subject'] ), $mails )
		);
		return $mails[0];
	}

	/**
	 * Moves the clock to a site time and fires due wake-ups.
	 *
	 * @param string $at Site time.
	 */
	private function go( $at ) {
		$this->clock->set( $at );
		$this->drain();
	}

	/**
	 * Updates a task (emits record.updated) and runs what it wakes.
	 *
	 * @param int                  $id     Task ID.
	 * @param array<string, mixed> $values Values.
	 */
	private function edit_task( $id, array $values ) {
		$this->assertTrue( Records::update( $id, $values ) );
		$this->drain();
	}

	/**
	 * A task's reminder run as [ status, current step, resume time while waiting, outcome ].
	 *
	 * @param int $task Task ID.
	 * @return array{0: string, 1: string|null, 2: string|null, 3: string|null}
	 */
	private function reminder_state( $task ) {
		$run = $this->run_row( $this->run_for( 'barn_task_due_reminder', $task )['id'] );
		return array( $run['status'], $run['current_step'], 'waiting' === $run['status'] ? $run['resume_at'] : null, $run['context']['outcome'] ?? null );
	}

	/**
	 * Summaries of a task's `before_due` wait rows, oldest first.
	 *
	 * @param int $task Task ID.
	 * @return array<int, array<string, mixed>>
	 */
	private function wait_summaries( $task ) {
		$run = $this->run_for( 'barn_task_due_reminder', $task );
		return array_values( array_map( fn( $row ) => (array) $row['summary'], array_filter( $this->steps( $run['id'] ), fn( $row ) => 'before_due' === $row['step_id'] ) ) );
	}

	/**
	 * A volunteer application created at a site time (pending, as the form writes it).
	 *
	 * @param string $name    Name.
	 * @param string $created Site time, `Y-m-d H:i:s`.
	 * @param string $stage   Stage.
	 * @return int Record ID.
	 */
	private function volunteer( $name, $created, $stage = 'new' ) {
		global $wpdb;
		$id = Records::create(
			'barn_volunteer',
			array(
				'name'     => $name,
				'email'    => 'applicant@volunteer.example',
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
	 * Fires the retention schedule at the clock's time and runs it.
	 *
	 * @return array<string, mixed> The newest retention run.
	 */
	private function retention() {
		Engine::instance()->schedules()->fire( 'barn_volunteer_retention' );
		$this->drain();
		$runs = $this->runs( 'barn_volunteer_retention' );
		return $this->run_row( end( $runs )['id'] );
	}

	/**
	 * Submits the bulk Run confirmation screen as the current user, as the browser would.
	 *
	 * @param string $key Automation key.
	 * @param int[]  $ids Record IDs.
	 */
	private function bulk_run( $key, array $ids ) {
		$fields   = array(
			'action'     => BulkRun::POST,
			'automation' => $key,
			'records'    => implode( ',', $ids ),
			'request'    => 'barn-bulk-request-1',
			'back'       => admin_url( 'edit.php?post_type=barn_gift' ),
			'_wpnonce'   => wp_create_nonce( BulkRun::NONCE . $key ),
		);
		$_POST    = $fields;
		$_REQUEST = $fields;
		$redirect = static function ( $location ) {
			throw new RuntimeException( 'redirect:' . $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test signal, never printed.
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			BulkRun::submit();
			$this->fail( 'The handler did not redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect:' . admin_url( 'edit.php?post_type=barn_gift' ), $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
		}
	}

	/**
	 * Creates a contact.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return int Contact ID.
	 */
	private function contact( $name, $email ) {
		$id = Records::create(
			'barn_contact',
			array(
				'name'  => $name,
				'email' => $email,
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->assertSame( 0, $this->drain() );
		return $id;
	}

	/**
	 * Records a gift received today (site time), linked to its donor in the same write, as staff would, and
	 * runs what it starts.
	 *
	 * @param int|float $amount  Amount.
	 * @param int|null  $contact Donor contact ID, or null for a gift without one.
	 * @param string    $status  Post status.
	 * @return int Gift ID.
	 */
	private function gift( $amount, $contact, $status = 'private' ) {
		$values = array(
			'name'        => 'Gift of ' . $amount,
			'amount'      => $amount,
			'received_on' => wp_date( 'Y-m-d', $this->clock->now()->getTimestamp() ),
		);
		if ( null !== $contact ) {
			$values['gift_donor'] = $contact;
		}
		$id = Records::create( 'barn_gift', $values, $status );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->drain();
		return $id;
	}

	/**
	 * Creates a published task.
	 *
	 * @param string      $name  Name.
	 * @param string      $due   Due date.
	 * @param string|null $email Assignee email, or null for none.
	 * @param bool        $done  Done.
	 * @return int Task ID.
	 */
	private function task( $name, $due, $email, $done = false ) {
		$values = array(
			'name'   => $name,
			'due_on' => $due,
			'done'   => $done,
		);
		if ( null !== $email ) {
			$values['assignee_email'] = $email;
		}
		$id = Records::create( 'barn_task', $values, 'publish' );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Fires the weekly digest at the clock's time and runs it.
	 *
	 * @return array<string, mixed> The newest digest run.
	 */
	private function digest() {
		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$this->drain();
		$runs = $this->runs( 'barn_tasks_digest' );
		return end( $runs );
	}

	/**
	 * Posts a signed payload to the donation platform webhook, as the platform would.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return \WP_REST_Response
	 */
	private function inbound( array $payload ) {
		$body      = (string) wp_json_encode( $payload );
		$timestamp = (string) $this->clock->now()->getTimestamp();
		$request   = new WP_REST_Request( 'POST', '/pegwork/v1/automations/barn_donation_inbound/webhook' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Pegwork-Timestamp', $timestamp );
		$request->set_header( 'X-Pegwork-Signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, (string) Secrets::get( 'barn_donation_inbound' ) ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * IDs of the contacts with an email.
	 *
	 * @param string $email Email.
	 * @return int[]
	 */
	private function contacts_by_email( $email ) {
		return Records::find(
			'barn_contact',
			array(
				'filter' => array(
					'all' => array(
						array(
							'field' => 'email',
							'op'    => 'eq',
							'value' => $email,
						),
					),
				),
			)
		)['ids'];
	}

	/**
	 * Submits the volunteer form as a visitor, as the browser would, and runs what it starts.
	 *
	 * @param string $name     Name.
	 * @param string $email    Email.
	 * @param string $interest Interest.
	 * @param int    $runs     Runs the submission starts (intake, plus the review when it is on).
	 * @return int Volunteer record ID.
	 */
	private function sign_up( $name, $email, $interest, $runs = 1 ) {
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$now     = $this->clock->now()->getTimestamp();
		$outcome = Submission::process(
			pegwork_registry()->form( 'barn_volunteer_signup' ),
			array(
				'name'     => $name,
				'email'    => $email,
				'interest' => $interest,
			),
			array(
				'key'   => 'barn_volunteer_signup',
				'token' => Token::create( 'barn_volunteer_signup', $now - 30 ),
				'url'   => '',
			),
			$now
		);
		wp_set_current_user( $user );
		$this->assertSame( 'sent', $outcome['outcome'], (string) wp_json_encode( $outcome ) );
		$this->assertSame( 'barn_volunteer', get_post_type( $outcome['record_id'] ) );
		$this->assertSame( $runs, $this->drain() );
		return $outcome['record_id'];
	}

	/**
	 * The thank-you run of a gift.
	 *
	 * @param int $gift Gift ID.
	 * @return array<string, mixed>
	 */
	private function thanks_run( $gift ) {
		return $this->run_for( 'barn_gift_thanks', $gift );
	}

	/**
	 * The milestone run of a gift.
	 *
	 * @param int $gift Gift ID.
	 * @return array<string, mixed>
	 */
	private function milestone_run( $gift ) {
		return $this->run_for( 'barn_lifetime_milestone', $gift );
	}

	/**
	 * Milestone emails sent so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function milestone_mail() {
		return array_values( array_filter( tests_retrieve_phpmailer_instance()->mock_sent, fn( $mail ) => str_contains( $mail['subject'], ' gifts to ' ) ) );
	}

	/**
	 * The only run of an automation for a record.
	 *
	 * @param string $automation Automation key.
	 * @param int    $record     Record ID.
	 * @return array<string, mixed>
	 */
	private function run_for( $automation, $record ) {
		$runs = array_values( array_filter( $this->runs( $automation ), fn( $run ) => $record === $run['record_id'] ) );
		$this->assertCount( 1, $runs, "One $automation run for record $record." );
		return $runs[0];
	}

	/**
	 * Step ids of a run's attempts, oldest first.
	 *
	 * @param array<string, mixed> $run Run row.
	 * @return string[]
	 */
	private function path( array $run ) {
		return array_column( $this->steps( $run['id'] ), 'step_id' );
	}

	/**
	 * Step rows of a run as `step_id#iteration:status` (no iteration or status when none or ok), oldest first.
	 *
	 * @param array<string, mixed> $run Run row.
	 * @return string[]
	 */
	private function trace( array $run ) {
		return array_map(
			fn( $row ) => $row['step_id'] . ( null === $row['iteration'] ? '' : '#' . $row['iteration'] ) . ( 'ok' === $row['status'] ? '' : ':' . $row['status'] ),
			$this->steps( $run['id'] )
		);
	}

	/**
	 * The last logged row of a step in a run.
	 *
	 * @param array<string, mixed> $run  Run row.
	 * @param string               $step Step id.
	 * @return array<string, mixed>
	 */
	private function step_row( array $run, $step ) {
		$rows = array_values( array_filter( $this->steps( $run['id'] ), fn( $row ) => $step === $row['step_id'] ) );
		$this->assertNotEmpty( $rows, "Step $step was logged." );
		return end( $rows );
	}

	/**
	 * Tasks linked to a gift (or all of them), as Records reads them.
	 *
	 * @param int|null $gift Gift ID, or null for every task.
	 * @return array<int, array<string, mixed>>
	 */
	private function tasks( $gift = null ) {
		$query = array( 'limit' => 50 );
		if ( null !== $gift ) {
			$query['related'] = array(
				'relationship' => 'task_gift',
				'of'           => $gift,
			);
		}
		return array_map( array( Records::class, 'get' ), Records::find( 'barn_task', $query )['ids'] );
	}

	/**
	 * Mails sent since the last call.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function new_mail() {
		$sent       = tests_retrieve_phpmailer_instance()->mock_sent;
		$new        = array_slice( $sent, $this->seen );
		$this->seen = count( $sent );
		return $new;
	}

	/**
	 * The one mail sent since the last call.
	 *
	 * @return array<string, mixed>
	 */
	private function only_new_mail() {
		$new = $this->new_mail();
		$this->assertCount( 1, $new, 'Exactly one new mail: ' . implode( ', ', array_column( $new, 'subject' ) ) );
		return $new[0];
	}

	/**
	 * The admin run detail of a run.
	 *
	 * @param int $run_id Run ID.
	 * @return string HTML.
	 */
	private function run_detail( $run_id ) {
		$user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET = array(
			'view' => 'run',
			'run'  => (string) $run_id,
		);
		ob_start();
		try {
			Screen::render();
		} finally {
			$html = ob_get_clean();
			wp_set_current_user( $user );
		}
		return $html;
	}
}
