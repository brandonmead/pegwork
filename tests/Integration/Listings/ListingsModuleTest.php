<?php
/**
 * Integration tests: the listings module on the SI for Good example.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Listings;

use Pegwork\Api\RegistryBuilder;
use Pegwork\Plugin;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The pegwork/field source and pegwork/conditional block, rendered through do_blocks().
 */
class ListingsModuleTest extends WP_UnitTestCase {

	/**
	 * Contact email used to detect leaks.
	 */
	private const EMAIL = 'secret-contact@example.org';

	/**
	 * The worked example from docs/phase-2-design.md.
	 */
	private const VOLUNTEER_BUTTON = '<!-- wp:pegwork/conditional {"conditions":[{"field":"needs_volunteers","op":"equals","value":true},{"taxonomy":"sifg_project_status","op":"not_in","terms":["paused"]}]} -->
<!-- wp:buttons --><div class="wp-block-buttons">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/field","args":{"key":"volunteer_link"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Volunteer with this project</a></div>
<!-- /wp:button --></div><!-- /wp:buttons -->
<!-- /wp:pegwork/conditional -->';

	/**
	 * Registers examples/sifg and boots the listings module.
	 */
	public function set_up() {
		parent::set_up();
		add_action( 'pegwork_register', fn( RegistryBuilder $b ) => $b->add_package( dirname( __DIR__, 3 ) . '/examples/sifg' ) );
		Plugin::init();

		$module = require dirname( __DIR__, 3 ) . '/modules/listings/module.php';
		$module->boot();
		update_option( 'date_format', 'F j, Y' );
	}

