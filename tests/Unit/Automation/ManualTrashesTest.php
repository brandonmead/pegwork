<?php
/**
 * Unit tests: whether an automation, or one it starts, can move records to the trash.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Automation;

use Pegwork\Registry\Loader;
use PegworkAutomation\Manual;
use WP_UnitTestCase;

/**
 * Manual::trashes() (docs/automation-v4-design.md sections 3.3 and 6): a trash step at any depth, and the
 * closure through start_automation chains, with cycles and unknown keys ending the walk.
 */
class ManualTrashesTest extends WP_UnitTestCase {

	/**
	 * Loads the module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';
	}

	/**
	 * An automation with steps.
	 *
	 * @param array<int, array<string, mixed>> $steps Steps.
	 * @param array<int, array<string, mixed>> $links Links.
	 * @return array<string, mixed>
	 */
	private static function automation( array $steps, array $links = array() ) {
		$definition = array(
			'trigger' => array( 'type' => 'manual' ),
			'steps'   => $steps,
		);
		if ( array() !== $links ) {
			$definition['links'] = $links;
		}
		return $definition;
	}

	/**
	 * A start step.
	 *
	 * @param string $id     Step ID.
	 * @param string $target Automation it starts.
	 * @return array<string, mixed>
	 */
	private static function start( $id, $target ) {
		return array(
			'id'         => $id,
			'type'       => 'start_automation',
			'automation' => $target,
		);
	}

	/**
	 * A trash step.
	 *
	 * @return array<string, mixed>
	 */
	private static function trash() {
		return array(
			'id'           => 'remove',
			'type'         => 'trash_record',
			'content_type' => 'barn_volunteer',
		);
	}

	/**
	 * The v4 fixture's canonical automations: a trash in a loop body and in a branch is found; the receipt
	 * chain trashes nothing.
	 */
	public function test_v4_fixture() {
		$automations = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/v4' ) )->registry->automations();
		$this->assertTrue( Manual::trashes( 'barn_volunteer_retention', $automations ), 'In a loop body.' );
		$this->assertTrue( Manual::trashes( 'barn_v4_shapes', $automations ), 'In a branch and at the top.' );
		$this->assertFalse( Manual::trashes( 'barn_gift_receipt', $automations ) );
		$this->assertFalse( Manual::trashes( 'barn_gift_receipt_start', $automations ), 'It starts one that trashes nothing.' );
		$this->assertFalse( Manual::trashes( 'barn_no_such_key', $automations ) );
	}

	/**
	 * Through start chains: A starts B starts C, and C trashes in an Otherwise branch, so A and B trash too;
	 * a cycle without a trash ends; a start of an unknown key is not a trash; a trash in a link's steps
	 * counts.
	 */
	public function test_closure_through_starts() {
		$automations = array(
			'a'      => self::automation( array( self::start( 'to_b', 'b' ) ) ),
			'b'      => self::automation(
				array(
					array(
						'id'   => 'note',
						'type' => 'condition',
					),
					self::start( 'to_c', 'c' ),
				)
			),
			'c'      => self::automation(
				array(
					array(
						'id'        => 'pick',
						'type'      => 'decision',
						'branches'  => array(
							array(
								'label' => 'Keep',
								'steps' => array(),
							),
						),
						'otherwise' => array( self::trash() ),
					),
				)
			),
			'x'      => self::automation( array( self::start( 'to_y', 'y' ) ) ),
			'y'      => self::automation( array( self::start( 'to_x', 'x' ), self::start( 'to_self', 'y' ) ) ),
			'lost'   => self::automation( array( self::start( 'to_gone', 'gone' ) ) ),
			'linked' => self::automation(
				array(),
				array(
					array(
						'name'  => 'remove',
						'steps' => array( self::trash() ),
					),
				)
			),
		);

		$this->assertTrue( Manual::trashes( 'a', $automations ) );
		$this->assertTrue( Manual::trashes( 'b', $automations ) );
		$this->assertTrue( Manual::trashes( 'c', $automations ) );
		$this->assertFalse( Manual::trashes( 'x', $automations ), 'A cycle without a trash ends.' );
		$this->assertFalse( Manual::trashes( 'lost', $automations ) );
		$this->assertTrue( Manual::trashes( 'linked', $automations ) );

		$automations['y']['steps'][] = self::start( 'to_a', 'a' );
		$this->assertTrue( Manual::trashes( 'x', $automations ), 'Through the cycle to a chain that trashes.' );
	}
}
