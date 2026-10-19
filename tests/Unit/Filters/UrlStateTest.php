<?php
/**
 * Unit tests: URL state parsing and constraints (filters module).
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Unit\Filters;

use PegworkFilters\UrlState;
use WP_UnitTestCase;

/**
 * UrlState is pure: a definition and query parameters in, state and constraints out.
 */
class UrlStateTest extends WP_UnitTestCase {

	/**
	 * Loads the module's classes.
	 */
	public static function wpSetUpBeforeClass() {
		require_once dirname( __DIR__, 3 ) . '/modules/filters/module.php';
	}

	/**
	 * The SI for Good Projects definition, plus a select field.
	 *
	 * @param bool $search Whether search is on.
	 */
	private static function definition( $search = true ) {
		$terms = fn( ...$slugs ) => array_map(
			fn( $slug ) => array(
				'value' => $slug,
				'label' => ucfirst( $slug ),
			),
			$slugs
		);
		return array(
			'type'    => 'sifg_project',
			'plural'  => 'Projects',
			'search'  => $search,
			'filters' => array(
				array(
					'key'      => 'project_type',
					'kind'     => 'taxonomy',
					'target'   => 'sifg_project_type',
					'multiple' => false,
					'label'    => 'Type',
					'options'  => $terms( 'nonprofit', 'school' ),
				),
				array(
					'key'      => 'impact',
					'kind'     => 'taxonomy',
					'target'   => 'sifg_impact',
					'multiple' => true,
					'label'    => 'Impact category',
					'options'  => $terms( 'sdg-04', 'sdg-13', 'sdg-15' ),
				),
				array(
					'key'      => 'volunteers',
					'kind'     => 'boolean',
					'target'   => 'needs_volunteers',
					'multiple' => false,
					'label'    => 'Needs volunteers',
					'options'  => array(),
				),
				array(
					'key'      => 'size',
					'kind'     => 'select',
					'target'   => 'team_size',
					'multiple' => false,
					'label'    => 'Size',
					'options'  => $terms( 'small', 'large' ),
				),
			),
		);
	}

	/**
	 * Both array forms parse to the same values; the comma form round-trips.
	 */
	public function test_comma_and_bracket_forms() {
		$comma   = UrlState::parse( self::definition(), array( 'impact' => 'sdg-04,sdg-13' ) );
		$bracket = UrlState::parse( self::definition(), array( 'impact' => array( 'sdg-04', 'sdg-13' ) ) );

		$this->assertSame( array( 'sdg-04', 'sdg-13' ), $comma['values']['impact'] );
		$this->assertSame( $comma, $bracket );
		$this->assertSame( array( 'impact' => 'sdg-04,sdg-13' ), UrlState::to_query( $comma ) );
		$this->assertSame( $comma, UrlState::parse( self::definition(), UrlState::to_query( $comma ) ) );
	}

	/**
	 * Every declared key is present; unset keys are empty lists.
	 */
	public function test_empty_state() {
		$state = UrlState::parse( self::definition(), array() );
		$this->assertSame(
			array(
				'q'      => '',
				'values' => array(
					'project_type' => array(),
					'impact'       => array(),
					'volunteers'   => array(),
					'size'         => array(),
				),
			),
			$state
		);
		$this->assertFalse( UrlState::active( $state ) );
		$this->assertSame( array(), UrlState::to_constraints( self::definition(), $state ) );
	}

	/**
	 * Unknown values, unknown keys, private fields, nested arrays and non-strings are dropped.
	 */
	public function test_bad_values_dropped() {
		$state = UrlState::parse(
			self::definition(),
			array(
				'project_type'                => 'nonprofit,school',
				'impact'                      => array( 'sdg-99', 'SDG-04', array( 'sdg-13' ), 13, 'sdg-15', 'sdg-15', ' sdg-04 ' ),
				'volunteers'                  => '2',
				'size'                        => 'huge',
				'contact_email'               => 'a@example.org',
				'_sifg_project_contact_email' => 'a@example.org',
				'meta_key'                    => '_sifg_project_contact_email', // phpcs:ignore WordPress.DB.SlowDBQuery -- A URL parameter, not a query.
				'paged'                       => '3',
			)
		);

		$this->assertSame( array( 'nonprofit' ), $state['values']['project_type'], 'single-value filters keep the first valid value' );
		$this->assertSame( array( 'sdg-15', 'sdg-04' ), $state['values']['impact'] );
		$this->assertSame( array(), $state['values']['volunteers'] );
		$this->assertSame( array(), $state['values']['size'] );
		$this->assertSame( array( 'q', 'values' ), array_keys( $state ) );
		$this->assertSame( array( 'project_type', 'impact', 'volunteers', 'size' ), array_keys( $state['values'] ) );
	}

