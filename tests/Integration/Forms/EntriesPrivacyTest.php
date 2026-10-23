<?php
/**
 * Integration tests: personal data exporter and eraser, retention cleanup.
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The tests read core's privacy filters.

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\EntriesTestCase;
use PegworkForms\Cleanup;
use PegworkForms\Entries;
use PegworkForms\EntryState;
use PegworkForms\Privacy;

require_once dirname( __DIR__, 2 ) . '/Support/EntriesTestCase.php';

/**
 * Tools > Export and Erase Personal Data find entries by email; the daily cleanup deletes by age with a fixed clock.
 */
class EntriesPrivacyTest extends EntriesTestCase {

	/**
	 * Exporter and eraser are registered with WordPress.
	 */
	public function test_exporter_and_eraser_are_registered() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertSame( array( Privacy::class, 'export' ), $exporters[ Privacy::ID ]['callback'] );
		$this->assertSame( 'Pegwork form entries', $exporters[ Privacy::ID ]['exporter_friendly_name'] );
		$this->assertSame( array( Privacy::class, 'erase' ), $erasers[ Privacy::ID ]['callback'] );
	}

	/**
	 * The exporter returns each entry of the address (any case) with its form, status and labeled values.
	 */
	public function test_export_finds_entries_by_email() {
		$id = $this->entry(
			array(
				'values' => array(
					'name'  => 'Ada',
					'topic' => 'talks',
				),
			)
		);
		$this->entry( array( 'email' => 'someone@example.org' ) );

		$result = Privacy::export( 'ADA@example.org' );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$item = $result['data'][0];
		$this->assertSame( 'pegwork-form-entries', $item['group_id'] );
		$this->assertSame( 'pegwork-entry-' . $id, $item['item_id'] );
		$pairs = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'Contact us', $pairs['Form'] );
		$this->assertSame( (string) $id, $pairs['Entry ID'] );
		$this->assertSame( 'Received', $pairs['Status'] );
		$this->assertSame( 'Ada', $pairs['Name'] );
		$this->assertSame( 'Talks', $pairs['Topic'] );

		$this->assertSame( array(), Privacy::export( 'nobody@example.org' )['data'] );
	}

	/**
	 * Security review I-10: entries a logged-in user sent on a form without an email item are found by the
	 * account's address, for export and erasure.
	 */
	public function test_export_and_erase_find_a_users_entries_without_email() {
		$user = self::factory()->user->create( array( 'user_email' => 'member@example.org' ) );
		$id   = $this->entry(
			array(
				'email'   => '',
				'user_id' => $user,
			)
		);
		$this->entry(
			array(
				'email'   => '',
				'user_id' => 0,
			)
		);

		$this->assertSame( array( 'pegwork-entry-' . $id ), array_column( Privacy::export( 'Member@example.org' )['data'], 'item_id' ) );
		$this->assertTrue( Privacy::erase( 'member@example.org' )['items_removed'] );
		$this->assertNull( \PegworkForms\Entries::get( $id ) );
		$this->assertSame( array(), Privacy::export( 'nobody@example.org' )['data'], 'Anonymous entries never match.' );
	}

	/**
	 * The exporter pages by PAGE_SIZE.
	 */
	public function test_export_pages() {
		for ( $i = 0; $i < Privacy::PAGE_SIZE + 1; $i++ ) {
			$this->entry();
		}
		$first = Privacy::export( 'ada@example.org', 1 );
		$this->assertCount( Privacy::PAGE_SIZE, $first['data'] );
		$this->assertFalse( $first['done'] );

		$second = Privacy::export( 'ada@example.org', 2 );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	/**
	 * The eraser deletes the address's entries, keeps others, and reports linked posts as retained.
	 */
	public function test_erase_deletes_entries_and_reports_posts_kept() {
		$plain  = $this->entry();
		$linked = $this->entry( array( 'form_key' => 'fe_project' ) );
		$post   = self::factory()->post->create( array( 'post_title' => 'Seed library' ) );
		EntryState::set( $linked, array( 'post_id' => $post ) );
		$other = $this->entry( array( 'email' => 'someone@example.org' ) );

		$result = Privacy::erase( 'ada@example.org' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( '"Seed library" (ID ' . $post . '), was kept', $result['messages'][0] );

		$this->assertNull( Entries::get( $plain ) );
		$this->assertNull( Entries::get( $linked ) );
		$this->assertNotNull( Entries::get( $other ) );
		$this->assertNotNull( get_post( $post ), 'The record stays.' );

		$again = Privacy::erase( 'ada@example.org' );
		$this->assertFalse( $again['items_removed'] );
		$this->assertTrue( $again['done'] );
	}

	/**
	 * Cleanup at a fixed time: spam after 30 days, unconfirmed after 7, received and failed after the
	 * form's retention_days only; forms without retention_days keep received entries.
	 */
	public function test_cleanup_deletes_by_age_and_status() {
		$now = strtotime( '2026-10-18 12:00:00 UTC' );
		$at  = static fn( $days ) => gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );

		$spam_old         = $this->entry( array( 'status' => 'spam', 'created_at' => $at( 31 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$spam_new         = $this->entry( array( 'status' => 'spam', 'created_at' => $at( 29 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$unconfirmed_old  = $this->entry( array( 'status' => 'unconfirmed', 'created_at' => $at( 8 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$unconfirmed_new  = $this->entry( array( 'status' => 'unconfirmed', 'created_at' => $at( 6 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$contact_ancient  = $this->entry( array( 'status' => 'received', 'created_at' => $at( 4000 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$story_old        = $this->entry( array( 'form_key' => 'fe_story', 'created_at' => $at( 31 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$story_failed_old = $this->entry( array( 'form_key' => 'fe_story', 'status' => 'failed', 'created_at' => $at( 31 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$story_new        = $this->entry( array( 'form_key' => 'fe_story', 'created_at' => $at( 29 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.
		$story_processing = $this->entry( array( 'form_key' => 'fe_story', 'status' => 'processing', 'created_at' => $at( 31 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Test table.

		$this->assertSame( 4, Cleanup::run( $now ) );

		foreach ( array( $spam_old, $unconfirmed_old, $story_old, $story_failed_old ) as $id ) {
			$this->assertNull( Entries::get( $id ), "Entry {$id} deleted." );
		}
		foreach ( array( $spam_new, $unconfirmed_new, $contact_ancient, $story_new, $story_processing ) as $id ) {
			$this->assertNotNull( Entries::get( $id ), "Entry {$id} kept." );
		}
		$this->assertSame( 0, Cleanup::run( $now ), 'Idempotent.' );
	}

	/**
	 * The `pegwork_forms_retention_days` filter changes or disables the status limits.
	 */
	public function test_cleanup_status_days_filter() {
		$now  = strtotime( '2026-10-18 12:00:00 UTC' );
		$spam = $this->entry(
			array(
				'status'     => 'spam',
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS ),
			)
		);
		$held = $this->entry(
			array(
				'status'     => 'unconfirmed',
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ),
			)
		);
		add_filter(
			'pegwork_forms_retention_days',
			fn() => array(
				'spam'        => 2,
				'unconfirmed' => 0,
			)
		);

		$this->assertSame( array( 'spam' => 2 ), Cleanup::status_days() );
		$this->assertSame( 1, Cleanup::run( $now ) );
		$this->assertNull( Entries::get( $spam ) );
		$this->assertNotNull( Entries::get( $held ) );
	}

	/**
	 * The module schedules the daily job once.
	 */
	public function test_cleanup_is_scheduled_daily() {
		wp_clear_scheduled_hook( Cleanup::HOOK );
		Cleanup::register();
		Cleanup::register();

		$event = wp_get_scheduled_event( Cleanup::HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 'daily', $event->schedule );
		$this->assertTrue( has_action( Cleanup::HOOK, array( Cleanup::class, 'cron' ) ) > 0 );
		wp_clear_scheduled_hook( Cleanup::HOOK );
	}
}
