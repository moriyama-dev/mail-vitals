<?php
/**
 * Watches every send and raises alerts.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

use MailVitals\Mailer\Delivery;
use MailVitals\Mailer\Dispatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects mail outcomes to the circuit breaker and the alert channels.
 *
 * Nothing happens unless a channel is configured: with nowhere to send an
 * alert, keeping incident state would only add a database write to every
 * failed send.
 *
 * While Delivery is working through the routes for one message, failures are
 * held back until it knows the outcome. A route that failed while the backup
 * delivered is a lower-severity problem than one that left the message
 * undelivered, and that is only known at the end.
 */
final class Monitor {

	/**
	 * Option holding incident state and channel errors. Not autoloaded: it is
	 * read only when a send fails, or succeeds during an incident.
	 *
	 * @var string
	 */
	const OPTION = 'mail_vitals_alert_state';

	/**
	 * Name of the database lock around state changes.
	 *
	 * @var string
	 */
	const LOCK = 'mail_vitals_alerts';

	/**
	 * Seconds to wait for the lock before going on without it.
	 *
	 * @var int
	 */
	const LOCK_WAIT = 2;

	/**
	 * Failures held back while Delivery decides the outcome.
	 *
	 * @var Failure[]
	 */
	private static $held = array();

	/**
	 * Replacement clock, for tests.
	 *
	 * @var callable|null
	 */
	private static $clock = null;

	/**
	 * Replacement channel list, for tests.
	 *
	 * @var Notifier[]|null
	 */
	private static $channels = null;

	/**
	 * Start watching.
	 *
	 * @return void
	 */
	public static function register() {
		// After the log has recorded the outcome.
		add_action( 'wp_mail_failed', array( self::class, 'on_failure' ), 20 );
		add_action( 'wp_mail_succeeded', array( self::class, 'on_success' ), 20 );
		add_action( 'mail_vitals_message_abandoned', array( self::class, 'on_abandoned' ) );
	}

	/**
	 * A route refused a message.
	 *
	 * @param \WP_Error $error Error raised by the route.
	 * @return void
	 */
	public static function on_failure( $error ) {
		if ( ! is_wp_error( $error ) || ! self::channels() ) {
			return;
		}

		$failure = Classifier::classify( $error, Dispatcher::current_id() );

		if ( Delivery::is_handling() ) {
			self::$held[] = $failure;

			return;
		}

		// Nothing else will try this message: WordPress sent it on its own.
		self::process( array( $failure ), Alert::SEVERITY_HIGH );
	}

	/**
	 * Delivery has finished with a message.
	 *
	 * @param bool $sent Whether any route delivered it.
	 * @return void
	 */
	public static function settle( $sent ) {
		$held       = self::$held;
		self::$held = array();

		if ( $held && self::channels() ) {
			self::process( $held, $sent ? Alert::SEVERITY_LOW : Alert::SEVERITY_HIGH );
		}
	}

	/**
	 * A route delivered a message.
	 *
	 * @param array $mail_data The arguments wp_mail() was called with.
	 * @return void
	 */
	public static function on_success( $mail_data ) {
		if ( ! self::channels() ) {
			return;
		}

		$mail_data = is_array( $mail_data ) ? $mail_data : array();
		$transport = isset( $mail_data['mail_vitals_transport'] ) ? (string) $mail_data['mail_vitals_transport'] : Dispatcher::current_id();

		// The usual case, answered from the cached option without a lock.
		$current = new CircuitBreaker( self::load() );

		if ( ! $current->has_incident_on( $transport ) ) {
			return;
		}

		$now    = self::now();
		$alerts = self::with_state(
			static function ( CircuitBreaker $breaker ) use ( $transport, $now ) {
				return $breaker->success( $transport, $now );
			}
		);

		foreach ( $alerts as $alert ) {
			self::deliver( $alert );
		}
	}

	/**
	 * The retry queue gave up on a message.
	 *
	 * @return void
	 */
	public static function on_abandoned() {
		if ( self::channels() ) {
			self::process( array( new Failure( CircuitBreaker::QUEUE, Classifier::ABANDONED, 0, '' ) ), Alert::SEVERITY_HIGH );
		}
	}

