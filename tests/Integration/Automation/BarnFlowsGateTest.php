<?php
/**
 * Phase 6 and Automation v3 gate: the BarnCRM flows on simulated time.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;
use PegworkAutomation\Webhooks\Secrets;
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
	private const MAJOR_PATH = array( 'donor', 'has_donor', 'size', 'thank_major', 'call_task', 'stamp_donor', 'gifts', 'first_gift', 'grace' );

	/**
	 * Steps of a donor run up to the wait, otherwise branch.
	 */
	private const OTHER_PATH = array( 'donor', 'has_donor', 'size', 'thank', 'stamp_donor', 'gifts', 'first_gift', 'grace' );

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
		parent::tear_down();
	}

	/**
	 * Donor acknowledgment and first-gift follow-up, one automation (design section 8, donor steps 1 to 5).
	 */
	public function test_donor_timeline() {
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
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'ada@donor.example', self::EMAIL_A ), array( $mail['to'][0][0], $mail['subject'] ) );
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
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'grace@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringNotContainsString( 'Reply-To:', $mail['header'] );
		$this->assertStringContainsString( 'Thank you for your gift of $40 on March 1, 2027.', $mail['body'] );
		$this->assertSame( array(), $this->tasks( $g2 ), 'A gift under 250 creates no task.' );
		$this->assertSame( 'waiting', $run['status'] );

		// 2. Same day: Carl gives twice. Exactly 250 takes the major branch; the first run waits, the second
		// counts two gifts and stops at first_gift (the Phase 6 same-day limit is gone).
		$c1  = $this->gift( 250, $carl );
		$run = $this->thanks_run( $c1 );
		$this->assertSame( self::MAJOR_PATH, $this->path( $run ) );
		$this->assertSame( self::EMAIL_A, $this->only_new_mail()['subject'] );
		$this->assertCount( 1, $this->tasks( $c1 ) );
		$this->assertSame( 'waiting', $run['status'] );
		$carl_first = $run;
		$c2         = $this->gift( 20, $carl );
		$run        = $this->thanks_run( $c2 );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:first_gift', $run['context']['outcome'] );
		$this->assertSame( array_slice( self::OTHER_PATH, 0, 7 ), $this->path( $run ) );
		$this->assertSame( 2, $this->step_row( $run, 'gifts' )['summary']['count'] );
		$this->assertSame( self::EMAIL_B, $this->only_new_mail()['subject'] );

		// 3. 2027-03-10: G3 (Ada, 75) is thanked; its run stops at first_gift.
		$this->clock->set( '2027-03-10 09:00' );
		$g3  = $this->gift( 75, $ada );
		$run = $this->thanks_run( $g3 );
		$this->assertSame( 'stopped_at:first_gift', $run['context']['outcome'] );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'ada@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
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
		$this->assertSame( array( 'donor', 'has_donor', 'size', '  thank', 'stamp_donor', 'gifts', 'first_gift', 'grace', 'gifts_later', 'no_second_gift', 'follow_up' ), array_column( $rows, 'step' ) );
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

		// Totals: five thank-yous and one follow-up, two call tasks, no milestone, nothing failed or waiting.
		$subjects = array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
		$this->assertSame( array( self::EMAIL_A, self::EMAIL_B, self::EMAIL_A, self::EMAIL_B, self::EMAIL_B, self::FOLLOW_UP ), $subjects );
		$this->assertCount( 2, $this->tasks() );
		$all = array_merge( $this->runs( 'barn_gift_thanks' ), $this->runs( 'barn_lifetime_milestone' ) );
		$this->assertCount( 12, $all, 'Each of the six gifts starts both gift automations.' );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( array_map( fn( $run ) => $this->run_row( $run['id'] ), $all ), 'status' ) ) ) );
	}

	/**
	 * Lifetime giving reaches 1,000 (design section 8, milestone steps 1 to 3).
	 */
	public function test_milestone_timeline() {
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
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'nia@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
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
		$this->assertSame( self::EMAIL_B, $this->only_new_mail()['subject'] );

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
	 * @return int Volunteer record ID.
	 */
	private function sign_up( $name, $email, $interest ) {
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
		$this->assertSame( 1, $this->drain() );
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
