<?php
/**
 * Integration tests: the registry accepts Automation v3 shapes before the engine runs them.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Registry\Normalizer;
use Pegwork\Tests\Support\AutomationTestCase;
use PegworkAutomation\Engine;
use PegworkAutomation\Manual;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Reads, relate, `on_error`, `call_webhook.record` and the new template filters run (Automation v3 PR 3),
 * and loops run (PR 4, tests/Integration/Automation/AutomationLoopTest.php). A step type without a handler
 * is still refused before the first step. Until the manual-trigger PR lands (docs/automation-v3-design.md
 * section 11, PR 5), a manual trigger never starts a run.
 */
class AutomationV3GuardTest extends AutomationTestCase {

	/**
	 * Registers the reads fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/reads';
	}

	/**
	 * Canonical automations of the repository's valid packages, by path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function automations() {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		foreach ( array_merge( glob( $root . '/tests/fixtures/valid/*/automations/*.json' ), glob( $root . '/examples/*/automations/*.json' ) ) as $file ) {
			$found[ substr( $file, strlen( $root ) + 1 ) ] = Normalizer::normalize( 'automation', json_decode( file_get_contents( $file ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		}
		return $found;
	}

	/**
	 * Every fixture runs, loops included; a step type without a handler, here inside a loop body, is still
	 * refused with the reason naming it.
	 */
	public function test_every_fixture_runs_and_unknown_types_are_refused() {
		$refused = array();
		foreach ( self::automations() as $path => $definition ) {
			$reason = Engine::instance()->unsupported( $definition );
			if ( null !== $reason ) {
				$refused[ $path ] = $reason;
			}
		}
		$this->assertSame( array(), $refused );

		$definition                                 = pegwork_registry()->automation( 'barn_tasks_digest' );
		$definition['steps'][1]['steps'][0]['type'] = 'send_sms';
		$this->assertSame( 'step "remind" has type "send_sms", which this version of the automation module cannot run.', Engine::instance()->unsupported( $definition ) );
	}

	/**
	 * A manual trigger starts no run: no event matches it, and `wp pegwork automation run` refuses it.
	 */
	public function test_manual_trigger_is_inert() {
		$gift = Records::create( 'barn_gift', array( 'name' => 'Gift' ), 'private' );
		$this->assertIsInt( $gift );

		$result = ( new Manual( $this->clock ) )->run( 'barn_resend_thanks', $gift );
		$this->assertWPError( $result );
		$this->assertSame( 'pegwork_unsupported_trigger', $result->get_error_code() );
		$this->assertSame( array(), $this->runs( 'barn_resend_thanks' ) );
		$this->assertSame( array(), $this->runs( 'reads_manual_list' ) );
	}
}
