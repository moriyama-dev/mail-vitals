<?php
/**
 * Turning mail outcomes into alerts.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\CircuitBreaker;
use MailVitals\Alerts\Classifier;
use MailVitals\Alerts\Monitor;
use MailVitals\Mailer\Delivery;
use MailVitals\Tests\Support\RecordingChannel;

/**
 * Drives Monitor through the same actions WordPress and the plugin fire.
 */
final class MonitorTest extends \WP_UnitTestCase {

	/**
	 * A fixed starting time.
	 *
	 * @var int
	 */
	const T0 = 1800000000;

	/**
	 * Current test time.
	 *
	 * @var int
	 */
	private $now = self::T0;

	/**
	 * Channel under observation.
	 *
	 * @var RecordingChannel
	 */
	private $channel;

	/**
	 * Fresh channel, fixed clock, empty state.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->now     = self::T0;
		$this->channel = new RecordingChannel();

		Monitor::use_channels( array( $this->channel ) );
		Monitor::use_clock(
			function () {
				return $this->now;
			}
		);
		Monitor::reset();
		delete_option( Monitor::OPTION );
	}

	/**
	 * Put the real channels and clock back.
	 *
	 * @return void
	 */
	public function tear_down() {
		Monitor::use_channels( null );
		Monitor::use_clock( null );
		Monitor::reset();
		$this->set_delivery_running( false );

		parent::tear_down();
	}

	/**
	 * The error a route raises when it cannot connect.
	 *
	 * @param string $transport Route.
	 * @return \WP_Error
	 */
	private function connection_error( $transport = 'smtp' ) {
		return new \WP_Error(
			'wp_mail_failed',
			'SMTP Error: Could not connect to SMTP host. Failed to connect to server for jane@example.com',
			array(
				'to'                    => array( 'jane@example.com' ),
				'subject'               => 'Your order',
				'mail_vitals_transport' => $transport,
			)
		);
	}

	/**
	 * Pretend Delivery is between routes, as it is while it works on a message.
	 *
	 * @param bool $running Whether Delivery is working.
	 * @return void
	 */
	private function set_delivery_running( $running ) {
		$property = new \ReflectionProperty( Delivery::class, 'running' );
		$property->setAccessible( true );
		$property->setValue( null, $running );
	}

	/**
	 * With no channel, a failure leaves nothing behind.
	 *
	 * @return void
	 */
	public function test_without_a_channel_nothing_is_stored() {
		Monitor::use_channels( array() );

		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertFalse( get_option( Monitor::OPTION ) );
	}

	/**
	 * A failure outside Delivery is reported at once, as severe.
	 *
	 * @return void
	 */
	public function test_a_failure_is_reported_at_once() {
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertSame( array( Alert::FAILING ), $this->channel->types() );
		$this->assertSame( Alert::SEVERITY_HIGH, $this->channel->alerts[0]->severity() );
		$this->assertSame( Classifier::CONNECTION, $this->channel->alerts[0]->cause() );
		$this->assertSame( 'smtp', $this->channel->alerts[0]->transport() );
	}

	/**
	 * The notice carries neither the recipient nor the subject.
	 *
	 * @return void
	 */
	public function test_the_notice_carries_no_recipient_or_subject() {
		do_action( 'wp_mail_failed', $this->connection_error() );

		$text = implode( "\n", $this->channel->alerts[0]->lines() ) . $this->channel->alerts[0]->error();

		$this->assertStringNotContainsString( 'jane@example.com', $text );
		$this->assertStringNotContainsString( 'Your order', $text );
		$this->assertStringContainsString( 'j***@example.com', $text );
	}

	/**
	 * Repeated failures inside the cooldown are counted, not sent.
	 *
	 * @return void
	 */
	public function test_repeats_wait_for_the_next_interval() {
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->now += 60;
		do_action( 'wp_mail_failed', $this->connection_error() );
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertCount( 1, $this->channel->alerts );

		$this->now = self::T0 + 900;
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertSame( array( Alert::FAILING, Alert::STILL_FAILING ), $this->channel->types() );
		$this->assertSame( 4, $this->channel->alerts[1]->total() );
	}

	/**
	 * A route that failed while the backup delivered is the lesser problem.
	 *
	 * @return void
	 */
	public function test_a_failure_the_backup_covered_is_low_severity() {
		$this->set_delivery_running( true );
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertCount( 0, $this->channel->alerts, 'Held until the outcome is known.' );

		$this->set_delivery_running( false );
		Monitor::settle( true );

		$this->assertCount( 1, $this->channel->alerts );
		$this->assertSame( Alert::SEVERITY_LOW, $this->channel->alerts[0]->severity() );
	}

	/**
	 * When no route delivered, every failure along the way is severe.
	 *
	 * @return void
	 */
	public function test_an_undelivered_message_is_high_severity() {
		$this->set_delivery_running( true );
		do_action( 'wp_mail_failed', $this->connection_error( 'smtp' ) );
		do_action( 'wp_mail_failed', $this->connection_error( 'brevo' ) );
		$this->set_delivery_running( false );

		Monitor::settle( false );

		$this->assertCount( 2, $this->channel->alerts, 'One incident per route.' );

		foreach ( $this->channel->alerts as $alert ) {
			$this->assertSame( Alert::SEVERITY_HIGH, $alert->severity() );
		}
	}

