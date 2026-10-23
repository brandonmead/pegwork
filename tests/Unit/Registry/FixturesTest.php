<?php
/**
 * Fixture tests for the PHP validator. bin/check-schema-fixtures.php checks the JSON Schema
 * against the same fixtures and expected.json files, so the two cannot drift.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every valid fixture loads cleanly; every invalid fixture fails for its own rule.
 */
class FixturesTest extends TestCase {

	/**
	 * Valid package directories: tests/fixtures/valid/* and examples/*.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_valid_packages() {
		$root = dirname( __DIR__, 3 );
		$dirs = array_merge( glob( $root . '/tests/fixtures/valid/*', GLOB_ONLYDIR ), glob( $root . '/examples/*', GLOB_ONLYDIR ) );

		return array_combine( array_map( 'basename', $dirs ), array_map( fn( $dir ) => array( $dir ), $dirs ) );
	}

	/**
	 * Invalid package directories: tests/fixtures/invalid/*.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_invalid_packages() {
		$dirs = glob( dirname( __DIR__, 2 ) . '/fixtures/invalid/*', GLOB_ONLYDIR );

		return array_combine( array_map( 'basename', $dirs ), array_map( fn( $dir ) => array( $dir ), $dirs ) );
	}

	/**
	 * A valid package loads with no errors and registers its objects.
	 *
	 * @dataProvider data_valid_packages
	 *
	 * @param string $dir Package directory.
	 */
	public function test_valid_package_loads_without_errors( $dir ) {
		$result = Loader::load( array( $dir ) );

		$this->assertSame( array(), array_map( 'strval', $result->errors ) );

		$expected = count( glob( $dir . '/*/*.json' ) );
		$loaded   = array_sum( array_map( fn( $section ) => count( $result->registry->$section() ), Loader::SECTIONS ) );
		$this->assertSame( $expected, $loaded, 'Every object file is registered.' );
	}

	/**
	 * An invalid package reports its rule's error at the expected file and pointer, and registers nothing.
	 *
	 * @dataProvider data_invalid_packages
	 *
	 * @param string $dir Package directory.
	 */
	public function test_invalid_package_fails_for_its_rule( $dir ) {
		$expected = json_decode( file_get_contents( $dir . '/expected.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$result   = Loader::load( array( $dir ) );

		$matches = array_filter(
			$result->errors,
			fn( $error ) => $error->code === $expected['code']
				&& $error->file === $dir . '/' . $expected['file']
				&& $error->pointer === $expected['pointer']
		);

		$this->assertNotEmpty(
			$matches,
			sprintf( "Expected %s at %s#%s. Got:\n%s", $expected['code'], $expected['file'], $expected['pointer'], implode( "\n", array_map( 'strval', $result->errors ) ) )
		);
		foreach ( Loader::SECTIONS as $section ) {
			$this->assertSame( array(), $result->registry->$section(), "No $section registered." );
		}
	}

	/**
	 * The E2E BarnCRM package holds the proof fixture's types, form and options page byte for byte, and no
	 * automations (the editor E2E builds those and compares them with the fixture's).
	 */
	public function test_e2e_barn_package_matches_the_fixture() {
		$root    = dirname( __DIR__, 3 );
		$fixture = $root . '/tests/fixtures/valid/barn';
		$e2e     = $root . '/tests/e2e/packages/barn';
		$objects = fn( $dir ) => array_map( fn( $file ) => substr( $file, strlen( $dir ) + 1 ), glob( $dir . '/*/*.json' ) );

		$expected = array_values( array_filter( $objects( $fixture ), fn( $file ) => ! str_starts_with( $file, 'automations/' ) ) );
		$this->assertSame( $expected, $objects( $e2e ) );
		foreach ( $expected as $file ) {
			$this->assertFileEquals( "$fixture/$file", "$e2e/$file" );
		}

		$result = Loader::load( array( $e2e ) );
		$this->assertSame( array(), array_map( 'strval', $result->errors ) );
		$this->assertCount( 4, $result->registry->content_types() );
		$this->assertSame( array(), $result->registry->automations() );
	}

	/**
	 * The E2E BarnCRM reads package holds the reads fixture's types, relationships, taxonomy and options
	 * page byte for byte, and no automations (the reads editor E2E builds barn_lifetime_milestone).
	 */
	public function test_e2e_barn_reads_package_matches_the_fixture() {
		$root    = dirname( __DIR__, 3 );
		$fixture = $root . '/tests/fixtures/valid/reads';
		$e2e     = $root . '/tests/e2e/packages/barn-reads';
		$objects = fn( $dir ) => array_map( fn( $file ) => substr( $file, strlen( $dir ) + 1 ), glob( $dir . '/*/*.json' ) );

		$expected = array_values( array_filter( $objects( $fixture ), fn( $file ) => ! str_starts_with( $file, 'automations/' ) ) );
		$this->assertSame( $expected, $objects( $e2e ) );
		foreach ( $expected as $file ) {
			$this->assertFileEquals( "$fixture/$file", "$e2e/$file" );
		}

		$result = Loader::load( array( $e2e ) );
		$this->assertSame( array(), array_map( 'strval', $result->errors ) );
		$this->assertCount( 2, $result->registry->relationships() );
		$this->assertSame( array(), $result->registry->automations() );
	}
}
