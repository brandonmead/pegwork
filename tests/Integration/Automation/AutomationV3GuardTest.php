<?php
/**
 * Integration tests: the registry accepts new shapes before the engine runs them.
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
 * Reads, relate, `on_error`, `call_webhook.record` and the new template filters run (Automation v3 PR 3),
 * and loops run (PR 4, tests/Integration/Automation/AutomationLoopTest.php). A step type without a handler
 * is still refused before the first step. Manual triggers (PR 5) are covered by AutomationManualTest.
 *
 * Automation v4 PR 2 (registry): the `v4` fixture's shapes (wait until, trash_record, start_automation,
 * role recipients, read `created`) are refused before the first step until the engine PRs land. A wait
 * until a date runs since v4 PR 3, so barn_task_due_reminder is no longer refused.
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
	 * Every fixture runs, loops included, except the v4 shapes, each refused with a reason naming it; a step
	 * type without a handler, here inside a loop body, is still refused with the reason naming it.
	 */
	public function test_every_fixture_runs_and_unknown_types_are_refused() {
		$refused = array();
		foreach ( self::automations() as $path => $definition ) {
			$reason = Engine::instance()->unsupported( $definition );
			if ( null !== $reason ) {
				$refused[ $path ] = $reason;
			}
		}
		$v4 = 'tests/fixtures/valid/v4/automations/';
		$this->assertSame(
			array(
				$v4 . 'barn_gift_receipt_start.json'  => 'step "receipt" has type "start_automation", which this version of the automation module cannot run.',
				$v4 . 'barn_v4_shapes.json'           => 'step "latest" reads by creation time ("created"), which this version of the automation module cannot do.',
				$v4 . 'barn_volunteer_retention.json' => 'step "old" reads by creation time ("created"), which this version of the automation module cannot do.',
				$v4 . 'barn_volunteer_review.json'    => 'step "notify" sends to a role ("{role:...}"), which this version of the automation module cannot do.',
				$v4 . 'barn_weekly_note.json'         => 'step "note" sends to a role ("{role:...}"), which this version of the automation module cannot do.',
			),
			$refused,
			'Only the v4 shapes are refused (barn_gift_receipt has none).'
		);

		$definition                                 = pegwork_registry()->automation( 'barn_tasks_digest' );
		$definition['steps'][1]['steps'][0]['type'] = 'send_sms';
		$this->assertSame( 'step "remind" has type "send_sms", which this version of the automation module cannot run.', Engine::instance()->unsupported( $definition ) );

		$retention             = self::automations()[ $v4 . 'barn_volunteer_retention.json' ];
		$retention['steps'][0] = array_diff_key( $retention['steps'][0], array( 'created' => true ) );
		$this->assertSame( 'step "remove" has type "trash_record", which this version of the automation module cannot run.', Engine::instance()->unsupported( $retention ), 'A trash step inside a loop body is refused.' );
	}
}