	/**
	 * Settling with nothing held sends nothing.
	 *
	 * @return void
	 */
	public function test_settling_a_clean_send_does_nothing() {
		Monitor::settle( true );

		$this->assertCount( 0, $this->channel->alerts );
		$this->assertFalse( get_option( Monitor::OPTION ) );
	}

	/**
	 * Recovery is announced once, by the next success on the failed route.
	 *
	 * @return void
	 */
	public function test_recovery_is_announced_once() {
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->now += 120;
		do_action( 'wp_mail_succeeded', array( 'mail_vitals_transport' => 'smtp' ) );
		do_action( 'wp_mail_succeeded', array( 'mail_vitals_transport' => 'smtp' ) );

		$this->assertSame( array( Alert::FAILING, Alert::RECOVERED ), $this->channel->types() );
		$this->assertSame( array(), Monitor::incidents() );
	}

	/**
	 * A success on another route is not a recovery.
	 *
	 * @return void
	 */
	public function test_a_success_elsewhere_is_not_a_recovery() {
		do_action( 'wp_mail_failed', $this->connection_error( 'smtp' ) );
		do_action( 'wp_mail_succeeded', array( 'mail_vitals_transport' => 'brevo' ) );

		$this->assertSame( array( Alert::FAILING ), $this->channel->types() );
		$this->assertCount( 1, Monitor::incidents() );
	}

	/**
	 * A success with no incident open does not touch the state.
	 *
	 * @return void
	 */
	public function test_a_success_without_an_incident_writes_nothing() {
		do_action( 'wp_mail_succeeded', array( 'mail_vitals_transport' => 'smtp' ) );

		$this->assertFalse( get_option( Monitor::OPTION ) );
	}

	/**
	 * The queue giving up on a message is reported.
	 *
	 * @return void
	 */
	public function test_an_abandoned_message_is_reported() {
		do_action( 'mail_vitals_message_abandoned', 4 );

		$this->assertCount( 1, $this->channel->alerts );
		$this->assertSame( CircuitBreaker::QUEUE, $this->channel->alerts[0]->transport() );
		$this->assertSame( Classifier::ABANDONED, $this->channel->alerts[0]->cause() );
		$this->assertSame( Alert::SEVERITY_HIGH, $this->channel->alerts[0]->severity() );
	}

	/**
	 * A notice no channel took is tried again soon, and the reason is kept.
	 *
	 * @return void
	 */
	public function test_a_refused_notice_is_retried() {
		$this->channel->refuse = new \WP_Error( 'down', 'Channel is down' );

		do_action( 'wp_mail_failed', $this->connection_error() );

		$incidents = Monitor::incidents();
		$incident  = reset( $incidents );
		$errors    = Monitor::channel_errors();

		$this->assertSame( 0, $incident['notices'] );
		$this->assertStringContainsString( 'Channel is down', $incident['notice_error'] );
		$this->assertSame( 'Channel is down', $errors['recording']['error'] );

		$this->channel->refuse = null;

		$this->now += CircuitBreaker::RETRY_FAILED_NOTICE - 1;
		do_action( 'wp_mail_failed', $this->connection_error() );
		$this->assertCount( 0, $this->channel->alerts );

		$this->now += 1;
		do_action( 'wp_mail_failed', $this->connection_error() );

		$this->assertSame( array( Alert::FAILING ), $this->channel->types() );
		$this->assertSame( array(), Monitor::channel_errors(), 'A success clears the channel error.' );
	}

	/**
	 * One channel taking the notice is enough.
	 *
	 * @return void
	 */
	public function test_one_working_channel_is_enough() {
		$broken         = new RecordingChannel( 'broken' );
		$broken->refuse = new \WP_Error( 'down', 'Channel is down' );

		Monitor::use_channels( array( $broken, $this->channel ) );

		do_action( 'wp_mail_failed', $this->connection_error() );

		$incidents = Monitor::incidents();
		$incident  = reset( $incidents );

		$this->assertSame( 1, $incident['notices'] );
		$this->assertCount( 1, $this->channel->alerts );
		$this->assertArrayHasKey( 'broken', Monitor::channel_errors() );
	}

	/**
	 * The state option is not loaded on every request.
	 *
	 * @return void
	 */
	public function test_the_state_is_not_autoloaded() {
		global $wpdb;

		do_action( 'wp_mail_failed', $this->connection_error() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The autoload flag has no API of its own.
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Monitor::OPTION ) );

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * When another request holds the lock, the alert still goes out.
	 *
	 * @return void
	 */
	public function test_a_held_lock_does_not_stop_the_alert() {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', Monitor::LOCK ) ) );

		try {
			$started = microtime( true );
			do_action( 'wp_mail_failed', $this->connection_error() );
			$waited = microtime( true ) - $started;
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', Monitor::LOCK ) );
			$other->close();
		}

		$this->assertCount( 1, $this->channel->alerts );
		$this->assertGreaterThanOrEqual( Monitor::LOCK_WAIT - 0.1, $waited, 'It waited for the lock first.' );
	}
}
