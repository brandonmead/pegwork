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

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Reads, relate, `on_error`, `call_webhook.record` and the new template filters run (Automation v3 PR 3).
 * Until the loop PR lands (docs/automation-v3-design.md section 11, PR 4), a run of an automation that
 * holds a `loop` fails with `unsupported_step` before its first step. Manual triggers (PR 5) are covered
 * by AutomationManualTest.
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
	 * Only the fixtures that hold a loop are refused, with the reason naming the loop step; every other
	 * fixture (reads, relate, on_error, the new filters included) runs.
	 */
	public function test_only_loops_are_refused() {
		$refused = array();
		foreach ( self::automations() as $path => $definition ) {
			$reason = Engine::instance()->unsupported( $definition );
			if ( null !== $reason ) {
				$refused[ $path ] = $reason;
			}
		}
		$this->assertSame(
			array(
				'tests/fixtures/valid/reads/automations/barn_tasks_digest.json' => 'step "each_task" has type "loop", which this version of the automation module cannot run.',
				'tests/fixtures/valid/reads/automations/reads_manual_list.json' => 'step "each" has type "loop", which this version of the automation module cannot run.',
			),
			$refused
		);
	}

	/**
	 * A firing of the digest (which holds a loop) fails before any step: no step row, no email.
	 */
	public function test_loop_run_fails_before_the_first_step() {
		reset_phpmailer_instance();
		$task = Records::create(
			'barn_task',
			array(
				'name'   => 'Call Ada',
				'due_on' => '2026-11-03',
			),
			'publish'
		);
		$this->assertIsInt( $task );

		Engine::instance()->schedules()->fire( 'barn_tasks_digest' );
		$this->drain();

		$run = $this->only_run( 'barn_tasks_digest' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'unsupported_step: step "each_task" has type "loop"', (string) $run['error'] );
		$this->assertSame( array(), $this->steps( $run['id'] ) );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertSame( '', (string) Records::get( $task )['values']['reminded_on'] );
	}
}
