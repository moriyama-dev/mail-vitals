<?php
/**
 * Detecting other code that takes part in sending.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Diagnostics;

use MailVitals\Admin\SettingsPage;
use MailVitals\Diagnostics\Finding;
use MailVitals\Diagnostics\MailInterference;
use MailVitals\Diagnostics\SiteHealth;
use MailVitals\Mailer\BrevoTransport;
use MailVitals\Mailer\Delivery;
use MailVitals\Mailer\Settings;
use MailVitals\Mailer\SmtpTransport;
use MailVitals\Tests\Diagnostics\Fixtures\OtherMailer;

require_once __DIR__ . '/Fixtures/functions.php';
require_once __DIR__ . '/Fixtures/OtherMailer.php';

/**
 * Which callbacks are reported, who they are attributed to, and how serious
 * the result is.
 */
final class MailInterferenceTest extends \WP_UnitTestCase {

	/**
	 * Plugin paths WordPress knew before a test changed them.
	 *
	 * @var mixed
	 */
	private $plugin_paths;

	/**
	 * Start every test with nothing on the sending hooks and no route.
	 *
	 * Hooks are put back after each test by the test case itself.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->plugin_paths = isset( $GLOBALS['wp_plugin_paths'] ) ? $GLOBALS['wp_plugin_paths'] : null;

		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'phpmailer_init' );
		delete_option( Settings::OPTION );
	}

	/**
	 * Restore the plugin path mapping.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['wp_plugin_paths'] = $this->plugin_paths;

		parent::tear_down();
	}

	/**
	 * Absolute path of a fixture, normalised the way findings report it.
	 *
	 * @param string $name File name inside Fixtures/.
	 * @return string
	 */
	private function fixture( $name ) {
		return wp_normalize_path( __DIR__ . '/Fixtures/' . $name );
	}

	/**
	 * The path a finding shows for a file: relative to ABSPATH when inside it.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function shown( $file ) {
		$root = wp_normalize_path( ABSPATH );

		return 0 === strpos( $file, $root ) ? substr( $file, strlen( $root ) ) : $file;
	}

	/**
	 * Put the Brevo route in charge. Its key is defined by the test bootstrap.
	 *
	 * @return void
	 */
	private function activate_route() {
		update_option( Settings::OPTION, array( 'transport' => Settings::TRANSPORT_BREVO ) );
	}

	/**
	 * Attach Mail Vitals' own callbacks the way it does with routes set up.
	 *
	 * @return void
	 */
	private function attach_own_callbacks() {
		Delivery::register();
		( new BrevoTransport() )->register();
		( new SmtpTransport() )->register();
	}

	/**
	 * Nothing else on the hooks and wp_mail() untouched is good.
	 *
	 * @return void
	 */
	public function test_nothing_foreign_is_good() {
		$this->activate_route();
		$this->attach_own_callbacks();

		$finding = MailInterference::check();

		$this->assertSame( 'mail_interference', $finding->id() );
		$this->assertSame( Finding::STATUS_GOOD, $finding->status() );
		$this->assertSame( array(), $finding->detail()['callbacks'] );
		$this->assertFalse( $finding->detail()['wp_mail']['replaced'] );
	}

	/**
	 * A closure from other code on phpmailer_init is a warning while a route
	 * is in charge, with the hook, priority and file to find it by.
	 *
	 * @return void
	 */
	public function test_a_foreign_closure_on_phpmailer_init_is_a_warning() {
		$this->activate_route();
		$this->attach_own_callbacks();
		add_action( 'phpmailer_init', \MailVitals\Tests\Diagnostics\Fixtures\mailer_closure(), 20 );

		$finding   = MailInterference::check();
		$callbacks = $finding->detail()['callbacks'];

		$this->assertSame( Finding::STATUS_WARNING, $finding->status() );
		$this->assertCount( 1, $callbacks );
		$this->assertSame( 'phpmailer_init', $callbacks[0]['hook'] );
		$this->assertSame( 20, $callbacks[0]['priority'] );
		$this->assertSame( 'Closure', $callbacks[0]['callback'] );
		$this->assertSame( $this->shown( $this->fixture( 'functions.php' ) ), $callbacks[0]['file'] );
		$this->assertGreaterThan( 0, $callbacks[0]['line'] );
		$this->assertSame( 'after', $callbacks[0]['runs'] );
		$this->assertStringContainsString( 'can overwrite the SMTP server', $finding->summary() );
	}

