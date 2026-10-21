<?php
/**
 * Removes everything the plugin stored.
 *
 * Runs only when the plugin is deleted, never when it is merely deactivated.
 *
 * @package MailVitals
 */

// Called by WordPress during deletion; nothing else may run this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';

if ( ! defined( 'MAIL_VITALS_DIR' ) ) {
	define( 'MAIL_VITALS_DIR', plugin_dir_path( __FILE__ ) );
}

\MailVitals\Autoloader::register();
\MailVitals\Log\Pruner::unschedule();
\MailVitals\Log\Schema::drop();

delete_option( \MailVitals\Mailer\Settings::OPTION );
delete_option( 'mail_vitals_recent_failures' );
