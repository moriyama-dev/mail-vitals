<?php
/**
 * Takes charge of every message the plugin is responsible for.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

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

		$routes = self::routes();

		if ( empty( $routes ) ) {
			return $short_circuit;
		}

		self::$running = true;

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

		/*
		 * False even when the message was queued: it has not been delivered,
		 * and a caller that reports success to its user would be wrong.
		 */
		return $sent;
	}

	/**
	 * Routes to try, the active one first.
	 *
	 * @return Transport[]
	 */
	private static function routes() {
		$routes = array();
		$active = Dispatcher::active();

		if ( null === $active ) {
			return $routes;
		}

		$routes[] = $active;
		$backup   = Dispatcher::backup();

		if ( null !== $backup ) {
			$routes[] = $backup;
		}

		return $routes;
	}
}
