<?php
/**
 * Sorts failures into kinds of problem.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a failed send into a Failure with a kind of problem attached.
 *
 * The kind decides what someone has to do: a refused login needs new
 * credentials, a refused sender needs a DNS or From address change, an
 * unreachable server needs the host or the network looked at. Status codes
 * are used first because they are unambiguous; the wording of the error is
 * used when there is no code, or when the code alone does not say enough.
 */
final class Classifier {

	/**
	 * The route could not be reached at all.
	 *
	 * @var string
	 */
	const CONNECTION = 'connection';

	/**
	 * The route refused the credentials.
	 *
	 * @var string
	 */
	const AUTH = 'auth';

	/**
	 * The route refused the From address.
	 *
	 * @var string
	 */
	const SENDER = 'sender';

	/**
	 * The route refused a recipient.
	 *
	 * @var string
	 */
	const RECIPIENT = 'recipient';

	/**
	 * The route is limiting how much the site may send.
	 *
	 * @var string
	 */
	const RATE_LIMIT = 'rate_limit';

	/**
	 * The provider itself is in trouble.
	 *
	 * @var string
	 */
	const PROVIDER = 'provider';

	/**
	 * A queued message was given up on after every retry.
	 *
	 * @var string
	 */
	const ABANDONED = 'abandoned';

	/**
	 * Anything that matches none of the above.
	 *
	 * @var string
	 */
	const UNKNOWN = 'unknown';

	/**
	 * Wording that identifies each kind, checked in this order. Order
	 * matters: "Could not authenticate" must be read as a login problem
	 * before anything treats it as a connection one.
	 *
	 * @var array
	 */
	const PATTERNS = array(
		self::AUTH       => array( 'could not authenticate', 'authentication', 'username and password', 'api key', 'api-key', 'unauthorized', 'unauthorised', 'key not found' ),
		self::RATE_LIMIT => array( 'rate limit', 'too many', 'quota', 'throttl', 'sending limit' ),
		self::SENDER     => array( 'from address failed', '(from)', 'sender', 'mail from' ),
		self::RECIPIENT  => array( 'recipients failed', 'recipient', 'rcpt to', 'user unknown', 'no such user', 'mailbox unavailable', 'mailbox not found', '(to)' ),
		self::CONNECTION => array( 'could not connect', 'connect() failed', 'failed to connect', 'connection refused', 'connection reset', 'timed out', 'timeout', 'could not resolve', 'name or service not known', 'network is unreachable', 'ssl', 'tls', 'certificate', 'could not instantiate mail function', 'curl error' ),
	);

	/**
	 * SMTP reply codes worth recognising in an error message.
	 *
	 * @var string
	 */
	const SMTP_CODE_PATTERN = '/(?<![\d.])(421|45[0-4]|50[0-4]|53[0458]|55[0-4])(?![\d])/';

	/**
	 * Describe a failed send.
	 *
	 * @param \WP_Error $error     Error raised by whichever route failed.
	 * @param string    $transport Route to assume when the error does not say.
	 * @return Failure
	 */
	public static function classify( \WP_Error $error, $transport ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		if ( isset( $data['mail_vitals_transport'] ) ) {
			$transport = (string) $data['mail_vitals_transport'];
		}

		$message = (string) $error->get_error_message();
		$http    = isset( $data['mail_vitals_http_status'] ) ? (int) $data['mail_vitals_http_status'] : null;
		$code    = null !== $http ? $http : self::smtp_code( $message );

		return new Failure( $transport, self::cause( $message, $http, $code ), $code, $message );
	}

	/**
	 * Work out the kind of problem.
	 *
	 * @param string   $message Error text.
	 * @param int|null $http    HTTP status, null when the route is not HTTP.
	 * @param int      $code    HTTP status or SMTP reply code, 0 when there was none.
	 * @return string One of the cause constants.
	 */
	public static function cause( $message, $http, $code ) {
		if ( null !== $http ) {
			if ( 0 === $http ) {
				return self::CONNECTION;
			}

			if ( 401 === $http || 403 === $http ) {
				return self::AUTH;
			}

			if ( 429 === $http ) {
				return self::RATE_LIMIT;
			}

			if ( $http >= 500 ) {
				return self::PROVIDER;
			}
		}

		$text = strtolower( $message );

		foreach ( self::PATTERNS as $cause => $needles ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return $cause;
				}
			}
		}

		return self::cause_of_smtp_code( $code );
	}

	/**
	 * The SMTP reply code mentioned in an error message, if any.
	 *
	 * @param string $message Error text.
	 * @return int 0 when none is found.
	 */
	public static function smtp_code( $message ) {
		return preg_match( self::SMTP_CODE_PATTERN, (string) $message, $match ) ? (int) $match[1] : 0;
	}

	/**
	 * Kind of problem implied by an SMTP reply code alone.
	 *
	 * @param int $code SMTP reply code.
	 * @return string
	 */
	private static function cause_of_smtp_code( $code ) {
		if ( in_array( $code, array( 530, 534, 535, 538 ), true ) ) {
			return self::AUTH;
		}

		if ( 452 === $code ) {
			return self::RATE_LIMIT;
		}

		if ( in_array( $code, array( 421, 450, 451 ), true ) ) {
			return self::PROVIDER;
		}

		if ( in_array( $code, array( 550, 551, 553 ), true ) ) {
			return self::RECIPIENT;
		}

		return self::UNKNOWN;
	}
}
