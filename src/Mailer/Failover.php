<?php
/**
 * Tries the other transport when the first one fails.
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
 * Runs a send through the chosen transport, then the backup if it failed.
 *
 * Most delivery failures are not about the message. A blocked port, a refused
 * connection, a provider returning 502: all of them are properties of one
 * route, and all of them disappear on a different one. Trying the other route
 * immediately resolves the majority of failures without waiting for anything.
 *
 * Taking over at pre_wp_mail, rather than reacting to wp_mail_failed, is
 * deliberate: reacting after the fact cannot correct the value wp_mail() has
 * already decided to return, and a caller told the message failed will act on
 * that however well the backup did.
 */
final class Failover {

	/**
	 * Whether a send is already being orchestrated.
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
		add_filter( 'pre_wp_mail', array( self::class, 'orchestrate' ), 1, 2 );
	}

	/**
	 * Send through the chosen transport, falling back to the other on failure.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $atts          The arguments wp_mail() was called with.
	 * @return null|bool Null to let the normal path run, or the final result.
	 */
	public static function orchestrate( $short_circuit, $atts ) {
		if ( null !== $short_circuit || self::$running ) {
			return $short_circuit;
		}

		$primary = Dispatcher::active();
		$backup  = Dispatcher::backup();

		if ( null === $primary || null === $backup ) {
			return $short_circuit;
		}

		self::$running = true;

		try {
			$sent = self::attempt( $primary, $atts );

			if ( ! $sent ) {
				$sent = self::attempt( $backup, $atts );
			}

			/*
			 * Both routes refused it. That points at something broader than a
			 * single refused connection, so the message is set aside to be
			 * tried again later rather than retried hard against an outage.
			 * A message already being retried is not queued again, or the
			 * queue would fill with copies of itself.
			 */
			if ( ! $sent && ! Worker::is_retrying() ) {
				Queue::push( $atts );
			}
		} finally {
			self::$running = false;
		}

		return $sent;
	}

	/**
	 * Send once, through one named transport.
	 *
	 * @param Transport $transport Transport to send through.
	 * @param array     $atts      The arguments wp_mail() was called with.
	 * @return bool
	 */
	private static function attempt( Transport $transport, array $atts ) {
		Dispatcher::push( $transport );

		try {
			$sent = self::send_unfiltered( $atts );
		} finally {
			Dispatcher::pop();
		}

		return (bool) $sent;
	}

	/**
	 * Send the already-filtered arguments through wp_mail() unchanged.
	 *
	 * By the time this runs, wp_mail() has already applied its "wp_mail"
	 * filter to the arguments once. Calling wp_mail() again would apply them a
	 * second time, and a plugin that appends a header or rewrites a recipient
	 * would do it twice. Pinning the arguments at the lowest priority means
	 * whatever the filters produce on the second pass is discarded, so the
	 * retry is an attempt at the same message rather than a slightly
	 * different one.
	 *
	 * @param array $atts Already-filtered arguments.
	 * @return bool
	 */
	private static function send_unfiltered( array $atts ) {
		$pin = static function () use ( $atts ) {
			return $atts;
		};

		add_filter( 'wp_mail', $pin, PHP_INT_MAX );

		try {
			$sent = wp_mail(
				isset( $atts['to'] ) ? $atts['to'] : array(),
				isset( $atts['subject'] ) ? $atts['subject'] : '',
				isset( $atts['message'] ) ? $atts['message'] : '',
				isset( $atts['headers'] ) ? $atts['headers'] : '',
				isset( $atts['attachments'] ) ? $atts['attachments'] : array()
			);
		} finally {
			remove_filter( 'wp_mail', $pin, PHP_INT_MAX );
		}

		return (bool) $sent;
	}
}
