<?php
/**
 * Daily retention cleanup.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * WP-Cron job `pegwork_forms_cleanup`, daily: deletes spam after 30 days, unconfirmed entries after 7
 * (filter `pegwork_forms_retention_days`), and received and failed entries after their form's
 * `retention_days` when the form sets it. Entries of deleted forms keep only the status-based limits.
 * Deletion goes through Entries::delete(), like the admin and the eraser.
 */
final class Cleanup {

	/**
	 * WP-Cron hook.
	 */
	public const HOOK = 'pegwork_forms_cleanup';

	/**
	 * Default days to keep entries by status, regardless of form.
	 */
	public const DEFAULT_DAYS = array(
		'spam'        => 30,
		'unconfirmed' => 7,
	);

	/**
	 * Entries deleted per query.
	 */
	private const BATCH = 200;

	/**
	 * Hooks the job and schedules it daily unless it is pending.
	 */
	public static function register(): void {
		if ( ! has_action( self::HOOK, array( self::class, 'cron' ) ) ) {
			add_action( self::HOOK, array( self::class, 'cron' ) );
		}
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Cron callback.
	 */
	public static function cron(): void {
		self::run( time() );
	}

	/**
	 * Deletes every entry past its retention at a time.
	 *
	 * @param int $now Unix time.
	 * @return int Entries deleted.
	 */
	public static function run( int $now ): int {
		$deleted = 0;
		foreach ( self::status_days() as $status => $days ) {
			$deleted += self::purge( array( $status ), $days, null, $now );
		}
		foreach ( pegwork_registry()->forms() as $key => $form ) {
			$days = (int) ( $form['retention_days'] ?? 0 );
			if ( $days > 0 ) {
				$deleted += self::purge( array( 'received', 'failed' ), $days, (string) $key, $now );
			}
		}
		return $deleted;
	}

	/**
	 * Days to keep spam and unconfirmed entries. A value of 0 or less keeps them.
	 *
	 * @return array<string, int>
	 */
	public static function status_days(): array {
		/**
		 * Filters how many days spam and unconfirmed entries are kept.
		 *
		 * @param array<string, int> $days `spam` (default 30) and `unconfirmed` (default 7). 0 keeps them.
		 */
		$days = apply_filters( 'pegwork_forms_retention_days', self::DEFAULT_DAYS );
		$days = is_array( $days ) ? $days : array();

		$out = array();
		foreach ( self::DEFAULT_DAYS as $status => $default ) {
			$value = (int) ( $days[ $status ] ?? $default );
			if ( $value > 0 ) {
				$out[ $status ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Deletes entries in statuses older than a number of days, in batches.
	 *
	 * @param string[]    $statuses Statuses.
	 * @param int         $days     Days to keep.
	 * @param string|null $form     Form key, or null for every form.
	 * @param int         $now      Unix time.
	 */
	private static function purge( array $statuses, int $days, ?string $form, int $now ): int {
		$before  = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$deleted = 0;
		do {
			$ids      = Entries::ids_before( $statuses, $before, $form, self::BATCH );
			$found    = count( $ids );
			$batch    = Entries::delete( $ids );
			$deleted += $batch;
		} while ( self::BATCH === $found && $batch > 0 );

		return $deleted;
	}
}
