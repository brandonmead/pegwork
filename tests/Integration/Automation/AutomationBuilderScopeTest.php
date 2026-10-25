<?php
/**
 * Integration tests: the builder's field, merge tag and operator lists agree with the validator.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Registry\Loader;
use Pegwork\Registry\Validator;
use PegworkAutomation\Editor\BuilderEditor;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/modules/automation/module.php';

/**
 * Reads tests/fixtures/automation/scope.json, which the builder's Vitest suite
 * (assets/src/automation-builder/test/scope.test.js) also reads: the config the editor gets for each
 * package is BuilderEditor::registry_config(), every merge tag and condition field the builder offers
 * at a point validates there, the refused ones do not, and exactly the operators it offers for a var
 * pass the validator's type rules.
 */
class AutomationBuilderScopeTest extends WP_UnitTestCase {

	/**
	 * The fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture() {
		return json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/automation/scope.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * A package's registry.
	 *
	 * @param string $package Package name in the fixture.
	 * @return \Pegwork\Api\Registry
	 */
	private static function registry( $package ) {
		return Loader::load( array( dirname( __DIR__, 3 ) . '/' . self::fixture()['packages'][ $package ]['dir'] ) )->registry;
	}

	/**
	 * An automation of a package, as written.
	 *
	 * @param string $package Package name in the fixture.
	 * @param string $key     Automation key.
	 * @return array<string, mixed>
	 */
	private static function automation( $package, $key ) {
		$dir = dirname( __DIR__, 3 ) . '/' . self::fixture()['packages'][ $package ]['dir'];
		return json_decode( file_get_contents( $dir . '/automations/' . $key . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
	}

	/**
	 * Errors (code and pointer) of an automation, shape then references, against a package.
	 *
	 * @param string $package Package name in the fixture.
	 * @param array  $data    Automation.
	 * @return string[]
	 */
	private static function errors( $package, array $data ) {
		$registry = self::registry( $package );
		$known    = array();
		foreach ( Loader::SECTIONS as $kind => $section ) {
			$known[ $kind ] = $registry->$section();
		}
		$errors = Validator::validate( 'automation', $data, 'f' );
		if ( ! $errors ) {
			$errors = Validator::references( 'automation', $data, 'f', $known );
		}
		return array_map( fn( $e ) => $e->code . ' ' . $e->pointer . ' ' . $e->message, $errors );
	}

	/**
	 * A copy of a value with the value at a JSON pointer replaced.
	 *
	 * @param array  $data    Value.
	 * @param string $pointer Pointer (no escaped segments).
	 * @param mixed  $value   New value.
	 * @return array
	 */
	private static function with( array $data, $pointer, $value ) {
		$target = &$data;
		foreach ( array_slice( explode( '/', $pointer ), 1 ) as $segment ) {
			$target = &$target[ is_numeric( $segment ) ? (int) $segment : $segment ];
		}
		$target = $value;
		unset( $target );
		return $data;
	}

	/**
	 * The editor config of each package is what the fixture holds (so the Vitest suite tests the real shape).
	 * `roles` is site state, not registry, so the fixture leaves it out (AutomationRoleEmailTest checks it).
	 */
	public function test_config_matches_registry() {
		foreach ( self::fixture()['packages'] as $name => $package ) {
			$config = BuilderEditor::registry_config( self::registry( $name ) );
			unset( $config['roles'] );
			$this->assertSame(
				wp_json_encode( $package['config'] ),
				wp_json_encode( $config ),
				$name
			);
		}
	}

	/**
	 * Every offered merge tag validates at its template; every refused one is refused there.
	 */
	public function test_merge_tags() {
		$cases = self::fixture()['tags'];
		$this->assertGreaterThanOrEqual( 7, count( $cases ) );
		foreach ( $cases as $case ) {
			$data     = self::automation( $case['package'], $case['automation'] );
			$label    = $case['automation'] . ' ' . $case['at'];
			$template = static fn( $path ) => str_replace( '{{tag}}', '{{' . $path . '}}', $case['template'] ?? '{{tag}}' );
			$this->assertSame( array(), self::errors( $case['package'], $data ), $label . ' (fixture as written)' );
			foreach ( $case['expect'] as $path ) {
				$this->assertSame( array(), self::errors( $case['package'], self::with( $data, $case['at'], $template( $path ) ) ), $label . ' ' . $path );
			}
			foreach ( $case['refuse'] as $path ) {
				$this->assertNotSame( array(), self::errors( $case['package'], self::with( $data, $case['at'], $template( $path ) ) ), $label . ' refuses ' . $path );
				$this->assertNotContains( $path, $case['expect'], $label );
			}
		}
	}

	/**
	 * Every offered condition field validates as a `not_empty` leaf at its condition.
	 */
	public function test_condition_fields() {
		foreach ( self::fixture()['fields'] as $case ) {
			$data = self::automation( $case['package'], $case['automation'] );
			$this->assertNotSame( array(), $case['expect'] );
			foreach ( $case['expect'] as $path ) {
				$leaf = array(
					'var' => $path,
					'op'  => 'not_empty',
				);
				$this->assertSame( array(), self::errors( $case['package'], self::with( $data, $case['at'], $leaf ) ), $case['automation'] . ' ' . $case['at'] . ' ' . $path );
			}
		}
	}

	/**
	 * Exactly the offered operators pass the validator for a var in a condition (the trigger's unless the
	 * case names another, as for typed read outputs).
	 */
	public function test_operators() {
		$all = array( 'eq', 'ne', 'in', 'not_in', 'lt', 'lte', 'gt', 'gte', 'empty', 'not_empty', 'contains', 'changed' );
		foreach ( self::fixture()['operators'] as $case ) {
			$data  = self::automation( $case['package'], $case['automation'] );
			$valid = array();
			foreach ( $all as $op ) {
				$leaf = array(
					'var' => $case['var'],
					'op'  => $op,
				);
				if ( in_array( $op, array( 'in', 'not_in' ), true ) ) {
					$leaf['value'] = array( $case['sample'] );
				} elseif ( ! in_array( $op, array( 'empty', 'not_empty', 'changed' ), true ) ) {
					$leaf['value'] = $case['sample'];
				}
				if ( array() === self::errors( $case['package'], self::with( $data, $case['at'] ?? '/trigger/when', $leaf ) ) ) {
					$valid[] = $op;
				}
			}
			$this->assertSame( $case['expect'], $valid, $case['automation'] . ' ' . $case['var'] );
		}
	}
}
