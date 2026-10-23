<?php
/**
 * Dry runs and template previews.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use Pegwork\Api\Records;
use PegworkAutomation\Steps\CallWebhook;
use PegworkAutomation\Steps\Decision;
use PegworkAutomation\Steps\SendEmail;
use WP_Error;

/**
 * Walks an automation's steps with the same Plan the executor uses, and reports what each step would
 * do. No step handler runs: no email, no HTTP, no record write, no run row, no signed link and no
 * secret or link key. Decisions and conditions are evaluated; a false condition stops the walk ("stops
 * here"); waits continue as if they had passed, against the record as it is now.
 *
 * The trigger payload is synthesized from the sample record (`old.*` holds the current values,
 * `changed` is empty, form `values` are the record's values for the form's bound keys), or for a
 * webhook trigger it is the pasted sample payload. The context is built without an automation key,
 * so even a raw lookup of `link.*` cannot mint a link.
 *
 * Used by `POST pegwork/v1/automation-editor/test` and `wp pegwork automation test`, which print the
 * same report (lines()).
 */
final class DryRun {

	/**
	 * Most steps one report walks (the executor's per-run limit).
	 */
	private const MAX_STEPS = Executor::MAX_STEPS;

	/**
	 * The content type whose record a trigger binds: a record event's or sweep's `content_type`, or the
	 * target of a form whose events bind their record. Null when the trigger binds none.
	 *
	 * @param array<string, mixed> $trigger Trigger.
	 */
	public static function bound_type( array $trigger ): ?string {
		$type = $trigger['content_type'] ?? ( $trigger['for_each']['content_type'] ?? null );
		if ( is_string( $type ) ) {
			return $type;
		}
		if ( Triggers::binds_form( $trigger ) ) {
			$form = pegwork_registry()->form( (string) $trigger['form'] );
			$type = $form['target']['content_type'] ?? null;
			return is_string( $type ) ? $type : null;
		}
		return null;
	}

	/**
	 * The sample record for a trigger, checked against its bound type.
	 *
	 * @param array<string, mixed> $trigger   Trigger.
	 * @param int|null             $record_id Sample record ID, or null.
	 * @return array<string, mixed>|null|WP_Error The record (Records::get shape), null when the trigger
	 *                                            binds none, or a 400 error.
	 */
	public static function sample_record( array $trigger, ?int $record_id ): array|null|WP_Error {
		$type = self::bound_type( $trigger );
		// A record event without a content type binds the record of any type.
		$any = null === $type && 'event' === ( $trigger['type'] ?? '' ) && str_starts_with( (string) ( $trigger['event'] ?? '' ), 'record.' );
		if ( null === $type && ! $any ) {
			return null;
		}
		if ( null === $record_id || $record_id <= 0 ) {
			return new WP_Error(
				'pegwork_test_record',
				$any
					? __( 'Choose a sample record.', 'pegwork' )
					/* translators: %s: content type key. */
					: sprintf( __( 'Choose a sample %s record.', 'pegwork' ), $type ),
				array( 'status' => 400 )
			);
		}
		$record = Records::get( $record_id );
		if ( null === $record || ( ! $any && $record['type'] !== $type ) ) {
			return new WP_Error(
				'pegwork_test_record',
				$any
					/* translators: %d: record ID. */
					? sprintf( __( 'Record %d is not a Pegwork record.', 'pegwork' ), $record_id )
					/* translators: 1: record ID, 2: content type key. */
					: sprintf( __( 'Record %1$d is not a %2$s record.', 'pegwork' ), $record_id, $type ),
				array( 'status' => 400 )
			);
		}
		return $record;
	}

