<?php
/**
 * Integration tests: the filters module on the SI for Good example.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Filters;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\Taxonomies;
use Pegwork\Plugin;
use PegworkFilters\Count;
use PegworkFilters\Definition;
use PegworkFilters\FiltersBlock;
use PegworkFilters\Hooks;
use PegworkListings\Patterns;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * URL filtering on the archive and on Pegwork Query Loops, the block markup, the pattern additions, and
 * the private-field rules.
 */
class FiltersModuleTest extends WP_UnitTestCase {

	/**
	 * Contact email used to detect leaks.
	 */
	private const EMAIL = 'secret-contact@example.org';

	/**
	 * Projects by title: [ type, status, impact[], volunteers, extra words ].
	 */
	private const PROJECTS = array(
		'Alpha'   => array( 'nonprofit', 'active', array( 'sdg-04' ), true, 'data' ),
		'Bravo'   => array( 'nonprofit', 'active', array( 'sdg-13' ), false, 'data' ),
		'Charlie' => array( 'nonprofit', 'paused', array( 'sdg-04', 'sdg-13' ), true, 'river' ),
		'Delta'   => array( 'corporation', 'active', array( 'sdg-15' ), true, 'data' ),
		'Echo'    => array( 'corporation', 'paused', array(), false, 'garden' ),
	);

