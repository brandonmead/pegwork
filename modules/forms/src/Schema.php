<?php
/**
 * Entries table.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkForms;

/**
 * Creates and upgrades `{prefix}pegwork_entries` with dbDelta, versioned by the `pegwork_forms_db_version`
 * option. Checked on every boot (one autoloaded option read), so enabling the module and updating the
 * plugin both install or upgrade. Uninstall keeps the table: entries are content (Phase 1 rule).
 */
final class Schema {

	/**
	 * Current table schema version.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'pegwork_forms_db_version';

	/**
	 * Entries table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pegwork_entries';
	}

	/**
	 * Installs or upgrades the table when the stored version differs.
	 *
	 * @return bool Whether dbDelta ran.
	 */
	public static function maybe_install(): bool {
		if ( self::VERSION === get_option( self::OPTION ) ) {
			return false;
		}
		self::install();
		return true;
	}

	/**
	 * Creates or upgrades the table and records the version.
	 *
	 * Statuses: `received`, `spam`, plus `unconfirmed`, `processing` and `failed` from record mapping and
	 * confirmation. Times are UTC. `field_values` and `meta` are JSON.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		// dbDelta format: one column per line, two spaces after PRIMARY KEY, KEY names given.
		dbDelta(
			"CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
form_key varchar(64) NOT NULL,
status varchar(20) NOT NULL,
email varchar(254) NOT NULL DEFAULT '',
post_id bigint(20) unsigned DEFAULT NULL,
user_id bigint(20) unsigned DEFAULT NULL,
field_values longtext NOT NULL,
meta longtext NOT NULL,
token_hash char(64) DEFAULT NULL,
fingerprint char(64) NOT NULL,
created_at datetime NOT NULL,
confirmed_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY form_status_created (form_key,status,created_at),
KEY email (email(191)),
KEY post_id (post_id),
KEY fingerprint (fingerprint)
) {$charset};"
		);

		update_option( self::OPTION, self::VERSION, true );
	}
}
