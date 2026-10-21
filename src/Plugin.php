<?php
/**
 * Plugin bootstrap.
 *
 * @package MailVitals
 */

namespace MailVitals;

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
	 * This stays deliberately small. Anything that costs real time belongs in
	 * the component that needs it, deferred to the hook that actually uses it,
	 * so that merely having the plugin active does not slow a page down.
	 *
	 * @return void
	 */
	public static function boot() {
		// Components are registered here as they are built.
	}
}
