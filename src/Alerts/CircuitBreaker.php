<?php
/**
 * Decides when a failure is worth a notice.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a stream of failures into a few notices.
 *
 * When a provider goes down, hundreds of sends fail at once. One notice per
 * failure would bury the one thing worth knowing, so failures are grouped by
 * signature into incidents. An incident gets a notice when it starts, further
 * notices at growing intervals while it lasts, and one notice when it ends.
 *
 * This class only holds and changes state; it reads no clock and writes
 * nothing. The caller passes the time in and stores the state, which keeps
 * every boundary testable.
 */
final class CircuitBreaker {

	/**
	 * Wait after each notice before the next, in seconds: 15 minutes after the
	 * first, an hour after the second, six hours after the third, then a day.
	 * The first notice is immediate. The same shape as the queue's backoff.
	 *
	 * @var int[]
	 */
	const INTERVALS = array( 900, 3600, 21600, 86400 );

	/**
	 * Wait before trying again when a notice could not be delivered.
	 *
	 * @var int
	 */
	const RETRY_FAILED_NOTICE = 300;

	/**
	 * Route used for messages the retry queue gave up on.
	 *
	 * @var string
	 */
	const QUEUE = 'queue';

	/**
	 * Open incidents, keyed by signature.
	 *
	 * @var array
	 */
	private $incidents = array();

	/**
	 * Start from stored state.
	 *
	 * @param array $state What state() returned last time.
	 */
	public function __construct( array $state = array() ) {
		if ( isset( $state['incidents'] ) && is_array( $state['incidents'] ) ) {
			$this->incidents = $state['incidents'];
		}
	}

	/**
	 * State to store.
	 *
	 * @return array
	 */
	public function state() {
		return array( 'incidents' => $this->incidents );
	}

	/**
	 * Open incidents, keyed by signature.
	 *
	 * @return array
	 */
	public function incidents() {
		return $this->incidents;
	}

	/**
	 * Whether any open incident is about a route.
	 *
	 * @param string $transport Route identifier.
	 * @return bool
	 */
	public function has_incident_on( $transport ) {
		foreach ( $this->incidents as $incident ) {
			if ( $incident['transport'] === $transport || self::QUEUE === $incident['transport'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a failure.
	 *
	 * @param Failure $failure  What failed.
	 * @param string  $severity Alert::SEVERITY_LOW when the message still got through another route, else Alert::SEVERITY_HIGH.
	 * @param int     $now      Unix time.
	 * @return Alert|null The notice to send now, or null when none is due.
	 */
	public function failure( Failure $failure, $severity, $now ) {
		$signature = $failure->signature();

		if ( ! isset( $this->incidents[ $signature ] ) ) {
			$this->incidents[ $signature ] = array(
				'transport'      => $failure->transport(),
				'cause'          => $failure->cause(),
				'severity'       => $severity,
				'first_seen'     => $now,
				'last_seen'      => $now,
				'total'          => 0,
				'since_notice'   => 0,
				'notices'        => 0,
				'next_notice_at' => $now,
				'code'           => 0,
				'error'          => '',
				'notice_error'   => '',
			);
		}

		$incident = $this->incidents[ $signature ];

		++$incident['total'];
		++$incident['since_notice'];
		$incident['last_seen'] = $now;
		$incident['code']      = $failure->code();
		$incident['error']     = $failure->error();

		/*
		 * Getting worse is news: "still delivered over the backup" becoming
		 * "not delivered" is worth hearing at once, whatever the interval.
		 */
		if ( Alert::SEVERITY_HIGH === $severity && Alert::SEVERITY_HIGH !== $incident['severity'] ) {
			$incident['severity']       = Alert::SEVERITY_HIGH;
			$incident['next_notice_at'] = $now;
		}

		$this->incidents[ $signature ] = $incident;

		if ( $now < $incident['next_notice_at'] ) {
			return null;
		}

		return $this->alert_for( $incident, 0 === $incident['notices'] ? Alert::FAILING : Alert::STILL_FAILING );
	}

	/**
	 * Note that a notice went out, and set when the next one may.
	 *
	 * Called before the notice is actually delivered, so that a second
	 * request failing at the same moment does not send the same notice.
	 *
	 * @param string $signature Incident signature.
	 * @param int    $now       Unix time.
	 * @return void
	 */
	public function notified( $signature, $now ) {
		if ( ! isset( $this->incidents[ $signature ] ) ) {
			return;
		}

		$incident = $this->incidents[ $signature ];

		++$incident['notices'];
		$incident['since_notice']   = 0;
		$incident['next_notice_at'] = $now + self::interval_after( $incident['notices'] );
		$incident['notice_error']   = '';

		$this->incidents[ $signature ] = $incident;
	}

	/**
	 * Undo notified() for a notice that could not be delivered, so that it is
	 * tried again soon instead of after the full interval.
	 *
	 * @param string $signature Incident signature.
	 * @param Alert  $alert     The notice that failed.
	 * @param int    $now       Unix time.
	 * @param string $reason    Why it could not be delivered.
	 * @return void
	 */
	public function notice_failed( $signature, Alert $alert, $now, $reason ) {
		if ( ! isset( $this->incidents[ $signature ] ) ) {
			return;
		}

		$incident = $this->incidents[ $signature ];

		$incident['notices']        = max( 0, $incident['notices'] - 1 );
		$incident['since_notice']  += $alert->count();
		$incident['next_notice_at'] = $now + self::RETRY_FAILED_NOTICE;
		$incident['notice_error']   = (string) $reason;

		$this->incidents[ $signature ] = $incident;
	}

	/**
	 * Record a message a route delivered, closing that route's incidents.
	 *
	 * Mail going out again also closes the retry queue's incident, since the
	 * condition that made the queue give up has passed.
	 *
	 * @param string $transport Route that delivered.
	 * @param int    $now       Unix time.
	 * @return Alert[] Recovery notices, one per closed incident that had been reported.
	 */
	public function success( $transport, $now ) {
		$alerts = array();

		foreach ( $this->incidents as $signature => $incident ) {
			if ( $incident['transport'] !== $transport && self::QUEUE !== $incident['transport'] ) {
				continue;
			}

			unset( $this->incidents[ $signature ] );

			// Nobody was told it was failing, so nobody needs telling it recovered.
			if ( $incident['notices'] < 1 ) {
				continue;
			}

			$incident['last_seen'] = $now;
			$alerts[]              = $this->alert_for( $incident, Alert::RECOVERED );
		}

		return $alerts;
	}

	/**
	 * Seconds to wait after a given number of notices.
	 *
	 * @param int $notices Notices sent so far, including the one just sent.
	 * @return int
	 */
	public static function interval_after( $notices ) {
		$index = max( 0, min( count( self::INTERVALS ), (int) $notices ) - 1 );

		return self::INTERVALS[ $index ];
	}

	/**
	 * Build the notice for an incident.
	 *
	 * @param array  $incident Incident state.
	 * @param string $type     One of the Alert type constants.
	 * @return Alert
	 */
	private function alert_for( array $incident, $type ) {
		return new Alert(
			array(
				'type'       => $type,
				'severity'   => Alert::RECOVERED === $type ? Alert::SEVERITY_INFO : $incident['severity'],
				'transport'  => $incident['transport'],
				'cause'      => $incident['cause'],
				'code'       => $incident['code'],
				'count'      => $incident['since_notice'],
				'total'      => $incident['total'],
				'first_seen' => $incident['first_seen'],
				'last_seen'  => $incident['last_seen'],
				'error'      => $incident['error'],
			)
		);
	}
}
