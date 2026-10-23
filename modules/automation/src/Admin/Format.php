<?php
/**
 * Shared formatting for the Automations screens.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation\Admin;

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
	 * A run's outcome: a stopped condition, else its error or skip reason.
	 *
	 * @param array<string, mixed> $run Run.
	 */
	public static function outcome( array $run ): string {
		$outcome = $run['context']['outcome'] ?? null;
		if ( is_string( $outcome ) && str_starts_with( $outcome, 'stopped_at:' ) ) {
			/* translators: %s: step ID. */
			return sprintf( __( 'Stopped at condition %s', 'pegwork' ), substr( $outcome, 11 ) );
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