	/**
	 * Mail Vitals' own callbacks are never reported.
	 *
	 * @return void
	 */
	public function test_own_callbacks_are_ignored() {
		$this->attach_own_callbacks();

		$this->assertSame( array(), MailInterference::foreign_callbacks() );
	}

	/**
	 * WordPress's own multisite callback is not other code.
	 *
	 * @return void
	 */
	public function test_core_callbacks_are_ignored() {
		add_action( 'phpmailer_init', 'fix_phpmailer_messageid' );

		$this->assertSame( array(), MailInterference::foreign_callbacks() );
	}

	/**
	 * A core helper put on pre_wp_mail by someone else stops every message,
	 * so it is reported even though its file is in WordPress.
	 *
	 * @return void
	 */
	public function test_a_core_helper_attached_by_other_code_is_reported() {
		add_filter( 'pre_wp_mail', '__return_false' );

		$callbacks = MailInterference::foreign_callbacks();

		$this->assertCount( 1, $callbacks );
		$this->assertSame( 'core', $callbacks[0]['owner_type'] );
		$this->assertSame( 'wp-includes/functions.php', $callbacks[0]['file'] );
	}

	/**
	 * Order against Mail Vitals' own callback is reported, because on these
	 * hooks it decides who wins.
	 *
	 * @return void
	 */
	public function test_order_relative_to_mail_vitals_is_reported() {
		$this->attach_own_callbacks();
		add_filter( 'pre_wp_mail', array( OtherMailer::class, 'short_circuit' ), 0 );
		add_filter( 'pre_wp_mail', array( OtherMailer::class, 'short_circuit' ), 5 );

		$callbacks = MailInterference::foreign_callbacks();

		$this->assertSame( array( 0, 5 ), wp_list_pluck( $callbacks, 'priority' ) );
		$this->assertSame( array( 'before', 'after' ), wp_list_pluck( $callbacks, 'runs' ) );
	}

	/**
	 * With no callback of ours on the hook, there is no order to report.
	 *
	 * @return void
	 */
	public function test_order_is_blank_without_mail_vitals_on_the_hook() {
		add_action( 'phpmailer_init', '\MailVitals\Tests\Diagnostics\Fixtures\configure_mailer' );

		$callbacks = MailInterference::foreign_callbacks();

		$this->assertSame( '', $callbacks[0]['runs'] );
	}

	/**
	 * Every shape of callback leads back to the file defining it.
	 *
	 * @return void
	 */
	public function test_every_callable_shape_resolves_to_its_file() {
		$object = new OtherMailer();
		$class  = $this->fixture( 'OtherMailer.php' );
		$func   = $this->fixture( 'functions.php' );

		$shapes = array(
			'function name'         => array( 'MailVitals\Tests\Diagnostics\Fixtures\configure_mailer', $func ),
			'Class::method string'  => array( OtherMailer::class . '::short_circuit', $class ),
			'object and method'     => array( array( $object, 'configure' ), $class ),
			'class and method'      => array( array( OtherMailer::class, 'short_circuit' ), $class ),
			'closure'               => array( \MailVitals\Tests\Diagnostics\Fixtures\mailer_closure(), $func ),
			'invokable object'      => array( $object, $class ),
			'method through __call' => array( array( $object, 'undeclared' ), $class ),
		);

		foreach ( $shapes as $shape => $case ) {
			$location = MailInterference::locate( $case[0] );

			$this->assertSame( $case[1], wp_normalize_path( $location['file'] ), $shape );
			$this->assertGreaterThan( 0, $location['line'], $shape );
		}
	}

	/**
	 * Readable names for each shape.
	 *
	 * @return void
	 */
	public function test_callbacks_get_readable_names() {
		$object = new OtherMailer();

		$this->assertSame( OtherMailer::class . '->configure', MailInterference::name_of( array( $object, 'configure' ) ) );
		$this->assertSame( OtherMailer::class . '::short_circuit', MailInterference::name_of( array( OtherMailer::class, 'short_circuit' ) ) );
		$this->assertSame( OtherMailer::class . '::__invoke', MailInterference::name_of( $object ) );
		$this->assertSame( 'Closure', MailInterference::name_of( static function () {} ) );
		$this->assertSame( 'strlen', MailInterference::name_of( 'strlen' ) );
	}

