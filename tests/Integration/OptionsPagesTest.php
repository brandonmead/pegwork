<?php
/**
 * Integration tests: options pages (settings, screen, capability, values, bindings).
 *
 * @package Pegwork
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The test drives core's admin and settings globals and fires admin_menu as core does.

namespace Pegwork\Tests\Integration;

use Pegwork\Admin\Overview;
use Pegwork\Api\RegistryBuilder;
use Pegwork\Content\RecordValidator;
use Pegwork\Options\BindingSource;
use Pegwork\Options\Pages;
use Pegwork\Options\Screen;
use Pegwork\Options\Values;
use Pegwork\Plugin;
use Pegwork\Registry\Status;
use WP_UnitTestCase;

use const Pegwork\Api\ADMIN_MENU;

/**
 * Options pages from examples/sifg (`sifg_settings`, manage_options) and the `sections` fixture
 * (`talk_settings`, edit_others_posts).
 */
class OptionsPagesTest extends WP_UnitTestCase {

	/**
	 * A published Project.
	 *
	 * @var int
	 */
	private $project;

	/**
	 * Registers both packages, loads the admin APIs and resets settings errors and menu globals.
	 */
	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$root = dirname( __DIR__, 2 );
		add_action(
			'pegwork_register',
			static function ( RegistryBuilder $builder ) use ( $root ) {
				$builder->add_package( $root . '/examples/sifg' );
				$builder->add_package( $root . '/tests/fixtures/valid/sections' );
			}
		);
		Plugin::init();
		$this->reset_globals();
		$this->project = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'publish',
				'post_title'  => 'Seed library',
			)
		);
	}

	/**
	 * Rebuilds the registry without the test's packages.
	 */
	public function tear_down() {
		$this->reset_globals();
		parent::tear_down();
		Plugin::init();
	}

	/**
	 * Empties settings errors and the admin menu globals.
	 */
	private function reset_globals() {
		$GLOBALS['wp_settings_errors'] = array();
		$GLOBALS['menu']               = array();
		$GLOBALS['submenu']            = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();
		$GLOBALS['_wp_menu_nopriv']    = array();
		$GLOBALS['_registered_pages']  = array();
		$GLOBALS['_parent_pages']      = array();
		$GLOBALS['admin_page_hooks']   = array();
		unset( $GLOBALS['plugin_page'], $GLOBALS['pagenow'] );
		delete_transient( 'settings_errors' );
	}

	/**
	 * A form submission of sifg_settings, as options.php passes it to update_option().
	 *
	 * @param array<string, mixed> $overrides Values over the valid defaults.
	 * @return array<string, mixed>
	 */
	private function submission( array $overrides = array() ) {
		return array_merge(
			array(
				'featured_project' => (string) $this->project,
				'moderator_email'  => 'mod@example.org',
				'review_criteria'  => '<p>Clear <strong>impact</strong>.</p><script>alert(1)</script>',
				'contact_email'    => 'hello@example.org',
			),
			$overrides
		);
	}

	/**
	 * Settings errors added for sifg_settings, code => message.
	 *
	 * @return array<string, string>
	 */
	private function errors() {
		return array_column( get_settings_errors( 'sifg_settings' ), 'message', 'code' );
	}

	/**
	 * Each page is a registered setting in its own group, not in REST, defaulting to field defaults.
	 */
	public function test_pages_are_registered_settings() {
		$settings = get_registered_settings();

		$this->assertSame( 'pegwork_sifg_settings', $settings['sifg_settings']['group'] );
		$this->assertFalse( $settings['sifg_settings']['show_in_rest'] );
		$this->assertSame( 'object', $settings['sifg_settings']['type'] );
		$this->assertSame( array( 'sifg_settings', 'talk_settings' ), Pages::registered() );
		$this->assertSame(
			array(
				'seats_default' => 30,
				'mode'          => 'open',
				'accepting'     => true,
			),
			$settings['talk_settings']['default']
		);

		Plugin::init();
		$this->assertCount( 1, $GLOBALS['wp_filter']['sanitize_option_sifg_settings']->callbacks[10] ?? array(), 'Re-registering does not stack sanitize callbacks.' );
	}

	/**
	 * All four sifg_settings fields save, typed and sanitized, autoloaded; no errors.
	 */
	public function test_all_four_fields_save() {
		update_option( 'sifg_settings', $this->submission() );

		$this->assertSame( array(), $this->errors() );
		$stored = get_option( 'sifg_settings' );
		$this->assertSame( $this->project, $stored['featured_project'] );
		$this->assertSame( 'mod@example.org', $stored['moderator_email'] );
		$this->assertSame( '<p>Clear <strong>impact</strong>.</p>alert(1)', $stored['review_criteria'] );
		$this->assertSame( 'hello@example.org', $stored['contact_email'] );

		$this->assertSame( $this->project, pegwork_get_option( 'sifg_settings', 'featured_project' ) );
		$this->assertSame( 'mod@example.org', pegwork_get_option( 'sifg_settings', 'moderator_email' ), 'Private values are readable server-side.' );

		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayHasKey( 'sifg_settings', wp_load_alloptions(), 'The option is autoloaded.' );
	}

	/**
	 * An invalid email keeps the previous value with an error naming the field and the rejected text;
	 * the other fields still save.
	 */
	public function test_invalid_email_keeps_old_value_with_error() {
		update_option( 'sifg_settings', $this->submission() );
		$GLOBALS['wp_settings_errors'] = array();

		update_option(
			'sifg_settings',
			$this->submission(
				array(
					'contact_email'   => 'not-an-email<b>',
					'review_criteria' => 'Updated criteria',
				)
			)
		);

		$stored = get_option( 'sifg_settings' );
		$this->assertSame( 'hello@example.org', $stored['contact_email'] );
		$this->assertSame( 'Updated criteria', $stored['review_criteria'] );

		$errors = $this->errors();
		$this->assertSame( array( Pages::error_code( 'contact_email' ), 'pegwork_partial' ), array_keys( $errors ) );
		$this->assertSame(
			'Contact email must be a valid email address. You entered &quot;not-an-email&lt;b&gt;&quot;. The previous value was kept.',
			$errors[ Pages::error_code( 'contact_email' ) ]
		);
	}

	/**
	 * A required field left empty keeps its value; a first save reports each error once even though core
	 * sanitizes twice (update_option, then add_option).
	 */
	public function test_required_and_first_save() {
		update_option( 'sifg_settings', $this->submission( array( 'moderator_email' => '' ) ) );

		$errors = get_settings_errors( 'sifg_settings' );
		$this->assertCount( 2, $errors );
		$this->assertSame( 'Moderator notification email is required. Nothing was saved for this field.', $errors[0]['message'] );
		$this->assertArrayNotHasKey( 'moderator_email', get_option( 'sifg_settings' ) );
		$this->assertSame( 'hello@example.org', pegwork_get_option( 'sifg_settings', 'contact_email' ) );
	}

	/**
	 * Post fields take a published post of the configured type only; '' clears.
	 */
	public function test_post_field_rules() {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'sifg_project',
				'post_status' => 'draft',
			)
		);
		$page  = pegwork_registry()->options_page( 'sifg_settings' );
		$field = $page['fields'][0];

		$this->assertSame( array( $this->project ), Values::check_type( $field, (string) $this->project ) );
		$this->assertSame( 'Featured project must be a published Project.', Values::check_type( $field, (string) $draft ) );
		$this->assertSame( 'Featured project must be a published Project.', Values::check_type( $field, (string) self::factory()->post->create() ) );
		$this->assertSame( 'Featured project must be a published Project.', Values::check_type( $field, 'abc' ) );
		$this->assertSame( array( '' ), Values::check_type( $field, '' ) );

		update_option( 'sifg_settings', $this->submission( array( 'featured_project' => '' ) ) );
		$this->assertNull( pegwork_get_option( 'sifg_settings', 'featured_project' ) );
	}

	/**
	 * Url fields need no network: a well-formed URL on a domain that does not resolve is kept; other schemes are not.
	 */
	public function test_url_field_needs_no_network() {
		$field = array(
			'type'  => 'url',
			'label' => 'Website',
		);

		$this->assertSame( array( 'https://example.invalid/x' ), Values::check_type( $field, 'https://example.invalid/x' ) );
		$this->assertSame( array( 'http://barn.example/a?b=1' ), Values::check_type( $field, 'http://barn.example/a?b=1' ) );
		foreach ( array( 'javascript:alert(1)', 'ftp://example.org/x', 'not a url', '//example.org' ) as $bad ) {
			$this->assertSame( 'Website must be a web address starting with http:// or https://.', Values::check_type( $field, $bad ), $bad );
		}
	}

	/**
	 * Type checks and shared rules on the talk_settings fields: number range, select, date, boolean, words.
	 */
	public function test_types_and_shared_rules() {
		$page   = pegwork_registry()->options_page( 'talk_settings' );
		$result = Values::sanitize(
			$page,
			array(
				'organizer_email' => 'org@example.org',
				'seats_default'   => '900',
				'mode'            => 'maybe',
				'deadline'        => '2026-02-30',
				'accepting'       => '0',
				'intro'           => str_repeat( 'word ', 121 ),
			),
			array()
		);

		$this->assertSame( array( 'intro', 'seats_default', 'mode', 'deadline' ), array_keys( $result['errors'] ), 'Errors follow field order.' );
		$this->assertStringStartsWith( 'Default seats must be at most 500.', $result['errors']['seats_default'] );
		$this->assertStringStartsWith( 'Intro must be at most 120 words (it has 121).', $result['errors']['intro'] );
		$this->assertStringStartsWith( 'Mode must be one of its options.', $result['errors']['mode'] );
		$this->assertStringStartsWith( 'Deadline must be a date in the form YYYY-MM-DD.', $result['errors']['deadline'] );
		$this->assertSame(
			array(
				'organizer_email' => 'org@example.org',
				'accepting'       => false,
			),
			$result['values']
		);

		$typed = Values::get( pegwork_registry(), 'talk_settings' );
		$this->assertSame( 30, $typed['seats_default'] );
		$this->assertTrue( $typed['accepting'] );
		$this->assertSame( 'open', $typed['mode'] );
		$this->assertSame( '', $typed['deadline'] );
		$this->assertNull( $typed['featured_talk'] );
	}

	/**
	 * RecordValidator::check_values() is the shared rule set: same messages for records and options pages.
	 */
	public function test_check_values_is_shared() {
		$fields = pegwork_registry()->options_page( 'talk_settings' )['fields'];

		$this->assertSame(
			array( 'organizer_email' => 'Organizer email is required.' ),
			RecordValidator::check_values( $fields, array( 'seats_default' => 30 ) )
		);
		$this->assertSame( array(), RecordValidator::check_values( $fields, array(), false ) );
		$this->assertSame(
			array( 'seats_default' => 'Default seats must be at least 1.' ),
			RecordValidator::check_values(
				$fields,
				array(
					'organizer_email' => 'a@example.org',
					'seats_default'   => '0',
				)
			)
		);
	}

	/**
	 * The function pegwork_get_option(): unknown page or field is null; a page without a field gives every value.
	 */
	public function test_get_option() {
		$this->assertNull( pegwork_get_option( 'nope' ) );
		$this->assertNull( pegwork_get_option( 'sifg_settings', 'nope' ) );
		$this->assertSame(
			array(
				'featured_project' => null,
				'moderator_email'  => '',
				'review_criteria'  => '',
				'contact_email'    => '',
			),
			pegwork_get_option( 'sifg_settings' )
		);
	}

	/**
	 * The capability guards menu, screen and save: an editor cannot reach the manage_options page but can
	 * reach and save the edit_others_posts one.
	 */
	public function test_capability_guards_menu_screen_and_save() {
		$this->assertSame( 'manage_options', apply_filters( 'option_page_capability_pegwork_sifg_settings', 'manage_options' ) );
		$this->assertSame( 'edit_others_posts', apply_filters( 'option_page_capability_pegwork_talk_settings', 'manage_options' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		do_action( 'admin_menu', '' );

		$this->assertSame( array( Screen::slug( 'talk_settings' ) ), array_values( array_column( $GLOBALS['submenu'][ ADMIN_MENU ] ?? array(), 2 ) ) );

		$GLOBALS['pagenow']     = 'admin.php';
		$GLOBALS['plugin_page'] = Screen::slug( 'talk_settings' );
		$this->assertTrue( user_can_access_admin_page() );
		$GLOBALS['plugin_page'] = Screen::slug( 'sifg_settings' );
		$this->assertFalse( user_can_access_admin_page() );

		// options.php: allowed for the page capability, denied otherwise.
		$this->assertTrue( current_user_can( apply_filters( 'option_page_capability_pegwork_talk_settings', 'manage_options' ) ) );
		$this->assertFalse( current_user_can( apply_filters( 'option_page_capability_pegwork_sifg_settings', 'manage_options' ) ) );
		$this->assertSame( array( 'talk_settings' ), array_values( $GLOBALS['new_allowed_options']['pegwork_talk_settings'] ) );

		$this->expectException( \WPDieException::class );
		Screen::render( 'sifg_settings' );
	}

	/**
	 * Administrators see both screens at positions 10 and 11, between Overview and Modules.
	 */
	public function test_admin_menu_order() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'admin_menu', '' );

		$this->assertSame(
			array( ADMIN_MENU, Screen::slug( 'sifg_settings' ), Screen::slug( 'talk_settings' ), 'pegwork-modules' ),
			array_values( array_column( $GLOBALS['submenu'][ ADMIN_MENU ], 2 ) )
		);
		$this->assertSame( 'Site settings', $GLOBALS['submenu'][ ADMIN_MENU ][1][0] );
	}

	/**
	 * The screen uses Settings API markup: labels tied to controls, required and private in text, errors
	 * tied by aria-describedby with aria-invalid, and the form posts to options.php without browser validation.
	 */
	public function test_screen_markup_and_errors() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'sifg_settings', $this->submission() );
		update_option( 'sifg_settings', $this->submission( array( 'contact_email' => 'bad' ) ) );

		ob_start();
		Screen::render( 'sifg_settings' );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<form method="post" action="options.php" novalidate>', $html );
		$this->assertStringContainsString( "name='option_page' value='pegwork_sifg_settings'", $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '<label for="pegwork-sifg_settings-moderator_email">Moderator notification email <span class="pegwork-required">(required)</span> <span class="pegwork-badge">Private</span></label>', $html );
		$this->assertMatchesRegularExpression( '#<input type="email" value="hello@example.org" class="regular-text" id="pegwork-sifg_settings-contact_email" name="sifg_settings\[contact_email\]" aria-describedby="pegwork-sifg_settings-contact_email-error" aria-invalid="true">#', $html );
		$this->assertStringContainsString( 'id="pegwork-sifg_settings-contact_email-error">Error: Contact email must be a valid email address. You entered &quot;bad&quot;. The previous value was kept.</p>', $html );
		$this->assertStringContainsString( '<a href="#pegwork-sifg_settings-contact_email">', $html );
		$this->assertStringContainsString( 'aria-describedby="pegwork-sifg_settings-moderator_email-description" required="required" aria-required="true"', $html );
		$this->assertMatchesRegularExpression( '#<option value="' . $this->project . '" selected=\'selected\'>Seed library</option>#', $html );
		$this->assertStringContainsString( 'data-pegwork-post-picker="', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * A page whose option name another plugin registered first is skipped, with a notice.
	 */
	public function test_conflicting_setting_is_skipped() {
		unregister_setting( 'pegwork_sifg_settings', 'sifg_settings' );
		register_setting( 'other_plugin', 'sifg_settings' );
		Plugin::init();

		$this->assertSame( array( 'talk_settings' ), Pages::registered() );
		$this->assertNull( Pages::page( 'sifg_settings' ) );
		$this->assertSame( 'other_plugin', get_registered_settings()['sifg_settings']['group'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Pages::conflict_notice();
		$this->assertStringContainsString( 'sifg_settings', ob_get_clean() );

		unregister_setting( 'other_plugin', 'sifg_settings' );
	}

	/**
	 * The Overview lists options pages from Registry\Status.
	 */
	public function test_overview_lists_options_pages() {
		$rows = Status::current()->options_pages();
		$this->assertSame(
			array(
				'key'        => 'sifg_settings',
				'title'      => 'SI for Good settings',
				'menu_title' => 'Site settings',
				'capability' => 'manage_options',
				'fields'     => 4,
				'private'    => 1,
				'source'     => 'sifg',
			),
			$rows[0]
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Overview::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<h2 id="pegwork-options-pages">Options pages</h2>', $html );
		$this->assertStringContainsString( '<code>talk_settings</code>', $html );
		$this->assertStringContainsString( '<code>edit_others_posts</code>', $html );
		$this->assertStringContainsString( '4 (1 private)', $html );
	}

	/**
	 * The pegwork/option source: public values escaped for their type, private and post fields never,
	 * fallback text when empty, mailto for email buttons.
	 */
	public function test_binding_source() {
		$this->assertNotNull( get_block_bindings_source( BindingSource::NAME ) );
		update_option( 'sifg_settings', $this->submission( array( 'contact_email' => 'hello@example.org' ) ) );

		$paragraph = fn( $field, $fallback ) => do_blocks(
			'<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"' . $field . '"}}}}} --><p>' . $fallback . '</p><!-- /wp:paragraph -->'
		);

		$this->assertStringContainsString( '>hello@example.org</p>', $paragraph( 'contact_email', 'Fallback contact' ) );
		$this->assertStringContainsString( '>Moderator fallback</p>', $paragraph( 'moderator_email', 'Moderator fallback' ) );
		$this->assertStringNotContainsString( 'mod@example.org', $paragraph( 'moderator_email', 'Moderator fallback' ) );
		$this->assertStringContainsString( '>Featured fallback</p>', $paragraph( 'featured_project', 'Featured fallback' ) );
		$this->assertStringContainsString( '>Unknown fallback</p>', $paragraph( 'nope', 'Unknown fallback' ) );
		$rich = $paragraph( 'review_criteria', 'Criteria' );
		$this->assertStringContainsString( 'Clear <strong>impact</strong>.', $rich );
		$this->assertStringNotContainsString( '<script', $rich );

		$button = do_blocks(
			'<!-- wp:button {"metadata":{"bindings":{"url":{"source":"pegwork/option","args":{"page":"sifg_settings","field":"contact_email","format":"mailto"}}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Email us</a></div><!-- /wp:button -->'
		);
		$this->assertStringContainsString( 'href="mailto:hello@example.org"', $button );
		$this->assertStringContainsString( '>Email us</a>', $button );

		// Empty value: fallback text stays.
		update_option( 'sifg_settings', $this->submission( array( 'contact_email' => '' ) ) );
		$this->assertStringContainsString( '>Fallback contact</p>', $paragraph( 'contact_email', 'Fallback contact' ) );
	}

	/**
	 * Resolves a sifg_settings binding.
	 *
	 * @param string      $field     Field key.
	 * @param string      $attribute Bound attribute.
	 * @param string|null $format    Format arg.
	 * @return string|null
	 */
	private function bind( $field, $attribute, $format = null ) {
		$args = array(
			'page'  => 'sifg_settings',
			'field' => $field,
		);
		if ( null !== $format ) {
			$args['format'] = $format;
		}

		return BindingSource::resolve( pegwork_registry(), $args, $attribute );
	}

	/**
	 * Formatting rules of the source, without blocks.
	 */
	public function test_binding_formats() {
		$registry = pegwork_registry();
		update_option( 'sifg_settings', $this->submission( array( 'contact_email' => 'a+b@example.org' ) ) );

		$this->assertSame( 'a+b@example.org', $this->bind( 'contact_email', 'content' ) );
		$this->assertSame( 'mailto:a+b@example.org', $this->bind( 'contact_email', 'url' ) );
		$this->assertSame( 'mailto:a+b@example.org', $this->bind( 'contact_email', 'content', 'mailto' ) );
		$this->assertNull( $this->bind( 'review_criteria', 'url' ) );
		$this->assertNull( $this->bind( 'moderator_email', 'url', 'mailto' ) );
		$this->assertNull( BindingSource::resolve( $registry, array( 'page' => 'sifg_settings' ), 'content' ) );
		$this->assertNull( BindingSource::resolve( $registry, array( 'page' => 'nope' ) + array( 'field' => 'x' ), 'content' ) );

		$fields = array_column( $registry->options_page( 'talk_settings' )['fields'], null, 'key' );
		$this->assertSame( 'Open', BindingSource::format( $fields['mode'], 'open', array(), 'content' ) );
		$this->assertSame( 'Accepting talks', BindingSource::format( $fields['accepting'], true, array(), 'content' ) );
		$this->assertNull( BindingSource::format( $fields['accepting'], false, array(), 'content' ) );
		$this->assertSame( 'March 2027', BindingSource::format( $fields['deadline'], '2027-03-05', array( 'format' => 'F Y' ), 'content' ) );
		$this->assertSame( '30', BindingSource::format( $fields['seats_default'], 30, array(), 'content' ) );
		$this->assertSame( '&lt;b&gt;', BindingSource::format( array( 'type' => 'text' ), '<b>', array(), 'content' ) );
		$this->assertSame( 'https://example.org/a?b=1&#038;c=2', BindingSource::format( array( 'type' => 'url' ), 'https://example.org/a?b=1&c=2', array(), 'url' ) );
		$this->assertNull( BindingSource::format( array( 'type' => 'url' ), 'javascript:alert(1)', array(), 'url' ) );
	}
}
