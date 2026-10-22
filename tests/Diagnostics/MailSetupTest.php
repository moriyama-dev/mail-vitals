<?php
/**
 * Whether the server and the configured routes can send.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Diagnostics;

use MailVitals\Admin\SettingsPage;
use MailVitals\Diagnostics\Finding;
use MailVitals\Diagnostics\MailSetup;
use MailVitals\Diagnostics\Probe;
use MailVitals\Diagnostics\SiteHealth;
use MailVitals\Mailer\Settings;

/**
 * Nothing here opens a real connection: SMTP goes through a stand-in
 * connector and Brevo requests are intercepted.
 */
final class MailSetupTest extends \WP_UnitTestCase {

	/**
	 * Connections the stand-in was asked for.
	 *
	 * @var array
	 */
	private $connections = array();

	/**
	 * What the stand-in answers.
	 *
	 * @var array
	 */
	private $answer = array(
		'status'   => 'reached',
		'greeting' => '220 mail.example.test ESMTP',
		'error'    => '',
	);

	/**
	 * Brevo requests seen.
	 *
	 * @var array
	 */
	private $brevo = array();

	/**
	 * Status Brevo answers with.
	 *
	 * @var int
	 */
	private $brevo_status = 200;

	/**
	 * Stand-ins in place.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->connections  = array();
		$this->brevo        = array();
		$this->brevo_status = 200;

		Probe::use_connector(
			function ( $host, $port, $encryption ) {
				$this->connections[] = array( $host, $port, $encryption );

				return $this->answer;
			}
		);

		add_filter( 'pre_http_request', array( $this, 'answer_brevo' ), 10, 3 );
	}

	/**
	 * Real connector back.
	 *
	 * @return void
	 */
	public function tear_down() {
		Probe::use_connector( mail_vitals_tests_offline_connector() );

		parent::tear_down();
	}

