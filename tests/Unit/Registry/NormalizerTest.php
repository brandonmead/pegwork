<?php
/**
 * Unit tests for the normalizer.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
use Pegwork\Registry\Normalizer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Normalizer.
 */
class NormalizerTest extends TestCase {

	/**
	 * Defaults are filled for content types, fields and taxonomy usage; empty objects dropped.
	 */
	public function test_content_type_defaults() {
		$normalized = Normalizer::normalize(
			'content_type',
			array(
				'key'        => 'thing',
				'format'     => 1,
				'labels'     => array(
					'singular' => 'Thing',
					'plural'   => 'Things',
				),
				'taxonomies' => array( array( 'key' => 'topic' ) ),
				'fields'     => array(
					array(
						'type'     => 'text',
						'key'      => 'a',
						'label'    => 'A',
						'validate' => array(),
						'wp'       => array(),
					),
				),
				'wp'         => array(),
			)
		);

		$this->assertSame(
			array(
				'fields'     => array(
					array(
						'key'        => 'a',
						'label'      => 'A',
						'required'   => false,
						'type'       => 'text',
						'visibility' => 'public',
					),
				),
				'format'     => 1,
				'key'        => 'thing',
				'labels'     => array(
					'plural'   => 'Things',
					'singular' => 'Thing',
				),
				'public'     => true,
				'taxonomies' => array(
					array(
						'key'      => 'topic',
						'required' => false,
						'select'   => 'multi',
					),
				),
			),
			$normalized
		);
	}

	/**
	 * Taxonomy and relationship defaults.
	 */
	public function test_taxonomy_and_relationship_defaults() {
		$taxonomy = Normalizer::normalize( 'taxonomy', array( 'key' => 'topic' ) );
		$this->assertSame(
			array(
				'hierarchical' => false,
				'key'          => 'topic',
				'locked_terms' => false,
				'terms'        => array(),
			),
			$taxonomy
		);

		$relationship = Normalizer::normalize( 'relationship', array( 'key' => 'rel' ) );
		$this->assertSame(
			array(
				'key'      => 'rel',
				'required' => false,
				'storage'  => 'meta',
			),
			$relationship
		);
		$this->assertArrayNotHasKey( 'label', $relationship, 'The label default is resolved at runtime, not stored.' );
	}

	/**
	 * Listing defaults: search off, no filters; `multiple` follows the taxonomy's select, false for fields.
	 */
	public function test_listing_defaults() {
		$type = Normalizer::normalize(
			'content_type',
			array(
				'key'        => 'thing',
				'taxonomies' => array(
					array(
						'key'    => 'topic',
						'select' => 'multi',
					),
					array(
						'key'    => 'level',
						'select' => 'single',
					),
				),
				'listing'    => array(
					'filters' => array(
						array(
							'key'      => 'topic',
							'taxonomy' => 'topic',
						),
						array(
							'key'      => 'level',
							'taxonomy' => 'level',
						),
						array(
							'key'   => 'done',
							'field' => 'done',
						),
					),
				),
			)
		);

		$this->assertFalse( $type['listing']['search'] );
		$this->assertSame( array( true, false, false ), array_column( $type['listing']['filters'], 'multiple' ) );
		$this->assertArrayNotHasKey( 'label', $type['listing']['filters'][0], 'Filter labels are resolved at runtime, not stored.' );

		$this->assertArrayNotHasKey( 'listing', Normalizer::normalize( 'content_type', array( 'key' => 'thing' ) ) );
	}

