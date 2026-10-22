<?php
/**
 * Grouping failures into a few notices.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\CircuitBreaker;
use MailVitals\Alerts\Classifier;
use MailVitals\Alerts\Failure;

/**
 * Every boundary is driven by an explicit time, never the clock.
 */
final class CircuitBreakerTest extends \WP_UnitTestCase {

	/**
	 * A fixed starting time.
	 *
	 * @var int
	 */
	const T0 = 1800000000;

	/**
	 * A failure on a route.
	 *
	 * @param string $transport Route.
	 * @param string $cause     Kind of problem.
	 * @param int    $code      Code.
	 * @return Failure
	 */
	private function failure( $transport = 'smtp', $cause = Classifier::CONNECTION, $code = 0 ) {
		return new Failure( $transport, $cause, $code, 'Could not connect to a@example.com' );
	}

	/**
	 * Record a failure and, when a notice is due, mark it sent as Monitor does.
	 *
	 * @param CircuitBreaker $breaker  Breaker.
	 * @param int            $now      Unix time.
	 * @param Failure|null   $failure  Failure, a default SMTP one when null.
	 * @param string         $severity Severity.
	 * @return Alert|null
	 */
	private function record( CircuitBreaker $breaker, $now, $failure = null, $severity = Alert::SEVERITY_HIGH ) {
		$failure = $failure ? $failure : $this->failure();
		$alert   = $breaker->failure( $failure, $severity, $now );

		if ( $alert ) {
			$breaker->notified( $failure->signature(), $now );
		}

		return $alert;
	}

	/**
	 * The first failure is reported at once.
	 *
	 * @return void
	 */
	public function test_first_failure_is_reported_immediately() {
		$breaker = new CircuitBreaker();
		$alert   = $this->record( $breaker, self::T0 );

		$this->assertInstanceOf( Alert::class, $alert );
		$this->assertSame( Alert::FAILING, $alert->type() );
		$this->assertSame( 1, $alert->count() );
		$this->assertSame( 'smtp', $alert->transport() );
	}

	/**
	 * Notices come at 0, 15 minutes, 1 hour, 6 hours, then every 24 hours,
	 * each measured from the previous notice.
	 *
	 * @return void
	 */
	public function test_notice_schedule_boundaries() {
		$breaker = new CircuitBreaker();
		$this->assertNotNull( $this->record( $breaker, self::T0 ) );

		$at = self::T0;
		foreach ( array( 900, 3600, 21600, 86400, 86400 ) as $wait ) {
			$this->assertNull( $this->record( $breaker, $at + $wait - 1 ), "Nothing one second before the {$wait}s mark." );

			$alert = $this->record( $breaker, $at + $wait );
			$this->assertNotNull( $alert, "A notice at the {$wait}s mark." );
			$this->assertSame( Alert::STILL_FAILING, $alert->type() );
			$this->assertSame( 2, $alert->count(), 'Counts the failures since the previous notice.' );

			$at += $wait;
		}
	}

	/**
	 * A day of failures, one a minute, is four notices, not ninety-six.
	 *
	 * @return void
	 */
	public function test_a_day_long_outage_stays_quiet() {
		$breaker = new CircuitBreaker();
		$notices = 0;

		for ( $minute = 0; $minute < 24 * 60; $minute++ ) {
			if ( $this->record( $breaker, self::T0 + $minute * 60 ) ) {
				++$notices;
			}
		}

		$this->assertSame( 4, $notices );
	}

	/**
	 * One outage answering with different codes is one incident.
	 *
	 * @return void
	 */
	public function test_codes_share_an_incident() {
		$breaker = new CircuitBreaker();

		$this->assertNotNull( $this->record( $breaker, self::T0, $this->failure( 'brevo', Classifier::PROVIDER, 502 ) ) );
		$this->assertNull( $this->record( $breaker, self::T0 + 1, $this->failure( 'brevo', Classifier::PROVIDER, 503 ) ) );
		$this->assertNull( $this->record( $breaker, self::T0 + 2, $this->failure( 'brevo', Classifier::PROVIDER, 504 ) ) );
		$this->assertCount( 1, $breaker->incidents() );
	}

	/**
	 * Different routes or kinds of problem are separate incidents.
	 *
	 * @return void
	 */
	public function test_different_problems_are_reported_separately() {
		$breaker = new CircuitBreaker();

		$this->assertNotNull( $this->record( $breaker, self::T0, $this->failure( 'smtp', Classifier::CONNECTION ) ) );
		$this->assertNotNull( $this->record( $breaker, self::T0, $this->failure( 'smtp', Classifier::AUTH ) ) );
		$this->assertNotNull( $this->record( $breaker, self::T0, $this->failure( 'brevo', Classifier::CONNECTION ) ) );
		$this->assertCount( 3, $breaker->incidents() );
	}

