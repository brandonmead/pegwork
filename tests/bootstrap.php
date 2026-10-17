<?php
/**
 * PHPUnit bootstrap.
 *
 * CI sets WP_TESTS_DIR (bin/install-wp-tests.sh). Locally the wp-phpunit package is used with
 * tests/wp-tests-config.php, configured through the environment variables read there.
 *
 * @package Pegwork
 */

$pegwork_root = dirname( __DIR__ );

require_once $pegwork_root . '/vendor/autoload.php';

$pegwork_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $pegwork_tests_dir ) {
	$pegwork_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
	if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
		putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- wp-phpunit reads its config path from the environment.
	}
}

require_once $pegwork_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $pegwork_root ) {
		require $pegwork_root . '/pegwork.php';
	}
);

require $pegwork_tests_dir . '/includes/bootstrap.php';
