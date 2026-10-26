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
	 * An invalid package reports its rule's error at the expected file and pointer, and registers nothing. A
	 * fixture whose rule involves site definitions holds them in `db.json` (the `pegwork_schema` option); those
	 * still register.
	 *
	 * @dataProvider data_invalid_packages
	 *
	 * @param string $dir Package directory.
	 */
	public function test_invalid_package_fails_for_its_rule( $dir ) {
		$expected = json_decode( file_get_contents( $dir . '/expected.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$db       = is_file( $dir . '/db.json' ) ? json_decode( file_get_contents( $dir . '/db.json' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$result   = Loader::load( array( $dir ), $db );

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
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$from_package = array_filter( $result->sources[ $kind ] ?? array(), fn( $source ) => Loader::DB_SOURCE !== $source );
			$this->assertSame( array(), $from_package, "No $section registered from the package." );
			$this->assertCount( count( $db[ $section ] ?? array() ), $result->registry->$section(), "Only the site's $section registered." );
		}
	}

	/**
	 * The E2E BarnCRM package holds the proof fixture's types, relationships, form and options page byte for
	 * byte, and no automations (the editor E2Es build those and compare them with the fixture's).
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
		$this->assertCount( 2, $result->registry->relationships() );
		$this->assertSame( array(), $result->registry->automations() );
	}

	/**
	 * The test packages that carry BarnCRM automations for their own purposes (`reads`: every new step shape
	 * and the editor scope fixture; `engine-loops`: the loop engine tests; `v4`: the Automation v4 shapes)
	 * hold them byte for byte as the BarnCRM proof fixture does, so the worked examples have one text. The
	 * `v4` package's own shape automations are not worked examples and have no copy.
	 */
	public function test_barn_automation_copies_match_the_fixture() {
		$root    = dirname( __DIR__, 3 ) . '/tests/fixtures/valid';
		$v4_only = array( 'barn_gift_receipt_start', 'barn_v4_shapes', 'barn_weekly_note' );
		$v4      = array_filter( glob( $root . '/v4/automations/barn_*.json' ), fn( $file ) => ! in_array( basename( $file, '.json' ), $v4_only, true ) );
		$copies  = array_merge( glob( $root . '/reads/automations/barn_*.json' ), glob( $root . '/engine-loops/automations/barn_*.json' ), $v4 );
		$checked = array();
		foreach ( $copies as $copy ) {
			$this->assertFileEquals( $root . '/barn/automations/' . basename( $copy ), $copy );
			$checked[] = basename( dirname( $copy, 2 ) ) . '/' . basename( $copy, '.json' );
		}
		$this->assertSame(
			array(
				'reads/barn_donation_inbound',
				'reads/barn_gift_receipt',
				'reads/barn_gift_thanks',
				'reads/barn_lifetime_milestone',
				'reads/barn_resend_thanks',
				'reads/barn_tasks_digest',
				'engine-loops/barn_tasks_digest',
				'v4/barn_gift_receipt',
				'v4/barn_task_due_reminder',
				'v4/barn_volunteer_retention',
				'v4/barn_volunteer_review',
			),
			$checked
		);
	}
}
