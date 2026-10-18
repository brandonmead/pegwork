<?php
/**
 * Unit tests for the public Registry API.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Api;

use Pegwork\Api\Registry;
use Pegwork\Registry\Loader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Pegwork\Api\Registry.
 */
class RegistryTest extends TestCase {

	/**
	 * Registry built from the complete fixture.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Loads the fixture.
	 */
	protected function set_up() {
		parent::set_up();
		$this->registry = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/complete' ) )->registry;
	}

	/**
	 * Collections are keyed by key, in load order.
	 */
	public function test_collections() {
		$this->assertSame( array( 'demo_event', 'demo_venue' ), array_keys( $this->registry->content_types() ) );
		$this->assertSame( array( 'demo_region', 'demo_topic' ), array_keys( $this->registry->taxonomies() ) );
		$this->assertSame( array( 'event_related', 'event_venue' ), array_keys( $this->registry->relationships() ) );
	}

	/**
	 * Relationships for a type include both ends.
	 */
	public function test_relationships_for() {
		$this->assertSame( array( 'event_related', 'event_venue' ), array_keys( $this->registry->relationships_for( 'demo_event' ) ) );
		$this->assertSame( array( 'event_related', 'event_venue' ), array_keys( $this->registry->relationships_for( 'demo_venue' ) ) );
		$this->assertSame( array(), $this->registry->relationships_for( 'missing' ) );
		$this->assertSame( 'Related venues', $this->registry->relationships()['event_related']['label'] );
		$this->assertTrue( $this->registry->relationships()['event_related']['required'] );
		$this->assertFalse( $this->registry->relationships()['event_venue']['required'] );
	}

	/**
	 * One content type, or null.
	 */
	public function test_content_type() {
		$this->assertSame( 'Events', $this->registry->content_type( 'demo_event' )['labels']['plural'] );
		$this->assertNull( $this->registry->content_type( 'missing' ) );
	}

	/**
	 * Fields keep definition order and filter by visibility.
	 */
	public function test_fields() {
		$all = $this->registry->fields( 'demo_event' );
		$this->assertCount( 11, $all );
		$this->assertSame( 'title', array_key_first( $all ) );
		$this->assertSame( 'venue', array_key_last( $all ) );

		$this->assertSame( array( 'organizer_email' ), array_keys( $this->registry->fields( 'demo_event', 'private' ) ) );
		$this->assertCount( 10, $this->registry->fields( 'demo_event', 'public' ) );
		$this->assertSame( array(), $this->registry->fields( 'missing' ) );
		$this->assertSame( array(), $this->registry->fields( 'demo_venue' ) );
	}

	/**
	 * Returned arrays are copies: changing them does not change the registry.
	 */
	public function test_read_only() {
		$types                         = $this->registry->content_types();
		$types['demo_event']['labels'] = array();
		unset( $types['demo_venue'] );

		$this->assertSame( 'Events', $this->registry->content_type( 'demo_event' )['labels']['plural'] );
		$this->assertCount( 2, $this->registry->content_types() );
	}

	/**
	 * An empty registry answers every method.
	 */
	public function test_empty_registry() {
		$empty = new Registry();

		$this->assertSame( array(), $empty->content_types() );
		$this->assertNull( $empty->content_type( 'x' ) );
		$this->assertSame( array(), $empty->fields( 'x' ) );
		$this->assertSame( array(), $empty->taxonomies() );
		$this->assertSame( array(), $empty->relationships() );
		$this->assertNull( $empty->listing( 'x' ) );
		$this->assertSame( array(), $empty->forms() );
		$this->assertNull( $empty->form( 'x' ) );
		$this->assertNull( $empty->form_source( 'x' ) );
		$this->assertSame( array(), $empty->automations() );
		$this->assertNull( $empty->automation( 'x' ) );
		$this->assertNull( $empty->automation_source( 'x' ) );
		$this->assertSame( array(), $empty->options_pages() );
		$this->assertNull( $empty->options_page( 'x' ) );
	}

	/**
	 * Listing, forms, automations and options pages; a form's source tells file packages from the DB option.
	 */
	public function test_sections() {
		$db       = array(
			'automations' => array(
				array(
					'format'  => 1,
					'key'     => 'site_digest',
					'label'   => 'Digest',
					'trigger' => array(
						'type'  => 'schedule',
						'every' => 'day',
					),
					'steps'   => array(
						array(
							'id'   => 'pause',
							'type' => 'wait',
							'for'  => 'PT1H',
						),
					),
				),
			),
			'forms'       => array(
				array(
					'format' => 1,
					'key'    => 'site_contact',
					'label'  => 'Contact',
					'fields' => array(
						array(
							'key'   => 'message',
							'type'  => 'textarea',
							'label' => 'Message',
						),
					),
				),
			),
		);
		$registry = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/sections' ), $db )->registry;

		$listing = $registry->listing( 'talk' );
		$this->assertTrue( $listing['search'] );
		$this->assertSame( array( 'track', 'level', 'recorded', 'talk_format' ), array_column( $listing['filters'], 'key' ) );
		$this->assertNull( $this->registry->listing( 'demo_event' ) );
		$this->assertNull( $registry->listing( 'missing' ) );

		$this->assertSame( array( 'contact', 'submit_talk', 'site_contact' ), array_keys( $registry->forms() ) );
		$this->assertSame( 'Submit a talk', $registry->form( 'submit_talk' )['label'] );
		$this->assertSame( 'file:sections', $registry->form_source( 'submit_talk' ) );
		$this->assertSame( 'db', $registry->form_source( 'site_contact' ) );
		$this->assertNull( $registry->form_source( 'missing' ) );

		$this->assertSame( array( 'contact_received', 'talk_followup', 'talk_inbound', 'talk_published', 'talk_updated', 'site_digest' ), array_keys( $registry->automations() ) );
		$this->assertFalse( $registry->automation( 'talk_inbound' )['enabled'] );
		$this->assertNull( $registry->automation( 'missing' ) );
		$this->assertSame( 'file:sections', $registry->automation_source( 'talk_published' ) );
		$this->assertSame( 'db', $registry->automation_source( 'site_digest' ) );
		$this->assertNull( $registry->automation_source( 'missing' ) );

		$this->assertSame( array( 'talk_settings' ), array_keys( $registry->options_pages() ) );
		$this->assertSame( 'edit_others_posts', $registry->options_page( 'talk_settings' )['capability'] );
		$this->assertNull( $registry->options_page( 'missing' ) );
	}
}
