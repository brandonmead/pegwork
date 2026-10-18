<?php
/**
 * Integration tests: the Entries screen and its actions.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.NonceVerification -- The tests drive core's admin menu and request globals.

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\EntriesTestCase;
use PegworkForms\EntriesAdmin;
use PegworkForms\Entries;
use PegworkForms\EntryActions;
use PegworkForms\Export;
use PegworkForms\Notifications;

require_once dirname( __DIR__, 2 ) . '/Support/EntriesTestCase.php';

/**
 * List, filters, entry screen, delete confirmation, bulk actions and "Create post anyway", each behind
 * the forms capability and a nonce.
 */
class EntriesAdminTest extends EntriesTestCase {

	/**
	 * Sets the admin screen the list table renders on.
	 */
	public function set_up() {
		parent::set_up();
		set_current_screen( 'pegwork_page_' . Notifications::ENTRIES_PAGE );
	}

	/**
	 * Leaves the admin screen.
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Renders the screen with query arguments.
	 *
	 * @param array $query Query arguments.
	 * @return string HTML.
	 */
	private function render( $query = array() ) {
		$_GET     = $query;
		$_REQUEST = $query;
		ob_start();
		EntriesAdmin::render();
		return ob_get_clean();
	}

	/**
	 * Posts to the actions handler.
	 *
	 * @param array       $fields Fields (op, entry[], confirmed, list args).
	 * @param string|null $nonce  Nonce, or null for a valid one.
	 * @return string Redirect location.
	 */
	private function post( $fields, $nonce = null ) {
		$fields['_wpnonce'] = $nonce ?? wp_create_nonce( EntryActions::NONCE );
		$_POST              = $fields;
		$_REQUEST           = $fields;
		return $this->redirect_of( array( EntryActions::class, 'handle' ) );
	}

	/**
	 * Admin menu: Pegwork > Entries at the documented slug, with the forms capability.
	 */
	public function test_menu_registers_entries_with_forms_capability() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$GLOBALS['submenu'] = array();
		add_menu_page( 'Pegwork', 'Pegwork', 'manage_options', 'pegwork', '__return_null' );
		EntriesAdmin::menu();

