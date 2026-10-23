<?php
/**
 * Run log tables.
 *
 * @package Pegwork
 */

declare( strict_types=1 );

namespace PegworkAutomation;

/**
 * Creates and upgrades `{prefix}pegwork_runs` and `{prefix}pegwork_run_steps` with dbDelta, versioned by
 * the `pegwork_automation_db_version` option. Checked on every boot (one autoloaded option read), so
 * enabling the module and updating the plugin both install or upgrade. Dropped by modules/automation/uninstall.php.
 *
 * Versions: 1 (Phase 4); 2 (Automation v3 loops) adds the nullable `pegwork_run_steps.iteration`. dbDelta
 * only adds the column, so runs and step rows written under 1 (waiting runs included) are kept as they are.
 */
final class Schema {

	/**
	 * Current table schema version.
	 */
	public const VERSION = '2';

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'pegwork_automation_db_version';

	/**
	 * Runs table name.
	 */
	public static function runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'pegwork_runs';
	}

	/**
	 * Run steps table name.
	 */
	public static function steps(): string {
		global $wpdb;
		return $wpdb->prefix . 'pegwork_run_steps';
	}

	/**
	 * Installs or upgrades the tables when the stored version differs.
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
	 * Creates or upgrades both tables and records the version.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = self::runs();
		$steps   = self::steps();

		// dbDelta format: one column per line, two spaces after PRIMARY KEY, KEY names given.
		dbDelta(
			"CREATE TABLE {$runs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
automation varchar(64) NOT NULL,
trigger_type varchar(16) NOT NULL,
record_id bigint(20) unsigned DEFAULT NULL,
status varchar(16) NOT NULL,
current_step varchar(64) NOT NULL DEFAULT '',
resume_at datetime DEFAULT NULL,
depth tinyint(3) unsigned NOT NULL DEFAULT 0,
idempotency_key varchar(191) DEFAULT NULL,
definition_hash char(32) NOT NULL DEFAULT '',
context longtext NOT NULL,
error text DEFAULT NULL,
created_at datetime NOT NULL,
claimed_at datetime DEFAULT NULL,
started_at datetime DEFAULT NULL,
finished_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY status_resume (status,resume_at),
KEY automation_created (automation,created_at),
KEY record_id (record_id),
UNIQUE KEY automation_key (automation,idempotency_key)
) {$charset};
CREATE TABLE {$steps} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
step_id varchar(64) NOT NULL,
step_type varchar(32) NOT NULL,
attempt smallint(5) unsigned NOT NULL DEFAULT 1,
iteration smallint(5) unsigned DEFAULT NULL,
status varchar(16) NOT NULL,
started_at datetime NOT NULL,
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
summary text DEFAULT NULL,
error text DEFAULT NULL,
PRIMARY KEY  (id),
KEY run_id (run_id)
) {$charset};"
		);

		update_option( self::OPTION, self::VERSION, true );
	}
}
