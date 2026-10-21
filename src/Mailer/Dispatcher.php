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
 * Decides which transport is in charge, and lets that change mid-request.
 *
 * Both the chosen transport and its backup attach their hooks at boot, and
 * each one checks whether it is currently in charge before acting. Swapping a
 * single value is a great deal safer than adding and removing hooks part-way
 * through a send.
 */
final class Dispatcher {

	/**
	 * The transport currently responsible for sending.
	 *
	 * @var Transport|null
	 */
	private static $current = null;

	/**
	 * Transports displaced by a temporary switch, newest last.
	 *
	 * @var array
	 */
	private static $stack = array();

	/**
	 * Start recording outcomes and put the configured transport in charge.
	 *
	 * @return void
	 */
	public static function register() {
		Recorder::register();
		self::apply_from_overrides();

		self::$current = self::active();

		if ( null === self::$current ) {
			return;
		}

		self::$current->register();

		$backup = self::backup();

		if ( null !== $backup ) {
			$backup->register();
			Failover::register();
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
	 * The transport to fall back on when the chosen one fails.
	 *
	 * Chosen rather than configured: whichever other transport already has
	 * what it needs becomes the backup. Asking for a second, separate set of
	 * backup settings would double the screen to describe something the site
	 * has usually already told us.
	 *
	 * @return Transport|null
	 */
	public static function backup() {
		$active = self::active();

		if ( null === $active ) {
			return null;
		}

		foreach ( self::available() as $transport ) {
			if ( $transport->id() !== $active->id() && $transport->is_configured() ) {
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
	 * Whether a transport is the one currently in charge.
	 *
	 * @param Transport $transport Transport asking whether it should act.
	 * @return bool
	 */
	public static function is_current( Transport $transport ) {
		return null !== self::$current && self::$current->id() === $transport->id();
	}

	/**
	 * Identifier of the transport acting right now.
	 *
	 * Not the same as the stored setting. During a failover the backup is in
	 * charge, and the log has to say so: "it went out on the backup again" is
	 * the signal that the primary needs attention.
	 *
	 * @return string
	 */
	public static function current_id() {
		return null !== self::$current ? self::$current->id() : Settings::transport();
	}

	/**
	 * Put a different transport in charge until it is handed back.
	 *
	 * @param Transport $transport Transport to put in charge.
	 * @return void
	 */
	public static function push( Transport $transport ) {
		self::$stack[] = self::$current;
		self::$current = $transport;
	}

	/**
	 * Hand charge back to whichever transport held it before.
	 *
	 * @return void
	 */
	public static function pop() {
		if ( empty( self::$stack ) ) {
			return;
		}

		self::$current = array_pop( self::$stack );
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
