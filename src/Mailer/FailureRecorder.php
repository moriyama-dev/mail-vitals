<?php
/**
 * Keeps the reason a message failed.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records why sending failed, so the reason survives the attempt.
 *
 * A failed wp_mail() returns a bare false and throws the cause away. That one
 * is why "my site stopped sending email" is so often unanswerable, and it is
 * the gap this plugin exists to close.
 *
 * Message bodies are deliberately not kept. Everything useful for working out
 * why a send failed is in the envelope and the provider's reply; the body adds
 * only personal data to protect and rows to grow.
 */
final class FailureRecorder {

	/**
	 * Option holding the most recent failures.
	 *
	 * @var string
	 */
	const OPTION = 'mail_vitals_recent_failures';

	/**
	 * How many failures are kept.
	 *
	 * @var int
	 */
	const KEEP = 20;

	/**
	 * Start listening for failures.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_mail_failed', array( self::class, 'record' ) );
	}

	/**
	 * Store one failure.
	 *
	 * @param \WP_Error $error Error raised by whichever transport failed.
	 * @return void
	 */
	public static function record( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		$entry = array(
			'time'      => time(),
			'transport' => isset( $data['mail_vitals_transport'] ) ? (string) $data['mail_vitals_transport'] : Settings::transport(),
			'to'        => self::flatten( isset( $data['to'] ) ? $data['to'] : array() ),
			'subject'   => isset( $data['subject'] ) ? (string) $data['subject'] : '',
			'reason'    => $error->get_error_message(),
		);

		$recent = self::recent();
		array_unshift( $recent, $entry );

		update_option( self::OPTION, array_slice( $recent, 0, self::KEEP ), false );
	}

	/**
	 * The failures kept so far, newest first.
	 *
	 * @return array[]
	 */
	public static function recent() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Discard everything recorded.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Reduce a recipient argument to a plain comma-separated string.
	 *
	 * @param string|string[] $recipients Recipients as given to wp_mail().
	 * @return string
	 */
	private static function flatten( $recipients ) {
		if ( is_array( $recipients ) ) {
			return implode( ', ', array_map( 'strval', $recipients ) );
		}

		return (string) $recipients;
	}
}