	/**
	 * The trigger payload a real event would carry, synthesized from the sample record.
	 *
	 * @param array<string, mixed>      $trigger Trigger.
	 * @param array<string, mixed>|null $record  Sample record, or null.
	 * @param array<mixed>|null         $payload Pasted sample payload (webhook triggers; form values of
	 *                                           forms that bind no record), or null.
	 * @return array<string, mixed>
	 */
	public static function payload( array $trigger, ?array $record, ?array $payload ): array {
		$origin = array(
			'source' => 'test',
			'depth'  => 0,
		);
		if ( 'webhook' === ( $trigger['type'] ?? '' ) ) {
			return array(
				'payload' => $payload ?? array(),
				'origin'  => $origin,
			);
		}
		$event = (string) ( $trigger['event'] ?? '' );
		if ( in_array( $event, array( 'form.submitted', 'form.confirmed' ), true ) ) {
			return array(
				'form'      => (string) ( $trigger['form'] ?? '' ),
				'entry_id'  => 0,
				'record_id' => null === $record ? null : (int) $record['id'],
				'values'    => null === $record ? ( $payload ?? array() ) : self::form_values( (string) ( $trigger['form'] ?? '' ), $record ),
				'origin'    => $origin,
			);
		}
		if ( null === $record ) {
			return 'event' === ( $trigger['type'] ?? '' ) ? ( $payload ?? array() ) + array( 'origin' => $origin ) : array();
		}
		if ( 'schedule' === ( $trigger['type'] ?? '' ) ) {
			return array();
		}

		$base = array(
			'id'   => (int) $record['id'],
			'type' => (string) $record['type'],
		);
		return match ( $event ) {
			'record.updated'        => $base + array(
				'status'  => (string) $record['status'],
				'changed' => array(),
				'old'     => (array) $record['values'] + array( 'status' => (string) $record['status'] ),
			),
			'record.status_changed' => $base + array(
				'old_status' => (string) $record['status'],
				'new_status' => (string) $record['status'],
			),
			default                 => $base + array( 'status' => (string) $record['status'] ),
		} + array(
			'user_id' => get_current_user_id(),
			'origin'  => $origin,
		);
	}

	/**
	 * The `values` a form event would carry for a record: the record's value of each bound field and
	 * taxonomy, under the form item's key.
	 *
	 * @param string               $form   Form key.
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>
	 */
	private static function form_values( string $form, array $record ): array {
		$values = array();
		foreach ( (array) ( pegwork_registry()->form( $form )['fields'] ?? array() ) as $item ) {
			$bound = $item['bind']['field'] ?? ( $item['bind']['taxonomy'] ?? null );
			if ( ! is_string( $bound ) ) {
				continue;
			}
			$values[ (string) ( $item['key'] ?? $bound ) ] = $record['values'][ $bound ] ?? null;
		}
		return $values;
	}

	/**
	 * The context of a preview or dry run: the engine clock, the sample record and the payload, no
	 * run, no automation key (so no link can be minted) and no step outputs.
	 *
	 * @param array<string, mixed>|null $record  Sample record.
	 * @param array<string, mixed>      $trigger Trigger payload.
	 */
	public static function context( ?array $record, array $trigger ): Context {
		return new Context( Engine::instance()->clock(), $record, $trigger );
	}

	/**
	 * Renders one template for the editor preview.
	 *
	 * @param string                    $template Template.
	 * @param bool                      $html     Whether it is an email HTML body.
	 * @param array<string, mixed>|null $record   Sample record.
	 * @param array<string, mixed>      $trigger  Trigger payload.
	 */
	public static function preview( string $template, bool $html, ?array $record, array $trigger ): string {
		return Templates::render( $template, self::context( $record, $trigger ), $html ? Templates::PREVIEW_HTML : Templates::PREVIEW );
	}

