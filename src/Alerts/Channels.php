<?php
/**
 * The channels alerts can be sent to.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the alert channels this plugin implements.
 */
final class Channels {

	/**
	 * Every channel, configured or not.
	 *
	 * @return Notifier[]
	 */
	public static function all() {
		return array(
			new SlackNotifier(),
			new WebhookNotifier(),
		);
	}

	/**
	 * Channels that have what they need to send.
	 *
	 * @return Notifier[]
	 */
	public static function configured() {
		return array_values(
			array_filter(
				self::all(),
				static function ( Notifier $notifier ) {
					return $notifier->is_configured();
				}
			)
		);
	}
}
