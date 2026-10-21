<?php
/**
 * Delivery over SMTP.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

use PHPMailer\PHPMailer\PHPMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points the mail library at an SMTP server.
 *
 * Works through the phpmailer_init hook rather than by redefining wp_mail().
 * wp_mail() is a pluggable function, so two plugins that both redefine it do
 * not merge: whichever loads first wins and the other silently does nothing.
 * Configuring the library instead leaves that failure mode unavailable.
 */
final class SmtpTransport implements Transport {

	/**
	 * Stable identifier, matching the stored transport setting.
	 *
	 * @return string
	 */
	public function id() {
		return Settings::TRANSPORT_SMTP;
	}

	/**
	 * Whether a host has been configured to connect to.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$smtp = Settings::smtp();

		return '' !== $smtp['host'] && $smtp['port'] > 0;
	}

	/**
	 * Attach to the mail library's initialisation.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
	}

	/**
	 * Apply the SMTP settings to the mail library instance.
	 *
	 * @param PHPMailer $mailer The instance WordPress is about to send with.
	 * @return void
	 */
	public function configure( $mailer ) {
		if ( ! Dispatcher::is_current( $this ) ) {
			return;
		}

		$smtp = Settings::smtp();

		/*
		 * PHPMailer names its properties in StudlyCaps. The coding standard
		 * wants snake_case, but these are someone else's property names and
		 * cannot be spelled any other way, so the rule is suspended here only.
		 */
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$mailer->isSMTP();
		$mailer->Host = $smtp['host'];
		$mailer->Port = $smtp['port'];

		if ( 'none' === $smtp['encryption'] ) {
			$mailer->SMTPSecure  = '';
			$mailer->SMTPAutoTLS = false;
		} else {
			$mailer->SMTPSecure = $smtp['encryption'];
		}

		if ( '' !== $smtp['username'] && '' !== $smtp['password'] ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $smtp['username'];
			$mailer->Password = $smtp['password'];
		} else {
			$mailer->SMTPAuth = false;
		}

		/*
		 * A send that hangs is worse than one that fails, because the page
		 * hangs with it. Fifteen seconds is long enough for a slow relay and
		 * short enough that a dead one is noticed rather than waited on.
		 */
		$mailer->Timeout   = 15;
		$mailer->SMTPDebug = 0;

		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
