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
 * Until the engine PRs add the handlers (docs/automation-v3-design.md section 11, PRs 3 to 5), a run of an
 * automation that uses a read, loop, relate, `on_error`, `call_webhook.record` or a new template filter fails
 * with `unsupported_step` before its first step, and a manual trigger never starts a run.
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
	 * Every v3 fixture is refused, with the reason naming a step; every earlier fixture runs as before.
	 */
	public function test_v3_definitions_are_refused_and_earlier_ones_are_not() {
		$v3 = 0;
		foreach ( self::automations() as $path => $definition ) {
			$reason = Engine::instance()->unsupported( $definition );
			if ( str_starts_with( $path, 'tests/fixtures/valid/reads/' ) ) {
				++$v3;
				$this->assertIsString( $reason, $path );
				$this->assertStringStartsWith( 'step "', $reason, $path );
			} else {
				$this->assertNull( $reason, $path );
			}
		}
		$this->assertSame( 7, $v3 );

		$definition = self::automations()['tests/fixtures/valid/reads/automations/barn_tasks_digest.json'];
		$this->assertStringContainsString( '"find_records"', Engine::instance()->unsupported( $definition ) );

		// Features on step types that have handlers are refused too.
		$mail   = array(
			'id'      => 'mail',
			'type'    => 'send_email',
			'to'      => 'ops@example.org',
			'subject' => 'Hi',
			'text'    => 'Hi',
		);
		$checks = array(
			'on_error' => array( 'on_error' => 'continue' ) + $mail,
			'| number' => array( 'text' => 'Total {{record.amount | number:2}}' ) + $mail,
			'| add'    => array( 'subject' => 'Due {{today | add:"P2D"}}' ) + $mail,
			'#each'    => array( 'text' => '{{#each steps.due.records}}x{{/each}}' ) + $mail,
			'record'   => array(
				'id'           => 'send',
				'type'         => 'call_webhook',
				'url'          => 'https://hooks.example.org/',
				'record'       => '{{record.id}}',
				'content_type' => 'barn_gift',
			),
		);
		foreach ( $checks as $label => $step ) {
			$branch = array(
				array(
					'id'       => 'route',
					'type'     => 'decision',
					'branches' => array(
						array(
							'label' => 'Always',
							'when'  => array(
								'var' => 'record.id',
								'op'  => 'not_empty',
							),
							'steps' => array( $step ),
						),
					),
				),
			);
			$this->assertNotNull( Engine::instance()->unsupported( array( 'steps' => $branch ) ), $label );
		}
		$this->assertNull( Engine::instance()->unsupported( array( 'steps' => array( array( 'text' => 'Due {{record.received_on | date | default:"never"}}' ) + $mail ) ) ) );
	}

	/**
	 * A gift's record.created runs of barn_gift_thanks and barn_lifetime_milestone fail before any step: no
	 * step row, no email, no task.
	 */
	public function test_runs_fail_before_the_first_step() {
		reset_phpmailer_instance();
		$contact = Records::create(
			'barn_contact',
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.org',
			),
			'private'
		);
		$this->assertIsInt( $contact );
		$gift = Records::create(
			'barn_gift',
			array(
				'name'        => 'Gift',
				'amount'      => 300,
				'received_on' => '2026-11-02',
				'gift_donor'  => array( $contact ),
			),
			'private'
		);
		$this->assertIsInt( $gift );
		$this->drain();

		foreach ( array( 'barn_gift_thanks', 'barn_lifetime_milestone' ) as $key ) {
			$run = $this->only_run( $key );
			$this->assertSame( 'failed', $run['status'], $key );
			$this->assertStringStartsWith( 'unsupported_step: step "donor" has type "get_record"', (string) $run['error'], $key );
			$this->assertSame( array(), $this->steps( $run['id'] ), $key );
		}
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'barn_task',
					'post_status' => 'any',
				)
			)
		);
		$this->assertSame( '', Records::get( $contact )['values']['last_gift_on'] );
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
