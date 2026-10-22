<?php
/**
 * One failed send, reduced to what alerting needs.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A failed send: which route, what kind of problem, and what was said.
 */
final class Failure {

	/**
	 * Route that failed, as stored in the log ("smtp", "brevo", "default", "queue").
	 *
	 * @var string
	 */
	private $transport;

	/**
	 * One of the Classifier cause constants.
	 *
	 * @var string
	 */
	private $cause;

	/**
	 * HTTP status or SMTP reply code, 0 when there was none.
	 *
	 * @var int
	 */
	private $code;

	/**
	 * Error text exactly as the route reported it.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Describe a failure.
	 *
	 * @param string $transport Route that failed.
	 * @param string $cause     One of the Classifier cause constants.
	 * @param int    $code      HTTP status or SMTP reply code, 0 when there was none.
	 * @param string $error     Error text as reported.
	 */
	public function __construct( $transport, $cause, $code, $error ) {
		$this->transport = (string) $transport;
		$this->cause     = (string) $cause;
		$this->code      = (int) $code;
		$this->error     = (string) $error;
	}

	/**
	 * Route that failed.
	 *
	 * @return string
	 */
	public function transport() {
		return $this->transport;
	}

	/**
	 * Kind of problem.
	 *
	 * @return string
	 */
	public function cause() {
		return $this->cause;
	}

	/**
	 * HTTP status or SMTP reply code, 0 when there was none.
	 *
	 * @return int
	 */
	public function code() {
		return $this->code;
	}

	/**
	 * Error text as reported.
	 *
	 * @return string
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * What makes two failures the same problem: the route and the kind of
	 * problem, and nothing else.
	 *
	 * The code is left out on purpose. A provider in trouble answers 502,
	 * 503 and 504 by turns; with the code in the signature that one outage
	 * would raise three separate alerts. The recipient and subject are left
	 * out too, so that hundreds of messages failing for one reason become one
	 * alert.
	 *
	 * @return string
	 */
	public function signature() {
		return $this->transport . ':' . $this->cause;
	}
}
