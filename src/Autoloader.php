<?php
/**
 * Class autoloader.
 *
 * @package MailVitals
 */

namespace MailVitals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps this plugin's class names onto files under src/.
 *
 * The plugin has no runtime Composer dependencies, so it resolves its own
 * classes instead of requiring a vendor directory to be shipped.
 */
final class Autoloader {

	/**
	 * Namespace prefix this autoloader is responsible for.
	 *
	 * @var string
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Hand the loader to PHP.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Require the file backing a class name this plugin owns.
	 *
	 * Class names outside the plugin's namespace are ignored so that other
	 * autoloaders keep their turn.
	 *
	 * @param string $class_name Fully qualified class name being resolved.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = MAIL_VITALS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
