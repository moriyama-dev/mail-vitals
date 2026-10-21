<?php
/**
 * Address handling shared by the transports.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads addresses out of the forms WordPress accepts them in.
 */
final class Address {

	/**
	 * The address the message will be sent from.
	 *
	 * Runs the value through the core filter so that the result matches what
	 * an SMTP send would use, keeping the two transports consistent.
	 *
	 * @return string
	 */
	public static function from_address() {
		$configured = Settings::from_address();
		$host       = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$fallback   = 'wordpress@' . ( is_string( $host ) ? $host : 'localhost' );

		/** This filter is documented in wp-includes/pluggable.php */
		return (string) apply_filters( 'wp_mail_from', '' !== $configured ? $configured : $fallback );
	}

	/**
	 * The display name the message will be sent under.
	 *
	 * @return string
	 */
	public static function from_name() {
		$configured = Settings::from_name();

		/** This filter is documented in wp-includes/pluggable.php */
		return (string) apply_filters( 'wp_mail_from_name', '' !== $configured ? $configured : 'WordPress' );
	}

	/**
	 * Convert recipients into the name/email pairs an API expects.
	 *
	 * Accepts both bare addresses and the "Name <address>" form.
	 *
	 * @param string|string[] $recipients Recipients as given to wp_mail().
	 * @return array[] One entry per valid recipient.
	 */
	public static function to_list( $recipients ) {
		if ( ! is_array( $recipients ) ) {
			$recipients = explode( ',', (string) $recipients );
		}

		$list = array();

		foreach ( $recipients as $recipient ) {
			$pair = self::split( (string) $recipient );

			if ( null !== $pair ) {
				$list[] = $pair;
			}
		}

		return $list;
	}

	/**
	 * Separate a recipient into its name and address.
	 *
	 * @param string $recipient One recipient, in either accepted form.
	 * @return array|null Null when no valid address could be read.
	 */
	private static function split( $recipient ) {
		$recipient = trim( $recipient );
		$name      = '';

		if ( preg_match( '/^(.*)<(.+)>$/', $recipient, $matches ) ) {
			$name      = trim( $matches[1], " \t\"'" );
			$recipient = trim( $matches[2] );
		}

		if ( ! is_email( $recipient ) ) {
			return null;
		}

		$pair = array( 'email' => $recipient );

		if ( '' !== $name ) {
			$pair['name'] = $name;
		}

		return $pair;
	}
}
