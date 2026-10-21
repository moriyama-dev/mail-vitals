<?php
/**
 * Reading and writing the log.
 *
 * @package MailVitals
 */

namespace MailVitals\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only place that touches the log table.
 *
 * Message bodies are never accepted, let alone stored. Everything useful for
 * working out why a message did not arrive is in the envelope and the
 * provider's reply; the body would add only personal data to look after.
 */
final class Store {

	/**
	 * The message was accepted for delivery.
	 *
	 * @var string
	 */
	const STATUS_SENT = 'sent';

	/**
	 * The message was refused.
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Add one entry.
	 *
	 * @param array $entry Keys: status (a STATUS_* constant), transport, recipients,
	 *                     subject, and error (verbatim, empty on success). A message
	 *                     body is not accepted here and must not be passed.
	 * @return bool Whether the entry was stored.
	 */
	public static function add( array $entry ) {
		global $wpdb;

		if ( ! Schema::exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
		$written = $wpdb->insert(
			Schema::table(),
			array(
				'sent_at'    => current_time( 'mysql', true ),
				'status'     => substr( (string) $entry['status'], 0, 20 ),
				'transport'  => substr( (string) $entry['transport'], 0, 20 ),
				'recipients' => (string) $entry['recipients'],
				'subject'    => (string) $entry['subject'],
				'error'      => isset( $entry['error'] ) ? (string) $entry['error'] : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $written;
	}

	/**
	 * Read a page of entries, newest first.
	 *
	 * @param array $args Keys: status (a STATUS_* constant, or empty for both),
	 *                    limit, and offset.
	 * @return array[]
	 */
	public static function page( array $args = array() ) {
		global $wpdb;

		if ( ! Schema::exists() ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'limit'  => 25,
				'offset' => 0,
			)
		);

		$table  = Schema::table();
		$limit  = max( 1, min( 200, (int) $args['limit'] ) );
		$offset = max( 0, (int) $args['offset'] );

		/*
		 * The table name is built from the site's own prefix and cannot be
		 * influenced by a request, so interpolating it is safe. Every value
		 * still goes through prepare().
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' !== $args['status'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d",
					$args['status'],
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many entries are stored.
	 *
	 * @param string $status One of the STATUS_* constants, or empty for both.
	 * @return int
	 */
	public static function count( $status = '' ) {
		global $wpdb;

		if ( ! Schema::exists() ) {
			return 0;
		}

		$table = Schema::table();

		if ( '' !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting rows in the plugin's own table.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting rows in the plugin's own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete every entry.
	 *
	 * @return void
	 */
	public static function purge() {
		global $wpdb;

		if ( ! Schema::exists() ) {
			return;
		}

		$table = Schema::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Emptying the plugin's own table.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