	/**
	 * Project IDs by title.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Registers examples/sifg, boots listings and filters, seeds the projects.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 3 ) . '/examples/sifg' ) );
		Plugin::init();
		$this->set_permalink_structure( '/%postname%/' );
		Plugin::init();
		flush_rewrite_rules( false );
		Taxonomies::seed( pegwork_registry() );

		foreach ( array( 'listings', 'filters' ) as $slug ) {
			$module = require dirname( __DIR__, 3 ) . "/modules/$slug/module.php";
			$module->boot();
		}

		$this->ids = array();
		$i         = 0;
		foreach ( self::PROJECTS as $title => list( $type, $status, $impact, $volunteers, $words ) ) {
			$id = self::factory()->post->create(
				array(
					'post_type'    => 'sifg_project',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => "<p>About $title and $words.</p>",
					'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01' ) + ( ++$i ) * HOUR_IN_SECONDS ),
				)
			);
			update_post_meta( $id, '_sifg_project_contact_email', self::EMAIL );
			update_post_meta( $id, '_sifg_project_needs_volunteers', $volunteers ? '1' : '' );
			update_post_meta( $id, '_sifg_project_last_verified', '2026-10-05' );
			wp_set_object_terms( $id, $type, 'sifg_project_type' );
			wp_set_object_terms( $id, $status, 'sifg_project_status' );
			wp_set_object_terms( $id, $impact, 'sifg_impact' );
			$this->ids[ $title ] = $id;
		}
		$_GET = array();
	}

	/**
	 * Clears request state and rebuilds the registry.
	 */
	public function tear_down() {
		$_GET = array();
		foreach ( Patterns::KINDS as $kind ) {
			$name = Patterns::name( 'sifg_project', $kind );
			if ( WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_pattern( $name );
			}
		}
		unset( $GLOBALS['post'] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Titles rendered by the post template, in order.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private static function titles( $html ) {
		preg_match_all( '#<h3[^>]*><a [^>]*>([^<]+)</a></h3>#', $html, $matches );
		return $matches[1];
	}

	/**
	 * The expected titles for URL parameters, newest first, from the fixture table.
	 *
	 * @param array<string, mixed> $params Parameters.
	 * @return string[]
	 */
	private static function expected( array $params ) {
		$titles = array();
		foreach ( self::PROJECTS as $title => list( $type, $status, $impact, $volunteers, $words ) ) {
			if ( isset( $params['project_type'] ) && $params['project_type'] !== $type ) {
				continue;
			}
			if ( isset( $params['project_status'] ) && $params['project_status'] !== $status ) {
				continue;
			}
			if ( isset( $params['impact'] ) && array() === array_intersect( explode( ',', $params['impact'] ), $impact ) ) {
				continue;
			}
			if ( isset( $params['volunteers'] ) && ! $volunteers ) {
				continue;
			}
			if ( isset( $params['q'] ) && ! str_contains( "$title $words", $params['q'] ) ) {
				continue;
			}
			$titles[] = $title;
		}
		return array_reverse( $titles );
	}

	/**
	 * Every subset of the scenario parameters.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function combinations() {
		$all   = array(
			'project_type'   => 'nonprofit',
			'project_status' => 'active',
			'impact'         => 'sdg-04,sdg-13',
			'volunteers'     => '1',
			'q'              => 'data',
		);
		$keys  = array_keys( $all );
		$list  = array();
		$masks = 1 << count( $keys );
		for ( $mask = 0; $mask < $masks; $mask++ ) {
			$params = array();
			foreach ( $keys as $bit => $key ) {
				if ( $mask & ( 1 << $bit ) ) {
					$params[ $key ] = $all[ $key ];
				}
			}
			$list[] = $params;
		}
		return $list;
	}

	/**
	 * The archive main query with the filters block above an inherit Query Loop, for URL parameters.
	 *
	 * @param array<string, string> $params Parameters.
	 * @return string HTML.
	 */
	private function archive( array $params ) {
		$_GET = $params;
		$this->go_to( add_query_arg( $params, get_post_type_archive_link( 'sifg_project' ) ) );
		$this->assertTrue( is_post_type_archive( 'sifg_project' ) );
		$this->assertFalse( is_search(), 'the archive template is kept' );
		return do_blocks(
			'<!-- wp:pegwork/filters /-->'
			. '<!-- wp:query {"queryId":1,"query":{"inherit":true}} --><div class="wp-block-query"><!-- wp:post-template -->'
			. Patterns::card( 'sifg_project' )
			. '<!-- /wp:post-template --></div><!-- /wp:query -->'
		);
	}

	/**
	 * The generated directory pattern on a page, for URL parameters.
	 *
	 * @param array<string, string> $params Parameters.
	 * @return string HTML.
	 */
	private function directory( array $params ) {
		$_GET = $params;
		return do_blocks( Hooks::pattern( Patterns::pattern( 'sifg_project', 'directory' ), 'sifg_project', 'directory' )['content'] );
	}

	/**
	 * The count the filters block shows.
	 *
	 * @param string $html HTML.
	 */
	private static function count_text( $html ) {
		preg_match( '#<p class="pegwork-filters__count" role="status"[^>]*>([^<]*)</p>#', $html, $match );
		return $match[1] ?? null;
	}

	/**
	 * Every combination of type, status, impact (any of two), Needs volunteers and search filters the
	 * archive by GET, with the count matching.
	 */
	public function test_archive_every_combination() {
		foreach ( self::combinations() as $params ) {
			$html     = $this->archive( $params );
			$expected = self::expected( $params );
			$label    = wp_json_encode( $params );
			$this->assertSame( $expected, self::titles( $html ), $label );
			$this->assertSame( FiltersBlock::count_text( count( $expected ) ), self::count_text( $html ), $label );
		}
	}

	/**
	 * Every combination filters the directory pattern (a Pegwork Query Loop with `filters: true`).
	 */
	public function test_directory_every_combination() {
		foreach ( self::combinations() as $params ) {
			$html     = $this->directory( $params );
			$expected = self::expected( $params );
			$label    = wp_json_encode( $params );
			$this->assertSame( $expected, self::titles( $html ), $label );
			$this->assertSame( FiltersBlock::count_text( count( $expected ) ), self::count_text( $html ), $label );
		}
	}

	/**
	 * The comma form and the bracket form give the same results.
	 */
	public function test_bracket_form() {
		$this->assertSame(
			self::titles( $this->directory( array( 'impact' => 'sdg-04,sdg-15' ) ) ),
			self::titles( $this->directory( array( 'impact' => array( 'sdg-04', 'sdg-15' ) ) ) )
		);
		$this->assertSame( array( 'Delta', 'Charlie', 'Alpha' ), self::titles( $this->directory( array( 'impact' => array( 'sdg-04', 'sdg-15' ) ) ) ) );
	}

	/**
	 * Bad and private parameters change nothing, and the contact email never appears.
	 */
	public function test_bad_and_private_params_ignored() {
		$all = self::titles( $this->directory( array() ) );
		$this->assertCount( 5, $all );
		// phpcs:disable WordPress.DB.SlowDBQuery -- URL parameters named like query vars, not a query.

		$bad = array(
			'contact_email'               => self::EMAIL,
			'_sifg_project_contact_email' => self::EMAIL,
			'meta_key'                    => '_sifg_project_contact_email',
			'meta_value'                  => self::EMAIL,
			'sifg_project_type'           => 'corporation',
			'impact'                      => 'sdg-99,<script>',
			'project_type'                => array( array( 'corporation' ) ),
			'volunteers'                  => '2',
			'post_status'                 => 'draft',
			'q'                           => array( 'data' ),
		);

		$cases = array( $bad, array( 'contact_email' => 'x' ), array( 'meta_key' => '_sifg_project_needs_volunteers' ) );
		// phpcs:enable WordPress.DB.SlowDBQuery
		foreach ( $cases as $params ) {
			$html = $this->directory( $params );
			$this->assertSame( $all, self::titles( $html ), wp_json_encode( $params ) );
			$this->assertStringNotContainsString( self::EMAIL, $html );
			$this->assertStringNotContainsString( '<script>', $html );
			$this->assertSame( FiltersBlock::count_text( 5 ), self::count_text( $html ) );
		}

		$html = $this->archive( array( 'contact_email' => self::EMAIL ) );
		$this->assertCount( 5, self::titles( $html ) );
	}

	/**
	 * Filters apply to loops with `pegwork.filters` only; plain and `filters: false` loops ignore the URL.
	 */
	public function test_only_filterable_loops() {
		$_GET  = array( 'project_type' => 'corporation' );
		$loop  = '<!-- wp:query {"queryId":7,"query":{"perPage":10,"postType":"sifg_project","inherit":false}%s} --><div class="wp-block-query"><!-- wp:post-template -->'
			. Patterns::card( 'sifg_project' ) . '<!-- /wp:post-template --></div><!-- /wp:query -->';
		$plain = do_blocks( sprintf( $loop, '' ) );
		$off   = do_blocks( sprintf( $loop, ',"pegwork":{}' ) );
		$on    = do_blocks( sprintf( $loop, ',"pegwork":{"filters":true}' ) );

		$this->assertCount( 5, self::titles( $plain ) );
		$this->assertCount( 5, self::titles( $off ) );
		$this->assertSame( array( 'Echo', 'Delta' ), self::titles( $on ) );

		// `where` presets win over the URL for the same field.
		$_GET   = array( 'volunteers' => '1' );
		$preset = do_blocks( sprintf( $loop, ',"pegwork":{"filters":true,"where":{"needs_volunteers":false}}' ) );
		$this->assertSame( array( 'Echo', 'Bravo' ), self::titles( $preset ) );
	}

	/**
	 * The block markup: a labelled GET search form, fieldsets with legends, the Clear all link, the count in
	 * a status region, Interactivity directives, and escaping.
	 */
	public function test_block_markup() {
		$this->go_to( get_post_type_archive_link( 'sifg_project' ) );
		$html = do_blocks( '<!-- wp:pegwork/filters /-->' );
		$base = get_post_type_archive_link( 'sifg_project' );

		$this->assertStringContainsString( 'class="wp-block-pegwork-filters"', $html );
		$this->assertStringContainsString( 'data-wp-interactive="pegwork/filters"', $html );
		$this->assertMatchesRegularExpression( '#<form class="pegwork-filters__form" method="get" role="search" action="' . preg_quote( esc_url( $base ), '#' ) . '" aria-label="Filter projects"#', $html );
		$this->assertMatchesRegularExpression( '#<label class="pegwork-filters__label" for="(pegwork-filters-\d+-q)">Search projects</label><input type="search" id="\1" name="q" value=""#', $html );
		$this->assertStringContainsString( '<legend class="pegwork-filters__label">Type</legend>', $html );
		$this->assertStringContainsString( '<legend class="pegwork-filters__label">Status</legend>', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio" id="(pegwork-filters-\d+-project_type-0)" name="project_type" value="" checked=\'checked\'><label for="\1">Any</label>#', $html );
		$this->assertStringContainsString( '<summary class="pegwork-filters__label" data-wp-text="context.summaries.impact">Impact category</summary>', $html );
		$this->assertStringContainsString( '<legend class="screen-reader-text">Impact category</legend>', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="(pegwork-filters-\d+-impact-0)" name="impact\[\]" value="sdg-01"><label for="\1">No Poverty</label>#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox" id="(pegwork-filters-\d+-volunteers)" name="volunteers" value="1"><label for="\1">Needs volunteers</label>#', $html );
		$this->assertStringContainsString( '<button type="submit" class="pegwork-filters__apply wp-element-button">Apply filters</button>', $html );
		$this->assertMatchesRegularExpression( '#<a class="pegwork-filters__clear" href="' . preg_quote( esc_url( $base ), '#' ) . '"[^>]* hidden>Clear all</a>#', $html );
		$this->assertStringContainsString( '<p class="pegwork-filters__count" role="status" data-wp-text="context.countText">5 results</p>', $html );
		$this->assertStringNotContainsString( 'contact', $html );

		// SDG terms keep the registry order.
		$this->assertLessThan( strpos( $html, 'value="sdg-10"' ), strpos( $html, 'value="sdg-02"' ) );

		// Active filters: values checked, summary counts, Clear all shown, search value escaped.
		$params = array(
			'impact'       => 'sdg-04,sdg-13',
			'project_type' => 'corporation',
			'volunteers'   => '1',
			'q'            => '"><b>x',
		);
		// go_to() fills $_GET from the URL.
		$this->go_to( add_query_arg( urlencode_deep( $params ), $base ) );
		$html = do_blocks( '<!-- wp:pegwork/filters /-->' );
		$this->assertStringContainsString( 'Impact category, 2 selected</summary>', $html );
		$this->assertMatchesRegularExpression( '#name="impact\[\]" value="sdg-04" checked=\'checked\'#', $html );
		$this->assertMatchesRegularExpression( '#name="project_type" value="corporation" checked=\'checked\'#', $html );
		$this->assertMatchesRegularExpression( '#name="volunteers" value="1" checked=\'checked\'#', $html );
		$this->assertMatchesRegularExpression( '#<a class="pegwork-filters__clear" href="[^"]+"[^>]*>Clear all</a>#', $html );
		$this->assertDoesNotMatchRegularExpression( '#pegwork-filters__clear[^>]* hidden>#', $html );
		$this->assertStringContainsString( 'name="q" value="&quot;&gt;x"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	/**
	 * Attributes choose filters (in their order) and turn the search off; unknown types render nothing.
	 */
	public function test_block_attributes() {
		$html = do_blocks( '<!-- wp:pegwork/filters {"postType":"sifg_project","filters":["volunteers","project_type","nope"],"showSearch":false} /-->' );
		$this->assertStringNotContainsString( 'name="q"', $html );
		$this->assertStringNotContainsString( 'name="impact[]"', $html );
		$this->assertLessThan( strpos( $html, 'name="project_type"' ), strpos( $html, 'name="volunteers"' ) );

		$this->assertSame( '', do_blocks( '<!-- wp:pegwork/filters {"postType":"sifg_story"} /-->' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:pegwork/filters {"postType":"page"} /-->' ) );
		// No postType outside an archive: the only type with a listing.
		$this->assertStringContainsString( 'aria-label="Filter projects"', do_blocks( '<!-- wp:pegwork/filters /-->' ) );
	}

	/**
	 * A filterable loop of Projects that need volunteers (3 of the 5 fixtures), as block markup.
	 */
	private static function volunteer_loop(): string {
		return '<!-- wp:query {"queryId":777,"query":{"perPage":10,"postType":"sifg_project","inherit":false},"pegwork":{"filters":true,"where":{"needs_volunteers":true}}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->';
	}

	/**
	 * The count follows a loop that sits in a synced pattern (core/block) or a template part, including its
	 * `where` preset, and a reference cycle ends at the depth limit instead of looping.
	 */
	public function test_count_finds_loop_in_synced_pattern_and_template_part() {
		$synced = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Volunteer directory',
				'post_content' => self::volunteer_loop(),
			)
		);
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Synced directory',
				'post_content' => '<!-- wp:pegwork/filters /--><!-- wp:block {"ref":' . $synced . '} /-->',
			)
		);
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( 777, Count::bound_loop( 'sifg_project' )['queryId'] );
		$this->assertSame( FiltersBlock::count_text( 3 ), self::count_text( do_blocks( get_post( $page )->post_content ) ) );

		// A template part (database copy), referenced from the current template.
		$part = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_name'    => 'volunteer-directory',
				'post_title'   => 'Volunteer directory',
				'post_content' => self::volunteer_loop(),
			)
		);
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		$GLOBALS['_wp_current_template_content'] = '<!-- wp:template-part {"slug":"volunteer-directory","theme":"' . get_stylesheet() . '"} /-->'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's global for the template being rendered.
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 777, Count::bound_loop( 'sifg_project' )['queryId'] );
		$this->assertSame( 3, Count::for_type( 'sifg_project' ) );

		// A synced pattern that contains itself: no loop, no endless recursion.
		$cycle = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
				'post_title'  => 'Cycle',
			)
		);
		wp_update_post(
			array(
				'ID'           => $cycle,
				'post_content' => '<!-- wp:block {"ref":' . $cycle . '} /-->',
			)
		);
		$GLOBALS['_wp_current_template_content'] = '<!-- wp:block {"ref":' . $cycle . '} /-->'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's global for the template being rendered.
		$this->assertNull( Count::bound_loop( 'sifg_project' ) );
		unset( $GLOBALS['_wp_current_template_content'] );
	}

	/**
	 * `collapseOnSmall` wraps the fields and actions in a details: off by default, closed with no filter,
	 * closed too with the active count in the summary (the search and each shown filter with a value), and
	 * the result count before the disclosure so it shows on a phone without opening it.
	 */
	public function test_collapse_on_small_screens() {
		$base = get_post_type_archive_link( 'sifg_project' );
		$this->go_to( $base );
		$this->assertStringNotContainsString( '<details class="pegwork-filters__collapse"', do_blocks( '<!-- wp:pegwork/filters /-->' ) );
		$this->assertStringNotContainsString( 'collapseLabel', do_blocks( '<!-- wp:pegwork/filters /-->' ) );

		$html = do_blocks( '<!-- wp:pegwork/filters {"collapseOnSmall":true} /-->' );
		$this->assertStringContainsString( '<details class="pegwork-filters__collapse"><summary class="pegwork-filters__collapse-summary" data-wp-text="context.collapseLabel">Filters</summary><div class="pegwork-filters__fields">', $html );
		$this->assertMatchesRegularExpression( '#<p class="pegwork-filters__count"[^>]*>[^<]*</p><form [^>]*><details #', $html, 'the count comes before the disclosure' );
		$this->assertStringEndsWith( '</div></details></form></div>', $html );

		$params = array(
			'q'          => 'data',
			'volunteers' => '1',
			'impact'     => 'sdg-04,sdg-13',
		);
		$this->go_to( add_query_arg( urlencode_deep( $params ), $base ) );
		$html = do_blocks( '<!-- wp:pegwork/filters {"collapseOnSmall":true} /-->' );
		$this->assertStringContainsString( '<details class="pegwork-filters__collapse">', $html, 'a filtered URL stays collapsed' );
		$this->assertStringNotContainsString( ' open', $html );
		$this->assertStringContainsString( '>Filters, 3 active</summary>', $html );
		$this->assertLessThan( strpos( $html, '<details' ), strpos( $html, 'pegwork-filters__count' ) );

		// Only shown controls count: no search box, and only the volunteers filter.
		$html = do_blocks( '<!-- wp:pegwork/filters {"collapseOnSmall":true,"showSearch":false,"filters":["volunteers"]} /-->' );
		$this->assertStringContainsString( '>Filters, 1 active</summary>', $html );
	}

	/**
	 * Base URL: the page permalink without paging or filter params; plain permalinks keep their own
	 * parameter as a hidden field (a GET form drops the action's query string).
	 */
	public function test_base_url_and_paging() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Directory',
				'post_content' => Hooks::pattern( Patterns::pattern( 'sifg_project', 'directory' ), 'sifg_project', 'directory' )['content'],
			)
		);
		$this->go_to( get_permalink( $page ) );
		$this->assertSame( get_permalink( $page ), FiltersBlock::base_url( 'sifg_project' ) );

		// Pagination keeps the filters (core keeps the query string); the form never carries paging.
		update_post_meta( $this->ids['Alpha'], '_sifg_project_needs_volunteers', '1' );
		$query_id = parse_blocks( Patterns::directory( 'sifg_project' ) )[0]['attrs']['queryId'];
		for ( $i = 1; $i <= 12; $i++ ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => 'sifg_project',
					'post_status' => 'publish',
					'post_title'  => "Extra $i",
					'post_date'   => '2025-01-01 00:00:00',
				)
			);
			update_post_meta( $id, '_sifg_project_needs_volunteers', '1' );
		}
		$params = array(
			'volunteers'                  => '1',
			"query-$query_id-page"        => '2',
			'_sifg_project_contact_email' => 'x',
		);
		$this->go_to( add_query_arg( $params, get_permalink( $page ) ) );
		$html = do_blocks( get_post( $page )->post_content );
		$this->assertMatchesRegularExpression( '#<a [^>]*href="[^"]*volunteers=1[^"]*"[^>]*class="wp-block-query-pagination-previous"#', $html, 'the previous page link keeps the filter' );
		$this->assertMatchesRegularExpression( '#action="' . preg_quote( esc_url( get_permalink( $page ) ), '#' ) . '"#', $html, 'the form starts again from page 1' );
		$this->assertSame( FiltersBlock::count_text( 15 ), self::count_text( $html ) );

		// The bound loop is found in the page content, so its own settings count.
		$this->assertSame( $query_id, Count::bound_loop( 'sifg_project' )['queryId'] );

		// No-results: the pattern's Clear all link points at the base URL.
		$this->go_to( add_query_arg( 'q', 'nothing-matches-this', get_permalink( $page ) ) );
		$html = do_blocks( get_post( $page )->post_content );
		$this->assertStringContainsString( 'No projects match your search or filters. <a class="pegwork-filters-clear" href="' . esc_url( get_permalink( $page ) ) . '">Clear all filters</a>', $html );
		$this->assertSame( 'No results', self::count_text( $html ) );

		// Plain permalinks.
		$this->set_permalink_structure( '' );
		Plugin::init();
		$this->go_to( get_permalink( $page ) );
		$html = do_blocks( '<!-- wp:pegwork/filters /-->' );
		$this->assertStringContainsString( 'action="' . esc_url( home_url( '/' ) ) . '"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page_id" value="' . $page . '">', $html );
	}

	/**
	 * The directory pattern gains the filters block above its Query Loop; the variation stays unchanged.
	 */
	public function test_directory_pattern() {
		// set_up() booted listings after init, so its patterns registered before the filters hook existed.
		unregister_block_pattern( 'pegwork/sifg_project-directory' );
		Patterns::register_patterns();
		$pattern = WP_Block_Patterns_Registry::get_instance()->get_registered( 'pegwork/sifg_project-directory' );
		$blocks  = array_values( array_filter( parse_blocks( $pattern['content'] ), fn( $b ) => null !== $b['blockName'] ) );

		$this->assertSame( 'pegwork/filters', $blocks[0]['blockName'] );
		$this->assertSame(
			array(
				'postType' => 'sifg_project',
				'align'    => 'wide',
			),
			$blocks[0]['attrs']
		);
		$this->assertSame( 'core/query', $blocks[1]['blockName'] );
		$this->assertStringContainsString( '<a class="pegwork-filters-clear" href="?">Clear all filters</a>', $pattern['content'] );

		// Other kinds and already-added filters are left alone.
		$this->assertSame( array( 'content' => 'x' ), Hooks::pattern( array( 'content' => 'x' ), 'sifg_project', 'card' ) );
		$this->assertSame( $pattern, Hooks::pattern( $pattern, 'sifg_project', 'directory' ) );
	}

	/**
	 * The card's meta lines share one size; Last verified reads "Verified Oct 2026".
	 */
	public function test_card_meta_lines() {
		$card = Patterns::card( 'sifg_project' );
		$this->assertStringContainsString( '"args":{"key":"last_verified","format":"M Y","prefix":"Verified "}}}},"fontSize":"small"', $card );
		$this->assertStringContainsString( '<!-- wp:post-terms {"term":"sifg_project_type","prefix":"Type: ","fontSize":"small"} /-->', $card );
		$this->assertStringContainsString( '<p class="pegwork-badge has-small-font-size">Needs volunteers</p>', $card );
		$this->assertSame( 'Verified ', Patterns::card_date_prefix( 'Last verified' ) );
		$this->assertSame( 'Founded: ', Patterns::card_date_prefix( 'Founded' ) );

		$html = $this->directory( array( 'project_type' => 'corporation' ) );
		$this->assertMatchesRegularExpression( '#<p class="[^"]*has-small-font-size[^"]*">Verified Oct 2026</p>#', $html );
	}

	/**
	 * Interactivity state: translated strings and the directory count; definition skips private fields.
	 */
	public function test_state_and_definition() {
		$_GET = array( 'volunteers' => '1' );
		do_blocks( '<!-- wp:pegwork/filters {"postType":"sifg_project"} /-->' );
		$state = wp_interactivity_state( 'pegwork/filters' );
		$this->assertSame( 3, $state['directories']['sifg_project']['count'] );
		$this->assertSame( '3 results', $state['directories']['sifg_project']['countText'] );
		$this->assertSame( '%1$s, %2$s selected', $state['strings']['selectedOther'] );

		$definition = Definition::for_type( 'sifg_project' );
		$this->assertSame( array( 'project_type', 'project_status', 'impact', 'volunteers' ), array_column( $definition['filters'], 'key' ) );
		$this->assertSame( array( false, false, true, false ), array_column( $definition['filters'], 'multiple' ) );
		$this->assertSame( 'Impact category', $definition['filters'][2]['label'], 'label from the taxonomy at runtime' );
		$this->assertNull( Definition::for_type( 'sifg_story' ) );
		$this->assertNull( Definition::for_type( 'nope' ) );
	}

	/**
	 * The render function works outside blocks (a theme template or shortcode): a plain GET form with the
	 * server values already in place.
	 */
	public function test_render_outside_blocks() {
		$this->go_to( add_query_arg( 'volunteers', '1', get_post_type_archive_link( 'sifg_project' ) ) );
		$html = FiltersBlock::render_block( array( 'postType' => 'sifg_project' ) );

		$this->assertStringStartsWith( '<div class="wp-block-pegwork-filters"', $html );
		$this->assertStringContainsString( 'method="get"', $html );
		$this->assertMatchesRegularExpression( '#name="volunteers" value="1" checked=\'checked\'#', $html );
		$this->assertStringContainsString( 'role="status" data-wp-text="context.countText">3 results</p>', $html );
		$this->assertDoesNotMatchRegularExpression( '#pegwork-filters__clear[^>]* hidden>#', $html );
		$this->assertSame( '', FiltersBlock::render_block( array( 'postType' => 'page' ) ) );
	}

	/**
	 * No role sees the contact email in any filtered render; the block registers with interactivity.
	 */
	public function test_no_leaks_for_any_role() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'pegwork/filters' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->get_registered( 'pegwork/filters' )->supports['interactivity'] );

		foreach ( array( 0, 'subscriber', 'editor', 'administrator' ) as $role ) {
			wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
			foreach ( array( array(), array( 'contact_email' => self::EMAIL ), array( 'q' => 'secret-contact' ) ) as $params ) {
				$html = $this->directory( $params );
				$this->assertStringNotContainsString( self::EMAIL, $html, (string) $role );
				$html = $this->archive( $params );
				$this->assertStringNotContainsString( self::EMAIL, $html, (string) $role );
			}
		}
	}
}
