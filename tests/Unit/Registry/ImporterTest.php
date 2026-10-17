<?php
/**
 * Unit tests for import planning.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Importer;
use Pegwork\Registry\Loader;
use Pegwork\Registry\Normalizer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Importer::plan() and Importer::missing_terms(): upsert by key, idempotency, prune, strict validation.
 */
class ImporterTest extends TestCase {

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
	 * @param array $objects Objects by subdirectory, such as [ 'content-types' => [ ... ] ].
	 * @return string Package directory.
	 */
	private function package( array $objects ) {
		$dir          = get_temp_dir() . 'pegwork-test-' . wp_generate_password( 8, false );
		$this->dirs[] = $dir;
		wp_mkdir_p( $dir );
		$manifest = array(
			'format'  => 1,
			'id'      => 'pkg',
			'version' => '1.0.0',
			'label'   => 'Package',
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
	 * Changes as "action kind key" strings.
	 *
	 * @param array $changes Plan changes.
	 * @return string[]
	 */
	private function changes( array $changes ) {
		return array_map( fn( $c ) => $c['action'] . ' ' . $c['kind'] . ' ' . $c['key'], $changes );
	}

	/**
	 * First import adds everything in canonical form; importing again changes nothing.
	 */
	public function test_import_is_idempotent() {
		$dir  = dirname( __DIR__, 2 ) . '/fixtures/valid/complete';
		$plan = Importer::plan( $dir, array() );

		$this->assertTrue( $plan->is_valid() );
		$this->assertSame(
			array(
				'add manifest complete',
				'add content_type demo_event',
				'add content_type demo_venue',
				'add taxonomy demo_region',
				'add taxonomy demo_topic',
				'add relationship event_related',
				'add relationship event_venue',
			),
			$this->changes( $plan->changes )
		);
		$this->assertSame( array( 'content_types', 'taxonomies', 'relationships', 'manifest' ), array_keys( $plan->option ) );

		$file = Loader::load( array( $dir ) );
		$db   = Loader::load( array(), $plan->option );
		$this->assertTrue( $db->is_valid() );
		$this->assertSame( $file->registry->content_types(), $db->registry->content_types() );
		$this->assertSame( $file->registry->taxonomies(), $db->registry->taxonomies() );
		$this->assertSame( $file->registry->relationships(), $db->registry->relationships() );
		$this->assertSame( $file->manifests['complete'], $plan->option['manifest'] );

		$again = Importer::plan( $dir, $plan->option );
		$this->assertSame( 0, $again->count() );
		$this->assertSame( $plan->option, $again->option );
	}

	/**
	 * Changed entries are replaced in place, others kept, new ones appended; non-canonical entries are rewritten.
	 */
	public function test_upsert_by_key() {
		$dir = $this->package( array( 'content-types' => array( $this->type( 'alpha', 'New alpha' ), $this->type( 'beta' ) ) ) );
		$db  = array(
			'content_types' => array(
				$this->type( 'keep' ),
				Normalizer::normalize( 'content_type', $this->type( 'alpha', 'Old alpha' ) ),
			),
		);

		$plan = Importer::plan( $dir, $db );

		$this->assertSame( array( 'add manifest pkg', 'update content_type alpha', 'add content_type beta' ), $this->changes( $plan->changes ) );
		$this->assertSame( array( 'keep', 'alpha', 'beta' ), array_column( $plan->option['content_types'], 'key' ) );
		$this->assertSame( $this->type( 'keep' ), $plan->option['content_types'][0] );
		$this->assertSame( 'New alpha', $plan->option['content_types'][1]['labels']['plural'] );

		$db['content_types'][1] = $this->type( 'alpha', 'New alpha' ); // Same meaning, not canonical.
		$this->assertSame( array( 'add manifest pkg', 'update content_type alpha', 'add content_type beta' ), $this->changes( Importer::plan( $dir, $db )->changes ) );
	}

	/**
	 * Prune removes DB entries the package does not define; without it they stay. Repeated keys are dropped.
	 */
	public function test_prune() {
		$dir = $this->package( array( 'content-types' => array( $this->type( 'alpha' ) ) ) );
		$db  = array(
			'content_types' => array( $this->type( 'stale' ), $this->type( 'alpha' ), $this->type( 'alpha', 'Repeat' ) ),
			'taxonomies'    => array( 'not an object' ),
		);

		$kept = Importer::plan( $dir, $db );
		$this->assertSame( array( 'add manifest pkg', 'update content_type alpha', 'remove content_type alpha' ), $this->changes( $kept->changes ) );
		$this->assertSame( array( 'stale', 'alpha' ), array_column( $kept->option['content_types'], 'key' ) );
		$this->assertSame( array( 'not an object' ), $kept->option['taxonomies'] );

		$pruned = Importer::plan( $dir, $db, array(), true );
		$this->assertSame(
			array( 'add manifest pkg', 'remove content_type stale', 'update content_type alpha', 'remove content_type alpha', 'remove taxonomy #0' ),
			$this->changes( $pruned->changes )
		);
		$this->assertSame( array( 'alpha' ), array_column( $pruned->option['content_types'], 'key' ) );
		$this->assertSame( array(), $pruned->option['taxonomies'] );
	}

	/**
	 * The same validator as file packages applies: callables and invalid objects reject the whole import.
	 */
	public function test_invalid_package_is_rejected() {
		$plan = Importer::plan( dirname( __DIR__, 2 ) . '/fixtures/invalid/callable', array() );
		$this->assertFalse( $plan->is_valid() );
		$this->assertSame( 'callable_not_allowed', $plan->errors[0]->code );
		$this->assertSame( array(), $plan->option );

		$this->assertSame( 'missing_package', Importer::plan( '/no/such/dir', array() )->errors[0]->code );
	}

	/**
	 * References resolve against the package, keys defined elsewhere, and kept DB entries (not pruned ones).
	 */
	public function test_references() {
		$uses = $this->type( 'thing', 'Things', array( 'taxonomies' => array( array( 'key' => 'topic' ) ) ) );
		$dir  = $this->package( array( 'content-types' => array( $uses ) ) );

		$this->assertSame( 'unknown_taxonomy', Importer::plan( $dir, array() )->errors[0]->code );
		$this->assertTrue( Importer::plan( $dir, array(), array( 'taxonomy' => array( 'topic' => true ) ) )->is_valid() );

		$db = array(
			'taxonomies' => array(
				array(
					'format' => 1,
					'key'    => 'topic',
					'labels' => array(
						'singular' => 'Topic',
						'plural'   => 'Topics',
					),
				),
			),
		);
		$this->assertTrue( Importer::plan( $dir, $db )->is_valid() );
		$this->assertFalse( Importer::plan( $dir, $db, array(), true )->is_valid() );
	}

	/**
	 * Missing seed terms are listed by taxonomy and slug in registry order.
	 */
	public function test_missing_terms() {
		$registry = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/complete' ) )->registry;
		$missing  = Importer::missing_terms( $registry, fn( $taxonomy, $slug ) => 'arts' === $slug );

		$this->assertSame(
			array(
				array(
					'taxonomy' => 'demo_topic',
					'slug'     => 'civic-life',
				),
				array(
					'taxonomy' => 'demo_topic',
					'slug'     => '2026',
				),
			),
			$missing
		);
	}
}
