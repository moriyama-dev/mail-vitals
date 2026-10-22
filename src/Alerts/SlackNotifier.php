<?php
/**
 * Sends alerts to Slack.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts alerts to a Slack Incoming Webhook.
 *
 * The webhook URL is a credential: anyone who has it can post to the
 * channel. Like the other secrets it is read from wp-config.php only.
 */
final class SlackNotifier implements Notifier {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'slack';
	}

	/**
	 * Name to show on the settings screen.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Slack', 'mail-vitals' );
	}

	/**
	 * Constant that holds the webhook URL.
	 *
	 * @return string
	 */
	public function constant() {
		return 'MAIL_VITALS_SLACK_WEBHOOK_URL';
	}

	/**
	 * Whether a usable webhook URL is set.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return Http::valid_url( Settings::secret( 'slack_webhook_url' ), true );
	}

	/**
	 * Slack does not depend on the site's mail, so it takes every alert.
	 *
	 * @param Alert $alert Alert to send.
	 * @return bool
	 */
	public function accepts( Alert $alert ) {
		return true;
	}

	/**
	 * Post the alert.
	 *
	 * @param Alert $alert Alert to send.
	 * @return true|\WP_Error
	 */
	public function send( Alert $alert ) {
		return Http::post_json( Settings::secret( 'slack_webhook_url' ), wp_json_encode( self::payload( $alert ) ) );
	}

	/**
	 * The message Slack is given.
	 *
	 * @param Alert $alert Alert to send.
	 * @return array
	 */
	public static function payload( Alert $alert ) {
		$lines = array_merge( array( '*' . self::escape( $alert->title() ) . '*' ), array_map( array( self::class, 'escape' ), $alert->lines() ) );

		return array(
			'text'         => implode( "\n", $lines ),
			'unfurl_links' => false,
			'unfurl_media' => false,
		);
	}

	/**
	 * Escape the three characters Slack treats as markup.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function escape( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
	}
}
