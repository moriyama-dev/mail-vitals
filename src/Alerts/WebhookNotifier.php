<?php
/**
 * Sends alerts to any service that accepts a JSON POST.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Posts alerts as JSON to a URL of the site owner's choosing.
 *
 * The body has a fixed shape with a schema number, so a receiving system can
 * rely on it. When a secret is set, the body is signed with HMAC-SHA256 so
 * the receiver can check that it came from this site.
 */
final class WebhookNotifier implements Notifier {

	/**
	 * Version of the payload shape. Raise it when a field changes meaning or
	 * disappears; adding a field does not need it.
	 *
	 * @var int
	 */
	const SCHEMA = 1;

	/**
	 * Header carrying the signature.
	 *
	 * @var string
	 */
	const SIGNATURE_HEADER = 'X-Mail-Vitals-Signature';

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'webhook';
	}

	/**
	 * Name to show on the settings screen.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Webhook', 'mail-vitals' );
	}

	/**
	 * Constant that holds the URL.
	 *
	 * @return string
	 */
	public function constant() {
		return 'MAIL_VITALS_WEBHOOK_URL';
	}

	/**
	 * Whether a usable URL is set.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return Http::valid_url( Settings::secret( 'webhook_url' ) );
	}

	/**
	 * A webhook does not depend on the site's mail, so it takes every alert.
	 *
	 * @param Alert $alert Alert to send.
	 * @return bool
	 */
	public function accepts( Alert $alert ) {
		return true;
	}

	/**
	 * Never needed, since every alert is accepted.
	 *
	 * @return string
	 */
	public function refusal_reason() {
		return '';
	}

	/**
	 * Post the alert, signed when a secret is set.
	 *
	 * @param Alert $alert Alert to send.
	 * @return true|\WP_Error
	 */
	public function send( Alert $alert ) {
		$body    = wp_json_encode( self::payload( $alert ) );
		$headers = array( 'X-Mail-Vitals-Event' => $alert->type() );
		$secret  = Settings::secret( 'webhook_secret' );

		if ( '' !== $secret ) {
			$headers[ self::SIGNATURE_HEADER ] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		return Http::post_json( Settings::secret( 'webhook_url' ), $body, $headers );
	}

	/**
	 * The JSON body.
	 *
	 * @param Alert $alert Alert to send.
	 * @return array
	 */
	public static function payload( Alert $alert ) {
		return array(
			'schema'         => self::SCHEMA,
			'event'          => $alert->type(),
			'severity'       => $alert->severity(),
			'site'           => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'route'          => $alert->transport(),
			'cause'          => $alert->cause(),
			'code'           => $alert->code(),
			'failures'       => $alert->count(),
			'failures_total' => $alert->total(),
			'first_seen'     => $alert->first_seen() ? gmdate( 'c', $alert->first_seen() ) : null,
			'last_seen'      => $alert->last_seen() ? gmdate( 'c', $alert->last_seen() ) : null,
			'error'          => $alert->error(),
			'title'          => $alert->title(),
			'text'           => implode( "\n", $alert->lines() ),
			'plugin_version' => MAIL_VITALS_VERSION,
		);
	}
}