	/**
	 * Record failures and send whatever notices fall due.
	 *
	 * Deciding happens under the lock; sending happens outside it, so a slow
	 * channel never holds other requests up. The notice is marked as sent
	 * before it goes, which is what stops a second request sending it too,
	 * and is rolled back if every channel refused it.
	 *
	 * @param Failure[] $failures Failures to record.
	 * @param string    $severity One of the Alert severity constants.
	 * @return void
	 */
	private static function process( array $failures, $severity ) {
		$now = self::now();

		$due = self::with_state(
			static function ( CircuitBreaker $breaker ) use ( $failures, $severity, $now ) {
				$due = array();

				foreach ( $failures as $failure ) {
					$alert = $breaker->failure( $failure, $severity, $now );

					if ( null !== $alert ) {
						$breaker->notified( $failure->signature(), $now );
						$due[ $failure->signature() ] = $alert;
					}
				}

				return $due;
			}
		);

		foreach ( $due as $signature => $alert ) {
			$errors = self::deliver( $alert );

			if ( null === $errors ) {
				continue;
			}

			self::with_state(
				static function ( CircuitBreaker $breaker ) use ( $signature, $alert, $now, $errors ) {
					$breaker->notice_failed( $signature, $alert, $now, implode( ' ', $errors ) );
				}
			);
		}
	}

	/**
	 * Send an alert to every channel that accepts it.
	 *
	 * @param Alert $alert Alert to send.
	 * @return string[]|null Errors when no channel took it, null when at least one did.
	 */
	public static function deliver( Alert $alert ) {
		$errors    = array();
		$delivered = false;
		$results   = array();

		foreach ( self::channels() as $channel ) {
			if ( ! $channel->accepts( $alert ) ) {
				continue;
			}

			$result = $channel->send( $alert );

			if ( is_wp_error( $result ) ) {
				$message = $result->get_error_message();

				/* translators: 1: channel name, 2: error message. */
				$errors[]                  = sprintf( __( '%1$s: %2$s', 'mail-vitals' ), $channel->label(), $message );
				$results[ $channel->id() ] = $message;
			} else {
				$delivered                 = true;
				$results[ $channel->id() ] = '';
			}
		}

		self::record_channel_results( $results );

		return $delivered ? null : $errors;
	}

	/**
	 * Open incidents, for the settings screen.
	 *
	 * @return array
	 */
	public static function incidents() {
		$state = self::load();

		return isset( $state['incidents'] ) && is_array( $state['incidents'] ) ? $state['incidents'] : array();
	}

	/**
	 * The most recent error per channel, for the settings screen.
	 *
	 * @return array Channel id => array( 'error' => string, 'at' => int ).
	 */
	public static function channel_errors() {
		$state = self::load();

		return isset( $state['channels'] ) && is_array( $state['channels'] ) ? $state['channels'] : array();
	}

	/**
	 * Channels to send to.
	 *
	 * @return Notifier[]
	 */
	public static function channels() {
		return null !== self::$channels ? self::$channels : Channels::configured();
	}

	/**
	 * Use a fixed set of channels, or null for the configured ones. For tests.
	 *
	 * @param Notifier[]|null $channels Channels.
	 * @return void
	 */
	public static function use_channels( $channels ) {
		self::$channels = $channels;
	}

	/**
	 * Use a different clock, or null for the real one. For tests.
	 *
	 * @param callable|null $clock Returns a Unix time.
	 * @return void
	 */
	public static function use_clock( $clock ) {
		self::$clock = $clock;
	}

	/**
	 * Forget failures held back from an interrupted send. For tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$held = array();
	}

	/**
	 * Current Unix time.
	 *
	 * @return int
	 */
	private static function now() {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * Remember each channel's latest result, so the settings screen can say
	 * why alerts are not arriving.
	 *
	 * @param array $results Channel id => error message, empty when it worked.
	 * @return void
	 */
	private static function record_channel_results( array $results ) {
		if ( empty( $results ) ) {
			return;
		}

		$now = self::now();

		self::with_state(
			static function ( CircuitBreaker $breaker, array &$state ) use ( $results, $now ) {
				foreach ( $results as $id => $error ) {
					if ( '' === $error ) {
						unset( $state['channels'][ $id ] );
					} else {
						$state['channels'][ $id ] = array(
							'error' => $error,
							'at'    => $now,
						);
					}
				}
			}
		);
	}

	/**
	 * Stored state.
	 *
	 * @return array
	 */
	private static function load() {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Change the state under the lock, when the lock can be had.
	 *
	 * The lock only keeps two requests from sending the same notice. If it
	 * cannot be had, the change goes ahead without it: a duplicate notice is
	 * a nuisance, a missing one is a failure.
	 *
	 * @param callable $change Receives the breaker and the whole state by reference.
	 * @return mixed Whatever $change returns.
	 */
	private static function with_state( callable $change ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A named lock has no WordPress API.
		$locked = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, self::LOCK_WAIT ) );

		try {
			// Another request may have changed it since this one cached it.
			wp_cache_delete( self::OPTION, 'options' );

			$state   = self::load();
			$breaker = new CircuitBreaker( $state );
			$result  = $change( $breaker, $state );

			$state['incidents'] = $breaker->incidents();
			update_option( self::OPTION, $state, false );
		} finally {
			if ( $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
			}
		}

		return $result;
	}
}