	/**
	 * Stand in for Brevo's account endpoint.
	 *
	 * @param false|array $response Response so far.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Destination.
	 * @return false|array
	 */
	public function answer_brevo( $response, $args, $url ) {
		if ( Probe::BREVO_ACCOUNT !== $url ) {
			return $response;
		}

		$this->brevo[] = $args;

		return array(
			'headers'  => array(),
			'body'     => 200 === $this->brevo_status ? '{"email":"owner@example.com"}' : "{\"message\":\"Key not found\"}\n",
			'response' => array(
				'code'    => $this->brevo_status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Choose the route and SMTP details.
	 *
	 * @param string $transport Transport setting.
	 * @param string $host      SMTP host.
	 * @param string $username  SMTP username.
	 * @return void
	 */
	private function settings( $transport, $host = '', $username = '' ) {
		update_option(
			Settings::OPTION,
			array(
				'transport'       => $transport,
				'smtp_host'       => $host,
				'smtp_port'       => 587,
				'smtp_encryption' => 'tls',
				'smtp_username'   => $username,
			)
		);
	}

	/**
	 * Findings by id.
	 *
	 * @return Finding[]
	 */
	private function findings() {
		$by_id = array();

		foreach ( MailSetup::run( true ) as $finding ) {
			$by_id[ $finding->id() ] = $finding;
		}

		return $by_id;
	}

	/**
	 * When WordPress relies on mail(), a missing program blocks every message.
	 *
	 * @return void
	 */
	public function test_a_missing_sendmail_blocks_default_sending() {
		$finding = MailSetup::php_mail( true, array(), false, 'Linux', '/usr/sbin/sendmail -t -i', '', false );

		$this->assertSame( Finding::STATUS_CRITICAL, $finding->status() );
		$this->assertStringContainsString( '"/usr/sbin/sendmail", which does not exist', $finding->summary() );
	}

	/**
	 * A disabled mail() blocks default sending, and is only noted otherwise.
	 *
	 * @return void
	 */
	public function test_a_disabled_mail_function() {
		$used     = MailSetup::php_mail( true, array(), true, 'Linux', '/usr/sbin/sendmail -t -i', '' );
		$not_used = MailSetup::php_mail( false, array( 'smtp' ), true, 'Linux', '/usr/sbin/sendmail -t -i', '' );

		$this->assertSame( Finding::STATUS_CRITICAL, $used->status() );
		$this->assertStringContainsString( 'disable_functions', $used->summary() );
		$this->assertSame( Finding::STATUS_GOOD, $not_used->status() );
		$this->assertStringContainsString( 'Not in use: mail goes through SMTP', $not_used->summary() );
	}

	/**
	 * An empty sendmail_path, a working one, and one that cannot be looked at.
	 *
	 * @return void
	 */
	public function test_other_sendmail_states() {
		$this->assertSame( Finding::STATUS_CRITICAL, MailSetup::php_mail( true, array(), false, 'Linux', '', '' )->status() );
		$this->assertSame( Finding::STATUS_GOOD, MailSetup::php_mail( true, array(), false, 'Linux', '/usr/sbin/sendmail -t -i', '', true )->status() );
		$this->assertSame( Finding::STATUS_UNKNOWN, MailSetup::php_mail( true, array(), false, 'Linux', '/usr/sbin/sendmail -t -i', '/var/www' )->status() );
		$this->assertSame( Finding::STATUS_UNKNOWN, MailSetup::php_mail( true, array(), false, 'Windows', '', '' )->status() );
	}

	/**
	 * The program is read out of sendmail_path, quoted or not.
	 *
	 * @return void
	 */
	public function test_the_program_is_read_from_sendmail_path() {
		$this->assertSame( '/usr/sbin/sendmail', MailSetup::program_of( '/usr/sbin/sendmail -t -i' ) );
		$this->assertSame( '/opt/my mail/sendmail', MailSetup::program_of( '"/opt/my mail/sendmail" -t' ) );
		$this->assertSame( '', MailSetup::program_of( '  ' ) );
	}

	/**
	 * A route that is chosen but not configured is not used at all.
	 *
	 * @return void
	 */
	public function test_a_chosen_route_that_cannot_be_used() {
		$this->settings( Settings::TRANSPORT_SMTP );

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_CRITICAL, $findings['selected_route']->status() );
		$this->assertStringContainsString( 'host or port is not filled in', $findings['selected_route']->summary() );
		$this->assertTrue( $findings['php_mail']->detail()['used'], 'WordPress sends on its own instead.' );
		$this->assertNull( MailSetup::chosen_but_unusable( Settings::TRANSPORT_DEFAULT, true ) );
	}

	/**
	 * A reachable SMTP server is good, and nothing but a connection is made.
	 *
	 * @return void
	 */
	public function test_a_reachable_smtp_server() {
		$this->settings( Settings::TRANSPORT_SMTP, 'mail.example.test' );

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_GOOD, $findings['route_smtp']->status() );
		$this->assertStringContainsString( 'answered as a mail server', $findings['route_smtp']->summary() );
		$this->assertSame( array( array( 'mail.example.test', 587, 'tls' ) ), $this->connections );
		$this->assertSame( Finding::STATUS_GOOD, $findings['php_mail']->status(), 'mail() is not what sends.' );
	}

	/**
	 * An unreachable server is critical, with the reason.
	 *
	 * @return void
	 */
	public function test_an_unreachable_smtp_server() {
		$this->settings( Settings::TRANSPORT_SMTP, 'mail.example.test' );
		$this->answer = array(
			'status'   => 'unreachable',
			'greeting' => '',
			'error'    => 'Connection timed out',
		);

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_CRITICAL, $findings['route_smtp']->status() );
		$this->assertStringContainsString( 'Connection timed out', $findings['route_smtp']->summary() );
	}

	/**
	 * A username without the password constant connects without logging in.
	 *
	 * @return void
	 */
	public function test_a_username_without_a_password() {
		$smtp = array(
			'host'       => 'mail.example.test',
			'port'       => 587,
			'encryption' => 'tls',
			'username'   => 'owner',
			'password'   => '',
		);

		$finding = MailSetup::smtp_route( $smtp, $this->answer, false );

		$this->assertSame( Finding::STATUS_CRITICAL, $finding->status() );
		$this->assertStringContainsString( 'MAIL_VITALS_SMTP_PASSWORD is not', $finding->summary() );

		$smtp['username'] = '';
		$smtp['password'] = 'secret';

		$this->assertSame( Finding::STATUS_WARNING, MailSetup::smtp_route( $smtp, $this->answer, false )->status() );
	}

	/**
	 * Silence on port 465 without SSL points at the encryption setting.
	 *
	 * @return void
	 */
	public function test_silence_on_port_465_suggests_ssl() {
		$smtp  = array(
			'host'       => 'mail.example.test',
			'port'       => 465,
			'encryption' => 'tls',
			'username'   => '',
			'password'   => '',
		);
		$quiet = array(
			'status'   => 'not_smtp',
			'greeting' => '',
			'error'    => '',
		);

		$finding = MailSetup::smtp_route( $smtp, $quiet, true );

		$this->assertSame( Finding::STATUS_WARNING, $finding->status() );
		$this->assertStringContainsString( 'choose SSL', $finding->summary() );
		$this->assertStringContainsString( '(backup)', $finding->label() );
	}

