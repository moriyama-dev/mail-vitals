<?php
/**
 * Takes charge of every message the plugin is responsible for.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

use MailVitals\Alerts\Monitor;
use MailVitals\Queue\Queue;
use MailVitals\Queue\Worker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends each message over the configured routes, and keeps it for a later
 * retry when none of them accepted it.
 *
 * Keeping a message is not tied to having a backup route. Most sites have a
 * single route, and a message that route refused deserves a retry just as
 * much as one that two routes refused. Switching routes is Failover's job;
 * deciding that a message has failed for now and must not be lost is this
 * class's.
 *
 * Taking over at pre_wp_mail, rather than reacting to wp_mail_failed, is
 * deliberate: reacting after the fact cannot correct the value wp_mail() has
 * already decided to return, and a caller told the message failed will act on
 * that however well a backup route did.
 *
 * With no route configured the plugin leaves sending to WordPress entirely,
 * and this class is not registered at all.
 */
final class Delivery {

	/**
	 * Whether a send is already being handled, so that the inner wp_mail()
	 * calls made for each route are let through.
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * Take charge of sending.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'pre_wp_mail', array( self::class, 'handle' ), 1, 2 );
	}

	/**
	 * Whether a message is being worked through the routes right now.
	 *
	 * @return bool
	 */
	public static function is_handling() {
		return self::$running;
	}

	/**
	 * Send over the active route and its backup, then keep the message if
	 * both refused it.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $atts          The arguments wp_mail() was called with.
	 * @return null|bool Null to let the normal path run, or the final result.
	 */
	public static function handle( $short_circuit, $atts ) {
		if ( null !== $short_circuit || self::$running ) {
			return $short_circuit;
		}

		$routes = Dispatcher::routes();

		if ( empty( $routes ) ) {
			return $short_circuit;
		}

		self::$running = true;
		$sent          = false;

		try {
			$sent = Failover::send( $routes, is_array( $atts ) ? $atts : array() );

			/*
			 * Every route refused it. The message is set aside to be tried
			 * again later rather than retried hard against an outage. A
			 * message already being retried is not queued again, or the queue
			 * would fill with copies of itself.
			 */
			if ( ! $sent && ! Worker::is_retrying() ) {
				Queue::push( is_array( $atts ) ? $atts : array() );
			}
		} finally {
			self::$running = false;
		}

		// Only now is it known whether a failed route cost the message.
		Monitor::settle( $sent );

		/*
		 * False even when the message was queued: it has not been delivered,
		 * and a caller that reports success to its user would be wrong.
		 */
		return $sent;
	}

	/**
	 * Send over one route only, outside the usual handling.
	 *
	 * For the plugin's own alert email, which must travel over a route other
	 * than the one it reports on. It is not switched to the backup, not
	 * queued if refused, and not settled with the alert monitor: an alert
	 * that failed to send is reported to whoever asked for it, not turned
	 * into a new alert.
	 *
	 * @param Transport $route Route to send over.
	 * @param array     $atts  The arguments for wp_mail().
	 * @return bool Whether the route accepted the message.
	 */
	public static function send_through( Transport $route, array $atts ) {
		$was_running   = self::$running;
		self::$running = true;

		try {
			return Failover::send( array( $route ), $atts );
		} finally {
			self::$running = $was_running;
		}
	}
}
