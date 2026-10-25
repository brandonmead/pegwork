<?php
/**
 * Shared formatting for the Automations screens.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

use PegworkAutomation\Executor;
use PegworkAutomation\Plan;

/**
 * Labels and escaped HTML fragments. Every method returns escaped HTML or plain text for esc_html().
 *
 * Nothing here reads a run's `context.trigger` (it may hold private field values): runs show their
 * status, trigger type and event name, record ID and title, outcome or error, and step summaries, which
 * hold keys, IDs, masked recipients and subjects with private fields hidden.
 */
final class Format {

	/**
	 * Translated label of a run status.
	 *
	 * @param string $status Run status.
	 */
	public static function run_status( string $status ): string {
		$labels = array(
			'queued'    => __( 'Queued', 'pegwork' ),
			'running'   => __( 'Running', 'pegwork' ),
			'waiting'   => __( 'Waiting', 'pegwork' ),
			'completed' => __( 'Completed', 'pegwork' ),
			'failed'    => __( 'Failed', 'pegwork' ),
			'cancelled' => __( 'Cancelled', 'pegwork' ),
			'skipped'   => __( 'Skipped', 'pegwork' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Translated label of a step attempt status.
	 *
	 * @param string $status Step status.
	 */
	public static function step_status( string $status ): string {
		$labels = array(
			'ok'       => __( 'OK', 'pegwork' ),
			'failed'   => __( 'Failed', 'pegwork' ),
			'retrying' => __( 'Failed, will retry', 'pegwork' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Status as a labelled badge (text, never colour alone).
	 *
	 * @param string $status Run or step status.
	 * @param string $label  Its translated label.
	 */
	public static function badge( string $status, string $label ): string {
		return '<span class="pegwork-status pegwork-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * A stored UTC time in site time, inside a <time> element.
	 *
	 * @param string|null $utc  `Y-m-d H:i:s` UTC, or null.
	 * @param string      $none Text when there is no time.
	 */
	public static function time( ?string $utc, string $none = '' ): string {
		$timestamp = null === $utc || '' === $utc ? false : strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return esc_html( $none );
		}
		return self::timestamp( $timestamp );
	}

	/**
	 * A Unix time in site time, inside a <time> element.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function timestamp( int $timestamp ): string {
		$format = get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' );
		return '<time datetime="' . esc_attr( gmdate( 'c', $timestamp ) ) . '">' . esc_html( (string) wp_date( $format, $timestamp ) ) . '</time>';
	}

	/**
	 * One-line, translated trigger summary of a canonical trigger.
	 *
	 * @param array<string, mixed> $trigger Canonical trigger.
	 */
	public static function trigger( array $trigger ): string {
		switch ( $trigger['type'] ) {
			case 'event':
				$filter = (string) ( $trigger['content_type'] ?? ( $trigger['form'] ?? '' ) );
				return '' === $filter
					/* translators: %s: event name such as record.updated. */
					? sprintf( __( 'On %s', 'pegwork' ), $trigger['event'] )
					/* translators: 1: event name such as record.updated, 2: content type or form key. */
					: sprintf( __( 'On %1$s (%2$s)', 'pegwork' ), $trigger['event'], $filter );

			case 'schedule':
				$at = (string) ( $trigger['at'] ?? '00:00' );
				switch ( $trigger['every'] ) {
					case 'hour':
						/* translators: %s: minutes past the hour, such as 30. */
						$when = sprintf( __( 'Every hour at %s minutes past', 'pegwork' ), substr( $at, 3, 2 ) );
						break;
					case 'week':
						global $wp_locale;
						$day = $wp_locale->get_weekday( (int) get_option( 'start_of_week', 1 ) % 7 );
						/* translators: 1: weekday name, 2: time HH:MM in site time. */
						$when = sprintf( __( 'Every week on %1$s at %2$s', 'pegwork' ), $day, $at );
						break;
					default:
						/* translators: %s: time HH:MM in site time. */
						$when = sprintf( __( 'Every day at %s', 'pegwork' ), $at );
				}
				if ( isset( $trigger['for_each']['content_type'] ) ) {
					/* translators: 1: schedule such as "Every day at 09:00", 2: content type key. */
					$when = sprintf( __( '%1$s, for each %2$s', 'pegwork' ), $when, $trigger['for_each']['content_type'] );
				}
				return $when;

			case 'webhook':
				return __( 'Inbound webhook', 'pegwork' );

			case 'manual':
				return isset( $trigger['content_type'] )
					/* translators: 1: button text, 2: content type key. */
					? sprintf( __( 'Started by staff: "%1$s" on each %2$s', 'pegwork' ), $trigger['button'], $trigger['content_type'] )
					/* translators: %s: button text. */
					: sprintf( __( 'Started by staff: "%s" (Run now)', 'pegwork' ), $trigger['button'] );
		}
		/* translators: %s: trigger type. */
		return sprintf( __( '%s (not supported yet)', 'pegwork' ), $trigger['type'] );
	}

	/**
	 * Where an automation is defined, from Registry::automation_source().
	 *
	 * @param string|null $source `file:<package id>`, `db` or null.
	 */
	public static function source( ?string $source ): string {
		if ( null === $source ) {
			return '';
		}
		if ( 'db' === $source ) {
			return __( 'Site automation (database)', 'pegwork' );
		}
		/* translators: %s: schema package id. */
		return sprintf( __( 'File package %s', 'pegwork' ), substr( $source, 5 ) );
	}

	/**
	 * A run's trigger: its type and, for events, the event name.
	 *
	 * @param array<string, mixed> $run Run.
	 */
	public static function run_trigger( array $run ): string {
		$types = array(
			'event'    => __( 'Event', 'pegwork' ),
			'schedule' => __( 'Schedule', 'pegwork' ),
			'manual'   => __( 'Manual', 'pegwork' ),
			'webhook'  => __( 'Webhook', 'pegwork' ),
			'link'     => __( 'Link', 'pegwork' ),
		);
		$type  = $types[ $run['trigger_type'] ] ?? (string) $run['trigger_type'];
		$event = $run['context']['event'] ?? null;
		return is_string( $event ) && '' !== $event ? $type . ' <code>' . esc_html( $event ) . '</code>' : esc_html( $type );
	}

	/**
	 * A run's record: ID and title, linked to its edit screen when the user may edit it.
	 *
	 * @param int|null $record_id Record ID.
	 */
	public static function record( ?int $record_id ): string {
		if ( null === $record_id ) {
			return esc_html__( 'None', 'pegwork' );
		}
		$post = get_post( $record_id );
		/* translators: %d: record (post) ID. */
		$id = esc_html( sprintf( __( '#%d', 'pegwork' ), $record_id ) );
		if ( null === $post ) {
			/* translators: %s: record ID such as #24. */
			return esc_html( sprintf( __( '%s (deleted)', 'pegwork' ), sprintf( '#%d', $record_id ) ) );
		}
		$title = '' === $post->post_title ? __( '(no title)', 'pegwork' ) : $post->post_title;
		$text  = $id . ' ' . esc_html( $title );
		$link  = current_user_can( 'edit_post', $record_id ) ? get_edit_post_link( $record_id, 'raw' ) : null;
		return null === $link || '' === $link ? $text : '<a href="' . esc_url( $link ) . '">' . $text . '</a>';
	}

	/**
	 * A run's outcome: a stopped condition, steps that failed and were continued past, else its error or
	 * skip reason.
	 *
	 * @param array<string, mixed> $run Run.
	 */
	public static function outcome( array $run ): string {
		$outcome = $run['context']['outcome'] ?? null;
		if ( is_string( $outcome ) && str_starts_with( $outcome, 'stopped_at:' ) ) {
			/* translators: %s: step ID. */
			return sprintf( __( 'Stopped at condition %s', 'pegwork' ), substr( $outcome, 11 ) );
		}
		if ( is_string( $outcome ) && str_starts_with( $outcome, Executor::ERRORS_OUTCOME ) ) {
			/* translators: %s: comma-separated step IDs. */
			return sprintf( __( 'Completed with errors in %s', 'pegwork' ), str_replace( ',', ', ', substr( $outcome, strlen( Executor::ERRORS_OUTCOME ) ) ) );
		}
		return is_string( $outcome ) && '' !== $outcome ? $outcome : (string) $run['error'];
	}

	/**
	 * A step attempt's summary as a list of key: value pairs.
	 *
	 * @param array<string, mixed> $summary Decoded summary.
	 */
	public static function summary( array $summary ): string {
		if ( array() === $summary ) {
			return '';
		}
		$items = '';
		foreach ( $summary as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? __( 'yes', 'pegwork' ) : __( 'no', 'pegwork' );
			} elseif ( is_array( $value ) ) {
				$value = implode( ', ', array_map( fn( $item ) => is_scalar( $item ) ? (string) $item : (string) wp_json_encode( $item ), $value ) );
			} elseif ( ! is_scalar( $value ) ) {
				$value = (string) wp_json_encode( $value );
			}
			$items .= '<li><code>' . esc_html( (string) $key ) . '</code> ' . esc_html( (string) $value ) . '</li>';
		}
		return '<ul class="pegwork-summary">' . $items . '</ul>';
	}

	/**
	 * A start_automation attempt's summary, with the child run linked ("Run 413") when the user may see the
	 * run log (administrators).
	 *
	 * @param array<string, mixed> $summary Decoded summary.
	 */
	public static function start( array $summary ): string {
		$child = (int) ( $summary['run'] ?? 0 );
		$html  = self::summary( array_diff_key( $summary, array( 'run' => true ) ) );
		if ( $child <= 0 ) {
			return $html;
		}
		return $html . '<p class="pegwork-meta">' . self::run_link( $child ) . '</p>';
	}

	/**
	 * The parent of a run a start_automation step started (`context.parent`): "Run 412 of Thank a donor
	 * (step receipt)", the run linked for administrators; empty for other runs.
	 *
	 * @param array<string, mixed> $run Run.
	 */
	public static function parent( array $run ): string {
		$parent = $run['context']['parent'] ?? null;
		if ( ! is_array( $parent ) || (int) ( $parent['run'] ?? 0 ) <= 0 ) {
			return '';
		}
		$key        = (string) ( $parent['automation'] ?? '' );
		$automation = pegwork_registry()->automation( $key );
		$label      = null === $automation ? $key : (string) $automation['label'];
		$step       = '<code>' . esc_html( (string) ( $parent['step'] ?? '' ) ) . '</code>';
		if ( isset( $parent['iteration'] ) ) {
			/* translators: 1: step ID, 2: loop item number. */
			$step = sprintf( esc_html__( '%1$s, item %2$s', 'pegwork' ), $step, esc_html( number_format_i18n( (int) $parent['iteration'] ) ) );
		}
		/* translators: 1: run link, 2: automation label, 3: step ID. */
		return sprintf( esc_html__( '%1$s of %2$s (step %3$s)', 'pegwork' ), self::run_link( (int) $parent['run'] ), esc_html( $label ), $step );
	}

	/**
	 * "Run 413", linked to the run's detail when the user may see the run log.
	 *
	 * @param int $id Run ID.
	 */
	private static function run_link( int $id ): string {
		/* translators: %d: run ID. */
		$text = esc_html( sprintf( __( 'Run %d', 'pegwork' ), $id ) );
		return current_user_can( Screen::CAPABILITY ) ? '<a href="' . esc_url( RunLog::run_url( $id ) ) . '">' . $text . '</a>' : $text;
	}

	/**
	 * Plain-text headings that group a run's loop rows by item, keyed by the index of the first row of each
	 * item: "Item 2 of 12: Task #38 (Call Ada)". The item IDs come from the loop's start row (`{ items, ids }`);
	 * a row's loop is its own id for a loop row, else Plan::loop_of() under the current definition, else the
	 * loop that started last. Shared by the run detail and `wp pegwork automation log --run`.
	 *
	 * @param array<int, array<string, mixed>> $steps Run-step rows, oldest first.
	 * @param Plan|null                        $plan  Plan of the run under the current definition, or null.
	 * @return array<int, string>
	 */
	public static function item_headings( array $steps, ?Plan $plan ): array {
		$headings = array();
		$started  = array(); // Start row summary by loop id.
		$last     = null;
		$current  = null;
		foreach ( array_values( $steps ) as $index => $step ) {
			$id        = (string) $step['step_id'];
			$iteration = $step['iteration'] ?? null;
			if ( null === $iteration ) {
				if ( 'loop' === $step['step_type'] ) {
					$started[ $id ] = (array) $step['summary'];
					$last           = $id;
				}
				$current = null;
				continue;
			}
			$loop = 'loop' === $step['step_type'] ? $id : ( $plan?->loop_of( $id ) ?? $last );
			$key  = $loop . ':' . (int) $iteration;
			if ( $key !== $current ) {
				$current            = $key;
				$headings[ $index ] = self::item( (array) ( $started[ (string) $loop ] ?? array() ), (int) $iteration );
			}
		}
		return $headings;
	}

	/**
	 * One item heading: "Item 2 of 12: Task #38 (Call Ada)", the title only when the viewer can read it.
	 *
	 * @param array<string, mixed> $summary   The loop's start row summary.
	 * @param int                  $iteration 1-based item number.
	 */
	public static function item( array $summary, int $iteration ): string {
		$ids   = array_values( (array) ( $summary['ids'] ?? array() ) );
		$count = (int) ( $summary['items'] ?? count( $ids ) );
		$id    = (int) ( $ids[ $iteration - 1 ] ?? 0 );
		if ( $id <= 0 ) {
			/* translators: 1: item number, 2: number of items. */
			return sprintf( __( 'Item %1$d of %2$d', 'pegwork' ), $iteration, $count );
		}
		/* translators: 1: item number, 2: number of items, 3: record such as "Task #38 (Call Ada)". */
		return sprintf( __( 'Item %1$d of %2$d: %3$s', 'pegwork' ), $iteration, $count, self::record_name( $id ) );
	}

	/**
	 * A record as plain text: its type label and ID ("Task #38"), plus its title in parentheses when the
	 * current user can read it (a run log never shows titles otherwise; it reads them live, never stores them).
	 *
	 * @param int $id Record ID.
	 */
	public static function record_name( int $id ): string {
		$post  = get_post( $id );
		$type  = null === $post ? null : pegwork_registry()->content_type( (string) $post->post_type );
		$label = (string) ( $type['labels']['singular'] ?? '' );
		/* translators: 1: content type label, 2: record ID. */
		$name = '' === $label ? sprintf( __( '#%d', 'pegwork' ), $id ) : sprintf( __( '%1$s #%2$d', 'pegwork' ), $label, $id );
		if ( null !== $post && '' !== $post->post_title && current_user_can( 'read_post', $id ) ) {
			/* translators: 1: record such as "Task #38", 2: record title. */
			$name = sprintf( __( '%1$s (%2$s)', 'pegwork' ), $name, wp_specialchars_decode( $post->post_title, ENT_QUOTES ) );
		}
		return $name;
	}

	/**
	 * The records a read step found (`record_id` of a get_record, `ids` of a find_records summary), named
	 * live for the run detail: at most 10, titled where the current user can read them. Empty for other
	 * summaries.
	 *
	 * @param array<string, mixed> $summary Decoded summary.
	 */
	public static function read_records( array $summary ): string {
		$ids = isset( $summary['ids'] ) && is_array( $summary['ids'] ) ? $summary['ids'] : ( isset( $summary['record_id'] ) && is_numeric( $summary['record_id'] ) ? array( $summary['record_id'] ) : array() );
		$ids = array_values( array_filter( array_map( 'intval', $ids ), fn( int $id ) => $id > 0 ) );
		if ( array() === $ids ) {
			return '';
		}
		$names = array_map( array( self::class, 'record_name' ), array_slice( $ids, 0, 10 ) );
		if ( count( $ids ) > 10 ) {
			/* translators: %d: number of further records. */
			$names[] = sprintf( __( 'and %d more', 'pegwork' ), count( $ids ) - 10 );
		}
		/* translators: %s: comma-separated records. */
		return '<p class="pegwork-meta pegwork-read-records">' . esc_html( sprintf( __( 'Read: %s', 'pegwork' ), implode( ', ', $names ) ) ) . '</p>';
	}

	/**
	 * A decision attempt's summary (`branch`: label, "otherwise" or null; `index`) as one line.
	 *
	 * @param array<string, mixed> $summary Decoded summary.
	 */
	public static function decision( array $summary ): string {
		$index = $summary['index'] ?? null;
		if ( -1 === $index ) {
			return esc_html__( 'Took Otherwise', 'pegwork' );
		}
		if ( ! is_int( $index ) ) {
			return esc_html__( 'No branch matched', 'pegwork' );
		}
		/* translators: %s: branch label. */
		return sprintf( esc_html__( 'Took branch %s', 'pegwork' ), '<strong>' . esc_html( (string) ( $summary['branch'] ?? '' ) ) . '</strong>' );
	}
}
