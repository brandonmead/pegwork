<?php
/**
 * Public event bus.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Api;

/**
 * In-process, synchronous event bus on WordPress actions. Part of the semver-stable public API.
 *
 * Listeners hook `pegwork_event` (every event, with its name) or `pegwork_event_{name}` with dots
 * replaced by underscores (for example `pegwork_event_record_updated`). Listeners that do slow work
 * must queue it. Events are never persisted. See docs/events.md.
 */
final class Events {

	/**
	 * Valid event names: two or more dot-separated lowercase segments, such as `record.updated`.
	 */
	public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

	/**
	 * Emits an event to its listeners, synchronously.
	 *
	 * An invalid name triggers `_doing_it_wrong()` and nothing is emitted.
	 *
	 * @param string               $name    Event name matching NAME_PATTERN, for example `record.updated`.
	 * @param array<string, mixed> $payload Event data. Record events may carry private field values: never output a payload as is.
	 */
	public static function emit( string $name, array $payload ): void {
		if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
			_doing_it_wrong(
				__METHOD__,
				/* translators: %s: event name. */
				esc_html( sprintf( __( 'Invalid event name "%s": use dot-separated lowercase segments, such as record.updated.', 'pegwork' ), $name ) ),
				'0.2.0'
			);
			return;
		}

		/**
		 * Fires for every Pegwork event.
		 *
		 * @param string               $name    Event name.
		 * @param array<string, mixed> $payload Event data.
		 */
		do_action( 'pegwork_event', $name, $payload );

		/**
		 * Fires for one Pegwork event; the hook name is the event name with dots replaced by underscores.
		 *
		 * @param array<string, mixed> $payload Event data.
		 */
		do_action( 'pegwork_event_' . str_replace( '.', '_', $name ), $payload );
	}
}
