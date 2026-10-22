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
 * Alert channels point at a reserved domain, and nothing is allowed to reach
 * it: a test that forgets to intercept a request fails instead of posting.
 */
define( 'MAIL_VITALS_SLACK_WEBHOOK_URL', 'https://hooks.example.test/slack' );
define( 'MAIL_VITALS_WEBHOOK_URL', 'https://hooks.example.test/webhook' );
define( 'MAIL_VITALS_WEBHOOK_SECRET', 'test-secret' );

require_once $mail_vitals_tests_dir . '/includes/functions.php';

tests_add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		if ( false === $response && false !== strpos( (string) $url, '.example.test' ) ) {
			return new WP_Error( 'mail_vitals_tests_offline', 'Tests do not make network requests.' );
		}

		return $response;
	},
	PHP_INT_MAX,
	3
);

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/mail-vitals.php';
	}
);

require $mail_vitals_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/RecordingChannel.php';
