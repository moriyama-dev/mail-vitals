<?php
/**
 * Tries the other transport when the first one fails.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a message over a list of routes, stopping at the first that takes it.
 *
 * Most delivery failures are not about the message. A blocked port, a refused
 * connection, a provider returning 502: all of them are properties of one
 * route, and all of them disappear on a different one. Trying the other route
 * immediately resolves the majority of failures without waiting for anything.
 *
 * This class only switches routes. What happens to a message that every
 * route refused is decided by Delivery, so that it applies just the same on
 * a site with a single route.
 */
final class Failover {

	/**
	 * Send through each transport in turn until one accepts the message.
	 *
	 * @param Transport[] $transports Transports to try, in order.
	 * @param array       $atts       The arguments wp_mail() was called with, already filtered.
	 * @return bool Whether any transport accepted the message.
	 */
	public static function send( array $transports, array $atts ) {
		foreach ( $transports as $transport ) {
			if ( self::attempt( $transport, $atts ) ) {
				return true;
			}
		}

		return false;
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
