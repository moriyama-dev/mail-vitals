<?php
/**
 * Contract for the places an alert can be sent.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One channel that can carry an alert to a person or a system.
 */
interface Notifier {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Name to show on the settings screen.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Constant in wp-config.php that switches this channel on.
	 *
	 * @return string
	 */
	public function constant();

	/**
	 * Whether this channel has what it needs to send.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Whether this channel may carry a particular alert. A channel that would
	 * travel over the route the alert is about can refuse it.
	 *
	 * @param Alert $alert Alert to send.
	 * @return bool
	 */
	public function accepts( Alert $alert );

	/**
	 * Send an alert.
	 *
	 * @param Alert $alert Alert to send.
	 * @return true|\WP_Error
	 */
	public function send( Alert $alert );
}