	/**
	 * Form defaults: open access, create as pending, 72-hour confirmation, unbound fields optional.
	 */
	public function test_form_defaults() {
		$form = Normalizer::normalize(
			'form',
			array(
				'key'      => 'suggest',
				'target'   => array( 'content_type' => 'thing' ),
				'confirm'  => array( 'field' => 'email' ),
				'fields'   => array(
					array( 'bind' => array( 'field' => 'name' ) ),
					array(
						'key'      => 'note',
						'type'     => 'text',
						'label'    => 'Note',
						'validate' => array(),
					),
				),
				'messages' => array(),
			)
		);

		$this->assertSame( 'anyone', $form['access'] );
		$this->assertSame(
			array(
				'action'       => 'create',
				'content_type' => 'thing',
				'status'       => 'pending',
			),
			$form['target']
		);
		$this->assertSame( 72, $form['confirm']['expires_hours'] );
		$this->assertSame( array(), $form['notifications'] );
		$this->assertSame( array( 'bind' => array( 'field' => 'name' ) ), $form['fields'][0], 'Bound items take their defaults from the content type.' );
		$this->assertSame(
			array(
				'key'      => 'note',
				'label'    => 'Note',
				'required' => false,
				'type'     => 'text',
			),
			$form['fields'][1]
		);
		$this->assertArrayNotHasKey( 'messages', $form );

		$update = Normalizer::normalize(
			'form',
			array(
				'key'    => 'edit',
				'target' => array(
					'content_type' => 'thing',
					'action'       => 'update',
				),
			)
		);
		$this->assertArrayNotHasKey( 'status', $update['target'], 'Updates keep the record status.' );
	}

	/**
	 * Automation defaults: enabled; for_each runs at most 200 published records; created records are drafts;
	 * links ask for confirmation. Retry has no stored default.
	 */
	public function test_automation_defaults() {
		$automation = Normalizer::normalize(
			'automation',
			array(
				'key'     => 'follow',
				'trigger' => array(
					'type'     => 'schedule',
					'every'    => 'day',
					'for_each' => array( 'content_type' => 'thing' ),
				),
				'steps'   => array(
					array(
						'id'           => 'copy',
						'type'         => 'create_record',
						'content_type' => 'thing',
						'values'       => array( 'name' => 'x' ),
					),
				),
				'links'   => array(
					'done' => array(
						'expires' => 'P7D',
						'steps'   => array(),
					),
				),
				'ui'      => array(),
			)
		);

		$this->assertTrue( $automation['enabled'] );
		$this->assertSame(
			array(
				'content_type' => 'thing',
				'max_runs'     => 200,
				'status'       => array( 'publish' ),
			),
			$automation['trigger']['for_each']
		);
		$this->assertSame( 'draft', $automation['steps'][0]['status'] );
		$this->assertArrayNotHasKey( 'retry', $automation['steps'][0] );
		$this->assertTrue( $automation['links']['done']['confirm'] );
		$this->assertArrayNotHasKey( 'ui', $automation );

		$this->assertArrayNotHasKey(
			'links',
			Normalizer::normalize(
				'automation',
				array(
					'key'   => 'follow',
					'steps' => array(),
					'links' => array(),
				)
			)
		);
	}

	/**
	 * Decisions normalize recursively: create_record defaults fill inside branches and otherwise, an empty
	 * otherwise is dropped, empty branch lists stay, branch keys are sorted and list order is kept.
	 */
	public function test_decision_defaults() {
		$create   = fn( $id ) => array(
			'type'         => 'create_record',
			'id'           => $id,
			'values'       => array( 'name' => 'x' ),
			'content_type' => 'thing',
		);
		$decision = array(
			'type'      => 'decision',
			'id'        => 'outer',
			'branches'  => array(
				array(
					'when'  => array(
						'var' => 'record.done',
						'op'  => 'not_empty',
					),
					'steps' => array(
						array(
							'type'      => 'decision',
							'id'        => 'inner',
							'branches'  => array(
								array(
									'steps' => array( $create( 'b' ), $create( 'a' ) ),
									'label' => 'Inner',
									'when'  => array(
										'var' => 'record.done',
										'op'  => 'empty',
									),
								),
							),
							'otherwise' => array( $create( 'c' ) ),
						),
					),
					'label' => 'Outer',
				),
				array(
					'label' => 'Empty',
					'when'  => array(
						'var' => 'record.done',
						'op'  => 'empty',
					),
					'steps' => array(),
				),
			),
			'otherwise' => array(),
		);

		$automation = Normalizer::normalize(
			'automation',
			array(
				'key'     => 'follow',
				'trigger' => array( 'type' => 'webhook' ),
				'steps'   => array( $decision ),
			)
		);
		$outer      = $automation['steps'][0];

		$this->assertArrayNotHasKey( 'otherwise', $outer );
		$this->assertSame( array( 'branches', 'id', 'type' ), array_keys( $outer ) );
		$this->assertSame( array( 'label', 'steps', 'when' ), array_keys( $outer['branches'][0] ) );
		$this->assertSame( array(), $outer['branches'][1]['steps'] );
		$inner = $outer['branches'][0]['steps'][0];
		$this->assertSame( array( 'b', 'a' ), array_column( $inner['branches'][0]['steps'], 'id' ) );
		$this->assertSame( array( 'draft', 'draft' ), array_column( $inner['branches'][0]['steps'], 'status' ) );
		$this->assertSame( 'draft', $inner['otherwise'][0]['status'] );
		$this->assertSame( wp_json_encode( $automation ), wp_json_encode( Normalizer::normalize( 'automation', $automation ) ) );
	}

