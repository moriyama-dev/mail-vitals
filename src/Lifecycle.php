<?php
/**
 * What happens when the plugin is switched on and off.
 *
 * @package MailVitals
 */

namespace MailVitals;

use MailVitals\Log\Pruner;
use MailVitals\Log\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets the plugin up on activation and stands it down on deactivation.
 *
 * Deactivation stops the plugin working; it does not destroy anything. The log
 * is the record of what the site has sent, and someone turning the plugin off
 * to test something should not lose it. Removing data belongs in uninstall,
 * which is the deliberate act of saying the plugin is not coming back.
 */
final class Lifecycle {

	/**
	 * Register the hooks WordPress calls at those moments.
	 *
	 * @param string $plugin_file Main plugin file.
	 * @return void
	 */
	public static function register( $plugin_file ) {
		register_activation_hook( $plugin_file, array( self::class, 'activate' ) );
		register_deactivation_hook( $plugin_file, array( self::class, 'deactivate' ) );
	}

	/**
	 * Prepare storage and start the routine maintenance.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();
		Pruner::schedule();
	}

	/**
	 * Stop the routine maintenance, and leave the data alone.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Pruner::unschedule();
	}
}
