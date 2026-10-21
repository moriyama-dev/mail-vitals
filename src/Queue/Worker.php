<?php
/**
 * Works through the retry queue.
 *
 * @package MailVitals
 */

namespace MailVitals\Queue;

use MailVitals\Log\Store;
use MailVitals\Mailer\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends queued messages again when they come due.
 */
final class Worker {

	/**
	 * Name of the scheduled event.
	 *
	 * @var string
	 */
	const EVENT = 'mail_vitals_process_queue';

	/**
	 * Name of the custom schedule this runs on.
	 *
	 * @var string
	 */
	const SCHEDULE = 'mail_vitals_five_minutes';

	/**
	 * Whether a queued message is being retried right now.
	 *
	 * @var bool
	 */
	private static $retrying = false;

	/**
	 * Attach the schedule and the handler.
	 *
	 * @return void
	 */
	public static function register() {
		/*
		 * The coding standard discourages intervals under fifteen minutes. This
		 * one is deliberate: the queue only holds messages that already failed on
		 * every route, and the handler does nothing at all when it is empty. A
		 * person waiting on a password reset is the reason for the shorter tick.
		 */
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );
		add_action( self::EVENT, array( self::class, 'run' ) );
	}

	/**
	 * Offer a five minute interval.
	 *
	 * @param array $schedules Schedules collected so far.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Mail Vitals)', 'mail-vitals' ),
		);

		return $schedules;
	}

	/**
	 * Put the recurring event in place.
	 *
	 * @return void
	 */
	public static function schedule() {
		/*
		 * Activation happens after plugins_loaded has already fired, so the
		 * filter offering this interval may not be in place yet. Without it
		 * wp_schedule_event() rejects the unknown schedule and fails silently,
		 * leaving the queue with nothing to work it.
		 */
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_filter( 'cron_schedules', array( self::class, 'add_schedule' ) );

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::EVENT );
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
	 * Whether the current send is a retry of something from the queue.
	 *
	 * Without this, a retry that fails again would be queued a second time and
	 * the queue would grow with duplicates of the same message.
	 *
	 * @return bool
	 */
	public static function is_retrying() {
		return self::$retrying;
	}

	/**
	 * Try every message that has come due.
	 *
	 * @return array Counts of what happened, keyed sent, requeued and abandoned.
	 */
	public static function run() {
		$result = array(
			'sent'      => 0,
			'requeued'  => 0,
			'abandoned' => 0,
		);

		foreach ( Queue::due() as $row ) {
			$payload = Queue::payload_of( $row );

			if ( null === $payload ) {
				Queue::remove( (int) $row['id'] );
				++$result['abandoned'];
				continue;
			}

			self::$retrying = true;

			try {
				$sent = wp_mail(
					$payload['to'],
					$payload['subject'],
					$payload['message'],
					$payload['headers'],
					$payload['attachments']
				);
			} finally {
				self::$retrying = false;
			}

			if ( $sent ) {
				Queue::remove( (int) $row['id'] );
				++$result['sent'];
				continue;
			}

			if ( Queue::defer( $row ) ) {
				++$result['requeued'];
				continue;
			}

			self::record_abandonment( $row );
			++$result['abandoned'];
		}

		return $result;
	}

	/**
	 * Say in the log that a message will not be tried again.
	 *
	 * Every individual attempt is already logged as it happens. This adds the
	 * conclusion, because "it failed four times" and "it has been given up on"
	 * are different facts and only the second one means the message is gone.
	 *
	 * @param array $row The queue row being abandoned.
	 * @return void
	 */
	private static function record_abandonment( array $row ) {
		$payload    = Queue::payload_of( $row );
		$recipients = '';

		if ( null !== $payload ) {
			$recipients = is_array( $payload['to'] ) ? implode( ', ', $payload['to'] ) : (string) $payload['to'];
		}

		Store::add(
			array(
				'status'     => Store::STATUS_FAILED,
				'transport'  => Dispatcher::current_id(),
				'recipients' => $recipients,
				'subject'    => (string) $row['subject'],
				'error'      => sprintf(
					/* translators: %d: number of attempts made. */
					__( 'Given up on after %d attempts over every configured route. The message was not delivered.', 'mail-vitals' ),
					Queue::MAX_ATTEMPTS
				),
			)
		);
	}
}