	/**
	 * Rebuilds the registry without the test package.
	 */
	public function tear_down() {
		unset( $GLOBALS['post'] );
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Creates a published project with every field set.
	 *
	 * @param array  $meta   Field values by field key (overrides).
	 * @param string $status Status slug.
	 * @param array  $post   Extra post args.
	 * @return int Post ID.
	 */
	private function project( $meta = array(), $status = 'active', $post = array() ) {
		$id     = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'sifg_project',
					'post_status'  => 'publish',
					'post_title'   => 'River Watch',
					'post_content' => '<p>Description text.</p>',
				),
				$post
			)
		);
		$values = array_merge(
			array(
				'who_for'          => 'Neighborhood groups',
				'website'          => 'https://river.example.org',
				'needs_volunteers' => true,
				'volunteer_link'   => 'https://river.example.org/volunteer',
				'github_link'      => 'https://github.com/example/river',
				'contact_email'    => self::EMAIL,
				'last_verified'    => '2026-10-05',
			),
			$meta
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $id, '_sifg_project_' . $key, $value );
		}
		wp_set_object_terms( $id, $status, 'sifg_project_status' );
		wp_set_object_terms( $id, 'nonprofit', 'sifg_project_type' );

		return $id;
	}

	/**
	 * Renders block markup with a post as the global post (as a single template does).
	 *
	 * @param int    $id     Post ID.
	 * @param string $markup Block markup.
	 */
	private function render( $id, $markup ) {
		$GLOBALS['post'] = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test sets the global post like the template loader does.
		return do_blocks( $markup );
	}

	/**
	 * Writes a meta value straight to the database, bypassing the registered sanitizer.
	 *
	 * @param int    $id    Post ID.
	 * @param string $key   Field key.
	 * @param string $value Raw value.
	 */
	private function raw_meta( $id, $key, $value ) {
		global $wpdb;
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $value ), array( 'post_id' => $id, 'meta_key' => '_sifg_project_' . $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery, WordPress.Arrays.ArrayDeclarationSpacing -- Simulates an unsanitized stored value.
		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * A paragraph bound to a field.
	 *
	 * @param string $key  Field key.
	 * @param string $args Extra args as JSON object members, e.g. `"prefix":"For: "`.
	 */
	private static function paragraph( $key, $args = '' ) {
		$args = '' === $args ? '' : ',' . $args;
		return '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/field","args":{"key":"' . $key . '"' . $args . '}}}}} --><p>PLACEHOLDER-' . $key . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * A button bound to a URL field.
	 *
	 * @param string $key Field key.
	 */
	private static function button( $key ) {
		return '<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/field","args":{"key":"' . $key . '"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Button ' . $key . '</a></div><!-- /wp:button -->';
	}

	/**
	 * A conditional wrapping a marker paragraph.
	 *
	 * @param string $conditions Conditions as JSON.
	 */
	private static function conditional( $conditions ) {
		return '<!-- wp:pegwork/conditional {"conditions":' . $conditions . '} --><!-- wp:paragraph --><p>INNER</p><!-- /wp:paragraph --><!-- /wp:pegwork/conditional -->';
	}

	/**
	 * The detail markup: every public field, plus attempts to show the private one.
	 */
	private function detail_markup() {
		return '<!-- wp:heading {"metadata":{"bindings":{"content":{"source":"pegwork/field","args":{"key":"name"}}}}} --><h2 class="wp-block-heading">PLACEHOLDER-name</h2><!-- /wp:heading -->'
			. self::paragraph( 'who_for', '"prefix":"For: "' )
			. self::paragraph( 'needs_volunteers', '"label":"Needs volunteers"' )
			. self::paragraph( 'last_verified', '"format":"M Y","prefix":"Verified "' )
			. self::paragraph( 'contact_email' )
			. '<!-- wp:buttons --><div class="wp-block-buttons">' . self::button( 'website' ) . self::button( 'github_link' ) . self::button( 'contact_email' ) . '</div><!-- /wp:buttons -->'
			. self::VOLUNTEER_BUTTON
			. str_replace( 'INNER', 'LEAK', self::conditional( '[{"field":"contact_email","op":"not_empty"}]' ) );
	}

	/**
	 * The source, the block and the category are registered.
	 */
	public function test_registration() {
		$source = get_block_bindings_source( 'pegwork/field' );
		$this->assertNotNull( $source );
		$this->assertSame( array( 'postId', 'postType' ), $source->uses_context );

		$block = WP_Block_Type_Registry::get_instance()->get_registered( 'pegwork/conditional' );
		$this->assertNotNull( $block );
		$this->assertSame( 'pegwork', $block->category );
		$this->assertSame( array( 'postId', 'postType' ), $block->uses_context );
		$this->assertTrue( $block->supports['interactivity']['clientNavigation'] );

		$categories = \PegworkListings\Module::block_categories( array( array( 'slug' => 'text' ) ) );
		$this->assertSame( array( 'text', 'pegwork' ), array_column( $categories, 'slug' ) );
		$this->assertCount( 2, \PegworkListings\Module::block_categories( $categories ) );
		$this->assertNotFalse( has_filter( 'block_categories_all', array( \PegworkListings\Module::class, 'block_categories' ) ) );
	}

	/**
	 * The detail markup shows every public field formatted, for every role, and never the contact email.
	 */
	public function test_detail_shows_public_fields_and_never_contact_email() {
		$id = $this->project();

		foreach ( array( 'administrator', 'editor', 'subscriber', 'anonymous' ) as $role ) {
			wp_set_current_user( 'anonymous' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
			$html = $this->render( $id, $this->detail_markup() );

			$this->assertStringContainsString( '>River Watch</h2>', $html, $role );
			$this->assertStringContainsString( '>For: Neighborhood groups</p>', $html, $role );
			$this->assertStringContainsString( '>Needs volunteers</p>', $html, $role );
			$this->assertStringContainsString( '>Verified Oct 2026</p>', $html, $role );
			$this->assertStringContainsString( 'href="https://river.example.org"', $html, $role );
			$this->assertStringContainsString( 'href="https://github.com/example/river"', $html, $role );
			$this->assertStringContainsString( 'href="https://river.example.org/volunteer"', $html, $role );

			$this->assertStringNotContainsString( self::EMAIL, $html, $role );
			$this->assertStringNotContainsString( 'secret-contact', $html, $role );
			$this->assertStringNotContainsString( 'LEAK', $html, $role );
			$this->assertStringNotContainsString( 'PLACEHOLDER', $html, $role );
			$this->assertStringNotContainsString( 'Button contact_email', $html, $role );
		}
	}

	/**
	 * Volunteer button matrix: shown only when Needs volunteers is on and the status is not Paused.
	 */
	public function test_volunteer_button_matrix() {
		$cases = array(
			array( true, 'active', true ),
			array( true, 'paused', false ),
			array( false, 'active', false ),
			array( false, 'paused', false ),
			array( true, 'building', true ),
		);
		foreach ( $cases as [ $needs, $status, $shown ] ) {
			$html  = $this->render( $this->project( array( 'needs_volunteers' => $needs ), $status ), self::VOLUNTEER_BUTTON );
			$label = ( $needs ? 'on' : 'off' ) . '/' . $status;

			$this->assertSame( $shown, str_contains( $html, 'Volunteer with this project' ), $label );
			$this->assertSame( $shown, str_contains( $html, 'href="https://river.example.org/volunteer"' ), $label );
		}
	}

	/**
	 * A stale volunteer link never renders once Needs volunteers is off (show_when), even without the conditional.
	 */
	public function test_show_when_hides_stale_link() {
		$id = $this->project( array( 'needs_volunteers' => false ) );

		$this->assertSame( '', trim( $this->render( $id, self::button( 'volunteer_link' ) ) ) );
		$this->assertSame( '', trim( $this->render( $id, self::paragraph( 'needs_volunteers' ) ) ) );
	}

	/**
	 * Markup in Who it's for renders as text, whether stored through the API (sanitized) or raw in the database.
	 */
	public function test_script_in_who_for_renders_as_text() {
		$id = $this->project( array( 'who_for' => '<script>alert(1)</script>Kids' ) );
		$this->assertStringNotContainsString( '<script', $this->render( $id, self::paragraph( 'who_for' ) ) );

		$this->raw_meta( $id, 'who_for', '<script>alert(1)</script>Kids' );
		$html = $this->render( $id, self::paragraph( 'who_for' ) );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;Kids', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A javascript: URL renders no button (stored raw, bypassing esc_url_raw).
	 */
	public function test_javascript_url_renders_no_button() {
		$id = $this->project();
		$this->raw_meta( $id, 'website', 'javascript:alert(1)' );

		$html = $this->render( $id, self::button( 'website' ) );
		$this->assertStringNotContainsString( 'javascript', $html );
		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * Empty values render nothing (not the placeholder), unless keepEmpty is set.
	 */
	public function test_empty_values_hide_block_unless_keep_empty() {
		$id = $this->project(
			array(
				'last_verified' => '',
				'website'       => '',
			)
		);

		$this->assertSame( '', trim( $this->render( $id, self::paragraph( 'last_verified', '"prefix":"Verified "' ) ) ) );
		$this->assertSame( '', trim( $this->render( $id, self::button( 'website' ) ) ) );
		$this->assertStringContainsString( 'PLACEHOLDER-last_verified', $this->render( $id, self::paragraph( 'last_verified', '"keepEmpty":true' ) ) );
		$this->assertStringContainsString( 'Not bound', $this->render( $id, '<!-- wp:paragraph --><p>Not bound</p><!-- /wp:paragraph -->' ) );
	}

	/**
	 * Password-protected, draft and private records show no values; conditionals render nothing.
	 */
	public function test_non_public_records_show_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$records = array(
			'password' => $this->project( array(), 'active', array( 'post_password' => 'pw' ) ),
			'draft'    => $this->project( array(), 'active', array( 'post_status' => 'draft' ) ),
			'private'  => $this->project( array(), 'active', array( 'post_status' => 'private' ) ),
		);
		foreach ( $records as $label => $id ) {
			$html = $this->render( $id, self::paragraph( 'who_for' ) . self::VOLUNTEER_BUTTON );
			$this->assertSame( '', trim( $html ), $label );
		}
	}

	/**
	 * The conditional fails closed on unknown fields, private fields, foreign taxonomies, unknown operators
	 * and malformed conditions.
	 */
	public function test_conditional_fails_closed() {
		$id = $this->project();

		$closed = array(
			'unknown field'      => '[{"field":"nope","op":"empty"}]',
			'private empty'      => '[{"field":"contact_email","op":"empty"}]',
			'private not empty'  => '[{"field":"contact_email","op":"not_empty"}]',
			'foreign taxonomy'   => '[{"taxonomy":"category","op":"not_in","terms":["x"]}]',
			'unknown op'         => '[{"field":"who_for","op":"contains","value":"x"}]',
			'no target'          => '[{"op":"empty"}]',
			'not an object'      => '["who_for"]',
			'not a list'         => '"who_for"',
			'one failing of two' => '[{"field":"who_for","op":"not_empty"},{"field":"website","op":"empty"}]',
		);
		foreach ( $closed as $label => $conditions ) {
			$this->assertStringNotContainsString( 'INNER', $this->render( $id, self::conditional( $conditions ) ), $label );
		}

		$open = array(
			'none'             => '[]',
			'boolean on'       => '[{"field":"needs_volunteers","op":"equals","value":true}]',
			'boolean string 1' => '[{"field":"needs_volunteers","op":"equals","value":"1"}]',
			'not paused'       => '[{"taxonomy":"sifg_project_status","op":"not_in","terms":["paused"]}]',
			'status in'        => '[{"taxonomy":"sifg_project_status","op":"in","terms":["idea","active"]}]',
			'impact none of'   => '[{"taxonomy":"sifg_impact","op":"not_in","terms":["sdg-01"]}]',
			'text equals'      => '[{"field":"who_for","op":"equals","value":"Neighborhood groups"}]',
			'text not equals'  => '[{"field":"who_for","op":"not_equals","value":"Kids"}]',
			'title not empty'  => '[{"field":"name","op":"not_empty"}]',
		);
		foreach ( $open as $label => $conditions ) {
			$this->assertStringContainsString( 'INNER', $this->render( $id, self::conditional( $conditions ) ), $label );
		}

		// Without a record (no post context) conditions cannot hold.
		unset( $GLOBALS['post'] );
		$this->assertStringNotContainsString( 'INNER', do_blocks( self::conditional( $open['boolean on'] ) ) );
	}

	/**
	 * Inside a Query Loop each card resolves its own record.
	 */
	public function test_query_loop_cards_resolve_their_own_record() {
		$this->project( array( 'who_for' => 'First audience' ), 'active', array( 'post_title' => 'First' ) );
		$this->project( array( 'who_for' => 'Second audience' ), 'active', array( 'post_title' => 'Second' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$markup = '<!-- wp:query {"queryId":1,"query":{"perPage":10,"postType":"sifg_project","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template -->'
			. self::paragraph( 'who_for' ) . self::paragraph( 'contact_email' )
			. '<!-- /wp:post-template --></div><!-- /wp:query -->';
		$html   = $this->render( $page, $markup );

		$this->assertStringContainsString( '>First audience</p>', $html );
		$this->assertStringContainsString( '>Second audience</p>', $html );
		$this->assertStringNotContainsString( self::EMAIL, $html );
		$this->assertStringNotContainsString( 'PLACEHOLDER', $html );
	}

	/**
	 * A binding whose context post is not a Pegwork type (e.g. a page) renders nothing.
	 */
	public function test_other_post_types_resolve_nothing() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( '', trim( $this->render( $page, self::paragraph( 'who_for' ) ) ) );
	}

	/**
	 * The editor config lists public fields only and the fields list format for this WordPress.
	 */
	public function test_editor_config_has_public_fields_only() {
		$config  = \PegworkListings\Editor::config();
		$project = $config['types']['sifg_project'];

		$this->assertNotContains( 'contact_email', array_column( $project['fields'], 'key' ) );
		$this->assertContains( 'volunteer_link', array_column( $project['fields'], 'key' ) );
		$this->assertStringNotContainsString( 'contact_email', wp_json_encode( $config ) );
		$this->assertSame( array( 'sifg_project_type', 'sifg_project_status', 'sifg_impact' ), array_column( $project['taxonomies'], 'key' ) );
		$this->assertContains( 'paused', array_column( $project['taxonomies'][1]['terms'], 'slug' ) );
		$this->assertSame( version_compare( get_bloginfo( 'version' ), '6.9-alpha', '>=' ) ? 'array' : 'object', $config['fieldsListFormat'] );
	}
}
