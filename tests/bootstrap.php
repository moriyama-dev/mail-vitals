<?php
/**
 * PHPUnit bootstrap. Set the suite up first with bin/install-wp-tests.sh.
 *
 * @package MailVitals
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Plain CLI output before WordPress loads.

$mail_vitals_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $mail_vitals_tests_dir ) {
	$mail_vitals_tests_dir = getenv( 'HOME' ) . '/.cache/mail-vitals-wp-tests/current/tests-lib';
}

if ( ! file_exists( $mail_vitals_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "The WordPress test library is missing at {$mail_vitals_tests_dir}. Run bin/install-wp-tests.sh first.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

/*
 * Every channel and route is configured, pointing at reserved names, and no
 * request leaves the machine: a test that forgets to intercept one fails
 * instead of posting. Constants cannot be undefined, so tests choose what is
 * active through the settings and Monitor::use_channels().
 */
define( 'MAIL_VITALS_SLACK_WEBHOOK_URL', 'https://hooks.example.test/slack' );
define( 'MAIL_VITALS_WEBHOOK_URL', 'https://hooks.example.test/webhook' );
define( 'MAIL_VITALS_WEBHOOK_SECRET', 'test-secret' );
define( 'MAIL_VITALS_BREVO_API_KEY', 'test-key' );

require_once $mail_vitals_tests_dir . '/includes/functions.php';

tests_add_filter(
	'pre_http_request',
	static function ( $response ) {
		if ( false === $response ) {
			return new WP_Error( 'mail_vitals_tests_offline', 'Tests do not make network requests.' );
		}

		return $response;
	},
	PHP_INT_MAX
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/mail-vitals.php';
	}
);

require $mail_vitals_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/RecordingChannel.php';
