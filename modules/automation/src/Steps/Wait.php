<?php
/**
 * `wait` step.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use DateTimeImmutable;
use DateTimeZone;
use PegworkAutomation\Context;
use PegworkAutomation\Duration;
use PegworkAutomation\WaitUntil;

/**
 * Pauses the run for `for` (ISO 8601, PT1H to P2Y). The executor stores `resume_at` and the next step,
 * sets the run `waiting` and schedules a wake-up; the claim only takes a waiting run once `resume_at` has
 * passed, and the daily maintenance (or `wp pegwork automation tick`) resumes it if the wake-up is lost.
 *
 * With `until` (Automation v4, docs/automation-v4-design.md section 3.2) the run waits until a date field of
 * its bound record, and the cursor stays on this step (StepResult `stay`), so every wake-up reads the field
 * again and decides again: a date in the future waits (at most WaitUntil::HORIZON per wait), a date now or
 * past continues, an empty date waits for a date (at most HORIZON from the first arrival, then the run stops
 * with `no_date`), and a record that is gone or in the trash stops the run (`no_record`). The first arrival
 * time and whether the date is empty live in the run context (`context.until.<id>`), removed when the step
 * completes. Waits::nudge() pulls the wake-up to now when the field or the record's status changes.
 */
final class Wait implements Step {

	/**
	 * Runs the step.
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $run     Run row.
	 */
	public function run( array $step, Context $context, array $run ): StepResult {
		if ( isset( $step['until'] ) && is_array( $step['until'] ) ) {
			return self::until( $step, $context );
		}
		$for    = (string) ( $step['for'] ?? '' );
		$resume = Duration::add( $context->clock()->now(), $for );
		if ( null === $resume ) {
			return StepResult::fail( 'invalid_duration', sprintf( 'The wait duration "%s" is not ISO 8601.', $for ), array(), false );
		}
		return StepResult::wait(
			$resume,
			array(
				'for'       => $for,
				'resume_at' => $resume->format( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Evaluates a date wait (on the first arrival and on every wake-up).
	 *
	 * @param array<string, mixed> $step    Step definition.
	 * @param Context              $context Run context.
	 */
	private static function until( array $step, Context $context ): StepResult {
		$id      = (string) $step['id'];
		$until   = (array) $step['until'];
		$var     = (string) ( $until['var'] ?? '' );
		$key     = WaitUntil::key( $var );
		$now     = $context->clock()->now();
		$summary = array( 'until' => WaitUntil::describe( $until, $key ) );
		$record  = $context->record();

		if ( null === $record || 'trash' === ( $record['status'] ?? null ) ) {
			$context->set_until( $id, null );
			return StepResult::stop( $summary + array( 'no_record' => true ) );
		}

		$state = $context->until( $id );
		$first = null === $state;
		$state = $state ?? array( 'since' => $now->format( 'Y-m-d H:i:s' ) );
		$value = $record['values'][ $key ] ?? null;

		$target = WaitUntil::target( $value, (string) ( $until['at'] ?? WaitUntil::AT_DEFAULT ), (string) ( $until['offset'] ?? '' ) );
		if ( null === $target ) {
			$since   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $state['since'], new DateTimeZone( 'UTC' ) );
			$horizon = WaitUntil::horizon( $since instanceof DateTimeImmutable ? $since : $now );
			if ( $now >= $horizon ) {
				$context->set_until( $id, null );
				return StepResult::stop( $summary + array( 'no_date' => true ) );
			}
			$context->set_until( $id, array( 'empty' => true ) + $state );
			return StepResult::wait(
				$horizon,
				$summary + array(
					'waiting_for_date' => true,
					'resume_at'        => $horizon->format( 'Y-m-d H:i:s' ),
				),
				true
			);
		}

		unset( $state['empty'] );
		$summary['date'] = $context->is_private( $var ) ? '[private]' : (string) $value;
		if ( $target <= $now ) {
			$context->set_until( $id, null );
			return StepResult::ok(
				array(),
				$summary + array(
					'due'  => true,
					'late' => $first,
				)
			);
		}

		$resume = WaitUntil::clamp( $target, $now );
		$context->set_until( $id, $state );
		return StepResult::wait( $resume, $summary + array( 'resume_at' => $resume->format( 'Y-m-d H:i:s' ) ), true );
	}
}
