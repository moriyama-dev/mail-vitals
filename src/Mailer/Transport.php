<?php
/**
 * Contract for the ways this plugin can deliver mail.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One way of getting a message off the server.
 *
 * Implementations differ in more than configuration. An SMTP transport lets
 * WordPress build and send the message and only adjusts the mail library; an
 * API transport takes the whole job over. Rather than hide that behind a
 * uniform send() that would fit neither, each transport attaches itself to the
 * hook that suits it.
 */
interface Transport {

	/**
	 * Stable identifier, matching the stored transport setting.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Whether this transport has enough configuration to be used.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Attach to whichever hook this transport works through.
	 *
	 * @return void
	 */
	public function register();
}