		$items = array_column( $GLOBALS['submenu']['pegwork'], null, 2 );
		$this->assertSame( 'Entries', $items[ Notifications::ENTRIES_PAGE ][0] );
		$this->assertSame( 'manage_options', $items[ Notifications::ENTRIES_PAGE ][1] );
		$GLOBALS['submenu'] = array();
		$GLOBALS['menu']    = array();
	}

	/**
	 * Every screen and every action refuses editors and subscribers with 403, even with a valid nonce.
	 */
	public function test_every_screen_and_action_needs_the_capability() {
		$id = $this->entry();

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->login_as( $role );
			$nonce = wp_create_nonce( EntryActions::NONCE );

			foreach ( array( array(), array( 'entry' => $id ), array( 'delete' => (string) $id ) ) as $query ) {
				$this->assertSame( 403, $this->die_code( fn() => $this->render( $query ) ), $role . ' screen ' . wp_json_encode( $query ) );
			}
			foreach ( EntryActions::OPS as $op ) {
				$fields   = array(
					'op'        => $op,
					'entry'     => array( $id ),
					'confirmed' => '1',
					'_wpnonce'  => $nonce,
				);
				$_POST    = $fields;
				$_REQUEST = $fields;
				$this->assertSame( 403, $this->die_code( array( EntryActions::class, 'handle' ) ), $role . ' ' . $op );
			}

			$_GET     = array(
				'form'     => 'fr_contact',
				'_wpnonce' => wp_create_nonce( Export::ACTION ),
			);
			$_REQUEST = $_GET;
			$this->assertSame( 403, $this->die_code( array( Export::class, 'handle' ) ), $role . ' export' );
		}

		$this->assertSame( 'received', Entries::get( $id )['status'] );
	}

	/**
	 * The `pegwork_forms_capability` filter widens access to the screen.
	 */
	public function test_capability_filter_lets_editors_in() {
		$this->entry();
		add_filter( 'pegwork_forms_capability', fn() => 'edit_others_posts' );
		$this->login_as( 'editor' );

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Entries</h1>', $this->render() );
	}

	/**
	 * Actions and the export refuse a missing or wrong nonce with 403 and change nothing.
	 */
	public function test_actions_and_export_need_a_nonce() {
		$id = $this->entry();

		foreach ( array( '', 'not-a-nonce', wp_create_nonce( Export::ACTION ) ) as $nonce ) {
			foreach ( EntryActions::OPS as $op ) {
				$fields   = array(
					'op'        => $op,
					'entry'     => array( $id ),
					'confirmed' => '1',
					'_wpnonce'  => $nonce,
				);
				$_POST    = $fields;
				$_REQUEST = $fields;
				$this->assertSame( 403, $this->die_code( array( EntryActions::class, 'handle' ) ), $op . ' with nonce ' . $nonce );
			}
		}
		$this->assertSame( 'received', Entries::get( $id )['status'] );

		foreach ( array( '', 'not-a-nonce', wp_create_nonce( EntryActions::NONCE ) ) as $nonce ) {
			$_GET     = array(
				'form'     => 'fr_contact',
				'_wpnonce' => $nonce,
			);
			$_REQUEST = $_GET;
			$this->assertSame( 403, $this->die_code( array( Export::class, 'handle' ) ), 'export with nonce ' . $nonce );
		}
	}

	/**
	 * The list shows entries newest first with escaped values, form labels and status, plus a CSV link once a form is chosen.
	 */
	public function test_list_renders_entries() {
		$this->entry(
			array(
				'values'     => array( 'name' => '<script>alert(1)</script>' ),
				'created_at' => '2026-01-01 10:00:00',
			)
		);
		$this->entry(
			array(
				'email'      => 'grace@example.org',
				'created_at' => '2026-01-02 10:00:00',
			)
		);

		$html = $this->render();
		$this->assertStringContainsString( 'wp-list-table', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'Contact us', $html );
		$this->assertLessThan( strpos( $html, 'ada@example.org' ), strpos( $html, 'grace@example.org' ), 'Newest first.' );
		$this->assertStringContainsString( 'All <span class="count">(2)</span>', $html );
		$this->assertStringNotContainsString( 'Export CSV', $html );
		$this->assertStringContainsString( 'To export entries as CSV, choose a form', $html );
		$this->assertStringContainsString( 'name="action" value="' . EntryActions::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		$html = $this->render( array( 'form' => 'fr_contact' ) );
		$this->assertStringContainsString( 'Export CSV', $html );
		$this->assertStringContainsString( 'action=' . Export::ACTION, $html );
	}

	/**
	 * Filters: form, status view (All hides spam), email search; pagination at 20.
	 */
	public function test_filters_views_search_and_paging() {
		$this->entry( array( 'email' => 'spammer@example.org', 'status' => 'spam' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test data.
		$this->entry(
			array(
				'form_key' => 'fe_story',
				'email'    => 'story@example.org',
			)
		);
		for ( $i = 0; $i < 22; $i++ ) {
			$this->entry( array( 'email' => "bulk{$i}@example.org" ) );
		}

		$all = $this->render();
		$this->assertStringNotContainsString( 'spammer@example.org', $all );
		$this->assertStringContainsString( '23 items', $all );
		$this->assertStringContainsString( 'Spam <span class="count">(1)</span>', $all );

		$spam = $this->render( array( 'status' => 'spam' ) );
		$this->assertStringContainsString( 'spammer@example.org', $spam );
		$this->assertStringContainsString( '<option value="not_spam">Not spam</option>', $spam );
		$this->assertStringNotContainsString( '<option value="spam">', $spam );

		$story = $this->render( array( 'form' => 'fe_story' ) );
		$this->assertStringContainsString( 'story@example.org', $story );
		$this->assertStringNotContainsString( 'bulk0@example.org', $story );

		$search = $this->render( array( 's' => 'BULK1' ) );
		$this->assertStringContainsString( 'bulk1@example.org', $search );
		$this->assertStringContainsString( 'bulk12@example.org', $search );
		$this->assertStringNotContainsString( 'bulk2@example.org', $search );

		$page2 = $this->render( array( 'paged' => '2' ) );
		$this->assertSame( 3, substr_count( $page2, 'name="entry[]"' ) );
	}

	/**
	 * Empty states: nothing yet, and nothing matching.
	 */
	public function test_empty_states() {
		$this->assertStringContainsString( 'No entries yet. Entries appear here when someone submits a form.', $this->render() );
		$this->assertStringContainsString( 'No entries match these filters.', $this->render( array( 'status' => 'failed' ) ) );
	}

	/**
	 * The entry screen lists labeled, escaped values in form order, extra keys, status, times and meta.
	 */
	public function test_entry_screen_shows_values_and_details() {
		$id = $this->entry(
			array(
				'values' => array(
					'message' => "Line one\nLine <b>two</b>",
					'topic'   => 'talks',
					'name'    => 'Ada',
					'legacy'  => 'old value',
					'consent' => true,
				),
				'meta'   => array(
					'notifications' => array( 'admin' => 'sent' ),
					'spam'          => array( 'honeypot' ),
				),
			)
		);

		$html = $this->render( array( 'entry' => (string) $id ) );
		$this->assertStringContainsString( '<h1>Entry ' . $id . '</h1>', $html );
		$this->assertStringContainsString( '<th scope="row">Topic</th><td>Talks</td>', $html );
		$this->assertStringContainsString( 'Line one<br />' . "\n" . 'Line &lt;b&gt;two&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<th scope="row">I agree to be contacted</th><td>Yes</td>', $html );
		$this->assertStringContainsString( '<th scope="row">legacy</th><td>old value</td>', $html );
		$this->assertLessThan( strpos( $html, '>Topic<' ), strpos( $html, '>Name<' ), 'Form order.' );
		$this->assertStringContainsString( 'admin: sent', $html );
		$this->assertStringContainsString( 'honeypot', $html );
		$this->assertStringContainsString( 'value="spam">Mark as spam', $html );
		$this->assertStringNotContainsString( 'Create post anyway', $html, 'Entry-only form without a draft type.' );

		$this->assertStringContainsString( 'Entry not found', $this->render( array( 'entry' => '999999' ) ) );
	}

	/**
	 * Entries of a deleted form stay listed and viewable, labeled as such.
	 */
	public function test_deleted_form_entries_are_labeled() {
		$id = $this->entry( array( 'form_key' => 'gone_form' ) );

		$this->assertStringContainsString( 'gone_form (deleted form)', $this->render() );
		$this->assertStringContainsString( 'gone_form (deleted form)', $this->render( array( 'entry' => (string) $id ) ) );
	}

	/**
	 * Bulk spam and not spam change only eligible entries and report the count.
	 */
	public function test_bulk_spam_and_not_spam() {
		$a = $this->entry();
		$b = $this->entry( array( 'status' => 'unconfirmed' ) );
		$c = $this->entry( array( 'status' => 'processing' ) );

		$location = $this->post(
			array(
				'op'     => 'spam',
				'entry'  => array( $a, $b, $c ),
				'form'   => 'fr_contact',
				'status' => 'all',
			)
		);
		$this->assertStringContainsString( 'page=' . Notifications::ENTRIES_PAGE, $location );
		$this->assertStringContainsString( 'form=fr_contact', $location );
		$this->assertSame( 'spam', Entries::get( $a )['status'] );
		$this->assertSame( 'spam', Entries::get( $b )['status'] );
		$this->assertSame( 'processing', Entries::get( $c )['status'], 'A confirmation in progress is left alone.' );
		$this->assertStringContainsString( '2 entries marked as spam.', $this->render() );

		$this->post(
			array(
				'op'    => 'not_spam',
				'entry' => array( $a ),
			)
		);
		$this->assertSame( 'received', Entries::get( $a )['status'] );
		$this->assertStringContainsString( '1 entry marked as not spam.', $this->render() );
	}

	/**
	 * Delete goes through the confirmation screen; only the confirmed POST deletes.
	 */
	public function test_delete_needs_confirmation() {
		$a = $this->entry();
		$b = $this->entry( array( 'email' => 'grace@example.org' ) );

		$location = $this->post(
			array(
				'op'    => 'delete',
				'entry' => array( $a, $b ),
			)
		);
		$this->assertStringContainsString( 'delete=' . rawurlencode( $a . ',' . $b ), $location );
		$this->assertNotNull( Entries::get( $a ) );

		$html = $this->render( array( 'delete' => $a . ',' . $b ) );
		$this->assertStringContainsString( '<h1>Delete entries</h1>', $html );
		$this->assertStringContainsString( 'grace@example.org', $html );
		$this->assertStringContainsString( 'name="confirmed" value="1"', $html );
		$this->assertStringContainsString( 'Cancel', $html );

		$this->post(
			array(
				'op'        => 'delete',
				'entry'     => array( $a, $b ),
				'confirmed' => '1',
			)
		);
		$this->assertNull( Entries::get( $a ) );
		$this->assertNull( Entries::get( $b ) );
		$this->assertStringContainsString( '2 entries permanently deleted.', $this->render() );
		$this->assertStringContainsString( 'These entries no longer exist.', $this->render( array( 'delete' => (string) $a ) ) );
	}

	/**
	 * An action without a selection or an operation changes nothing; Enter in the page field just pages.
	 */
	public function test_missing_selection_or_operation() {
		$id = $this->entry();

		$this->post( array( 'op' => 'spam' ) );
		$this->assertStringContainsString( 'Select at least one entry.', $this->render() );

		$location = $this->post( array( 'paged' => '2' ) );
		$this->assertStringContainsString( 'paged=2', $location );
		$this->assertSame( 'received', Entries::get( $id )['status'] );
	}

	/**
	 * "Create post anyway" for an unconfirmed entry of a target form: a Draft of the type with the bound
	 * values, linked; the entry becomes received and cannot make a second post.
	 */
	public function test_create_post_anyway_for_target_form() {
		$id = $this->entry(
			array(
				'form_key' => 'fe_project',
				'status'   => 'unconfirmed',
				'email'    => 'owner@example.org',
				'values'   => array(
					'title'         => 'Seed library',
					'website'       => 'https://seeds.example.org',
					'fe_kind'       => 'tool',
					'contact_email' => 'owner@example.org',
					'consent'       => true,
				),
			)
		);

		$html = $this->render( array( 'entry' => (string) $id ) );
		$this->assertStringContainsString( 'value="create_post"', $html );
		$this->assertStringContainsString( 'Creates a Draft Project', $html );

		$location = $this->post(
			array(
				'op'           => 'create_post',
				'entry'        => array( $id ),
				'return_entry' => (string) $id,
			)
		);
		$this->assertStringContainsString( 'entry=' . $id, $location );

		$entry = Entries::get( $id );
		$this->assertSame( 'received', $entry['status'] );
		$post = get_post( $entry['post_id'] );
		$this->assertSame( 'fe_project', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Seed library', $post->post_title );
		$this->assertSame( 'https://seeds.example.org', pegwork_get_field( $post, 'website' ) );
		$this->assertSame( array( 'tool' ), wp_get_object_terms( $post->ID, 'fe_kind', array( 'fields' => 'slugs' ) ) );
		$this->assertSame( 'unconfirmed', $entry['meta']['post_created']['from'] );
		$this->assertStringContainsString( 'Draft &quot;Seed library&quot; created from the entry.', $this->render( array( 'entry' => (string) $id ) ) );

		$again = EntryActions::create_post( $id );
		$this->assertWPError( $again );
		$this->assertSame( 'pegwork_entry_not_postable', $again->get_error_code() );
	}

	/**
	 * A value the type rejects is left out so the admin still gets a draft; spam entries are not postable.
	 */
	public function test_create_post_skips_invalid_values_and_refuses_spam() {
		$id = $this->entry(
			array(
				'form_key' => 'fe_project',
				'values'   => array(
					'title'   => 'Rain gauge',
					'fe_kind' => 'not-a-term',
				),
			)
		);

		$post_id = EntryActions::create_post( $id );
		$this->assertIsInt( $post_id );
		$this->assertSame( 'Rain gauge', get_the_title( $post_id ) );
		$this->assertSame( array(), wp_get_object_terms( $post_id, 'fe_kind', array( 'fields' => 'slugs' ) ) );

		$spam = $this->entry(
			array(
				'form_key' => 'fe_project',
				'status'   => 'spam',
			)
		);
		$this->assertSame( 'pegwork_entry_not_postable', EntryActions::create_post( $spam )->get_error_code() );
		$this->assertSame( 'spam', Entries::get( $spam )['status'] );
	}

	/**
	 * Entry-only forms (Share a story) get a draft type from `pegwork_forms_draft_type`.
	 */
	public function test_create_post_for_entry_only_form_through_filter() {
		$id = $this->entry(
			array(
				'form_key' => 'fe_story',
				'values'   => array(
					'title'   => 'We fixed the bus timetable',
					'story'   => 'A volunteer group mapped every stop.',
					'email'   => 'story@example.org',
					'consent' => true,
				),
			)
		);
		$this->assertSame( 'pegwork_entry_not_postable', EntryActions::create_post( $id )->get_error_code() );

		add_filter( 'pegwork_forms_draft_type', fn( $type, $form ) => 'fe_story' === $form['key'] ? 'fe_story' : $type, 10, 2 );
		$post_id = EntryActions::create_post( $id );
		$this->assertIsInt( $post_id );
		$this->assertSame( 'fe_story', get_post_type( $post_id ) );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'A volunteer group mapped every stop.', pegwork_get_field( $post_id, 'story' ) );
		$this->assertSame( $post_id, Entries::get( $id )['post_id'] );
	}

	/**
	 * A claimed entry (processing) cannot be posted twice at once.
	 */
	public function test_create_post_respects_a_concurrent_claim() {
		$id = $this->entry(
			array(
				'form_key' => 'fe_project',
				'status'   => 'unconfirmed',
				'values'   => array( 'title' => 'Twice' ),
			)
		);
		add_action(
			'pegwork_event_record_created',
			function () use ( $id ) {
				$this->assertSame( 'processing', Entries::get( $id )['status'], 'Claimed while the post is created.' );
			}
		);
		$this->assertIsInt( EntryActions::create_post( $id ) );
		$this->assertSame( 0, Entries::change_status( array( $id ), array( 'unconfirmed' ), 'processing' ), 'A confirm claim now finds nothing.' );
	}

	/**
	 * Users who can manage entries but not create posts of the type are refused.
	 */
	public function test_create_post_needs_create_posts() {
		$id = $this->entry(
			array(
				'form_key' => 'fe_project',
				'values'   => array( 'title' => 'Nope' ),
			)
		);
		add_filter( 'pegwork_forms_capability', fn() => 'read' );
		$this->login_as( 'subscriber' );

		$this->assertSame( 'pegwork_entry_cannot_create', EntryActions::create_post( $id )->get_error_code() );
		$this->assertNull( Entries::get( $id )['post_id'] );
		$this->assertSame( 'received', Entries::get( $id )['status'] );
	}
}
