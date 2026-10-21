<?php
/**
 * Plugin Name:       Mail Vitals
 * Plugin URI:        https://github.com/moriyama-dev/mail-vitals
 * Description:       Finds out why email leaving WordPress does not arrive, and keeps a record of what happened to every message the site sends.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Yoshiro Moriyama
 * Author URI:        https://github.com/moriyama-dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mail-vitals
 * Domain Path:       /languages
 *
 * @package MailVitals
 */

namespace MailVitals;

// This file is a WordPress plugin and must never run on its own.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MAIL_VITALS_VERSION', '0.1.0' );
define( 'MAIL_VITALS_FILE', __FILE__ );
define( 'MAIL_VITALS_DIR', plugin_dir_path( __FILE__ ) );

require_once MAIL_VITALS_DIR . 'src/Autoloader.php';

Autoloader::register();

Lifecycle::register( __FILE__ );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
