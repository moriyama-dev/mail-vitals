<?php
/**
 * Keeps the log from growing without end.
 *
 * @package MailVitals
 */

namespace MailVitals\Log;

use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trims the log on a schedule, by age and by size.
 *
 * A log nobody prunes is a slow outage: the table grows until backups, exports
 * and the admin screen all get slower, and by then it holds years of data
 * nobody will read. Two limits apply, because either alone leaves a hole. Age
 * alone lets a busy site store millions of rows inside the window; size alone
 * lets a quiet site keep entries from years ago.
 */
final class Pruner {

	/**
	 * Name of the scheduled event.
	 *
	 * @var string
	 */
	const EVENT = 'mail_vitals_prune_log';

	/**
	 * Attach the handler for the scheduled event.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::EVENT, array( self::class, 'prune' ) );
	}

	/**
	 * Put the recurring event in place.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	/**
	 * Take the recurring event away.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::EVENT );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::EVENT );
		}
	}

	/**
	 * Apply both limits.
	 *
	 * @return int How many entries were removed.
	 */
	public static function prune() {
		return self::prune_by_age() + self::prune_by_size();
	}

	/**
	 * Delete entries older than the configured number of days.
	 *
	 * @return int How many entries were removed.
	 */
	public static function prune_by_age() {
		global $wpdb;

		$days = Settings::log_retention_days();

		if ( $days <= 0 || ! Schema::exists() ) {
			return 0;
		}

		$table  = Schema::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trimming the plugin's own table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent_at < %s", $cutoff ) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Delete the oldest entries beyond the configured row limit.
	 *
	 * @return int How many entries were removed.
	 */
	public static function prune_by_size() {
		global $wpdb;

		$max = Settings::log_max_rows();

		if ( $max <= 0 || ! Schema::exists() ) {
			return 0;
		}

		$table = Schema::table();

		/*
		 * Find the id of the newest entry that is past the limit, then delete
		 * everything at or below it. One comparison on the primary key beats
		 * collecting thousands of ids to delete individually.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trimming the plugin's own table.
		$threshold = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $max ) );

		if ( null === $threshold ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trimming the plugin's own table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
