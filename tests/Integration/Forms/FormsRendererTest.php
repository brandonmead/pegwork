<?php
/**
 * Integration tests: the pegwork/form block and its markup.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Editor;
use PegworkForms\Renderer;
use PegworkForms\Schema;
use PegworkForms\Submission;
use WP_Block_Type_Registry;

require_once dirname( __DIR__, 2 ) . '/Support/FormsTestCase.php';

/**
 * Server-rendered form: names, labels, required text, ARIA, errors, success and fail-closed states.
 */
class FormsRendererTest extends FormsTestCase {

	/**
	 * Renders a block.
	 *
	 * @param string $form Form key.
	 */
	private function block( $form ) {
		return do_blocks( '<!-- wp:pegwork/form {"form":"' . $form . '"} /-->' );
	}

	/**
	 * The block is registered and renders the form for visitors.
	 */
	public function test_block_renders_accessible_form() {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'pegwork/form' ) );
		$html = $this->block( 'fr_contact' );

		$this->assertStringContainsString( '<div class="wp-block-pegwork-form pegwork-form" id="pegwork-fr_contact">', $html );
		$this->assertStringContainsString( 'method="post" action="http://example.org/contact/" novalidate data-pegwork-config="', $html );
		$this->assertStringContainsString( '<input type="hidden" name="pegwork_form[key]" value="fr_contact">', $html );
		$this->assertMatchesRegularExpression( '/name="pegwork_form\[token\]" value="[0-9]+\.[a-f0-9]{64}"/', $html );
		$this->assertStringNotContainsString( 'pegwork_form[nonce]', $html, 'Anonymous visitors get no nonce.' );
		$this->assertStringContainsString( 'aria-hidden="true" style="position:absolute;left:-10000px', $html );
		$this->assertStringContainsString( 'name="pegwork_form[url]" value="" tabindex="-1" autocomplete="off"', $html );

		$this->assertStringContainsString( '<label class="pegwork-form__label" for="pegwork-fr_contact-name">Name <span class="pegwork-form__required">(required)</span></label>', $html );
		$this->assertStringContainsString( 'type="text" id="pegwork-fr_contact-name" name="pegwork[name]" value="" aria-required="true" autocomplete="name">', $html );
		$this->assertStringContainsString( 'type="email" spellcheck="false" id="pegwork-fr_contact-email" name="pegwork[email]"', $html );
		$this->assertStringContainsString( '<fieldset class="pegwork-form__field pegwork-form__field--select" role="radiogroup"><legend class="pegwork-form__label">Topic</legend>', $html );
		$this->assertStringContainsString( '<input type="radio" id="pegwork-fr_contact-topic-1" name="pegwork[topic]" value="other"> <label for="pegwork-fr_contact-topic-1">Something else</label>', $html );
		$this->assertStringContainsString( '<p class="pegwork-form__help" id="pegwork-fr_contact-frequency-help">Only needed if &quot;Send me the newsletter&quot; is checked.</p>', $html );
		$this->assertStringContainsString( '<option value="">Choose an option</option>', $html );
		$this->assertStringContainsString( 'name="pegwork[message]" rows="6" aria-required="true" aria-describedby="pegwork-fr_contact-message-help"></textarea>', $html );
		$this->assertStringContainsString( '<input type="checkbox" class="pegwork-form__checkbox" id="pegwork-fr_contact-consent" name="pegwork[consent]" value="1" aria-required="true">', $html );
		$this->assertStringContainsString( '>Send message</button>', $html );
		$this->assertStringNotContainsString( 'There is a problem', $html );
	}

	/**
	 * Logged-in users get the nonce field.
	 */
	public function test_logged_in_users_get_a_nonce() {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertStringContainsString( 'name="pegwork_form[nonce]"', $this->block( 'fr_contact' ) );
	}

	/**
	 * A re-render after errors: summary with links, per-field messages linked by aria-describedby, values kept and escaped.
	 */
	public function test_error_rerender() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['pegwork_form']     = array(
			'key'   => 'fr_contact',
			'token' => \PegworkForms\Token::create( 'fr_contact', time() - 30 ),
			'url'   => '',
		);
		$_POST['pegwork']          = $this->contact_values(
			array(
				'name'    => '"><script>alert(1)</script>',
				'email'   => 'nope',
				'topic'   => '',
				'message' => '',
			)
		);
		Submission::handle();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$html = $this->block( 'fr_contact' );
		$this->assertStringContainsString( '<div class="pegwork-form__errors" tabindex="-1" autofocus aria-labelledby="pegwork-fr_contact-errors-title"><h2 class="pegwork-form__errors-title" id="pegwork-fr_contact-errors-title">There is a problem</h2>', $html );
		$this->assertStringContainsString( '<li><a href="#pegwork-fr_contact-email">Enter Email as an email address, like name@example.com.</a></li>', $html );
		$this->assertStringContainsString( '<li><a href="#pegwork-fr_contact-message">Message is required.</a></li>', $html );
		$this->assertStringContainsString( '<p class="pegwork-form__error" id="pegwork-fr_contact-email-error"><span class="pegwork-form__sr">Error: </span>', $html );
		$this->assertStringContainsString( 'name="pegwork[email]" value="nope" aria-required="true" aria-invalid="true" aria-describedby="pegwork-fr_contact-email-error"', $html );
		$this->assertStringContainsString( 'aria-describedby="pegwork-fr_contact-message-help pegwork-fr_contact-message-error"', $html );
		$this->assertStringContainsString( 'value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'value="1" checked aria-required="true">', $html, 'The consent box stays checked.' );
	}

	/**
	 * Notices for outcomes that store nothing.
	 */
	public function test_expired_notice() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['pegwork_form']     = array(
			'key'   => 'fr_contact',
			'token' => 'old',
		);
		$_POST['pegwork']          = $this->contact_values();
		Submission::handle();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertStringContainsString( 'role="alert" tabindex="-1" autofocus><p>This page was open too long. Check your answers and send again.</p>', $this->block( 'fr_contact' ) );
		$this->assertSame( 0, $this->count_entries() );
	}

	/**
	 * After the redirect, the success message replaces the form in a focused status region.
	 */
	public function test_success_state() {
		$_GET['pegwork_sent'] = 'fr_contact';
		$html                 = $this->block( 'fr_contact' );
		$this->assertStringContainsString( '<div class="pegwork-form__status" role="status" tabindex="-1" autofocus><p>Thanks. We will reply soon.</p></div>', $html );
		$this->assertStringNotContainsString( '<form', $html );

		Renderer::reset();
		$_GET['pegwork_sent'] = 'other_form';
		$this->assertStringContainsString( '<form', $this->block( 'fr_contact' ) );
	}

	/**
	 * Unknown and unsupported forms render nothing for visitors and a notice for managers.
	 */
	public function test_fail_closed_states() {
		$this->assertSame( '', $this->block( 'nope' ) );
		$this->assertSame( '', $this->block( 'fr_turnstile' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:pegwork/form /-->' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'Pegwork form &quot;nope&quot; is not defined.', $this->block( 'nope' ) );
		$this->assertStringContainsString( 'Turnstile', $this->block( 'fr_turnstile' ) );
		$this->assertStringContainsString( 'Choose a form for this block.', do_blocks( '<!-- wp:pegwork/form /-->' ) );
		$this->assertStringContainsString( 'Choose a form for this block.', $this->block( 'Bad-Key' ), 'A malformed key is treated as no key.' );
	}

	/**
	 * Steps render as fieldsets on one page; access-limited forms ask visitors to log in.
	 */
	public function test_steps_and_access() {
		$this->assertStringContainsString( 'Please <a href="http://example.org/wp-login.php?redirect_to=', $this->block( 'fr_members' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringContainsString( 'You do not have permission to use this form.', $this->block( 'fr_members' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		Renderer::reset();
		$html = $this->block( 'fr_members' );
		$this->assertStringContainsString( '<fieldset class="pegwork-form__step"><legend class="pegwork-form__step-title"><h2 class="pegwork-form__step-heading">About you</h2></legend><p class="pegwork-form__step-description">Tell us who you are.</p>', $html );
		$this->assertStringContainsString( 'type="text" inputmode="decimal" id="pegwork-fr_members-age"', $html );
		$this->assertStringContainsString( 'type="date" id="pegwork-fr_members-start"', $html );
		$this->assertSame( 1, substr_count( $html, '<button' ) );
	}

	/**
	 * A form placed twice gets distinct IDs.
	 */
	public function test_repeated_form_ids_are_unique() {
		$html = $this->block( 'fr_contact' ) . $this->block( 'fr_contact' );
		$this->assertSame( 1, substr_count( $html, 'id="pegwork-fr_contact-name"' ) );
		$this->assertSame( 1, substr_count( $html, 'id="pegwork-fr_contact-2-name"' ) );
	}

	/**
	 * The editor config lists forms by key and label, and whether this release shows them.
	 */
	public function test_editor_config() {
		$forms = array_column( Editor::config()['forms'], null, 'key' );
		$this->assertSame(
			array(
				'key'       => 'fr_contact',
				'label'     => 'Contact us',
				'supported' => true,
			),
			$forms['fr_contact']
		);
		$this->assertFalse( $forms['fr_turnstile']['supported'] );
	}

	/**
	 * The entries table matches the design, and the version gate skips a second install.
	 */
	public function test_entries_table() {
		global $wpdb;
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
		$this->assertSame(
			array( 'id', 'form_key', 'status', 'email', 'post_id', 'user_id', 'field_values', 'meta', 'token_hash', 'fingerprint', 'created_at', 'confirmed_at' ),
			$columns
		);
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
		$this->assertFalse( Schema::maybe_install() );
	}
}
