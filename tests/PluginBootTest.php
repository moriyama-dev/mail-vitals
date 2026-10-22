<?php
/**
 * The plugin loads, and with nothing configured stays out of the way.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests;

use MailVitals\Mailer\Delivery;
use MailVitals\Mailer\Dispatcher;
use MailVitals\Mailer\Settings;

/**
 * Smoke test for the test setup itself.
 */
final class PluginBootTest extends \WP_UnitTestCase {

	/**
	 * The plugin's code is loaded.
	 *
	 * @return void
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'MAIL_VITALS_VERSION' ) );
		$this->assertTrue( class_exists( Dispatcher::class ) );
	}

	/**
	 * Without a configured route, sending is left to WordPress entirely.
	 *
	 * @return void
	 */
	public function test_default_route_does_not_take_over_sending() {
		$this->assertSame( Settings::TRANSPORT_DEFAULT, Settings::transport() );
		$this->assertNull( Dispatcher::active() );
		$this->assertFalse( has_filter( 'pre_wp_mail', array( Delivery::class, 'handle' ) ) );
	}
}
