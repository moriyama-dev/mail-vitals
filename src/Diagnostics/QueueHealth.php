<?php
/**
 * Notices when queued messages have stopped moving.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

use MailVitals\Queue\Queue;
use MailVitals\Queue\Worker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that the retry queue is actually being worked through.
 *
 * The plugin asks nobody to configure a scheduler. WordPress runs scheduled
 * events off site traffic, which is enough for a site that has any, and a site
 * with none has nobody waiting for its mail either. The case that genuinely
 * breaks is a site that turned WP-Cron off and never put anything in its
 * place: messages then queue up and nothing ever moves them.
 *
 * That is worth detecting rather than demanding configuration against. This
 * check is the difference between a plugin that makes its problem the user's
 * and one that tells the user when there is a problem.
 */
final class QueueHealth {

	/**
	 * How overdue the queue may get before it is worth reporting, in seconds.
	 *
	 * @var int
	 */
	const TOLERANCE = 1800;

	/**
	 * Examine the queue and the scheduler.
	 *
	 * @return Finding
	 */
	public static function check() {
		$label = __( 'Retry queue', 'mail-vitals' );
		$depth = Queue::depth();

		if ( 0 === $depth ) {
			return new Finding(
				'retry_queue',
				$label,
				Finding::STATUS_GOOD,
				__( 'No messages are waiting to be retried.', 'mail-vitals' ),
				array( 'depth' => 0 )
			);
		}

		$oldest  = Queue::oldest_due();
		$overdue = null === $oldest ? 0 : max( 0, time() - $oldest );

		if ( $overdue > self::TOLERANCE ) {
			return new Finding(
				'retry_queue',
				$label,
				Finding::STATUS_CRITICAL,
				sprintf(
					/* translators: 1: number of messages waiting, 2: human-readable duration. */
					__( '%1$d messages are waiting to be retried, and the oldest has been due for %2$s. Scheduled events are not running, so nothing is going to send them. This usually means WP-Cron was switched off and nothing was set up in its place.', 'mail-vitals' ),
					$depth,
					human_time_diff( time() - $overdue )
				),
				array(
					'depth'             => $depth,
					'overdue_seconds'   => $overdue,
					'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
					'next_scheduled_at' => wp_next_scheduled( Worker::EVENT ),
				)
			);
		}

		return new Finding(
			'retry_queue',
			$label,
			Finding::STATUS_WARNING,
			sprintf(
				/* translators: %d: number of messages waiting. */
				__( '%d messages failed on every route and are waiting to be retried. They are moving; this is a sign the mail provider is having trouble, not that the queue is stuck.', 'mail-vitals' ),
				$depth
			),
			array( 'depth' => $depth )
		);
	}
}