	/**
	 * Callbacks that cannot be traced come back empty instead of failing,
	 * and are reported as unidentified.
	 *
	 * @return void
	 */
	public function test_an_unresolvable_callback_does_not_fail() {
		$unresolvable = array(
			'mail_vitals_no_such_function',
			'Mail_Vitals_No_Such_Class::send',
			array( 'Mail_Vitals_No_Such_Class', 'send' ),
			array( 1, 2, 3 ),
			42,
			'strlen',
		);

		foreach ( $unresolvable as $callback ) {
			$this->assertSame( '', MailInterference::locate( $callback )['file'] );
		}

		add_filter( 'pre_wp_mail', 'mail_vitals_no_such_function' );

		$callbacks = MailInterference::foreign_callbacks();

		$this->assertCount( 1, $callbacks );
		$this->assertSame( 'unknown', $callbacks[0]['owner_type'] );
		$this->assertSame( '', $callbacks[0]['file'] );
	}

	/**
	 * Files are attributed by where they live, with the folder name standing
	 * in when there is no header to read.
	 *
	 * @return void
	 */
	public function test_files_are_attributed_by_location() {
		$plugin = MailInterference::owner_of( WP_PLUGIN_DIR . '/acme-mailer/includes/class-acme.php' );
		$this->assertSame( 'plugin', $plugin['type'] );
		$this->assertSame( 'acme-mailer', $plugin['slug'] );
		$this->assertSame( 'acme-mailer', $plugin['name'] );
		$this->assertSame( $this->shown( wp_normalize_path( WP_PLUGIN_DIR . '/acme-mailer/includes/class-acme.php' ) ), $plugin['file'] );

		$single = MailInterference::owner_of( WP_PLUGIN_DIR . '/solo-mailer.php' );
		$this->assertSame( 'plugin', $single['type'] );
		$this->assertSame( 'solo-mailer.php', $single['slug'] );

		$mu = MailInterference::owner_of( WPMU_PLUGIN_DIR . '/site-mail.php' );
		$this->assertSame( 'mu-plugin', $mu['type'] );
		$this->assertSame( 'site-mail.php', $mu['slug'] );

		$theme = MailInterference::owner_of( get_theme_root() . '/no-such-theme/functions.php' );
		$this->assertSame( 'theme', $theme['type'] );
		$this->assertSame( 'no-such-theme', $theme['slug'] );
		$this->assertSame( 'no-such-theme', $theme['name'] );

		$elsewhere = MailInterference::owner_of( ABSPATH . 'custom/mail-snippet.php' );
		$this->assertSame( 'other', $elsewhere['type'] );
		$this->assertSame( 'custom/mail-snippet.php', $elsewhere['file'] );

		$outside = MailInterference::owner_of( '/opt/shared/mail-snippet.php' );
		$this->assertSame( 'other', $outside['type'] );
		$this->assertSame( '/opt/shared/mail-snippet.php', $outside['file'] );

		$this->assertSame( 'core', MailInterference::owner_of( ABSPATH . WPINC . '/functions.php' )['type'] );
		$this->assertSame( 'unknown', MailInterference::owner_of( '' )['type'] );
	}

	/**
	 * Names come from the plugin or theme header when there is one.
	 *
	 * @return void
	 */
	public function test_names_come_from_headers() {
		$theme = MailInterference::owner_of( get_stylesheet_directory() . '/functions.php' );
		$this->assertSame( wp_get_theme()->get( 'Name' ), $theme['name'] );

		$plugin = MailInterference::owner_of( WP_PLUGIN_DIR . '/hello.php' );
		$this->assertSame( 'Hello Dolly', $plugin['name'] );
	}

	/**
	 * A symlinked plugin is recognised by the folder WordPress loaded it from.
	 *
	 * @return void
	 */
	public function test_a_symlinked_plugin_is_recognised() {
		$GLOBALS['wp_plugin_paths'] = array( wp_normalize_path( WP_PLUGIN_DIR . '/linked-mailer' ) => '/srv/checkouts/linked-mailer' );

		$owner = MailInterference::owner_of( '/srv/checkouts/linked-mailer/linked-mailer.php' );

		$this->assertSame( 'plugin', $owner['type'] );
		$this->assertSame( 'linked-mailer', $owner['slug'] );
	}

