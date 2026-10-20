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
	 * One merge tag body: a path or `option:<page>.<field>` (1), an optional `date` filter (2), an optional default filter.
	 */
	public const TEMPLATE_TAG_PATTERN = '/^\s*((?:option:)?[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)\s*(\|\s*date\s*)?(?:\|\s*default:"[^"]*"\s*)?$/';

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
	public const STEP_TYPES      = array( 'update_record', 'create_record', 'send_email', 'call_webhook', 'wait', 'condition' );
	public const LINK_STEP_TYPES = array( 'update_record', 'condition' );

	/**
	 * Outputs a step exposes as `steps.<id>.<output>`.
	 */
	public const STEP_OUTPUTS = array( 'create_record' => array( 'record_id' ) );

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
	 * Wait and link expiry bounds.
	 */
	public const DURATION_MIN = 'PT1H';
	public const DURATION_MAX = 'P2Y';

	/**
	 * Outbound webhook timeout limit, seconds.
	 */
	public const WEBHOOK_MAX_TIMEOUT = 15;

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
		}
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
		if ( ! in_array( $type, array( 'event', 'schedule', 'webhook' ), true ) ) {
			if ( null === $type ) {
				$this->add( 'missing_property', $pointer, __( 'Missing required property "type".', 'pegwork' ) );
			} else {
				$this->enum( $type, $pointer . '/type', array( 'event', 'schedule', 'webhook' ) );
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

		$is_object = $this->props( $trigger, $pointer, $checks, $needed );
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
	 * Validates a step list (at least one step).
	 *
	 * @param mixed  $steps   Decoded list.
	 * @param string $pointer JSON pointer of the list.
	 * @param bool   $link    Whether the list belongs to a signed link.
	 */
	private function automation_steps( mixed $steps, string $pointer, bool $link ): void {
		$this->list_of( $steps, $pointer, fn( $step, $p ) => $this->automation_step( $step, $p, $link ) );
		if ( array() === $steps ) {
			$this->add( 'invalid_value', $pointer, __( 'Add at least one step.', 'pegwork' ) );
		}
	}

	/**
	 * Validates one step.
	 *
	 * @param mixed  $step    Decoded step.
	 * @param string $pointer JSON pointer of the step.
	 * @param bool   $link    Whether the step belongs to a signed link.
	 */
	private function automation_step( mixed $step, string $pointer, bool $link ): void {
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
					'url'     => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'string' ) && ! preg_match( '#^https?://\S+$#', $v ) ) {
							$this->add( 'invalid_value', $p, __( 'Webhook URLs start with http:// or https://.', 'pegwork' ) );
						}
					},
					'fields'  => fn( $v, $p ) => $this->list_of( $v, $p, fn( $item, $q ) => $this->key( $item, $q ) ),
					'timeout' => function ( $v, $p ) {
						if ( $this->type( $v, $p, 'number' ) && ( $v <= 0 || $v > self::WEBHOOK_MAX_TIMEOUT ) ) {
							/* translators: %d: maximum seconds. */
							$this->add( 'invalid_value', $p, sprintf( __( '"timeout" is more than 0 and at most %d seconds.', 'pegwork' ), self::WEBHOOK_MAX_TIMEOUT ) );
						}
					},
				),
				array( 'url' ),
			),
			'wait'          => array( array( 'for' => fn( $v, $p ) => $this->duration( $v, $p, true ) ), array( 'for' ) ),
			'condition'     => array( array( 'when' => fn( $v, $p ) => $this->automation_condition( $v, $p ) ), array( 'when' ) ),
		};

		$this->props( $step, $pointer, $checks + $own[0], array_merge( array( 'id', 'type' ), $own[1] ) );
	}

	/**
	 * Validates a `values` map: at least one key, each a template string, number, boolean or list of strings.
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
			$ok = is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value )
				|| ( is_array( $value ) && array_is_list( $value ) && count( array_filter( $value, 'is_string' ) ) === count( $value ) );
			if ( ! $ok ) {
				$this->add( 'invalid_type', self::pointer( $pointer, $name ), __( 'Expected a string, number, boolean or list of strings.', 'pegwork' ) );
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
	 * @param array<string, mixed> $data    Automation that passed the shape checks.
	 * @param string               $pointer JSON pointer of the automation.
	 * @return list<array<string, mixed>> Uses: each has `use` (record_key, form_value, leaf, write, webhook_field) and `pointer`.
	 */
	private function automation_scan( array $data, string $pointer ): array {
		$trigger = $data['trigger'];
		$scope   = array(
			'trigger' => $trigger['type'],
			'event'   => $trigger['event'] ?? null,
			'form'    => $trigger['form'] ?? null,
			'binds'   => ( 'event' === $trigger['type'] && str_starts_with( $trigger['event'], 'record.' ) ) || isset( $trigger['for_each'] ),
			'type'    => $trigger['content_type'] ?? ( $trigger['for_each']['content_type'] ?? null ),
			'links'   => array_keys( $data['links'] ?? array() ),
			'steps'   => array(),
		);
		$uses    = array();

		if ( isset( $trigger['when'] ) ) {
			$this->scan_condition( $trigger['when'], $pointer . '/trigger/when', $scope, $uses );
		}
		if ( isset( $trigger['for_each']['where'] ) ) {
			$this->scan_condition( $trigger['for_each']['where'], $pointer . '/trigger/for_each/where', $scope, $uses );
		}
		if ( isset( $data['idempotency'] ) ) {
			$this->scan_template( $data['idempotency'], $pointer . '/idempotency', 'private', $scope, $uses );
		}

		$this->scan_steps( $data['steps'], $pointer . '/steps', $scope, $uses );
		foreach ( $data['links'] ?? array() as $name => $link ) {
			$at = self::pointer( $pointer . '/links', $name );
			$this->scan_steps( $link['steps'], $at . '/steps', $scope, $uses );
			foreach ( array( 'button', 'success_url', 'success_message', 'expired_url' ) as $text ) {
				if ( isset( $link[ $text ] ) ) {
					$this->scan_template( $link[ $text ], $at . '/' . $text, 'public', $scope, $uses );
				}
			}
		}

		return $uses;
	}

	/**
	 * Walks one step list: unique ids, step references in order, templates, values and webhook fields.
	 *
	 * @param list<array<string, mixed>> $steps   Steps.
	 * @param string                     $pointer JSON pointer of the list.
	 * @param array<string, mixed>       $scope   Scan scope; `steps` is reset for this list.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_steps( array $steps, string $pointer, array $scope, array &$uses ): void {
		$scope['steps'] = array();

		foreach ( $steps as $index => $step ) {
			$at = $pointer . '/' . $index;
			if ( isset( $scope['steps'][ $step['id'] ] ) ) {
				/* translators: %s: step id. */
				$this->add( 'duplicate_key', $at . '/id', sprintf( __( 'Step id "%s" is already used in this step list.', 'pegwork' ), $step['id'] ) );
			}

			switch ( $step['type'] ) {
				case 'update_record':
				case 'create_record':
					$own = 'update_record' === $step['type'];
					if ( $own && isset( $step['record'] ) ) {
						$this->scan_template( $step['record'], $at . '/record', 'private', $scope, $uses );
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
							'type'    => $own ? ( isset( $step['record'] ) ? null : $scope['type'] ) : $step['content_type'],
							'known'   => ! $own || ! isset( $step['record'] ),
							'key'     => (string) $name,
							'value'   => $value,
						);
					}
					break;
				case 'send_email':
					foreach ( array( 'to', 'subject', 'text', 'html', 'reply_to' ) as $name ) {
						if ( isset( $step[ $name ] ) ) {
							$this->scan_template( $step[ $name ], $at . '/' . $name, 'private', $scope, $uses );
						}
					}
					break;
				case 'call_webhook':
					$this->scan_template( $step['url'], $at . '/url', 'public', $scope, $uses );
					if ( ! empty( $step['fields'] ) && ! $scope['binds'] ) {
						$this->add( 'automation_no_record', $at . '/fields', __( 'This trigger binds no record, so the webhook has no record fields to send.', 'pegwork' ) );
					}
					foreach ( $scope['binds'] ? ( $step['fields'] ?? array() ) : array() as $field_index => $name ) {
						$uses[] = array(
							'use'     => 'record_key',
							'pointer' => $at . '/fields/' . $field_index,
							'key'     => $name,
							'context' => 'public',
							'type'    => $scope['type'],
						);
					}
					break;
				case 'condition':
					$this->scan_condition( $step['when'], $at . '/when', $scope, $uses );
					break;
			}

			$scope['steps'][ $step['id'] ] = $step['type'];
		}
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

		$this->scan_path( $node['var'], $pointer . '/var', 'condition', false, $scope, $uses );
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
			'type'    => $scope['type'],
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
	 */
	private function scan_template( string $template, string $pointer, string $context, array $scope, array &$uses ): void {
		preg_match_all( '/\{\{(.*?)\}\}/s', $template, $tags );
		$rest = (string) preg_replace( '/\{\{.*?\}\}/s', '', $template );
		if ( str_contains( $rest, '{{' ) || str_contains( $rest, '}}' ) ) {
			$this->add( 'invalid_template', $pointer, __( 'Unbalanced "{{" or "}}" in template.', 'pegwork' ) );
		}

		foreach ( $tags[1] as $tag ) {
			if ( ! preg_match( self::TEMPLATE_TAG_PATTERN, $tag, $match ) ) {
				/* translators: %s: merge tag. */
				$this->add( 'invalid_template', $pointer, sprintf( __( 'Merge tag "{{%s}}" is not "{{ path }}", "{{ path | date }}" or "{{ path | default:"text" }}" (date before default).', 'pegwork' ), $tag ) );
				continue;
			}
			$path = $match[1];
			$date = '' !== trim( $match[2] ?? '' );
			if ( str_starts_with( $path, 'option:' ) ) {
				$parts = explode( '.', substr( $path, 7 ) );
				if ( 2 !== count( $parts ) ) {
					/* translators: %s: path. */
					$this->add( 'invalid_var', $pointer, sprintf( __( 'Path "%s": use "option:<page>.<field>".', 'pegwork' ), $path ) );
					continue;
				}
				$uses[] = array(
					'use'     => 'option',
					'pointer' => $pointer,
					'page'    => $parts[0],
					'key'     => $parts[1],
					'date'    => $date,
				);
				continue;
			}
			$this->scan_path( $path, $pointer, $context, true, $scope, $uses );
			if ( $date ) {
				$this->scan_date_filter( $path, $pointer, $scope, $uses );
			}
		}
	}

	/**
	 * Checks a `| date` filter's path: `today`, `now`, a record (or `trigger.old`) field that must be a date
	 * field (checked in references()), or a path whose type is not known here (payloads, step outputs).
	 *
	 * @param string                     $path    Path.
	 * @param string                     $pointer JSON pointer of the template.
	 * @param array<string, mixed>       $scope   Scan scope.
	 * @param list<array<string, mixed>> $uses    Uses, appended to.
	 */
	private function scan_date_filter( string $path, string $pointer, array $scope, array &$uses ): void {
		$parts = explode( '.', $path );
		$key   = null;
		if ( 2 === count( $parts ) && 'record' === $parts[0] ) {
			$key = $parts[1];
		} elseif ( 3 === count( $parts ) && 'trigger' === $parts[0] && 'old' === $parts[1] ) {
			$key = $parts[2];
		} elseif ( ! in_array( $parts[0], array( 'site', 'run', 'link' ), true ) ) {
			return;
		}

		if ( null === $key || in_array( $key, array( 'id', 'status', 'type', 'permalink', 'edit_url' ), true ) ) {
			/* translators: %s: path. */
			$this->add( 'invalid_template', $pointer, sprintf( __( 'Path "%s" is not a date, so it cannot take "| date".', 'pegwork' ), $path ) );
			return;
		}
		if ( $scope['binds'] || 'trigger' === $parts[0] ) {
			$uses[] = array(
				'use'     => 'date_filter',
				'pointer' => $pointer,
				'key'     => $key,
				'type'    => $scope['type'],
				'path'    => $path,
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
	 */
	private function scan_path( string $path, string $pointer, string $context, bool $template, array $scope, array &$uses ): void {
		$parts = explode( '.', $path );
		$count = count( $parts );
		$root  = $parts[0];
		$bad   = function ( string $message ) use ( $pointer, $path ): void {
			/* translators: 1: path, 2: reason. */
			$this->add( 'invalid_var', $pointer, sprintf( __( 'Path "%1$s": %2$s', 'pegwork' ), $path, $message ) );
		};

		if ( 'record' === $root ) {
			if ( ! $scope['binds'] ) {
				/* translators: %s: path. */
				$this->add( 'automation_no_record', $pointer, sprintf( __( 'Path "%s" needs a record, but this trigger binds none (use a record.* event or a schedule with "for_each").', 'pegwork' ), $path ) );
				return;
			}
			if ( 2 !== $count ) {
				$bad( __( 'use "record.<key>".', 'pegwork' ) );
				return;
			}
			if ( in_array( $parts[1], array( 'id', 'status', 'type' ), true ) || ( $template && in_array( $parts[1], array( 'permalink', 'edit_url' ), true ) ) ) {
				return;
			}
			$uses[] = array(
				'use'     => 'record_key',
				'pointer' => $pointer,
				'key'     => $parts[1],
				'context' => $context,
				'type'    => $scope['type'],
			);
			return;
		}

		if ( 'trigger' === $root ) {
			$this->scan_trigger_path( $parts, $pointer, $context, $scope, $uses, $bad );
			return;
		}

		if ( 'steps' === $root ) {
			$type = 3 === $count ? ( $scope['steps'][ $parts[1] ] ?? null ) : null;
			if ( 3 !== $count ) {
				$bad( __( 'use "steps.<id>.<output>".', 'pegwork' ) );
			} elseif ( null === $type ) {
				/* translators: %s: step id. */
				$bad( sprintf( __( 'no step "%s" runs before this point.', 'pegwork' ), $parts[1] ) );
			} elseif ( ! in_array( $parts[2], self::STEP_OUTPUTS[ $type ] ?? array(), true ) ) {
				/* translators: 1: step type, 2: output name. */
				$bad( sprintf( __( '%1$s steps have no output "%2$s".', 'pegwork' ), $type, $parts[2] ) );
			}
			return;
		}

		if ( 'site' === $root ) {
			if ( 2 !== $count || ! in_array( $parts[1], array( 'name', 'url' ), true ) ) {
				$bad( __( 'use "site.name" or "site.url".', 'pegwork' ) );
			}
			return;
		}

		if ( $template && in_array( $root, array( 'today', 'now' ), true ) ) {
			if ( 1 !== $count ) {
				$bad( __( 'today and now have no sub-keys.', 'pegwork' ) );
			}
			return;
		}
		if ( $template && 'run' === $root ) {
			if ( 2 !== $count || 'id' !== $parts[1] ) {
				$bad( __( 'use "run.id".', 'pegwork' ) );
			}
			return;
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
			return;
		}

		$bad(
			$template
				? __( 'paths start with record, trigger, steps, site, today, now, run or link.', 'pegwork' )
				: __( 'condition paths start with record, trigger, steps or site (use a date operand for today and now).', 'pegwork' )
		);
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
	 */
	private function scan_trigger_path( array $parts, string $pointer, string $context, array $scope, array &$uses, callable $bad ): void {
		$count = count( $parts );
		$key   = $parts[1] ?? null;

		if ( 'webhook' === $scope['trigger'] ) {
			if ( 'payload' !== $key ) {
				$bad( __( 'webhook triggers expose "trigger.payload.*" only.', 'pegwork' ) );
			}
			return;
		}
		if ( 'schedule' === $scope['trigger'] ) {
			$bad( __( 'schedule triggers have no payload.', 'pegwork' ) );
			return;
		}

		$event = (string) $scope['event'];
		if ( isset( self::RECORD_EVENT_KEYS[ $event ] ) ) {
			if ( null === $key || ! in_array( $key, self::RECORD_EVENT_KEYS[ $event ], true ) ) {
				/* translators: 1: event name, 2: comma-separated keys. */
				$bad( sprintf( __( '%1$s payloads have %2$s.', 'pegwork' ), $event, implode( ', ', self::RECORD_EVENT_KEYS[ $event ] ) ) );
				return;
			}
			if ( 'old' === $key ) {
				if ( 3 !== $count ) {
					$bad( __( 'use "trigger.old.<key>".', 'pegwork' ) );
					return;
				}
				$uses[] = array(
					'use'     => 'record_key',
					'pointer' => $pointer,
					'key'     => $parts[2],
					'context' => $context,
					'type'    => $scope['type'],
				);
			}
			return;
		}

		if ( str_starts_with( $event, 'form.' ) ) {
			if ( null === $key || ! in_array( $key, self::FORM_EVENT_KEYS, true ) ) {
				/* translators: %s: comma-separated keys. */
				$bad( sprintf( __( 'form event payloads have %s.', 'pegwork' ), implode( ', ', self::FORM_EVENT_KEYS ) ) );
				return;
			}
			if ( 'values' === $key && 3 === $count && null !== $scope['form'] ) {
				$uses[] = array(
					'use'     => 'form_value',
					'pointer' => $pointer,
					'form'    => $scope['form'],
					'key'     => $parts[2],
				);
			}
		}
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
		foreach ( array( '/steps' => $data['steps'] ) as $list => $steps ) {
			foreach ( $steps as $index => $step ) {
				if ( 'create_record' === $step['type'] ) {
					$targets[ $list . '/' . $index . '/content_type' ] = $step['content_type'];
				}
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

		foreach ( $this->automation_scan( $data, $pointer ) as $found ) {
			$type = $found['type'] ?? null;
			if ( isset( $missing[ (string) $type ] ) ) {
				continue;
			}
			match ( $found['use'] ) {
				'record_key'    => $this->check_record_key( $found, $known ),
				'option'        => $this->check_option( $found, $known ),
				'date_filter'   => $this->check_date_filter( $found, $known ),
				'form_value'    => $this->check_form_value( $found, $known ),
				'leaf'          => $this->check_leaf( $found, $known ),
				'write'         => $this->check_write( $found, $known ),
				default         => null,
			};
		}
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
		if ( $found['date'] && 'date' !== ( $field['type'] ?? '' ) ) {
			/* translators: 1: options page key, 2: field key. */
			$this->add( 'invalid_template', $found['pointer'], sprintf( __( 'Options field "%1$s.%2$s" is not a date field, so it cannot take "| date".', 'pegwork' ), $found['page'], $found['key'] ) );
		}
	}

	/**
	 * Checks that a `| date` filter on a record field names a date field.
	 *
	 * @param array<string, mixed>                $found Use from scan_date_filter().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_date_filter( array $found, array $known ): void {
		$definition = $this->record_key_definition( $found['type'], $found['key'], $known );
		if ( is_array( $definition ) && 'date' !== ( $definition['type'] ?? '' ) ) {
			/* translators: %s: path. */
			$this->add( 'invalid_template', $found['pointer'], sprintf( __( 'Path "%s" is not a date field, so it cannot take "| date".', 'pegwork' ), $found['path'] ) );
		}
	}

	/**
	 * Checks a `trigger.values.<key>` reference against the trigger's form.
	 *
	 * @param array<string, mixed>                $found  Use from automation_scan().
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 */
	private function check_form_value( array $found, array $known ): void {
		$form = $known['form'][ $found['form'] ] ?? null;
		if ( ! is_array( $form ) ) {
			return;
		}
		$keys = array_column( $this->form_items( $form, '' ), 'key' );
		if ( ! in_array( $found['key'], $keys, true ) ) {
			/* translators: 1: field key, 2: form key. */
			$this->add( 'unknown_field', $found['pointer'], sprintf( __( 'Field "%1$s" is not a field of form "%2$s".', 'pegwork' ), $found['key'], $found['form'] ) );
		}
	}

	/**
	 * Type of the value a condition var reads, when known: a field definition, or a pseudo field.
	 *
	 * @param string                              $path  Var path.
	 * @param string|null                         $type  Bound content type.
	 * @param array<string, array<string, mixed>> $known Defined objects by kind, then key.
	 * @return array<string, mixed>|null Definition with `type`, or null when the type is not known.
	 */
	private function var_definition( string $path, ?string $type, array $known ): ?array {
		$parts = explode( '.', $path );
		if ( 'record' === $parts[0] && 2 === count( $parts ) ) {
			if ( 'id' === $parts[1] ) {
				return array( 'type' => 'number' );
			}
			if ( in_array( $parts[1], array( 'status', 'type' ), true ) ) {
				return array( 'type' => 'text' );
			}
			$key = $parts[1];
		} elseif ( 'trigger' === $parts[0] && 'old' === ( $parts[1] ?? null ) && 3 === count( $parts ) ) {
			$key = $parts[2];
		} else {
			return null;
		}

		$definition = $this->record_key_definition( $type, $key, $known );

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
		$definition = $this->var_definition( $leaf['var'], $found['type'], $known );
		if ( null === $definition ) {
			return;
		}
		$type     = $definition['type'];
		$op       = $leaf['op'];
		$mismatch = function ( string $message ) use ( $found, $leaf ): void {
			/* translators: 1: var path, 2: reason. */
			$this->add( 'condition_type_mismatch', $found['pointer'], sprintf( __( 'Condition on "%1$s": %2$s', 'pegwork' ), $leaf['var'], $message ) );
		};

		if ( in_array( $op, self::ORDER_OPS, true ) && ! in_array( $type, array( 'number', 'date' ), true ) ) {
			/* translators: 1: operator, 2: field type. */
			$mismatch( sprintf( __( '"%1$s" compares numbers and dates, not %2$s values.', 'pegwork' ), $op, $type ) );
			return;
		}
		if ( 'contains' === $op && ! ( 'taxonomy' === $type && 'multi' === $definition['select'] ) ) {
			$mismatch( __( '"contains" applies to multi-select taxonomies only.', 'pegwork' ) );
			return;
		}
		if ( in_array( $op, self::UNARY_OPS, true ) ) {
			return;
		}

		$value = $leaf['value'];
		if ( is_array( $value ) && isset( $value['date'] ) ) {
			if ( 'date' !== $type ) {
				/* translators: %s: field type. */
				$mismatch( sprintf( __( 'a date operand cannot compare with a %s value.', 'pegwork' ), $type ) );
			}
			return;
		}
		if ( is_array( $value ) && isset( $value['var'] ) ) {
			return;
		}
		foreach ( in_array( $op, self::LIST_OPS, true ) ? $value : array( $value ) as $literal ) {
			$reason = self::literal_mismatch( $definition, $literal, 'contains' === $op );
			if ( null !== $reason ) {
				$mismatch( $reason );
				return;
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
			$this->check_record_key( $found + array( 'context' => 'private' ), $known );
			return;
		}
		if ( null === $definition ) {
			return;
		}

		$value = $found['value'];
		if ( is_string( $value ) && ( '' === $value || str_contains( $value, '{{' ) ) ) {
			return; // Empty clears the field; templates are cast to the field type at run time.
		}
		if ( is_array( $value ) ) {
			$reason = 'taxonomy' === $definition['type'] && 'multi' === $definition['select']
				? null
				: __( 'only multi-select taxonomies take a list.', 'pegwork' );
		} else {
			$reason = self::literal_mismatch( $definition, $value, false );
		}
		if ( null !== $reason ) {
			/* translators: 1: field key, 2: reason. */
			$this->add( 'automation_value_type', $found['pointer'], sprintf( __( 'Value for "%1$s": %2$s', 'pegwork' ), $found['key'], $reason ) );
		}
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
