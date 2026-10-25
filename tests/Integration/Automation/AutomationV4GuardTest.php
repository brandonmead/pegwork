<?php
/**
 * Integration tests: the registry accepts Automation v4 shapes before the engine runs them.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Integration\Automation;

use Pegwork\Api\Records;
use Pegwork\Tests\Support\AutomationTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/AutomationTestCase.php';

/**
 * Automation v4 PR 2 (registry, docs/automation-v4-design.md section 11): the `v4` fixture loads, and a run of
 * an automation with a v4 shape that has no engine yet (start_automation) fails `unsupported_step` before its
 * first step, so nothing half-runs until the engine PRs land. A wait until a date runs since v4 PR 3.
 */
class AutomationV4GuardTest extends AutomationTestCase {

	/**
	 * Registers the v4 fixture package.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__, 2 ) . '/fixtures/valid/v4';
	}

	/**
	 * Resets the mail mock.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * A new task and a new gift start runs that fail before any step: no step rows, no mail, no write.
	 */
	public function test_v4_runs_fail_before_the_first_step() {
		$this->assertSame( array(), array_map( 'strval', \Pegwork\Plugin::result()->errors ) );
		$this->assertCount( 7, pegwork_registry()->automations() );

		$task = Records::create(
			'barn_task',
			array(
				'name'           => 'Call the barn',
				'due_on'         => '2026-11-20',
				'assignee_email' => 'staff@example.org',
			),
			'publish'
		);
		$gift = Records::create(
			'barn_gift',
			array(
				'name'   => 'Gift',
				'amount' => 300,
			),
			'publish'
		);
		$this->assertIsInt( $task );
		$this->assertIsInt( $gift );
		$this->drain();

		$run = $this->only_run( 'barn_gift_receipt_start' );
		$this->assertSame( 'failed', $run['status'] );
		$this->assertStringStartsWith( 'unsupported_step: step "', $run['error'] );
		$this->assertSame( array(), $this->steps( $run['id'] ), 'barn_gift_receipt_start ran no step.' );

		// Since v4 PR 3 the date wait runs: the reminder waits at its first step.
		$run = $this->only_run( 'barn_task_due_reminder' );
		$this->assertSame( 'waiting', $run['status'] );
		$this->assertSame( 'before_due', $run['current_step'] );
		$this->assertSame( array(), $this->runs( 'barn_gift_receipt' ), 'No receipt run was started.' );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent, 'No mail.' );
		$this->assertSame( '', (string) Records::get( $task )['values']['reminded_on'], 'No stamp.' );
	}
}
