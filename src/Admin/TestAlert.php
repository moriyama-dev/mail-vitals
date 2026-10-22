<?php
/**
 * Handles the test alert the settings screen offers.
 *
 * @package MailVitals
 */

namespace MailVitals\Admin;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends one test alert to every configured channel.
 *
 * Goes through the same delivery as a real alert, so a test that arrives
 * proves real alerts will too.
 */
final class TestAlert {

	/**
	 * Name of the admin-post action.
	 *
	 * @var string
	 */
	const ACTION = 'mail_vitals_test_alert';

	/**
	 * Listen for the form submission.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Send the alert and return to the settings screen with the outcome.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to send test alerts from this site.', 'mail-vitals' ), 403 );
		}

		check_admin_referer( self::ACTION );

		if ( ! Monitor::channels() ) {
			self::redirect( 'none' );
		}

		$errors = Monitor::deliver( Alert::test( time() ) );

		self::redirect( null === $errors ? 'sent' : 'failed' );
	}

	/**
	 * Go back to the settings screen carrying the outcome.
	 *
	 * @param string $result "sent", "failed" or "none".
	 * @return void
	 */
	private static function redirect( $result ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => SettingsPage::SLUG,
					'mail-vitals-alert' => $result,
				),
				admin_url( 'options-general.php' )
			)
		);

		exit;
	}
}
