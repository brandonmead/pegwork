<?php
/**
 * Integration tests: automation definition to builder blocks to definition.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Registry\Normalizer;
use PegworkAutomation\Editor\AutomationPost;
use PegworkAutomation\Editor\Builder;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';

/**
 * Every valid automation fixture survives Builder::generate() then Builder::compile() byte-identical
 * after normalization, the generated markup is stable, and compile() reports misplaced blocks by
 * JSON pointer.
 */
class AutomationBuilderRoundTripTest extends WP_UnitTestCase {

	/**
	 * Every valid automation definition in the repository: fixtures, e2e packages and examples.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function valid_automations() {
		$root  = dirname( __DIR__, 3 );
		$files = array_merge(
			glob( $root . '/tests/fixtures/valid/*/automations/*.json' ),
			glob( $root . '/tests/fixtures/forms-records/automations/*.json' ),
			glob( $root . '/tests/e2e/packages/*/automations/*.json' ),
			glob( $root . '/examples/*/automations/*.json' )
		);
		$cases = array();
		foreach ( $files as $file ) {
			$cases[ substr( $file, strlen( $root ) + 1 ) ] = array( $file );
		}
		return $cases;
	}

	/**
	 * Pretty JSON for byte comparison.
	 *
	 * @param array $value Value.
	 */
	private static function json( array $value ) {
		return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The fixture list is not empty (a moved directory would silently test nothing), and it includes
	 * the branching fixtures.
	 */
	public function test_fixtures_found() {
		$cases = self::valid_automations();
		$this->assertGreaterThanOrEqual( 27, count( $cases ) );
		$this->assertArrayHasKey( 'tests/fixtures/valid/branching/automations/br_deep.json', $cases );
		$this->assertArrayHasKey( 'examples/sifg/automations/sifg_staleness_check.json', $cases );
	}

	/**
	 * Definition to blocks to definition is lossless, for the canonical form and as written.
	 *
	 * @dataProvider valid_automations
	 *
	 * @param string $file Fixture path.
	 */
	public function test_round_trip( $file ) {
		$written   = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$canonical = Normalizer::normalize( 'automation', $written );

		$cases = array(
			'canonical' => $canonical,
			'written'   => $written,
		);
		foreach ( $cases as $label => $definition ) {
			$generated = Builder::generate( $definition );
			$compiled  = Builder::compile( $generated['title'], $generated['content'], AutomationPost::settings_json( $generated['settings'] ) );
			$this->assertSame( array(), $compiled['errors'], $label );
			$this->assertSame( self::json( $canonical ), self::json( Normalizer::normalize( 'automation', $compiled['definition'] ) ), $label );
		}

		// The markup itself is stable: generating from the compiled definition gives the same blocks.
		$generated = Builder::generate( $canonical );
		$compiled  = Builder::compile( $generated['title'], $generated['content'], $generated['settings'] );
		$this->assertSame( $generated['content'], Builder::generate( Normalizer::normalize( 'automation', $compiled['definition'] ) )['content'] );
	}

	/**
	 * The generated tree: decisions hold branches, the otherwise branch is last and flagged, an empty
	 * otherwise is omitted, and settings keep everything but format, label and steps.
	 */
	public function test_generated_shape() {
		$definition = array(
			'format'  => 1,
			'key'     => 'shape',
			'label'   => 'Shape',
			'enabled' => false,
			'trigger' => array(
				'type'  => 'event',
				'event' => 'pegwork/custom',
			),
			'ui'      => array( 'note' => 'kept' ),
			'steps'   => array(
				array(
					'id'        => 'route',
					'type'      => 'decision',
					'branches'  => array(
						array(
							'label' => 'Big',
							'when'  => array(
								'var'   => 'trigger.amount',
								'op'    => 'gt',
								'value' => 10,
							),
							'steps' => array(),
						),
					),
					'otherwise' => array(
						array(
							'id'   => 'pause',
							'type' => 'wait',
							'for'  => 'P1D',
						),
					),
				),
				array(
					'id'        => 'second',
					'type'      => 'decision',
					'branches'  => array(
						array(
							'label' => 'Any',
							'when'  => array(
								'var'   => 'trigger.amount',
								'op'    => 'gt',
								'value' => 0,
							),
							'steps' => array(),
						),
					),
					'otherwise' => array(),
				),
			),
		);

		$generated = Builder::generate( $definition );
		$this->assertSame( 'Shape', $generated['title'] );
		$this->assertSame(
			array(
				'key'     => 'shape',
				'enabled' => false,
				'trigger' => $definition['trigger'],
				'ui'      => array( 'note' => 'kept' ),
			),
			$generated['settings']
		);

		$blocks = array_values( array_filter( parse_blocks( $generated['content'] ), fn( $block ) => null !== $block['blockName'] ) );
		$this->assertSame( array( Builder::DECISION, Builder::DECISION ), array_column( $blocks, 'blockName' ) );
		$this->assertSame( array( 'id' => 'route' ), $blocks[0]['attrs'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
		$this->assertSame( 'Big', $blocks[0]['innerBlocks'][0]['attrs']['label'] );
		$this->assertSame( array(), $blocks[0]['innerBlocks'][0]['innerBlocks'] );
		$this->assertSame( Builder::OTHERWISE, $blocks[0]['innerBlocks'][1]['attrs'] );
		$this->assertSame(
			array(
				'otherwise' => true,
				'lock'      => array(
					'move'   => true,
					'remove' => true,
				),
			),
			$blocks[0]['innerBlocks'][1]['attrs'],
			'The otherwise branch is flagged and locked in place.'
		);
		$this->assertSame( Builder::STEP, $blocks[0]['innerBlocks'][1]['innerBlocks'][0]['blockName'] );
		$this->assertCount( 1, $blocks[1]['innerBlocks'], 'An empty otherwise is omitted.' );

		$compiled = Builder::compile( $generated['title'], $generated['content'], $generated['settings'] );
		$this->assertSame( array(), $compiled['errors'] );
		$this->assertArrayNotHasKey( 'otherwise', $compiled['definition']['steps'][1] );
		$this->assertSame( array( 'note' => 'kept' ), $compiled['definition']['ui'] );
	}

	/**
	 * Misplaced blocks, a missing title and unreadable settings are reported by pointer.
	 */
	public function test_compile_errors_by_pointer() {
		$step     = '<!-- wp:pegwork/automation-step {"id":"a","type":"wait","for":"P1D"} /-->';
		$branch   = '<!-- wp:pegwork/automation-branch {"label":"X","when":{"var":"trigger.a","op":"eq","value":1}} -->' . "\n" . $step . "\n" . '<!-- /wp:pegwork/automation-branch -->';
		$other    = '<!-- wp:pegwork/automation-branch {"otherwise":true} -->' . "\n" . $step . "\n" . '<!-- /wp:pegwork/automation-branch -->';
		$decision = static fn( $inner ) => '<!-- wp:pegwork/automation-decision {"id":"d"} -->' . "\n" . $inner . "\n" . '<!-- /wp:pegwork/automation-decision -->';

		$result = Builder::compile( '', '', '[1]' );
		$this->assertArrayHasKey( '/', $result['errors'] );
		$this->assertArrayHasKey( '/label', $result['errors'] );
		$this->assertArrayHasKey( '/key', $result['errors'] );
		$this->assertArrayHasKey( '/steps', $result['errors'] );

		$result = Builder::compile( 'T', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' . "\n\n" . $step, '{}' );
		$this->assertSame( array( '/steps' ), array_keys( $result['errors'] ) );
		$this->assertSame( 'a', $result['definition']['steps'][0]['id'], 'Indexes count only steps.' );

		$result = Builder::compile( 'T', $branch, '{}' );
		$this->assertSame( array( '/steps' ), array_keys( $result['errors'] ), 'A branch outside a decision.' );

		$result = Builder::compile( 'T', $step . "\n\n" . $decision( $step ), '{}' );
		$this->assertSame( array( '/steps/1/branches' ), array_keys( $result['errors'] ), 'A step directly in a decision.' );

		$result = Builder::compile( 'T', $decision( $other . "\n\n" . $branch ), '{}' );
		$this->assertSame( array( '/steps/0/otherwise' ), array_keys( $result['errors'] ), 'Otherwise not last.' );

		$result = Builder::compile( 'T', $decision( $branch . "\n\n" . $other . "\n\n" . $other ), '{}' );
		$this->assertSame( array( '/steps/0/otherwise' ), array_keys( $result['errors'] ), 'Two otherwise branches.' );

		$nested = Builder::compile( 'T', $decision( str_replace( $step, $branch, $branch ) ), '{}' );
		$this->assertSame( array( '/steps/0/branches/0/steps' ), array_keys( $nested['errors'] ), 'A branch inside a branch.' );

		$keyed = Builder::compile( 'Été 2027!', $step, '{"key":"","format":9,"steps":[]}' );
		$this->assertSame( 'ete_2027', $keyed['definition']['key'] );
		$this->assertSame( 1, $keyed['definition']['format'] );
		$this->assertSame( 'a_2027', Builder::slug( '2027' ) );
		$this->assertSame( 'locked', Builder::compile( 'T', $step, '{"key":"other"}', 'locked' )['definition']['key'] );
	}
}
