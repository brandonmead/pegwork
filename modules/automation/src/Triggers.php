<?php
/**
 * Event triggers.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;

/**
 * Listens on the core event bus. For each enabled automation whose event trigger matches, it evaluates
 * `when` synchronously (the payload's old values exist only now), applies the loop depth cap and the
 * idempotency key, inserts a `queued` run and schedules its wake-up. The saving request never runs steps.
 *
 * A `record.*` event binds the record in its payload; a form event binds `record_id` when the trigger
 * binds its form's record (binds_form()); a binding form event without a record inserts a `skipped` run.
 */
final class Triggers {

	/**
	 * Default loop depth cap: a trigger at this origin depth or deeper is logged as skipped.
	 */
	public const MAX_DEPTH = 3;

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Uses the engine's clock, runs and scheduler.
	 *
	 * @param Engine $engine Engine.
	 */
	public function __construct( Engine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Handles one bus event.
	 *
	 * @param string               $name    Event name.
	 * @param array<string, mixed> $payload Event payload.
	 * @return int[] IDs of the runs created (queued or skipped).
	 */
	public function handle( string $name, array $payload ): array {
		$created = array();

		foreach ( pegwork_registry()->automations() as $key => $definition ) {
			$trigger = $definition['trigger'];
			if ( 'event' !== $trigger['type'] || $trigger['event'] !== $name ) {
				continue;
			}
			if ( isset( $trigger['content_type'] ) && ( $payload['type'] ?? null ) !== $trigger['content_type'] ) {
				continue;
			}
			if ( isset( $trigger['form'] ) && ( $payload['form'] ?? null ) !== $trigger['form'] ) {
				continue;
			}
			if ( ! Overrides::enabled( $definition ) ) {
				continue;
			}

			$id = $this->start( (string) $key, $definition, $name, $payload );
			if ( $id > 0 ) {
				$created[] = $id;
			}
		}
		return $created;
	}

	/**
	 * Starts one run for a matching automation.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param string               $name       Event name.
	 * @param array<string, mixed> $payload    Event payload.
	 * @return int Run ID, or 0 when none was created.
	 */
	private function start( string $key, array $definition, string $name, array $payload ): int {
		$form = self::binds_form( $definition['trigger'] );
		if ( $form ) {
			$record_id = is_numeric( $payload['record_id'] ?? null ) && (int) $payload['record_id'] > 0 ? (int) $payload['record_id'] : null;
		} else {
			$record_id = str_starts_with( $name, 'record.' ) && isset( $payload['id'] ) ? (int) $payload['id'] : null;
		}
		$record  = null === $record_id ? null : Records::get( $record_id );
		$context = new Context( $this->engine->clock(), $record, $payload );

		if ( isset( $definition['trigger']['when'] ) && ! Conditions::evaluate( $definition['trigger']['when'], $context ) ) {
			return 0;
		}

		$depth = (int) ( $payload['origin']['depth'] ?? 0 );
		$run   = array(
			'automation'      => $key,
			'trigger_type'    => 'event',
			'record_id'       => $record_id,
			'depth'           => $depth,
			'definition_hash' => self::hash( $definition ),
			'context'         => array(
				'event'   => $name,
				'trigger' => $payload,
			),
		);

		/**
		 * Filters the loop depth cap: triggers at this origin depth or deeper are logged as skipped.
		 *
		 * @param int $max Default 3.
		 */
		$max = (int) apply_filters( 'pegwork_automation_max_depth', self::MAX_DEPTH );
		if ( $depth >= $max ) {
			return $this->engine->runs()->insert(
				$run + array(
					'status' => 'skipped',
					'error'  => sprintf( 'loop_depth: trigger depth %1$d reached the limit of %2$d.', $depth, $max ),
				)
			);
		}
		if ( $form && null === $record_id ) {
			return $this->engine->runs()->insert(
				$run + array(
					'status' => 'skipped',
					'error'  => sprintf( 'no_record: the %s event names no record, but the automation binds the record its form wrote.', $name ),
				)
			);
		}

		return $this->enqueue( $run, $definition, $context );
	}

	/**
	 * Whether a trigger binds the record a form wrote: an event trigger on `form.confirmed`, or on
	 * `form.submitted` of a form without `confirm`, whose form has a `target` (the registry rule, read
	 * against the current form definition).
	 *
	 * @param array<string, mixed> $trigger Canonical trigger.
	 */
	public static function binds_form( array $trigger ): bool {
		$event = (string) ( $trigger['event'] ?? '' );
		if ( 'event' !== ( $trigger['type'] ?? '' ) || ! in_array( $event, array( 'form.submitted', 'form.confirmed' ), true ) || ! isset( $trigger['form'] ) ) {
			return false;
		}
		$form = pegwork_registry()->form( (string) $trigger['form'] );
		return null !== $form && isset( $form['target'] ) && ( 'form.confirmed' === $event || ! isset( $form['confirm'] ) );
	}

	/**
	 * Inserts a queued run unless its idempotency key exists, and schedules its wake-up for now. Shared by
	 * event triggers, schedules and `wp pegwork automation run`.
	 *
	 * @param array<string, mixed> $run        Run row for Runs::insert(), without status and key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param Context              $context    Context the idempotency template renders in.
	 * @return int Run ID, or 0 when the key exists.
	 */
	public function enqueue( array $run, array $definition, Context $context ): int {
		if ( isset( $definition['idempotency'] ) ) {
			$run['idempotency_key'] = self::key( Templates::render( (string) $definition['idempotency'], $context ) );
		}

		$id = $this->engine->runs()->insert( $run + array( 'status' => 'queued' ) );
		if ( $id > 0 ) {
			$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $this->engine->clock()->now()->getTimestamp() );
		}
		return $id;
	}

