<?php
/**
 * An alert channel that keeps what it is given.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Support;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\Notifier;

/**
 * Records every alert, and can be told to refuse them.
 */
final class RecordingChannel implements Notifier {

	/**
	 * Alerts received, in order.
	 *
	 * @var Alert[]
	 */
	public $alerts = array();

	/**
	 * Error to answer with, or null to accept.
	 *
	 * @var \WP_Error|null
	 */
	public $refuse = null;

	/**
	 * Identifier.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Create a channel.
	 *
	 * @param string $id Identifier.
	 */
	public function __construct( $id = 'recording' ) {
		$this->id = $id;
	}

	/**
	 * Identifier.
	 *
	 * @return string
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Recording';
	}

	/**
	 * Constant.
	 *
	 * @return string
	 */
	public function constant() {
		return 'MAIL_VITALS_TEST_CHANNEL';
	}

	/**
	 * Always configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return true;
	}

	/**
	 * Takes everything.
	 *
	 * @param Alert $alert Alert.
	 * @return bool
	 */
	public function accepts( Alert $alert ) {
		return true;
	}

	/**
	 * Never needed.
	 *
	 * @return string
	 */
	public function refusal_reason() {
		return '';
	}

	/**
	 * Keep the alert, or refuse it.
	 *
	 * @param Alert $alert Alert.
	 * @return true|\WP_Error
	 */
	public function send( Alert $alert ) {
		if ( null !== $this->refuse ) {
			return $this->refuse;
		}

		$this->alerts[] = $alert;

		return true;
	}

	/**
	 * Types of the alerts received.
	 *
	 * @return string[]
	 */
	public function types() {
		return array_map(
			static function ( Alert $alert ) {
				return $alert->type();
			},
			$this->alerts
		);
	}
}