	/**
	 * A Brevo key that Brevo accepts is good; the key is sent only as a header.
	 *
	 * @return void
	 */
	public function test_brevo_accepts_the_key() {
		$this->settings( Settings::TRANSPORT_BREVO );

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_GOOD, $findings['route_brevo']->status() );
		$this->assertCount( 1, $this->brevo );
		$this->assertSame( 'test-key', $this->brevo[0]['headers']['api-key'] );
	}

	/**
	 * A refused key is critical, with Brevo's answer.
	 *
	 * @return void
	 */
	public function test_brevo_refuses_the_key() {
		$this->settings( Settings::TRANSPORT_BREVO );
		$this->brevo_status = 401;

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_CRITICAL, $findings['route_brevo']->status() );
		$this->assertStringContainsString( 'HTTP 401: {"message":"Key not found"})', $findings['route_brevo']->summary() );
	}

	/**
	 * An SMTP key in place of an API key is caught without asking Brevo.
	 *
	 * @return void
	 */
	public function test_an_smtp_key_is_caught_before_asking() {
		$finding = MailSetup::brevo_route( 'xsmtpsib-abc', null, false );

		$this->assertSame( Finding::STATUS_CRITICAL, $finding->status() );
		$this->assertStringContainsString( 'xkeysib-', $finding->summary() );
		$this->assertTrue( MailSetup::is_smtp_key( 'xsmtpsib-abc' ) );
		$this->assertFalse( MailSetup::is_smtp_key( 'xkeysib-abc' ) );
	}

	/**
	 * A key for a route that is not used is pointed out.
	 *
	 * @return void
	 */
	public function test_unused_settings_are_pointed_out() {
		$this->settings( Settings::TRANSPORT_DEFAULT );

		$findings = $this->findings();

		$this->assertSame( Finding::STATUS_WARNING, $findings['unused_settings']->status() );
		$this->assertStringContainsString( 'MAIL_VITALS_BREVO_API_KEY is set', $findings['unused_settings']->summary() );
		$this->assertStringContainsString( 'Leave it to WordPress', $findings['unused_settings']->summary() );
		$this->assertCount( 0, $this->brevo, 'An unused key is not tried.' );
	}

	/**
	 * With SMTP chosen, Brevo is the backup and is in use, so nothing is unused.
	 *
	 * @return void
	 */
	public function test_a_backup_counts_as_in_use() {
		$this->settings( Settings::TRANSPORT_SMTP, 'mail.example.test' );

		$findings = $this->findings();

		$this->assertArrayNotHasKey( 'unused_settings', $findings );
		$this->assertStringContainsString( '(backup)', $findings['route_brevo']->label() );
	}

	/**
	 * Results are kept, so opening the screen again does not reconnect.
	 *
	 * @return void
	 */
	public function test_results_are_kept() {
		Probe::smtp( 'mail.example.test', 587, 'tls' );
		Probe::smtp( 'mail.example.test', 587, 'tls' );
		Probe::brevo( 'xkeysib-abc' );
		Probe::brevo( 'xkeysib-abc' );

		$this->assertCount( 1, $this->connections );
		$this->assertCount( 1, $this->brevo );

		Probe::smtp( 'mail.example.test', 587, 'tls', true );

		$this->assertCount( 2, $this->connections, 'Fresh ignores what was kept.' );
	}

	/**
	 * Brevo out of reach is a warning, not a verdict on the key.
	 *
	 * @return void
	 */
	public function test_brevo_out_of_reach() {
		$finding = MailSetup::brevo_route(
			'xkeysib-abc',
			array(
				'status' => 'unreachable',
				'error'  => 'cURL error 28',
			),
			false
		);

		$this->assertSame( Finding::STATUS_WARNING, $finding->status() );
	}

	/**
	 * Site Health and the settings screen both show it.
	 *
	 * @return void
	 */
	public function test_it_is_shown_in_site_health_and_settings() {
		$this->settings( Settings::TRANSPORT_SMTP, 'mail.example.test' );

		$result = SiteHealth::run_setup_test();

		$this->assertSame( 'mail_vitals_mail_setup', $result['test'] );
		$this->assertSame( 'good', $result['status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Server and route setup', $html );
		$this->assertStringContainsString( 'mail.example.test:587 answered as a mail server', $html );
	}
}
