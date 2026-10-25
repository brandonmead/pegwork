<?php
/**
 * Finds records of a registry content type, with SQL counts and sums over every match.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Content;

use DateTimeImmutable;
use Exception;
use Pegwork\Api\Registry;
use WP_Error;
use WP_Post;

/**
 * The read query model of `Pegwork\Api\Records::find()` (docs/automation-v3-design.md section 4.1).
 *
 * One WHERE clause (type, status, relationship, filter, created) drives the ID list, the count and the sums,
 * so totals cover every match, never a capped list. Filter leaves are EXISTS subqueries on post meta
 * and term relationships, or comparisons on `ID` and `post_title`, so `all` and `any` groups mix
 * them freely and no join duplicates a row. Relationship storage stays behind
 * Relationships::sql(), the SQL counterpart of Relationships::query_args(). Operand values are resolved
 * literals: core knows nothing of run paths.
 */
final class RecordQuery {

	/**
	 * Query properties (`created` since API 0.4.0).
	 */
	public const PROPERTIES = array( 'related', 'filter', 'status', 'sort', 'limit', 'count', 'sum', 'created' );

	/**
	 * Bounds of the `created` property.
	 */
	public const CREATED_BOUNDS = array( 'before', 'after' );

	/**
	 * Most records returned.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Records returned when `limit` is not given.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Most leaves in a filter group.
	 */
	public const MAX_LEAVES = 10;

	/**
	 * Most summed fields.
	 */
	public const MAX_SUMS = 5;

	/**
	 * Statuses read when `status` is not given (BarnCRM records are private posts).
	 */
	public const DEFAULT_STATUS = array( 'private', 'publish' );

	/**
	 * Statuses a query may read. Trash and auto-drafts are never read.
	 */
	public const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Filter operators. `changed` is a trigger notion and has no place in a read.
	 */
	public const OPS = array( 'eq', 'ne', 'in', 'not_in', 'lt', 'lte', 'gt', 'gte', 'empty', 'not_empty', 'contains' );

	/**
	 * Field types that cannot be filtered (long text; post_content storage is excluded too).
	 */
	public const UNFILTERABLE_TYPES = array( 'rich_text', 'textarea' );

	/**
	 * Field types that can be sorted on (and title-storage fields).
	 */
	public const SORTABLE_TYPES = array( 'text', 'number', 'date', 'select', 'email' );

	/**
	 * SQL type numbers are compared and summed as.
	 */
	private const DECIMAL = 'DECIMAL(20,4)';

