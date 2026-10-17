<?php
/**
 * Unit test of the PHP and WordPress version guard.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests pegwork_unmet_requirements().
 */
class VersionGuardTest extends TestCase {

	/**
	 * Cases: PHP version, WordPress version, expected unmet minimums.
	 *
	 * @return array<string, array{string, string, array<string, string>}>
	 */
	public function data_versions() {
		return array(
			'both at minimum'   => array( '8.2.0', '6.7', array() ),
			'both above'        => array( '8.4.1', '7.1.3', array() ),
			'old PHP'           => array( '8.1.29', '6.7.1', array( 'php' => '8.2' ) ),
			'old WordPress'     => array( '8.2.29', '6.6.2', array( 'wp' => '6.7' ) ),
			'both old'          => array(
				'7.4.33',
				'6.5',
				array(
					'php' => '8.2',
					'wp'  => '6.7',
				),
			),
			'WordPress 6.7 RC'  => array( '8.2.0', '6.7-RC1', array( 'wp' => '6.7' ) ),
			'WordPress 6.7 dot' => array( '8.2.0', '6.7.2', array() ),
		);
	}

	/**
	 * The guard reports exactly the unmet minimums.
	 *
	 * @dataProvider data_versions
	 *
	 * @param string                $php      PHP version.
	 * @param string                $wp       WordPress version.
	 * @param array<string, string> $expected Expected unmet minimums.
	 */
	public function test_unmet_requirements( $php, $wp, $expected ) {
		$this->assertSame( $expected, pegwork_unmet_requirements( $php, $wp ) );
	}
}
