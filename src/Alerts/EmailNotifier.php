<?php
/**
 * Sends alerts by email.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

use MailVitals\Mailer\Delivery;
use MailVitals\Mailer\Dispatcher;
use MailVitals\Mailer\Settings;
use MailVitals\Mailer\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails alerts, but never over the route that is failing.
 *
 * An email saying that mail is not getting through, sent over the route that
 * is not getting through, would never arrive, and would make a second failure
 * out of the first. So a failure on one route is only emailed over the other
 * one. A site with a single route cannot be told about its failures by email
 * at all, and the settings screen says so.
 *
 * The same goes for a message the retry queue gave up on: every route had
 * just refused it, so none of them is known to work.
 *
 * The recipients are not a credential, but they are read from wp-config.php
 * like the other channels so that every channel is switched on the same way.
 */
final class EmailNotifier implements Notifier {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'email';
	}

	/**
	 * Name to show on the settings screen.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Email', 'mail-vitals' );
	}

	/**
	 * Constant that holds the recipients.
	 *
	 * @return string
	 */
	public function constant() {
		return 'MAIL_VITALS_ALERT_EMAIL';
	}

	/**
	 * Whether at least one valid address is set.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( self::recipients() );
	}

	/**
	 * Whether some route other than the failing one can carry this alert.
	 *
	 * @param Alert $alert Alert to send.
	 * @return bool
	 */
	public function accepts( Alert $alert ) {
		return null !== self::route_for( $alert );
	}

	/**
	 * Send the alert over a route it is not about.
	 *
	 * @param Alert $alert Alert to send.
	 * @return true|\WP_Error
	 */
	public function send( Alert $alert ) {
		$route = self::route_for( $alert );

		if ( null === $route ) {
			return new \WP_Error( 'mail_vitals_alert_no_route', $this->refusal_reason() );
		}

		$sent = Delivery::send_through(
			$route,
			array(
				'to'          => self::recipients(),
				'subject'     => $alert->title(),
				'message'     => implode( "\n\n", $alert->lines() ) . "\n",
				'headers'     => array( 'X-Mail-Vitals-Event: ' . $alert->type() ),
				'attachments' => array(),
			)
		);

		if ( ! $sent ) {
			return new \WP_Error(
				'mail_vitals_alert_refused',
				sprintf(
					/* translators: %s: name of the mail route, such as "SMTP". */
					__( 'The alert email was refused by %s. The reason is in the send log.', 'mail-vitals' ),
					Alert::route_label( $route->id() )
				)
			);
		}

		return true;
	}

	/**
	 * Why an alert could not be emailed.
	 *
	 * @return string
	 */
	public function refusal_reason() {
		return __( 'Email alerts are only sent over a route other than the one that is failing, and no other route is configured.', 'mail-vitals' );
	}

	/**
	 * The route an alert can be emailed over.
	 *
	 * The active route comes first, so that a failure the backup is covering
	 * is reported over the backup, and anything else over the usual route.
	 *
	 * @param Alert $alert Alert to send.
	 * @return Transport|null Null when every route is the one in trouble.
	 */
	public static function route_for( Alert $alert ) {
		$reports_failure = in_array( $alert->type(), array( Alert::FAILING, Alert::STILL_FAILING ), true );

		if ( $reports_failure && CircuitBreaker::QUEUE === $alert->transport() ) {
			return null;
		}

		foreach ( Dispatcher::routes() as $route ) {
			if ( $reports_failure && $route->id() === $alert->transport() ) {
				continue;
			}

			return $route;
		}

		return null;
	}

	/**
	 * Valid addresses from the constant, which may list several separated by
	 * commas.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$addresses = array_map( 'trim', explode( ',', Settings::secret( 'alert_email' ) ) );

		return array_values( array_filter( $addresses, 'is_email' ) );
	}
}
