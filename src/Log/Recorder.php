<?php
/**
 * Writes every send attempt to the log.
 *
 * @package MailVitals
 */

namespace MailVitals\Log;

use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens for the outcome of each message and records it.
 *
 * Both outcomes arrive through core hooks, which the API transport raises for
 * itself. That keeps one recorder correct for every way the plugin can send,
 * instead of one per transport drifting apart.
 */
final class Recorder {

	/**
	 * Start listening.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_mail_succeeded', array( self::class, 'record_success' ) );
		add_action( 'wp_mail_failed', array( self::class, 'record_failure' ) );
	}

	/**
	 * Record a message that was accepted.
	 *
	 * @param array $mail_data The arguments wp_mail() was called with.
	 * @return void
	 */
	public static function record_success( $mail_data ) {
		if ( ! Settings::log_enabled() ) {
			return;
		}

		$mail_data = is_array( $mail_data ) ? $mail_data : array();

		Store::add(
			array(
				'status'     => Store::STATUS_SENT,
				'transport'  => self::transport_of( $mail_data ),
				'recipients' => self::recipients_of( $mail_data ),
				'subject'    => isset( $mail_data['subject'] ) ? (string) $mail_data['subject'] : '',
				'error'      => '',
			)
		);
	}

	/**
	 * Record a message that was refused, keeping the reason verbatim.
	 *
	 * @param \WP_Error $error Error raised by whichever transport failed.
	 * @return void
	 */
	public static function record_failure( $error ) {
		if ( ! is_wp_error( $error ) || ! Settings::log_enabled() ) {
			return;
		}

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		Store::add(
			array(
				'status'     => Store::STATUS_FAILED,
				'transport'  => self::transport_of( $data ),
				'recipients' => self::recipients_of( $data ),
				'subject'    => isset( $data['subject'] ) ? (string) $data['subject'] : '',
				'error'      => $error->get_error_message(),
			)
		);
	}

	/**
	 * Work out which transport was responsible.
	 *
	 * @param array $data Data accompanying the outcome.
	 * @return string
	 */
	private static function transport_of( array $data ) {
		if ( isset( $data['mail_vitals_transport'] ) ) {
			return (string) $data['mail_vitals_transport'];
		}

		return Settings::transport();
	}

	/**
	 * Reduce the recipient argument to a plain string.
	 *
	 * @param array $data Data accompanying the outcome.
	 * @return string
	 */
	private static function recipients_of( array $data ) {
		$to = isset( $data['to'] ) ? $data['to'] : array();

		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}

		return (string) $to;
	}
}