	/**
	 * Getting worse is reported at once, inside the interval.
	 *
	 * @return void
	 */
	public function test_escalation_to_high_is_reported_immediately() {
		$breaker = new CircuitBreaker();

		$first = $this->record( $breaker, self::T0, null, Alert::SEVERITY_LOW );
		$this->assertSame( Alert::SEVERITY_LOW, $first->severity() );
		$this->assertNull( $this->record( $breaker, self::T0 + 60, null, Alert::SEVERITY_LOW ) );

		$worse = $this->record( $breaker, self::T0 + 120, null, Alert::SEVERITY_HIGH );
		$this->assertNotNull( $worse );
		$this->assertSame( Alert::SEVERITY_HIGH, $worse->severity() );

		$this->assertNull( $this->record( $breaker, self::T0 + 180, null, Alert::SEVERITY_LOW ), 'Severity does not fall back within an incident.' );
	}

	/**
	 * A notice that could not be delivered is retried soon, without losing count.
	 *
	 * @return void
	 */
	public function test_failed_notice_is_retried_after_five_minutes() {
		$breaker   = new CircuitBreaker();
		$signature = $this->failure()->signature();

		$alert = $breaker->failure( $this->failure(), Alert::SEVERITY_HIGH, self::T0 );
		$breaker->notified( $signature, self::T0 );
		$breaker->notice_failed( $signature, $alert, self::T0, 'HTTP 500' );

		$incident = $breaker->incidents()[ $signature ];
		$this->assertSame( 0, $incident['notices'] );
		$this->assertSame( 'HTTP 500', $incident['notice_error'] );

		$this->assertNull( $this->record( $breaker, self::T0 + 299 ) );
		$retry = $this->record( $breaker, self::T0 + 300 );
		$this->assertNotNull( $retry );
		$this->assertSame( Alert::FAILING, $retry->type(), 'Still the first notice, since none got through.' );
		$this->assertSame( 3, $retry->count() );
	}

	/**
	 * Delivery on the failing route closes the incident with one notice.
	 *
	 * @return void
	 */
	public function test_recovery_is_reported_once() {
		$breaker = new CircuitBreaker();
		$this->record( $breaker, self::T0 );
		$this->record( $breaker, self::T0 + 60 );

		$alerts = $breaker->success( 'smtp', self::T0 + 600 );
		$this->assertCount( 1, $alerts );
		$this->assertSame( Alert::RECOVERED, $alerts[0]->type() );
		$this->assertSame( 2, $alerts[0]->total() );
		$this->assertSame( 600, $alerts[0]->last_seen() - $alerts[0]->first_seen() );

		$this->assertSame( array(), $breaker->success( 'smtp', self::T0 + 700 ) );
		$this->assertSame( array(), $breaker->incidents() );
	}

	/**
	 * Delivery on another route is not a recovery.
	 *
	 * @return void
	 */
	public function test_backup_delivery_is_not_a_recovery() {
		$breaker = new CircuitBreaker();
		$this->record( $breaker, self::T0, null, Alert::SEVERITY_LOW );

		$this->assertSame( array(), $breaker->success( 'brevo', self::T0 + 60 ) );
		$this->assertCount( 1, $breaker->incidents() );
		$this->assertCount( 1, $breaker->success( 'smtp', self::T0 + 120 ) );
	}

	/**
	 * An incident nobody was told about closes without a notice.
	 *
	 * @return void
	 */
	public function test_unreported_incident_closes_quietly() {
		$breaker = new CircuitBreaker();
		$breaker->failure( $this->failure(), Alert::SEVERITY_HIGH, self::T0 );

		$this->assertSame( array(), $breaker->success( 'smtp', self::T0 + 60 ) );
		$this->assertSame( array(), $breaker->incidents() );
	}

	/**
	 * Messages the queue gave up on are an incident that any delivery closes.
	 *
	 * @return void
	 */
	public function test_abandonment_closes_on_any_delivery() {
		$breaker   = new CircuitBreaker();
		$abandoned = new Failure( CircuitBreaker::QUEUE, Classifier::ABANDONED, 0, '' );

		$this->assertNotNull( $this->record( $breaker, self::T0, $abandoned ) );
		$this->assertTrue( $breaker->has_incident_on( 'brevo' ) );

		$alerts = $breaker->success( 'brevo', self::T0 + 60 );
		$this->assertCount( 1, $alerts );
		$this->assertSame( Classifier::ABANDONED, $alerts[0]->cause() );
	}

	/**
	 * State survives being stored and loaded.
	 *
	 * @return void
	 */
	public function test_state_round_trip() {
		$breaker = new CircuitBreaker();
		$this->record( $breaker, self::T0 );

		$restored = new CircuitBreaker( $breaker->state() );
		$this->assertNull( $this->record( $restored, self::T0 + 60 ), 'The interval carries over.' );
		$this->assertCount( 1, $restored->success( 'smtp', self::T0 + 120 ) );
	}

	/**
	 * Notice text carries no full address.
	 *
	 * @return void
	 */
	public function test_notice_masks_addresses() {
		$alert = $this->record( new CircuitBreaker(), self::T0 );

		$this->assertStringNotContainsString( 'a@example.com', implode( "\n", $alert->lines() ) );
		$this->assertStringContainsString( 'a***@example.com', $alert->error() );
	}
}
