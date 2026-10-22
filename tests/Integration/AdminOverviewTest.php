<?php
/**
 * Integration tests: the Overview screen.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Overview;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use Pegwork\Registry\Status;
use WPDieException;
use WP_UnitTestCase;

/**
 * The Overview lists the registry, shadowed DB entries and rejected packages, escaped, for admins only.
 */
class AdminOverviewTest extends WP_UnitTestCase {

	/**
	 * Rebuilds the registry without the test's packages (hooks are restored by the parent).
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Registers packages and a simulated DB source, then rebuilds the registry as init does.
	 *
	 * @param string[] $dirs Package directories.
	 * @param array    $db   Value of the pegwork_schema option.
	 */
	private function load( array $dirs, array $db = array() ) {
		add_action(
			'pegwork_register',
			static function ( RegistryBuilder $builder ) use ( $dirs ) {
				foreach ( $dirs as $dir ) {
					$builder->add_package( $dir );
				}
			}
		);
		add_filter( 'pre_option_pegwork_schema', fn() => $db );
		Plugin::init();
	}

	/**
	 * Renders the screen as an administrator.
	 *
	 * @return string HTML.
	 */
	private function render() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Overview::render();
		return ob_get_clean();
	}

	/**
	 * The example package, a shadowed DB entry, a DB-only entry and a rejected package all show.
	 */
	public function test_lists_example_package_shadowed_entry_and_rejected_package() {
		$invalid = dirname( __DIR__ ) . '/fixtures/invalid/invalid-key';
		$this->load(
			array( dirname( __DIR__, 2 ) . '/examples/sifg', $invalid ),
			array(
				'content_types' => array(
					array(
						'format' => 1,
						'key'    => 'sifg_project',
						'labels' => array(
							'singular' => 'Shadow',
							'plural'   => 'Shadows',
						),
					),
				),
				'taxonomies'    => array(
					array(
						'format' => 1,
						'key'    => 'site_tag',
						'labels' => array(
							'singular' => 'Tag',
							'plural'   => '<script>alert(1)</script>Tags',
						),
					),
				),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '<div class="wrap pegwork-admin">', $html );
		$this->assertStringContainsString( '<h1>Pegwork Overview</h1>', $html );
		foreach ( array( 'Content types', 'Taxonomies', 'Relationships', 'Shadowed DB entries', 'Rejected packages and DB entries' ) as $caption ) {
			$this->assertStringContainsString( '<caption class="screen-reader-text">' . $caption . '</caption>', $html );
		}
		$this->assertSame( substr_count( $html, '<table' ), substr_count( $html, '<caption' ), 'Every table has a caption.' );
		$this->assertStringContainsString( '<th scope="col" class="column-rest_base">', $html );

		// Content type row and its field list.
		$this->assertStringContainsString( '<th scope="row" class="column-key"><code>sifg_project</code></th>', $html );
		$this->assertStringContainsString( '<code>projects</code>', $html );
		$this->assertStringContainsString( '<details class="pegwork-fields" id="pegwork-fields-sifg_project">', $html );
		$this->assertStringContainsString( 'Fields of Projects (sifg_project): 12 fields', $html );
		$this->assertStringContainsString( '<code>_sifg_project_contact_email</code>', $html );
		$this->assertStringContainsString( '<code>post_content</code>', $html );
		$this->assertStringContainsString( '>Private</td>', $html );

		// Taxonomies: locked flag and the DB-only entry, escaped.
		$this->assertStringContainsString( '<code>sifg_impact</code>', $html );
		$this->assertStringContainsString( '<code>site_tag</code>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;Tags', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '<code class="pegwork-path">option:pegwork_schema</code>', $html );

		// Shadowed entry: kind, key, the package that wins.
		$this->assertMatchesRegularExpression( '#<th scope="row" class="column-kind">content_type</th><td class="column-key"><code>sifg_project</code></td><td class="column-by"><code>sifg</code></td>#', $html );
		$this->assertStringNotContainsString( 'Shadows', $html );

		// Rejected package with file and pointer, as paths relative to the package (never absolute).
		// Paths and pointers may break only after a slash (a <wbr> after each).
		$this->assertStringContainsString( '<th scope="row" class="column-source"><code class="pegwork-path">invalid-key</code></th>', $html );
		$this->assertStringContainsString( '<code class="pegwork-path">invalid-key/<wbr>content-types/<wbr>thing.json</code>', $html );
		$this->assertStringNotContainsString( $invalid, $html );
		$this->assertStringContainsString( '<code class="pegwork-path">/<wbr>key</code>', $html );

		// Relationships: the example's story_projects, required.
		$this->assertStringContainsString( '<th scope="row" class="column-key"><code>story_projects</code></th><td class="column-from">sifg_story</td><td class="column-to">sifg_project</td><td class="column-cardinality">many_to_many</td><td class="column-required">Yes</td>', $html );
		$this->assertStringContainsString( 'wp pegwork schema import', $html );
	}

	/**
	 * Admin screens show short paths: wp-content/... under the content directory, else relative to the
	 * package directory that holds the file, else the file name; the DB source label is kept.
	 */
	public function test_display_path_hides_absolute_paths() {
		$package = dirname( __DIR__ ) . '/fixtures/invalid/invalid-key';
		$status  = new Status( Plugin::result(), array( $package ) );

		$this->assertSame( 'wp-content/plugins/demo/schema/forms/x.json', $status->display_path( WP_CONTENT_DIR . '/plugins/demo/schema/forms/x.json' ) );
		$this->assertSame( 'invalid-key/content-types/thing.json', $status->display_path( $package . '/content-types/thing.json' ) );
		$this->assertSame( 'invalid-key/content-types/thing.json', $status->display_path( (string) realpath( $package ) . '/content-types/thing.json' ), 'The real path of a package counts too.' );
		$this->assertSame( 'invalid-key', $status->display_path( $package ) );
		$this->assertSame( 'other.json', $status->display_path( '/srv/elsewhere/other.json' ) );
		$this->assertSame( 'option:pegwork_schema', $status->display_path( 'option:pegwork_schema' ) );
	}

	/**
	 * With nothing registered every section shows its empty state.
	 */
	public function test_empty_states() {
		$this->load( array() );

		$html = $this->render();

		$this->assertSame( 4, substr_count( $html, 'None registered. Add a schema package (see the extension API docs).' ) );
		$this->assertStringContainsString( 'No shadowed DB entries.', $html );
		$this->assertStringContainsString( 'No rejected packages or DB entries.', $html );
		$this->assertStringNotContainsString( '<details', $html );
	}

	/**
	 * Users without manage_options are refused.
	 */
	public function test_editor_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		Overview::render();
	}
}
