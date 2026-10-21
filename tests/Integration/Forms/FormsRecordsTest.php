<?php
/**
 * Integration tests: record mapping and email confirmation.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Confirm;
use PegworkForms\Entries;
use PegworkForms\EntryState;
use PegworkForms\RecordWriter;
use PegworkForms\Renderer;
use PegworkForms\Schema;
use PegworkForms\Submission;

require_once dirname( __DIR__, 2 ) . '/Support/FormsTestCase.php';

/**
 * Bound items, create and update targets, the confirmation hold, link, POST page, atomic claim,
 * re-validation, the per-address email cap and form.confirmed. Uses examples/sifg plus
 * tests/fixtures/forms-records (fx_project is examples/sifg's submit_project without Turnstile).
 */
class FormsRecordsTest extends FormsTestCase {

	/**
	 * Registers examples/sifg and the record fixtures, seeds terms, rebuilds REST routes.
	 */
	public function set_up() {
		parent::set_up();
		$root = dirname( __DIR__, 3 );
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( $root . '/examples/sifg' ) );
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( $root . '/tests/fixtures/forms-records' ) );
		Plugin::init();
		$this->assertTrue( Plugin::result()->is_valid(), 'Fixture packages load.' );
		Taxonomies::seed( pegwork_registry() );
		( require $root . '/modules/forms/module.php' )->boot();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.
		$_SERVER['REQUEST_URI']    = '/submit/';
	}

	/**
	 * Drops the REST server built for the fixture types.
	 */
	public function tear_down() {
		unset( $_GET['pegwork_confirm'], $_GET['pegwork_confirmed'], $_GET['record'] );
		parent::tear_down();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * A valid Submit a project POST.
	 *
	 * @param array $overrides Values to change.
	 * @return array<string, mixed>
	 */
	private function project_values( $overrides = array() ) {
		return array_merge(
			array(
				'name'                => 'River Watch',
				'description'         => trim( str_repeat( 'Volunteers test river water every week. ', 8 ) ) . "\n\nResults <b>go</b> to the city & the public (pH < 7 is acidic).",
				'who_for'             => 'Neighbors along the river',
				'sifg_project_type'   => 'community-group',
				'sifg_project_status' => 'active',
				'sifg_impact'         => array( 'sdg-14', 'sdg-06' ),
				'website'             => 'https://riverwatch.example.org',
				'github_link'         => '',
				'needs_volunteers'    => '1',
				'volunteer_link'      => 'https://riverwatch.example.org/join',
				'contact_email'       => 'Ada@Example.org',
				'consent'             => '1',
			),
			$overrides
		);
	}

	/**
	 * Events of one name.
	 *
	 * @param string $name Event name.
	 * @return array<int, array>
	 */
	private function events_named( $name ) {
		return array_values( array_map( fn( $event ) => $event[1], array_filter( $this->events, fn( $event ) => $name === $event[0] ) ) );
	}

	/**
	 * The confirmation link value (`<id>.<token>`) from the last mail that has one.
	 *
	 * @return string
	 */
	private function confirm_link() {
		foreach ( array_reverse( $this->sent_mail() ) as $mail ) {
			if ( preg_match( '/pegwork_confirm=([0-9]+\.[A-Za-z0-9_-]{43})/', $mail['body'], $match ) ) {
				return $match[1];
			}
		}
		$this->fail( 'No confirmation link was mailed.' );
	}

	/**
	 * Term slugs of a post, sorted.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function slugs( $post_id, $taxonomy ) {
		$slugs = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Number of sifg_project posts in any status.
	 */
	private function project_count() {
		return count(
			get_posts(
				array(
					'post_type'   => 'sifg_project',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Bound items take type, label, required, options and conditions from the registry; the form adds presentation only.
	 */
	public function test_bound_items_render_from_the_registry() {
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' );

		$this->assertStringContainsString( '<fieldset class="pegwork-form__step"><legend class="pegwork-form__step-title"><h2 class="pegwork-form__step-heading">Your project</h2></legend>', $html );
		$this->assertStringContainsString( '<label class="pegwork-form__label" for="pegwork-fx_project-name">Name <span class="pegwork-form__required">(required)</span></label>', $html );
		$this->assertStringContainsString( 'id="pegwork-fx_project-description" name="pegwork[description]" rows="6" aria-required="true" aria-describedby="pegwork-fx_project-description-help">', $html, 'A rich_text field is entered as plain text.' );
		$this->assertStringContainsString( '<fieldset class="pegwork-form__field pegwork-form__field--select" role="radiogroup" aria-required="true"><legend class="pegwork-form__label">Type <span class="pegwork-form__required">(required)</span></legend>', $html );
		$this->assertStringContainsString( 'name="pegwork[sifg_project_type]" value="community-group"> <label for="pegwork-fx_project-sifg_project_type-2">Community group (informal)</label>', $html );
		$this->assertStringContainsString( '<fieldset class="pegwork-form__field pegwork-form__field--choices"><legend class="pegwork-form__label">Impact categories</legend>', $html );
		$this->assertStringContainsString( '<input type="checkbox" id="pegwork-fx_project-sifg_impact-0" name="pegwork[sifg_impact][]" value="sdg-01"> <label for="pegwork-fx_project-sifg_impact-0">No Poverty</label>', $html, 'Terms keep the registry order.' );
		$this->assertStringContainsString( 'Only needed if &quot;Needs volunteers&quot; is checked.', $html, 'Conditions come from the content type.' );
		$this->assertStringContainsString( 'Private. Used only to confirm', $html );
		$this->assertStringContainsString( 'type="email" spellcheck="false" id="pegwork-fx_project-contact_email" name="pegwork[contact_email]" value="" aria-required="true" aria-describedby="pegwork-fx_project-contact_email-help" autocomplete="email">', $html );

		// The view script's config carries the registry rules of bound items.
		preg_match( '/data-pegwork-config="([^"]*)"/', $html, $match );
		$config = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
		$items  = array_column( $config['items'], null, 'key' );
		$this->assertEquals(
			array(
				'min_words' => 50,
				'max_words' => 300,
			),
			$items['description']['validate']
		);
		$this->assertTrue( $items['description']['bound'] );
		$this->assertSame(
			array(
				'field'  => 'needs_volunteers',
				'equals' => true,
			),
			$items['volunteer_link']['show_when']
		);
		$this->assertSame( $items['volunteer_link']['show_when'], $items['volunteer_link']['required_when'] );
		$this->assertSame( '', $items['volunteer_link']['help'] );
		$this->assertSame( array( 'Your project', 'Links', 'Contact' ), array_column( $config['steps'], 'label' ) );

		$direct = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project_direct"} /-->' );
		$this->assertStringContainsString( 'for="pegwork-fx_project_direct-name">Project name <span', $direct, 'A label override is presentational.' );
		$this->assertStringContainsString( '<select class="pegwork-form__select" multiple id="pegwork-fx_project_direct-sifg_impact" name="pegwork[sifg_impact][]">', $direct );
	}

	/**
	 * Submit holds the entry as unconfirmed: no post, a hashed token, one confirm email, form.submitted with a null record.
	 */
	public function test_confirm_form_holds_the_entry_and_mails_a_link() {
		$result = $this->submit( $this->project_values(), 'fx_project' );

		$this->assertSame( 'sent', $result['outcome'], wp_json_encode( $result ) );
		$this->assertNull( $result['record_id'] );
		$this->assertSame( 0, $this->project_count(), 'No post before confirmation.' );

		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'unconfirmed', $entry['status'] );
		$this->assertSame( 'ada@example.org', $entry['email'] );
		$this->assertNull( $entry['post_id'] );
		$this->assertSame( array( 'sdg-06', 'sdg-14' ), $entry['values']['sifg_impact'], 'Choices in option order.' );
		$this->assertSame( 'community-group', $entry['values']['sifg_project_type'] );
		$this->assertSame( self::NOW + 72 * HOUR_IN_SECONDS, $entry['meta']['confirm_expires'] );
		$this->assertSame( 'sent', $entry['meta']['confirm_mail'] );
		$this->assertSame( array( 'admin_new' => 'sent' ), $entry['meta']['notifications'] );

		$mail = $this->sent_mail();
		$this->assertCount( 2, $mail );
		$this->assertSame( 'Ada@Example.org', $mail[0]['to'][0][0] );
		$this->assertSame( 'Confirm your SI for Good submission', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Confirm "River Watch" within 72 hours:', $mail[0]['body'] );
		$link = $this->confirm_link();
		$this->assertStringContainsString( 'http://example.org/?pegwork_confirm=' . $link, $mail[0]['body'], 'Without a viewable page the link goes home.' );
		[ $id, $token ] = explode( '.', $link );
		$this->assertSame( (string) $result['entry_id'], $id );
		$this->assertSame( hash( 'sha256', $token ), $entry['token_hash'], 'Only the hash is stored.' );
		$this->assertStringNotContainsString( $token, wp_json_encode( $entry ) );
		$this->assertSame( 'admin@example.org', $mail[1]['to'][0][0], 'A submitted notification to a fixed address is allowed.' );

		$this->assertSame( array(), $this->events_named( 'record.created' ) );
		$submitted = $this->events_named( 'form.submitted' );
		$this->assertCount( 1, $submitted );
		$this->assertNull( $submitted[0]['record_id'] );
		$this->assertSame( $entry['values'], $submitted[0]['values'] );
	}

	/**
	 * Confirming creates a Pending sifg_project whose meta and terms match the submission exactly.
	 */
	public function test_confirm_creates_the_pending_project() {
		$result = $this->submit( $this->project_values(), 'fx_project' );

		[ $id, $token ] = explode( '.', $this->confirm_link() );
		reset_phpmailer_instance();
		$this->events = array();

		$confirmed = Confirm::confirm( (int) $id, $token, self::NOW + 3600 );
		$this->assertSame( 'confirmed', $confirmed['outcome'] );
		$this->assertSame( 'fx_project', $confirmed['form'] );
		$post_id = $confirmed['record_id'];
		$this->assertIsInt( $post_id );
		$this->assertSame( 1, $this->project_count() );

		$post = get_post( $post_id );
		$this->assertSame( 'sifg_project', $post->post_type );
		$this->assertSame( 'pending', $post->post_status );
		$this->assertSame( 'River Watch', $post->post_title );
		$this->assertSame( '0', (string) $post->post_author, 'Anonymous submitter.' );
		$this->assertSame(
			"<!-- wp:paragraph -->\n<p>" . trim( str_repeat( 'Volunteers test river water every week. ', 8 ) ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Results go to the city &amp; the public (pH &lt; 7 is acidic).</p>\n<!-- /wp:paragraph -->",
			$post->post_content,
			'Paragraph blocks; plain text input (tags stripped), escaped once.'
		);
		$this->assertSame( 'Neighbors along the river', get_post_meta( $post_id, '_sifg_project_who_for', true ) );
		$this->assertSame( 'https://riverwatch.example.org', get_post_meta( $post_id, '_sifg_project_website', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_sifg_project_github_link', true ) );
		$this->assertTrue( (bool) get_post_meta( $post_id, '_sifg_project_needs_volunteers', true ) );
		$this->assertSame( 'https://riverwatch.example.org/join', get_post_meta( $post_id, '_sifg_project_volunteer_link', true ) );
		$this->assertSame( 'Ada@Example.org', get_post_meta( $post_id, '_sifg_project_contact_email', true ), 'Private email stored as meta.' );
		$this->assertSame( '', get_post_meta( $post_id, '_sifg_project_consent', true ), 'Unbound items stay on the entry.' );
		$this->assertSame( array( 'community-group' ), wp_get_object_terms( $post_id, 'sifg_project_type', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'active' ), wp_get_object_terms( $post_id, 'sifg_project_status', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( array( 'sdg-06', 'sdg-14' ), $this->slugs( $post_id, 'sifg_impact' ) );

		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'received', $entry['status'] );
		$this->assertSame( $post_id, $entry['post_id'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 3600 ), $entry['confirmed_at'] );
		$this->assertSame(
			array(
				'admin_new' => 'sent',
				'moderator' => 'sent',
				'submitter' => 'sent',
			),
			$entry['meta']['notifications']
		);

		$mail = $this->sent_mail();
		$this->assertCount( 2, $mail );
		$this->assertSame( 'admin@example.org', $mail[0]['to'][0][0], 'Unset moderator option falls back to the admin email.' );
		$this->assertSame( 'Project to review: River Watch', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Impact categories: Clean Water and Sanitation, Life Below Water', $mail[0]['body'] );
		$this->assertStringContainsString( 'Type: Community group (informal)', $mail[0]['body'] );
		$this->assertStringContainsString( 'post.php?post=' . $post_id . '&action=edit', $mail[0]['body'] );
		$this->assertSame( 'Ada@Example.org', $mail[1]['to'][0][0] );

		$confirmed_events = $this->events_named( 'form.confirmed' );
		$this->assertCount( 1, $confirmed_events );
		$this->assertSame(
			array(
				'form'      => 'fx_project',
				'entry_id'  => $result['entry_id'],
				'record_id' => $post_id,
				'values'    => $entry['values'],
			),
			$confirmed_events[0]
		);
		$this->assertCount( 1, $this->events_named( 'record.created' ) );
	}

	/**
	 * Posted post_type, post_status, author, meta keys and unknown fields are ignored; new term slugs are rejected.
	 */
	public function test_tampered_requests_are_ignored() {
		$values  = $this->project_values(
			array(
				'post_type'                   => 'page',
				'post_status'                 => 'publish',
				'post_author'                 => '1',
				'_sifg_project_last_verified' => '2020-01-01',
				'last_verified'               => '2020-01-01',
				'ID'                          => '1',
			)
		);
		$control = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'record'      => '1',
		);
		$result  = $this->submit( $values, 'fx_project', $control );
		$this->assertSame( 'sent', $result['outcome'] );
		$entry = Entries::get( $result['entry_id'] );
		foreach ( array( 'post_type', 'post_status', 'post_author', '_sifg_project_last_verified', 'last_verified', 'ID' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $entry['values'] );
		}
		$this->assertArrayNotHasKey( 'record', $entry['meta'] );

		[ $id, $token ] = explode( '.', $this->confirm_link() );
		$post_id        = Confirm::confirm( (int) $id, $token, self::NOW + 60 )['record_id'];
		$post           = get_post( $post_id );
		$this->assertSame( 'sifg_project', $post->post_type );
		$this->assertSame( 'pending', $post->post_status );
		$this->assertSame( '0', (string) $post->post_author );
		$this->assertSame( '', get_post_meta( $post_id, '_sifg_project_last_verified', true ) );

		$terms    = wp_count_terms( array( 'taxonomy' => 'sifg_impact' ) );
		$tampered = array(
			'name'              => 'Other',
			'sifg_impact'       => array( 'sdg-01', 'brand-new' ),
			'sifg_project_type' => 'made-up',
		);
		$invalid  = $this->submit( $this->project_values( $tampered ), 'fx_project' );
		$this->assertSame( 'invalid', $invalid['outcome'] );
		$this->assertSame( array( 'sifg_project_type', 'sifg_impact' ), array_keys( $invalid['errors'] ) );
		$this->assertSame( 'Choose one of the options for Type.', $invalid['errors']['sifg_project_type'] );
		$this->assertSame( $terms, wp_count_terms( array( 'taxonomy' => 'sifg_impact' ) ), 'Public input never creates terms.' );
		$this->assertFalse( get_term_by( 'slug', 'brand-new', 'sifg_impact' ) );
	}

	/**
	 * The content type's rules apply through pegwork_validate_record(), mapped back to form keys.
	 */
	public function test_record_rules_validate_bound_items() {
		$result = $this->submit(
			$this->project_values(
				array(
					'description'         => 'Too short.',
					'sifg_project_status' => '',
					'volunteer_link'      => '',
					'consent'             => '',
				)
			),
			'fx_project'
		);

		$this->assertSame( 'invalid', $result['outcome'] );
		$this->assertSame( array( 'description', 'sifg_project_status', 'volunteer_link', 'consent' ), array_keys( $result['errors'] ) );
		$this->assertSame( 'Description must be at least 50 words (it has 2).', $result['errors']['description'] );
		$this->assertSame( 'Project status is required.', $result['errors']['sifg_project_status'] );
		$this->assertSame( 'Volunteer link is required.', $result['errors']['volunteer_link'], 'required_when from the content type.' );
		$this->assertStringStartsWith( 'Check the box to confirm:', $result['errors']['consent'] );
		$this->assertSame( array( 'sdg-14', 'sdg-06' ), $result['values']['sifg_impact'], 'Posted choices are kept for the re-render.' );
		$this->assertSame( 0, $this->count_entries() );
		$this->assertCount( 0, $this->sent_mail() );

		$hidden = $this->submit(
			$this->project_values(
				array(
					'needs_volunteers' => '',
					'volunteer_link'   => 'https://ignored.example',
				)
			),
			'fx_project'
		);
		$this->assertSame( 'sent', $hidden['outcome'] );
		$this->assertArrayNotHasKey( 'volunteer_link', Entries::get( $hidden['entry_id'] )['values'], 'show_when from the content type.' );
	}

	/**
	 * A second confirm, a concurrent claim, an expired token and a wrong token create nothing more.
	 */
	public function test_second_concurrent_expired_and_wrong_confirms() {
		$first = $this->submit( $this->project_values(), 'fx_project' );

		[ $id, $token ] = explode( '.', $this->confirm_link() );

		$this->assertSame( 'invalid', Confirm::confirm( (int) $id, str_repeat( 'A', 43 ), self::NOW + 60 )['outcome'] );
		$this->assertSame( 'unconfirmed', Entries::get( $first['entry_id'] )['status'] );

		$this->assertSame( 'confirmed', Confirm::confirm( (int) $id, $token, self::NOW + 60 )['outcome'] );
		$again = Confirm::confirm( (int) $id, $token, self::NOW + 120 );
		$this->assertSame( 'done', $again['outcome'] );
		$this->assertNull( $again['record_id'] );
		$this->assertSame( 1, $this->project_count(), 'No duplicate post.' );
		$this->assertCount( 1, $this->events_named( 'form.confirmed' ) );

		// Another request claimed the entry between our check and our claim.
		$second = $this->submit( $this->project_values( array( 'name' => 'Second project' ) ), 'fx_project' );

		[ $id2, $token2 ] = explode( '.', $this->confirm_link() );
		$this->assertTrue( EntryState::claim( (int) $id2, 'unconfirmed', 'processing' ) );
		$this->assertFalse( EntryState::claim( (int) $id2, 'unconfirmed', 'processing' ), 'The claim is atomic.' );
		$this->assertSame( 'done', Confirm::confirm( (int) $id2, $token2, self::NOW + 60 )['outcome'] );
		$this->assertSame( 1, $this->project_count() );
		$this->assertSame( 'processing', Entries::get( $second['entry_id'] )['status'] );

		$third = $this->submit( $this->project_values( array( 'name' => 'Third project' ) ), 'fx_project' );

		[ $id3, $token3 ] = explode( '.', $this->confirm_link() );
		$this->assertSame( 'pending', Confirm::state( Entries::get( (int) $id3 ), $token3, self::NOW + 72 * HOUR_IN_SECONDS ) );
		$this->assertSame( 'expired', Confirm::confirm( (int) $id3, $token3, self::NOW + 72 * HOUR_IN_SECONDS + 1 )['outcome'] );
		$this->assertSame( 'unconfirmed', Entries::get( $third['entry_id'] )['status'] );
		$this->assertSame( 1, $this->project_count() );
		$this->assertSame( 'invalid', Confirm::confirm( 999999, $token3, self::NOW )['outcome'] );
	}

	/**
	 * The link page (GET) changes nothing and offers a POST button; the states render friendly messages.
	 */
	public function test_confirm_pages() {
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'submit',
				'post_content' => '<!-- wp:pegwork/form {"form":"fx_project"} /-->',
			)
		);
		$result = Submission::process( pegwork_registry()->form( 'fx_project' ), $this->project_values(), $this->control( 'fx_project' ), self::NOW, $page );
		$link   = $this->confirm_link();
		$this->assertStringContainsString( add_query_arg( 'pegwork_confirm', $link, get_permalink( $page ) ), $this->sent_mail()[0]['body'], 'The link goes to the page that held the form.' );

		$_GET['pegwork_confirm'] = $link;
		$html                    = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' );
		$this->assertStringContainsString( '<h2 class="pegwork-form__confirm-title" id="pegwork-fx_project-confirm-title">Confirm your submission</h2>', $html );
		$this->assertStringContainsString( 'method="post" action="http://example.org/submit/?pegwork_confirm=' . $link . '"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="pegwork_confirm[entry]" value="' . $result['entry_id'] . '">', $html );
		$this->assertStringContainsString( '>Confirm my submission</button>', $html );
		$this->assertStringNotContainsString( 'pegwork[name]', $html, 'The form itself is not shown.' );
		$this->assertStringNotContainsString( 'Ada@Example.org', $html, 'The private address is never output.' );
		$this->assertSame( 'unconfirmed', Entries::get( $result['entry_id'] )['status'], 'GET never confirms.' );
		$this->assertSame( 0, $this->project_count() );

		// The POST handler confirms, then redirects (303) to the thank-you state.
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/submit/?pegwork_confirm=' . $link;
		[ $id, $token ]            = explode( '.', $link );
		$_POST['pegwork_confirm']  = array(
			'entry' => $id,
			'token' => $token,
		);
		$redirect                  = null;
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
			Confirm::handle();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertSame( array( 'http://example.org/submit/?pegwork_confirmed=fx_project', 303 ), $redirect );
		$this->assertSame( 'received', Entries::get( $result['entry_id'] )['status'] );
		$this->assertSame( 1, $this->project_count() );

		// Pressing the button again (back button) shows "already confirmed" without a second post.
		Confirm::reset();
		Renderer::reset();
		Confirm::handle();
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' );
		$this->assertStringContainsString( 'role="status" tabindex="-1" autofocus><p>This submission is already confirmed.', $html );
		$this->assertSame( 1, $this->project_count() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_POST['pegwork_confirm'], $_GET['pegwork_confirm'] );

		Renderer::reset();
		$_GET['pegwork_confirmed'] = 'fx_project';
		$this->assertStringContainsString( '<div class="pegwork-form__status" role="status" tabindex="-1" autofocus><p>Thank you. Your submission is confirmed.</p></div>', do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' ) );
		unset( $_GET['pegwork_confirmed'] );

		// A wrong token: a notice, then the form to submit again.
		Confirm::reset();
		Renderer::reset();
		$_GET['pegwork_confirm'] = $id . '.' . str_repeat( 'B', 43 );
		$html                    = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' );
		$this->assertStringContainsString( 'role="alert" tabindex="-1" autofocus><p>This confirmation link is not valid.', $html );
		$this->assertStringContainsString( 'name="pegwork[name]"', $html );
		$this->assertStringContainsString( 'action="http://example.org/submit/" novalidate', $html, 'The form does not carry the token along.' );

		// Expired: a notice and the form.
		$then    = time() - 2 * HOUR_IN_SECONDS;
		$expired = Submission::process( pegwork_registry()->form( 'fx_newsletter' ), array( 'email' => 'old@example.org' ), $this->control( 'fx_newsletter', 30, array( 'token' => \PegworkForms\Token::create( 'fx_newsletter', $then - 30 ) ) ), $then, $page );
		Renderer::reset();
		$_GET['pegwork_confirm'] = $this->confirm_link();
		$html                    = do_blocks( '<!-- wp:pegwork/form {"form":"fx_newsletter"} /-->' );
		$this->assertSame( 'sent', $expired['outcome'] );
		$this->assertStringContainsString( 'This confirmation link has expired. Submit the form again below.', $html );
		$this->assertStringContainsString( 'name="pegwork[email]"', $html );

		// The link of another form's entry does not change this form's block.
		Renderer::reset();
		$this->assertStringNotContainsString( 'pegwork-form__notice', do_blocks( '<!-- wp:pegwork/form {"form":"fx_project"} /-->' ) );
	}

	/**
	 * On a page whose content lacks the form (home fallback, or a form placed by a template), the block is added to the main content.
	 */
	public function test_confirm_link_on_a_page_without_the_block() {
		$this->submit( $this->project_values(), 'fx_project' );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>Welcome</p><!-- /wp:paragraph -->',
			)
		);
		$this->go_to( get_permalink( $page ) );
		$_GET['pegwork_confirm'] = $this->confirm_link();
		Confirm::handle();
		$this->assertSame( 10, has_filter( 'wp_robots', 'wp_robots_no_robots' ), 'Confirmation pages are not indexed.' );

		$this->the_post_for( $page );
		$content = apply_filters( 'the_content', get_post( $page )->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringContainsString( 'Confirm my submission', $content );
		$this->assertStringContainsString( 'Welcome', $content );
		$again = apply_filters( 'the_content', get_post( $page )->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertStringNotContainsString( 'Confirm my submission', $again, 'Added once per request.' );
		remove_filter( 'the_content', array( Confirm::class, 'inject' ), 8 );
	}

	/**
	 * Sets up the global post as the main loop does.
	 *
	 * @param int $post_id Post ID.
	 */
	private function the_post_for( $post_id ) {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the loop.
		setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * At most 3 confirmation emails per address per 24 hours, across forms.
	 */
	public function test_confirm_email_cap_per_address() {
		$this->assertSame( 'sent', $this->submit( $this->project_values( array( 'name' => 'One' ) ), 'fx_project' )['outcome'] );
		$this->assertSame( 'sent', $this->submit( $this->project_values( array( 'name' => 'Two' ) ), 'fx_project' )['outcome'] );
		$this->assertSame( 'sent', $this->submit( array( 'email' => 'ADA@example.org' ), 'fx_newsletter' )['outcome'] );
		$this->assertCount( 3, array_filter( $this->sent_mail(), fn( $mail ) => str_contains( $mail['body'], 'pegwork_confirm=' ) ) );

		$over = $this->submit( $this->project_values( array( 'name' => 'Three' ) ), 'fx_project', array(), 30, self::NOW + HOUR_IN_SECONDS + 1 );
		$this->assertSame( 'rate_limited', $over['outcome'] );
		$this->assertSame( 3, $this->count_entries() );
		$this->assertCount( 3, array_filter( $this->sent_mail(), fn( $mail ) => str_contains( $mail['body'], 'pegwork_confirm=' ) ) );

		$grace = array(
			'name'          => 'Three',
			'contact_email' => 'grace@example.org',
		);
		$other = $this->submit( $this->project_values( $grace ), 'fx_project', array(), 30, self::NOW + HOUR_IN_SECONDS + 1 );
		$this->assertSame( 'sent', $other['outcome'], 'Other addresses are not affected.' );

		$later = $this->submit( $this->project_values( array( 'name' => 'Four' ) ), 'fx_project', array(), 30, self::NOW + DAY_IN_SECONDS + 1 );
		$this->assertSame( 'sent', $later['outcome'], 'The window is 24 hours.' );

		$victim = array(
			'name'          => 'Spam',
			'contact_email' => 'victim@example.org',
		);
		$spam   = $this->submit( $this->project_values( $victim ), 'fx_project', array( 'url' => 'x' ) );
		$this->assertSame( 'spam', $spam['outcome'] );
		$this->assertCount( 0, array_filter( $this->sent_mail(), fn( $mail ) => 'victim@example.org' === $mail['to'][0][0] ), 'Spam never sends mail.' );
	}

	/**
	 * Values that no longer validate at confirmation become a draft; the entry fails and the admin is told.
	 */
	public function test_revalidation_failure_creates_a_draft() {
		global $wpdb;
		$result = $this->submit( $this->project_values(), 'fx_project' );

		[ $id, $token ] = explode( '.', $this->confirm_link() );

		$values                = Entries::get( $result['entry_id'] )['values'];
		$values['description'] = 'Now far too short.';
		$wpdb->update( Schema::table(), array( 'field_values' => wp_json_encode( $values ) ), array( 'id' => $result['entry_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Simulates a changed rule.
		reset_phpmailer_instance();
		$this->events = array();

		$confirmed = Confirm::confirm( (int) $id, $token, self::NOW + 60 );
		$this->assertSame( 'confirmed', $confirmed['outcome'], 'The submitter still sees the thank-you.' );
		$draft = get_post( $confirmed['record_id'] );
		$this->assertSame( 'draft', $draft->post_status );
		$this->assertSame( 'River Watch', $draft->post_title );

		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'failed', $entry['status'] );
		$this->assertSame( $draft->ID, $entry['post_id'] );
		$this->assertSame( array( 'description' => 'Description must be at least 50 words (it has 4).' ), $entry['meta']['errors'] );
		$this->assertSame( 'sent', $entry['meta']['failure_notice'] );

		$mail = $this->sent_mail();
		$this->assertCount( 1, $mail, 'Only the admin notice; no confirmed notifications.' );
		$this->assertSame( 'admin@example.org', $mail[0]['to'][0][0] );
		$this->assertSame( '[Test Blog] Form submission needs attention: Submit a project', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Description must be at least 50 words', $mail[0]['body'] );
		$this->assertStringContainsString( 'post.php?post=' . $draft->ID . '&action=edit', $mail[0]['body'] );
		$this->assertSame( array(), $this->events_named( 'form.confirmed' ) );
	}

	/**
	 * Without confirm, the record is created at submit: Pending, linked from the entry, record_id on form.submitted.
	 */
	public function test_create_without_confirmation() {
		$result = $this->submit(
			array(
				'name'                => 'Seed Library',
				'description'         => trim( str_repeat( 'Neighbors share seeds and grow food together. ', 8 ) ),
				'sifg_project_type'   => 'nonprofit',
				'sifg_project_status' => 'idea',
				'sifg_impact'         => array( 'sdg-02' ),
				'contact_email'       => 'seeds@example.org',
				'note'                => 'Please call first.',
				'_sifg_project_note'  => 'tamper',
			),
			'fx_project_direct'
		);

		$this->assertSame( 'sent', $result['outcome'], wp_json_encode( $result ) );
		$post = get_post( $result['record_id'] );
		$this->assertSame( 'pending', $post->post_status, 'Default status for create.' );
		$this->assertSame( 'Seed Library', $post->post_title );
		$this->assertSame( array( 'sdg-02' ), wp_get_object_terms( $post->ID, 'sifg_impact', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( '', get_post_meta( $post->ID, '_sifg_project_note', true ) );

		$entry = Entries::get( $result['entry_id'] );
		$this->assertSame( 'received', $entry['status'] );
		$this->assertSame( $post->ID, $entry['post_id'] );
		$this->assertNull( $entry['token_hash'] );
		$this->assertSame( 'Please call first.', $entry['values']['note'] );
		$this->assertStringContainsString( 'post.php?post=' . $post->ID . '&action=edit', $this->sent_mail()[0]['body'] );

		$submitted = $this->events_named( 'form.submitted' );
		$this->assertSame( $post->ID, $submitted[0]['record_id'] );
		$this->assertCount( 1, $this->events_named( 'record.created' ) );
	}

	/**
	 * Update forms need edit_post on a record of the target type, keep its status and prefill its values.
	 */
	public function test_update_action() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$record = \Pegwork\Api\Records::create(
			'sifg_project',
			array(
				'name'                => 'Old name',
				'description'         => trim( str_repeat( 'Word ', 60 ) ),
				'who_for'             => 'Everyone',
				'sifg_project_type'   => array( 'individual' ),
				'sifg_project_status' => array( 'idea' ),
				'sifg_impact'         => array( 'sdg-04' ),
				'contact_email'       => 'owner@example.org',
			),
			'publish'
		);
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertIsInt( $record );
		wp_update_post(
			array(
				'ID'          => $record,
				'post_author' => $author,
			)
		);

		$this->assertSame( 'denied', $this->submit( array( 'who_for' => 'x' ), 'fx_project_edit', array( 'record' => $record ) )['outcome'], 'Anonymous: access.' );

		wp_set_current_user( $other );
		$this->assertSame( 'denied', $this->submit( array( 'who_for' => 'x' ), 'fx_project_edit', array( 'record' => $record ) )['outcome'], 'No edit_post on another author\'s published record.' );

		wp_set_current_user( $author );
		$this->assertSame( 'denied', $this->submit( array( 'who_for' => 'x' ), 'fx_project_edit', array( 'record' => $page ) )['outcome'], 'Wrong type.' );
		$this->assertSame( 'denied', $this->submit( array( 'who_for' => 'x' ), 'fx_project_edit' )['outcome'], 'No record.' );

		$_GET['record'] = (string) $record;
		$html           = do_blocks( '<!-- wp:pegwork/form {"form":"fx_project_edit"} /-->' );
		$this->assertStringContainsString( 'name="pegwork_form[record]" value="' . $record . '"', $html );
		$this->assertStringContainsString( 'name="pegwork[who_for]" value="Everyone"', $html );
		$this->assertStringContainsString( 'name="pegwork[sifg_impact][]" value="sdg-04"> <label', str_replace( ' checked', '', $html ) );
		$this->assertStringContainsString( 'value="sdg-04" checked>', $html, 'Terms prefilled.' );

		$result = $this->submit(
			array(
				'who_for'     => 'Students',
				'website'     => 'https://new.example.org',
				'sifg_impact' => array( 'sdg-04', 'sdg-10' ),
			),
			'fx_project_edit',
			array( 'record' => $record )
		);
		$this->assertSame( 'sent', $result['outcome'], wp_json_encode( $result ) );
		$this->assertSame( $record, $result['record_id'] );
		$post = get_post( $record );
		$this->assertSame( 'publish', $post->post_status, 'Update keeps the status.' );
		$this->assertSame( 'Old name', $post->post_title, 'Unbound keys keep their value.' );
		$this->assertSame( 'Students', get_post_meta( $record, '_sifg_project_who_for', true ) );
		$this->assertSame( array( 'sdg-04', 'sdg-10' ), $this->slugs( $record, 'sifg_impact' ) );
		$this->assertSame( $record, Entries::get( $result['entry_id'] )['post_id'] );
		$this->assertSame( $record, Entries::get( $result['entry_id'] )['meta']['record'] );

		wp_set_current_user( $other );
		Renderer::reset();
		$this->assertStringContainsString( 'There is no record here that you can edit with this form.', do_blocks( '<!-- wp:pegwork/form {"form":"fx_project_edit"} /-->' ) );
	}

	/**
	 * The private contact email is stored as meta but not served by public REST once the project is published.
	 */
	public function test_private_email_is_not_public() {
		$result = $this->submit( $this->project_values(), 'fx_project' );

		[ $id, $token ] = explode( '.', $this->confirm_link() );
		$post_id        = Confirm::confirm( (int) $id, $token, self::NOW + 60 )['record_id'];
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( 0 );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/projects/' . $post_id ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( '_sifg_project_contact_email', $response->get_data()['meta'] );
		$this->assertStringNotContainsString( 'Ada@Example.org', wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'Ada@Example.org', apply_filters( 'the_content', get_post( $post_id )->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertSame( 'Ada@Example.org', get_post_meta( $post_id, '_sifg_project_contact_email', true ) );
		$this->assertSame( $post_id, Entries::get( $result['entry_id'] )['post_id'] );
	}

	/**
	 * Paragraph conversion and its inverse for update prefill.
	 */
	public function test_paragraphs_round_trip() {
		$text   = "First line\nsecond line\n\n\n  Second <i>para</i> & more  ";
		$blocks = RecordWriter::paragraphs( $text );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>First line<br>second line</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second &lt;i&gt;para&lt;/i&gt; &amp; more</p>\n<!-- /wp:paragraph -->", $blocks );
		$this->assertSame( "First line\nsecond line\n\nSecond <i>para</i> & more", RecordWriter::plain_text( $blocks ) );
		$this->assertSame( '', RecordWriter::paragraphs( " \n\n " ) );
	}

	/**
	 * Forms with a target, confirm or bound items are supported; Turnstile forms too, with keys set
	 * (FormsTurnstileKeysTest covers missing keys).
	 */
	public function test_target_and_confirm_forms_are_supported() {
		foreach ( array( 'fx_project', 'fx_project_direct', 'fx_project_edit', 'fx_newsletter', 'submit_project' ) as $key ) {
			$this->assertNull( \PegworkForms\Definition::unsupported( pegwork_registry()->form( $key ) ), $key );
		}
	}
}
