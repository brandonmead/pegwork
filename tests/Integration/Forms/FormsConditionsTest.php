<?php
/**
 * The shared condition rules table, PHP side.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Content\RecordValidator;
use PegworkForms\Definition;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/modules/forms/module.php'; // Registers the module autoloader.

/**
 * Runs `tests/fixtures/forms/conditions.json` through `Definition::shown()`, `Definition::visible()` and
 * `RecordValidator::count_words()`. The view script's Vitest suite (`assets/src/forms-view/test/`) runs
 * the same file, so the browser hides, shows and counts exactly as the server does.
 */
class FormsConditionsTest extends WP_UnitTestCase {

	/**
	 * The decoded fixture.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixture(): array {
		$json = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/forms/conditions.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$data = json_decode( (string) $json, true );
		self::assertIsArray( $data );
		return $data;
	}

	/**
	 * Every `shown` case.
	 */
	public function test_shown_cases() {
		$cases = self::fixture()['shown'];
		$this->assertGreaterThan( 30, count( $cases ) );
		foreach ( $cases as $case ) {
			$item = array( 'key' => 'item' );
			if ( null !== $case['show_when'] ) {
				$item['show_when'] = $case['show_when'];
			}
			$this->assertSame( $case['expected'], Definition::shown( $item, $case['values'] ), $case['name'] );
		}
	}

	/**
	 * Every `visible` case: the whole form's shown flags.
	 */
	public function test_visible_cases() {
		foreach ( self::fixture()['visible'] as $case ) {
			$by_key = array();
			foreach ( $case['items'] as $item ) {
				$by_key[ $item['key'] ] = $item;
			}
			$this->assertSame( $case['expected'], Definition::visible( $by_key, $case['values'] ), $case['name'] );
		}
	}

	/**
	 * Every `words` case, against the counter the record rules use.
	 */
	public function test_word_cases() {
		foreach ( self::fixture()['words'] as $case ) {
			$this->assertSame( $case['count'], RecordValidator::count_words( $case['text'] ), $case['name'] );
		}
	}
}
