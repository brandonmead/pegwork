<?php
/**
 * Unit tests for loader precedence.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Loader: file beats DB, duplicate packages rejected, references across sources.
 */
class LoaderTest extends TestCase {

	/**
	 * Temporary package directories to remove.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Removes temporary packages.
	 */
	protected function tear_down() {
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/{,*/}*.json', GLOB_BRACE ) as $file ) {
				wp_delete_file( $file );
			}
			foreach ( array_merge( glob( $dir . '/*', GLOB_ONLYDIR ), array( $dir ) ) as $sub ) {
				rmdir( $sub ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	/**
	 * Writes a package to a temporary directory.
	 *
	 * @param string $id      Package id.
	 * @param array  $objects Objects by subdirectory, such as [ 'content-types' => [ ... ] ].
	 * @return string Package directory.
	 */
	private function package( $id, array $objects ) {
		$dir          = get_temp_dir() . 'pegwork-test-' . wp_generate_password( 8, false );
		$this->dirs[] = $dir;
		wp_mkdir_p( $dir );
		$manifest = array(
			'format'  => 1,
			'id'      => $id,
			'version' => '1.0.0',
			'label'   => $id,
		);
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		foreach ( $objects as $subdir => $items ) {
			wp_mkdir_p( $dir . '/' . $subdir );
			foreach ( $items as $item ) {
				file_put_contents( $dir . '/' . $subdir . '/' . $item['key'] . '.json', wp_json_encode( $item ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}
		}

		return $dir;
	}

	/**
	 * A minimal content type.
	 *
	 * @param string $key   Key.
	 * @param string $label Plural label.
	 * @param array  $extra Extra properties.
	 * @return array
	 */
	private function type( $key, $label = 'Things', array $extra = array() ) {
		return array(
			'format' => 1,
			'key'    => $key,
			'labels' => array(
				'singular' => 'Thing',
				'plural'   => $label,
			),
		) + $extra;
	}

	/**
	 * A minimal taxonomy.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	private function taxonomy( $key ) {
		return array(
			'format' => 1,
			'key'    => $key,
			'labels' => array(
				'singular' => 'Topic',
				'plural'   => 'Topics',
			),
		);
	}

	/**
	 * When a key is in a file package and the DB, the file wins and the DB entry is shadowed.
	 */
	public function test_file_beats_db() {
		$dir = $this->package( 'files', array( 'content-types' => array( $this->type( 'thing', 'From file' ) ) ) );
		$db  = array(
			'content_types' => array( $this->type( 'thing', 'From DB' ), $this->type( 'db_only', 'DB only' ) ),
		);

		$result = Loader::load( array( $dir ), $db );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'From file', $result->registry->content_type( 'thing' )['labels']['plural'] );
		$this->assertSame( 'files', $result->sources['content_type']['thing'] );
		$this->assertSame(
			array(
				array(
					'kind' => 'content_type',
					'key'  => 'thing',
					'by'   => 'files',
				),
			),
			$result->shadowed
		);
		$this->assertSame( 'DB only', $result->registry->content_type( 'db_only' )['labels']['plural'] );
		$this->assertSame( Loader::DB_SOURCE, $result->sources['content_type']['db_only'] );
	}

	/**
	 * A package that redefines an earlier package's key is rejected whole.
	 */
	public function test_duplicate_key_rejects_later_package() {
		$first  = $this->package( 'first', array( 'content-types' => array( $this->type( 'thing', 'First' ) ) ) );
		$second = $this->package(
			'second',
			array(
				'content-types' => array( $this->type( 'thing', 'Second' ), $this->type( 'other' ) ),
				'taxonomies'    => array( $this->taxonomy( 'topic' ) ),
			)
		);

		$result = Loader::load( array( $first, $second ) );

		$this->assertCount( 1, $result->errors );
		$this->assertSame( 'duplicate_key', $result->errors[0]->code );
		$this->assertStringContainsString( '"first"', $result->errors[0]->message );
		$this->assertStringContainsString( '"second" was not registered', $result->errors[0]->message );
		$this->assertSame( 'First', $result->registry->content_type( 'thing' )['labels']['plural'] );
		$this->assertNull( $result->registry->content_type( 'other' ) );
		$this->assertSame( array(), $result->registry->taxonomies() );
	}

	/**
	 * The same package registered twice is rejected the second time.
	 */
	public function test_duplicate_package_rejected() {
		$dir = $this->package( 'same', array( 'taxonomies' => array( $this->taxonomy( 'topic' ) ) ) );

		$result = Loader::load( array( $dir, $dir ) );

		$this->assertSame( array( 'duplicate_package', 'duplicate_key' ), array_map( fn( $e ) => $e->code, $result->errors ) );
		$this->assertSame( array( 'topic' ), array_keys( $result->registry->taxonomies() ) );
	}

	/**
	 * Invalid and duplicate DB entries are reported with option pointers and dropped.
	 */
	public function test_invalid_db_entries_dropped() {
		$db = array(
			'content_types' => array( $this->type( 'thing' ), $this->type( 'Bad' ), $this->type( 'thing' ) ),
			'taxonomies'    => 'not a list',
		);

		$result = Loader::load( array(), $db );

		$this->assertSame(
			array(
				'option:pegwork_schema#/content_types/1/key',
				'option:pegwork_schema#/content_types/2/key',
				'option:pegwork_schema#/taxonomies',
			),
			array_map( fn( $e ) => $e->file . '#' . $e->pointer, $result->errors )
		);
		$this->assertSame( array( 'thing' ), array_keys( $result->registry->content_types() ) );
	}

	/**
	 * References resolve across packages and the DB; a package depending on a rejected one is rejected too.
	 */
	public function test_references_across_sources() {
		$uses = array( 'taxonomies' => array( array( 'key' => 'topic' ) ) );

		$taxonomies = $this->package( 'taxonomies', array( 'taxonomies' => array( $this->taxonomy( 'topic' ) ) ) );
		$types      = $this->package( 'types', array( 'content-types' => array( $this->type( 'thing', 'Things', $uses ) ) ) );
		$this->assertTrue( Loader::load( array( $taxonomies, $types ) )->is_valid() );

		$from_db = Loader::load( array( $types ), array( 'taxonomies' => array( $this->taxonomy( 'topic' ) ) ) );
		$this->assertTrue( $from_db->is_valid() );

		$relationship = array(
			'format'      => 1,
			'key'         => 'thing_link',
			'from'        => 'thing',
			'to'          => 'thing',
			'cardinality' => 'one_to_many',
		);
		$dependent    = $this->package( 'dependent', array( 'relationships' => array( $relationship ) ) );
		$result       = Loader::load( array( $types, $dependent ) );
		$this->assertSame( array( 'unknown_taxonomy', 'unknown_content_type', 'unknown_content_type' ), array_map( fn( $e ) => $e->code, $result->errors ) );
		$this->assertSame( array(), $result->registry->content_types() );
		$this->assertSame( array(), $result->registry->relationships() );
	}

	/**
	 * A missing directory is reported, not fatal.
	 */
	public function test_missing_directory() {
		$result = Loader::load( array( '/nonexistent/pegwork-package' ) );

		$this->assertSame( 'missing_package', $result->errors[0]->code );
		$this->assertSame( '/nonexistent/pegwork-package', $result->errors[0]->file );
	}
}
