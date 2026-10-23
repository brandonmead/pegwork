<?php
/**
 * Rate limit table.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace Pegwork\Throttle;

/**
 * Creates and upgrades `{prefix}pegwork_throttle` (one row per counted hit, see Pegwork\Api\Throttle) with
 * dbDelta, versioned by the `pegwork_throttle_db_version` option. Checked on plugins_loaded (one autoloaded
 * option read), so activating and updating the plugin both install it. Dropped by uninstall.php.
 */
final class Schema {

	/**
	 * Current table schema version.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'pegwork_throttle_db_version';

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pegwork_throttle';
	}

	/**
	 * Installs or upgrades the table when the stored version differs. Hooked to plugins_loaded.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Creates or upgrades the table and records the version.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta format: one column per line, two spaces after PRIMARY KEY, KEY names given.
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
bucket char(64) NOT NULL,
expires_at bigint(20) unsigned NOT NULL,
PRIMARY KEY  (id),
KEY bucket_expires (bucket,expires_at),
KEY expires_at (expires_at)
) {$charset};"
		);
		update_option( self::OPTION, self::VERSION, true );
	}
}
