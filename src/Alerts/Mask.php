<?php
/**
 * Hides personal details in text that leaves the site.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortens and masks error text before it is sent to a chat or a webhook.
 *
 * Masking is best effort and does not guarantee that nothing personal gets
 * through: an address written in an unexpected form will not be caught. The
 * complete text stays in the send log on the site.
 */
final class Mask {

	/**
	 * Longest error text an alert carries.
	 *
	 * @var int
	 */
	const MAX_LENGTH = 300;

	/**
	 * Mask email addresses and cut the text to a readable length.
	 *
	 * @param string $text Error text.
	 * @return string
	 */
	public static function error_text( $text ) {
		$text = self::emails( (string) $text );

		if ( mb_strlen( $text ) > self::MAX_LENGTH ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_LENGTH ) ) . '…';
		}

		return $text;
	}

	/**
	 * Replace each email address with its first letter and its domain,
	 * for example "user@example.com" becomes "u***@example.com". The domain
	 * is kept because it is usually what explains the failure.
	 *
	 * @param string $text Text that may contain addresses.
	 * @return string
	 */
	public static function emails( $text ) {
		return (string) preg_replace(
			'/([A-Za-z0-9])[A-Za-z0-9._%+\-]*@([A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)+)/',
			'$1***@$2',
			(string) $text
		);
	}
}
