<?php
/**
 * Shared query model of the read steps.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Steps;

use DateTimeImmutable;
use Pegwork\Api\Records;
use PegworkAutomation\Conditions;
use PegworkAutomation\Context;
use PegworkAutomation\Templates;
use WP_Error;

/**
 * Turns a `get_record` or `find_records` step's `related`, `filter`, `status`, `sort` and `created` into a
 * Records::find() query with resolved operands (`{ var }` and `{ date, offset }` resolve here; core knows
 * nothing of run paths), and builds the parts of a read's log summary. Reads are pure: they write nothing,
 * so retrying them is safe.
 */
final class Reads {

	/**
	 * Statuses a read sees by default.
	 */
	public const DEFAULT_STATUS = array( 'private', 'publish' );

	/**
	 * Most record IDs a summary lists.
	 */
	public const SUMMARY_IDS = 50;

	/**
	 * Statuses the step reads.
	 *
	 * @param array<string, mixed> $step Step.
	 * @return string[]
	 */
	public static function statuses( array $step ): array {
		return array_values( array_map( 'strval', (array) ( $step['status'] ?? self::DEFAULT_STATUS ) ) );
	}

	/**
	 * The Records::find() query of a step (without `limit`, `count` and `sum`).
	 *
	 * @param array<string, mixed> $step    Step.
	 * @param Context              $context Run context.
	 * @return array<string, mixed>
	 */
	public static function query( array $step, Context $context ): array {
		$query = array( 'status' => self::statuses( $step ) );
		if ( isset( $step['related'] ) ) {
			$query['related'] = array(
				'relationship' => (string) ( $step['related']['relationship'] ?? '' ),
				'of'           => (int) Templates::render( (string) ( $step['related']['of'] ?? '' ), $context ),
			);
		}
		if ( isset( $step['filter'] ) ) {
			$group           = isset( $step['filter']['any'] ) ? 'any' : 'all';
			$query['filter'] = array(
				$group => array_map(
					function ( $leaf ) use ( $context ) {
						$leaf = (array) $leaf;
						if ( array_key_exists( 'value', $leaf ) ) {
							$leaf['value'] = Conditions::operand( $leaf['value'], $context );
						}
						return $leaf;
					},
					array_values( (array) $step['filter'][ $group ] )
				),
			);
		}
		if ( isset( $step['sort'] ) ) {
			$query['sort'] = (array) $step['sort'];
		}
		if ( isset( $step['created'] ) && is_array( $step['created'] ) ) {
			$query['created'] = self::created( $step['created'], $context );
		}
		return $query;
	}

	/**
	 * Resolves a read's `created` bounds to the site-time `Y-m-d` days core's query takes: a literal day stays,
	 * `{ date, offset }` and `{ var }` resolve against the run (Conditions::operand()), and a resolved datetime
	 * becomes its day in the site time zone. An empty operand stays '' (core then matches nothing); a value
	 * that is not a date is passed on as is, so core refuses it (`read_invalid`).
	 *
	 * @param array<string, mixed> $created `{ before?, after? }`.
	 * @param Context              $context Run context.
	 * @return array<string, mixed>
	 */
	public static function created( array $created, Context $context ): array {
		$out = array();
		foreach ( array( 'before', 'after' ) as $bound ) {
			if ( ! array_key_exists( $bound, $created ) ) {
				continue;
			}
			$value = Conditions::operand( $created[ $bound ], $context );
			if ( null === $value || '' === $value ) {
				$out[ $bound ] = '';
				continue;
			}
			if ( is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value ) ) {
				$time  = date_create_immutable( $value, wp_timezone() );
				$value = $time instanceof DateTimeImmutable ? $time->setTimezone( wp_timezone() )->format( 'Y-m-d' ) : $value; // Not a date after all: core refuses it.
			}
			$out[ $bound ] = $value;
		}
		return $out;
	}

	/**
	 * The resolved `created` bounds of a query for a log summary or a test line, or null without any.
	 *
	 * @param array<string, mixed> $query Query (Reads::query()).
	 * @return array<string, string>|null
	 */
	public static function created_summary( array $query ): ?array {
		if ( ! isset( $query['created'] ) || ! is_array( $query['created'] ) ) {
			return null;
		}
		return array_map( fn( $value ) => is_scalar( $value ) ? (string) $value : '', $query['created'] );
	}

	/**
	 * Runs a query: Records::find(), or a failure result (`read_invalid`, no retry) for a query core refuses.
	 *
	 * @param string               $type  Content type.
	 * @param array<string, mixed> $query Query.
	 * @param array<string, mixed> $base  Summary so far.
	 * @return array{ids: int[], count: int|null, sums: array<string, float>, empty_operand: string|null}|StepResult
	 */
	public static function find( string $type, array $query, array $base ): array|StepResult {
		$found = Records::find( $type, $query );
		if ( $found instanceof WP_Error ) {
			return StepResult::fail( 'read_invalid', $found->get_error_message(), $base, false );
		}
		return $found;
	}

	/**
	 * The failure of a read that would pass the run's read limit.
	 *
	 * @param int                  $count   Records the read returned.
	 * @param Context              $context Run context.
	 * @param array<string, mixed> $summary Log summary.
	 */
	public static function over_limit( int $count, Context $context, array $summary ): StepResult {
		return StepResult::fail(
			'read_limit',
			sprintf( 'This read returned %1$d records; the run has read %2$d of at most %3$d.', $count, $context->reads(), Context::read_limit() ),
			$summary,
			false
		);
	}

	/**
	 * Why a query's `related.of` matches nothing: `related` when it rendered empty, for the summary.
	 *
	 * @param array<string, mixed> $query Query.
	 */
	public static function empty_related( array $query ): ?string {
		return isset( $query['related'] ) && $query['related']['of'] <= 0 ? 'related' : null;
	}
}