	/**
	 * Starts a run of an event automation against a record, outside any event (`wp pegwork automation run
	 * --record`): `when` is not evaluated (there are no old values), the idempotency key applies, and the
	 * payload is the record's `id`, `type` and `status` with origin source `cli`.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param array<string, mixed> $record     Records::get() shape.
	 * @return int Run ID, or 0 when the key exists.
	 */
	public function manual( string $key, array $definition, array $record ): int {
		$payload = array(
			'id'     => (int) $record['id'],
			'type'   => (string) $record['type'],
			'status' => (string) $record['status'],
			'origin' => array(
				'source' => 'cli',
				'depth'  => 0,
			),
		);
		$run     = array(
			'automation'      => $key,
			'trigger_type'    => 'manual',
			'record_id'       => (int) $record['id'],
			'depth'           => 0,
			'definition_hash' => self::hash( $definition ),
			'context'         => array( 'trigger' => $payload ),
		);
		return $this->enqueue( $run, $definition, new Context( $this->engine->clock(), $record, $payload ) );
	}

	/**
	 * Starts a run of a `manual` automation for a staff member (the record editor button, the list's Run
	 * now, or `wp pegwork automation run`). Callers authorize and check the record's type; this checks the
	 * trigger's `when` against the record. The payload is `user_id` and `source` with origin depth 0. A
	 * `request` ID (one per confirmation dialog or list page) is the idempotency key `manual:<user>:<request>`,
	 * so a double submit starts one run; without one the automation's own `idempotency` template applies.
	 *
	 * @param string                    $key        Automation key.
	 * @param array<string, mixed>      $definition Canonical automation.
	 * @param array<string, mixed>|null $record     Records::get() shape, or null when the trigger has no content_type.
	 * @param int                       $user       User who started it.
	 * @param string                    $source     `editor`, `list` or `cli`.
	 * @param string|null               $request    Request ID, or null.
	 * @return array{run: int, duplicate: bool}|null The run (or the earlier run of the same key), or null when `when` is false.
	 */
	public function staff( string $key, array $definition, ?array $record, int $user, string $source, ?string $request = null ): ?array {
		$payload = array(
			'user_id' => $user,
			'source'  => $source,
			'origin'  => array(
				'source' => 'manual',
				'depth'  => 0,
			),
		);
		$context = new Context( $this->engine->clock(), $record, $payload );
		if ( isset( $definition['trigger']['when'] ) && ! Conditions::evaluate( $definition['trigger']['when'], $context ) ) {
			return null;
		}

		$run = array(
			'automation'      => $key,
			'trigger_type'    => 'manual',
			'record_id'       => null === $record ? null : (int) $record['id'],
			'depth'           => 0,
			'definition_hash' => self::hash( $definition ),
			'context'         => array( 'trigger' => $payload ),
		);
		if ( null !== $request ) {
			// The request ID wins over the automation's own idempotency template, as a delivery ID does.
			unset( $definition['idempotency'] );
			$run['idempotency_key'] = self::request_key( $user, $request );
		}

		$id = $this->enqueue( $run, $definition, $context );
		if ( $id > 0 ) {
			return array(
				'run'       => $id,
				'duplicate' => false,
			);
		}
		$stored  = $run['idempotency_key'] ?? ( isset( $definition['idempotency'] ) ? self::key( Templates::render( (string) $definition['idempotency'], $context ) ) : null );
		$earlier = null === $stored ? null : $this->engine->runs()->find_key( $key, $stored );
		return array(
			'run'       => (int) ( $earlier['id'] ?? 0 ),
			'duplicate' => true,
		);
	}

