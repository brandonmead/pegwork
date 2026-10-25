<?php
/**
 * Step `start_automation`.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use PegworkAutomation\Context;
use PegworkAutomation\Manual;
use PegworkAutomation\Templates;
use WP_Error;

/**
 * Starts another (`manual`) automation, asynchronously, through Manual::chain(): the child run is queued
 * and the parent continues. Output `started`: false when the target's `when` is false for the record or the
 * depth cap logged a skipped run instead; neither is a failure. An unknown, non-manual or disabled target and
 * a missing, trashed or other-type record fail the step without retry (`on_error` applies), as does a start
 * past the per-run limit (`start_limit`, `context.starts`). Summary `{ automation, record_id, started, run,
 * skipped }`; the child's outputs never come back.
 *
 * `record` is a template giving one record ID; without it the bound record is used when the target has a
 * content type (the registry checks the types), and no record when it has none.
 */
final class StartAutomation implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		$key        = (string) $step['automation'];
		$target     = pegwork_registry()->automation( $key );
		$has_record = isset( $target['trigger']['content_type'] );
		$record     = null;
		if ( $has_record ) {
			$record = isset( $step['record'] ) ? (int) trim( Templates::render( (string) $step['record'], $context ) ) : (int) ( $context->record()['id'] ?? 0 );
		}
		$summary = array(
			'automation' => $key,
			'record_id'  => $record,
		);

		if ( $context->starts() >= Context::start_limit() ) {
			return StepResult::fail( 'start_limit', sprintf( 'This run already started %d automations, the most one run may start.', Context::start_limit() ), $summary, false );
		}

		$step_id = (string) $step['id'];
		$result  = Manual::chain( $key, $record, $run, $step_id, $context->iteration( $step_id ) );
		if ( $result instanceof WP_Error ) {
			return StepResult::fail( (string) $result->get_error_code(), $result->get_error_message(), $summary, false );
		}
		if ( $result['run'] > 0 ) {
			// A duplicate is the child of an earlier attempt of this step that ended before it returned (a
			// retry or Retry from the log), so that attempt counted nothing.
			$context->count_start();
		}

		$summary += array(
			'started' => $result['started'],
			'run'     => $result['run'] > 0 ? $result['run'] : null,
			'skipped' => $result['skipped'] ?? ( $result['started'] ? null : 'not_applicable' ),
		);
		return StepResult::ok( array( 'started' => $result['started'] ), array_filter( $summary, fn( $value ) => null !== $value ) );
	}
}
