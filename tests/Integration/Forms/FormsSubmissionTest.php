<?php
/**
 * Integration tests: the forms submission pipeline.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Entries;
use PegworkForms\Notifications;
use PegworkForms\Submission;

require_once dirname( __DIR__, 2 ) . '/Support/FormsTestCase.php';

/**
 * Validate, spam-check, store, notify, emit; PRG on template_redirect.
 */
class FormsSubmissionTest extends FormsTestCase {

	/**
	 * A valid submission stores one received entry, sends every notification and emits form.submitted.
	 */
	public function test_valid_submission_stores_notifies_and_emits() {
		$result = $this->submit( $this->contact_values( array( 'post_type' => 'page' ) ) );

		$this->assertSame( 'sent', $result['outcome'] );
		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'received', $entry['status'] );
		$this->assertSame( 'fr_contact', $entry['form_key'] );
		$this->assertSame( 'ada@example.org', $entry['email'] );
		$this->assertNull( $entry['post_id'] );
		$this->assertNull( $entry['user_id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW ), $entry['created_at'] );
		$this->assertSame( 64, strlen( $entry['fingerprint'] ) );
		$this->assertSame(
			array(
				'name'      => 'Ada Lovelace',
				'email'     => 'Ada@Example.org',
				'topic'     => 'talks',
				'website'   => '',
				'subscribe' => false,
				'message'   => 'Hello there, I would like to give a talk.',
				'consent'   => true,
			),
			$entry['values'],
			'Only defined, visible items are stored; posted post_type is ignored.'
		);
		$this->assertSame(
			array(
				'admin'     => 'sent',
				'moderator' => 'sent',
				'submitter' => 'sent',
			),
			$entry['meta']['notifications']
		);
		$this->assertArrayHasKey( 'definition_hash', $entry['meta'] );

		$mail = array_map(
			function ( $sent ) {
				$sent['body'] = str_replace( "\r\n", "\n", $sent['body'] );
				return $sent;
			},
			$this->sent_mail()
		);
		$this->assertCount( 3, $mail );
		$this->assertSame( 'admin@example.org', $mail[0]['to'][0][0] );
		$this->assertSame( 'Contact: Ada Lovelace', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Reply-To: Ada@Example.org', $mail[0]['header'] );
		$this->assertStringContainsString( "Name: Ada Lovelace\n\nEmail: Ada@Example.org\n\nTopic: Talks", $mail[0]['body'] );
		$this->assertStringContainsString( 'Send me the newsletter: No', $mail[0]['body'] );
		$this->assertStringContainsString( 'Entry ' . $result['entry_id'] . ' on Test Blog: ', $mail[0]['body'] );
		$this->assertStringContainsString( 'page=pegwork-entries&entry=' . $result['entry_id'], $mail[0]['body'] );
		$this->assertStringNotContainsString( '{unknown_tag}', $mail[0]['body'] );
		$this->assertSame( 'admin@example.org', $mail[1]['to'][0][0], 'An unset options page address falls back to the admin email.' );
		$this->assertSame( 'Moderate Contact us', $mail[1]['subject'] );
		$this->assertSame( 'Ada@Example.org', $mail[2]['to'][0][0] );
		$this->assertStringContainsString( 'about Talks.', $mail[2]['body'] );

		$this->assertCount( 1, $this->events );
		$this->assertSame( 'form.submitted', $this->events[0][0] );
		$this->assertSame(
			array(
				'form'      => 'fr_contact',
				'entry_id'  => $result['entry_id'],
				'record_id' => null,
				'values'    => $entry['values'],
			),
			$this->events[0][1]
		);
	}

	/**
	 * Invalid values come back as messages in form order; nothing is stored or sent.
	 */
	public function test_validation_errors_store_nothing() {
		$result = $this->submit(
			$this->contact_values(
				array(
					'name'    => '',
					'email'   => 'not-an-email',
					'website' => 'javascript:alert(1)',
					'message' => str_repeat( 'a', 201 ),
					'consent' => '',
				)
			)
		);

		$this->assertSame( 'invalid', $result['outcome'] );
		$this->assertSame( array( 'name', 'email', 'website', 'message', 'consent' ), array_keys( $result['errors'] ) );
		$this->assertSame( 'Name is required.', $result['errors']['name'] );
		$this->assertStringContainsString( 'name@example.com', $result['errors']['email'] );
		$this->assertStringContainsString( 'https://', $result['errors']['website'] );
		$this->assertSame( 'Message must be at most 200 characters.', $result['errors']['message'] );
		$this->assertSame( 'Check the box to confirm: I agree to be contacted', $result['errors']['consent'] );
		$this->assertSame( 'not-an-email', $result['values']['email'], 'Posted text is kept for the re-render.' );
		$this->assertSame( 0, $this->count_entries() );
		$this->assertCount( 0, $this->sent_mail() );
		$this->assertCount( 0, $this->events );
	}

	/**
	 * Conditional items: hidden values are ignored, required_when applies when shown.
	 */
	public function test_show_when_and_required_when() {
		$hidden = $this->submit( $this->contact_values( array( 'frequency' => 'weekly' ) ) );
		$this->assertSame( 'sent', $hidden['outcome'] );
		$this->assertArrayNotHasKey( 'frequency', Entries::get( $hidden['entry_id'] )['values'] );

		$missing = $this->submit( $this->contact_values( array( 'subscribe' => '1' ) ) );
		$this->assertSame( 'invalid', $missing['outcome'] );
		$this->assertSame( array( 'frequency' ), array_keys( $missing['errors'] ) );

		$shown = $this->submit(
			$this->contact_values(
				array(
					'subscribe' => '1',
					'frequency' => 'monthly',
					'name'      => 'Grace',
				)
			)
		);
		$this->assertSame( 'sent', $shown['outcome'] );
		$this->assertSame( 'monthly', Entries::get( $shown['entry_id'] )['values']['frequency'] );
	}

	/**
	 * Honeypot and time trap store spam with reasons, send nothing and emit nothing.
	 */
	public function test_honeypot_and_time_trap_store_spam_quietly() {
		$honeypot = $this->submit( $this->contact_values(), 'fr_contact', array( 'url' => 'https://spam.example' ) );
		$this->assertSame( 'spam', $honeypot['outcome'] );
		$this->assertSame( array( 'honeypot' ), Entries::get( $honeypot['entry_id'] )['meta']['spam'] );

		$fast = $this->submit( $this->contact_values( array( 'name' => 'Fast' ) ), 'fr_contact', array(), 1 );
		$this->assertSame( 'spam', $fast['outcome'] );
		$this->assertSame( array( 'too_fast' ), Entries::get( $fast['entry_id'] )['meta']['spam'] );

		$missing = Submission::process( pegwork_registry()->form( 'fr_contact' ), $this->contact_values( array( 'name' => 'No pot' ) ), array_diff_key( $this->control(), array( 'url' => 1 ) ), self::NOW );
		$this->assertSame( 'spam', $missing['outcome'], 'A post without the honeypot field did not come from the form.' );

		$this->assertSame( 3, $this->count_entries( 'spam' ) );
		$this->assertCount( 0, $this->sent_mail() );
		$this->assertCount( 0, $this->events );
	}

	/**
	 * The pegwork_forms_spam_check filter can flag a submission.
	 */
	public function test_spam_check_filter() {
		add_filter(
			'pegwork_forms_spam_check',
			function ( $reasons, $form, $values ) {
				return str_contains( $values['message'], 'casino' ) ? array_merge( $reasons, array( 'keyword' ) ) : $reasons;
			},
			10,
			3
		);

		$result = $this->submit( $this->contact_values( array( 'message' => 'Visit my casino' ) ) );
		$this->assertSame( 'spam', $result['outcome'] );
		$this->assertSame( array( 'keyword' ), Entries::get( $result['entry_id'] )['meta']['spam'] );
		$this->assertCount( 0, $this->sent_mail() );
	}

	/**
	 * Missing, forged, other-form and week-old tokens re-render with a message and store nothing.
	 */
	public function test_bad_or_old_tokens_store_nothing() {
		foreach ( array( '', 'garbage', '1799999990.' . str_repeat( 'a', 64 ) ) as $token ) {
			$this->assertSame( 'expired', $this->submit( $this->contact_values(), 'fr_contact', array( 'token' => $token ) )['outcome'] );
		}
		$other = $this->control( 'fr_members' )['token'];
		$this->assertSame( 'expired', $this->submit( $this->contact_values(), 'fr_contact', array( 'token' => $other ) )['outcome'] );
		$this->assertSame( 'expired', $this->submit( $this->contact_values(), 'fr_contact', array(), 8 * DAY_IN_SECONDS )['outcome'] );
		$this->assertSame( 0, $this->count_entries() );
	}

	/**
	 * Logged-in users need the form nonce; anonymous visitors do not get one.
	 */
	public function test_nonce_for_logged_in_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 'expired', $this->submit( $this->contact_values(), 'fr_contact', array( 'nonce' => 'bad' ) )['outcome'] );

		$result = $this->submit( $this->contact_values() );
		$this->assertSame( 'sent', $result['outcome'] );
		$this->assertSame( get_current_user_id(), Entries::get( $result['entry_id'] )['user_id'] );
	}