	/**
	 * The dry run report of a registry automation.
	 *
	 * @param string            $key       Automation key.
	 * @param int|null          $record_id Sample record ID.
	 * @param array<mixed>|null $payload   Sample payload.
	 * @return array<string, mixed>|WP_Error Report, or `pegwork_test_not_found` (404) or `pegwork_test_record` (400).
	 */
	public static function report( string $key, ?int $record_id, ?array $payload ): array|WP_Error {
		$definition = pegwork_registry()->automation( $key );
		if ( null === $definition ) {
			return new WP_Error(
				'pegwork_test_not_found',
				/* translators: %s: automation key. */
				sprintf( __( 'No automation with key "%s".', 'pegwork' ), $key ),
				array( 'status' => 404 )
			);
		}
		$trigger = (array) $definition['trigger'];
		$record  = self::sample_record( $trigger, $record_id );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$context = self::context( $record, self::payload( $trigger, $record, $payload ) );

		$report = array(
			'automation' => $key,
			'record'     => null === $record ? null : array(
				'id'    => (int) $record['id'],
				'type'  => (string) $record['type'],
				'title' => (string) get_the_title( (int) $record['id'] ),
			),
			'trigger'    => self::trigger_check( $trigger, $record, $context ),
			'steps'      => array(),
			'outcome'    => 'completed',
		);

		$plan  = new Plan( array_values( (array) $definition['steps'] ) );
		$id    = $plan->first();
		$count = 0;
		while ( null !== $id && $count < self::MAX_STEPS ) {
			++$count;
			$step  = (array) $plan->step( $id );
			$entry = self::step( $step, $context, $record, $plan );

			$report['steps'][] = $entry;
			if ( 'stops' === $entry['result'] ) {
				$report['outcome'] = 'stopped_at:' . $id;
				break;
			}
			if ( 'fails' === $entry['result'] ) {
				$report['outcome'] = 'failed_at:' . $id;
				break;
			}
			$id = 'decision' === ( $step['type'] ?? null ) ? $plan->after_branch( $id, $entry['detail']['index'] ) : $plan->next( $id );
		}
		if ( null !== $id && $count >= self::MAX_STEPS && 'completed' === $report['outcome'] ) {
			$report['outcome'] = 'step_limit';
		}

		return $report;
	}

	/**
	 * The report plus its text `lines`: the body of `POST .../automation-editor/test` and the output of
	 * `wp pegwork automation test --format=json`.
	 *
	 * @param string            $key       Automation key.
	 * @param int|null          $record_id Sample record ID.
	 * @param array<mixed>|null $payload   Sample payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function result( string $key, ?int $record_id, ?array $payload ): array|WP_Error {
		$report = self::report( $key, $record_id, $payload );
		return is_wp_error( $report ) ? $report : $report + array( 'lines' => self::lines( $report ) );
	}

	/**
	 * Whether a real event or sweep would start a run for the sample.
	 *
	 * @param array<string, mixed>      $trigger Trigger.
	 * @param array<string, mixed>|null $record  Sample record.
	 * @param Context                   $context Context.
	 * @return array{matches: bool, message: string}
	 */
	private static function trigger_check( array $trigger, ?array $record, Context $context ): array {
		$matches = true;
		if ( 'event' === ( $trigger['type'] ?? '' ) && isset( $trigger['when'] ) ) {
			$matches = Conditions::evaluate( (array) $trigger['when'], $context );
		}
		if ( 'schedule' === ( $trigger['type'] ?? '' ) && isset( $trigger['for_each'] ) && null !== $record ) {
			$for_each = (array) $trigger['for_each'];
			$matches  = in_array( (string) $record['status'], (array) ( $for_each['status'] ?? array( 'publish' ) ), true )
				&& ( ! isset( $for_each['where'] ) || Conditions::evaluate( (array) $for_each['where'], $context ) );
		}

		if ( $matches ) {
			$message = null === $record
				? __( 'A real trigger would start a run.', 'pegwork' )
				: __( 'A real trigger would start a run for this record.', 'pegwork' );
		} else {
			$message = null === $record
				? __( 'A real trigger would not start a run. The steps are tested anyway.', 'pegwork' )
				: __( 'A real trigger would not start a run for this record. The steps are tested anyway.', 'pegwork' );
		}
		return array(
			'matches' => $matches,
			'message' => $message,
		);
	}

