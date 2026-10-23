<?php
/**
 * Base class for loop tests.
 *
 * @package Pegwork
 */

namespace Pegwork\Tests\Support;

use Pegwork\Api\Records;
use PegworkAutomation\Engine;

require_once __DIR__ . '/AutomationTestCase.php';

/**
 * Registers tests/fixtures/valid/engine-loops (the BarnCRM types, options page `barn_settings`, the digest
 * of worked example c and `lp_items`), answers outbound requests with a recorder, and sets the development
 * email the summaries go to.
 */
abstract class LoopsTestCase extends AutomationTestCase {

	/**
	 * Development email of the `barn_settings` options page.
	 */
	const DEVELOPMENT = 'development@example.org';

	/**
	 * Requests the receiver got: url, headers and decoded body.
	 *
	 * @var array<int, array{url: string, headers: array<string, string>, body: array<string, mixed>}>
	 */
	protected $requests = array();

	/**
	 * HTTP status the receiver answers with.
	 *
	 * @var int
	 */
	protected $status = 200;

	/**
	 * Package directory: the engine_loops fixture.
	 *
	 * @return string
	 */
	protected function package() {
		return dirname( __DIR__ ) . '/fixtures/valid/engine-loops';
	}

	/**
	 * A fresh mailer, the options page value and the receiver.
	 */
	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		update_option( 'barn_settings', array( 'development_email' => self::DEVELOPMENT ) );
		$this->requests = array();
		$this->status   = 200;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'     => (string) $url,
					'headers' => (array) $args['headers'],
					'body'    => (array) json_decode( (string) $args['body'], true ),
				);
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => $this->status,
						'message' => 'Status',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * A published task.
	 *
	 * @param string      $name  Name.
	 * @param string|null $email Assignee email, or null for none.
	 * @param string      $due   Due date.
	 * @param bool        $done  Done.
	 * @param int[]       $gifts Linked gifts (task_gift).
	 * @return int
	 */
	protected function task( $name, $email = null, $due = '2026-11-04', $done = false, array $gifts = array() ) {
		$values = array(
			'name'   => $name,
			'due_on' => $due,
			'done'   => $done,
		);
		if ( null !== $email ) {
			$values['assignee_email'] = $email;
		}
		if ( array() !== $gifts ) {
			$values['task_gift'] = $gifts;
		}
		$id = Records::create( 'barn_task', $values, 'publish' );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A private gift.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	protected function gift( $name ) {
		$id = Records::create(
			'barn_gift',
			array(
				'name'   => $name,
				'amount' => 50,
			),
			'private'
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * Starts an `lp_items` run (queued, a wake-up scheduled) without running it.
	 *
	 * @return int Run ID.
	 */
	protected function start_items() {
		$id = Engine::instance()->triggers()->webhook( 'lp_items', pegwork_registry()->automation( 'lp_items' ), array( 'source' => 'test' ), null );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * Sent mail as `to` and `subject`, in order.
	 *
	 * @return array<int, array{to: string, subject: string}>
	 */
	protected function sent() {
		return array_map(
			fn( $mail ) => array(
				'to'      => (string) $mail['to'][0][0],
				'subject' => (string) $mail['subject'],
			),
			tests_retrieve_phpmailer_instance()->mock_sent
		);
	}

	/**
	 * Step rows of a run as `step_id`, `iteration` and `status` triples, oldest first.
	 *
	 * @param int $id Run ID.
	 * @return array<int, string>
	 */
	protected function trace( $id ) {
		return array_map(
			fn( $row ) => $row['step_id'] . ( null === $row['iteration'] ? '' : '#' . $row['iteration'] ) . ( 'ok' === $row['status'] ? '' : ':' . $row['status'] ),
			$this->steps( $id )
		);
	}
}
