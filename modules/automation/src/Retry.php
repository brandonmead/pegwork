<?php
/**
 * Step retry policy.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

/**
 * How often a failed step is tried again and after how long. A step's `retry` (`max`, `backoff`: one
 * duration or a list, one per retry, the last repeating) wins over the defaults per type: `send_email`
 * 2 retries 15 minutes apart, `call_webhook` 3 retries after 1 minute, 10 minutes and 1 hour, others none.
 * Retries reuse the wait mechanism: the run is `waiting` at the same step until the backoff has passed.
 */
final class Retry {

	/**
	 * Default policy by step type.
	 */
	public const DEFAULTS = array(
		'send_email'   => array(
			'max'     => 2,
			'backoff' => array( 'PT15M' ),
		),
		'call_webhook' => array(
			'max'     => 3,
			'backoff' => array( 'PT1M', 'PT10M', 'PT1H' ),
		),
	);

	/**
	 * Backoff when a step sets `retry.max` without `backoff` and its type has no default.
	 */
	public const BACKOFF = 'PT15M';

	/**
	 * The policy of a step.
	 *
	 * @param array<string, mixed> $step Step definition.
	 * @return array{max: int, backoff: string[]}
	 */
	public static function policy( array $step ): array {
		$default = self::DEFAULTS[ (string) ( $step['type'] ?? '' ) ] ?? array(
			'max'     => 0,
			'backoff' => array( self::BACKOFF ),
		);
		if ( ! isset( $step['retry'] ) || ! is_array( $step['retry'] ) ) {
			return $default;
		}

		$backoff = $step['retry']['backoff'] ?? $default['backoff'];
		$backoff = array_values( array_filter( array_map( 'strval', (array) $backoff ), 'strlen' ) );
		return array(
			'max'     => max( 0, (int) ( $step['retry']['max'] ?? 0 ) ),
			'backoff' => array() === $backoff ? array( self::BACKOFF ) : $backoff,
		);
	}

	/**
	 * The wait before the next retry, or null when no retry is left.
	 *
	 * @param array<string, mixed> $step Step definition.
	 * @param int                  $done Retries already made for this step in this run.
	 * @return string|null ISO 8601 duration.
	 */
	public static function delay( array $step, int $done ): ?string {
		$policy = self::policy( $step );
		if ( $done >= $policy['max'] ) {
			return null;
		}
		return $policy['backoff'][ min( $done, count( $policy['backoff'] ) - 1 ) ];
	}
}