	/**
	 * Options page defaults: manage_options; fields get the content type field defaults; menu_title is not stored.
	 */
	public function test_options_page_defaults() {
		$this->assertSame(
			array(
				'capability' => 'manage_options',
				'fields'     => array(
					array(
						'key'        => 'tagline',
						'label'      => 'Tagline',
						'required'   => false,
						'type'       => 'text',
						'visibility' => 'public',
					),
				),
				'key'        => 'settings',
				'title'      => 'Settings',
			),
			Normalizer::normalize(
				'options_page',
				array(
					'key'    => 'settings',
					'title'  => 'Settings',
					'fields' => array(
						array(
							'key'   => 'tagline',
							'type'  => 'text',
							'label' => 'Tagline',
						),
					),
				)
			)
		);
	}

	/**
	 * Object keys are sorted at every depth and list order is kept.
	 */
	public function test_sorts_object_keys_and_keeps_list_order() {
		$normalized = Normalizer::normalize(
			'taxonomy',
			array(
				'terms' => array(
					array(
						'slug' => 'zeta',
						'name' => 'Zeta',
					),
					array(
						'slug' => 'alpha',
						'name' => 'Alpha',
					),
				),
				'key'   => 'topic',
			)
		);

		$this->assertSame( array( 'hierarchical', 'key', 'locked_terms', 'terms' ), array_keys( $normalized ) );
		$this->assertSame( array( 'zeta', 'alpha' ), array_column( $normalized['terms'], 'slug' ) );
		$this->assertSame( array( 'name', 'slug' ), array_keys( $normalized['terms'][0] ) );
	}

	/**
	 * Normalizing the canonical form again changes nothing, byte for byte.
	 */
	public function test_idempotent_on_fixtures() {
		$root   = dirname( __DIR__, 3 );
		$result = Loader::load( array( $root . '/examples/sifg', $root . '/tests/fixtures/valid/complete', $root . '/tests/fixtures/valid/sections', $root . '/tests/fixtures/valid/branching' ) );
		$this->assertTrue( $result->is_valid() );

		foreach ( Loader::SECTIONS as $kind => $section ) {
			$objects = $result->registry->$section();
			$this->assertNotEmpty( $objects );
			foreach ( $objects as $object ) {
				$this->assertSame( wp_json_encode( $object ), wp_json_encode( Normalizer::normalize( $kind, $object ) ) );
			}
		}
	}