	/**
	 * What one step would do.
	 *
	 * @param array<string, mixed>      $step    Step.
	 * @param Context                   $context Context.
	 * @param array<string, mixed>|null $record  Sample record.
	 * @param Plan                      $plan    Plan.
	 * @return array{id: string, type: string, depth: int, branch: string|null, result: string, detail: array<string, mixed>, message: string}
	 */
	private static function step( array $step, Context $context, ?array $record, Plan $plan ): array {
		$id       = (string) $step['id'];
		$type     = (string) $step['type'];
		$location = $plan->location( $id );
		$entry    = array(
			'id'      => $id,
			'type'    => $type,
			'depth'   => (int) $plan->depth( $id ),
			'branch'  => null !== $location && $location['first'] ? $location['label'] : null,
			'result'  => 'ok',
			'detail'  => array(),
			'message' => '',
		);

		switch ( $type ) {
			case 'decision':
				$result = ( new Decision() )->run( $step, $context, array() );
				$label  = $result->summary['branch'] ?? null;

				$entry['detail']  = array(
					'branch' => $label,
					'index'  => $result->branch,
				);
				$entry['message'] = null === $label
					/* translators: %s: step ID. */
					? sprintf( __( 'Decision %s: no branch matches; continues after the decision.', 'pegwork' ), $id )
					: ( 'otherwise' === $label && -1 === $result->branch
						/* translators: %s: step ID. */
						? sprintf( __( 'Decision %s: takes Otherwise.', 'pegwork' ), $id )
						/* translators: 1: step ID, 2: branch label. */
						: sprintf( __( 'Decision %1$s: takes "%2$s".', 'pegwork' ), $id, (string) $label ) );
				break;

			case 'condition':
				$passes           = Conditions::evaluate( (array) ( $step['when'] ?? array() ), $context );
				$entry['result']  = $passes ? 'ok' : 'stops';
				$entry['detail']  = array( 'passes' => $passes );
				$entry['message'] = $passes
					/* translators: %s: step ID. */
					? sprintf( __( 'Condition %s holds; continues.', 'pegwork' ), $id )
					/* translators: %s: step ID. */
					: sprintf( __( 'Condition %s is false: stops here.', 'pegwork' ), $id );
				break;

			case 'wait':
				$entry['result']  = 'waits';
				$entry['detail']  = array( 'for' => (string) ( $step['for'] ?? '' ) );
				$entry['message'] = sprintf(
					/* translators: 1: step ID, 2: ISO 8601 duration. */
					__( 'Wait %1$s: waits %2$s. The test continues as if it had passed, against the record as it is now.', 'pegwork' ),
					$id,
					(string) ( $step['for'] ?? '' )
				);
				break;

			case 'send_email':
				$entry = self::email( $step, $context ) + $entry;
				break;

			case 'update_record':
			case 'create_record':
				$values = array();
				foreach ( (array) ( $step['values'] ?? array() ) as $key => $value ) {
					$values[ (string) $key ] = is_string( $value ) ? Templates::render( $value, $context, Templates::PREVIEW ) : $value;
				}
				if ( 'update_record' === $type ) {
					$target          = isset( $step['record'] ) ? Templates::render( (string) $step['record'], $context, Templates::PREVIEW ) : (string) ( $record['id'] ?? '' );
					$entry['detail'] = array(
						'record' => $target,
						'values' => $values,
					);
					/* translators: 1: step ID, 2: record ID, 3: field keys. */
					$entry['message'] = sprintf( __( 'Update record %1$s: would write %3$s to record %2$s (not applied).', 'pegwork' ), $id, '' === $target ? '?' : $target, implode( ', ', array_keys( $values ) ) );
				} else {
					$entry['detail'] = array(
						'content_type' => (string) ( $step['content_type'] ?? '' ),
						'status'       => (string) ( $step['status'] ?? 'draft' ),
						'values'       => $values,
					);
					/* translators: 1: step ID, 2: content type key, 3: status, 4: field keys. */
					$entry['message'] = sprintf( __( 'Create record %1$s: would create a %3$s %2$s with %4$s (not applied).', 'pegwork' ), $id, $entry['detail']['content_type'], $entry['detail']['status'], implode( ', ', array_keys( $values ) ) );
				}
				break;

			case 'call_webhook':
				$url    = trim( Templates::render( (string) ( $step['url'] ?? '' ), $context, Templates::PREVIEW ) );
				$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
				$fields = array_keys( (array) ( CallWebhook::record( $step, $context )['fields'] ?? array() ) );

				$entry['detail']  = array(
					'host'   => $host,
					'fields' => $fields,
				);
				$entry['message'] = sprintf(
					/* translators: 1: step ID, 2: host name, 3: field keys. */
					__( 'Call webhook %1$s: would POST to %2$s with %3$s (not sent).', 'pegwork' ),
					$id,
					'' === $host ? '?' : $host,
					array() === $fields ? __( 'no record fields', 'pegwork' ) : implode( ', ', $fields )
				);
				break;

			default:
				$entry['result'] = 'fails';
				/* translators: 1: step ID, 2: step type. */
				$entry['message'] = sprintf( __( 'Step %1$s: type "%2$s" is not supported.', 'pegwork' ), $id, $type );
		}

		return $entry;
	}