	/**
	 * Starts a run of a `manual` automation from a start_automation step of a parent run (Manual::chain(),
	 * which checked the automation and the record). Asynchronous: the child is queued and scheduled now.
	 *
	 * - Idempotency key `start:<parent run>-<step>`, plus `-<iteration>` inside loops, winning over the
	 *   target's own `idempotency`: a parent that resumes or is retried at this step finds its earlier child
	 *   (or skipped run) and starts no second one. A queued earlier child gets its wake-up again.
	 * - The target's `when` is checked against the record: false starts nothing (`started: false`).
	 * - Origin depth is the parent's depth plus 1; at or past `pegwork_automation_max_depth` a `skipped` run
	 *   (`loop_depth: ...`) is logged instead, as events do.
	 * - Payload: `user_id` (the parent's `trigger.user_id`, else 0), `source` `automation` and `origin`.
	 *   `context.parent` `{ run, automation, step }` (plus `iteration`) is stored beside it, outside the
	 *   payload, so the child's templates cannot read it.
	 *
	 * @param string                    $key        Automation key.
	 * @param array<string, mixed>      $definition Canonical automation.
	 * @param array<string, mixed>|null $record     Records::get() shape, or null when the trigger has no content_type.
	 * @param array<string, mixed>      $starter    Parent run row.
	 * @param string                    $step       Step ID in the parent.
	 * @param int|null                  $iteration  1-based loop item number, or null.
	 * @return array{run: int, started: bool, skipped: string|null, duplicate: bool} The child run (0 when
	 *         `when` is false); `skipped` is `loop_depth` for a skipped run.
	 */
	public function chained( string $key, array $definition, ?array $record, array $starter, string $step, ?int $iteration = null ): array {
		$parent_id = (int) $starter['id'];
		$stored    = self::key( 'start:' . $parent_id . '-' . $step . ( null === $iteration ? '' : '-' . $iteration ) );

		$earlier = $this->engine->runs()->find_key( $key, $stored );
		if ( null !== $earlier ) {
			return $this->earlier( $earlier );
		}

		$depth   = (int) $starter['depth'] + 1;
		$user    = $starter['context']['trigger']['user_id'] ?? 0;
		$payload = array(
			'user_id' => is_numeric( $user ) ? max( 0, (int) $user ) : 0,
			'source'  => 'automation',
			'origin'  => array(
				'source' => 'automation',
				'depth'  => $depth,
			),
		);
		$context = new Context( $this->engine->clock(), $record, $payload );
		if ( isset( $definition['trigger']['when'] ) && ! Conditions::evaluate( $definition['trigger']['when'], $context ) ) {
			return array(
				'run'       => 0,
				'started'   => false,
				'skipped'   => null,
				'duplicate' => false,
			);
		}

		$origin = array(
			'run'        => $parent_id,
			'automation' => (string) $starter['automation'],
			'step'       => $step,
		);
		if ( null !== $iteration ) {
			$origin['iteration'] = $iteration;
		}
		$run = array(
			'automation'      => $key,
			'trigger_type'    => 'manual',
			'record_id'       => null === $record ? null : (int) $record['id'],
			'depth'           => $depth,
			'definition_hash' => self::hash( $definition ),
			'idempotency_key' => $stored, // Wins over the target's own idempotency, as a request ID does.
			'context'         => array(
				'trigger' => $payload,
				'parent'  => $origin,
			),
		);

		/** This filter is documented in modules/automation/src/Triggers.php */
		$max = (int) apply_filters( 'pegwork_automation_max_depth', self::MAX_DEPTH );
		if ( $depth >= $max ) {
			$id = $this->engine->runs()->insert(
				$run + array(
					'status' => 'skipped',
					'error'  => sprintf( 'loop_depth: trigger depth %1$d reached the limit of %2$d.', $depth, $max ),
				)
			);
			if ( 0 === $id ) {
				return $this->earlier( (array) $this->engine->runs()->find_key( $key, $stored ) );
			}
			return array(
				'run'       => $id,
				'started'   => false,
				'skipped'   => 'loop_depth',
				'duplicate' => false,
			);
		}

		unset( $definition['idempotency'] );
		$id = $this->enqueue( $run, $definition, $context );
		if ( 0 === $id ) {
			return $this->earlier( (array) $this->engine->runs()->find_key( $key, $stored ) );
		}
		return array(
			'run'       => $id,
			'started'   => true,
			'skipped'   => null,
			'duplicate' => false,
		);
	}

