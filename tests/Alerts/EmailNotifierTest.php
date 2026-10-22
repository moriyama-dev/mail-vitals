<?php
/**
 * Emailing alerts over a route other than the failing one.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Admin\SettingsPage;
use MailVitals\Alerts\Alert;
use MailVitals\Alerts\CircuitBreaker;
use MailVitals\Alerts\EmailNotifier;
use MailVitals\Alerts\Monitor;
use MailVitals\Log\Schema;
use MailVitals\Mailer\BrevoTransport;
use MailVitals\Mailer\Delivery;
use MailVitals\Mailer\Settings;
use MailVitals\Mailer\SmtpTransport;
use MailVitals\Queue\Queue;

/**
 * SMTP goes to the test suite's mock mailer; Brevo requests are intercepted.
 */
final class EmailNotifierTest extends \WP_UnitTestCase {

	/**
	 * Requests made to Brevo.
	 *
	 * @var array
	 */
	private $brevo = array();

	/**
	 * Status Brevo answers with.
	 *
	 * @var int
	 */
	private $brevo_status = 201;

	/**
	 * Both routes wired up as Dispatcher would, and Brevo intercepted.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Schema::install();
		reset_phpmailer_instance();

		$this->brevo        = array();
		$this->brevo_status = 201;

		( new SmtpTransport() )->register();
		( new BrevoTransport() )->register();
		Delivery::register();

		add_filter( 'pre_http_request', array( $this, 'answer_brevo' ), 10, 3 );

		Monitor::use_channels( array( new EmailNotifier() ) );
		Monitor::reset();
		delete_option( Monitor::OPTION );
	}

	/**
	 * Put the real channels back.
	 *
	 * @return void
	 */
	public function tear_down() {
		Monitor::use_channels( null );
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Stand in for the Brevo API.
	 *
	 * @param false|array $response Response so far.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Destination.
	 * @return false|array
	 */
	public function answer_brevo( $response, $args, $url ) {
		if ( BrevoTransport::ENDPOINT !== $url ) {
			return $response;
		}

		$this->brevo[] = json_decode( $args['body'], true );

		return array(
			'headers'  => array(),
			'body'     => 201 === $this->brevo_status ? '{"messageId":"<1@example.test>"}' : '{"code":"unauthorized","message":"Key not found"}',
			'response' => array(
				'code'    => $this->brevo_status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Choose the active route, and whether SMTP is configured.
	 *
	 * Brevo is always configured in the tests, so it is the backup whenever
	 * SMTP is active, and the only route when SMTP has no host.
	 *
	 * @param string $transport Active route.
	 * @param bool   $smtp      Whether SMTP is configured.
	 * @return void
	 */
	private function use_routes( $transport, $smtp = true ) {
		update_option(
			Settings::OPTION,
			array(
				'transport'    => $transport,
				'from_address' => 'site@example.com',
				'alert_email'  => 'alerts@example.com, ops@example.com',
				'smtp_host'    => $smtp ? 'smtp.example.test' : '',
				'smtp_port'    => 587,
			)
		);
	}

	/**
	 * An alert of a given type about a route.
	 *
	 * @param string $transport Route.
	 * @param string $type      Alert type.
	 * @return Alert
	 */
	private function alert( $transport, $type = Alert::FAILING ) {
		return new Alert(
			array(
				'type'       => $type,
				'severity'   => Alert::SEVERITY_HIGH,
				'transport'  => $transport,
				'cause'      => 'auth',
				'code'       => 535,
				'count'      => 1,
				'total'      => 1,
				'first_seen' => 1800000000,
				'last_seen'  => 1800000000,
				'error'      => 'Authentication failed for jane@example.com',
			)
		);
	}

	/**
	 * Saving keeps each valid address once and drops the rest.
	 *
	 * @return void
	 */
	public function test_saving_keeps_only_valid_addresses() {
		$saved = SettingsPage::sanitize( array( 'alert_email' => ' alerts@example.com,not an address, ops@example.com,,alerts@example.com ' ) );

		$this->assertSame( 'alerts@example.com, ops@example.com', $saved['alert_email'] );
	}

	/**
	 * A value stored by other means is checked again before use.
	 *
	 * @return void
	 */
	public function test_recipients_are_checked_on_the_way_out() {
		update_option( Settings::OPTION, array( 'alert_email' => 'alerts@example.com, <script>, ops@example.com' ) );

		$this->assertSame( array( 'alerts@example.com', 'ops@example.com' ), EmailNotifier::recipients() );
	}

	/**
	 * Without an address, the channel is off.
	 *
	 * @return void
	 */
	public function test_no_address_means_no_email_channel() {
		update_option( Settings::OPTION, array( 'transport' => Settings::TRANSPORT_SMTP ) );

		$this->assertFalse( ( new EmailNotifier() )->is_configured() );
	}

	/**
	 * A failure on SMTP is emailed over Brevo.
	 *
	 * @return void
	 */
	public function test_an_smtp_failure_goes_over_brevo() {
		$this->use_routes( Settings::TRANSPORT_SMTP );

		$this->assertTrue( ( new EmailNotifier() )->send( $this->alert( 'smtp' ) ) );

		$this->assertCount( 1, $this->brevo );
		$this->assertSame( 'alerts@example.com', $this->brevo[0]['to'][0]['email'] );
		$this->assertStringContainsString( 'mail is failing on SMTP', $this->brevo[0]['subject'] );
		$this->assertStringNotContainsString( 'jane@', wp_json_encode( $this->brevo[0] ) );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'Not sent over SMTP.' );
	}

	/**
	 * A failure on Brevo is emailed over SMTP.
	 *
	 * @return void
	 */
	public function test_a_brevo_failure_goes_over_smtp() {
		$this->use_routes( Settings::TRANSPORT_SMTP );

		$this->assertTrue( ( new EmailNotifier() )->send( $this->alert( 'brevo' ) ) );

		$sent = tests_retrieve_phpmailer_instance()->get_sent();

		$this->assertNotFalse( $sent );
		$this->assertStringContainsString( 'mail is failing on the Brevo API', $sent->subject );
		$this->assertCount( 0, $this->brevo );
	}

	/**
	 * With a single route, a failure on it cannot be emailed.
	 *
	 * @return void
	 */
	public function test_a_single_route_cannot_report_its_own_failure() {
		$this->use_routes( Settings::TRANSPORT_BREVO, false );

		$notifier = new EmailNotifier();

		$this->assertFalse( $notifier->accepts( $this->alert( 'brevo' ) ) );
		$this->assertFalse( $notifier->accepts( $this->alert( 'brevo', Alert::STILL_FAILING ) ) );
		$this->assertTrue( $notifier->accepts( $this->alert( 'brevo', Alert::RECOVERED ) ), 'Once it works again, it can say so.' );
	}

	/**
	 * A message the queue gave up on had just been refused by every route.
	 *
	 * @return void
	 */
	public function test_an_abandoned_message_is_not_emailed() {
		$this->use_routes( Settings::TRANSPORT_SMTP );

		$this->assertFalse( ( new EmailNotifier() )->accepts( $this->alert( CircuitBreaker::QUEUE ) ) );
	}

	/**
	 * When WordPress sends on its own, there is no route to choose.
	 *
	 * @return void
	 */
	public function test_nothing_is_emailed_without_a_route() {
		$this->use_routes( Settings::TRANSPORT_DEFAULT );

		$notifier = new EmailNotifier();

		$this->assertFalse( $notifier->accepts( $this->alert( 'default' ) ) );
		$this->assertFalse( $notifier->accepts( Alert::test( 1800000000 ) ) );
	}

	/**
	 * The test alert uses the usual route.
	 *
	 * @return void
	 */
	public function test_the_test_alert_uses_the_active_route() {
		$this->use_routes( Settings::TRANSPORT_SMTP );

		$this->assertSame( 'smtp', EmailNotifier::route_for( Alert::test( 1800000000 ) )->id() );
	}

	/**
	 * An alert email that fails is reported, not turned into an incident or
	 * queued.
	 *
	 * @return void
	 */
	public function test_a_failed_alert_email_does_not_feed_back() {
		$this->use_routes( Settings::TRANSPORT_SMTP );
		$this->brevo_status = 401;

		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'SMTP Error: Could not authenticate.', array( 'mail_vitals_transport' => 'smtp' ) ) );

		$this->assertCount( 1, $this->brevo, 'The alert was tried over Brevo.' );
		$this->assertSame( array( 'smtp:auth' ), array_keys( Monitor::incidents() ), 'No incident for the alert email itself.' );
		$this->assertSame( 0, Queue::depth(), 'The alert email is not queued.' );

		$errors = Monitor::channel_errors();

		$this->assertStringContainsString( 'Brevo', $errors['email']['error'] );
	}

	/**
	 * When email steps aside, the settings screen is told why.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_recorded() {
		$this->use_routes( Settings::TRANSPORT_BREVO, false );

		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', 'Key not found', array( 'mail_vitals_transport' => 'brevo' ) ) );

		$errors    = Monitor::channel_errors();
		$incidents = Monitor::incidents();
		$incident  = reset( $incidents );

		$this->assertSame( ( new EmailNotifier() )->refusal_reason(), $errors['email']['error'] );
		$this->assertSame( 0, $incident['notices'] );
		$this->assertStringContainsString( 'only sent over a route other than', $incident['notice_error'] );
		$this->assertCount( 0, $this->brevo );
	}
}
