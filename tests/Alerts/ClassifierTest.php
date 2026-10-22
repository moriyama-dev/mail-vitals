<?php
/**
 * Sorting failures into kinds of problem.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Alerts\Classifier;

/**
 * Uses the wording the mail library and the Brevo API actually produce.
 */
final class ClassifierTest extends \WP_UnitTestCase {

	/**
	 * Build the error a route raises.
	 *
	 * @param string   $message   Error text.
	 * @param string   $transport Route.
	 * @param int|null $http      HTTP status, for the API route.
	 * @return \WP_Error
	 */
	private function error( $message, $transport = 'smtp', $http = null ) {
		$data = array( 'mail_vitals_transport' => $transport );

		if ( null !== $http ) {
			$data['mail_vitals_http_status'] = $http;
		}

		return new \WP_Error( 'wp_mail_failed', $message, $data );
	}

	/**
	 * Real SMTP errors, and what they mean.
	 *
	 * @return array
	 */
	public function smtp_errors() {
		return array(
			'unreachable host'     => array( 'SMTP Error: Could not connect to SMTP host. Failed to connect to server', Classifier::CONNECTION, 0 ),
			'connect failed'       => array( 'SMTP connect() failed. https://github.com/PHPMailer/PHPMailer/wiki/Troubleshooting', Classifier::CONNECTION, 0 ),
			'port is not a code'   => array( 'Failed to connect to server: Connection refused (111) smtp.example.com:465', Classifier::CONNECTION, 0 ),
			'no local mailer'      => array( 'Could not instantiate mail function.', Classifier::CONNECTION, 0 ),
			'login refused'        => array( 'SMTP Error: Could not authenticate.', Classifier::AUTH, 0 ),
			'password refused'     => array( '535 5.7.8 Username and Password not accepted.', Classifier::AUTH, 535 ),
			'unknown recipient'    => array( 'SMTP Error: The following recipients failed: a@example.com: 550 5.1.1 <a@example.com>: Recipient address rejected: User unknown', Classifier::RECIPIENT, 550 ),
			'sender rejected'      => array( 'SMTP Error: The following from address failed: wp@example.com : MAIL FROM command failed,550,5.7.1 Sender address rejected', Classifier::SENDER, 550 ),
			'invalid from address' => array( 'Invalid address:  (From): wordpress@localhost', Classifier::SENDER, 0 ),
			'too many connections' => array( '421 4.7.0 Too many connections from your host', Classifier::RATE_LIMIT, 421 ),
			'temporary failure'    => array( 'SMTP Error: data not accepted. SMTP server error: DATA END command failed SMTP code: 451', Classifier::PROVIDER, 451 ),
			'code only'            => array( 'SMTP server error: 554', Classifier::UNKNOWN, 554 ),
			'nothing recognisable' => array( 'Something odd happened.', Classifier::UNKNOWN, 0 ),
		);
	}

	/**
	 * SMTP errors are read by their wording, then their reply code.
	 *
	 * @dataProvider smtp_errors
	 *
	 * @param string $message Error text.
	 * @param string $cause   Expected kind of problem.
	 * @param int    $code    Expected code.
	 * @return void
	 */
	public function test_smtp_errors( $message, $cause, $code ) {
		$failure = Classifier::classify( $this->error( $message ), 'smtp' );

		$this->assertSame( $cause, $failure->cause() );
		$this->assertSame( $code, $failure->code() );
		$this->assertSame( 'smtp', $failure->transport() );
	}

	/**
	 * Brevo API responses, and what they mean.
	 *
	 * @return array
	 */
	public function api_errors() {
		return array(
			'bad key'          => array( 401, 'Brevo refused the message (HTTP 401): {"code":"unauthorized","message":"Key not found"}', Classifier::AUTH ),
			'forbidden'        => array( 403, 'Brevo refused the message (HTTP 403): {"code":"permission_denied"}', Classifier::AUTH ),
			'rate limited'     => array( 429, 'Brevo refused the message (HTTP 429): {"code":"too_many_requests"}', Classifier::RATE_LIMIT ),
			'outage 502'       => array( 502, 'Brevo refused the message (HTTP 502): Bad Gateway', Classifier::PROVIDER ),
			'outage 503'       => array( 503, 'Brevo refused the message (HTTP 503): {"code":"unavailable"}', Classifier::PROVIDER ),
			'never connected'  => array( 0, 'Brevo refused the message (HTTP 0): cURL error 28: Operation timed out after 10001 milliseconds', Classifier::CONNECTION ),
			'bad sender'       => array( 400, 'Brevo refused the message (HTTP 400): {"code":"invalid_parameter","message":"sender is not valid"}', Classifier::SENDER ),
			'unrecognised 400' => array( 400, 'Brevo refused the message (HTTP 400): {"code":"invalid_parameter","message":"htmlContent is missing"}', Classifier::UNKNOWN ),
		);
	}

	/**
	 * API errors are read by their HTTP status first.
	 *
	 * @dataProvider api_errors
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Error text.
	 * @param string $cause   Expected kind of problem.
	 * @return void
	 */
	public function test_api_errors( $status, $message, $cause ) {
		$failure = Classifier::classify( $this->error( $message, 'brevo', $status ), 'smtp' );

		$this->assertSame( $cause, $failure->cause() );
		$this->assertSame( $status, $failure->code() );
		$this->assertSame( 'brevo', $failure->transport(), 'The route named in the error wins over the fallback.' );
	}

	/**
	 * One outage answering with different codes is still one problem.
	 *
	 * @return void
	 */
	public function test_codes_do_not_split_a_signature() {
		$first  = Classifier::classify( $this->error( 'Bad Gateway', 'brevo', 502 ), 'brevo' );
		$second = Classifier::classify( $this->error( 'Service Unavailable', 'brevo', 503 ), 'brevo' );
		$third  = Classifier::classify( $this->error( 'Gateway Timeout', 'brevo', 504 ), 'brevo' );

		$this->assertSame( 'brevo:provider', $first->signature() );
		$this->assertSame( $first->signature(), $second->signature() );
		$this->assertSame( $first->signature(), $third->signature() );
	}

	/**
	 * Without a route in the error, the fallback is used.
	 *
	 * @return void
	 */
	public function test_fallback_transport() {
		$failure = Classifier::classify( new \WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' ), 'default' );

		$this->assertSame( 'default:connection', $failure->signature() );
	}
}
