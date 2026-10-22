<?php
/**
 * Posts JSON to a channel.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one HTTP call every channel makes.
 *
 * The timeout is short because the call happens while a page is being
 * served. It happens at most once per incident per interval, so a slow
 * channel costs a few seconds on rare requests rather than on every send.
 */
final class Http {

	/**
	 * Seconds to wait for a channel to answer.
	 *
	 * @var int
	 */
	const TIMEOUT = 3;

	/**
	 * Whether a URL can be posted to.
	 *
	 * @param string $url        URL from wp-config.php.
	 * @param bool   $https_only Refuse plain http.
	 * @return bool
	 */
	public static function valid_url( $url, $https_only = false ) {
		$parts = wp_parse_url( (string) $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$schemes = $https_only ? array( 'https' ) : array( 'http', 'https' );

		return in_array( strtolower( $parts['scheme'] ), $schemes, true );
	}

	/**
	 * Post a JSON body.
	 *
	 * @param string $url     Destination.
	 * @param string $body    JSON text, already encoded so it can be signed as sent.
	 * @param array  $headers Extra headers.
	 * @return true|\WP_Error
	 */
	public static function post_json( $url, $body, array $headers = array() ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array_merge(
					array(
						'Content-Type' => 'application/json; charset=utf-8',
						'User-Agent'   => 'MailVitals/' . MAIL_VITALS_VERSION . '; ' . home_url( '/' ),
					),
					$headers
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'mail_vitals_alert_refused',
				sprintf(
					/* translators: 1: HTTP status code, 2: start of the response body. */
					__( 'The channel answered HTTP %1$d: %2$s', 'mail-vitals' ),
					$code,
					mb_substr( (string) wp_remote_retrieve_body( $response ), 0, 200 )
				)
			);
		}

		return true;
	}
}
