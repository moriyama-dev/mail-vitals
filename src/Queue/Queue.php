<?php
/**
 * Messages waiting to be tried again.
 *
 * @package MailVitals
 */

namespace MailVitals\Queue;

use MailVitals\Log\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds messages that failed on every route, until they succeed or are given up on.
 *
 * This is working storage, not history. Each row carries the message body,
 * because a message cannot be sent again without it, and each row is deleted
 * the moment the message is delivered or abandoned. The log, which is kept
 * indefinitely, has no column for a body at all.
 */
final class Queue {

	/**
	 * Attempts made before a message is given up on.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * How long to wait before each attempt, in seconds.
	 *
	 * Spaced out rather than rapid, because a message only reaches the queue
	 * after failing on every route, and that means something broader than one
	 * refused connection. Retrying hard against an outage helps nobody.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 300, 1800, 7200, 21600 );

	/**
	 * Put a message in the queue.
	 *
	 * @param array $atts The arguments wp_mail() was called with.
	 * @return bool Whether it was stored.
	 */
	public static function push( array $atts ) {
		global $wpdb;

		$table = Schema::queue_table();

		if ( ! Schema::exists( $table ) ) {
			return false;
		}

		$payload = wp_json_encode(
			array(
				'to'          => isset( $atts['to'] ) ? $atts['to'] : array(),
				'subject'     => isset( $atts['subject'] ) ? $atts['subject'] : '',
				'message'     => isset( $atts['message'] ) ? $atts['message'] : '',
				'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : '',
				'attachments' => isset( $atts['attachments'] ) ? $atts['attachments'] : array(),
			)
		);

		if ( false === $payload ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to the plugin's own table.
		$written = $wpdb->insert(
			$table,
			array(
				'queued_at'       => current_time( 'mysql', true ),
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + self::BACKOFF[0] ),
				'attempts'        => 0,
				'subject'         => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
				'payload'         => $payload,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $written;
	}

	/**
	 * Messages that are due to be tried again.
	 *
	 * @param int $limit Most to return at once.
	 * @return array[]
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;

		$table = Schema::queue_table();

		if ( ! Schema::exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 100, (int) $limit ) );
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE next_attempt_at <= %s ORDER BY next_attempt_at ASC LIMIT %d",
				$now,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Note a failed attempt and work out when to try next.
	 *
	 * @param array $row The queue row that was just attempted.
	 * @return bool Whether the message stays in the queue.
	 */
	public static function defer( array $row ) {
		global $wpdb;

		$attempts = (int) $row['attempts'] + 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::remove( (int) $row['id'] );

			return false;
		}

		$wait = isset( self::BACKOFF[ $attempts ] ) ? self::BACKOFF[ $attempts ] : end( self::BACKOFF );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating the plugin's own table.
		$wpdb->update(
			Schema::queue_table(),
			array(
				'attempts'        => $attempts,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $wait ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Take a message out of the queue.
	 *
	 * @param int $id Row to remove.
	 * @return void
	 */
	public static function remove( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from the plugin's own table.
		$wpdb->delete( Schema::queue_table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Read the message back out of a queue row.
	 *
	 * @param array $row Queue row.
	 * @return array|null Null when the stored payload cannot be read.
	 */
	public static function payload_of( array $row ) {
		$payload = json_decode( (string) $row['payload'], true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Messages waiting, the next to be tried first, for the settings screen.
	 *
	 * The stored message is read only for its recipients. The body never
	 * leaves this method, so the screen has no way to show it.
	 *
	 * @param int $limit Most to return.
	 * @return array[] Each with id, queued_at, next_attempt_at (Unix times),
	 *                 attempts, subject and recipients.
	 */
	public static function waiting( $limit = 25 ) {
		global $wpdb;

		$table = Schema::queue_table();

		if ( ! Schema::exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 100, (int) $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ) {
				$payload = self::payload_of( $row );
				$to      = null !== $payload && isset( $payload['to'] ) ? $payload['to'] : '';

				return array(
					'id'              => (int) $row['id'],
					'queued_at'       => (int) strtotime( $row['queued_at'] . ' UTC' ),
					'next_attempt_at' => (int) strtotime( $row['next_attempt_at'] . ' UTC' ),
					'attempts'        => (int) $row['attempts'],
					'subject'         => (string) $row['subject'],
					'recipients'      => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
					'readable'        => null !== $payload,
				);
			},
			$rows
		);
	}

	/**
	 * How many messages are waiting.
	 *
	 * @return int
	 */
	public static function depth() {
		global $wpdb;

		$table = Schema::queue_table();

		if ( ! Schema::exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counting rows in the plugin's own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The oldest moment a queued message has been waiting to be retried since.
	 *
	 * @return int|null Unix time, or null when nothing is overdue.
	 */
	public static function oldest_due() {
		global $wpdb;

		$table = Schema::queue_table();

		if ( ! Schema::exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading the plugin's own table.
		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(next_attempt_at) FROM {$table} WHERE next_attempt_at <= %s", current_time( 'mysql', true ) ) );

		return null === $oldest ? null : (int) strtotime( $oldest . ' UTC' );
	}
}
