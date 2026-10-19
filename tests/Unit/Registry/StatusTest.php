<?php
/**
 * Unit tests for the registry status rows.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
use Pegwork\Registry\Status;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Status rows for the Overview screen and `wp pegwork schema list|status`.
 */
class StatusTest extends TestCase {

	/**
	 * Fixture root.
	 *
	 * @return string
	 */
	private function fixtures() {
		return dirname( __DIR__, 2 ) . '/fixtures';
	}

	/**
	 * The example package yields rows with labels, REST base, field storage and source.
	 */
	public function test_rows_for_example_package() {
		$dir    = dirname( __DIR__, 3 ) . '/examples/sifg';
		$status = new Status( Loader::load( array( $dir ) ), array( $dir ) );

		$types = $status->content_types();
		$this->assertSame( array( 'sifg_project', 'sifg_story' ), array_column( $types, 'key' ) );
		$this->assertSame( 'Projects', $types[0]['label'] );
		$this->assertSame( 'projects', $types[0]['rest_base'] );
		$this->assertSame( 'sifg', $types[0]['source'] );
		$this->assertSame( array( 'sifg_project_type', 'sifg_project_status', 'sifg_impact' ), $types[0]['taxonomies'] );

		$fields = array_column( $types[0]['fields'], null, 'key' );
		$this->assertCount( 12, $fields );
		$this->assertSame( 'post_title', $fields['name']['stored_as'] );
		$this->assertSame( '_sifg_project_contact_email', $fields['contact_email']['stored_as'] );
		$this->assertSame( 'private', $fields['contact_email']['visibility'] );
		$this->assertTrue( $fields['contact_email']['required'] );
		$this->assertFalse( $fields['website']['required'] );

		$taxonomies = array_column( $status->taxonomies(), null, 'key' );
		$this->assertSame( 17, $taxonomies['sifg_impact']['terms'] );
		$this->assertTrue( $taxonomies['sifg_impact']['locked'] );
		$this->assertSame( 'sifg', $taxonomies['sifg_impact']['source'] );

		$this->assertSame(
			array(
				array(
					'key'         => 'story_projects',
					'from'        => 'sifg_story',
					'to'          => 'sifg_project',
					'cardinality' => 'many_to_many',
					'storage'     => 'meta',
					'required'    => true,
					'source'      => 'sifg',
				),
			),
			$status->relationships()
		);
		$this->assertSame( array(), $status->shadowed() );
		$this->assertSame( array(), $status->rejected() );
	}

	/**
	 * Relationship rows carry cardinality, storage, required and source.
	 */
	public function test_relationship_rows() {
		$dir    = $this->fixtures() . '/valid/complete';
		$status = new Status( Loader::load( array( $dir ) ), array( $dir ) );

		$rows = array_column( $status->relationships(), null, 'key' );
		$this->assertSame(
			array(
				'key'         => 'event_venue',
				'from'        => 'demo_event',
				'to'          => 'demo_venue',
				'cardinality' => 'one_to_many',
				'storage'     => 'meta',
				'required'    => false,
				'source'      => 'complete',
			),
			$rows['event_venue']
		);
		$this->assertTrue( $rows['event_related']['required'] );
	}

	/**
	 * Listings, forms, automations and options pages each get a row with a short summary and their source.
	 */
	public function test_section_rows() {
		$dir    = $this->fixtures() . '/valid/sections';
		$status = new Status( Loader::load( array( $dir ) ), array( $dir ) );

		$rows = array();
		foreach ( $status->sections() as $row ) {
			$this->assertSame( 'sections', $row['source'] );
			$rows[ $row['kind'] . ' ' . $row['key'] ] = $row['details'];
		}
		$this->assertSame(
			array(
				'listing talk'                => '4 filter(s), search',
				'form contact'                => 'entries only, 4 field(s)',
				'form submit_talk'            => 'create talk, 2 step(s)',
				'automation contact_received' => 'on form.submitted, 1 step(s)',
				'automation talk_followup'    => 'every day, 5 step(s)',
				'automation talk_inbound'     => 'webhook, 3 step(s), disabled',
				'automation talk_published'   => 'on record.status_changed, 3 step(s)',
				'automation talk_updated'     => 'on record.updated, 2 step(s), disabled',
				'options_page talk_settings'  => '7 field(s), edit_others_posts',
			),
			$rows
		);

		$this->assertSame( array(), ( new Status( Loader::load( array( $this->fixtures() . '/valid/complete' ) ) ) )->sections() );
	}

	/**
	 * Shadowed DB entries are listed; rejected errors are grouped by package directory, then the DB source.
	 */
	public function test_shadowed_and_rejected() {
		$valid   = dirname( __DIR__, 3 ) . '/examples/sifg';
		$invalid = $this->fixtures() . '/invalid/invalid-key';
		$db      = array(
			'content_types' => array(
				array(
					'format' => 1,
					'key'    => 'sifg_project',
					'labels' => array(
						'singular' => 'Shadow',
						'plural'   => 'Shadows',
					),
				),
				array( 'format' => 1 ),
			),
		);

		$status = new Status( Loader::load( array( $invalid, $valid ), $db ), array( $invalid, $valid ) );

		$this->assertSame(
			array(
				array(
					'kind' => 'content_type',
					'key'  => 'sifg_project',
					'by'   => 'sifg',
				),
			),
			$status->shadowed()
		);

		$rejected = $status->rejected();
		$this->assertSame( array( $invalid, Loader::DB_SOURCE ), array_column( $rejected, 'source' ) );

		$package_error = $rejected[0]['errors'][0];
		$this->assertSame( 'invalid_key', $package_error['code'] );
		$this->assertStringEndsWith( 'content-types/thing.json', $package_error['file'] );
		$this->assertSame( '/key', $package_error['pointer'] );
		$this->assertNotSame( '', $package_error['message'] );

		foreach ( $rejected[1]['errors'] as $error ) {
			$this->assertSame( Loader::DB_SOURCE, $error['file'] );
			$this->assertStringStartsWith( '/content_types/1', $error['pointer'] );
		}

		// The accepted package still loads.
		$this->assertSame( 'Projects', $status->content_types()[0]['label'] );
	}

	/**
	 * A missing package directory is reported under that directory.
	 */
	public function test_missing_package_is_grouped_under_its_directory() {
		$missing = $this->fixtures() . '/does-not-exist';
		$status  = new Status( Loader::load( array( $missing ) ), array( $missing ) );

		$rejected = $status->rejected();
		$this->assertCount( 1, $rejected );
		$this->assertSame( $missing, $rejected[0]['source'] );
		$this->assertSame( 'missing_package', $rejected[0]['errors'][0]['code'] );
	}
}
