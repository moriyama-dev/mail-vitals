<?php
/**
 * Reaches a mail route without sending anything through it.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds out whether a configured route can be reached at all.
 *
 * Nothing is sent. For SMTP the connection is opened, the server's greeting
 * read, and the connection closed: that is enough to tell a blocked port or a
 * wrong host from a working server, which is what most SMTP failures come down
 * to. For Brevo the account is looked up with the key, which says whether the
 * key is accepted without spending a message on it.
 *
 * Results are kept for ten minutes, so a settings screen or Site Health page
 * does not wait on the network every time it is opened.
 */
final class Probe {

	/**
	 * Seconds to wait for a server.
	 *
	 * @var int
	 */
	const TIMEOUT = 3;

	/**
	 * How long a result is kept, in seconds.
	 *
	 * @var int
	 */
	const CACHE_LIFETIME = 600;

	/**
	 * Prefix of the transient holding a result.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'mail_vitals_probe_';

	/**
	 * Brevo's account endpoint, which answers for a valid key and sends nothing.
	 *
	 * @var string
	 */
	const BREVO_ACCOUNT = 'https://api.brevo.com/v3/account';

	/**
	 * Replacement for opening a connection, for tests.
	 *
	 * @var callable|null
	 */
	private static $connector = null;

	/**
	 * Connect to an SMTP server and read its greeting.
	 *
	 * @param string $host       Host name.
	 * @param int    $port       Port.
	 * @param string $encryption "ssl" for a connection encrypted from the start, anything else for plain.
	 * @param bool   $fresh      Ignore a kept result.
	 * @return array With status ("reached", "not_smtp", "unreachable" or "unavailable"), greeting and error.
	 */
	public static function smtp( $host, $port, $encryption, $fresh = false ) {
		$key = self::CACHE_PREFIX . md5( 'smtp|' . $host . '|' . $port . '|' . $encryption );

		if ( ! $fresh ) {
			$kept = get_transient( $key );

			if ( is_array( $kept ) ) {
				return $kept;
			}
		}

		$result = null !== self::$connector
			? call_user_func( self::$connector, $host, $port, $encryption )
			: self::greet( $host, $port, $encryption );

		set_transient( $key, $result, self::CACHE_LIFETIME );

		return $result;
	}

	/**
	 * Ask Brevo whether it accepts a key.
	 *
	 * @param string $key   API key.
	 * @param bool   $fresh Ignore a kept result.
	 * @return array With status ("accepted", "rejected" or "unreachable") and error.
	 */
	public static function brevo( $key, $fresh = false ) {
		// The key itself is never stored, only a hash of it to find the result.
		$cache = self::CACHE_PREFIX . md5( 'brevo|' . $key );

		if ( ! $fresh ) {
			$kept = get_transient( $cache );

			if ( is_array( $kept ) ) {
				return $kept;
			}
		}

		$response = wp_remote_get(
			self::BREVO_ACCOUNT,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'api-key' => $key,
					'accept'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'status' => 'unreachable',
				'error'  => $response->get_error_message(),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$result = array(
				'status' => 200 === $code ? 'accepted' : ( 401 === $code || 403 === $code ? 'rejected' : 'unreachable' ),
				'error'  => 200 === $code ? '' : sprintf( 'HTTP %d: %s', $code, mb_substr( trim( (string) wp_remote_retrieve_body( $response ) ), 0, 200 ) ),
			);
		}

		set_transient( $cache, $result, self::CACHE_LIFETIME );

		return $result;
	}

	/**
	 * Use a different way of connecting, or null for the real one. For tests.
	 *
	 * @param callable|null $connector Receives host, port and encryption; returns what smtp() returns.
	 * @return void
	 */
	public static function use_connector( $connector ) {
		self::$connector = $connector;
	}

	/**
	 * Open the connection and read the first line the server sends.
	 *
	 * A mail server greets with a line starting "220" before anything is
	 * asked of it. Reading that one line and hanging up sends nothing.
	 *
	 * @param string $host       Host name.
	 * @param int    $port       Port.
	 * @param string $encryption "ssl" for implicit TLS.
	 * @return array
	 */
	private static function greet( $host, $port, $encryption ) {
		if ( ! function_exists( 'stream_socket_client' ) ) {
			return array(
				'status'   => 'unavailable',
				'greeting' => '',
				'error'    => __( 'This server does not allow PHP to open network connections, so the check could not run.', 'mail-vitals' ),
			);
		}

		$scheme  = 'ssl' === $encryption ? 'ssl' : 'tcp';
		$context = stream_context_create( array( 'ssl' => array( 'peer_name' => $host ) ) );
		$errno   = 0;
		$errstr  = '';

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A raw socket has no WordPress API; a refused connection is reported through $errstr, not as a PHP warning on the page.
		$socket = @stream_socket_client( $scheme . '://' . $host . ':' . (int) $port, $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context );

		if ( false === $socket ) {
			return array(
				'status'   => 'unreachable',
				'greeting' => '',
				'error'    => '' !== $errstr ? $errstr : sprintf( 'error %d', $errno ),
			);
		}

		stream_set_timeout( $socket, self::TIMEOUT );
		$greeting = fgets( $socket, 512 );
		fclose( $socket );
		// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$greeting = false === $greeting ? '' : trim( $greeting );

		return array(
			'status'   => 0 === strpos( $greeting, '220' ) ? 'reached' : 'not_smtp',
			'greeting' => mb_substr( $greeting, 0, 200 ),
			'error'    => '',
		);
	}
}
