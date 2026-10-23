<?php
/**
 * Phase 6 gate: the BarnCRM donor acknowledgment and volunteer intake flows on simulated time.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Admin\Screen;
use PegworkAutomation\Cli\AutomationCommand;
use PegworkForms\Schema as FormsSchema;
use PegworkForms\Submission;
use PegworkForms\Token;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';
require_once dirname( __DIR__, 3 ) . '/modules/forms/module.php'; // Registers the forms module autoloader.

/**
 * Runs `tests/fixtures/valid/barn` (design `docs/phase-6-design.md` section 11, "Simulated time
 * BarnFlowsGateTest") with a frozen clock in site time, the fake scheduler and the mock mailer. The site is in
 * New York, so the donor flow's 30-day wait crosses the start of daylight saving time (2027-03-14) and must
 * still end at 09:00 site time. Volunteers sign up through the real form pipeline as visitors.
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
	 * Subject of the donor follow-up.
	 */
	private const FOLLOW_UP = 'What your gift made possible';

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
		// Stored as the settings screen would store them, minus its URL check, which resolves the host
		// (wp_http_validate_url()); barn.example does not resolve, and a test needs no network.
		remove_all_filters( 'sanitize_option_barn_settings' );
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
	 * Donor acknowledgment (design section 11, donor steps 1 to 6).
	 */
	public function test_donor_timeline() {
		$ada   = $this->contact( 'Ada Lovelace', 'ada@donor.example' );
		$grace = $this->contact( 'Grace Hopper', 'grace@donor.example' );

		// 1. 2027-03-01 09:00, first gifts.
		$this->clock->set( '2027-03-01 09:00' );
		$g1 = $this->gift( 300, $ada );
		$this->assertSame( array( 'size', 'thank_major', 'call_task', 'has_donor', 'stamp_donor' ), $this->path( $this->thanks_run( $g1 ) ) );
		$this->assertSame(
			array(
				'branch' => 'Major gift (250 or more)',
				'index'  => 0,
			),
			$this->steps( $this->thanks_run( $g1 )['id'] )[0]['summary']
		);
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'ada@donor.example', self::EMAIL_A ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $mail['header'] );
		$this->assertStringContainsString( 'Dear Ada Lovelace,', $mail['body'] );
		$this->assertStringContainsString( 'Thank you for your gift of $300 on March 1, 2027. Our director will call you', $mail['body'] );
		$this->assertStringContainsString( get_bloginfo( 'name' ), $mail['body'] );
		$task = $this->tasks( $g1 );
		$this->assertCount( 1, $task, 'The major gift creates one call task.' );
		$this->assertSame(
			array(
				'name'           => 'Thank-you call: Ada Lovelace',
				'due_on'         => '2027-03-01',
				'assignee_email' => self::SETTINGS['development_email'],
				'related_gift'   => $g1,
				'done'           => false,
			),
			$task[0]['values']
		);
		$this->assertSame( 'publish', $task[0]['status'] );

		$g2 = $this->gift( 40, $grace );
		$this->assertSame( array( 'size', 'thank', 'has_donor', 'stamp_donor' ), $this->path( $this->thanks_run( $g2 ) ) );
		$this->assertSame(
			array(
				'branch' => 'otherwise',
				'index'  => -1,
			),
			$this->steps( $this->thanks_run( $g2 )['id'] )[0]['summary']
		);
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'grace@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringNotContainsString( 'Reply-To:', $mail['header'] );
		$this->assertStringContainsString( 'Thank you for your gift of $40 on March 1, 2027.', $mail['body'] );
		$this->assertSame( array(), $this->tasks( $g2 ), 'A gift under 250 creates no task.' );

		// The boundary: exactly 250 takes the major branch. Ada's second gift on the same day keeps her dates.
		$boundary = $this->gift( 250, $ada );
		$this->assertSame( array( 'size', 'thank_major', 'call_task', 'has_donor', 'stamp_donor' ), $this->path( $this->thanks_run( $boundary ) ) );
		$this->assertSame( self::EMAIL_A, $this->only_new_mail()['subject'] );
		$this->assertCount( 1, $this->tasks( $boundary ) );

		foreach ( array( $ada, $grace ) as $contact ) {
			$this->assertSame( '2027-03-01', $this->value( $contact, 'first_gift_on' ) );
			$this->assertSame( '2027-03-01', $this->value( $contact, 'last_gift_on' ) );
		}
		$followups = $this->runs( 'barn_gift_followup' );
		$this->assertSame( array( $ada, $grace ), array_column( $followups, 'record_id' ), 'One follow-up run per contact: the same-day gift does not change the date.' );
		foreach ( $followups as $run ) {
			$this->assertSame( 'waiting', $run['status'] );
			$this->assertSame( 'no_second_gift', $run['current_step'] );
			$this->assertSame( '2027-03-31 13:00:00', $run['resume_at'], '30 days later at 09:00 New York time, after the DST change (UTC-4).' );
			$this->assertSame( 1, (int) $run['depth'], 'Started by stamp_donor.' );
			$this->assertSame( array( 'first_gift', 'stamp_first', 'only_gift', 'grace' ), $this->path( $run ) );
			$this->assertSame( "{$run['record_id']}:2027-03-01", $run['idempotency_key'] );
		}
		$first_ada = $followups[0];

		// 2. 2027-03-10: a second gift from Ada is thanked; her new follow-up run stops at once.
		$this->clock->set( '2027-03-10 09:00' );
		$g3 = $this->gift( 75, $ada );
		$this->assertSame( array( 'size', 'thank', 'has_donor', 'stamp_donor' ), $this->path( $this->thanks_run( $g3 ) ) );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'ada@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Thank you for your gift of $75 on March 10, 2027.', $mail['body'] );
		$this->assertSame( '2027-03-10', $this->value( $ada, 'last_gift_on' ) );
		$this->assertSame( '2027-03-01', $this->value( $ada, 'first_gift_on' ) );
		$second = $this->runs( 'barn_gift_followup' )[2];
		$this->assertSame( $ada, $second['record_id'] );
		$this->assertSame( 'completed', $second['status'] );
		$this->assertSame( 'stopped_at:only_gift', $second['context']['outcome'] );
		$this->assertSame( array( 'first_gift', 'only_gift' ), $this->path( $second ), 'A repeat donor holds no wait.' );
		$this->assertSame( 'waiting', $this->run_row( $first_ada['id'] )['status'] );

		// 3. 2027-03-30: nothing is due, nothing is sent.
		$this->clock->set( '2027-03-30 09:00' );
		$this->assertSame( 0, $this->drain() );
		$this->assertSame( array(), $this->new_mail() );

		// 4. 2027-03-31: Ada's first run sees the second gift; Grace gets the one follow-up.
		$this->clock->set( '2027-03-31 08:59' );
		$this->assertSame( 0, $this->drain(), 'Not before 09:00 site time.' );
		$this->clock->set( '2027-03-31 09:00' );
		$this->assertSame( 2, $this->drain() );
		$ada_run = $this->run_row( $first_ada['id'] );
		$this->assertSame( 'completed', $ada_run['status'] );
		$this->assertSame( 'stopped_at:no_second_gift', $ada_run['context']['outcome'] );
		$grace_run = $this->run_row( $followups[1]['id'] );
		$this->assertSame( 'completed', $grace_run['status'] );
		$this->assertArrayNotHasKey( 'outcome', $grace_run['context'] );
		$this->assertSame( array( 'first_gift', 'stamp_first', 'only_gift', 'grace', 'no_second_gift', 'follow_up' ), $this->path( $grace_run ) );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'grace@donor.example', self::FOLLOW_UP ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertStringContainsString( 'Reply-To: ' . self::SETTINGS['development_email'], $mail['header'] );
		$this->assertStringContainsString( 'Dear Grace Hopper,', $mail['body'] );
		$this->assertStringContainsString( 'If you would like to give again: ' . self::SETTINGS['donate_url'], $mail['body'] );

		// 5. A gift without donor_id is thanked and stops before stamping anyone.
		$anonymous = $this->gift( 20, null );
		$run       = $this->thanks_run( $anonymous );
		$this->assertSame( 'completed', $run['status'] );
		$this->assertSame( 'stopped_at:has_donor', $run['context']['outcome'] );
		$this->assertSame( array( 'size', 'thank', 'has_donor' ), $this->path( $run ) );
		$mail = $this->only_new_mail();
		$this->assertSame( array( 'walkin@donor.example', self::EMAIL_B ), array( $mail['to'][0][0], $mail['subject'] ) );
		$this->assertCount( 3, $this->runs( 'barn_gift_followup' ), 'No contact was stamped.' );

		// 6. Totals: one follow-up email, two call tasks, no failed runs, nothing left waiting.
		$subjects = array_column( tests_retrieve_phpmailer_instance()->mock_sent, 'subject' );
		$this->assertSame( array( self::EMAIL_A, self::EMAIL_B, self::EMAIL_A, self::EMAIL_B, self::FOLLOW_UP, self::EMAIL_B ), $subjects );
		$this->assertCount( 2, $this->tasks() );
		$all = array_merge( $this->runs( 'barn_gift_thanks' ), $this->runs( 'barn_gift_followup' ) );
		$this->assertCount( 8, $all );
		$this->assertSame( array( 'completed' ), array_values( array_unique( array_column( array_map( fn( $run ) => $this->run_row( $run['id'] ), $all ), 'status' ) ) ) );
	}

	/**
	 * Volunteer intake (design section 11, volunteer steps 1 to 6).
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
	 * Records a gift received today (site time), as staff would, and runs what it starts.
	 *
	 * @param int|float $amount  Amount.
	 * @param int|null  $contact Donor contact ID, or null for a gift without one.
	 * @return int Gift ID.
	 */
	private function gift( $amount, $contact ) {
		$donor = null === $contact ? null : Records::get( $contact );
		$id    = Records::create(
			'barn_gift',
			array(
				'name'        => 'Gift of ' . $amount,
				'amount'      => $amount,
				'received_on' => wp_date( 'Y-m-d', $this->clock->now()->getTimestamp() ),
				'donor_id'    => null === $donor ? '' : $contact,
				'donor_name'  => null === $donor ? 'Walk-in donor' : $donor['values']['name'],
				'donor_email' => null === $donor ? 'walkin@donor.example' : $donor['values']['email'],
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		$this->drain();
		return $id;
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
	 * Call tasks of a gift (or all of them), as Records reads them.
	 *
	 * @param int|null $gift Gift ID, or null for every task.
	 * @return array<int, array<string, mixed>>
	 */
	private function tasks( $gift = null ) {
		$ids = get_posts(
			array(
				'post_type'   => 'barn_task',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);
		return array_values( array_filter( array_map( array( Records::class, 'get' ), $ids ), fn( $task ) => null === $gift || $gift === $task['values']['related_gift'] ) );
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
