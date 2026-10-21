<?php
/**
 * Reads the headers wp_mail() was handed.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns wp_mail()'s loose header argument into named values.
 *
 * Callers may pass headers as a single newline-joined string or as an array,
 * so both shapes have to be accepted before anything can be read out of them.
 */
final class Headers {

	/**
	 * Extract the headers an API transport needs to honour.
	 *
	 * @param string|string[] $headers Headers as given to wp_mail().
	 * @return array {
	 *     @type string   $content_type Lower-cased MIME type, when one was given.
	 *     @type string[] $cc           Carbon copy recipients.
	 *     @type string[] $bcc          Blind carbon copy recipients.
	 *     @type string[] $reply_to     Reply-To recipients.
	 * }
	 */
	public static function parse( $headers ) {
		$parsed = array(
			'content_type' => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
		);

		foreach ( self::as_lines( $headers ) as $line ) {
			$colon = strpos( $line, ':' );

			if ( false === $colon ) {
				continue;
			}

			$name  = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			switch ( $name ) {
				case 'content-type':
					$semicolon              = strpos( $value, ';' );
					$parsed['content_type'] = strtolower( trim( false === $semicolon ? $value : substr( $value, 0, $semicolon ) ) );
					break;

				case 'cc':
				case 'bcc':
					$parsed[ $name ] = array_merge( $parsed[ $name ], self::split_list( $value ) );
					break;

				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], self::split_list( $value ) );
					break;
			}
		}

		return $parsed;
	}

	/**
	 * Normalise the header argument into one entry per line.
	 *
	 * @param string|string[] $headers Headers as given to wp_mail().
	 * @return string[]
	 */
	private static function as_lines( $headers ) {
		if ( is_array( $headers ) ) {
			return array_filter( array_map( 'trim', $headers ) );
		}

		return array_filter( array_map( 'trim', explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) ) ) );
	}

	/**
	 * Split a comma-separated recipient list.
	 *
	 * @param string $value Raw header value.
	 * @return string[]
	 */
	private static function split_list( $value ) {
		return array_filter( array_map( 'trim', explode( ',', $value ) ) );
	}
}
