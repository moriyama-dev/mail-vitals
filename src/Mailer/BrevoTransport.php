<?php
/**
 * Delivery over the Brevo HTTP API.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands the message to Brevo over HTTPS instead of speaking SMTP.
 *
 * SMTP needs several round trips before a message is accepted, which is where
 * shared hosts tend to time out. One HTTPS request does not, and it comes back
 * with a reason when it fails rather than a closed socket.
 */
final class BrevoTransport implements Transport {

	/**
	 * Where messages are posted.
	 *
	 * @var string
	 */
	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/**
	 * Stable identifier, matching the stored transport setting.
	 *
	 * @return string
	 */
	public function id() {
		return Settings::TRANSPORT_BREVO;
	}

	/**
	 * Whether an API key is available.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== Settings::secret( 'brevo_api_key' );
	}

	/**
	 * Take over sending entirely.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * Send one message through the API.
	 *
	 * @param null|bool $short_circuit Whatever an earlier filter decided.
	 * @param array     $atts          The arguments wp_mail() was called with.
	 * @return null|bool Null to let WordPress carry on, or the send result.
	 */
	public function send( $short_circuit, $atts ) {
		if ( null !== $short_circuit || ! Dispatcher::is_current( $this ) ) {
			return $short_circuit;
		}

		$headers = Headers::parse( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$payload = $this->build_payload( $atts, $headers );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => Settings::secret( 'brevo_api_key' ),
					'content-type' => 'application/json',
					'accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		return $this->interpret( $response, $atts );
	}

	/**
	 * Shape the message the way the API expects it.
	 *
	 * @param array $atts    The arguments wp_mail() was called with.
	 * @param array $headers Parsed headers.
	 * @return array
	 */
	private function build_payload( array $atts, array $headers ) {
		$is_html = isset( $headers['content_type'] ) && 'text/html' === $headers['content_type'];
		$body    = isset( $atts['message'] ) ? (string) $atts['message'] : '';

		$payload = array(
			'sender'  => array(
				'email' => Address::from_address(),
				'name'  => Address::from_name(),
			),
			'to'      => Address::to_list( isset( $atts['to'] ) ? $atts['to'] : array() ),
			'subject' => isset( $atts['subject'] ) ? (string) $atts['subject'] : '',
		);

		$payload[ $is_html ? 'htmlContent' : 'textContent' ] = $body;

		foreach ( array( 'cc', 'bcc', 'replyTo' ) as $field ) {
			$key = 'replyTo' === $field ? 'reply_to' : $field;

			if ( ! empty( $headers[ $key ] ) ) {
				$list = Address::to_list( $headers[ $key ] );

				if ( ! empty( $list ) ) {
					$payload[ $field ] = 'replyTo' === $field ? $list[0] : $list;
				}
			}
		}

		$attachments = Attachments::encode( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );

		if ( ! empty( $attachments ) ) {
			$payload['attachment'] = $attachments;
		}

		return $payload;
	}

	/**
	 * Turn the HTTP response into a wp_mail() result, keeping the reason on failure.
	 *
	 * @param array|\WP_Error $response Response from wp_remote_post().
	 * @param array           $atts     The arguments wp_mail() was called with.
	 * @return bool
	 */
	private function interpret( $response, array $atts ) {
		if ( is_wp_error( $response ) ) {
			$this->report_failure( $response->get_error_message(), 0, $atts );

			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			/*
			 * Core raises this after its own successful send. Raising it here
			 * too means anything listening - the log included - hears about an
			 * API send in exactly the way it hears about an SMTP one.
			 */
			/** This action is documented in wp-includes/pluggable.php */
			do_action( 'wp_mail_succeeded', array_merge( $atts, array( 'mail_vitals_transport' => $this->id() ) ) );

			return true;
		}

		$this->report_failure( $body, $code, $atts );

		return false;
	}

	/**
	 * Announce a failure the way WordPress announces its own.
	 *
	 * Firing the core hook means anything already listening for mail failures,
	 * including this plugin's own recorder, hears about an API failure in
	 * exactly the same way it hears about an SMTP one.
	 *
	 * @param string $reason Verbatim text the provider returned.
	 * @param int    $code   HTTP status code, or 0 when the request never completed.
	 * @param array  $atts   The arguments wp_mail() was called with.
	 * @return void
	 */
	private function report_failure( $reason, $code, array $atts ) {
		$error = new \WP_Error(
			'wp_mail_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: verbatim response from the mail provider. */
				__( 'Brevo refused the message (HTTP %1$d): %2$s', 'mail-vitals' ),
				$code,
				$reason
			),
			array_merge( $atts, array( 'mail_vitals_transport' => $this->id() ) )
		);

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', $error );
	}
}