	/**
	 * What an email step would send: recipients masked as in the log, subject, text and HTML rendered.
	 *
	 * @param array<string, mixed> $step    Step.
	 * @param Context              $context Context.
	 * @return array{result: string, detail: array<string, mixed>, message: string}
	 */
	private static function email( array $step, Context $context ): array {
		$id      = (string) $step['id'];
		$subject = trim( (string) preg_replace( '/\s+/', ' ', Templates::render( (string) ( $step['subject'] ?? '' ), $context, Templates::PREVIEW ) ) );
		$detail  = array(
			'to'      => array(),
			'subject' => $subject,
			'text'    => Templates::render( (string) ( $step['text'] ?? '' ), $context, Templates::PREVIEW ),
		);
		if ( isset( $step['html'] ) ) {
			$detail['html'] = Templates::render( (string) $step['html'], $context, Templates::PREVIEW_HTML );
		}
		if ( isset( $step['reply_to'] ) ) {
			$detail['reply_to'] = Templates::render( (string) $step['reply_to'], $context, Templates::PREVIEW );
		}

		$template = (string) ( $step['to'] ?? '' );
		if ( preg_match( '/\{\{\s*steps\./', $template ) ) {
			// A recipient from an earlier step's output is known only in a real run.
			$detail['to'] = array( Templates::render( $template, $context, Templates::PREVIEW ) );
		} else {
			$to = ( new SendEmail() )->recipients( $template, $context );
			if ( $to instanceof WP_Error ) {
				return array(
					'result'  => 'fails',
					'detail'  => $detail,
					/* translators: 1: step ID, 2: error message. */
					'message' => sprintf( __( 'Send email %1$s: would fail: %2$s', 'pegwork' ), $id, $to->get_error_message() ),
				);
			}
			$detail['to'] = array_map( array( SendEmail::class, 'mask' ), $to );
		}

		return array(
			'result'  => 'ok',
			'detail'  => $detail,
			/* translators: 1: step ID, 2: recipients, 3: subject. */
			'message' => sprintf( __( 'Send email %1$s: to %2$s, subject "%3$s" (not sent).', 'pegwork' ), $id, implode( ', ', $detail['to'] ), $subject ),
		);
	}

	/**
	 * The report as text lines: the trigger check, then one line per step indented by depth, with a
	 * heading line where a branch starts. CLI output and the REST `lines`.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return string[]
	 */
	public static function lines( array $report ): array {
		$lines = array( (string) $report['trigger']['message'] );
		foreach ( (array) $report['steps'] as $entry ) {
			$indent = str_repeat( '  ', (int) $entry['depth'] );
			if ( null !== $entry['branch'] ) {
				$lines[] = substr( $indent, 2 ) . ( 'otherwise' === $entry['branch']
					? __( 'Otherwise:', 'pegwork' )
					/* translators: %s: branch label. */
					: sprintf( __( 'If %s:', 'pegwork' ), (string) $entry['branch'] ) );
			}
			$lines[] = $indent . (string) $entry['message'];
		}
		$lines[] = match ( true ) {
			'completed' === $report['outcome']                     => __( 'The run would complete.', 'pegwork' ),
			str_starts_with( (string) $report['outcome'], 'stopped_at:' ) => __( 'The run would stop there.', 'pegwork' ),
			'step_limit' === $report['outcome']                    => __( 'The run would hit the step limit.', 'pegwork' ),
			default                                                => __( 'The run would fail there.', 'pegwork' ),
		};
		return $lines;
	}
}
