<?php
/**
 * The log table.
 *
 * @package MailVitals
 */

namespace MailVitals\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and keeps the log table up to date.
 *
 * The log lives in its own table rather than an option because an option is
 * one row read whole on every request that touches it: it cannot be queried,
 * cannot be paged, and grows until it hurts. A table can be indexed, trimmed,
 * and read a page at a time.
 */
final class Schema {

	/**
	 * Option holding the installed schema version.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'mail_vitals_schema_version';

	/**
	 * Current schema version.
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * Full name of the log table, including the site's prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'mail_vitals_log';
	}

	/**
	 * Create or update the table if it is not already current.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or update the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		/*
		 * dbDelta is unusually particular about formatting: it compares this
		 * text against the live table, so the two spaces after PRIMARY KEY and
		 * the lower-case column types are load-bearing, not style.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sent_at datetime NOT NULL,
			status varchar(20) NOT NULL,
			transport varchar(20) NOT NULL,
			recipients text NOT NULL,
			subject text NOT NULL,
			error longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY sent_at (sent_at),
			KEY status_sent_at (status, sent_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Whether the table is actually present.
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Checking for a table cannot be done through the options API, and must not be cached.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Remove the table and the record of its version.
	 *
	 * Only ever called from uninstall. Deactivating a plugin should not throw
	 * away the history someone may still need to read.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table on uninstall has no API alternative.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( self::VERSION_OPTION );
	}
}
