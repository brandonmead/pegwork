<?php
/**
 * Integration tests: Registry::meta_key() and pegwork_get_field().
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Meta;
use Pegwork\Plugin;
use WP_UnitTestCase;

/**
 * Typed values, meta keys, and the private-field rule (null for every role in view context).
 */
class FieldValueTest extends WP_UnitTestCase {

	/**
	 * Registers examples/sifg and the complete fixture.
	 */
	public function set_up() {
		parent::set_up();
		$base = dirname( __DIR__ );
		add_action(
			'pegwork_register',
			fn( RegistryBuilder $b ) => $b->add_package( dirname( $base ) . '/examples/sifg' )->add_package( $base . '/fixtures/valid/complete' )
		);
		Plugin::init();
	}

	/**
	 * Rebuilds the registry without the test packages.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Default key, wp.meta_key override, post column storage and unknown fields; agrees with Meta::key().
	 */
	public function test_meta_key() {
		$registry = pegwork_registry();

		$this->assertSame( '_sifg_project_who_for', $registry->meta_key( 'sifg_project', 'who_for' ) );
		$this->assertSame( '_sifg_project_contact_email', $registry->meta_key( 'sifg_project', 'contact_email' ) );
		$this->assertSame( 'event_link', $registry->meta_key( 'demo_event', 'link' ) );
		$this->assertNull( $registry->meta_key( 'sifg_project', 'name' ) );
		$this->assertNull( $registry->meta_key( 'sifg_project', 'description' ) );
		$this->assertNull( $registry->meta_key( 'sifg_project', 'nope' ) );
		$this->assertNull( $registry->meta_key( 'nope', 'who_for' ) );

		foreach ( $registry->fields( 'demo_event' ) as $key => $field ) {
			if ( Meta::is_meta( $field ) ) {
				$this->assertSame( Meta::key( 'demo_event', $field ), $registry->meta_key( 'demo_event', $key ) );
			}
		}
	}

	/**
	 * Values come back typed, with registered defaults when unset.
	 */
	public function test_typed_values_and_defaults() {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'demo_event',
				'post_title'   => 'Launch',
				'post_content' => '<p>Body</p>',
			)
		);

		$this->assertSame( 'Launch', pegwork_get_field( $id, 'title' ) );
		$this->assertSame( '<p>Body</p>', pegwork_get_field( get_post( $id ), 'body' ) );
		$this->assertTrue( pegwork_get_field( $id, 'free' ) );
		$this->assertSame( 25, pegwork_get_field( $id, 'capacity' ) );
		$this->assertSame( '2026-01-01', pegwork_get_field( $id, 'starts_on' ) );
		$this->assertSame( 'online', pegwork_get_field( $id, 'format' ) );
		$this->assertSame( 'https://example.org', pegwork_get_field( $id, 'link' ) );
		$this->assertSame( '', pegwork_get_field( $id, 'summary' ) );

		update_post_meta( $id, '_demo_event_free', false );
		update_post_meta( $id, '_demo_event_capacity', '12.5' );
		$this->assertFalse( pegwork_get_field( $id, 'free' ) );
		$this->assertSame( 12.5, pegwork_get_field( $id, 'capacity' ) );

		$project = self::factory()->post->create( array( 'post_type' => 'sifg_project' ) );
		$this->assertFalse( pegwork_get_field( $project, 'needs_volunteers' ) );
		$this->assertSame( '', pegwork_get_field( $project, 'last_verified' ) );

		$this->assertNull( pegwork_get_field( $id, 'nope' ) );
		$this->assertNull( pegwork_get_field( 999999, 'title' ) );
		$this->assertNull( pegwork_get_field( self::factory()->post->create(), 'title' ) );
	}

	/**
	 * Private fields: null in view context for every role; in edit context only for users who can edit the post.
	 */
	public function test_private_field_refused_in_view_context() {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$project = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_author' => $author,
			)
		);
		update_post_meta( $project, '_sifg_project_contact_email', 'owner@example.org' );

		$roles = array(
			'administrator' => true,
			'editor'        => true,
			'subscriber'    => false,
			'anonymous'     => false,
		);
		foreach ( $roles as $role => $can_edit ) {
			wp_set_current_user( 'anonymous' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertNull( pegwork_get_field( $project, 'contact_email' ), $role );
			$this->assertSame( $can_edit ? 'owner@example.org' : null, pegwork_get_field( $project, 'contact_email', 'edit' ), $role );
		}
	}
}
