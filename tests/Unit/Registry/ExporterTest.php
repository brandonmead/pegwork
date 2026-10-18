<?php
/**
 * Unit tests for the exporter.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Exporter;
use Pegwork\Registry\Loader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Exporter: canonical, deterministic package output; source filter; stale file removal.
 */
class ExporterTest extends TestCase {

	/**
	 * Temporary directories to remove.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Removes temporary directories.
	 */
	protected function tear_down() {
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/{,*/}*', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			foreach ( array_merge( glob( $dir . '/*', GLOB_ONLYDIR ), array( $dir ) ) as $sub ) {
				rmdir( $sub ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	/**
	 * A new empty temporary directory.
	 */
	private function temp_dir() {
		$dir          = get_temp_dir() . 'pegwork-test-' . wp_generate_password( 8, false );
		$this->dirs[] = $dir;
		wp_mkdir_p( $dir );

		return $dir;
	}

	/**
	 * Writes a package with one content type to a temporary directory.
	 *
	 * @param string $id    Package id.
	 * @param string $key   Content type key.
	 * @param string $label Plural label.
	 * @return string Package directory.
	 */
	private function package( $id, $key, $label = 'Things' ) {
		$dir = $this->temp_dir();
		wp_mkdir_p( $dir . '/content-types' );
		$manifest = array(
			'format'  => 1,
			'id'      => $id,
			'version' => '1.0.0',
			'label'   => $id,
		);
		file_put_contents( $dir . '/manifest.json', wp_json_encode( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/content-types/' . $key . '.json', wp_json_encode( $this->type( $key, $label ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return $dir;
	}

	/**
	 * A minimal content type.
	 *
	 * @param string $key   Key.
	 * @param string $label Plural label.
	 */
	private function type( $key, $label = 'Things' ) {
		return array(
			'format' => 1,
			'key'    => $key,
			'labels' => array(
				'singular' => 'Thing',
				'plural'   => $label,
			),
		);
	}

	/**
	 * Path of the fixture with every feature.
	 */
	private function complete() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/complete';
	}

	/**
	 * Export is canonical JSON, has no paths, and loads back into the same registry.
	 */
	public function test_export_is_canonical_and_round_trips() {
		$result = Loader::load( array( $this->complete() ) );
		$files  = Exporter::files( $result );

		$this->assertSame(
			array(
				'content-types/demo_event.json',
				'content-types/demo_venue.json',
				'manifest.json',
				'relationships/event_related.json',
				'relationships/event_venue.json',
				'taxonomies/demo_region.json',
				'taxonomies/demo_topic.json',
			),
			array_keys( $files )
		);

		foreach ( $files as $path => $contents ) {
			$this->assertStringEndsWith( "}\n", $contents, $path );
			$this->assertStringStartsWith( "{\n    \"", $contents, $path );
			$this->assertStringNotContainsString( dirname( __DIR__, 2 ), $contents, $path );
			$this->assertStringNotContainsString( '\\/', $contents, $path );
		}

		$this->assertSame( $result->registry->content_type( 'demo_event' ), json_decode( $files['content-types/demo_event.json'], true ) );
		$this->assertSame( $result->manifests['complete'], json_decode( $files['manifest.json'], true ) );
		$this->assertSame( array( 'format', 'id', 'label', 'requires', 'version' ), array_keys( json_decode( $files['manifest.json'], true ) ) );
		$this->assertStringContainsString( '"storage": "meta"', $files['relationships/event_venue.json'] );

		$dir = $this->temp_dir();
		Exporter::write( $dir, $files );
		$again = Loader::load( array( $dir ) );

		$this->assertTrue( $again->is_valid() );
		$this->assertSame( $result->registry->content_types(), $again->registry->content_types() );
		$this->assertSame( $result->registry->taxonomies(), $again->registry->taxonomies() );
		$this->assertSame( $result->registry->relationships(), $again->registry->relationships() );
		$this->assertSame( $files, Exporter::files( $again ) );
	}

	/**
	 * Forms, automations and options pages export to their own directories and load back byte-identical.
	 */
	public function test_export_sections_round_trip() {
		$result = Loader::load( array( dirname( __DIR__, 2 ) . '/fixtures/valid/sections' ) );
		$files  = Exporter::files( $result );

		$this->assertSame(
			array(
				'automations/contact_received.json',
				'automations/talk_followup.json',
				'automations/talk_inbound.json',
				'automations/talk_published.json',
				'automations/talk_updated.json',
				'content-types/talk.json',
				'forms/contact.json',
				'forms/submit_talk.json',
				'manifest.json',
				'options-pages/talk_settings.json',
				'taxonomies/talk_level.json',
				'taxonomies/talk_track.json',
			),
			array_keys( $files )
		);
		$this->assertSame( $result->registry->form( 'submit_talk' ), json_decode( $files['forms/submit_talk.json'], true ) );
		$this->assertStringContainsString( '"listing": {', $files['content-types/talk.json'] );

		$dir = $this->temp_dir();
		Exporter::write( $dir, $files );
		$again = Loader::load( array( $dir ) );

		$this->assertTrue( $again->is_valid(), implode( "\n", array_map( 'strval', $again->errors ) ) );
		foreach ( Loader::SECTIONS as $section ) {
			$this->assertSame( $result->registry->$section(), $again->registry->$section(), $section );
		}
		$this->assertSame( $files, Exporter::files( $again ) );
	}

	/**
	 * Unicode and slashes are written unescaped.
	 */
	public function test_unicode_and_slashes_unescaped() {
		$files = Exporter::files( Loader::load( array( $this->package( 'cafe', 'thing', 'Cafés/Bars' ) ) ) );

		$this->assertStringContainsString( '"plural": "Cafés/Bars"', $files['content-types/thing.json'] );
	}

	/**
	 * The source filter picks objects, and the manifest comes from the only contributing source.
	 */
	public function test_source_filter_and_manifest() {
		$stored = array(
			'format'  => 1,
			'id'      => 'imported',
			'label'   => 'Imported',
			'version' => '2.0.0',
		);
		$db     = array(
			'content_types' => array( $this->type( 'db_thing' ) ),
		);
		$result = Loader::load( array( $this->package( 'files', 'file_thing' ) ), $db );

		$all = Exporter::files( $result, 'all', $stored );
		$this->assertSame( array( 'content-types/db_thing.json', 'content-types/file_thing.json', 'manifest.json' ), array_keys( $all ) );
		$this->assertSame( 'site', json_decode( $all['manifest.json'], true )['id'] );

		$only_db = Exporter::files( $result, 'db', $stored );
		$this->assertSame( array( 'content-types/db_thing.json', 'manifest.json' ), array_keys( $only_db ) );
		$this->assertSame( $stored, json_decode( $only_db['manifest.json'], true ) );

		$invalid = Exporter::files( $result, 'db', array( 'id' => 'Not valid' ) );
		$this->assertSame( 'site', json_decode( $invalid['manifest.json'], true )['id'] );

		$only_files = Exporter::files( $result, 'files', $stored );
		$this->assertSame( array( 'content-types/file_thing.json', 'manifest.json' ), array_keys( $only_files ) );
		$this->assertSame( 'files', json_decode( $only_files['manifest.json'], true )['id'] );
	}

	/**
	 * Writing removes stale object files from an earlier export and leaves other files alone.
	 */
	public function test_write_removes_stale_object_files() {
		$dir = $this->temp_dir();
		Exporter::write( $dir, Exporter::files( Loader::load( array( $this->complete() ) ) ) );
		file_put_contents( $dir . '/README.txt', 'notes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$report = Exporter::write( $dir, Exporter::files( Loader::load( array( $this->package( 'small', 'thing' ) ) ) ) );

		$this->assertSame( array( 'content-types/thing.json', 'manifest.json' ), $report['written'] );
		$this->assertSame(
			array(
				'content-types/demo_event.json',
				'content-types/demo_venue.json',
				'relationships/event_related.json',
				'relationships/event_venue.json',
				'taxonomies/demo_region.json',
				'taxonomies/demo_topic.json',
			),
			$report['removed']
		);
		$this->assertFileExists( $dir . '/README.txt' );
		$this->assertDirectoryDoesNotExist( $dir . '/taxonomies' );
		$this->assertSame( array( 'README.txt', 'content-types', 'manifest.json' ), array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ) );
	}
}
