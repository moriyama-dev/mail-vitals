<?php
/**
 * Chooses which transport carries the site's mail.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

use MailVitals\Log\Recorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts exactly one transport in charge, and always records failures.
 */
final class Dispatcher {

	/**
	 * Start recording outcomes and hand sending to the configured transport.
	 *
	 * @return void
	 */
	public static function register() {
		Recorder::register();
		self::apply_from_overrides();

		$transport = self::active();

		if ( $transport instanceof Transport ) {
			$transport->register();
		}
	}

	/**
	 * The transport that should be used, when one is both chosen and usable.
	 *
	 * A transport that is selected but not configured is not used. Half
	 * configured delivery fails in ways that are much harder to read than
	 * simply leaving WordPress to its default.
	 *
	 * @return Transport|null
	 */
	public static function active() {
		$chosen = Settings::transport();

		foreach ( self::available() as $transport ) {
			if ( $transport->id() === $chosen && $transport->is_configured() ) {
				return $transport;
			}
		}

		return null;
	}

	/**
	 * Every transport this plugin implements.
	 *
	 * @return Transport[]
	 */
	public static function available() {
		return array(
			new SmtpTransport(),
			new BrevoTransport(),
		);
	}

	/**
	 * Make the configured From details take effect, when asked to.
	 *
	 * Registered late so that a site deliberately setting its own From address
	 * through the same filters keeps the last word unless overriding was the
	 * point of the setting.
	 *
	 * @return void
	 */
	private static function apply_from_overrides() {
		if ( ! Settings::force_from() ) {
			return;
		}

		if ( '' !== Settings::from_address() ) {
			add_filter( 'wp_mail_from', array( Settings::class, 'from_address' ), 99 );
		}

		if ( '' !== Settings::from_name() ) {
			add_filter( 'wp_mail_from_name', array( Settings::class, 'from_name' ), 99 );
		}
	}
}
