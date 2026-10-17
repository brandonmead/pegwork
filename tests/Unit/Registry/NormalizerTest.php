<?php
/**
 * Unit tests for the normalizer.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Registry;

use Pegwork\Registry\Loader;
use Pegwork\Registry\Normalizer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests Normalizer.
 */
class NormalizerTest extends TestCase {

	/**
	 * Defaults are filled for content types, fields and taxonomy usage; empty objects dropped.
	 */
	public function test_content_type_defaults() {
		$normalized = Normalizer::normalize(
			'content_type',
			array(
				'key'        => 'thing',
				'format'     => 1,
				'labels'     => array(
					'singular' => 'Thing',
					'plural'   => 'Things',
				),
				'taxonomies' => array( array( 'key' => 'topic' ) ),
				'fields'     => array(
					array(
						'type'     => 'text',
						'key'      => 'a',
						'label'    => 'A',
						'validate' => array(),
						'wp'       => array(),
					),
				),
				'wp'         => array(),
			)
		);

		$this->assertSame(
			array(
				'fields'     => array(
					array(
						'key'        => 'a',
						'label'      => 'A',
						'required'   => false,
						'type'       => 'text',
						'visibility' => 'public',
					),
				),
				'format'     => 1,
				'key'        => 'thing',
				'labels'     => array(
					'plural'   => 'Things',
					'singular' => 'Thing',
				),
				'public'     => true,
				'taxonomies' => array(
					array(
						'key'      => 'topic',
						'required' => false,
						'select'   => 'multi',
					),
				),
			),
			$normalized
		);
	}

	/**
	 * Taxonomy and relationship defaults.
	 */
	public function test_taxonomy_and_relationship_defaults() {
		$taxonomy = Normalizer::normalize( 'taxonomy', array( 'key' => 'topic' ) );
		$this->assertSame(
			array(
				'hierarchical' => false,
				'key'          => 'topic',
				'locked_terms' => false,
				'terms'        => array(),
			),
			$taxonomy
		);

		$relationship = Normalizer::normalize( 'relationship', array( 'key' => 'rel' ) );
		$this->assertSame( 'meta', $relationship['storage'] );
	}

	/**
	 * Object keys are sorted at every depth and list order is kept.
	 */
	public function test_sorts_object_keys_and_keeps_list_order() {
		$normalized = Normalizer::normalize(
			'taxonomy',
			array(
				'terms' => array(
					array(
						'slug' => 'zeta',
						'name' => 'Zeta',
					),
					array(
						'slug' => 'alpha',
						'name' => 'Alpha',
					),
				),
				'key'   => 'topic',
			)
		);

		$this->assertSame( array( 'hierarchical', 'key', 'locked_terms', 'terms' ), array_keys( $normalized ) );
		$this->assertSame( array( 'zeta', 'alpha' ), array_column( $normalized['terms'], 'slug' ) );
		$this->assertSame( array( 'name', 'slug' ), array_keys( $normalized['terms'][0] ) );
	}

	/**
	 * Normalizing the canonical form again changes nothing, byte for byte.
	 */
	public function test_idempotent_on_fixtures() {
		$root   = dirname( __DIR__, 3 );
		$result = Loader::load( array( $root . '/examples/sifg', $root . '/tests/fixtures/valid/complete' ) );
		$this->assertTrue( $result->is_valid() );

		$collections = array(
			'content_type' => $result->registry->content_types(),
			'taxonomy'     => $result->registry->taxonomies(),
			'relationship' => $result->registry->relationships(),
		);
		foreach ( $collections as $kind => $objects ) {
			$this->assertNotEmpty( $objects );
			foreach ( $objects as $object ) {
				$this->assertSame( wp_json_encode( $object ), wp_json_encode( Normalizer::normalize( $kind, $object ) ) );
			}
		}
	}
}