	/**
	 * `access` with a capability: denied without it, accepted with it.
	 */
	public function test_access_capability() {
		$values = array( 'nickname' => 'Ada' );
		$this->assertSame( 'denied', $this->submit( $values, 'fr_members' )['outcome'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'denied', $this->submit( $values, 'fr_members' )['outcome'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$adult = array(
			'nickname' => 'Ada',
			'age'      => '30',
			'start'    => '2026-11-02',
		);
		$this->assertSame( 'sent', $this->submit( $adult, 'fr_members' )['outcome'] );
		$this->assertSame( 0, $this->count_entries( 'spam' ) );

		$young = array(
			'nickname' => 'Kid',
			'age'      => '12',
			'start'    => '2026-02-30',
		);
		$young = $this->submit( $young, 'fr_members' );
		$this->assertSame( array( 'age', 'start' ), array_keys( $young['errors'] ) );
	}

	/**
	 * Five stored entries per IP per form in 10 minutes; three per email address per form in an hour.
	 */
	public function test_rate_limits() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->assertSame( 'sent', $this->submit( $this->contact_values( array( 'message' => 'Same sender ' . $i ) ) )['outcome'] );
		}
		$this->assertSame( 'rate_limited', $this->submit( $this->contact_values( array( 'message' => 'Same sender 4' ) ) )['outcome'], 'Email limit.' );

		for ( $i = 4; $i <= 5; $i++ ) {
			$this->assertSame( 'sent', $this->submit( $this->contact_values( array( 'email' => "p$i@example.org" ) ) )['outcome'] );
		}
		$this->assertSame( 'rate_limited', $this->submit( $this->contact_values( array( 'email' => 'p6@example.org' ) ) )['outcome'], 'IP limit.' );
		$this->assertSame( 5, $this->count_entries() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertSame( 'sent', $this->submit( $this->contact_values( array( 'email' => 'p7@example.org' ) ) )['outcome'], 'Another IP.' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$later                  = self::NOW + 11 * MINUTE_IN_SECONDS;
		$this->assertSame( 'sent', $this->submit( $this->contact_values( array( 'email' => 'p8@example.org' ) ), 'fr_contact', array(), 30, $later )['outcome'], 'The IP window slides.' );
	}

	/**
	 * Rate limits are filterable; nothing about the IP is stored in the entry.
	 */
	public function test_rate_limit_filter_and_no_ip_stored() {
		add_filter(
			'pegwork_forms_rate_limits',
			fn( $limits ) => array_merge(
				$limits,
				array(
					'ip' => array(
						'max'    => 1,
						'window' => 60,
					),
				)
			)
		);
		$first = $this->submit( $this->contact_values() );
		$this->assertSame( 'rate_limited', $this->submit( $this->contact_values( array( 'email' => 'b@example.org' ) ) )['outcome'] );
		$this->assertStringNotContainsString( '203.0.113.7', wp_json_encode( Entries::get( $first['entry_id'] ) ) );
	}

	/**
	 * An identical submission within 10 minutes returns success without a second entry or mail.
	 */
	public function test_duplicate_submission_is_not_stored_twice() {
		$this->assertSame( 'sent', $this->submit( $this->contact_values() )['outcome'] );
		$this->assertSame( 'duplicate', $this->submit( $this->contact_values() )['outcome'] );
		$this->assertSame( 1, $this->count_entries() );
		$this->assertCount( 3, $this->sent_mail() );
		$this->assertCount( 1, $this->events );
	}

	/**
	 * Merge tag rendering strips CR and LF from subjects and recipients.
	 */
	public function test_notifications_strip_header_injection() {
		$form = pegwork_registry()->form( 'fr_contact' );
		$this->assertSame( '', Notifications::recipient( '{field:email}', array( 'email' => "a@example.org\r\nBcc: b@example.org" ) ) );
		$this->assertSame( 'a@example.org', Notifications::recipient( '{field:email}', array( 'email' => "a@example.org\r\n" ) ) );
		$this->assertSame( '', Notifications::recipient( '{field:email}', array() ) );

		$results = Notifications::send(
			$form,
			'submitted',
			array(
				'entry_id' => 9,
				'values'   => array(
					'name'  => "Eve\r\nBcc: victim@example.org",
					'email' => 'not valid',
				),
			)
		);
		$this->assertSame(
			array(
				'admin'     => 'sent',
				'moderator' => 'sent',
				'submitter' => 'skipped',
			),
			$results
		);
		$mail = $this->sent_mail();
		$this->assertSame( 'Contact: Eve Bcc: victim@example.org', $mail[0]['subject'] );
		$this->assertStringNotContainsString( 'Reply-To', $mail[0]['header'] );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $mail[0]['header'] );
		$this->assertSame( array(), $mail[0]['bcc'] );
	}

	/**
	 * `{option:page.field}` recipients resolve through pegwork_get_option(); an invalid value falls back to the admin email.
	 */
	public function test_option_recipient() {
		update_option( 'fr_settings', array( 'moderator_email' => 'moderator@example.org' ) );
		$this->assertSame( 'moderator@example.org', Notifications::recipient( '{option:fr_settings.moderator_email}', array() ) );

		$result = $this->submit( $this->contact_values() );
		$this->assertSame( 'moderator@example.org', $this->sent_mail()[1]['to'][0][0] );
		$this->assertSame( 'sent', Entries::get( $result['entry_id'] )['meta']['notifications']['moderator'] );

		// The settings screen would refuse this value; force it in as if stored by other code.
		add_filter( 'pre_option_fr_settings', fn() => array( 'moderator_email' => "x@example.org\nBcc: y@example.org" ) );
		$this->assertSame( 'admin@example.org', Notifications::recipient( '{option:fr_settings.moderator_email}', array() ) );
		$this->assertSame( '', Notifications::recipient( '{option:fr_settings.moderator_email}', array(), false ), 'reply_to has no fallback.' );
	}

	/**
	 * Every documented merge tag.
	 */
	public function test_merge_tags() {
		$form = pegwork_registry()->form( 'fr_contact' );
		$text = Notifications::render(
			'{form:label}|{form:key}|{entry:id}|{post:edit_url}|{confirm_url}|{admin_email}|{site:name}|{field:topic}|{field:subscribe}|{field:nope}|{bogus}',
			$form,
			array(
				'entry_id' => 4,
				'values'   => array(
					'topic'     => 'other',
					'subscribe' => true,
				),
			)
		);
		$this->assertSame( 'Contact us|fr_contact|4|||admin@example.org|Test Blog|Something else|Yes||', $text );
	}

	/**
	 * On template_redirect, a valid POST redirects (303) to the success state; an invalid one re-renders.
	 */
	public function test_template_redirect_post_redirect_get() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'contact',
				'post_content' => '<!-- wp:pegwork/form {"form":"fr_contact"} /-->',
			)
		);
		$this->go_to( get_permalink( $page ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/contact/?utm=1&pegwork_sent=old';
		$_POST['pegwork_form']     = array(
			'key'   => 'fr_contact',
			'token' => \PegworkForms\Token::create( 'fr_contact', time() - 30 ),
			'url'   => '',
		);
		$_POST['pegwork']          = $this->contact_values();

		$redirect = null;
		add_filter(
			'wp_redirect',
			function ( $location, $status ) use ( &$redirect ) {
				$redirect = array( $location, $status );
				throw new \RuntimeException( 'redirected' );
			},
			10,
			2
		);
		try {
			Submission::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( array( 'http://example.org/contact/?utm=1&pegwork_sent=fr_contact', 303 ), $redirect );
		$this->assertSame( 1, $this->count_entries() );
		$this->assertSame( $page, Entries::get( (int) $this->events[0][1]['entry_id'] )['meta']['page_id'] );

		$_POST['pegwork']['name']    = '';
		$_POST['pegwork']['message'] = 'Another message';
		Submission::handle();
		$this->assertSame( 'invalid', Submission::state( 'fr_contact' )['outcome'] );
		$this->assertSame( array( 'title' => 'Error: Contact' ), Submission::error_title( array( 'title' => 'Contact' ) ) );
		$this->assertNotFalse( has_filter( 'document_title_parts', array( Submission::class, 'error_title' ) ) );
		remove_filter( 'document_title_parts', array( Submission::class, 'error_title' ) );

		$_POST['pegwork_form']['key'] = 'nope';
		Submission::reset();
		Submission::handle();
		$this->assertNull( Submission::state( 'nope' ), 'Unknown forms are not handled (FormsTurnstileKeysTest: Turnstile forms without keys).' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_POST['pegwork_form'], $_POST['pegwork'] );
	}
}