	/**
	 * Runs a query.
	 *
	 * @param Registry             $registry Effective registry.
	 * @param string               $type     Content type key of the records read.
	 * @param array<string, mixed> $query    `related` { relationship, of }, `filter` { all|any: [ { field, op, value } ] },
	 *                                       `status`, `sort` { field, order }, `limit`, `count` (bool), `sum` (field keys),
	 *                                       `created` { before, after }.
	 * @return array{ids: int[], count: int|null, sums: array<string, float>, empty_operand: string|null}|WP_Error
	 */
	public static function run( Registry $registry, string $type, array $query ): array|WP_Error {
		$plan = self::plan( $registry, $type, $query );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$result = array(
			'ids'           => array(),
			'count'         => $plan['count'] ? 0 : null,
			'sums'          => array_fill_keys( array_keys( $plan['sums'] ), 0.0 ),
			'empty_operand' => $plan['empty_operand'],
		);
		if ( null !== $plan['empty_operand'] || $plan['none'] ) {
			return $result;
		}

		global $wpdb;
		$from = "FROM {$wpdb->posts} p {$plan['join']} WHERE {$plan['where']}";
		$args = array_merge( $plan['join_args'], $plan['args'] );

		if ( $plan['limit'] > 0 ) {
			$sql = "SELECT p.ID {$from} ORDER BY {$plan['order']} LIMIT %d";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Reads must be fresh; $sql holds only placeholders and table names.
			$ids           = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $args, $plan['order_args'], array( $plan['limit'] ) ) ) );
			$result['ids'] = array_map( 'intval', $ids );
		}

		if ( $plan['count'] ) {
			$sql = "SELECT COUNT(*) {$from}";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Counts must be fresh; $sql holds only placeholders and table names.
			$result['count'] = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		}

		if ( array() !== $plan['sums'] ) {
			$keys = array_values( $plan['sums'] );
			$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$sql  = 'SELECT sm.meta_key AS k, SUM( CAST( sm.meta_value AS ' . self::DECIMAL . " ) ) AS total FROM {$wpdb->posts} p {$plan['join']} INNER JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key IN ( {$in} ) AND sm.meta_value <> '' WHERE {$plan['where']} GROUP BY sm.meta_key";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Sums must be fresh; $sql holds only placeholders and table names.
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $plan['join_args'], $keys, $plan['args'] ) ), ARRAY_A );
			$by_key = array_flip( $plan['sums'] );
			foreach ( (array) $rows as $row ) {
				if ( isset( $by_key[ $row['k'] ] ) ) {
					$result['sums'][ $by_key[ $row['k'] ] ] = (float) $row['total'];
				}
			}
		}

		return $result;
	}

	/**
	 * Validates a query and builds its SQL.
	 *
	 * @param Registry             $registry Effective registry.
	 * @param string               $type     Content type key.
	 * @param array<string, mixed> $query    Query.
	 * @return array<string, mixed>|WP_Error `where`, `args`, `order`, `order_args`, `limit`, `count`, `sums` (field key to
	 *                                      meta key), `none` (the relationship end matches nothing), `empty_operand`.
	 */
	private static function plan( Registry $registry, string $type, array $query ): array|WP_Error {
		$content_type = $registry->content_type( $type );
		if ( null === $content_type ) {
			/* translators: %s: content type key. */
			return self::error( 'unknown_content_type', sprintf( __( 'Unknown content type "%s".', 'pegwork' ), $type ) );
		}

		$unknown = array_diff( array_keys( $query ), self::PROPERTIES );
		if ( array() !== $unknown ) {
			/* translators: %s: query property. */
			return self::error( 'unknown_property', sprintf( __( 'Unknown query property "%s".', 'pegwork' ), (string) reset( $unknown ) ) );
		}

		$status = $query['status'] ?? self::DEFAULT_STATUS;
		if ( ! is_array( $status ) || array() === $status || array() !== array_diff( $status, self::STATUSES ) ) {
			return self::error( 'invalid_value', __( 'status must list publish, future, draft, pending or private.', 'pegwork' ) );
		}
		$status = array_values( array_unique( $status ) );
		sort( $status );

		$limit = $query['limit'] ?? self::DEFAULT_LIMIT;
		if ( ! is_int( $limit ) || $limit < 0 || $limit > self::MAX_LIMIT ) {
			/* translators: %d: maximum. */
			return self::error( 'invalid_value', sprintf( __( 'limit must be an integer from 0 to %d.', 'pegwork' ), self::MAX_LIMIT ) );
		}

		$fields = $registry->fields( $type );
		$sums   = array();
		$sum    = $query['sum'] ?? array();
		if ( ! is_array( $sum ) || count( $sum ) > self::MAX_SUMS ) {
			/* translators: %d: maximum. */
			return self::error( 'invalid_value', sprintf( __( 'sum lists at most %d number fields.', 'pegwork' ), self::MAX_SUMS ) );
		}
		foreach ( $sum as $key ) {
			$field = is_string( $key ) ? ( $fields[ $key ] ?? null ) : null;
			if ( null === $field || 'number' !== $field['type'] || null !== ( $field['wp']['storage'] ?? null ) ) {
				/* translators: %s: field key. */
				return self::error( 'filter_field', sprintf( __( 'Only number fields can be summed, not "%s".', 'pegwork' ), is_scalar( $key ) ? (string) $key : '' ) );
			}
			$sums[ $key ] = Meta::key( $type, $field );
		}

		$where = array( 'p.post_type = %s', 'p.post_status IN ( ' . implode( ', ', array_fill( 0, count( $status ), '%s' ) ) . ' )' );
		$args  = array_merge( array( $type ), $status );
		$none  = false;
		$empty = null;
		$parts = array();
		$plan  = array(
			'limit'     => $limit,
			'count'     => ! empty( $query['count'] ),
			'sums'      => $sums,
			'join'      => '',
			'join_args' => array(),
		);

		if ( isset( $query['related'] ) ) {
			$related = self::related( $registry, $type, $query['related'] );
			if ( is_wp_error( $related ) ) {
				return $related;
			}
			if ( null === $related ) {
				$none = true;
			} else {
				$plan['join']      = $related['join'];
				$plan['join_args'] = $related['join_args'];
				$where[]           = $related['where'];
				$args              = array_merge( $args, $related['where_args'] );
			}
		}

		if ( isset( $query['filter'] ) ) {
			$filter = $query['filter'];
			$group  = is_array( $filter ) && 1 === count( $filter ) ? (string) array_key_first( $filter ) : '';
			$leaves = in_array( $group, array( 'all', 'any' ), true ) ? $filter[ $group ] : null;
			if ( ! is_array( $leaves ) || ! array_is_list( $leaves ) || array() === $leaves || count( $leaves ) > self::MAX_LEAVES ) {
				/* translators: %d: maximum leaves. */
				return self::error( 'condition_too_complex', sprintf( __( 'filter is { "all": [...] } or { "any": [...] } with 1 to %d conditions.', 'pegwork' ), self::MAX_LEAVES ) );
			}
			foreach ( $leaves as $leaf ) {
				$built = self::leaf( $registry, $type, $content_type, is_array( $leaf ) ? $leaf : array() );
				if ( is_wp_error( $built ) ) {
					return $built;
				}
				if ( null === $built ) {
					$empty = $empty ?? (string) $leaf['field'];
					continue;
				}
				$parts[] = $built;
			}
			if ( null === $empty ) {
				$where[] = '( ' . implode( 'all' === $group ? ' AND ' : ' OR ', array_column( $parts, 0 ) ) . ' )';
				$args    = array_merge( $args, ...array_column( $parts, 1 ) );
			}
		}

		if ( isset( $query['created'] ) ) {
			$created = self::created( $query['created'] );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			if ( null === $created ) {
				$empty = $empty ?? 'created';
			} elseif ( null === $empty ) {
				$where[] = $created[0];
				$args    = array_merge( $args, $created[1] );
			}
		}

		$order = self::order( $registry, $type, $query['sort'] ?? array() );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return $plan + array(
			'where'         => implode( ' AND ', $where ),
			'args'          => $args,
			'order'         => $order[0],
			'order_args'    => $order[1],
			'none'          => $none,
			'empty_operand' => $empty,
		);
	}

	/**
	 * Builds the `created` condition (API 0.4.0) on the creation time. Each bound is a site-local `Y-m-d`
	 * day: `before` matches records created before that day starts, `after` records created after that
	 * day ends (from the next day's start). Day starts are converted to UTC for `post_date_gmt`; a draft
	 * whose `post_date_gmt` is still zero is compared by its site-time `post_date`.
	 *
	 * @param mixed $created `{ before?, after? }`.
	 * @return array{0: string, 1: array<int, string>}|null|WP_Error SQL and args; null when a bound is empty
	 *                                                              (null or ''), which matches nothing.
	 */
	private static function created( mixed $created ): array|null|WP_Error {
		$message = __( 'created is { "before": "YYYY-MM-DD" } or { "after": "YYYY-MM-DD" }, or both.', 'pegwork' );
		if ( ! is_array( $created ) || array() === $created || array() !== array_diff( array_keys( $created ), self::CREATED_BOUNDS ) ) {
			return self::error( 'invalid_value', $message );
		}

		$parts = array();
		$args  = array();
		$empty = false;
		foreach ( self::CREATED_BOUNDS as $bound ) {
			if ( ! array_key_exists( $bound, $created ) ) {
				continue;
			}
			$value = $created[ $bound ];
			if ( null === $value || '' === $value ) {
				$empty = true;
				continue;
			}
			$day = is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;
			if ( ! $day instanceof DateTimeImmutable || $day->format( 'Y-m-d' ) !== $value ) {
				return self::error( 'invalid_value', $message );
			}

			$start   = 'after' === $bound ? $day->modify( '+1 day' ) : $day;
			$sql_op  = 'after' === $bound ? '>=' : '<';
			$parts[] = "( ( p.post_date_gmt <> '0000-00-00 00:00:00' AND p.post_date_gmt {$sql_op} %s ) OR ( p.post_date_gmt = '0000-00-00 00:00:00' AND p.post_date {$sql_op} %s ) )";
			array_push( $args, $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $start->format( 'Y-m-d H:i:s' ) );
		}
		if ( $empty ) {
			return null;
		}

		return array( implode( ' AND ', $parts ), $args );
	}

	/**
	 * Builds the relationship condition.
	 *
	 * @param Registry $registry Effective registry.
	 * @param string   $type     Content type read.
	 * @param mixed    $related  `{ relationship, of }`.
	 * @return array<string, mixed>|null|WP_Error Relationships::sql() shape; null when nothing can match (an `of`
	 *                                           record that is missing, trashed or not at the other end).
	 */
	private static function related( Registry $registry, string $type, mixed $related ): array|null|WP_Error {
		$key          = is_array( $related ) && is_string( $related['relationship'] ?? null ) ? $related['relationship'] : '';
		$relationship = $registry->relationships()[ $key ] ?? null;
		if ( null === $relationship ) {
			/* translators: %s: relationship key. */
			return self::error( 'unknown_relationship', sprintf( __( 'Unknown relationship "%s".', 'pegwork' ), $key ) );
		}
		if ( ! in_array( $type, array( $relationship['from'], $relationship['to'] ), true ) ) {
			/* translators: 1: relationship key, 2: content type key. */
			return self::error( 'relationship_end', sprintf( __( 'Relationship "%1$s" does not link "%2$s" records.', 'pegwork' ), $key, $type ) );
		}

		$of   = $related['of'] ?? null;
		$of   = is_int( $of ) || ( is_string( $of ) && ctype_digit( $of ) ) ? (int) $of : 0;
		$post = $of > 0 ? get_post( $of ) : null;
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			return null;
		}

		$sql = Relationships::sql( $registry, $post, $key, 'p' );

		return null !== $sql && $type === $sql['type'] ? $sql : null;
	}

	/**
	 * Builds one filter leaf.
	 *
	 * @param Registry             $registry     Effective registry.
	 * @param string               $type         Content type key.
	 * @param array<string, mixed> $content_type Normalized content type.
	 * @param array<string, mixed> $leaf         `{ field, op, value }`.
	 * @return array{0: string, 1: array<int, mixed>}|null|WP_Error SQL and args; null when the operand is empty.
	 */
	private static function leaf( Registry $registry, string $type, array $content_type, array $leaf ): array|null|WP_Error {
		$key = is_string( $leaf['field'] ?? null ) ? $leaf['field'] : '';
		$op  = is_string( $leaf['op'] ?? null ) ? $leaf['op'] : '';
		if ( ! in_array( $op, self::OPS, true ) ) {
			/* translators: %s: operator. */
			return self::error( 'invalid_value', sprintf( __( 'Unknown filter operator "%s".', 'pegwork' ), $op ) );
		}
		$unary = in_array( $op, array( 'empty', 'not_empty' ), true );
		$value = $leaf['value'] ?? null;
		if ( ! $unary && ( null === $value || '' === $value || array() === $value ) ) {
			return null;
		}

		$usages = array_column( $content_type['taxonomies'], null, 'key' );
		if ( isset( $usages[ $key ] ) ) {
			return self::taxonomy_leaf( $key, $usages[ $key ]['select'] ?? 'multi', $op, $value );
		}

		if ( 'id' === $key ) {
			$definition = array(
				'type' => 'number',
				'key'  => 'id',
			);
			$column     = 'p.ID';
			$meta_key   = null;
		} else {
			$definition = $registry->fields( $type )[ $key ] ?? null;
			if ( null === $definition ) {
				/* translators: %s: field key. */
				return self::error( 'unknown_field', sprintf( __( 'Unknown field "%s".', 'pegwork' ), $key ) );
			}
			$storage = $definition['wp']['storage'] ?? null;
			if ( in_array( $definition['type'], self::UNFILTERABLE_TYPES, true ) || 'post_content' === $storage ) {
				/* translators: %s: field key. */
				return self::error( 'filter_field', sprintf( __( 'Field "%s" holds long text and cannot be filtered.', 'pegwork' ), $key ) );
			}
			$column   = 'post_title' === $storage ? 'p.post_title' : null;
			$meta_key = null === $column ? Meta::key( $type, $definition ) : null;
		}

		$field_type = $definition['type'];
		if ( in_array( $op, array( 'lt', 'lte', 'gt', 'gte' ), true ) && ! in_array( $field_type, array( 'number', 'date' ), true ) ) {
			/* translators: 1: operator, 2: field type. */
			return self::error( 'condition_type_mismatch', sprintf( __( '"%1$s" compares numbers and dates, not %2$s values.', 'pegwork' ), $op, $field_type ) );
		}
		if ( 'contains' === $op ) {
			return self::error( 'condition_type_mismatch', __( '"contains" applies to multi-select taxonomies only.', 'pegwork' ) );
		}

		if ( 'boolean' === $field_type ) {
			return self::boolean_leaf( (string) $meta_key, $op, $value );
		}

		$values = in_array( $op, array( 'in', 'not_in' ), true ) ? $value : array( $value );
		if ( ! $unary && ( ! is_array( $values ) || ! array_is_list( $values ) ) ) {
			return self::error( 'condition_type_mismatch', __( '"in" and "not_in" take a list of values.', 'pegwork' ) );
		}

		$literals = array();
		$op_sql   = $op;
		foreach ( $unary ? array() : $values as $item ) {
			$literal = self::literal( $field_type, $item, $op );
			if ( is_wp_error( $literal ) ) {
				/* translators: 1: field key, 2: reason. */
				return self::error( 'condition_type_mismatch', sprintf( __( 'Filter on "%1$s": %2$s', 'pegwork' ), $key, $literal->get_error_message() ) );
			}
			if ( null !== $literal['op'] ) {
				$op_sql = $literal['op'];
			}
			if ( null !== $literal['value'] ) {
				$literals[] = $literal['value'];
			}
		}

		if ( 'p.ID' === $column ) {
			$expr     = '{v}';
			$holder   = '%d';
			$literals = array_map( 'intval', $literals );
		} else {
			$expr   = 'number' === $field_type ? 'CAST( {v} AS ' . self::DECIMAL . ' )' : '{v}';
			$holder = 'number' === $field_type ? '%f' : '%s';
		}
		$cmp = array( '1=1', array() );
		if ( ! $unary ) {
			if ( array() === $literals ) {
				$cmp = array( '1=0', array() ); // A date-time operand no stored date can equal.
			} elseif ( in_array( $op, array( 'in', 'not_in' ), true ) ) {
				$cmp = array( $expr . ' IN ( ' . implode( ', ', array_fill( 0, count( $literals ), $holder ) ) . ' )', $literals );
			} else {
				$sql_op = array(
					'eq'  => '=',
					'ne'  => '=',
					'lt'  => '<',
					'lte' => '<=',
					'gt'  => '>',
					'gte' => '>=',
				)[ $op_sql ];
				$cmp    = array( "{$expr} {$sql_op} {$holder}", array( $literals[0] ) );
			}
		}

		$negate = in_array( $op, array( 'ne', 'not_in', 'empty' ), true );
		if ( 'p.ID' === $column ) {
			if ( $unary ) {
				return array( 'empty' === $op ? '1=0' : '1=1', array() );
			}
			$sql = str_replace( '{v}', 'p.ID', $cmp[0] );

			return array( $negate ? "NOT ( {$sql} )" : $sql, $cmp[1] );
		}

		if ( null !== $column ) {
			$sql = "( {$column} <> '' AND " . str_replace( '{v}', $column, $cmp[0] ) . ' )';
		} else {
			$sql = self::meta_exists( "m.meta_value <> '' AND " . str_replace( '{v}', 'm.meta_value', $cmp[0] ) );
		}
		$args = null === $column ? array_merge( array( $meta_key ), $cmp[1] ) : $cmp[1];

		return array( $negate ? "NOT {$sql}" : $sql, $args );
	}

	/**
	 * Checks and converts one operand literal for a field type.
	 *
	 * Date fields hold site-local `Y-m-d` dates (midnight). A date-time operand that is not midnight
	 * is mapped so the comparison agrees with automation conditions: `lt` becomes `lte` its date, `gte`
	 * becomes `gt`, and `eq`, `in` cannot match it.
	 *
	 * @param string $type Field type.
	 * @param mixed  $item Literal.
	 * @param string $op   Operator.
	 * @return array{value: mixed, op: string|null}|WP_Error `value` null drops the literal; `op` replaces the operator.
	 */
	private static function literal( string $type, mixed $item, string $op ): array|WP_Error {
		if ( ! is_scalar( $item ) || is_bool( $item ) ) {
			return new WP_Error( 'mismatch', __( 'the value is not a single text, number or date.', 'pegwork' ) );
		}

		if ( 'number' === $type ) {
			if ( ! is_numeric( $item ) ) {
				return new WP_Error( 'mismatch', __( 'the value is not a number.', 'pegwork' ) );
			}

			return array(
				'value' => 0 + $item,
				'op'    => null,
			);
		}

		if ( 'date' !== $type ) {
			return array(
				'value' => (string) $item,
				'op'    => null,
			);
		}

		$text = (string) $item;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
			return array(
				'value' => $text,
				'op'    => null,
			);
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:?\d{2}|Z)?$/', $text ) ) {
			return new WP_Error( 'mismatch', __( 'the value is not a date (YYYY-MM-DD) or date-time.', 'pegwork' ) );
		}
		try {
			$local = ( new DateTimeImmutable( $text, wp_timezone() ) )->setTimezone( wp_timezone() );
		} catch ( Exception $e ) {
			return new WP_Error( 'mismatch', __( 'the value is not a date (YYYY-MM-DD) or date-time.', 'pegwork' ) );
		}
		$date = $local->format( 'Y-m-d' );
		if ( '00:00:00' === $local->format( 'H:i:s' ) ) {
			return array(
				'value' => $date,
				'op'    => null,
			);
		}

		return match ( $op ) {
			'lt', 'lte' => array(
				'value' => $date,
				'op'    => 'lte',
			),
			'gt', 'gte' => array(
				'value' => $date,
				'op'    => 'gt',
			),
			default     => array(
				'value' => null,
				'op'    => null,
			),
		};
	}

	/**
	 * Builds a boolean leaf. A boolean is true when its stored value is "1"; anything else (including no row) is false,
	 * as Records::get() reads it, so a boolean is never empty.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $op       Operator.
	 * @param mixed  $value    Operand.
	 * @return array{0: string, 1: array<int, mixed>}|WP_Error
	 */
	private static function boolean_leaf( string $meta_key, string $op, mixed $value ): array|WP_Error {
		if ( 'empty' === $op || 'not_empty' === $op ) {
			return array( 'empty' === $op ? '1=0' : '1=1', array() );
		}

		$wanted = array();
		foreach ( in_array( $op, array( 'in', 'not_in' ), true ) ? (array) $value : array( $value ) as $item ) {
			if ( ! rest_is_boolean( $item ) ) {
				return self::error( 'condition_type_mismatch', __( 'A boolean field compares with true or false.', 'pegwork' ) );
			}
			$wanted[ rest_sanitize_boolean( $item ) ? 1 : 0 ] = true;
		}
		$negate = in_array( $op, array( 'ne', 'not_in' ), true );
		if ( 2 === count( $wanted ) ) {
			return array( $negate ? '1=0' : '1=1', array() );
		}
		$is_true = self::meta_exists( "m.meta_value = '1'" );
		if ( isset( $wanted[0] ) ) {
			$negate = ! $negate;
		}

		return array( $negate ? "NOT {$is_true}" : $is_true, array( $meta_key ) );
	}

	/**
	 * Builds a taxonomy leaf on term slugs. `eq` is set equality, as automation conditions compare term lists.
	 *
	 * @param string $taxonomy Taxonomy key.
	 * @param string $select   `single` or `multi`.
	 * @param string $op       Operator.
	 * @param mixed  $value    Slug or list of slugs.
	 * @return array{0: string, 1: array<int, mixed>}|WP_Error
	 */
	private static function taxonomy_leaf( string $taxonomy, string $select, string $op, mixed $value ): array|WP_Error {
		if ( in_array( $op, array( 'lt', 'lte', 'gt', 'gte' ), true ) ) {
			/* translators: %s: operator. */
			return self::error( 'condition_type_mismatch', sprintf( __( '"%s" compares numbers and dates, not taxonomy terms.', 'pegwork' ), $op ) );
		}
		if ( 'contains' === $op && 'multi' !== $select ) {
			return self::error( 'condition_type_mismatch', __( '"contains" applies to multi-select taxonomies only.', 'pegwork' ) );
		}
		if ( 'empty' === $op || 'not_empty' === $op ) {
			$sql = self::term_exists( '1=1' );

			return array( 'empty' === $op ? "NOT {$sql}" : $sql, array( $taxonomy ) );
		}

		$slugs = is_array( $value ) ? $value : array( $value );
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				return self::error( 'condition_type_mismatch', __( 'A taxonomy compares with term slugs.', 'pegwork' ) );
			}
		}
		$slugs = array_values( array_unique( $slugs ) );
		$in    = 't.slug IN ( ' . implode( ', ', array_fill( 0, count( $slugs ), '%s' ) ) . ' )';

		if ( in_array( $op, array( 'in', 'not_in', 'contains' ), true ) ) {
			$sql = self::term_exists( $in );

			return array( 'not_in' === $op ? "NOT {$sql}" : $sql, array_merge( array( $taxonomy ), $slugs ) );
		}

		$parts = array();
		$args  = array();
		foreach ( $slugs as $slug ) {
			$parts[] = self::term_exists( 't.slug = %s' );
			array_push( $args, $taxonomy, $slug );
		}
		$parts[] = 'NOT ' . self::term_exists( "NOT {$in}" );
		$args    = array_merge( $args, array( $taxonomy ), $slugs );
		$sql     = '( ' . implode( ' AND ', $parts ) . ' )';

		return array( 'ne' === $op ? "NOT {$sql}" : $sql, $args );
	}

	/**
	 * Builds the ORDER BY clause. Records with no value for the sort field come last in both directions; ties
	 * go by ID in the same direction.
	 *
	 * @param Registry $registry Effective registry.
	 * @param string   $type     Content type key.
	 * @param mixed    $sort     `{ field, order }`.
	 * @return array{0: string, 1: array<int, mixed>}|WP_Error
	 */
	private static function order( Registry $registry, string $type, mixed $sort ): array|WP_Error {
		global $wpdb;

		$sort  = is_array( $sort ) ? $sort : array();
		$key   = $sort['field'] ?? 'id';
		$order = $sort['order'] ?? 'asc';
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			return self::error( 'invalid_value', __( 'sort order is asc or desc.', 'pegwork' ) );
		}
		$dir = 'asc' === $order ? 'ASC' : 'DESC';
		if ( 'id' === $key ) {
			return array( "p.ID {$dir}", array() );
		}

		$field   = is_string( $key ) ? ( $registry->fields( $type )[ $key ] ?? null ) : null;
		$storage = $field['wp']['storage'] ?? null;
		if ( null === $field || 'post_content' === $storage || ( 'post_title' !== $storage && ! in_array( $field['type'], self::SORTABLE_TYPES, true ) ) ) {
			/* translators: %s: field key. */
			return self::error( 'filter_field', sprintf( __( 'Records cannot be sorted by "%s".', 'pegwork' ), is_scalar( $key ) ? (string) $key : '' ) );
		}
		if ( 'post_title' === $storage ) {
			return array( "p.post_title = '' ASC, p.post_title {$dir}, p.ID {$dir}", array() );
		}

		$value = "( SELECT s.meta_value FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s ORDER BY s.meta_id LIMIT 1 )";
		$expr  = 'number' === $field['type'] ? 'CAST( ' . $value . ' AS ' . self::DECIMAL . ' )' : $value;
		$meta  = Meta::key( $type, $field );

		return array( "COALESCE( {$value}, '' ) = '' ASC, {$expr} {$dir}, p.ID {$dir}", array( $meta, $meta ) );
	}

	/**
	 * EXISTS over the record's rows for one meta key (first placeholder).
	 *
	 * @param string $condition Condition on `m.meta_value`.
	 */
	private static function meta_exists( string $condition ): string {
		global $wpdb;

		return "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND {$condition} )";
	}

	/**
	 * EXISTS over the record's terms in one taxonomy (first placeholder).
	 *
	 * @param string $condition Condition on `t.slug`.
	 */
	private static function term_exists( string $condition ): string {
		global $wpdb;

		return "EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND tt.taxonomy = %s AND {$condition} )";
	}

	/**
	 * An invalid-query error.
	 *
	 * @param string $reason  Registry-style reason code.
	 * @param string $message Message.
	 */
	private static function error( string $reason, string $message ): WP_Error {
		return new WP_Error(
			'pegwork_invalid_query',
			$message,
			array(
				'status' => 400,
				'reason' => $reason,
			)
		);
	}
}
