<?php
/**
 * Handles the test message the settings screen offers.
 *
 * @package MailVitals
 */

namespace MailVitals\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one message using whatever the site is currently configured to use.
 *
 * Goes through wp_mail() rather than talking to a transport directly, so the
 * test exercises the same path a real message takes. A test that bypassed the
 * configuration would prove nothing about it.
 */
final class TestSend {

	/**
	 * Name of the admin-post action.
	 *
	 * @var string
	 */
	const ACTION = 'mail_vitals_test_send';

	/**
	 * Listen for the form submission.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Send the message and return to the settings screen with the outcome.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send test messages from this site.', 'mail-vitals' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';

		if ( ! is_email( $to ) ) {
			self::redirect( 'failed' );
		}

		$sent = wp_mail(
			$to,
			__( 'Mail Vitals test message', 'mail-vitals' ),
			sprintf(
				/* translators: %s: the site's name. */
				__( 'This message was sent from %s to confirm that its mail configuration works. Nothing else needs to be done with it.', 'mail-vitals' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			)
		);

		self::redirect( $sent ? 'sent' : 'failed' );
	}

	/**
	 * Go back to the settings screen carrying the outcome.
	 *
	 * @param string $result Either "sent" or "failed".
	 * @return void
	 */
	private static function redirect( $result ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => SettingsPage::SLUG,
					'mail-vitals-test' => $result,
				),
				admin_url( 'options-general.php' )
			)
		);

		exit;
	}
}