	/**
	 * A boolean takes only `1`.
	 */
	public function test_boolean() {
		foreach ( array( '1', array( '1' ), '1,1' ) as $raw ) {
			$this->assertSame( array( '1' ), UrlState::parse( self::definition(), array( 'volunteers' => $raw ) )['values']['volunteers'] );
		}
		foreach ( array( '0', 'true', 'on', '', ' ' ) as $raw ) {
			$this->assertSame( array(), UrlState::parse( self::definition(), array( 'volunteers' => $raw ) )['values']['volunteers'], $raw );
		}
	}

	/**
	 * At most 20 values per filter.
	 */
	public function test_value_cap() {
		$options    = array_map(
			fn( $i ) => array(
				'value' => "t$i",
				'label' => "T$i",
			),
			range( 1, 30 )
		);
		$definition = self::definition();

		$definition['filters'][1]['options'] = $options;
		$state                               = UrlState::parse( $definition, array( 'impact' => implode( ',', array_column( $options, 'value' ) ) ) );
		$this->assertCount( UrlState::MAX_VALUES, $state['values']['impact'] );
		$this->assertSame( 't20', $state['values']['impact'][19] );
	}

	/**
	 * Search: plain text, at most 100 characters, only when the listing enables it.
	 */
	public function test_search() {
		$state = UrlState::parse( self::definition(), array( 'q' => '  <b>data</b> tools ' ) );
		$this->assertSame( 'data tools', $state['q'] );
		$this->assertTrue( UrlState::active( $state ) );

		$long = UrlState::parse( self::definition(), array( 'q' => str_repeat( 'é', 150 ) ) );
		$this->assertSame( 100, mb_strlen( $long['q'] ) );

		$this->assertSame( '', UrlState::parse( self::definition( false ), array( 'q' => 'data' ) )['q'] );
		$this->assertSame( '', UrlState::parse( self::definition(), array( 'q' => array( 'data' ) ) )['q'] );
	}

	/**
	 * Constraints: taxonomies by slug, boolean true, select value, search.
	 */
	public function test_to_constraints() {
		$state = UrlState::parse(
			self::definition(),
			array(
				'project_type' => 'nonprofit',
				'impact'       => 'sdg-04,sdg-13',
				'volunteers'   => '1',
				'size'         => 'small',
				'q'            => 'data',
			)
		);
		$this->assertSame(
			array(
				'taxonomies' => array(
					'sifg_project_type' => array( 'nonprofit' ),
					'sifg_impact'       => array( 'sdg-04', 'sdg-13' ),
				),
				'fields'     => array(
					'needs_volunteers' => true,
					'team_size'        => 'small',
				),
				'search'     => 'data',
			),
			UrlState::to_constraints( self::definition(), $state )
		);
	}

	/**
	 * Merge: existing constraints (presets) win per target; searches join.
	 */
	public function test_merge() {
		$merged = UrlState::merge(
			array(
				'fields'   => array( 'needs_volunteers' => false ),
				'post__in' => array( 4 ),
				'search'   => 'water',
			),
			array(
				'taxonomies' => array( 'sifg_impact' => array( 'sdg-04' ) ),
				'fields'     => array(
					'needs_volunteers' => true,
					'team_size'        => 'small',
				),
				'search'     => 'data',
			)
		);
		$this->assertSame(
			array(
				'fields'     => array(
					'needs_volunteers' => false,
					'team_size'        => 'small',
				),
				'post__in'   => array( 4 ),
				'search'     => 'water data',
				'taxonomies' => array( 'sifg_impact' => array( 'sdg-04' ) ),
			),
			$merged
		);
	}
}