	/**
	 * What chained() reports for a child that an earlier attempt of the same step already created; a child
	 * still queued gets its wake-up again (the attempt may have ended before scheduling it).
	 *
	 * @param array<string, mixed> $earlier Earlier run row (empty when it was pruned meanwhile).
	 * @return array{run: int, started: bool, skipped: string|null, duplicate: bool}
	 */
	private function earlier( array $earlier ): array {
		$id      = (int) ( $earlier['id'] ?? 0 );
		$skipped = 'skipped' === ( $earlier['status'] ?? null );
		if ( $id > 0 && 'queued' === $earlier['status'] ) {
			$this->engine->scheduler()->schedule( Engine::RUN_HOOK, array( $id ), $this->engine->clock()->now()->getTimestamp() );
		}
		return array(
			'run'       => $id,
			'started'   => ! $skipped,
			'skipped'   => $skipped ? 'loop_depth' : null,
			'duplicate' => true,
		);
	}

	/**
	 * The stored idempotency key of a staff request ID.
	 *
	 * @param int    $user    User ID.
	 * @param string $request Request ID.
	 */
	public static function request_key( int $user, string $request ): string {
		return self::key( 'manual:' . $user . ':' . $request );
	}

	/**
	 * Starts a run of a webhook automation from a verified inbound request. The payload is stored as
	 * `trigger.payload`; the sender's X-Pegwork-Delivery, when given, is the idempotency key (so a
	 * redelivery creates no second run), else the automation's `idempotency` template applies.
	 *
	 * @param string               $key        Automation key.
	 * @param array<string, mixed> $definition Canonical automation.
	 * @param array<mixed>         $payload    Decoded JSON body.
	 * @param string|null          $delivery   X-Pegwork-Delivery, or null.
	 * @return int Run ID, or 0 when a run with the same key exists.
	 */
	public function webhook( string $key, array $definition, array $payload, ?string $delivery ): int {
		$trigger = array(
			'payload' => $payload,
			'origin'  => array(
				'source' => 'webhook',
				'depth'  => 0,
			),
		);
		$run     = array(
			'automation'      => $key,
			'trigger_type'    => 'webhook',
			'record_id'       => null,
			'depth'           => 0,
			'definition_hash' => self::hash( $definition ),
			'context'         => array( 'trigger' => $trigger ),
		);
		$context = new Context( $this->engine->clock(), null, $trigger );

		if ( null !== $delivery ) {
			// The delivery ID wins over the automation's own idempotency template.
			unset( $definition['idempotency'] );
			$run['idempotency_key'] = self::delivery_key( $delivery );
		}
		return $this->enqueue( $run, $definition, $context );
	}

	/**
	 * The stored idempotency key of an inbound delivery ID.
	 *
	 * @param string $delivery X-Pegwork-Delivery.
	 */
	public static function delivery_key( string $delivery ): string {
		return self::key( 'delivery:' . $delivery );
	}

	/**
	 * Hash of a definition, stored on each run.
	 *
	 * @param array<string, mixed> $definition Canonical automation.
	 */
	public static function hash( array $definition ): string {
		return md5( (string) wp_json_encode( $definition ) );
	}

	/**
	 * An idempotency key that fits the column: longer keys are hashed.
	 *
	 * @param string $key Rendered key.
	 */
	private static function key( string $key ): string {
		return strlen( $key ) <= 191 ? $key : 'md5:' . md5( $key );
	}
}
