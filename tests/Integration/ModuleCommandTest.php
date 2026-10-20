<?php
/**
 * Integration tests: `wp pegwork module enable|disable` with several slugs.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Cli\ModuleCommand;
use Pegwork\Modules\Loader;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * ModuleCommand::toggle() applies the Loader's rules slug by slug and reports each one.
 */
class ModuleCommandTest extends WP_UnitTestCase {

	/**
	 * Starts with no module enabled.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Loader::OPTION );
	}

	/**
	 * Both commands take one or more slugs (WP-CLI reads the synopsis from the docblock).
	 */
	public function test_synopsis_is_variadic() {
		foreach ( array( 'enable', 'disable' ) as $method ) {
			$doc = ( new ReflectionMethod( ModuleCommand::class, $method ) )->getDocComment();
			$this->assertStringContainsString( '<slug>...', (string) $doc, $method );
		}
	}

	/**
	 * `enable listings filters`: in order, so filters finds listings already enabled.
	 */
	public function test_enable_several_in_order() {
		$results = ModuleCommand::toggle( 'enable', array( 'listings', 'filters' ) );

		$this->assertSame( array( true, true ), array_column( $results, 'ok' ) );
		$this->assertSame( array( 'listings', 'filters' ), array_column( $results, 'slug' ) );
		$this->assertSame( 'Enabled module filters.', $results[1]['message'] );
		$this->assertSame( array( 'listings', 'filters' ), Loader::enabled() );
	}

	/**
	 * One bad slug fails alone: the others still apply, and its result carries the reason.
	 */
	public function test_a_failure_does_not_stop_the_rest() {
		$results = ModuleCommand::toggle( 'enable', array( 'filters', 'nope', 'listings' ) );

		$this->assertSame( array( false, false, true ), array_column( $results, 'ok' ) );
		$this->assertStringContainsString( 'listings', $results[0]['message'] ); // Requires listings, not yet enabled.
		$this->assertSame( 'Unknown module: nope.', $results[1]['message'] );
		$this->assertSame( array( 'listings' ), Loader::enabled() );
	}

	/**
	 * `disable filters listings` disables both; listings first is refused while filters needs it.
	 */
	public function test_disable_several() {
		ModuleCommand::toggle( 'enable', array( 'listings', 'filters' ) );

		$blocked = ModuleCommand::toggle( 'disable', array( 'listings' ) );
		$this->assertFalse( $blocked[0]['ok'] );
		$this->assertSame( array( 'listings', 'filters' ), Loader::enabled() );

		$results = ModuleCommand::toggle( 'disable', array( 'filters', 'listings' ) );
		$this->assertSame( array( true, true ), array_column( $results, 'ok' ) );
		$this->assertSame( 'Disabled module listings.', $results[1]['message'] );
		$this->assertSame( array(), Loader::enabled() );
	}

	/**
	 * A single slug works as before.
	 */
	public function test_single_slug() {
		$this->assertSame(
			array(
				array(
					'slug'    => 'listings',
					'ok'      => true,
					'message' => 'Enabled module listings.',
				),
			),
			ModuleCommand::toggle( 'enable', array( 'listings' ) )
		);
	}
}
