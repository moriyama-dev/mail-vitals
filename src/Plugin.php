<?php
/**
 * Plugin bootstrap.
 *
 * @package MailVitals
 */

namespace MailVitals;

use MailVitals\Admin\SettingsPage;
use MailVitals\Admin\TestAlert;
use MailVitals\Admin\TestSend;
use MailVitals\Alerts\Monitor;
use MailVitals\Diagnostics\SiteHealth;
use MailVitals\Log\Pruner;
use MailVitals\Log\Schema;
use MailVitals\Queue\Worker;
use MailVitals\Mailer\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's components together.
 */
final class Plugin {

	/**
	 * Start the plugin.
	 *
	 * This stays deliberately small. Each component only attaches listeners
	 * here; the work itself waits for the hook that actually needs it, so that
	 * merely having the plugin active does not slow a page down.
	 *
	 * @return void
	 */
	public static function boot() {
		Schema::maybe_install();
		Pruner::register();
		Worker::register();

		Dispatcher::register();
		Monitor::register();
		SiteHealth::register();
		Abilities::register();

		if ( is_admin() ) {
			SettingsPage::register();
			TestSend::register();
			TestAlert::register();
		}
	}
}
