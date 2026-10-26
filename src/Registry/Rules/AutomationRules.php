<?php
/**
 * Validator rules for automations (`automations/<key>.json`, Phase 4).
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Registry\Rules;

use DateTimeImmutable;
use DateTimeZone;
use Pegwork\Content\RecordQuery;
use Pegwork\Registry\Loader;

/**
 * An automation: a trigger, ordered steps, optional signed links. Conditions are a JSON expression tree and
 * templates use `{{ path }}` merge tags; both are data, checked here at load time so a typo fails
 * `wp pegwork schema validate` instead of a run. Definitions are inert data; the automation module runs them.
 *
 * Validation checks shape and everything inside the definition (step order, links, durations, template
 * syntax, path roots, condition limits). The reference pass checks paths against the registry: record fields and
 * taxonomies exist, types fit the operators and values, and private fields stay out of public contexts.
 *
 * Used by Pegwork\Registry\Validator, whose helpers (props, type, key, add, ...) it calls.
 */
trait AutomationRules {

	/**
	 * Bus event names: dot-separated keys, at least two parts.
	 */
	public const EVENT_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

	/**
	 * ISO 8601 duration (no fractions, no sign), such as P30D or PT1H.
	 */
	public const DURATION_PATTERN = '/^P(?!$)([0-9]+Y)?([0-9]+M)?([0-9]+W)?([0-9]+D)?(T(?=[0-9])([0-9]+H)?([0-9]+M)?([0-9]+S)?)?$/';

	/**
	 * Date operand offset: an ISO 8601 duration with an optional sign, such as -P12M.
	 */
	public const OFFSET_PATTERN = '/^[-+]?P(?!$)([0-9]+Y)?([0-9]+M)?([0-9]+W)?([0-9]+D)?(T(?=[0-9])([0-9]+H)?([0-9]+M)?([0-9]+S)?)?$/';

	/**
	 * Schedule time of day (site time), HH:MM.
	 */
	public const TIME_PATTERN = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

	/**
	 * Variable path, such as record.last_verified or steps.create.record_id.
	 */
	public const VAR_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/';

	/**
	 * One merge tag body: a path or `option:<page>.<field>` (1), an optional `add:"<signed duration>"` filter (2,
	 * duration 3), an optional `date` or `number[:<0-4>]` filter (4), an optional default filter.
	 */
	public const TEMPLATE_TAG_PATTERN = '/^\s*((?:option:)?[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*(\|\s*add:"([^"]*)"\s*)?(\|\s*(?:date|number(?::[0-4])?)\s*)?(?:\|\s*default:"[^"]*"\s*)?$/';

	/**
	 * Block tags of `{{#each steps.<id>.records}} ... {{else}} ... {{/each}}`.
	 */
	public const EACH_OPEN_PATTERN  = '/^\s*#each\s+([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*$/';
	public const EACH_ELSE_PATTERN  = '/^\s*else\s*$/';
	public const EACH_CLOSE_PATTERN = '/^\s*\/each\s*$/';

	/**
	 * A loop's `over`: the records of a find_records step.
	 */
	public const COLLECTION_PATTERN = '/^steps\.[a-z][a-z0-9_]*\.records$/';

	/**
	 * Condition operators.
	 */
	public const CONDITION_OPS = array( 'eq', 'ne', 'in', 'not_in', 'lt', 'lte', 'gt', 'gte', 'empty', 'not_empty', 'contains', 'changed' );

	/**
	 * Operators that take no value, take a list, or compare order.
	 */
	public const UNARY_OPS = array( 'empty', 'not_empty', 'changed' );
	public const LIST_OPS  = array( 'in', 'not_in' );
	public const ORDER_OPS = array( 'lt', 'lte', 'gt', 'gte' );

	/**
	 * Condition size limits.
	 */
	public const CONDITION_MAX_DEPTH = 5;
	public const CONDITION_MAX_NODES = 50;

	/**
	 * Step types, and the subset allowed in signed links (they must finish within the click).
	 */
	public const STEP_TYPES      = array( 'update_record', 'create_record', 'send_email', 'call_webhook', 'wait', 'condition', 'decision', 'get_record', 'find_records', 'loop', 'relate', 'trash_record', 'start_automation' );
	public const LINK_STEP_TYPES = array( 'update_record', 'condition' );

	/**
	 * Step types that may set `"on_error": "continue"`, and the read steps.
	 */
	public const ON_ERROR_TYPES = array( 'update_record', 'create_record', 'send_email', 'call_webhook', 'relate', 'get_record', 'find_records', 'trash_record', 'start_automation' );
	public const READ_TYPES     = array( 'get_record', 'find_records' );

	/**
	 * Trigger types.
	 */
	public const TRIGGER_TYPES = array( 'event', 'schedule', 'webhook', 'manual' );

	/**
	 * Manual trigger button text limit, characters.
	 */
	public const MANUAL_BUTTON_MAX = 40;

	/**
	 * Decision limits: branches per decision, and nesting levels (a decision or loop inside a branch is level 2).
	 */
	public const DECISION_MAX_BRANCHES = 10;
	public const DECISION_MAX_LEVELS   = 3;

	/**
	 * Loop limits: steps in a body (nested ones included), and items (a find_records result holds at most 50).
	 */
	public const LOOP_MAX_STEPS = 10;
	public const LOOP_MAX_ITEMS = RecordQuery::MAX_LIMIT;

	/**
	 * Static bound on step attempts without retries (docs/automation-v3-design.md section 4.3).
	 */
	public const MAX_ATTEMPTS = 500;

	/**
	 * Form events that can bind the record the form wrote (section "Form binding" in docs/registry-reference.md).
	 */
	public const FORM_BINDING_EVENTS = array( 'form.submitted', 'form.confirmed' );

	/**
	 * Outputs a step exposes as `steps.<id>.<output>`, with their types: `number`, `boolean` and `text` are
	 * values; `record` takes one more key (`steps.<id>.record.<key>`), `sum` one number field; `records` is a
	 * collection, only for a loop's `over` and `{{#each}}`.
	 */
	public const STEP_OUTPUTS = array(
		'create_record'    => array( 'record_id' => 'number' ),
		'get_record'       => array(
			'found'     => 'boolean',
			'record_id' => 'number',
			'record'    => 'record',
		),
		'find_records'     => array(
			'count'   => 'number',
			'records' => 'records',
			'sum'     => 'sum',
		),
		'loop'             => array( 'count' => 'number' ),
		'start_automation' => array( 'started' => 'boolean' ),
	);

	/**
	 * Outputs of a loop inside its body, and of a step with `on_error`.
	 */
	public const LOOP_BODY_OUTPUTS = array(
		'item'  => 'record',
		'index' => 'number',
	);
	public const ON_ERROR_OUTPUTS  = array(
		'failed' => 'boolean',
		'error'  => 'text',
	);

	/**
	 * Payload keys of the core record events, and of the forms module's events.
	 */
	public const RECORD_EVENT_KEYS = array(
		'record.created'        => array( 'id', 'type', 'status', 'user_id', 'origin' ),
		'record.updated'        => array( 'id', 'type', 'status', 'changed', 'old', 'user_id', 'origin' ),
		'record.status_changed' => array( 'id', 'type', 'old_status', 'new_status', 'user_id', 'origin' ),
		'record.deleted'        => array( 'id', 'type', 'status', 'user_id', 'origin' ),
	);
	public const FORM_EVENT_KEYS   = array( 'form', 'entry_id', 'post_id', 'record_id', 'values' );

	/**
	 * Post statuses a schedule may sweep, and statuses create_record may set.
	 */
	public const SWEEP_STATUSES  = array( 'publish', 'future', 'draft', 'pending', 'private' );
	public const CREATE_STATUSES = array( 'draft', 'pending', 'publish', 'private' );

	/**
	 * A role recipient (Automation v4): `{role:<slug>}`, a whole part of send_email `to`.
	 */
	public const ROLE_TAG_PATTERN = '/^\{role:[a-z0-9_-]{1,64}\}$/';

	/**
	 * Recipient parts of send_email `to` (addresses, merge tags, option and role tokens), as SendEmail counts them.
	 */
	public const MAX_RECIPIENT_PARTS = 5;

	/**
	 * Default time of day of a date wait (`until.at`), site time; filled by the normalizer.
	 */
	public const WAIT_AT_DEFAULT = '09:00';

	/**
	 * Wait and link expiry bounds.
	 */
	public const DURATION_MIN = 'PT1H';
	public const DURATION_MAX = 'P2Y';

	/**
	 * Outbound webhook timeout limit, seconds.
	 */
	public const WEBHOOK_MAX_TIMEOUT = 15;

	/**
	 * Step ids seen so far in the step tree being scanned, in document order.
	 *
	 * @var array<string, true>
	 */
	private array $step_ids = array();

	/**
	 * Steps seen so far inside loop bodies: step id => loop id.
	 *
	 * @var array<string, string>
	 */
	private array $loop_members = array();

