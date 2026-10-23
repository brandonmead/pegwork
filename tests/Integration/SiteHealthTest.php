<?php
/**
 * Site Health check tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\SiteHealth;
use WP_UnitTestCase;

/**
 * The Site Health test warns when development dependencies are installed on a site that is not local or
 * development (security review SR-03, defence in depth next to the release zip).
 */
class SiteHealthTest extends WP_UnitTestCase {

	/**
	 * The test is registered as a direct Site Health test.
	 */
	public function test_registered() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$this->assertSame( array( SiteHealth::class, 'run' ), $tests['direct'][ SiteHealth::TEST ]['test'] );
		$this->assertContains( SiteHealth::run()['status'], array( 'good', 'critical' ) );
	}

	/**
	 * Composer's installed.php `root.dev` decides; a missing file means a release build.
	 */
	public function test_reads_composer_installed_file() {
		$dir = get_temp_dir() . 'pegwork-health-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir );
		foreach ( array(
			'dev'    => true,
			'no-dev' => false,
		) as $name => $dev ) {
			file_put_contents( "$dir/$name.php", '<?php return ' . var_export( array( 'root' => array( 'dev' => $dev ) ), true ) . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test fixture (a PHP array) in the temp folder.
			$this->assertSame( $dev, SiteHealth::dev_installed( "$dir/$name.php" ), $name );
			wp_delete_file( "$dir/$name.php" );
		}
		$this->assertFalse( SiteHealth::dev_installed( "$dir/missing.php" ) );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty temp folder.
	}

	/**
	 * Critical only with dev dependencies outside local and development environments.
	 */
	public function test_result() {
		$this->assertSame( 'critical', SiteHealth::result( true, 'production' )['status'] );
		$this->assertSame( 'critical', SiteHealth::result( true, 'staging' )['status'] );
		$this->assertSame( 'good', SiteHealth::result( true, 'local' )['status'] );
		$this->assertSame( 'good', SiteHealth::result( true, 'development' )['status'] );
		$this->assertSame( 'good', SiteHealth::result( false, 'production' )['status'] );
		$this->assertSame( SiteHealth::TEST, SiteHealth::result( true, 'production' )['test'] );
	}
}
