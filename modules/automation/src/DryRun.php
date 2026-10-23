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
use PegworkAutomation\Steps\FindRecords;
use PegworkAutomation\Steps\GetRecord;
use PegworkAutomation\Steps\SendEmail;
use WP_Error;

/**
 * Walks an automation's steps with the same Plan the executor uses, and reports what each step would
 * do. No step with a side effect runs: no email, no HTTP, no record write or link, no run row, no signed
 * link and no secret or link key. Decisions and conditions are evaluated; a false condition stops the
 * walk ("stops here"); waits continue as if they had passed, against the record as it is now.
 *
 * Reads (`get_record`, `find_records`) run for real through their handlers (read-only queries, the same
 * read limit), so later steps see their real outputs; the report names found records by ID and title
 * and gives counts and sums. Reads after a step that would write see the site as it is now, and the
 * report says so once. A step with `on_error: continue` that would fail is reported and the walk goes on.
 *
 * Loops iterate every item of the real find, with the executor's loop state in the context, so item paths
 * read real records. The report shows the first SHOWN_ITEMS items in full, each under an `item` heading,
 * then "and 9 more items, same steps" (a later item's failure is still shown). A false condition in a loop
 * body skips that item (`skips`), as in a real run.
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
	 * Items of a loop the report shows in full; later items are walked but summed up in one line (their
	 * failures are still shown).
	 */
	public const SHOWN_ITEMS = 3;

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

		$plan = new Plan( array_values( (array) $definition['steps'] ) );
		$context->use_plan( $plan );
		$id     = $plan->first();
		$count  = 0;
		$errors = array();
		$state  = array(
			'wrote' => false,
			'noted' => false,
		);
		$walk   = array(
			'item' => null, // Heading of the item about to start, for the next entry shown.
		);
		while ( null !== $id && $count < self::MAX_STEPS ) {
			$step = (array) $plan->step( $id );
			if ( $plan->is_loop( $id ) ) {
				if ( null === $context->loop( $id ) ) {
					++$count; // Starting a loop is an attempt, advancing it is not (as in the executor).
				}
				$id = self::loop( $step, $context, $plan, $report, $walk );
				if ( str_starts_with( $report['outcome'], 'failed_at:' ) ) {
					break;
				}
				continue;
			}
			++$count;
			$entry = self::step( $step, $context, $record, $plan, $state );
			$loop  = $plan->loop_of( $id );
			$item  = $context->iteration( $id );

			if ( 'fails' === $entry['result'] && 'continue' === ( $step['on_error'] ?? null ) ) {
				$entry['result']   = 'continues';
				$entry['message'] .= ' ' . __( 'The run would continue (on_error).', 'pegwork' );
				$context->set_output(
					$id,
					array(
						'failed' => true,
						'error'  => (string) ( $entry['detail']['error'] ?? 'failed' ),
					)
				);
				$errors[] = $id;
			}
			if ( 'stops' === $entry['result'] && null !== $loop ) {
				$entry['result']  = 'skips';
				$entry['message'] = sprintf(
					/* translators: %s: step ID. */
					__( 'Condition %s is false: skips this item.', 'pegwork' ),
					$id
				);
			}

			// Later loop items are walked but not listed, unless something fails there.
			if ( null === $item || $item <= self::SHOWN_ITEMS || in_array( $entry['result'], array( 'fails', 'continues' ), true ) ) {
				$entry['item']     = $walk['item'];
				$walk['item']      = null;
				$report['steps'][] = $entry;
			}
			if ( 'skips' === $entry['result'] ) {
				$id = $loop;
				continue;
			}
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
		if ( array() !== $errors && ( 'completed' === $report['outcome'] || str_starts_with( $report['outcome'], 'stopped_at:' ) ) ) {
			$report['outcome'] = Executor::ERRORS_OUTCOME . implode( ',', array_values( array_unique( $errors ) ) ); // As the executor reports it.
		}

		return $report;
	}

	/**
	 * Walks a loop step at the cursor: starts it (a report line with the item count; the IDs count toward
	 * the read limit) or advances it, then enters the next item that can still be read, or ends the loop
	 * ("and 9 more items, same steps" when items were not shown in full).
	 *
	 * @param array<string, mixed> $step    Loop step.
	 * @param Context              $context Context.
	 * @param Plan                 $plan    Plan.
	 * @param array<string, mixed> $report  Report (entries and outcome are added).
	 * @param array<string, mixed> $walk    Walk state: `item`, the heading for the next entry.
	 * @return string|null The step to continue at.
	 */
	private static function loop( array $step, Context $context, Plan $plan, array &$report, array &$walk ): ?string {
		$id    = (string) $step['id'];
		$depth = (int) $plan->depth( $id );
		$over  = (string) $plan->over( $id );
		$type  = (string) ( $plan->step( $over )['content_type'] ?? '' );
		$entry = fn( string $result, array $detail, string $message, int $at ) => array(
			'id'      => $id,
			'type'    => 'loop',
			'depth'   => $at,
			'branch'  => null,
			'result'  => $result,
			'detail'  => $detail,
			'message' => $message,
			'item'    => null,
		);

		if ( null === $context->loop( $id ) ) {
			$ids = $context->collection( (string) ( $step['over'] ?? '' ) ) ?? array();
			if ( ! $context->count_reads( count( $ids ) ) ) {
				$report['steps'][] = $entry(
					'fails',
					array( 'error' => 'read_limit' ),
					/* translators: 1: step ID, 2: error message. */
					sprintf( __( 'Loop %1$s: would fail: %2$s', 'pegwork' ), $id, (string) Steps\Reads::over_limit( count( $ids ), $context, array() )->error ),
					$depth
				);
				$report['outcome'] = 'failed_at:' . $id;
				return null;
			}
			$location = $plan->location( $id );
			$plural   = 1 !== count( $ids );
			$start    = $entry(
				'ok',
				array( 'items' => count( $ids ) ),
				sprintf(
					/* translators: 1: step ID, 2: number of items, 3: content type label (singular for 1). */
					__( 'Loop %1$s: repeats its steps for %2$d %3$s.', 'pegwork' ),
					$id,
					count( $ids ),
					self::type_label( $type, $plural )
				),
				$depth
			);
			$start['branch']   = null !== $location && $location['first'] ? $location['label'] : null;
			$start['item']     = $walk['item'];
			$walk['item']      = null;
			$report['steps'][] = $start;
			if ( array() === $ids ) {
				$context->end_loop( $id );
				return $plan->next( $id );
			}
			$context->start_loop( $id, $ids );
		} else {
			$context->advance_loop( $id ); // Past the last item there is no current item, and the loop ends below.
		}

		$state = (array) $context->loop( $id );
		$total = count( (array) ( $state['ids'] ?? array() ) );
		$body  = $plan->body_first( $id );
		$item  = $context->loop_item( $id );
		while ( null !== $body && null !== $item ) {
			$index = (int) $context->loop( $id )['index'] + 1;
			if ( null !== $context->record_by_id( $item ) ) {
				$walk['item'] = array(
					'index' => $index,
					'count' => $total,
					'id'    => $item,
					'title' => self::title( $item ),
					'label' => self::type_label( $type, false ),
				);
				return $body;
			}
			if ( $index <= self::SHOWN_ITEMS ) {
				$report['steps'][] = $entry(
					'ok',
					array(
						'item'         => $item,
						'skipped_item' => true,
					),
					/* translators: 1: item number, 2: number of items, 3: record ID. */
					sprintf( __( 'Item %1$d of %2$d (record %3$d) can no longer be read: skipped.', 'pegwork' ), $index, $total, $item ),
					$depth + 1
				);
			}
			if ( ! $context->advance_loop( $id ) ) {
				break;
			}
		}

		$walk['item'] = null; // A later item's heading never moves to the step after the loop.
		$context->end_loop( $id );
		if ( $total > self::SHOWN_ITEMS ) {
			$more              = $total - self::SHOWN_ITEMS;
			$report['steps'][] = $entry(
				'ok',
				array( 'more' => $more ),
				/* translators: %d: number of further loop items. */
				sprintf( _n( 'and %d more item, same steps.', 'and %d more items, same steps.', $more, 'pegwork' ), $more ),
				$depth + 1
			);
		}
		return $plan->next( $id );
	}

	/**
	 * A content type's label, plural or singular (its key when the type is unknown).
	 *
	 * @param string $type   Content type key.
	 * @param bool   $plural Whether the plural is wanted.
	 */
	private static function type_label( string $type, bool $plural ): string {
		return (string) ( pegwork_registry()->content_type( $type )['labels'][ $plural ? 'plural' : 'singular' ] ?? $type );
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
	 * @param array<string, bool>       $state   `wrote` (a step would have written), `noted` (the report said so).
	 * @return array{id: string, type: string, depth: int, branch: string|null, result: string, detail: array<string, mixed>, message: string, item: array<string, mixed>|null}
	 */
	private static function step( array $step, Context $context, ?array $record, Plan $plan, array &$state ): array {
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
			'item'    => null, // The loop item this entry starts: index, count, id, title, label.
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
				try {
					$entry = self::email( $step, $context ) + $entry;
				} catch ( TemplateTooLarge $e ) {
					$entry['result']  = 'fails';
					$entry['detail']  = array( 'error' => 'template_too_large' );
					$entry['message'] = sprintf(
						/* translators: 1: step ID, 2: error message. */
						__( 'Send email %1$s: would fail: %2$s', 'pegwork' ),
						$id,
						$e->getMessage()
					);
				}
				break;

			case 'get_record':
			case 'find_records':
				$entry = self::read( $step, $context, $state ) + $entry;
				break;

			case 'relate':
				$state['wrote'] = true;
				$relationship   = pegwork_registry()->relationships()[ (string) $step['relationship'] ] ?? array();
				$bound          = null !== $record && ( $relationship['from'] ?? null ) === $record['type'] ? (string) $record['id'] : '?';
				$from           = isset( $step['from'] ) ? Templates::render( (string) $step['from'], $context, Templates::PREVIEW ) : $bound;
				$to             = Templates::render( (string) ( $step['to'] ?? '' ), $context, Templates::PREVIEW );
				$action         = 'remove' === ( $step['action'] ?? 'add' ) ? 'remove' : 'add';

				$entry['detail']  = array(
					'relationship' => (string) $step['relationship'],
					'action'       => $action,
					'from'         => $from,
					'to'           => $to,
				);
				$entry['message'] = 'add' === $action
					/* translators: 1: step ID, 2: relationship key, 3: "from" record ID, 4: "to" record ID. */
					? sprintf( __( 'Relate %1$s: would add a %2$s link from record %3$s to record %4$s (not applied).', 'pegwork' ), $id, (string) $step['relationship'], '' === $from ? '?' : $from, '' === $to ? '?' : $to )
					/* translators: 1: step ID, 2: relationship key, 3: "from" record ID, 4: "to" record ID. */
					: sprintf( __( 'Relate %1$s: would remove the %2$s link from record %3$s to record %4$s (not applied).', 'pegwork' ), $id, (string) $step['relationship'], '' === $from ? '?' : $from, '' === $to ? '?' : $to );
				break;

			case 'update_record':
			case 'create_record':
				$state['wrote'] = true;
				$values         = array();
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
				$url     = trim( Templates::render( (string) ( $step['url'] ?? '' ), $context, Templates::PREVIEW ) );
				$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
				$pending = isset( $step['record'] ) ? self::pending( (string) $step['record'], $context ) : null;
				if ( null !== $pending ) {
					$entry['detail']  = array(
						'host'   => $host,
						'fields' => array(),
					);
					$entry['message'] = sprintf(
						/* translators: 1: step ID, 2: host name, 3: step ID. */
						__( 'Call webhook %1$s: would POST to %2$s with the record from step %3$s, known only in a real run (not sent).', 'pegwork' ),
						$id,
						'' === $host ? '?' : $host,
						$pending
					);
					break;
				}
				$target = CallWebhook::target( $step, $context );
				if ( $target instanceof WP_Error ) {
					$entry['result']  = 'fails';
					$entry['detail']  = array(
						'host'  => $host,
						'error' => (string) $target->get_error_code(),
					);
					$entry['message'] = sprintf(
						/* translators: 1: step ID, 2: error message. */
						__( 'Call webhook %1$s: would fail: %2$s', 'pegwork' ),
						$id,
						$target->get_error_message()
					);
					break;
				}
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

			default: // Loops are walked by report() itself.
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
		if ( null !== self::pending( $template, $context ) ) {
			// A recipient from the output of a step the test does not run is known only in a real run.
			$detail['to'] = array( Templates::render( $template, $context, Templates::PREVIEW ) );
		} else {
			$to = ( new SendEmail() )->recipients( $template, $context );
			if ( $to instanceof WP_Error ) {
				return array(
					'result'  => 'fails',
					'detail'  => $detail + array( 'error' => (string) $to->get_error_code() ),
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
			$item   = $entry['item'] ?? null;
			if ( is_array( $item ) ) {
				$lines[] = substr( $indent, 2 ) . sprintf(
					/* translators: 1: item number, 2: number of items, 3: content type label, 4: record ID, 5: record title. */
					__( 'Item %1$d of %2$d: %3$s %4$d "%5$s":', 'pegwork' ),
					(int) $item['index'],
					(int) $item['count'],
					(string) $item['label'],
					(int) $item['id'],
					(string) $item['title']
				);
			}
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
			str_starts_with( (string) $report['outcome'], Executor::ERRORS_OUTCOME ) => sprintf(
				/* translators: %s: comma-separated step IDs. */
				__( 'The run would complete with errors in %s.', 'pegwork' ),
				str_replace( ',', ', ', substr( (string) $report['outcome'], strlen( Executor::ERRORS_OUTCOME ) ) )
			),
			default                                                => __( 'The run would fail there.', 'pegwork' ),
		};
		return $lines;
	}

	/**
	 * The first step a template reads (`{{steps.<id>...}}`) that has no output in this test, or null.
	 *
	 * @param string  $template Template.
	 * @param Context $context  Context.
	 */
	private static function pending( string $template, Context $context ): ?string {
		preg_match_all( '/\{\{\s*steps\.([a-z0-9_]+)/', $template, $m );
		foreach ( $m[1] as $step ) {
			if ( ! $context->has_output( $step ) ) {
				return $step;
			}
		}
		return null;
	}

	/**
	 * What a read step finds: it runs for real (read-only), its outputs are stored for later steps, and the
	 * report names the records by ID and title.
	 *
	 * @param array<string, mixed> $step    Step.
	 * @param Context              $context Context.
	 * @param array<string, bool>  $state   Walk state (see step()).
	 * @return array{result: string, detail: array<string, mixed>, message: string}
	 */
	private static function read( array $step, Context $context, array &$state ): array {
		$id     = (string) $step['id'];
		$type   = (string) $step['content_type'];
		$get    = 'get_record' === $step['type'];
		$result = ( $get ? new GetRecord() : new FindRecords() )->run( $step, $context, array() );
		$label  = (string) ( pegwork_registry()->content_type( $type )['labels'][ $get ? 'singular' : 'plural' ] ?? $type );

		if ( 'failed' === $result->status ) {
			$error = (string) $result->error;
			return array(
				'result'  => 'fails',
				'detail'  => array(
					'content_type' => $type,
					'error'        => (string) strtok( $error, ':' ),
				),
				/* translators: 1: step ID, 2: error message. */
				'message' => sprintf( $get ? __( 'Get record %1$s: would fail: %2$s', 'pegwork' ) : __( 'Find records %1$s: would fail: %2$s', 'pegwork' ), $id, $error ),
			);
		}
		$context->set_output( $id, $result->output + ( 'continue' === ( $step['on_error'] ?? null ) ? array( 'failed' => false ) : array() ) );

		$note = '';
		if ( $state['wrote'] && ! $state['noted'] ) {
			$state['noted'] = true;
			$note           = ' ' . __( 'Reads in a test see the site as it is now: the writes before them are not applied.', 'pegwork' );
		}

		if ( $get ) {
			$record_id = (int) ( $result->output['record_id'] ?? 0 );
			$detail    = array(
				'content_type' => $type,
				'found'        => $record_id > 0,
				'record_id'    => $record_id > 0 ? $record_id : null,
				'title'        => $record_id > 0 ? self::title( $record_id ) : null,
			);
			$pending   = isset( $step['record'] ) ? self::pending( (string) $step['record'], $context ) : null;
			if ( $record_id > 0 ) {
				/* translators: 1: step ID, 2: content type label, 3: record ID, 4: record title. */
				$message = sprintf( __( 'Get record %1$s: found %2$s %3$d "%4$s".', 'pegwork' ), $id, $label, $record_id, $detail['title'] );
			} elseif ( null !== $pending ) {
				/* translators: 1: step ID, 2: step ID. */
				$message = sprintf( __( 'Get record %1$s: not found in a test run (step %2$s would create or name it).', 'pegwork' ), $id, $pending );
			} else {
				/* translators: 1: step ID, 2: content type label. */
				$message = sprintf( __( 'Get record %1$s: no %2$s found.', 'pegwork' ), $id, $label );
			}
			return array(
				'result'  => 'ok',
				'detail'  => $detail,
				'message' => $message . $note,
			);
		}

		$ids     = (array) $result->output['ids'];
		$records = array_map(
			fn( $record_id ) => array(
				'id'    => (int) $record_id,
				'title' => self::title( (int) $record_id ),
			),
			$ids
		);
		$shown   = array_map( fn( $item ) => sprintf( '%d "%s"', $item['id'], $item['title'] ), array_slice( $records, 0, 3 ) );
		if ( count( $records ) > 3 ) {
			/* translators: %d: number of further records. */
			$shown[] = sprintf( __( 'and %d more', 'pegwork' ), count( $records ) - 3 );
		}
		$sums = array();
		foreach ( (array) $result->output['sum'] as $key => $total ) {
			/* translators: 1: field key, 2: total. */
			$sums[] = sprintf( __( 'sum of %1$s %2$s', 'pegwork' ), (string) $key, (string) Templates::number( $total ) );
		}
		$message = sprintf(
			/* translators: 1: step ID, 2: number of matches, 3: content type label (singular for 1), 4: number returned. */
			__( 'Find records %1$s: %2$d %3$s found, %4$d returned', 'pegwork' ),
			$id,
			(int) $result->output['count'],
			1 === (int) $result->output['count'] ? (string) ( pegwork_registry()->content_type( $type )['labels']['singular'] ?? $type ) : $label,
			count( $ids )
		) . ( array() === $shown ? '' : ' (' . implode( ', ', $shown ) . ')' ) . ( array() === $sums ? '' : '; ' . implode( ', ', $sums ) ) . '.';

		return array(
			'result'  => 'ok',
			'detail'  => array(
				'content_type' => $type,
				'count'        => (int) $result->output['count'],
				'records'      => $records,
				'sum'          => (array) $result->output['sum'],
			),
			'message' => $message . $note,
		);
	}

	/**
	 * A record's title as plain text (without the "Private:" prefix of get_the_title()).
	 *
	 * @param int $id Record ID.
	 */
	private static function title( int $id ): string {
		return wp_specialchars_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES );
	}
}