	/**
	 * Source of each known object in the references pass (kind => key => package id or Loader::DB_SOURCE), when
	 * the caller knows them (the loader); empty otherwise.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $sources = array();

	/**
	 * Validates an automation.
	 *
	 * @param mixed  $data    Decoded automation.
	 * @param string $pointer JSON pointer of the object.
	 */
	private function automation( mixed $data, string $pointer ): void {
		$before    = count( $this->errors );
		$string    = fn( $v, $p ) => $this->type( $v, $p, 'string' );
		$is_object = $this->props(
			$data,
			$pointer,
			array(
				'format'      => fn( $v, $p ) => $this->format( $v, $p ),
				'key'         => fn( $v, $p ) => $this->bounded_key( $v, $p ),
				'label'       => $string,
				'description' => $string,
				'enabled'     => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'trigger'     => fn( $v, $p ) => $this->automation_trigger( $v, $p ),
				'steps'       => fn( $v, $p ) => $this->automation_steps( $v, $p, false ),
				'idempotency' => $string,
				'links'       => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'object' ) ) {
						foreach ( $v as $name => $link ) {
							$at = self::pointer( $p, $name );
							$this->key( (string) $name, $at );
							$this->automation_link( $link, $at );
						}
					}
				},
				'ui'          => fn( $v, $p ) => $this->type( $v, $p, 'object' ), // Reserved for the Phase 6 canvas; contents are free.
			),
			array( 'format', 'key', 'label', 'trigger', 'steps' )
		);

		if ( $is_object && count( $this->errors ) === $before ) {
			$this->automation_scan( $data, $pointer );

			$attempts = self::attempts( $data['steps'], 1 );
			if ( $attempts > self::MAX_ATTEMPTS ) {
				$this->add(
					'automation_too_large',
					$pointer . '/steps',
					/* translators: 1: worst-case step attempts, 2: limit, 3: items per loop, 4: body steps that fit after one step, 5: attempts of that example. */
					sprintf( __( 'This automation can take %1$d step attempts in one run; the limit is %2$d. Every step outside a loop body counts once (the loop step itself too, and a decision adds its longest branch), and every step inside a body counts %3$d times. So with one step before a %3$d-item loop, the body can hold %4$d steps: 1 + 1 + %4$d x %3$d = %5$d. Move steps out of the loop or split the automation.', 'pegwork' ), $attempts, self::MAX_ATTEMPTS, self::LOOP_MAX_ITEMS, intdiv( self::MAX_ATTEMPTS - 2, self::LOOP_MAX_ITEMS ), 2 + intdiv( self::MAX_ATTEMPTS - 2, self::LOOP_MAX_ITEMS ) * self::LOOP_MAX_ITEMS )
				);
			}
		}
	}

	/**
	 * Worst-case step attempts of a valid step list without retries: one per step, a loop's body steps
	 * LOOP_MAX_ITEMS times each, and a decision's longest branch.
	 *
	 * @param list<array<string, mixed>> $steps Valid steps.
	 * @param int                        $times How often the list runs.
	 */
	private static function attempts( array $steps, int $times ): int {
		$total = 0;
		foreach ( $steps as $step ) {
			$total += $times;
			if ( 'decision' === $step['type'] ) {
				$longest = self::attempts( $step['otherwise'] ?? array(), $times );
				foreach ( $step['branches'] as $branch ) {
					$longest = max( $longest, self::attempts( $branch['steps'], $times ) );
				}
				$total += $longest;
			}
			if ( 'loop' === $step['type'] ) {
				$total += self::attempts( $step['steps'], $times * self::LOOP_MAX_ITEMS );
			}
		}
		return $total;
	}

	/**
	 * Number of steps in a step list, counting the steps inside decisions.
	 *
	 * @param mixed $steps Decoded list.
	 */
	private static function step_count( mixed $steps ): int {
		$count = 0;
		foreach ( is_array( $steps ) ? $steps : array() as $step ) {
			++$count;
			if ( ! is_array( $step ) ) {
				continue;
			}
			foreach ( is_array( $step['branches'] ?? null ) ? $step['branches'] : array() as $branch ) {
				$count += self::step_count( is_array( $branch ) ? ( $branch['steps'] ?? array() ) : array() );
			}
			$count += self::step_count( $step['otherwise'] ?? array() );
			$count += self::step_count( $step['steps'] ?? array() );
		}
		return $count;
	}

	/**
	 * Validates a trigger.
	 *
	 * @param mixed  $trigger Decoded trigger.
	 * @param string $pointer JSON pointer of the trigger.
	 */
	private function automation_trigger( mixed $trigger, string $pointer ): void {
		if ( ! $this->type( $trigger, $pointer, 'object' ) ) {
			return;
		}
		$type = $trigger['type'] ?? null;
		if ( ! in_array( $type, self::TRIGGER_TYPES, true ) ) {
			if ( null === $type ) {
				$this->add( 'missing_property', $pointer, __( 'Missing required property "type".', 'pegwork' ) );
			} else {
				$this->enum( $type, $pointer . '/type', self::TRIGGER_TYPES );
			}
			return;
		}

		$checks = array( 'type' => fn() => null );
		$needed = array( 'type' );
		if ( 'event' === $type ) {
			$checks += array(
				'event'        => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::EVENT_PATTERN, $v ) ) {
						$this->add( 'invalid_event', $p, __( 'Event names are dot-separated keys, such as "record.status_changed".', 'pegwork' ) );
					}
				},
				'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
				'form'         => fn( $v, $p ) => $this->key( $v, $p ),
				'when'         => fn( $v, $p ) => $this->automation_condition( $v, $p ),
			);

			$needed[] = 'event';
		}
		if ( 'schedule' === $type ) {
			$checks += array(
				'every'    => fn( $v, $p ) => $this->enum( $v, $p, array( 'hour', 'day', 'week' ) ),
				'at'       => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::TIME_PATTERN, $v ) ) {
						$this->add( 'invalid_value', $p, __( '"at" is a site time of day, HH:MM (24-hour).', 'pegwork' ) );
					}
				},
				'for_each' => fn( $v, $p ) => $this->props(
					$v,
					$p,
					array(
						'content_type' => fn( $w, $q ) => $this->key( $w, $q ),
						'status'       => fn( $w, $q ) => $this->list_of( $w, $q, fn( $s, $r ) => $this->enum( $s, $r, self::SWEEP_STATUSES ) ),
						'where'        => fn( $w, $q ) => $this->automation_condition( $w, $q ),
						'max_runs'     => fn( $w, $q ) => $this->positive_count( $w, $q ),
					),
					array( 'content_type' )
				),
			);

			$needed[] = 'every';
		}
		if ( 'manual' === $type ) {
			$checks += array(
				'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
				'button'       => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'string' ) && mb_strlen( $v ) > self::MANUAL_BUTTON_MAX ) {
						/* translators: %d: maximum characters. */
						$this->add( 'invalid_value', $p, sprintf( __( 'Button text is at most %d characters.', 'pegwork' ), self::MANUAL_BUTTON_MAX ) );
					}
				},
				'allow'        => fn( $v, $p ) => $this->enum( $v, $p, array( 'admins', 'editors' ) ),
				'when'         => fn( $v, $p ) => $this->automation_condition( $v, $p ),
			);

			$needed[] = 'button';
		}

		$is_object = $this->props( $trigger, $pointer, $checks, $needed );
		if ( $is_object && 'manual' === $type && 'editors' === ( $trigger['allow'] ?? null ) && ! isset( $trigger['content_type'] ) ) {
			$this->add( 'invalid_value', $pointer . '/allow', __( '"allow": "editors" lets users who can edit the record run it, so it needs a "content_type".', 'pegwork' ) );
		}
		if ( ! $is_object || 'event' !== $type || ! is_string( $trigger['event'] ?? null ) ) {
			return;
		}
		if ( isset( $trigger['content_type'] ) && ! str_starts_with( $trigger['event'], 'record.' ) ) {
			$this->add( 'trigger_filter_mismatch', $pointer . '/content_type', __( '"content_type" narrows record.* events only.', 'pegwork' ) );
		}
		if ( isset( $trigger['form'] ) && ! str_starts_with( $trigger['event'], 'form.' ) ) {
			$this->add( 'trigger_filter_mismatch', $pointer . '/form', __( '"form" narrows form.* events only.', 'pegwork' ) );
		}
	}

	/**
	 * Validates a step list: at least one step, except in a decision's branch or `otherwise`.
	 *
	 * @param mixed  $steps   Decoded list.
	 * @param string $pointer JSON pointer of the list.
	 * @param bool   $link    Whether the list belongs to a signed link.
	 * @param int    $level   Nesting level of the list: 1 for the automation's own steps, 2 inside a decision or loop.
	 * @param bool   $in_loop Whether the list is inside a loop body.
	 */
	private function automation_steps( mixed $steps, string $pointer, bool $link, int $level = 1, bool $in_loop = false ): void {
		$this->list_of( $steps, $pointer, fn( $step, $p ) => $this->automation_step( $step, $p, $link, $level, $in_loop ) );
		if ( array() === $steps && 1 === $level ) {
			$this->add( 'invalid_value', $pointer, __( 'Add at least one step.', 'pegwork' ) );
		}
	}

	/**
	 * Validates a decision's branches: 1 to DECISION_MAX_BRANCHES of `{ label, when, steps }`, labels unique.
	 *
	 * @param mixed  $branches Decoded list.
	 * @param string $pointer  JSON pointer of the list.
	 * @param int    $level    Nesting level of the decision.
	 * @param bool   $in_loop  Whether the decision is inside a loop body.
	 */
	private function automation_branches( mixed $branches, string $pointer, int $level, bool $in_loop ): void {
		$this->list_of(
			$branches,
			$pointer,
			fn( $branch, $p ) => $this->props(
				$branch,
				$p,
				array(
					'label' => fn( $v, $q ) => $this->type( $v, $q, 'string' ),
					'when'  => fn( $v, $q ) => $this->automation_condition( $v, $q ),
					'steps' => fn( $v, $q ) => $this->automation_steps( $v, $q, false, $level + 1, $in_loop ),
				),
				array( 'label', 'when', 'steps' )
			)
		);
		if ( ! is_array( $branches ) || ! array_is_list( $branches ) ) {
			return;
		}
		if ( array() === $branches || count( $branches ) > self::DECISION_MAX_BRANCHES ) {
			/* translators: %d: maximum number of branches. */
			$this->add( 'invalid_value', $pointer, sprintf( __( 'A decision has from 1 to %d branches.', 'pegwork' ), self::DECISION_MAX_BRANCHES ) );
		}
		$this->unique( $branches, $pointer, 'label' );
	}

	/**
	 * Validates one step.
	 *
	 * @param mixed  $step    Decoded step.
	 * @param string $pointer JSON pointer of the step.
	 * @param bool   $link    Whether the step belongs to a signed link.
	 * @param int    $level   Nesting level of the step's list.
	 * @param bool   $in_loop Whether the step is inside a loop body.
	 */
	private function automation_step( mixed $step, string $pointer, bool $link, int $level = 1, bool $in_loop = false ): void {
		if ( ! $this->type( $step, $pointer, 'object' ) ) {
			return;
		}
		$type = $step['type'] ?? null;
		if ( ! in_array( $type, self::STEP_TYPES, true ) ) {
			if ( null === $type ) {
				$this->add( 'missing_property', $pointer, __( 'Missing required property "type".', 'pegwork' ) );
			} else {
				$this->enum( $type, $pointer . '/type', self::STEP_TYPES );
			}
			return;
		}
		if ( $link && ! in_array( $type, self::LINK_STEP_TYPES, true ) ) {
			/* translators: %s: step type. */
			$this->add( 'automation_link_step', $pointer . '/type', sprintf( __( 'Link steps run within the click, so only update_record and condition are allowed, not "%s".', 'pegwork' ), $type ) );
			return;
		}
		if ( $in_loop && 'wait' === $type ) {
			$this->add( 'loop_wait', $pointer . '/type', __( 'A loop cannot hold a wait, at any depth: per-record timing belongs in per-record runs (a record event or a schedule with "for_each").', 'pegwork' ) );
			return;
		}
		if ( $in_loop && 'loop' === $type ) {
			$this->add( 'loop_nested', $pointer . '/type', __( 'A loop cannot hold another loop.', 'pegwork' ) );
			return;
		}
		if ( in_array( $type, array( 'decision', 'loop' ), true ) && $level > self::DECISION_MAX_LEVELS ) {
			/* translators: %d: maximum nesting levels. */
			$this->add( 'decision_too_deep', $pointer, sprintf( __( 'Decisions and loops nest at most %d levels deep.', 'pegwork' ), self::DECISION_MAX_LEVELS ) );
			return;
		}

		$string = fn( $v, $p ) => $this->type( $v, $p, 'string' );
		$values = fn( $v, $p ) => $this->automation_values( $v, $p );
		$checks = array(
			'id'    => fn( $v, $p ) => $this->key( $v, $p ),
			'type'  => fn() => null,
			'retry' => fn( $v, $p ) => $this->props(
				$v,
				$p,
				array(
					'max'     => fn( $w, $q ) => $this->type( $w, $q, 'count' ),
					'backoff' => function ( $w, $q ) {
						if ( is_array( $w ) ) {
							$this->list_of( $w, $q, fn( $d, $r ) => $this->duration( $d, $r, false ) );
						} else {
							$this->duration( $w, $q, false );
						}
					},
				),
				array( 'max' )
			),
		);

		// Each type: its own properties, and which of them are required.
		$own = match ( $type ) {
			'update_record' => array(
				array(
					'record' => $string,
					'values' => $values,
				),
				array( 'values' ),
			),
			'create_record' => array(
				array(
					'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
					'status'       => fn( $v, $p ) => $this->enum( $v, $p, self::CREATE_STATUSES ),
					'values'       => $values,
				),
				array( 'content_type', 'values' ),
			),
			'send_email'    => array(
				array(
					'to'       => $string,
					'subject'  => $string,
					'text'     => $string,
					'html'     => $string,
					'reply_to' => $string,
				),
				array( 'to', 'subject', 'text' ),
			),
			'call_webhook'  => array(
				array(
					'url'          => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'string' ) && ! preg_match( '#^https?://\S+$#', $v ) ) {
							$this->add( 'invalid_value', $p, __( 'Webhook URLs start with http:// or https://.', 'pegwork' ) );
						}
					},
					'fields'       => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->key( $item, $q ) ),
					'timeout'      => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'number' ) && ( $v <= 0 || $v > self::WEBHOOK_MAX_TIMEOUT ) ) {
							/* translators: %d: maximum seconds. */
							$this->add( 'invalid_value', $p, sprintf( __( '"timeout" is more than 0 and at most %d seconds.', 'pegwork' ), self::WEBHOOK_MAX_TIMEOUT ) );
						}
					},
					'record'       => $string,
					'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
				),
				array( 'url' ),
			),
			'wait'          => array(
				array(
					'for'   => fn( $v, $p ) => $this->duration( $v, $p, true ),
					'until' => fn( $v, $p ) => $this->wait_until( $v, $p ),
				),
				array(),
			),
			'trash_record'     => array(
				array(
					'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
					'record'       => $string,
				),
				array( 'content_type' ),
			),
			'start_automation' => array(
				array(
					'automation' => fn( $v, $p ) => $this->bounded_key( $v, $p ),
					'record'     => $string,
				),
				array( 'automation' ),
			),
			'condition'     => array( array( 'when' => fn( $v, $p ) => $this->automation_condition( $v, $p ) ), array( 'when' ) ),
			'decision'      => array(
				array(
					'branches'  => fn( $v, $p ) => $this->automation_branches( $v, $p, $level, $in_loop ),
					'otherwise' => fn( $v, $p ) => $this->automation_steps( $v, $p, false, $level + 1, $in_loop ),
				),
				array( 'branches' ),
			),
			'get_record'    => array( $this->read_checks() + array( 'record' => $string ), array( 'content_type' ) ),
			'find_records'  => array(
				$this->read_checks() + array(
					'limit' => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'count' ) && $v > RecordQuery::MAX_LIMIT ) {
							/* translators: %d: maximum records. */
							$this->add( 'invalid_value', $p, sprintf( __( '"limit" is an integer from 0 to %d.', 'pegwork' ), RecordQuery::MAX_LIMIT ) );
						}
					},
					'sum'   => function ( $v, $p ) {
						$this->list_of( $v, $p, fn( $item, $q ) => $this->key( $item, $q ) );
						if ( is_array( $v ) && count( $v ) > RecordQuery::MAX_SUMS ) {
							/* translators: %d: maximum fields. */
							$this->add( 'invalid_value', $p, sprintf( __( '"sum" lists at most %d number fields.', 'pegwork' ), RecordQuery::MAX_SUMS ) );
						}
					},
				),
				array( 'content_type' ),
			),
			'loop'          => array(
				array(
					'over'  => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::COLLECTION_PATTERN, $v ) ) {
							$this->add( 'invalid_var', $p, __( '"over" is the records of a find_records step: "steps.<id>.records".', 'pegwork' ) );
						}
					},
					'steps' => function ( $v, $p ) use ( $level ) {
						$this->automation_steps( $v, $p, false, $level + 1, true );
						$count = self::step_count( $v );
						if ( is_array( $v ) && array_is_list( $v ) && ( 0 === $count || $count > self::LOOP_MAX_STEPS ) ) {
							/* translators: %d: maximum steps. */
							$this->add( 'invalid_value', $p, sprintf( __( 'A loop body has from 1 to %d steps, counting the steps inside decisions.', 'pegwork' ), self::LOOP_MAX_STEPS ) );
						}
					},
				),
				array( 'over', 'steps' ),
			),
			'relate'        => array(
				array(
					'relationship' => fn( $v, $p ) => $this->key( $v, $p ),
					'action'       => fn( $v, $p ) => $this->enum( $v, $p, array( 'add', 'remove' ) ),
					'from'         => $string,
					'to'           => $string,
				),
				array( 'relationship', 'to' ),
			),
		};
		if ( in_array( $type, array( 'decision', 'loop' ), true ) ) {
			unset( $checks['retry'] ); // A decision only evaluates conditions, and a loop retries its body steps; there is nothing to retry.
		}
		if ( in_array( $type, self::ON_ERROR_TYPES, true ) ) {
			$checks['on_error'] = fn( $v, $p ) => $this->enum( $v, $p, array( 'continue' ) );
		}

		$is_object = $this->props( $step, $pointer, $checks + $own[0], array_merge( array( 'id', 'type' ), $own[1] ) );
		if ( ! $is_object ) {
			return;
		}

		if ( 'call_webhook' === $type && isset( $step['record'] ) !== isset( $step['content_type'] ) ) {
			$this->add( 'missing_property', $pointer, __( 'A webhook that sends a read record names both "record" and its "content_type".', 'pegwork' ) );
		}
		if ( 'wait' === $type && isset( $step['for'] ) === isset( $step['until'] ) ) {
			$this->add( 'wait_source', $pointer, __( 'A wait has exactly one of "for" (a duration) or "until" (a date field of the record).', 'pegwork' ) );
		}
		$query = isset( $step['related'] ) || isset( $step['filter'] ) || isset( $step['created'] );
		if ( 'get_record' === $type && isset( $step['record'] ) && $query ) {
			$this->add( 'read_source', $pointer . '/record', __( '"record" reads one record by ID, so it cannot be combined with "related", "filter" or "created".', 'pegwork' ) );
		}
		if ( in_array( $type, self::READ_TYPES, true ) && ! $query && ! isset( $step['record'] ) ) {
			$this->add(
				'read_source',
				$pointer,
				'get_record' === $type
					? __( 'Say which record to read: "record" (an ID), "related", "filter" or "created".', 'pegwork' )
					: __( 'Say which records to read: "related", "filter", "created" or a combination (reading a whole content type is never implicit).', 'pegwork' )
			);
		}
	}

	/**
	 * Validates a date wait's `until`: `{ var, offset?, at? }`. That `var` is `record.<date field>` of a bound,
	 * typed record is checked by the scan and the references pass.
	 *
	 * @param mixed  $until   Decoded until.
	 * @param string $pointer JSON pointer of the until.
	 */
	private function wait_until( mixed $until, string $pointer ): void {
		$this->props(
			$until,
			$pointer,
			array(
				'var'    => fn( $v, $p ) => $this->var_path( $v, $p ),
				'offset' => function ( $v, $p ) {
					if ( ! $this->type( $v, $p, 'string' ) ) {
						return;
					}
					if ( ! preg_match( self::OFFSET_PATTERN, $v ) || self::seconds( ltrim( $v, '+-' ) ) > self::seconds( self::DURATION_MAX ) ) {
						/* translators: %s: maximum duration. */
						$this->add( 'invalid_value', $p, sprintf( __( '"offset" is an ISO 8601 duration with an optional sign, such as "-P3D", of at most %s either way.', 'pegwork' ), self::DURATION_MAX ) );
					}
				},
				'at'     => function ( $v, $p ) {
					if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::TIME_PATTERN, $v ) ) {
						$this->add( 'invalid_value', $p, __( '"at" is a site time of day, HH:MM (24-hour).', 'pegwork' ) );
					}
				},
			),
			array( 'var' )
		);
	}

	/**
	 * Validates a read's `created`: `{ before?, after? }`, at least one, each a `Y-m-d` date,
	 * `{ "date": "today|now", "offset" }` or `{ "var": "<path>" }`.
	 *
	 * @param mixed  $created Decoded created.
	 * @param string $pointer JSON pointer of the created.
	 */
	private function read_created( mixed $created, string $pointer ): void {
		$operand   = function ( $v, $p ) {
			if ( is_string( $v ) ) {
				$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $v, new DateTimeZone( 'UTC' ) );
				if ( ! preg_match( self::DATE_PATTERN, $v ) || false === $day || $day->format( 'Y-m-d' ) !== $v ) {
					$this->add( 'invalid_value', $p, __( 'A "created" date is a Y-m-d date, { "date": "today|now", "offset": "-P2Y" } or { "var": "<path>" }.', 'pegwork' ) );
				}
				return;
			}
			if ( is_array( $v ) && ! array_is_list( $v ) && array_key_exists( 'var', $v ) ) {
				$this->props( $v, $p, array( 'var' => fn( $w, $q ) => $this->var_path( $w, $q ) ), array( 'var' ) );
				return;
			}
			if ( is_array( $v ) && ! array_is_list( $v ) && array_key_exists( 'date', $v ) ) {
				$this->props(
					$v,
					$p,
					array(
						'date'   => fn( $w, $q ) => $this->enum( $w, $q, array( 'today', 'now' ) ),
						'offset' => function ( $w, $q ) {
							if ( $this->type( $w, $q, 'string' ) && ! preg_match( self::OFFSET_PATTERN, $w ) ) {
								$this->add( 'invalid_duration', $q, __( 'Offsets are ISO 8601 durations with an optional sign, such as "-P12M".', 'pegwork' ) );
							}
						},
					),
					array( 'date' )
				);
				return;
			}
			$this->add( 'invalid_value', $p, __( 'A "created" date is a Y-m-d date, { "date": "today|now", "offset": "-P2Y" } or { "var": "<path>" }.', 'pegwork' ) );
		};
		$is_object = $this->props( $created, $pointer, array_fill_keys( RecordQuery::CREATED_BOUNDS, $operand ) );
		if ( $is_object && array() === $created ) {
			$this->add( 'invalid_value', $pointer, __( '"created" needs "before", "after" or both.', 'pegwork' ) );
		}
	}

	/**
	 * Property checks of the read query model shared by get_record and find_records (section 3.1 of
	 * docs/automation-v3-design.md). Field-level rules need the registry and run in references().
	 *
	 * @return array<string, callable>
	 */
	private function read_checks(): array {
		return array(
			'content_type' => fn( $v, $p ) => $this->key( $v, $p ),
			'related'      => fn( $v, $p ) => $this->props(
				$v,
				$p,
				array(
					'relationship' => fn( $w, $q ) => $this->key( $w, $q ),
					'of'           => fn( $w, $q ) => $this->type( $w, $q, 'string' ),
				),
				array( 'relationship', 'of' )
			),
			'filter'       => fn( $v, $p ) => $this->read_filter( $v, $p ),
			'created'      => fn( $v, $p ) => $this->read_created( $v, $p ),
			'status'       => function ( $v, $p ) {
				$this->list_of( $v, $p, fn( $s, $q ) => $this->enum( $s, $q, RecordQuery::STATUSES ) );
				if ( is_array( $v ) && array_is_list( $v ) && ( array() === $v || count( array_unique( array_filter( $v, 'is_string' ) ) ) !== count( $v ) ) ) {
					$this->add( 'invalid_value', $p, __( '"status" lists one or more statuses, each once.', 'pegwork' ) );
				}
			},
			'sort'         => fn( $v, $p ) => $this->props(
				$v,
				$p,
				array(
					'field' => fn( $w, $q ) => $this->key( $w, $q ),
					'order' => fn( $w, $q ) => $this->enum( $w, $q, array( 'asc', 'desc' ) ),
				),
				array( 'field' )
			),
		);
	}

	/**
	 * Validates a read filter: `{ "all": [leaf...] }` or `{ "any": [leaf...] }`, one level, 1 to
	 * RecordQuery::MAX_LEAVES leaves of `{ field, op, value }`.
	 *
	 * @param mixed  $filter  Decoded filter.
	 * @param string $pointer JSON pointer of the filter.
	 */
	private function read_filter( mixed $filter, string $pointer ): void {
		if ( ! $this->type( $filter, $pointer, 'object' ) ) {
			return;
		}
		$groups = array_values( array_intersect( array( 'all', 'any' ), array_keys( $filter ) ) );
		if ( 1 !== count( $groups ) ) {
			$this->add( 'invalid_condition', $pointer, __( 'A filter is exactly one of { "all": [...] } or { "any": [...] }.', 'pegwork' ) );
			return;
		}
		$group = $groups[0];
		$this->props(
			$filter,
			$pointer,
			array(
				$group => function ( $leaves, $p ) {
					$this->list_of( $leaves, $p, fn( $leaf, $q ) => $this->read_leaf( $leaf, $q ) );
					if ( is_array( $leaves ) && array_is_list( $leaves ) && ( array() === $leaves || count( $leaves ) > RecordQuery::MAX_LEAVES ) ) {
						/* translators: %d: maximum conditions. */
						$this->add( 'condition_too_complex', $p, sprintf( __( 'A filter has from 1 to %d conditions, one level deep.', 'pegwork' ), RecordQuery::MAX_LEAVES ) );
					}
				},
			)
		);
	}

	/**
	 * Validates one read filter leaf `{ field, op, value }`: operators and operands as in conditions, without
	 * `changed`.
	 *
	 * @param mixed  $leaf    Decoded leaf.
	 * @param string $pointer JSON pointer of the leaf.
	 */
	private function read_leaf( mixed $leaf, string $pointer ): void {
		if ( is_array( $leaf ) && array_intersect( array( 'all', 'any', 'not' ), array_keys( $leaf ) ) ) {
			$this->add( 'condition_too_complex', $pointer, __( 'Filters are one level deep: { "field", "op", "value" } leaves only.', 'pegwork' ) );
			return;
		}
		$is_object = $this->props(
			$leaf,
			$pointer,
			array(
				'field' => fn( $v, $p ) => $this->key( $v, $p ),
				'op'    => fn( $v, $p ) => $this->enum( $v, $p, RecordQuery::OPS ),
				'value' => fn() => null, // Checked below against the operator.
			),
			array( 'field', 'op' )
		);
		if ( $is_object && in_array( $leaf['op'] ?? null, RecordQuery::OPS, true ) ) {
			$this->condition_value( $leaf, $pointer, (string) $leaf['op'] );
		}
	}

	/**
	 * Validates a `values` map: at least one key, each a template string, number, boolean, list of strings
	 * (taxonomy terms) or list of integers (relationship record IDs).
	 *
	 * @param mixed  $values  Decoded values.
	 * @param string $pointer JSON pointer of the values.
	 */
	private function automation_values( mixed $values, string $pointer ): void {
		if ( ! $this->type( $values, $pointer, 'object' ) ) {
			return;
		}
		if ( array() === $values ) {
			$this->add( 'invalid_value', $pointer, __( '"values" needs at least one field.', 'pegwork' ) );
		}
		foreach ( $values as $name => $value ) {
			$list = is_array( $value ) && array_is_list( $value );
			$ok   = is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value )
				|| ( $list && count( array_filter( $value, 'is_string' ) ) === count( $value ) )
				|| ( $list && count( array_filter( $value, 'is_int' ) ) === count( $value ) );
			if ( ! $ok ) {
				$this->add( 'invalid_type', self::pointer( $pointer, $name ), __( 'Expected a string, number, boolean, list of strings or list of integers.', 'pegwork' ) );
			}
		}
	}

	/**
	 * Validates a signed link.
	 *
	 * @param mixed  $link    Decoded link.
	 * @param string $pointer JSON pointer of the link.
	 */
	private function automation_link( mixed $link, string $pointer ): void {
		$string = fn( $v, $p ) => $this->type( $v, $p, 'string' );

		$this->props(
			$link,
			$pointer,
			array(
				'expires'         => fn( $v, $p ) => $this->duration( $v, $p, true ),
				'confirm'         => fn( $v, $p ) => $this->type( $v, $p, 'boolean' ),
				'button'          => $string,
				'steps'           => fn( $v, $p ) => $this->automation_steps( $v, $p, true ),
				'success_url'     => $string,
				'success_message' => $string,
				'expired_url'     => $string,
			),
			array( 'expires', 'steps' )
		);
	}

	/**
	 * Validates the shape of a condition tree. Limits and paths are checked by automation_scan().
	 *
	 * @param mixed  $node    Decoded condition.
	 * @param string $pointer JSON pointer of the condition.
	 */
	private function automation_condition( mixed $node, string $pointer ): void {
		if ( ! $this->type( $node, $pointer, 'object' ) ) {
			return;
		}
		$kinds = array_values( array_intersect( array( 'all', 'any', 'not', 'var' ), array_keys( $node ) ) );
		if ( 1 !== count( $kinds ) ) {
			$this->add( 'invalid_condition', $pointer, __( 'A condition is exactly one of { "all": [...] }, { "any": [...] }, { "not": {...} } or { "var", "op", "value" }.', 'pegwork' ) );
			return;
		}

		$kind = $kinds[0];
		if ( 'all' === $kind || 'any' === $kind ) {
			$this->props(
				$node,
				$pointer,
				array(
					$kind => function ( $v, $p ) {
						$this->list_of( $v, $p, fn( $child, $q ) => $this->automation_condition( $child, $q ) );
						if ( array() === $v ) {
							$this->add( 'invalid_condition', $p, __( 'A condition group needs at least one condition.', 'pegwork' ) );
						}
					},
				)
			);
			return;
		}
		if ( 'not' === $kind ) {
			$this->props( $node, $pointer, array( 'not' => fn( $v, $p ) => $this->automation_condition( $v, $p ) ) );
			return;
		}

		$is_object = $this->props(
			$node,
			$pointer,
			array(
				'var'   => fn( $v, $p ) => $this->var_path( $v, $p ),
				'op'    => fn( $v, $p ) => $this->enum( $v, $p, self::CONDITION_OPS ),
				'value' => fn() => null, // Checked below against the operator.
			),
			array( 'var', 'op' )
		);

		$op = $node['op'] ?? null;
		if ( ! $is_object || ! in_array( $op, self::CONDITION_OPS, true ) ) {
			return;
		}
		$this->condition_value( $node, $pointer, $op );
	}

	/**
	 * Checks a condition or filter leaf's `value` against its operator.
	 *
	 * @param array<string, mixed> $node    Leaf.
	 * @param string               $pointer JSON pointer of the leaf.
	 * @param string               $op      Known operator.
	 */
	private function condition_value( array $node, string $pointer, string $op ): void {
		$has = array_key_exists( 'value', $node );
		$at  = $pointer . ( $has ? '/value' : '' );
		if ( in_array( $op, self::UNARY_OPS, true ) ) {
			if ( $has ) {
				/* translators: %s: operator. */
				$this->add( 'condition_operand', $at, sprintf( __( 'Operator "%s" takes no "value".', 'pegwork' ), $op ) );
			}
			return;
		}
		if ( ! $has ) {
			/* translators: %s: operator. */
			$this->add( 'condition_operand', $at, sprintf( __( 'Operator "%s" needs a "value".', 'pegwork' ), $op ) );
			return;
		}
		$value = $node['value'];
		if ( in_array( $op, self::LIST_OPS, true ) ) {
			if ( ! is_array( $value ) || ! array_is_list( $value ) || array() === $value || count( array_filter( $value, 'is_scalar' ) ) !== count( $value ) ) {
				/* translators: %s: operator. */
				$this->add( 'condition_operand', $at, sprintf( __( 'Operator "%s" needs a non-empty list of strings, numbers or booleans.', 'pegwork' ), $op ) );
			}
			return;
		}
		if ( is_scalar( $value ) ) {
			return;
		}
		if ( is_array( $value ) && array_key_exists( 'var', $value ) ) {
			$this->props( $value, $at, array( 'var' => fn( $v, $p ) => $this->var_path( $v, $p ) ), array( 'var' ) );
			return;
		}
		if ( is_array( $value ) && array_key_exists( 'date', $value ) ) {
			$this->props(
				$value,
				$at,
				array(
					'date'   => fn( $v, $p ) => $this->enum( $v, $p, array( 'today', 'now' ) ),
					'offset' => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'string' ) && ! preg_match( self::OFFSET_PATTERN, $v ) ) {
							$this->add( 'invalid_duration', $p, __( 'Offsets are ISO 8601 durations with an optional sign, such as "-P12M".', 'pegwork' ) );
						}
					},
				),
				array( 'date' )
			);
			return;
		}
		$this->add( 'condition_operand', $at, __( 'A value is a string, number, boolean, { "var": "<path>" } or { "date": "today|now", "offset": "-P12M" }.', 'pegwork' ) );
	}

	/**
	 * Checks the syntax of a variable path.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 */
	private function var_path( mixed $value, string $pointer ): void {
		if ( $this->type( $value, $pointer, 'string' ) && ! preg_match( self::VAR_PATTERN, $value ) ) {
			$this->add( 'invalid_var', $pointer, __( 'Paths are dot-separated keys, such as "record.last_verified".', 'pegwork' ) );
		}
	}

	/**
	 * Checks an ISO 8601 duration, optionally within DURATION_MIN and DURATION_MAX.
	 *
	 * @param mixed  $value   Value to check.
	 * @param string $pointer JSON pointer of the value.
	 * @param bool   $bounded Whether the duration must be within the wait and link limits.
	 */
	private function duration( mixed $value, string $pointer, bool $bounded ): void {
		if ( ! $this->type( $value, $pointer, 'string' ) ) {
			return;
		}
		if ( ! preg_match( self::DURATION_PATTERN, $value ) ) {
			$this->add( 'invalid_duration', $pointer, __( 'Durations are ISO 8601, such as "P30D" or "PT1H".', 'pegwork' ) );
			return;
		}
		if ( $bounded && ( self::seconds( $value ) < self::seconds( self::DURATION_MIN ) || self::seconds( $value ) > self::seconds( self::DURATION_MAX ) ) ) {
			/* translators: 1: minimum duration, 2: maximum duration. */
			$this->add( 'duration_out_of_range', $pointer, sprintf( __( 'Duration must be from %1$s to %2$s.', 'pegwork' ), self::DURATION_MIN, self::DURATION_MAX ) );
		}
	}

	/**
	 * Length of a valid duration in seconds, measured from a fixed date so months and years are deterministic.
	 *
	 * @param string $duration Duration matching DURATION_PATTERN.
	 */
	private static function seconds( string $duration ): int {
		preg_match( self::DURATION_PATTERN, $duration, $parts );
		$units = array(
			1 => 'years',
			2 => 'months',
			3 => 'weeks',
			4 => 'days',
			6 => 'hours',
			7 => 'minutes',
			8 => 'seconds',
		);
		$start = new DateTimeImmutable( '2001-01-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$end   = $start;
		foreach ( $units as $index => $unit ) {
			$amount = (int) ( $parts[ $index ] ?? 0 );
			if ( $amount > 0 ) {
				$end = $end->modify( '+' . $amount . ' ' . $unit );
			}
		}

		return $end->getTimestamp() - $start->getTimestamp();
	}

	/**
	 * Walks a valid automation: checks everything that needs only the definition, and returns the uses
	 * that references() checks against the registry.
	 *
	 * @param array<string, mixed>                     $data    Automation that passed the shape checks.
	 * @param string                                   $pointer JSON pointer of the automation.
	 * @param array<string, array<string, mixed>>|null $known   Defined objects (references pass), null in the shape pass.
	 * @return list<array<string, mixed>> Uses: each has `use` (record_key, form_value, leaf, write, webhook_field) and `pointer`.
	 */
	private function automation_scan( array $data, string $pointer, ?array $known = null ): array {
		$trigger = $data['trigger'];
		$scope   = array(
			'trigger' => $trigger['type'],
			'event'   => $trigger['event'] ?? null,
			'form'    => $trigger['form'] ?? null,
			'binds'   => ( 'event' === $trigger['type'] && str_starts_with( $trigger['event'], 'record.' ) ) || isset( $trigger['for_each'] )
				|| ( 'manual' === $trigger['type'] && isset( $trigger['content_type'] ) ),
			'type'    => $trigger['content_type'] ?? ( $trigger['for_each']['content_type'] ?? null ),
			'links'   => array_keys( $data['links'] ?? array() ),
			'steps'   => array(), // Steps whose outputs are in scope: id => step.
			'loops'   => array(), // Loops whose body encloses this point: id => content type of the items ('' when unknown).
			'each'    => null,    // Content type of `item.*` inside an {{#each}} block ('' when unknown), else null.
		);
		$uses    = array();

		$this->loop_members = array();

		// Form binding needs the form, so it is decided in the references pass; the shape pass assumes it binds.
		if ( 'event' === $trigger['type'] && in_array( $trigger['event'], self::FORM_BINDING_EVENTS, true ) && isset( $trigger['form'] ) ) {
			$form = $known['form'][ $trigger['form'] ] ?? null;
			if ( is_array( $form ) ) {
				$scope['binds'] = self::form_binds( $trigger['event'], $form );
				$scope['type']  = $scope['binds'] ? (string) $form['target']['content_type'] : null;
			} else {
				$scope['binds'] = true;
				$scope['type']  = ''; // Unknown form contents: record keys are not checked (unknown_form reports a missing form).
			}
		}

		if ( isset( $trigger['when'] ) ) {
			$this->scan_condition( $trigger['when'], $pointer . '/trigger/when', $scope, $uses );
		}
		if ( isset( $trigger['for_each']['where'] ) ) {
			$this->scan_condition( $trigger['for_each']['where'], $pointer . '/trigger/for_each/where', $scope, $uses );
		}
		if ( isset( $data['idempotency'] ) ) {
			$this->scan_template( $data['idempotency'], $pointer . '/idempotency', 'private', $scope, $uses );
		}

		// Step ids are unique across the main tree; each link's steps are a tree of their own.
		$this->step_ids = array();
		$this->scan_steps( $data['steps'], $pointer . '/steps', $scope, $uses );
		foreach ( $data['links'] ?? array() as $name => $link ) {
			$at             = self::pointer( $pointer . '/links', $name );
			$this->step_ids = array();
			$this->scan_steps( $link['steps'], $at . '/steps', $scope, $uses );
			$this->step_ids = array();
			foreach ( array( 'button', 'success_url', 'success_message', 'expired_url' ) as $text ) {
				if ( isset( $link[ $text ] ) ) {
					$this->scan_template( $link[ $text ], $at . '/' . $text, 'public', $scope, $uses );
				}
			}
		}

		return $uses;
	}

	/**
	 * Walks one step list and the branches of its decisions: unique ids across the tree, step references in
	 * scope, templates, values and webhook fields.
	 *
	 * Output scope: `scope.steps` holds the steps that ran on every path before this point (earlier siblings and
	 * earlier siblings of enclosing lists). A branch adds its own steps for the rest of that branch only. A loop
	 * body sees the loop's `item` and `index` (`scope.loops`), and its steps' outputs stay inside the body.
	 *
	 * @param list<array<string, mixed>> $steps   Steps.
	 * @param string                     $pointer JSON pointer of the list.
	 * @param array<string, mixed>       $scope   Scan scope; `steps` are the outputs in scope where the list starts.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 * @param string|null                $loop    Id of the loop whose body holds the list.
	 */
	private function scan_steps( array $steps, string $pointer, array $scope, array &$uses, ?string $loop = null ): void {
		foreach ( $steps as $index => $step ) {
			$at = $pointer . '/' . $index;
			if ( isset( $this->step_ids[ $step['id'] ] ) ) {
				/* translators: %s: step id. */
				$this->add( 'duplicate_key', $at . '/id', sprintf( __( 'Step id "%s" is already used in this automation.', 'pegwork' ), $step['id'] ) );
			}
			if ( null !== $loop ) {
				$this->loop_members[ $step['id'] ] = $loop;
			}

			switch ( $step['type'] ) {
				case 'update_record':
				case 'create_record':
					$own    = 'update_record' === $step['type'];
					$target = null;
					if ( $own && isset( $step['record'] ) ) {
						$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
						$target = $this->typed_reference( $step['record'], $scope );
					} elseif ( $own && ! $scope['binds'] ) {
						$this->add( 'automation_no_record', $at, __( 'This trigger binds no record; give update_record a "record".', 'pegwork' ) );
					}
					foreach ( $step['values'] as $name => $value ) {
						$value_at = self::pointer( $at . '/values', $name );
						if ( is_string( $value ) ) {
							$this->scan_template( $value, $value_at, 'private', $scope, $uses );
						}
						$uses[] = array(
							'use'     => 'write',
							'pointer' => $value_at,
							'type'    => $own ? ( isset( $step['record'] ) ? $target : $scope['type'] ) : $step['content_type'],
							'known'   => ! $own || ! isset( $step['record'] ) || null !== $target,
							'key'     => (string) $name,
							'value'   => $value,
						);
					}
					break;
				case 'send_email':
					foreach ( array( 'to', 'subject', 'text', 'html', 'reply_to' ) as $name ) {
						if ( isset( $step[ $name ] ) ) {
							$template = 'to' === $name ? $this->scan_recipients( $step['to'], $at . '/to' ) : $step[ $name ];
							$this->scan_template( $template, $at . '/' . $name, 'private', $scope, $uses, in_array( $name, array( 'text', 'html' ), true ) );
						}
					}
					break;
				case 'wait':
					if ( isset( $step['until'] ) ) {
						$this->scan_wait_until( $step['until']['var'], $at . '/until/var', $scope, $uses );
					}
					break;
				case 'trash_record':
					if ( 'schedule' === $scope['trigger'] && $scope['binds'] ) {
						$this->add( 'trash_in_sweep', $at . '/type', __( 'A schedule with "for_each" cannot trash records: use a schedule without "for_each", find_records and a loop, which trash at most 50 records a run.', 'pegwork' ) );
					}
					$this->scan_trash( $step, $at, $scope, $uses );
					break;
				case 'start_automation':
					if ( isset( $step['record'] ) ) {
						$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
					}
					$uses[] = array(
						'use'        => 'start',
						'pointer'    => $at,
						'automation' => $step['automation'],
						'record'     => isset( $step['record'] ) ? $this->typed_reference( $step['record'], $scope ) : null,
						'has_record' => isset( $step['record'] ),
						'binds'      => $scope['binds'],
						'bound'      => $scope['type'],
						'sweep'      => 'schedule' === $scope['trigger'] && $scope['binds'],
					);
					break;
				case 'call_webhook':
					$this->scan_template( $step['url'], $at . '/url', 'public', $scope, $uses );
					$sends = isset( $step['record'] ) || $scope['binds'];
					if ( isset( $step['record'] ) ) {
						$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
					} elseif ( ! empty( $step['fields'] ) && ! $scope['binds'] ) {
						$this->add( 'automation_no_record', $at . '/fields', __( 'This trigger binds no record, so the webhook has no record fields to send (send a read record with "record" and "content_type").', 'pegwork' ) );
					}
					foreach ( $sends ? ( $step['fields'] ?? array() ) : array() as $field_index => $name ) {
						$uses[] = array(
							'use'     => 'record_key',
							'pointer' => $at . '/fields/' . $field_index,
							'key'     => $name,
							'context' => 'public',
							'type'    => $step['content_type'] ?? $scope['type'],
						);
					}
					break;
				case 'condition':
					$this->scan_condition( $step['when'], $at . '/when', $scope, $uses );
					break;
				case 'decision':
					$this->step_ids[ $step['id'] ] = true;
					foreach ( $step['branches'] as $branch_index => $branch ) {
						$branch_at = $at . '/branches/' . $branch_index;
						$this->scan_condition( $branch['when'], $branch_at . '/when', $scope, $uses );
						$this->scan_steps( $branch['steps'], $branch_at . '/steps', $scope, $uses, $loop );
					}
					if ( isset( $step['otherwise'] ) ) {
						$this->scan_steps( $step['otherwise'], $at . '/otherwise', $scope, $uses, $loop );
					}
					break;
				case 'get_record':
				case 'find_records':
					$this->scan_read( $step, $at, $scope, $uses );
					break;
				case 'loop':
					$body                          = $scope;
					$body['loops'][ $step['id'] ]  = (string) $this->scan_collection( $step['over'], $at . '/over', $scope );
					$this->step_ids[ $step['id'] ] = true;
					$this->scan_steps( $step['steps'], $at . '/steps', $body, $uses, $step['id'] );
					$step['content_type'] = $body['loops'][ $step['id'] ];
					break;
				case 'relate':
					foreach ( array( 'from', 'to' ) as $end ) {
						if ( isset( $step[ $end ] ) ) {
							$this->scan_template( $step[ $end ], $at . '/' . $end, 'private', $scope, $uses );
						}
					}
					$uses[] = array(
						'use'          => 'relate',
						'pointer'      => $at,
						'relationship' => $step['relationship'],
						'from'         => isset( $step['from'] ) ? $this->typed_reference( $step['from'], $scope ) : null,
						'to'           => $this->typed_reference( $step['to'], $scope ),
						'default_from' => isset( $step['from'] ) ? false : ( $scope['binds'] ? (string) $scope['type'] : null ),
					);
					break;
			}

			$this->step_ids[ $step['id'] ] = true;
			$scope['steps'][ $step['id'] ] = $step;
		}
	}

	/**
	 * Walks a get_record or find_records step: templates and operand paths in scope, and the registry uses
	 * of its query (relationship, filter fields and operators, sums, sort).
	 *
	 * @param array<string, mixed>       $step    Valid read step.
	 * @param string                     $at      JSON pointer of the step.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_read( array $step, string $at, array $scope, array &$uses ): void {
		$type = $step['content_type'];
		if ( isset( $step['record'] ) ) {
			$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
		}
		if ( isset( $step['related'] ) ) {
			$this->scan_template( $step['related']['of'], $at . '/related/of', 'private', $scope, $uses );
			$uses[] = array(
				'use'          => 'related',
				'pointer'      => $at . '/related',
				'type'         => $type,
				'relationship' => $step['related']['relationship'],
				'of'           => $this->typed_reference( $step['related']['of'], $scope ),
			);
		}
		foreach ( $step['filter'] ?? array() as $group => $leaves ) {
			foreach ( $leaves as $index => $leaf ) {
				$leaf_at = $at . '/filter/' . $group . '/' . $index;
				if ( is_array( $leaf['value'] ?? null ) && isset( $leaf['value']['var'] ) ) {
					$this->scan_path( $leaf['value']['var'], $leaf_at . '/value/var', 'condition', false, $scope, $uses );
				}
				$uses[] = array(
					'use'     => 'read_leaf',
					'pointer' => $leaf_at,
					'leaf'    => $leaf,
					'type'    => $type,
				);
			}
		}
		foreach ( $step['created'] ?? array() as $bound => $operand ) {
			if ( is_array( $operand ) && isset( $operand['var'] ) ) {
				$bound_at = $at . '/created/' . $bound . '/var';
				$uses[]   = array(
					'use'     => 'created_var',
					'pointer' => $bound_at,
					'path'    => $operand['var'],
					'ref'     => $this->scan_path( $operand['var'], $bound_at, 'condition', false, $scope, $uses ),
				);
			}
		}
		foreach ( $step['sum'] ?? array() as $index => $key ) {
			$uses[] = array(
				'use'     => 'read_field',
				'role'    => 'sum',
				'pointer' => $at . '/sum/' . $index,
				'key'     => $key,
				'type'    => $type,
			);
		}
		if ( isset( $step['sort'] ) ) {
			$uses[] = array(
				'use'     => 'read_field',
				'role'    => 'sort',
				'pointer' => $at . '/sort/field',
				'key'     => $step['sort']['field'],
				'type'    => $type,
			);
		}
	}

	/**
	 * Resolves a collection path (a loop's `over`, an `{{#each}}` path): the records of a find_records step in
	 * scope. Reports `invalid_var` otherwise.
	 *
	 * @param string               $path    Path such as steps.due.records.
	 * @param string               $pointer JSON pointer of the path (or of its template).
	 * @param array<string, mixed> $scope   Scan scope.
	 * @return string|null Content type of the records, or null after an error.
	 */
	private function scan_collection( string $path, string $pointer, array $scope ): ?string {
		$parts = explode( '.', $path );
		$step  = 'steps' === $parts[0] && 3 === count( $parts ) ? $this->step_in_scope( $parts[1], $scope ) : null;
		$error = null;
		if ( ! preg_match( self::COLLECTION_PATTERN, $path ) ) {
			$error = __( 'a list of records is "steps.<id>.records" of a find_records step.', 'pegwork' );
		} elseif ( is_string( $step ) ) {
			$error = $step;
		} elseif ( 'find_records' !== $step['type'] ) {
			/* translators: 1: step id, 2: step type. */
			$error = sprintf( __( 'step "%1$s" is a %2$s step; only find_records steps have "records".', 'pegwork' ), $parts[1], $step['type'] );
		}
		if ( null !== $error ) {
			/* translators: 1: path, 2: reason. */
			$this->add( 'invalid_var', $pointer, sprintf( __( 'Path "%1$s": %2$s', 'pegwork' ), $path, $error ) );
			return null;
		}

		return (string) $step['content_type'];
	}

	/**
	 * The step whose outputs are in scope under an id, or why it is not.
	 *
	 * @param string               $id    Step id.
	 * @param array<string, mixed> $scope Scan scope.
	 * @return array<string, mixed>|string The step, or the reason as text.
	 */
	private function step_in_scope( string $id, array $scope ): array|string {
		if ( isset( $scope['steps'][ $id ] ) ) {
			return $scope['steps'][ $id ];
		}
		$loop = $this->loop_members[ $id ] ?? null;
		if ( null !== $loop && ! isset( $scope['loops'][ $loop ] ) ) {
			/* translators: 1: step id, 2: loop step id. */
			return sprintf( __( 'step "%1$s" runs inside loop "%2$s", so its outputs exist only later in the same item.', 'pegwork' ), $id, $loop );
		}
		if ( isset( $this->step_ids[ $id ] ) ) {
			/* translators: %s: step id. */
			return sprintf( __( 'step "%s" runs only in another branch.', 'pegwork' ), $id );
		}
		/* translators: %s: step id. */
		return sprintf( __( 'no step "%s" runs before this point.', 'pegwork' ), $id );
	}

	/**
	 * The content type of a template that is exactly a typed record reference: `{{record.id}}` of a bound
	 * record, `{{steps.<id>.record_id}}` of a get_record or create_record step, or `{{steps.<loop>.item.id}}`.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $scope    Scan scope.
	 * @return string|null Content type key, or null when the template is anything else (its type is known only at run time).
	 */
	private function typed_reference( string $template, array $scope ): ?string {
		$type = $this->typed_reference_of( $template, $scope )['type'] ?? null;

		return is_string( $type ) && '' !== $type ? $type : null;
	}

	/**
	 * Whether a template is exactly a typed record reference in scope (see typed_reference()), with its type.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $scope    Scan scope.
	 * @return array{type: string|null}|null Null when the template is anything else; `type` is null for the bound
	 *                                       record of a trigger that names no content type, '' when not known yet.
	 */
	private function typed_reference_of( string $template, array $scope ): ?array {
		if ( ! preg_match( '/^\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*\}\}$/', $template, $match ) ) {
			return null;
		}
		$parts = explode( '.', $match[1] );
		if ( array( 'record', 'id' ) === $parts && $scope['binds'] ) {
			return array( 'type' => $scope['type'] );
		}
		if ( 3 === count( $parts ) && 'steps' === $parts[0] && 'record_id' === $parts[2] ) {
			$step = $scope['steps'][ $parts[1] ] ?? null;
			return is_array( $step ) && in_array( $step['type'], array( 'get_record', 'create_record' ), true ) ? array( 'type' => (string) $step['content_type'] ) : null;
		}
		if ( 4 === count( $parts ) && 'steps' === $parts[0] && 'item' === $parts[2] && 'id' === $parts[3] && isset( $scope['loops'][ $parts[1] ] ) ) {
			return array( 'type' => (string) $scope['loops'][ $parts[1] ] );
		}

		return null;
	}

	/**
	 * Checks the parts of send_email `to`: at most MAX_RECIPIENT_PARTS, and a part that names a role is exactly
	 * `{role:<slug>}` (role tags are read from the template, never from rendered values).
	 *
	 * @param string $to      `to` template.
	 * @param string $pointer JSON pointer of `to`.
	 * @return string The template without its role parts, for scan_template() (which refuses any other `{role:`).
	 */
	private function scan_recipients( string $to, string $pointer ): string {
		$parts = array_values( array_filter( array_map( 'trim', (array) preg_split( '/\{\{.*?\}\}(*SKIP)(*FAIL)|[,;]/s', $to ) ), fn( $part ) => '' !== $part ) );
		if ( count( $parts ) > self::MAX_RECIPIENT_PARTS ) {
			/* translators: 1: recipient parts, 2: limit. */
			$this->add( 'too_many_recipients', $pointer, sprintf( __( '"to" has %1$d recipients; at most %2$d per email (a role counts as one here, and its members are capped when the email is sent).', 'pegwork' ), count( $parts ), self::MAX_RECIPIENT_PARTS ) );
		}

		return implode( ', ', array_filter( $parts, fn( $part ) => ! preg_match( self::ROLE_TAG_PATTERN, $part ) ) );
	}

	/**
	 * Checks a date wait's `until.var`: `record.<key>` of a bound record of a named content type. That the key is
	 * a date field is checked in references().
	 *
	 * @param string                     $path    Var path.
	 * @param string                     $pointer JSON pointer of the var.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_wait_until( string $path, string $pointer, array $scope, array &$uses ): void {
		$parts = explode( '.', $path );
		if ( 2 !== count( $parts ) || 'record' !== $parts[0] ) {
			/* translators: %s: path. */
			$this->add( 'wait_field', $pointer, sprintf( __( 'A wait waits until a date field of the run\'s record: "record.<date field>", not "%s".', 'pegwork' ), $path ) );
			return;
		}
		if ( ! $scope['binds'] ) {
			$this->add( 'automation_no_record', $pointer, __( 'A wait until a date needs a record, but this trigger binds none (use a record.* event with "content_type", a schedule with "for_each", a manual trigger with "content_type", or a form event that binds).', 'pegwork' ) );
			return;
		}
		if ( null === $scope['type'] ) {
			$this->add( 'wait_field', $pointer, __( 'The trigger names no content type, so the date field cannot be checked: add the trigger\'s "content_type".', 'pegwork' ) );
			return;
		}
		$uses[] = array(
			'use'     => 'wait_field',
			'pointer' => $pointer,
			'key'     => $parts[1],
			'type'    => $scope['type'],
		);
	}

	/**
	 * Checks a trash_record step's target: a typed reference of its `content_type`, or by default the bound record.
	 *
	 * @param array<string, mixed>       $step  Valid trash_record step.
	 * @param string                     $at    JSON pointer of the step.
	 * @param array<string, mixed>       $scope Scan scope.
	 * @param list<array<string, mixed>> $uses  Uses, appended to.
	 */
	private function scan_trash( array $step, string $at, array $scope, array &$uses ): void {
		$want = $step['content_type'];
		if ( isset( $step['record'] ) ) {
			$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
			$ref = $this->typed_reference_of( $step['record'], $scope );
			if ( null === $ref ) {
				$this->add( 'trash_target', $at . '/record', __( '"record" is exactly one typed reference: {{record.id}}, {{steps.<id>.record_id}} of a get_record or create_record step, or {{steps.<loop>.item.id}}.', 'pegwork' ) );
				return;
			}
			$type = $ref['type'];
			$at  .= '/record';
		} elseif ( ! $scope['binds'] ) {
			$this->add( 'automation_no_record', $at, __( 'This trigger binds no record; give trash_record a "record".', 'pegwork' ) );
			return;
		} else {
			$type = $scope['type'];
		}
		if ( '' !== $type && $want !== $type ) {
			$this->add(
				'trash_type',
				$at,
				null === $type
					/* translators: %s: content type key. */
					? sprintf( __( 'The trigger names no content type, so the record may not be a "%s" record: add the trigger\'s "content_type".', 'pegwork' ), $want )
					/* translators: 1: content type key, 2: content type key. */
					: sprintf( __( 'The record is a "%1$s" record, but this step trashes "%2$s" records only.', 'pegwork' ), $type, $want )
			);
		}
	}

	/**
	 * Every step of a step list, of its decisions' branches and of its loop bodies, in document order, by JSON pointer.
	 *
	 * @param list<array<string, mixed>> $steps   Valid steps.
	 * @param string                     $pointer JSON pointer of the list.
	 * @return array<string, array<string, mixed>>
	 */
	private static function step_tree( array $steps, string $pointer ): array {
		$found = array();
		foreach ( $steps as $index => $step ) {
			$at           = $pointer . '/' . $index;
			$found[ $at ] = $step;
			if ( 'decision' === $step['type'] ) {
				foreach ( $step['branches'] as $branch_index => $branch ) {
					$found += self::step_tree( $branch['steps'], $at . '/branches/' . $branch_index . '/steps' );
				}
				$found += self::step_tree( $step['otherwise'] ?? array(), $at . '/otherwise' );
			}
			if ( 'loop' === $step['type'] ) {
				$found += self::step_tree( $step['steps'], $at . '/steps' );
			}
		}
		return $found;
	}

	/**
	 * Whether a form event binds the record the form wrote: the form has a `target`, and the event is
	 * `form.confirmed`, or `form.submitted` of a form without `confirm` (a confirm form writes on confirmation).
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $form  Canonical form.
	 */
	public static function form_binds( string $event, array $form ): bool {
		return in_array( $event, self::FORM_BINDING_EVENTS, true ) && isset( $form['target'] )
			&& ( 'form.confirmed' === $event || ! isset( $form['confirm'] ) );
	}

	/**
	 * Walks a condition tree: size limits, operator context and paths.
	 *
	 * @param array<string, mixed>       $node    Condition root.
	 * @param string                     $pointer JSON pointer of the root.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_condition( array $node, string $pointer, array $scope, array &$uses ): void {
		$nodes = 0;
		$depth = 0;
		$this->scan_condition_node( $node, $pointer, $scope, $uses, 1, $nodes, $depth );
		if ( $depth > self::CONDITION_MAX_DEPTH || $nodes > self::CONDITION_MAX_NODES ) {
			$this->add(
				'condition_too_complex',
				$pointer,
				/* translators: 1: nesting depth, 2: node count, 3: maximum depth, 4: maximum nodes. */
				sprintf( __( 'Condition has depth %1$d and %2$d nodes; the limits are depth %3$d and %4$d nodes.', 'pegwork' ), $depth, $nodes, self::CONDITION_MAX_DEPTH, self::CONDITION_MAX_NODES )
			);
		}
	}

	/**
	 * Walks one condition node.
	 *
	 * @param array<string, mixed>       $node    Condition node.
	 * @param string                     $pointer JSON pointer of the node.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 * @param int                        $level   Depth of this node (root is 1).
	 * @param int                        $nodes   Nodes seen so far.
	 * @param int                        $depth   Deepest level seen so far.
	 */
	private function scan_condition_node( array $node, string $pointer, array $scope, array &$uses, int $level, int &$nodes, int &$depth ): void {
		++$nodes;
		$depth = max( $depth, $level );

		foreach ( array( 'all', 'any' ) as $group ) {
			if ( isset( $node[ $group ] ) ) {
				foreach ( $node[ $group ] as $index => $child ) {
					$this->scan_condition_node( $child, $pointer . '/' . $group . '/' . $index, $scope, $uses, $level + 1, $nodes, $depth );
				}
				return;
			}
		}
		if ( isset( $node['not'] ) ) {
			$this->scan_condition_node( $node['not'], $pointer . '/not', $scope, $uses, $level + 1, $nodes, $depth );
			return;
		}

		$ref = $this->scan_path( $node['var'], $pointer . '/var', 'condition', false, $scope, $uses );
		if ( is_array( $node['value'] ?? null ) && isset( $node['value']['var'] ) ) {
			$this->scan_path( $node['value']['var'], $pointer . '/value/var', 'condition', false, $scope, $uses );
		}
		if ( 'changed' === $node['op'] && ( 'record.updated' !== $scope['event'] || ! str_starts_with( $node['var'], 'record.' ) ) ) {
			$this->add( 'condition_type_mismatch', $pointer . '/op', __( '"changed" needs a record.updated trigger and a "record.<key>" var.', 'pegwork' ) );
		}

		$uses[] = array(
			'use'     => 'leaf',
			'pointer' => $pointer,
			'leaf'    => $node,
			'ref'     => $ref,
			'type'    => $ref['ctype'] ?? null,
		);
	}

	/**
	 * Checks a template's merge tags and walks their paths.
	 *
	 * @param string                     $template Template text.
	 * @param string                     $pointer  JSON pointer of the template.
	 * @param string                     $context  `private` (email, values) or `public` (webhook, link pages).
	 * @param array<string, mixed>       $scope    Scan scope.
	 * @param list<array<string, mixed>> $uses     Uses, appended to.
	 * @param bool                       $blocks   Whether `{{#each}}` blocks are allowed (send_email text and html).
	 */
	private function scan_template( string $template, string $pointer, string $context, array $scope, array &$uses, bool $blocks = false ): void {
		if ( str_contains( $template, '{role:' ) ) {
			$this->add( 'role_recipient', $pointer, __( 'A role recipient is a whole part of send_email "to", written "{role:<slug>}" (lowercase letters, digits, "_" and "-"). Roles cannot appear in "reply_to" or any other template.', 'pegwork' ) );
		}
		preg_match_all( '/\{\{(.*?)\}\}/s', $template, $tags );
		$rest = (string) preg_replace( '/\{\{.*?\}\}/s', '', $template );
		if ( str_contains( $rest, '{{' ) || str_contains( $rest, '}}' ) ) {
			$this->add( 'invalid_template', $pointer, __( 'Unbalanced "{{" or "}}" in template.', 'pegwork' ) );
		}

		$open = false; // Inside {{#each}}: false, or the block's state.
		foreach ( $tags[1] as $tag ) {
			$is_open  = (bool) preg_match( self::EACH_OPEN_PATTERN, $tag, $each );
			$is_else  = (bool) preg_match( self::EACH_ELSE_PATTERN, $tag );
			$is_close = (bool) preg_match( self::EACH_CLOSE_PATTERN, $tag );
			if ( ( $is_open || $is_else || $is_close ) && ! $blocks ) {
				$this->add( 'invalid_template', $pointer, __( '{{#each}} blocks are allowed only in send_email "text" and "html".', 'pegwork' ) );
				return;
			}
			if ( $is_open ) {
				if ( false !== $open ) {
					$this->add( 'invalid_template', $pointer, __( '{{#each}} blocks cannot be nested.', 'pegwork' ) );
					return;
				}
				$open = array(
					'type' => (string) $this->scan_collection( $each[1], $pointer, $scope ),
					'else' => false,
				);
				continue;
			}
			if ( $is_else || $is_close ) {
				if ( false === $open || ( $is_else && $open['else'] ) ) {
					$this->add( 'invalid_template', $pointer, __( '{{else}} and {{/each}} belong to an open {{#each}}, with at most one {{else}}.', 'pegwork' ) );
					return;
				}
				$open = $is_else ? array( 'else' => true ) + $open : false;
				continue;
			}

			$inner = $scope;
			if ( false !== $open && ! $open['else'] ) {
				$inner['each'] = $open['type'];
			}
			$this->scan_tag( $tag, $pointer, $context, $inner, $uses );
		}
		if ( false !== $open ) {
			$this->add( 'invalid_template', $pointer, __( '{{#each}} needs a closing {{/each}}.', 'pegwork' ) );
		}
	}

	/**
	 * Checks one merge tag: its grammar, its path and its filters.
	 *
	 * @param string                     $tag     Tag body, without the braces.
	 * @param string                     $pointer JSON pointer of the template.
	 * @param string                     $context `private` or `public`.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_tag( string $tag, string $pointer, string $context, array $scope, array &$uses ): void {
		if ( ! preg_match( self::TEMPLATE_TAG_PATTERN, $tag, $match ) ) {
			/* translators: %s: merge tag. */
			$this->add( 'invalid_template', $pointer, sprintf( __( 'Merge tag "{{%s}}" is not "{{ path }}" with optional filters in this order: | add:"P3D", then | date or | number (or | number:2), then | default:"text".', 'pegwork' ), $tag ) );
			return;
		}
		$path    = $match[1];
		$add     = $match[3] ?? '';
		$format  = trim( ltrim( trim( $match[4] ?? '' ), '|' ) );
		$filters = array_filter(
			array(
				'add'    => '' !== ( $match[2] ?? '' ),
				'date'   => 'date' === $format,
				'number' => str_starts_with( $format, 'number' ),
			)
		);
		if ( isset( $filters['add'] ) && ! preg_match( self::OFFSET_PATTERN, $add ) ) {
			/* translators: %s: duration. */
			$this->add( 'invalid_template', $pointer, sprintf( __( '"| add" takes an ISO 8601 duration with an optional sign, such as "P3D" or "-PT6H", not "%s".', 'pegwork' ), $add ) );
			return;
		}
		if ( isset( $filters['add'], $filters['number'] ) ) {
			$this->add( 'invalid_template', $pointer, __( '"| add" gives a date, so it cannot take "| number".', 'pegwork' ) );
			return;
		}

		if ( str_starts_with( $path, 'option:' ) ) {
			$parts = explode( '.', substr( $path, 7 ) );
			if ( 2 !== count( $parts ) ) {
				/* translators: %s: path. */
				$this->add( 'invalid_var', $pointer, sprintf( __( 'Path "%s": use "option:<page>.<field>".', 'pegwork' ), $path ) );
				return;
			}
			$uses[] = array(
				'use'     => 'option',
				'pointer' => $pointer,
				'page'    => $parts[0],
				'key'     => $parts[1],
				'filters' => array_keys( $filters ),
			);
			return;
		}

		$ref = $this->scan_path( $path, $pointer, $context, true, $scope, $uses );
		foreach ( array_keys( $filters ) as $filter ) {
			$this->scan_filter( $filter, $path, $ref, $pointer, $uses );
		}
	}

	/**
	 * Checks a `| date`, `| add` or `| number` filter against its path's type. `| date` and `| add` take
	 * `today`, `now` and date fields; `| number` takes number fields and number outputs. Field types are
	 * checked in references(). `| date` also passes on paths whose type is not known here (payloads, form
	 * values); `| add` and `| number` do not.
	 *
	 * @param string                     $filter  `date`, `add` or `number`.
	 * @param string                     $path    Path.
	 * @param array<string, mixed>|null  $ref     The path's type from scan_path(), null when not known.
	 * @param string                     $pointer JSON pointer of the template.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_filter( string $filter, string $path, ?array $ref, string $pointer, array &$uses ): void {
		if ( isset( $ref['ctype'] ) || ( is_array( $ref ) && array_key_exists( 'ctype', $ref ) ) ) {
			$uses[] = array(
				'use'     => 'filter',
				'filter'  => $filter,
				'pointer' => $pointer,
				'key'     => $ref['key'],
				'type'    => $ref['ctype'],
				'path'    => $path,
			);
			return;
		}

		$type = null === $ref ? null : ( $ref['def']['type'] ?? null );
		$ok   = 'number' === $filter ? 'number' === $type : ( 'date' === $type || ( 'date' === $filter && null === $ref ) );
		if ( ! $ok ) {
			$this->add(
				'invalid_template',
				$pointer,
				'number' === $filter
					/* translators: %s: path. */
					? sprintf( __( 'Path "%s" is not a number, so it cannot take "| number".', 'pegwork' ), $path )
					/* translators: 1: path, 2: filter. */
					: sprintf( __( 'Path "%1$s" is not a date, so it cannot take "| %2$s".', 'pegwork' ), $path, $filter )
			);
		}
	}

	/**
	 * Checks a path against the trigger, the earlier steps and the links, and records registry uses.
	 *
	 * @param string                     $path     Path such as record.name.
	 * @param string                     $pointer  JSON pointer of the path (or of its template).
	 * @param string                     $context  `condition`, `private` or `public`.
	 * @param bool                       $template Whether the path is in a template (allows today, now, run, link).
	 * @param array<string, mixed>       $scope    Scan scope.
	 * @param list<array<string, mixed>> $uses     Uses, appended to.
	 * @return array<string, mixed>|null The path's type when known: `{ def: { type } }` for a value of a known
	 *                                   type (`date`, `number`, `boolean`, `text`), `{ ctype, key }` for a record
	 *                                   field or taxonomy of a content type; null when not known or invalid.
	 */
	private function scan_path( string $path, string $pointer, string $context, bool $template, array $scope, array &$uses ): ?array {
		$parts = explode( '.', $path );
		$count = count( $parts );
		$root  = $parts[0];
		$bad   = function ( string $message ) use ( $pointer, $path ): void {
			/* translators: 1: path, 2: reason. */
			$this->add( 'invalid_var', $pointer, sprintf( __( 'Path "%1$s": %2$s', 'pegwork' ), $path, $message ) );
		};
		$text  = array( 'def' => array( 'type' => 'text' ) );

		if ( 'record' === $root ) {
			if ( ! $scope['binds'] ) {
				/* translators: %s: path. */
				$this->add( 'automation_no_record', $pointer, sprintf( __( 'Path "%s" needs a record, but this trigger binds none (use a record.* event, a schedule with "for_each", a manual trigger with "content_type", or a form event that binds: form.confirmed, or form.submitted of a form without "confirm", of a form with a "target").', 'pegwork' ), $path ) );
				return null;
			}
			if ( 2 !== $count ) {
				$bad( __( 'use "record.<key>".', 'pegwork' ) );
				return null;
			}
			return $this->record_path( $scope['type'], $parts[1], $pointer, $context, $template, $uses );
		}

		if ( 'item' === $root ) {
			if ( null === $scope['each'] ) {
				$bad( __( '"item.<key>" exists only inside {{#each}} ... {{/each}}; in a loop, use "steps.<loop>.item.<key>".', 'pegwork' ) );
				return null;
			}
			if ( 2 !== $count ) {
				$bad( __( 'use "item.<key>".', 'pegwork' ) );
				return null;
			}
			return $this->record_path( $scope['each'], $parts[1], $pointer, $context, $template, $uses );
		}

		if ( 'trigger' === $root ) {
			return $this->scan_trigger_path( $parts, $pointer, $context, $scope, $uses, $bad );
		}

		if ( 'steps' === $root ) {
			return $this->scan_step_path( $parts, $pointer, $context, $template, $scope, $uses, $bad );
		}

		if ( 'site' === $root ) {
			if ( 2 !== $count || ! in_array( $parts[1], array( 'name', 'url' ), true ) ) {
				$bad( __( 'use "site.name" or "site.url".', 'pegwork' ) );
			}
			return $template ? $text : null; // Conditions on site.* stay untyped, as before typed outputs.
		}

		if ( $template && in_array( $root, array( 'today', 'now' ), true ) ) {
			if ( 1 !== $count ) {
				$bad( __( 'today and now have no sub-keys.', 'pegwork' ) );
			}
			return array( 'def' => array( 'type' => 'date' ) );
		}
		if ( $template && 'run' === $root ) {
			if ( 2 !== $count || 'id' !== $parts[1] ) {
				$bad( __( 'use "run.id".', 'pegwork' ) );
			}
			return $text;
		}
		if ( $template && 'link' === $root ) {
			if ( 2 !== $count ) {
				$bad( __( 'use "link.<name>".', 'pegwork' ) );
			} elseif ( ! in_array( $parts[1], $scope['links'], true ) ) {
				/* translators: %s: link name. */
				$this->add( 'automation_unknown_link', $pointer, sprintf( __( 'Link "%s" is not defined in this automation\'s "links".', 'pegwork' ), $parts[1] ) );
			} elseif ( ! $scope['binds'] ) {
				/* translators: %s: path. */
				$this->add( 'automation_no_record', $pointer, sprintf( __( 'Path "%s" signs a link for the bound record, but this trigger binds none.', 'pegwork' ), $path ) );
			}
			return $text;
		}

		$bad(
			$template
				? __( 'paths start with record, trigger, steps, site, today, now, run or link.', 'pegwork' )
				: __( 'condition paths start with record, trigger, steps or site (use a date operand for today and now).', 'pegwork' )
		);
		return null;
	}

	/**
	 * A record key of a content type (the bound record, a read record, a loop or `{{#each}}` item): `id`,
	 * `status`, `type`, in templates `permalink` and `edit_url`, else a field or taxonomy (checked in references()).
	 *
	 * @param string|null                $type     Content type key; null when the trigger names none, '' when unknown.
	 * @param string                     $key      Key.
	 * @param string                     $pointer  JSON pointer of the path (or of its template).
	 * @param string                     $context  `condition`, `private` or `public`.
	 * @param bool                       $template Whether the path is in a template.
	 * @param list<array<string, mixed>> $uses     Uses, appended to.
	 * @return array<string, mixed> The path's type (see scan_path()).
	 */
	private function record_path( ?string $type, string $key, string $pointer, string $context, bool $template, array &$uses ): array {
		if ( 'id' === $key ) {
			return array( 'def' => array( 'type' => 'number' ) );
		}
		if ( in_array( $key, array( 'status', 'type' ), true ) || ( $template && in_array( $key, array( 'permalink', 'edit_url' ), true ) ) ) {
			return array( 'def' => array( 'type' => 'text' ) );
		}
		$uses[] = array(
			'use'     => 'record_key',
			'pointer' => $pointer,
			'key'     => $key,
			'context' => $context,
			'type'    => $type,
		);
		return array(
			'ctype' => $type,
			'key'   => $key,
		);
	}

	/**
	 * Checks a `steps.<id>.<output>[.<key>]` path: the step runs on every path before this point (or is the
	 * loop whose body holds this point), has the output, and a record or sum key is valid.
	 *
	 * @param string[]                   $parts    Path segments.
	 * @param string                     $pointer  JSON pointer of the path.
	 * @param string                     $context  `condition`, `private` or `public`.
	 * @param bool                       $template Whether the path is in a template.
	 * @param array<string, mixed>       $scope    Scan scope.
	 * @param list<array<string, mixed>> $uses     Uses, appended to.
	 * @param callable                   $bad      Reports an invalid_var error with a reason.
	 * @return array<string, mixed>|null The path's type (see scan_path()).
	 */
	private function scan_step_path( array $parts, string $pointer, string $context, bool $template, array $scope, array &$uses, callable $bad ): ?array {
		$count = count( $parts );
		if ( $count < 3 || $count > 4 ) {
			$bad( __( 'use "steps.<id>.<output>", or "steps.<id>.record.<key>", "steps.<id>.item.<key>" or "steps.<id>.sum.<field>".', 'pegwork' ) );
			return null;
		}
		[ , $id, $output ] = $parts;

		if ( isset( $scope['loops'][ $id ] ) ) {
			$type    = $scope['loops'][ $id ];
			$outputs = self::LOOP_BODY_OUTPUTS;
			if ( 'count' === $output ) {
				/* translators: %s: loop step id. */
				$bad( sprintf( __( 'the count of loop "%s" is set after the loop; inside it, use "index".', 'pegwork' ), $id ) );
				return null;
			}
			$step = array(
				'type'         => 'loop',
				'content_type' => $type,
			);
		} else {
			$step = $this->step_in_scope( $id, $scope );
			if ( is_string( $step ) ) {
				$bad( $step );
				return null;
			}
			$outputs = ( self::STEP_OUTPUTS[ $step['type'] ] ?? array() ) + ( isset( $step['on_error'] ) ? self::ON_ERROR_OUTPUTS : array() );
			if ( 'loop' === $step['type'] && isset( self::LOOP_BODY_OUTPUTS[ $output ] ) ) {
				/* translators: 1: output name, 2: loop step id. */
				$bad( sprintf( __( '"%1$s" exists only inside the body of loop "%2$s".', 'pegwork' ), $output, $id ) );
				return null;
			}
		}

		$kind = $outputs[ $output ] ?? null;
		if ( null === $kind ) {
			/* translators: 1: step type, 2: output name. */
			$bad( sprintf( __( '%1$s steps have no output "%2$s".', 'pegwork' ), $step['type'] . ( isset( self::ON_ERROR_OUTPUTS[ $output ] ) ? __( ' (without "on_error")', 'pegwork' ) : '' ), $output ) );
			return null;
		}
		if ( 'records' === $kind ) {
			$bad( __( '"records" is a list: use it as a loop\'s "over" or in {{#each}} ... {{/each}}; "count" holds how many records match.', 'pegwork' ) );
			return null;
		}
		$keyed = in_array( $kind, array( 'record', 'sum' ), true );
		if ( ( 4 === $count ) !== $keyed ) {
			$bad(
				$keyed
					/* translators: 1: step id, 2: output name. */
					? sprintf( __( 'use "steps.%1$s.%2$s.<key>".', 'pegwork' ), $id, $output )
					/* translators: 1: step id, 2: output name. */
					: sprintf( __( 'use "steps.%1$s.%2$s".', 'pegwork' ), $id, $output )
			);
			return null;
		}
		if ( 'record' === $kind ) {
			return $this->record_path( $step['content_type'], $parts[3], $pointer, $context, $template, $uses );
		}
		if ( 'sum' === $kind ) {
			if ( ! in_array( $parts[3], $step['sum'] ?? array(), true ) ) {
				/* translators: 1: field key, 2: step id. */
				$bad( sprintf( __( 'step "%2$s" does not sum "%1$s"; add it to its "sum".', 'pegwork' ), $parts[3], $id ) );
				return null;
			}
			if ( 'public' === $context ) {
				$uses[] = array(
					'use'     => 'record_key',
					'pointer' => $pointer,
					'key'     => $parts[3],
					'context' => $context,
					'type'    => $step['content_type'],
				);
			}
			return array( 'def' => array( 'type' => 'number' ) );
		}

		return array( 'def' => array( 'type' => $kind ) );
	}

	/**
	 * Checks a `trigger.*` path against the trigger's payload.
	 *
	 * @param string[]                   $parts   Path segments.
	 * @param string                     $pointer JSON pointer of the path.
	 * @param string                     $context `condition`, `private` or `public`.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 * @param callable                   $bad     Reports an invalid_var error with a reason.
	 * @return array<string, mixed>|null The path's type (see scan_path()).
	 */
	private function scan_trigger_path( array $parts, string $pointer, string $context, array $scope, array &$uses, callable $bad ): ?array {
		$count = count( $parts );
		$key   = $parts[1] ?? null;

		if ( 'webhook' === $scope['trigger'] ) {
			if ( 'payload' !== $key ) {
				$bad( __( 'webhook triggers expose "trigger.payload.*" only.', 'pegwork' ) );
			}
			return null;
		}
		if ( 'schedule' === $scope['trigger'] ) {
			$bad( __( 'schedule triggers have no payload.', 'pegwork' ) );
			return null;
		}
		if ( 'manual' === $scope['trigger'] ) {
			if ( 2 !== $count || ! in_array( $key, array( 'user_id', 'source' ), true ) ) {
				$bad( __( 'manual triggers expose "trigger.user_id" and "trigger.source".', 'pegwork' ) );
				return null;
			}
			return array( 'def' => array( 'type' => 'user_id' === $key ? 'number' : 'text' ) );
		}

		$event = (string) $scope['event'];
		if ( isset( self::RECORD_EVENT_KEYS[ $event ] ) ) {
			if ( null === $key || ! in_array( $key, self::RECORD_EVENT_KEYS[ $event ], true ) ) {
				/* translators: 1: event name, 2: comma-separated keys. */
				$bad( sprintf( __( '%1$s payloads have %2$s.', 'pegwork' ), $event, implode( ', ', self::RECORD_EVENT_KEYS[ $event ] ) ) );
				return null;
			}
			if ( 'old' === $key ) {
				if ( 3 !== $count ) {
					$bad( __( 'use "trigger.old.<key>".', 'pegwork' ) );
					return null;
				}
				$uses[] = array(
					'use'     => 'record_key',
					'pointer' => $pointer,
					'key'     => $parts[2],
					'context' => $context,
					'type'    => $scope['type'],
				);
				return array(
					'ctype' => $scope['type'],
					'key'   => $parts[2],
				);
			}
			return null;
		}

		if ( str_starts_with( $event, 'form.' ) ) {
			if ( null === $key || ! in_array( $key, self::FORM_EVENT_KEYS, true ) ) {
				/* translators: %s: comma-separated keys. */
				$bad( sprintf( __( 'form event payloads have %s.', 'pegwork' ), implode( ', ', self::FORM_EVENT_KEYS ) ) );
				return null;
			}
			if ( 'values' === $key && 3 === $count && null !== $scope['form'] ) {
				$uses[] = array(
					'use'     => 'form_value',
					'pointer' => $pointer,
					'form'    => $scope['form'],
					'key'     => $parts[2],
					'context' => $context,
				);
			}
		}
		return null;
	}

	/**
	 * Checks an automation against the registry.
	 *
	 * @param array<string, mixed>                $data    Valid automation.
	 * @param string                              $pointer JSON pointer of the automation.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 */
	private function automation_references( array $data, string $pointer, array $known ): void {
		$trigger = $data['trigger'];
		$missing = array();
		$targets = array();
		if ( isset( $trigger['content_type'] ) ) {
			$targets['/trigger/content_type'] = $trigger['content_type'];
		}
		if ( isset( $trigger['for_each'] ) ) {
			$targets['/trigger/for_each/content_type'] = $trigger['for_each']['content_type'];
		}
		foreach ( self::step_tree( $data['steps'], '/steps' ) as $at => $step ) {
			if ( isset( $step['content_type'] ) && in_array( $step['type'], array( 'create_record', 'get_record', 'find_records', 'call_webhook', 'trash_record' ), true ) ) {
				$targets[ $at . '/content_type' ] = $step['content_type'];
			}
		}
		foreach ( $targets as $at => $type ) {
			if ( ! isset( $known['content_type'][ $type ] ) ) {
				/* translators: %s: content type key. */
				$this->add( 'unknown_content_type', $pointer . $at, sprintf( __( 'Content type "%s" is not defined.', 'pegwork' ), $type ) );
				$missing[ $type ] = true;
			}
		}
		if ( isset( $trigger['form'] ) && ! isset( $known['form'][ $trigger['form'] ] ) ) {
			/* translators: %s: form key. */
			$this->add( 'unknown_form', $pointer . '/trigger/form', sprintf( __( 'Form "%s" is not defined.', 'pegwork' ), $trigger['form'] ) );
		}

		foreach ( $this->automation_scan( $data, $pointer, $known ) as $found ) {
			$type = $found['type'] ?? null;
			if ( isset( $missing[ (string) $type ] ) ) {
				continue;
			}
			match ( $found['use'] ) {
				'record_key' => $this->check_record_key( $found, $known ),
				'option'     => $this->check_option( $found, $known ),
				'filter'     => $this->check_filter( $found, $known ),
				'form_value' => $this->check_form_value( $found, $known ),
				'leaf'       => $this->check_leaf( $found, $known ),
				'write'      => $this->check_write( $found, $known ),
				'related'    => $this->check_related( $found, $known ),
				'read_leaf'  => $this->check_read_leaf( $found, $known ),
				'read_field' => $this->check_read_field( $found, $known ),
				'relate'      => $this->check_relate( $found, $known ),
				'wait_field'  => $this->check_wait_field( $found, $known ),
				'created_var' => $this->check_created_var( $found, $known ),
				'start'       => $this->check_start( $found, (string) $data['key'], $known ),
				default       => null,
			};
		}
	}

	/**
	 * Checks a date wait's field: a date field of the bound content type (private fields allowed).
	 *
	 * @param array<string, mixed>                $found Use from scan_wait_until().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_wait_field( array $found, array $known ): void {
		$definition = $this->record_key_definition( $found['type'], $found['key'], $known );
		if ( null !== $definition && 'date' !== ( $definition['type'] ?? null ) ) {
			/* translators: 1: field key, 2: content type key. */
			$this->add( 'wait_field', $found['pointer'], sprintf( __( '"%1$s" is not a date field of content type "%2$s".', 'pegwork' ), $found['key'], $found['type'] ) );
		}
	}

	/**
	 * Checks a `created` operand `{ var }`: its path reads a date when its type is known.
	 *
	 * @param array<string, mixed>                $found Use from scan_read().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_created_var( array $found, array $known ): void {
		$definition = $this->ref_definition( $found['ref'], $known );
		if ( null !== $definition && 'date' !== ( $definition['type'] ?? null ) ) {
			/* translators: %s: path. */
			$this->add( 'invalid_value', $found['pointer'], sprintf( __( 'Path "%s" is not a date, so it cannot bound "created".', 'pegwork' ), $found['path'] ) );
		}
	}

	/**
	 * Checks a start_automation step: the target is defined and has a manual trigger, a package automation starts
	 * only package automations, no chain of starts leads back to this automation, and `record` suits the
	 * target's content type.
	 *
	 * @param array<string, mixed>                $found  Use from scan_steps().
	 * @param string                              $caller Key of the automation being checked.
	 * @param array<string, array<string, mixed>> $known  Defined objects by kind, then key.
	 */
	private function check_start( array $found, string $caller, array $known ): void {
		$key = $found['automation'];
		$at  = $found['pointer'] . '/automation';
		if ( ! isset( $known['automation'][ $key ] ) ) {
			/* translators: %s: automation key. */
			$this->add( 'unknown_automation', $at, sprintf( __( 'Automation "%s" is not defined.', 'pegwork' ), $key ) );
			return;
		}
		$target = $known['automation'][ $key ];
		if ( is_array( $target ) && 'manual' !== ( $target['trigger']['type'] ?? null ) ) {
			/* translators: %s: automation key. */
			$this->add( 'start_target', $at, sprintf( __( 'Automation "%s" cannot be started: only automations with a manual trigger can.', 'pegwork' ), $key ) );
			return;
		}
		if ( Loader::DB_SOURCE !== $this->file && Loader::DB_SOURCE === ( $this->sources['automation'][ $key ] ?? null ) ) {
			/* translators: %s: automation key. */
			$this->add( 'start_target', $at, sprintf( __( 'A package automation cannot start a site automation ("%s"): the package would not load without it. Start a package automation, or save this one on the site.', 'pegwork' ), $key ) );
			return;
		}
		$path = self::start_path( $key, $caller, $known );
		if ( null !== $path ) {
			/* translators: %s: chain of automation keys. */
			$this->add( 'start_cycle', $at, sprintf( __( 'Starting it would start this automation again: %s.', 'pegwork' ), implode( ' > ', array_merge( array( $caller ), $path ) ) ) );
			return;
		}
		if ( ! is_array( $target ) ) {
			return;
		}
		if ( ! empty( $found['sweep'] ) && self::start_trashes( $key, $known ) ) {
			/* translators: %s: automation key. */
			$this->add( 'trash_in_sweep', $at, sprintf( __( 'A schedule with "for_each" cannot start automation "%s": it can move records to the trash. Use a schedule without "for_each", find_records and a loop, which trash at most 50 records a run.', 'pegwork' ), $key ) );
			return;
		}

		$type = $target['trigger']['content_type'] ?? null;
		if ( null === $type ) {
			if ( $found['has_record'] ) {
				/* translators: %s: automation key. */
				$this->add( 'start_target', $found['pointer'] . '/record', sprintf( __( 'Automation "%s" names no content type, so it takes no "record".', 'pegwork' ), $key ) );
			}
			return;
		}
		if ( $found['has_record'] ) {
			if ( null !== $found['record'] && $type !== $found['record'] ) {
				/* translators: 1: content type key, 2: automation key, 3: content type key. */
				$this->add( 'start_target', $found['pointer'] . '/record', sprintf( __( '"record" is a "%1$s" record, but automation "%2$s" runs on "%3$s" records.', 'pegwork' ), $found['record'], $key, $type ) );
			}
			return;
		}
		if ( ! $found['binds'] || ( '' !== $found['bound'] && $type !== $found['bound'] ) ) {
			/* translators: 1: automation key, 2: content type key. */
			$this->add( 'missing_property', $found['pointer'], sprintf( __( 'Missing "record": automation "%1$s" runs on a "%2$s" record, and "record" defaults to the bound record only when that is one.', 'pegwork' ), $key, $type ) );
		}
	}

	/**
	 * Whether starting an automation can move records to the trash: it, or an automation it starts (followed
	 * transitively), has a trash_record step at any depth, links included. The module's Manual::trashes()
	 * walks the registry the same way.
	 *
	 * @param string                              $key   Automation key.
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private static function start_trashes( string $key, array $known ): bool {
		$seen  = array();
		$queue = array( $key );
		while ( $queue ) {
			$current = (string) array_shift( $queue );
			$data    = $known['automation'][ $current ] ?? null;
			if ( isset( $seen[ $current ] ) || ! is_array( $data ) ) {
				continue;
			}
			$seen[ $current ] = true;
			$lists            = array( (array) ( $data['steps'] ?? array() ) );
			foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
				$lists[] = (array) ( ( (array) $link )['steps'] ?? array() );
			}
			foreach ( $lists as $list ) {
				foreach ( self::step_tree( $list, '' ) as $step ) {
					if ( 'trash_record' === ( $step['type'] ?? null ) ) {
						return true;
					}
					if ( 'start_automation' === ( $step['type'] ?? null ) && is_string( $step['automation'] ?? null ) ) {
						$queue[] = $step['automation'];
					}
				}
			}
		}
		return false;
	}

	/**
	 * The chain of starts from an automation that reaches another, or null when none does.
	 *
	 * @param string                              $from  Automation key to start from.
	 * @param string                              $to    Automation key to look for.
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 * @return string[]|null Keys from `$from` to `$to`, both included.
	 */
	private static function start_path( string $from, string $to, array $known ): ?array {
		$paths = array( $from => array( $from ) );
		$queue = array( $from );
		while ( $queue ) {
			$key = array_shift( $queue );
			if ( $key === $to ) {
				return $paths[ $key ];
			}
			$automation = $known['automation'][ $key ] ?? null;
			if ( ! is_array( $automation ) ) {
				continue;
			}
			foreach ( self::step_tree( (array) ( $automation['steps'] ?? array() ), '' ) as $step ) {
				$next = 'start_automation' === ( $step['type'] ?? null ) && is_string( $step['automation'] ?? null ) ? $step['automation'] : null;
				if ( null !== $next && ! isset( $paths[ $next ] ) ) {
					$paths[ $next ] = array_merge( $paths[ $key ], array( $next ) );
					$queue[]        = $next;
				}
			}
		}
		return null;
	}

	/**
	 * The registry definition of a record key: a field (with its type) or a taxonomy usage.
	 *
	 * @param string|null                         $type  Content type key, null when the trigger names none.
	 * @param string                              $key   Field or taxonomy key.
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 * @return array<string, mixed>|false|null The field (taxonomies get type `taxonomy` and `select`), false when
	 *                                          the key is not defined, null when the type's contents are unknown.
	 */
	private function record_key_definition( ?string $type, string $key, array $known ): array|false|null {
		$definition = null === $type ? null : ( $known['content_type'][ $type ] ?? null );
		if ( ! is_array( $definition ) ) {
			return null === $type ? false : null;
		}
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( $key === $field['key'] ) {
				return $field;
			}
		}
		foreach ( $definition['taxonomies'] ?? array() as $usage ) {
			if ( $key === $usage['key'] ) {
				return array(
					'key'        => $key,
					'type'       => 'taxonomy',
					'select'     => $usage['select'] ?? 'multi',
					'visibility' => 'public',
				);
			}
		}

		return false;
	}

	/**
	 * Checks a record field or taxonomy reference: it exists, and is public in public contexts.
	 *
	 * @param array<string, mixed>                $found  Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_record_key( array $found, array $known ): void {
		$definition = $this->record_key_definition( $found['type'], $found['key'], $known );
		if ( false === $definition ) {
			$this->add(
				'unknown_field',
				$found['pointer'],
				null === $found['type']
					/* translators: %s: field key. */
					? sprintf( __( 'Field "%s" cannot be checked: name the trigger\'s "content_type" to use record fields.', 'pegwork' ), $found['key'] )
					/* translators: 1: field key, 2: content type key. */
					: sprintf( __( '"%1$s" is not a field or taxonomy of content type "%2$s".', 'pegwork' ), $found['key'], $found['type'] )
			);
			return;
		}
		if ( is_array( $definition ) && 'public' === $found['context'] && 'private' === ( $definition['visibility'] ?? 'public' ) ) {
			/* translators: %s: field key. */
			$this->add( 'automation_private_field', $found['pointer'], sprintf( __( 'Field "%s" is private and cannot appear in webhook bodies or link pages.', 'pegwork' ), $found['key'] ) );
		}
	}

	/**
	 * Checks an `{{option:<page>.<field>}}` merge tag: the page and field exist, the field is public (a
	 * template's value can reach the run log, webhooks and pages), and `| date` is on a date field. A private
	 * address belongs in `to` as `{option:<page>.<field>}`, which only the mail itself reads.
	 *
	 * @param array<string, mixed>                $found Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_option( array $found, array $known ): void {
		$page = $known['options_page'][ $found['page'] ] ?? null;
		if ( null === $page ) {
			/* translators: %s: options page key. */
			$this->add( 'unknown_options_page', $found['pointer'], sprintf( __( 'Options page "%s" is not defined.', 'pegwork' ), $found['page'] ) );
			return;
		}
		if ( ! is_array( $page ) ) {
			return;
		}
		$field = array_column( $page['fields'] ?? array(), null, 'key' )[ $found['key'] ] ?? null;
		if ( null === $field ) {
			/* translators: 1: field key, 2: options page key. */
			$this->add( 'unknown_field', $found['pointer'], sprintf( __( 'Field "%1$s" is not defined on options page "%2$s".', 'pegwork' ), $found['key'], $found['page'] ) );
			return;
		}
		if ( 'private' === ( $field['visibility'] ?? 'public' ) ) {
			$this->add(
				'automation_private_field',
				$found['pointer'],
				/* translators: 1: options page key, 2: field key. */
				sprintf( __( 'Options field "%1$s.%2$s" is private and cannot appear in templates. For a recipient, write "{option:%1$s.%2$s}" in "to".', 'pegwork' ), $found['page'], $found['key'] )
			);
			return;
		}
		foreach ( $found['filters'] as $filter ) {
			$wanted = 'number' === $filter ? 'number' : 'date';
			if ( ( $field['type'] ?? '' ) !== $wanted ) {
				/* translators: 1: options page key, 2: field key, 3: field type, 4: filter. */
				$this->add( 'invalid_template', $found['pointer'], sprintf( __( 'Options field "%1$s.%2$s" is not a %3$s field, so it cannot take "| %4$s".', 'pegwork' ), $found['page'], $found['key'], $wanted, $filter ) );
				return;
			}
		}
	}

	/**
	 * Checks that a `| date` or `| add` filter on a record field names a date field, and `| number` a number field.
	 *
	 * @param array<string, mixed>                $found Use from scan_filter().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_filter( array $found, array $known ): void {
		$definition = $this->record_key_definition( $found['type'], $found['key'], $known );
		$wanted     = 'number' === $found['filter'] ? 'number' : 'date';
		if ( is_array( $definition ) && ( $definition['type'] ?? '' ) !== $wanted ) {
			/* translators: 1: path, 2: field type, 3: filter. */
			$this->add( 'invalid_template', $found['pointer'], sprintf( __( 'Path "%1$s" is not a %2$s field, so it cannot take "| %3$s".', 'pegwork' ), $found['path'], $wanted, $found['filter'] ) );
		}
	}

	/**
	 * Checks a `trigger.values.<key>` reference against the trigger's form: the item exists, and an item
	 * bound to a private field of the form's target type is refused in public contexts, as `record.<key>` is.
	 *
	 * @param array<string, mixed>                $found  Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_form_value( array $found, array $known ): void {
		$form = $known['form'][ $found['form'] ] ?? null;
		if ( ! is_array( $form ) ) {
			return;
		}
		$items = array_column( $this->form_items( $form, '' ), null, 'key' );
		if ( ! isset( $items[ $found['key'] ] ) ) {
			/* translators: 1: field key, 2: form key. */
			$this->add( 'unknown_field', $found['pointer'], sprintf( __( 'Field "%1$s" is not a field of form "%2$s".', 'pegwork' ), $found['key'], $found['form'] ) );
			return;
		}
		$type       = $form['target']['content_type'] ?? null;
		$definition = 'field' === $items[ $found['key'] ]['bound'] && is_string( $type ) ? $this->record_key_definition( $type, $found['key'], $known ) : null;
		if ( is_array( $definition ) && 'public' === ( $found['context'] ?? '' ) && 'private' === ( $definition['visibility'] ?? 'public' ) ) {
			/* translators: %s: field key. */
			$this->add( 'automation_private_field', $found['pointer'], sprintf( __( 'Field "%s" is private and cannot appear in webhook bodies or link pages.', 'pegwork' ), $found['key'] ) );
		}
	}

	/**
	 * Type of the value a path reads, when known: a field definition, or a pseudo field `{ type }`.
	 *
	 * @param array<string, mixed>|null           $ref   The path's type from scan_path().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 * @return array<string, mixed>|null Definition with `type`, or null when the type is not known.
	 */
	private function ref_definition( ?array $ref, array $known ): ?array {
		if ( isset( $ref['def'] ) ) {
			return $ref['def'];
		}
		if ( ! is_array( $ref ) || ! isset( $ref['key'] ) ) {
			return null;
		}
		$definition = $this->record_key_definition( $ref['ctype'], $ref['key'], $known );

		return is_array( $definition ) ? $definition : null;
	}

	/**
	 * Checks a condition leaf's operator and literal operands against the var's type.
	 *
	 * @param array<string, mixed>                $found  Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_leaf( array $found, array $known ): void {
		$leaf       = $found['leaf'];
		$definition = $this->ref_definition( $found['ref'], $known );
		$reason     = null === $definition ? null : self::leaf_mismatch( $definition, $leaf );
		if ( null !== $reason ) {
			/* translators: 1: var path, 2: reason. */
			$this->add( 'condition_type_mismatch', $found['pointer'], sprintf( __( 'Condition on "%1$s": %2$s', 'pegwork' ), $leaf['var'], $reason ) );
		}
	}

	/**
	 * Why a condition or filter leaf's operator and literal operands do not suit the value's type, or null.
	 *
	 * @param array<string, mixed> $definition Field definition or pseudo field `{ type }`.
	 * @param array<string, mixed> $leaf       Leaf with `op` and maybe `value`.
	 */
	private static function leaf_mismatch( array $definition, array $leaf ): ?string {
		$type = $definition['type'];
		$op   = $leaf['op'];

		if ( in_array( $op, self::ORDER_OPS, true ) && ! in_array( $type, array( 'number', 'date' ), true ) ) {
			/* translators: 1: operator, 2: field type. */
			return sprintf( __( '"%1$s" compares numbers and dates, not %2$s values.', 'pegwork' ), $op, $type );
		}
		if ( 'contains' === $op && ! ( 'taxonomy' === $type && 'multi' === $definition['select'] ) ) {
			return __( '"contains" applies to multi-select taxonomies only.', 'pegwork' );
		}
		if ( in_array( $op, self::UNARY_OPS, true ) ) {
			return null;
		}

		$value = $leaf['value'];
		if ( is_array( $value ) && isset( $value['date'] ) ) {
			/* translators: %s: field type. */
			return 'date' !== $type ? sprintf( __( 'a date operand cannot compare with a %s value.', 'pegwork' ), $type ) : null;
		}
		if ( is_array( $value ) && isset( $value['var'] ) ) {
			return null;
		}
		foreach ( in_array( $op, self::LIST_OPS, true ) ? $value : array( $value ) as $literal ) {
			$reason = self::literal_mismatch( $definition, $literal, 'contains' === $op );
			if ( null !== $reason ) {
				return $reason;
			}
		}
		return null;
	}

	/**
	 * Checks a read's `related`: the relationship exists, has the read type at one end, and a typed `of`
	 * reference is a record of the other end.
	 *
	 * @param array<string, mixed>                $found Use from scan_read().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_related( array $found, array $known ): void {
		$relationship = $this->relationship_definition( $found['relationship'], $found['pointer'] . '/relationship', $known );
		if ( null === $relationship ) {
			return;
		}
		$ends = array( $relationship['from'], $relationship['to'] );
		if ( ! in_array( $found['type'], $ends, true ) ) {
			/* translators: 1: relationship key, 2: content type key. */
			$this->add( 'relationship_end', $found['pointer'] . '/relationship', sprintf( __( 'Relationship "%1$s" does not link "%2$s" records.', 'pegwork' ), $found['relationship'], $found['type'] ) );
			return;
		}
		$other = $relationship['from'] === $found['type'] ? $relationship['to'] : $relationship['from'];
		if ( null !== $found['of'] && $other !== $found['of'] ) {
			/* translators: 1: content type key, 2: relationship key, 3: content type key. */
			$this->add( 'relationship_end', $found['pointer'] . '/of', sprintf( __( '"of" is a "%1$s" record, but relationship "%2$s" reads from "%3$s" records here.', 'pegwork' ), $found['of'], $found['relationship'], $other ) );
		}
	}

	/**
	 * A relationship definition, reporting `unknown_relationship` when it is not defined.
	 *
	 * @param string                              $key     Relationship key.
	 * @param string                              $pointer JSON pointer of the reference.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 * @return array<string, mixed>|null The definition, or null when missing or its contents are unknown.
	 */
	private function relationship_definition( string $key, string $pointer, array $known ): ?array {
		if ( ! isset( $known['relationship'][ $key ] ) ) {
			/* translators: %s: relationship key. */
			$this->add( 'unknown_relationship', $pointer, sprintf( __( 'Relationship "%s" is not defined.', 'pegwork' ), $key ) );
			return null;
		}
		$relationship = $known['relationship'][ $key ];

		return is_array( $relationship ) && isset( $relationship['from'], $relationship['to'] ) ? $relationship : null;
	}

	/**
	 * Checks a read filter leaf: the field exists and can be filtered, and the operator and literals fit its type.
	 *
	 * @param array<string, mixed>                $found Use from scan_read().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_read_leaf( array $found, array $known ): void {
		$leaf       = $found['leaf'];
		$definition = 'id' === $leaf['field'] ? array( 'type' => 'number' ) : $this->read_field_definition( $found['type'], $leaf['field'], $found['pointer'] . '/field', $known );
		if ( null === $definition ) {
			return;
		}
		if ( in_array( $definition['type'], RecordQuery::UNFILTERABLE_TYPES, true ) || 'post_content' === ( $definition['wp']['storage'] ?? null ) ) {
			/* translators: %s: field key. */
			$this->add( 'filter_field', $found['pointer'] . '/field', sprintf( __( 'Field "%s" holds long text and cannot be filtered.', 'pegwork' ), $leaf['field'] ) );
			return;
		}
		$reason = self::leaf_mismatch( $definition, $leaf );
		if ( null !== $reason ) {
			/* translators: 1: field key, 2: reason. */
			$this->add( 'condition_type_mismatch', $found['pointer'], sprintf( __( 'Filter on "%1$s": %2$s', 'pegwork' ), $leaf['field'], $reason ) );
		}
	}

	/**
	 * Checks a `sum` field (a number field) or the `sort` field (`id`, a text, number, date, select or email
	 * field, or a title-storage field).
	 *
	 * @param array<string, mixed>                $found Use from scan_read().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_read_field( array $found, array $known ): void {
		if ( 'sort' === $found['role'] && 'id' === $found['key'] ) {
			return;
		}
		$definition = $this->read_field_definition( $found['type'], $found['key'], $found['pointer'], $known );
		if ( null === $definition ) {
			return;
		}
		$storage = $definition['wp']['storage'] ?? null;
		if ( 'sum' === $found['role'] && 'number' !== $definition['type'] ) {
			/* translators: %s: field key. */
			$this->add( 'filter_field', $found['pointer'], sprintf( __( 'Only number fields can be summed, not "%s".', 'pegwork' ), $found['key'] ) );
		}
		if ( 'sort' === $found['role'] && ( 'post_content' === $storage || ( 'post_title' !== $storage && ! in_array( $definition['type'], RecordQuery::SORTABLE_TYPES, true ) ) ) ) {
			/* translators: %s: field key. */
			$this->add( 'filter_field', $found['pointer'], sprintf( __( 'Records cannot be sorted by "%s".', 'pegwork' ), $found['key'] ) );
		}
	}

	/**
	 * The definition of a field or taxonomy a read names, reporting `unknown_field` when it is not defined.
	 *
	 * @param string                              $type    Content type read.
	 * @param string                              $key     Field or taxonomy key.
	 * @param string                              $pointer JSON pointer of the key.
	 * @param array<string, array<string, mixed>> $known   Defined objects by kind, then key.
	 * @return array<string, mixed>|null The definition, or null when missing or the type's contents are unknown.
	 */
	private function read_field_definition( string $type, string $key, string $pointer, array $known ): ?array {
		$definition = $this->record_key_definition( $type, $key, $known );
		if ( false === $definition ) {
			/* translators: 1: field key, 2: content type key. */
			$this->add( 'unknown_field', $pointer, sprintf( __( '"%1$s" is not a field or taxonomy of content type "%2$s".', 'pegwork' ), $key, $type ) );
			return null;
		}
		return $definition;
	}

	/**
	 * Checks a relate step: the relationship exists, `from` defaults to a bound record of its "from" type, and
	 * typed `from` and `to` references are records of the right ends.
	 *
	 * @param array<string, mixed>                $found Use from scan_steps().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_relate( array $found, array $known ): void {
		$relationship = $this->relationship_definition( $found['relationship'], $found['pointer'] . '/relationship', $known );
		if ( null === $relationship ) {
			return;
		}
		$default = $found['default_from'];
		if ( false !== $default && '' !== $default && $relationship['from'] !== $default ) {
			$this->add(
				'missing_property',
				$found['pointer'],
				/* translators: 1: content type key, 2: relationship key. */
				sprintf( __( 'Missing "from": it defaults to the bound record only when that is a "%1$s" record, the "from" end of "%2$s".', 'pegwork' ), $relationship['from'], $found['relationship'] )
			);
		}
		foreach ( array( 'from', 'to' ) as $end ) {
			if ( null !== $found[ $end ] && $relationship[ $end ] !== $found[ $end ] ) {
				/* translators: 1: end, 2: content type key, 3: relationship key, 4: content type key. */
				$this->add( 'relationship_end', $found['pointer'] . '/' . $end, sprintf( __( '"%1$s" is a "%2$s" record, but the "%1$s" end of relationship "%3$s" is "%4$s".', 'pegwork' ), $end, $found[ $end ], $found['relationship'], $relationship[ $end ] ) );
			}
		}
	}

	/**
	 * Checks a value written by update_record or create_record.
	 *
	 * @param array<string, mixed>                $found  Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_write( array $found, array $known ): void {
		if ( ! $found['known'] ) {
			return; // The record comes from a template; its type is known only at run time.
		}
		$definition = $this->record_key_definition( $found['type'], $found['key'], $known );
		if ( false === $definition ) {
			$definition = $this->written_relationship( (string) $found['type'], $found['key'], $known );
		}
		if ( false === $definition ) {
			$this->add(
				'unknown_field',
				$found['pointer'],
				null === $found['type']
					/* translators: %s: field key. */
					? sprintf( __( 'Field "%s" cannot be checked: name the trigger\'s "content_type" to use record fields.', 'pegwork' ), $found['key'] )
					/* translators: 1: field key, 2: content type key. */
					: sprintf( __( '"%1$s" is not a field or taxonomy of content type "%2$s", or a relationship from it.', 'pegwork' ), $found['key'], $found['type'] )
			);
			return;
		}
		if ( null === $definition ) {
			return;
		}

		$value = $found['value'];
		if ( is_string( $value ) && ( '' === $value || str_contains( $value, '{{' ) ) ) {
			return; // Empty clears the field; templates are cast to the field type at run time.
		}
		if ( 'relationship' === $definition['type'] ) {
			$ids    = is_array( $value ) ? array() === array_filter( $value, fn( $id ) => ! is_int( $id ) || $id < 1 ) : is_string( $value ) && 1 === preg_match( '/^\s*[1-9][0-9]*(\s*,\s*[1-9][0-9]*)*\s*$/', $value );
			$reason = $ids ? null : __( 'a relationship takes record IDs: a template, comma-separated IDs or a list of integers.', 'pegwork' );
		} elseif ( is_array( $value ) ) {
			$reason = 'taxonomy' === $definition['type'] && 'multi' === $definition['select'] && count( array_filter( $value, 'is_string' ) ) === count( $value )
				? null
				: __( 'only multi-select taxonomies take a list, of term slugs.', 'pegwork' );
		} else {
			$reason = self::literal_mismatch( $definition, $value, false );
		}
		if ( null !== $reason ) {
			/* translators: 1: field key, 2: reason. */
			$this->add( 'automation_value_type', $found['pointer'], sprintf( __( 'Value for "%1$s": %2$s', 'pegwork' ), $found['key'], $reason ) );
		}
	}

	/**
	 * A relationship whose "from" end is the written type, as a pseudo field `{ type: relationship }`.
	 *
	 * @param string                              $type  Content type written.
	 * @param string                              $key   Values key.
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 * @return array<string, mixed>|false|null False when no such relationship, null when its contents are unknown.
	 */
	private function written_relationship( string $type, string $key, array $known ): array|false|null {
		$relationship = $known['relationship'][ $key ] ?? null;
		if ( null === $relationship ) {
			return false;
		}
		if ( ! is_array( $relationship ) || ! isset( $relationship['from'] ) ) {
			return null;
		}

		return $type === $relationship['from'] ? array( 'type' => 'relationship' ) : false;
	}

	/**
	 * Why a literal does not suit a field, or null when it does.
	 *
	 * @param array<string, mixed> $definition Field definition (taxonomies have type `taxonomy`).
	 * @param mixed                $literal    Literal value.
	 * @param bool                 $term       Whether the literal is one term slug of a multi taxonomy.
	 */
	private static function literal_mismatch( array $definition, mixed $literal, bool $term ): ?string {
		$type = $definition['type'];

		$ok = match ( $type ) {
			'boolean'  => is_bool( $literal ),
			'number'   => is_int( $literal ) || is_float( $literal ),
			'date'     => is_string( $literal ) && 1 === preg_match( self::DATE_PATTERN, $literal ),
			'select'   => is_string( $literal ) && in_array( $literal, array_column( $definition['options'] ?? array(), 'value' ), true ),
			'taxonomy' => is_string( $literal ) && ( $term || 1 === preg_match( self::SLUG_PATTERN, $literal ) ),
			default    => is_string( $literal ),
		};
		if ( $ok ) {
			return null;
		}

		return match ( $type ) {
			'boolean'  => __( 'expected true or false.', 'pegwork' ),
			'number'   => __( 'expected a number.', 'pegwork' ),
			'date'     => __( 'expected a Y-m-d date.', 'pegwork' ),
			/* translators: %s: comma-separated option values. */
			'select'   => sprintf( __( 'expected one of the options: %s.', 'pegwork' ), implode( ', ', array_column( $definition['options'] ?? array(), 'value' ) ) ),
			'taxonomy' => __( 'expected a term slug.', 'pegwork' ),
			default    => __( 'expected a string.', 'pegwork' ),
		};
	}
}
