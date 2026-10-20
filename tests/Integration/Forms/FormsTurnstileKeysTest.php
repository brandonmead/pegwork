<?php
/**
 * Turnstile without keys fails closed.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Forms;

use Pegwork\Tests\Support\FormsTestCase;
use PegworkForms\Definition;
use PegworkForms\Submission;
use PegworkForms\Turnstile;

/**
 * A defined constant cannot be undefined within one process, so FormsTestCase::without_turnstile_keys()
 * points Turnstile at constant names that do not exist.
 */
class FormsTurnstileKeysTest extends FormsTestCase {

	/**
	 * Removes both keys.
	 */
	public function set_up() {
		parent::set_up();
		self::without_turnstile_keys();
	}

	/**
	 * One key alone is not enough.
	 */
	public function test_both_keys_are_needed() {
		self::without_turnstile_keys( 'site' );
		$this->assertNull( Turnstile::keys() );
		self::without_turnstile_keys( 'secret' );
		$this->assertNull( Turnstile::keys() );
		$this->assertNotNull( Definition::unsupported( pegwork_registry()->form( 'fr_turnstile' ) ) );
	}

	/**
	 * Missing keys: the form renders nothing for visitors and a notice for managers, its POSTs are
	 * ignored, and siteverify is never trusted.
	 */
	public function test_missing_keys_fail_closed() {
		$this->assertNull( Turnstile::keys() );
		$form = pegwork_registry()->form( 'fr_turnstile' );
		$this->assertNotNull( Definition::unsupported( $form ) );
		$this->assertNull( Definition::unsupported( pegwork_registry()->form( 'fr_contact' ) ), 'Forms without Turnstile still run.' );

		$this->assertSame( '', do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' ) );
		$this->assertSame( 'unverified', Turnstile::verify( 'XXXX.DUMMY.TOKEN.XXXX' ) );
		$this->assertSame( '', Turnstile::widget( 'pegwork-fr_turnstile' ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['pegwork_form']     = array(
			'key'     => 'fr_turnstile',
			'token'   => \PegworkForms\Token::create( 'fr_turnstile', time() - 30 ),
			'url'     => '',
			'captcha' => 'XXXX.DUMMY.TOKEN.XXXX',
		);
		$_POST['pegwork']          = array( 'note' => 'Hello' );
		Submission::handle();
		$this->assertNull( Submission::state( 'fr_turnstile' ), 'POSTs to a form without keys are ignored.' );
		$this->assertSame( 0, $this->count_entries() );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\PegworkForms\Renderer::reset();
		$html = do_blocks( '<!-- wp:pegwork/form {"form":"fr_turnstile"} /-->' );
		$this->assertStringContainsString( 'is not shown to visitors: it uses Cloudflare Turnstile, and PEGWORK_TURNSTILE_SITE_KEY and PEGWORK_TURNSTILE_SECRET_KEY are not both set in wp-config.php.', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * The admin notice names the forms and the constants, for users who manage forms only.
	 */
	public function test_admin_notice_for_managers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Turnstile::admin_notice();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Turnstile::class, 'admin_notice' ) ) );
		ob_start();
		Turnstile::admin_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'the form &quot;Needs Turnstile&quot; uses Cloudflare Turnstile, but its keys are not set', $notice );
		$this->assertStringContainsString( 'Define PEGWORK_TURNSTILE_SITE_KEY and PEGWORK_TURNSTILE_SECRET_KEY in wp-config.php.', $notice );
	}
}
