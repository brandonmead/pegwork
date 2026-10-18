<?php
/**
 * Condition interpreter.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

use DateInterval;
use DateTimeImmutable;
use Exception;

/**
 * Evaluates a condition tree (validated at load time by the registry): groups `all`, `any`, `not`, and
 * leaves `{ var, op, value }`. Data only: no callables, no eval.
 *
 * Semantics:
 * - Lists (taxonomy slugs) compare as sets: `eq` against a scalar means the list is exactly that one value,
 *   `in`/`not_in` mean any/no overlap, `contains` means the list holds the value.
 * - Empty is null, '' or an empty list. Order operators are false when either side is empty.
 * - Numbers compare numerically, booleans as booleans, dates and datetimes as instants in site time,
 *   everything else as strings.
 */
final class Conditions {

	/**
	 * Whether the condition holds.
	 *
	 * @param array<string, mixed> $node    Condition node.
	 * @param Context              $context Run context.
	 */
	public static function evaluate( array $node, Context $context ): bool {
		if ( isset( $node['all'] ) ) {
			foreach ( (array) $node['all'] as $child ) {
				if ( ! self::evaluate( (array) $child, $context ) ) {
					return false;
				}
			}
			return true;
		}
		if ( isset( $node['any'] ) ) {
			foreach ( (array) $node['any'] as $child ) {
				if ( self::evaluate( (array) $child, $context ) ) {
					return true;
				}
			}
			return false;
		}
		if ( isset( $node['not'] ) ) {
			return ! self::evaluate( (array) $node['not'], $context );
		}
		if ( ! isset( $node['var'], $node['op'] ) ) {
			return false;
		}

		$path = (string) $node['var'];
		$left = $context->resolve( $path );

		if ( 'changed' === $node['op'] ) {
			$changed = $context->resolve( 'trigger.changed' );
			$key     = str_starts_with( $path, 'record.' ) ? substr( $path, 7 ) : $path;
			return is_array( $changed ) && in_array( $key, $changed, true );
		}

		$right = array_key_exists( 'value', $node ) ? self::operand( $node['value'], $context ) : null;
		return self::compare_op( (string) $node['op'], $left, $right );
	}

	/**
	 * Applies a leaf operator.
	 *
	 * @param string $op    Operator.
	 * @param mixed  $left  Resolved var.
	 * @param mixed  $right Resolved operand.
	 */
	public static function compare_op( string $op, mixed $left, mixed $right ): bool {
		switch ( $op ) {
			case 'empty':
				return self::is_empty( $left );
			case 'not_empty':
				return ! self::is_empty( $left );
			case 'eq':
				return self::equals( $left, $right );
			case 'ne':
				return ! self::equals( $left, $right );
			case 'in':
				return self::overlaps( $left, (array) $right );
			case 'not_in':
				return ! self::overlaps( $left, (array) $right );
			case 'contains':
				return is_array( $left ) && self::overlaps( $left, array( $right ) );
			case 'lt':
			case 'lte':
			case 'gt':
			case 'gte':
				$order = self::order( $left, $right );
				if ( null === $order ) {
					return false;
				}
				return match ( $op ) {
					'lt'  => $order < 0,
					'lte' => $order <= 0,
					'gt'  => $order > 0,
					'gte' => $order >= 0,
				};
		}
		return false;
	}

	/**
	 * Resolves an operand: a literal, `{ var }`, or `{ date, offset }`.
	 *
	 * @param mixed   $value   Operand.
	 * @param Context $context Run context.
	 * @return mixed
	 */
	public static function operand( mixed $value, Context $context ): mixed {
		if ( is_array( $value ) && isset( $value['var'] ) && is_string( $value['var'] ) ) {
			return $context->resolve( $value['var'] );
		}
		if ( is_array( $value ) && isset( $value['date'] ) && is_string( $value['date'] ) ) {
			$base = $context->clock()->now()->setTimezone( wp_timezone() );
			if ( 'today' === $value['date'] ) {
				$base = $base->setTime( 0, 0 );
			}
			$base = self::offset( $base, (string) ( $value['offset'] ?? '' ) );
			return 'today' === $value['date'] ? $base->format( 'Y-m-d' ) : $base->format( 'Y-m-d\TH:i:sP' );
		}
		return $value;
	}

	/**
	 * Applies a signed ISO 8601 duration, such as -P12M.
	 *
	 * @param DateTimeImmutable $time   Time.
	 * @param string            $offset Signed duration, or '' for none.
	 */
	public static function offset( DateTimeImmutable $time, string $offset ): DateTimeImmutable {
		if ( '' === $offset ) {
			return $time;
		}
		try {
			$interval = new DateInterval( ltrim( $offset, '+-' ) );
		} catch ( Exception $e ) {
			return $time;
		}
		return str_starts_with( $offset, '-' ) ? $time->sub( $interval ) : $time->add( $interval );
	}

	/**
	 * Whether a value is empty: null, '' or an empty list.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_empty( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value;
	}

	/**
	 * Equality, with lists as sets and scalars normalized.
	 *
	 * @param mixed $left  Left.
	 * @param mixed $right Right.
	 */
	private static function equals( mixed $left, mixed $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			$a = self::as_list( $left );
			$b = self::as_list( $right );
			sort( $a );
			sort( $b );
			return $a === $b;
		}
		if ( self::is_empty( $left ) || self::is_empty( $right ) ) {
			return self::is_empty( $left ) && self::is_empty( $right );
		}
		return 0 === self::order( $left, $right );
	}

	/**
	 * Whether a value (or any list item) is among the candidates.
	 *
	 * @param mixed $left       Value or list.
	 * @param array $candidates Candidates.
	 */
	private static function overlaps( mixed $left, array $candidates ): bool {
		foreach ( self::as_list( $left ) as $item ) {
			foreach ( $candidates as $candidate ) {
				if ( self::equals( $item, $candidate ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * A value as a list of strings: empty values give [], scalars one item.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	private static function as_list( mixed $value ): array {
		if ( self::is_empty( $value ) ) {
			return array();
		}
		$items = is_array( $value ) ? $value : array( $value );
		return array_values( array_map( fn( $item ) => is_bool( $item ) ? ( $item ? '1' : '0' ) : ( is_scalar( $item ) ? (string) $item : '' ), $items ) );
	}

	/**
	 * Orders two scalars: negative, zero or positive, or null when they cannot be ordered.
	 *
	 * @param mixed $left  Left.
	 * @param mixed $right Right.
	 */
	private static function order( mixed $left, mixed $right ): ?int {
		if ( self::is_empty( $left ) || self::is_empty( $right ) || ! is_scalar( $left ) || ! is_scalar( $right ) ) {
			return null;
		}
		if ( is_bool( $left ) || is_bool( $right ) ) {
			return (int) rest_sanitize_boolean( $left ) <=> (int) rest_sanitize_boolean( $right );
		}
		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return (float) $left <=> (float) $right;
		}
		$a = self::instant( (string) $left );
		$b = self::instant( (string) $right );
		if ( null !== $a && null !== $b ) {
			return $a <=> $b;
		}
		return strcmp( (string) $left, (string) $right ) <=> 0;
	}

	/**
	 * Unix time of a `Y-m-d` date (site midnight) or ISO datetime, or null for other strings.
	 *
	 * @param string $value Value.
	 */
	private static function instant( string $value ): ?int {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:?\d{2}|Z)?)?$/', $value ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( $value, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}
}
