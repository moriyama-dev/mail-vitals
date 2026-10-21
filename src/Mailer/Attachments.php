<?php
/**
 * Attachment handling for API transports.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prepares files for transports that cannot stream them.
 */
final class Attachments {

	/**
	 * Read attachments into the base64 form an API expects.
	 *
	 * Unreadable paths are skipped rather than aborting the send: losing an
	 * attachment is bad, losing the message as well is worse.
	 *
	 * @param string|string[] $attachments Paths as given to wp_mail().
	 * @return array[] One entry per file that could be read.
	 */
	public static function encode( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = '' === trim( (string) $attachments ) ? array() : array( $attachments );
		}

		$encoded = array();

		foreach ( $attachments as $name => $path ) {
			$path = (string) $path;

			if ( ! is_readable( $path ) || ! is_file( $path ) ) {
				continue;
			}

			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local attachment, not a remote resource.

			if ( false === $contents ) {
				continue;
			}

			$encoded[] = array(
				'name'    => is_string( $name ) ? $name : basename( $path ),
				'content' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API requires base64, this is not obfuscation.
			);
		}

		return $encoded;
	}
}
