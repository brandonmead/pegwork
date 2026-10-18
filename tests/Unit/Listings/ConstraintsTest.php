<?php
/**
 * Unit tests: the listing constraint translator.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Listings;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use PegworkListings\Constraints;
use WP_UnitTestCase;

/**
 * Constraints::to_query_args() and merge() on the `talk` type of the sections fixture.
 */
class ConstraintsTest extends WP_UnitTestCase {

	/**
	 * Loads the listings module classes.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 3 ) . '/modules/listings/module.php';
	}

	/**
	 * Registers the sections fixture.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 2 ) . '/fixtures/valid/sections' ) );
		Plugin::init();
	}

	/**
	 * Rebuilds the registry without the fixture.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Empty constraints still restrict to published records.
	 */
	public function test_empty_constraints_publish_only() {
		$this->assertSame( array( 'post_status' => 'publish' ), Constraints::to_query_args( 'talk', array() ) );
	}

	/**
	 * A type outside the registry gets no args.
	 */
	public function test_unknown_type() {
		$this->assertSame( array(), Constraints::to_query_args( 'post', array( 'search' => 'x' ) ) );
	}

	/**
	 * Taxonomies become tax_query clauses (slug, IN), AND across; bad slugs and foreign taxonomies drop.
	 */
	public function test_taxonomies() {
		$args = Constraints::to_query_args(
			'talk',
			array(
				'taxonomies' => array(
					'talk_track' => array( 'web', 'Bad Slug', 'web', '', 7, 'data' ),
					'talk_level' => 'beginner',
					'category'   => array( 'news' ),
					'nope'       => array( 'x' ),
				),
			)
		);

		$this->assertSame(
			array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'talk_track',
					'field'    => 'slug',
					'terms'    => array( 'web', 'data' ),
					'operator' => 'IN',
				),
				array(
					'taxonomy' => 'talk_level',
					'field'    => 'slug',
					'terms'    => array( 'beginner' ),
					'operator' => 'IN',
				),
			),
			$args['tax_query']
		);
	}

	/**
	 * At most MAX_VALUES slugs per taxonomy.
	 */
	public function test_taxonomy_value_cap() {
		$slugs = array_map( fn( $i ) => 'term-' . $i, range( 1, 30 ) );
		$args  = Constraints::to_query_args( 'talk', array( 'taxonomies' => array( 'talk_track' => $slugs ) ) );
		$this->assertCount( Constraints::MAX_VALUES, $args['tax_query'][0]['terms'] );
	}

	/**
	 * Boolean fields: true is `= '1'`; false is not set or not '1'.
	 */
	public function test_boolean_field() {
		$on = Constraints::to_query_args( 'talk', array( 'fields' => array( 'recorded' => true ) ) );
		$this->assertSame(
			array(
				'relation' => 'AND',
				array(
					'key'     => '_talk_recorded',
					'value'   => '1',
					'compare' => '=',
				),
			),
			$on['meta_query']
		);

		$this->assertSame( $on, Constraints::to_query_args( 'talk', array( 'fields' => array( 'recorded' => '1' ) ) ) );

		$off = Constraints::to_query_args( 'talk', array( 'fields' => array( 'recorded' => 'false' ) ) );
		$this->assertSame( 'OR', $off['meta_query'][0]['relation'] );
		$this->assertSame( 'NOT EXISTS', $off['meta_query'][0][0]['compare'] );
		$this->assertSame( '!=', $off['meta_query'][0][1]['compare'] );

		$this->assertArrayNotHasKey( 'meta_query', Constraints::to_query_args( 'talk', array( 'fields' => array( 'recorded' => 'yes' ) ) ) );
	}

	/**
	 * Select fields: one value is `=`, several `IN`; values outside the options drop.
	 */
	public function test_select_field() {
		$one = Constraints::to_query_args( 'talk', array( 'fields' => array( 'format' => 'keynote' ) ) );
		$this->assertSame(
			array(
				'key'     => '_talk_format',
				'value'   => 'keynote',
				'compare' => '=',
			),
			$one['meta_query'][0]
		);

		$many = Constraints::to_query_args( 'talk', array( 'fields' => array( 'format' => array( 'keynote', 'bogus', 'workshop', 'keynote' ) ) ) );
		$this->assertSame(
			array(
				'key'     => '_talk_format',
				'value'   => array( 'keynote', 'workshop' ),
				'compare' => 'IN',
			),
			$many['meta_query'][0]
		);

		$this->assertArrayNotHasKey( 'meta_query', Constraints::to_query_args( 'talk', array( 'fields' => array( 'format' => 'bogus' ) ) ) );
	}

	/**
	 * Private, unknown, post-column and non-filterable fields are never queryable.
	 */
	public function test_private_and_other_fields_drop() {
		$args = Constraints::to_query_args(
			'talk',
			array(
				'fields' => array(
					'speaker_email' => 'a@example.org',
					'notes'         => 'x',
					'title'         => 'x',
					'seats'         => 10,
					'held_on'       => '2026-10-18',
					'nope'          => true,
					0               => true,
				),
			)
		);
		$this->assertSame( array( 'post_status' => 'publish' ), $args );
	}

	/**
	 * Search becomes `s`: plain text, capped.
	 */
	public function test_search() {
		$args = Constraints::to_query_args( 'talk', array( 'search' => '  <b>open</b> data  ' ) );
		$this->assertSame( 'open data', $args['s'] );

		$long = Constraints::to_query_args( 'talk', array( 'search' => str_repeat( 'a', 300 ) ) );
		$this->assertSame( Constraints::MAX_SEARCH, strlen( $long['s'] ) );

		$this->assertArrayNotHasKey( 's', Constraints::to_query_args( 'talk', array( 'search' => array( 'x' ) ) ) );
	}

	/**
	 * `post__in` keeps positive IDs; an empty list matches nothing.
	 */
	public function test_post_in() {
		$this->assertSame( array( 5, 9 ), Constraints::to_query_args( 'talk', array( 'post__in' => array( '5', 9, -1, 'x', 5 ) ) )['post__in'] );
		$this->assertSame( array( 0 ), Constraints::to_query_args( 'talk', array( 'post__in' => array() ) )['post__in'] );
	}

	/**
	 * Merging ANDs with the Query Loop's own tax and meta queries, joins searches, intersects post__in.
	 */
	public function test_merge() {
		$ours = Constraints::to_query_args(
			'talk',
			array(
				'taxonomies' => array( 'talk_track' => array( 'web' ) ),
				'fields'     => array( 'recorded' => true ),
				'search'     => 'data',
				'post__in'   => array( 3, 4 ),
			)
		);
		$vars = Constraints::merge(
			array(
				'post_type'   => 'talk',
				'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query vars under test.
					array(
						'taxonomy' => 'talk_level',
						'terms'    => array( 1 ),
					),
				),
				's'           => 'open',
				'post__in'    => array( 4, 5 ),
				'post_status' => array( 'publish', 'private' ),
			),
			$ours
		);

		$this->assertSame( 'AND', $vars['tax_query']['relation'] );
		$this->assertSame( 'talk_level', $vars['tax_query'][0][0]['taxonomy'] );
		$this->assertSame( $ours['tax_query'], $vars['tax_query'][1] );
		$this->assertSame( $ours['meta_query'], $vars['meta_query'] );
		$this->assertSame( 'open data', $vars['s'] );
		$this->assertSame( array( 4 ), $vars['post__in'] );
		$this->assertSame( 'publish', $vars['post_status'] );

		$none = Constraints::merge( array( 'post__in' => array( 1 ) ), array( 'post__in' => array( 2 ) ) );
		$this->assertSame( array( 0 ), $none['post__in'] );

		$empty = Constraints::merge( array( 'tax_query' => array() ), $ours ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Query vars under test.
		$this->assertSame( $ours['tax_query'], $empty['tax_query'] );
	}

	/**
	 * Presets: field keys map to fields, post_in_option to fields and post_in_option to post__in.
	 */
	public function test_from_where() {
		$constraints = Constraints::from_where(
			'talk',
			array(
				'recorded'       => true,
				'post_in_option' => 'talk_settings.featured_talk',
			)
		);
		$this->assertSame( array( 'recorded' => true ), $constraints['fields'] );
		$this->assertSame( array(), $constraints['post__in'] ); // No published talk yet.
	}
}