	/**
	 * Automation v3 defaults: reads get `status` (sorted) and `sort`, find_records `limit`, relate `action`,
	 * manual triggers `allow`, filled inside loop bodies and branches; the result is idempotent.
	 */
	public function test_reads_defaults() {
		$read = array(
			'id'           => 'r',
			'type'         => 'get_record',
			'content_type' => 'barn_gift',
			'record'       => '{{record.id}}',
		);
		$data = Normalizer::normalize(
			'automation',
			array(
				'format'  => 1,
				'key'     => 'a',
				'label'   => 'A',
				'trigger' => array(
					'type'   => 'manual',
					'button' => 'Run',
				),
				'steps'   => array(
					array(
						'id'           => 'f',
						'type'         => 'find_records',
						'content_type' => 'barn_gift',
						'status'       => array( 'publish', 'draft' ),
						'sort'         => array( 'field' => 'amount' ),
						'filter'       => array(
							'all' => array(
								array(
									'field' => 'amount',
									'op'    => 'not_empty',
								),
							),
						),
					),
					array(
						'id'    => 'l',
						'type'  => 'loop',
						'over'  => 'steps.f.records',
						'steps' => array(
							$read,
							array(
								'id'           => 'link',
								'type'         => 'relate',
								'relationship' => 'gift_donor',
								'to'           => '1',
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'admins', $data['trigger']['allow'] );
		$this->assertSame( array( 'draft', 'publish' ), $data['steps'][0]['status'] );
		$this->assertSame(
			array(
				'field' => 'amount',
				'order' => 'asc',
			),
			$data['steps'][0]['sort']
		);
		$this->assertSame( 10, $data['steps'][0]['limit'] );
		$body = $data['steps'][1]['steps'];
		$this->assertSame( array( 'private', 'publish' ), $body[0]['status'] );
		$this->assertSame(
			array(
				'field' => 'id',
				'order' => 'asc',
			),
			$body[0]['sort']
		);
		$this->assertArrayNotHasKey( 'limit', $body[0] );
		$this->assertSame( 'add', $body[1]['action'] );
		$this->assertSame( wp_json_encode( $data ), wp_json_encode( Normalizer::normalize( 'automation', $data ) ) );

		$result = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/reads' ) );
		$this->assertTrue( $result->is_valid() );
		foreach ( $result->registry->automations() as $object ) {
			$this->assertSame( wp_json_encode( $object ), wp_json_encode( Normalizer::normalize( 'automation', $object ) ) );
		}
	}

	/**
	 * Automation v4 defaults: a date wait gets `until.at` 09:00 and loses a zero offset (any sign or unit), a
	 * non-zero offset and `created` operands stay as written; the result is idempotent, and the v4 fixture too.
	 */
	public function test_v4_defaults() {
		$wait = fn( $until ) => array(
			'id'    => 'w',
			'type'  => 'wait',
			'until' => $until,
		);
		$read = array(
			'id'           => 'old',
			'type'         => 'find_records',
			'content_type' => 'barn_volunteer',
			'created'      => array(
				'before' => array(
					'date'   => 'today',
					'offset' => '-P2Y',
				),
				'after'  => '2020-01-01',
			),
		);
		$data = Normalizer::normalize(
			'automation',
			array(
				'format'  => 1,
				'key'     => 'a',
				'label'   => 'A',
				'trigger' => array(
					'type'         => 'event',
					'event'        => 'record.created',
					'content_type' => 'barn_task',
				),
				'steps'   => array(
					$wait( array( 'var' => 'record.due_on' ) ),
					$wait(
						array(
							'var'    => 'record.due_on',
							'offset' => '-PT0S',
						)
					),
					$wait(
						array(
							'var'    => 'record.due_on',
							'offset' => '+P0Y0D',
							'at'     => '17:00',
						)
					),
					$wait(
						array(
							'var'    => 'record.due_on',
							'offset' => '-P10D',
						)
					),
					$read,
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'at'  => '09:00',
					'var' => 'record.due_on',
				),
				array(
					'at'  => '09:00',
					'var' => 'record.due_on',
				),
				array(
					'at'  => '17:00',
					'var' => 'record.due_on',
				),
				array(
					'at'     => '09:00',
					'offset' => '-P10D',
					'var'    => 'record.due_on',
				),
			),
			array_column( array_slice( $data['steps'], 0, 4 ), 'until' )
		);
		$this->assertSame(
			array(
				'after'  => '2020-01-01',
				'before' => array(
					'date'   => 'today',
					'offset' => '-P2Y',
				),
			),
			$data['steps'][4]['created']
		);
		$this->assertSame( wp_json_encode( $data ), wp_json_encode( Normalizer::normalize( 'automation', $data ) ) );

		$result = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/v4' ) );
		$this->assertTrue( $result->is_valid() );
		foreach ( $result->registry->automations() as $object ) {
			$this->assertSame( wp_json_encode( $object ), wp_json_encode( Normalizer::normalize( 'automation', $object ) ) );
		}
	}
}
