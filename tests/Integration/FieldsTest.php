<?php
/**
 * Integration test: field meta registration, REST visibility and record validation.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\RecordValidator;
use Pegwork\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The sifg Project fields save through REST with the registry's rules applied.
 */
class FieldsTest extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Registers examples/sifg, rebuilds REST routes and logs in an editor.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $builder ) => $builder->add_package( dirname( __DIR__, 2 ) . '/examples/sifg' ) );
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Rebuild routes for the registered types.

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor );
	}

	/**
	 * Rebuilds the registry without the package.
	 */
	public function tear_down() {
		parent::tear_down();
		Plugin::init();
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Drop routes for the removed types.
	}

	/**
	 * Text of a given number of words.
	 *
	 * @param int $count Words.
	 */
	private function words( $count ) {
		return implode( ' ', array_fill( 0, $count, 'word' ) );
	}

	/**
	 * Term ID by slug.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $slug     Term slug.
	 */
	private function term( $taxonomy, $slug ) {
		return get_term_by( 'slug', $slug, $taxonomy )->term_id;
	}

	/**
	 * Body of a valid published Project, with overrides.
	 *
	 * @param array $overrides Top-level request params to replace.
	 * @param array $meta      Meta values to merge.
	 */
	private function project( $overrides = array(), $meta = array() ) {
		return array_merge(
			array(
				'title'               => 'Clean water map',
				'content'             => '<!-- wp:paragraph --><p>' . $this->words( 60 ) . '</p><!-- /wp:paragraph -->',
				'status'              => 'publish',
				'sifg_project_type'   => array( $this->term( 'sifg_project_type', 'nonprofit' ) ),
				'sifg_project_status' => array( $this->term( 'sifg_project_status', 'active' ) ),
				'meta'                => array_merge(
					array(
						'_sifg_project_contact_email'    => 'team@example.org',
						'_sifg_project_needs_volunteers' => false,
					),
					$meta
				),
			),
			$overrides
		);
	}

	/**
	 * Sends a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param array  $params Body or query params.
	 */
	private function rest( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Private fields are hidden from anonymous reads and shown to editors in edit context.
	 */
	public function test_private_field_needs_edit_context() {
		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project() );
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		wp_set_current_user( 0 );
		$meta = $this->rest( 'GET', "/wp/v2/projects/$id" )->get_data()['meta'];
		$this->assertArrayNotHasKey( '_sifg_project_contact_email', $meta );
		$this->assertArrayHasKey( '_sifg_project_website', $meta );
		$this->assertSame( 401, $this->rest( 'GET', "/wp/v2/projects/$id", array( 'context' => 'edit' ) )->get_status() );

		wp_set_current_user( $this->editor );
		$meta = $this->rest( 'GET', "/wp/v2/projects/$id", array( 'context' => 'edit' ) )->get_data()['meta'];
		$this->assertSame( 'team@example.org', $meta['_sifg_project_contact_email'] );
		$this->assertFalse( $meta['_sifg_project_needs_volunteers'] );
	}

	/**
	 * Private meta is never revisioned.
	 */
	public function test_private_meta_is_not_revisioned() {
		$args = get_registered_meta_keys( 'post', 'sifg_project' )['_sifg_project_contact_email'];
		$this->assertFalse( $args['revisions_enabled'] );
		$this->assertSame( array( 'edit' ), $args['show_in_rest']['schema']['context'] );
	}

	/**
	 * Volunteer link is required only while Needs volunteers is on.
	 */
	public function test_required_when() {
		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project( array(), array( '_sifg_project_needs_volunteers' => true ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );
		$this->assertSame( array( 'volunteer_link' ), array_keys( $response->get_data()['data']['fields'] ) );

		$response = $this->rest(
			'POST',
			'/wp/v2/projects',
			$this->project(
				array(),
				array(
					'_sifg_project_needs_volunteers' => true,
					'_sifg_project_volunteer_link'   => 'https://example.org/help',
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );
	}

	/**
	 * Description must be 50 to 300 words to publish or set pending; drafts may be anything.
	 */
	public function test_description_word_limits() {
		foreach ( array( 'publish', 'pending' ) as $status ) {
			foreach ( array( 49, 301 ) as $count ) {
				$response = $this->rest(
					'POST',
					'/wp/v2/projects',
					$this->project(
						array(
							'status'  => $status,
							'content' => $this->words( $count ),
						)
					)
				);
				$this->assertSame( 400, $response->get_status(), "$status $count" );
				$this->assertArrayHasKey( 'description', $response->get_data()['data']['fields'] );
			}
		}

		$response = $this->rest(
			'POST',
			'/wp/v2/projects',
			array(
				'title'   => 'Rough idea',
				'content' => $this->words( 5 ),
				'status'  => 'draft',
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$id = $response->get_data()['id'];

		$this->assertSame( 400, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'status' => 'publish' ) )->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	/**
	 * Word counts strip block comments and tags, as the editor panel does.
	 */
	public function test_word_count_matches_editor_panel() {
		$this->assertSame( 0, RecordValidator::count_words( '' ) );
		$this->assertSame( 0, RecordValidator::count_words( '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->' ) );
		$this->assertSame( 4, RecordValidator::count_words( "<!-- wp:paragraph -->\n<p>One <strong>two</strong>&nbsp;three</p>\n<!-- /wp:paragraph -->\n<!-- wp:list --><ul><li>four</li></ul><!-- /wp:list -->" ) );
		$this->assertSame( 2, RecordValidator::count_words( '<p>run</p><p>on</p>' ) );
		$this->assertSame( 2, RecordValidator::count_words( "a\u{00A0}b" ) );

		$blocks = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$blocks .= "<!-- wp:paragraph -->\n<p>one <em>two</em> three four five</p>\n<!-- /wp:paragraph -->\n\n";
		}
		$this->assertSame( 50, RecordValidator::count_words( $blocks ) );
		$this->assertSame( 201, $this->rest( 'POST', '/wp/v2/projects', $this->project( array( 'content' => $blocks ) ) )->get_status() );

		$short = $blocks . '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->';
		$short = preg_replace( '/five/', '', $short, 1 );
		$this->assertSame( 49, RecordValidator::count_words( $short ) );
		$this->assertSame( 400, $this->rest( 'POST', '/wp/v2/projects', $this->project( array( 'content' => $short ) ) )->get_status() );
	}

	/**
	 * Single-select taxonomies reject two terms, even on drafts; required ones block publish.
	 */
	public function test_taxonomy_usage() {
		$two      = array( $this->term( 'sifg_project_type', 'nonprofit' ), $this->term( 'sifg_project_type', 'individual' ) );
		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project( array( 'sifg_project_type' => $two ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'sifg_project_type', $response->get_data()['data']['fields'] );

		$draft = $this->rest(
			'POST',
			'/wp/v2/projects',
			array(
				'title'             => 'Draft',
				'status'            => 'draft',
				'sifg_project_type' => $two,
			)
		);
		$this->assertSame( 400, $draft->get_status() );

		$response = $this->rest( 'POST', '/wp/v2/projects', $this->project( array( 'sifg_project_status' => array() ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'sifg_project_status', $response->get_data()['data']['fields'] );

		$impact = array( $this->term( 'sifg_impact', 'sdg-06' ), $this->term( 'sifg_impact', 'sdg-13' ) );
		$this->assertSame( 201, $this->rest( 'POST', '/wp/v2/projects', $this->project( array( 'sifg_impact' => $impact ) ) )->get_status() );
	}

	/**
	 * Updates merge stored values: a stored link satisfies required_when on a later publish.
	 */
	public function test_update_merges_stored_values() {
		$response = $this->rest(
			'POST',
			'/wp/v2/projects',
			$this->project(
				array( 'status' => 'draft' ),
				array(
					'_sifg_project_needs_volunteers' => true,
					'_sifg_project_volunteer_link'   => 'https://example.org/help',
				)
			)
		);
		$id       = $response->get_data()['id'];

		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/projects/$id", array( 'status' => 'publish' ) )->get_status() );

		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_volunteer_link' => '' ) ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Each field type's sanitizer runs on save.
	 */
	public function test_sanitizers() {
		$id    = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'draft',
			)
		);
		$cases = array(
			'who_for'          => array( '<b>Kids</b> & families', 'Kids & families' ),
			'website'          => array( 'javascript:alert(1)', '' ),
			'github_link'      => array( 'https://github.com/x y', 'https://github.com/x%20y' ),
			'contact_email'    => array( 'not an email', '' ),
			'needs_volunteers' => array( 'yes', '1' ),
			'last_verified'    => array( '2026-02-30', '' ),
		);
		foreach ( $cases as $field => $case ) {
			update_post_meta( $id, '_sifg_project_' . $field, $case[0] );
			$this->assertSame( $case[1], get_post_meta( $id, '_sifg_project_' . $field, true ), $field );
		}

		update_post_meta( $id, '_sifg_project_last_verified', '2026-10-17' );
		$this->assertSame( '2026-10-17', get_post_meta( $id, '_sifg_project_last_verified', true ) );

		$this->assertSame(
			'online',
			\Pegwork\Content\Meta::sanitizer(
				array(
					'type'    => 'select',
					'options' => array( array( 'value' => 'online' ) ),
				)
			)( 'online' )
		);
		$this->assertSame(
			'',
			\Pegwork\Content\Meta::sanitizer(
				array(
					'type'    => 'select',
					'options' => array( array( 'value' => 'online' ) ),
				)
			)( 'other' )
		);
		$this->assertSame( 12.5, \Pegwork\Content\Meta::sanitize_number( ' 12.5 ' ) );
		$this->assertSame( '', \Pegwork\Content\Meta::sanitize_number( 'twelve' ) );
		$this->assertSame( '<p>ok</p>', \Pegwork\Content\Meta::sanitizer( array( 'type' => 'rich_text' ) )( '<p>ok</p><script></script>' ) );

		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_contact_email' => 'bad' ) ) );
		// Values the sanitizer would blank are rejected before the save, per field (QA C4).
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'contact_email', $response->get_data()['data']['fields'] );
		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_last_verified' => '17/10/2026' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pegwork_invalid_record', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'last_verified', $response->get_data()['data']['fields'] );
		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_contact_email' => '' ) ) );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Subscribers cannot write meta; editors can.
	 */
	public function test_subscriber_cannot_write_meta() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_author' => $this->editor,
			)
		);

		$this->assertTrue( current_user_can( 'edit_post_meta', $id, '_sifg_project_website' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $id, '_sifg_project_website' ) );
		$response = $this->rest( 'POST', "/wp/v2/projects/$id", array( 'meta' => array( '_sifg_project_website' => 'https://evil.example' ) ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( '', get_post_meta( $id, '_sifg_project_website', true ) );
	}

	/**
	 * Meta keys default to _{post_type}_{field}; post column fields have no meta.
	 */
	public function test_meta_keys() {
		$keys = array_keys( get_registered_meta_keys( 'post', 'sifg_project' ) );
		sort( $keys );
		$expected = array_map( fn( $f ) => '_sifg_project_' . $f, array( 'contact_email', 'github_link', 'last_verified', 'needs_volunteers', 'paused_from', 'paused_on', 'paused_volunteers', 'volunteer_link', 'website', 'who_for' ) );
		$this->assertSame( $expected, $keys );
		$this->assertSame( 'boolean', get_registered_meta_keys( 'post', 'sifg_project' )['_sifg_project_needs_volunteers']['type'] );
		$this->assertSame( 'uri', get_registered_meta_keys( 'post', 'sifg_project' )['_sifg_project_website']['show_in_rest']['schema']['format'] );
	}

	/**
	 * The public validation function applies every rule.
	 */
	public function test_pegwork_validate_record() {
		$valid = array(
			'name'                => 'Clean water map',
			'description'         => $this->words( 80 ),
			'contact_email'       => 'team@example.org',
			'needs_volunteers'    => '1',
			'volunteer_link'      => 'https://example.org/help',
			'who_for'             => 'Everyone',
			'sifg_project_type'   => array( 1 ),
			'sifg_project_status' => array( 2 ),
		);
		$this->assertTrue( pegwork_validate_record( 'sifg_project', $valid ) );

		$result = pegwork_validate_record(
			'sifg_project',
			array_merge(
				$valid,
				array(
					'name'           => '',
					'volunteer_link' => null,
					'who_for'        => str_repeat( 'x', 201 ),
				)
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array( 'name', 'who_for', 'volunteer_link' ), array_keys( $result->get_error_data()['fields'] ) );

		$this->assertWPError( pegwork_validate_record( 'nope', array() ) );
	}
}