	/**
	 * How serious each combination is.
	 *
	 * @return void
	 */
	public function test_severity_decision() {
		$phpmailer = array( array( 'hook' => 'phpmailer_init' ) );
		$pre       = array( array( 'hook' => 'pre_wp_mail' ) );

		$this->assertSame( Finding::STATUS_CRITICAL, MailInterference::decide( true, true, array() ) );
		$this->assertSame( Finding::STATUS_WARNING, MailInterference::decide( true, false, array() ) );
		$this->assertSame( Finding::STATUS_WARNING, MailInterference::decide( false, true, $phpmailer ) );
		$this->assertSame( Finding::STATUS_WARNING, MailInterference::decide( false, true, $pre ) );
		$this->assertSame( Finding::STATUS_WARNING, MailInterference::decide( false, false, $pre ) );
		$this->assertSame( Finding::STATUS_GOOD, MailInterference::decide( false, false, $phpmailer ) );
		$this->assertSame( Finding::STATUS_GOOD, MailInterference::decide( false, false, array() ) );
	}

	/**
	 * A wp_mail() from a plugin is critical while a route is in charge, and
	 * the finding says where it came from.
	 *
	 * @return void
	 */
	public function test_a_replaced_wp_mail_is_critical_with_a_route() {
		$finding = MailInterference::build( WP_PLUGIN_DIR . '/acme-mailer/acme-mailer.php', true, array() );

		$this->assertSame( Finding::STATUS_CRITICAL, $finding->status() );
		$this->assertTrue( $finding->detail()['wp_mail']['replaced'] );
		$this->assertSame( 'acme-mailer', $finding->detail()['wp_mail']['owner']['slug'] );
		$this->assertStringContainsString( 'acme-mailer', $finding->summary() );

		$without_route = MailInterference::build( WP_PLUGIN_DIR . '/acme-mailer/acme-mailer.php', false, array() );
		$this->assertSame( Finding::STATUS_WARNING, $without_route->status() );
	}

	/**
	 * Core's own wp_mail() is not a replacement, and in this suite that is
	 * the one defined.
	 *
	 * @return void
	 */
	public function test_core_wp_mail_is_not_a_replacement() {
		$core = ABSPATH . WPINC . '/pluggable.php';

		$this->assertFalse( MailInterference::is_replaced( $core, $core ) );
		$this->assertFalse( MailInterference::is_replaced( '', $core ) );
		$this->assertTrue( MailInterference::is_replaced( WP_PLUGIN_DIR . '/x/x.php', $core ) );
		$this->assertSame( wp_normalize_path( $core ), wp_normalize_path( MailInterference::wp_mail_file() ) );
	}

	/**
	 * Without a route, another plugin setting up the mailer is listed but is
	 * not a problem.
	 *
	 * @return void
	 */
	public function test_without_a_route_phpmailer_init_is_listed_not_flagged() {
		add_action( 'phpmailer_init', array( new OtherMailer(), 'configure' ) );

		$finding = MailInterference::check();

		$this->assertSame( Finding::STATUS_GOOD, $finding->status() );
		$this->assertCount( 1, $finding->detail()['callbacks'] );
	}

	/**
	 * Site Health has its own test for this, shaped the way it expects.
	 *
	 * @return void
	 */
	public function test_site_health_reports_it() {
		$this->activate_route();
		add_filter( 'pre_wp_mail', array( OtherMailer::class, 'short_circuit' ), 0 );

		$tests = SiteHealth::add_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'mail_vitals_mail_interference', $tests['direct'] );

		$result = SiteHealth::run_interference_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'mail_vitals_mail_interference', $result['test'] );
		$this->assertStringContainsString( 'pre_wp_mail', $result['description'] );
		$this->assertStringContainsString( 'OtherMailer.php', $result['description'] );
	}

	/**
	 * The settings screen lists each callback with its file.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_lists_callbacks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_action( 'phpmailer_init', \MailVitals\Tests\Diagnostics\Fixtures\mailer_closure(), 99 );

		ob_start();
		SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Other code handling mail', $html );
		$this->assertStringContainsString( '<code>phpmailer_init</code>', $html );
		$this->assertStringContainsString( 'Fixtures/functions.php:', $html );
	}
}
