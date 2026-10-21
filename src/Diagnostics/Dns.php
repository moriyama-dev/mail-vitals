<?php
/**
 * DNS lookups used by the diagnostics.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the TXT records that mail authentication is published in.
 *
 * Deliberately has no cache of its own: callers cache whole findings instead,
 * so that one stored value covers every lookup a single check performed.
 */
final class Dns {

	/**
	 * Whether this server lets PHP resolve DNS records at all.
	 *
	 * Shared hosts occasionally disable the function. When that happens the
	 * diagnostics have to say "could not check" rather than "record missing",
	 * because the two call for completely different responses.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'dns_get_record' );
	}

	/**
	 * Fetch the TXT records published at a host name.
	 *
	 * @param string $host Fully qualified host name to query.
	 * @return string[] One entry per record; empty when none exist or the lookup failed.
	 */
	public static function txt_records( $host ) {
		if ( ! self::is_available() ) {
			return array();
		}

		/*
		 * A host with no records is an ordinary answer to this question, and
		 * dns_get_record() reports it as a PHP warning. Silencing it here keeps
		 * an expected outcome out of the site's error log.
		 */
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$records = @dns_get_record( $host, DNS_TXT );

		if ( ! is_array( $records ) ) {
			return array();
		}

		$values = array();

		foreach ( $records as $record ) {
			if ( isset( $record['txt'] ) ) {
				$values[] = (string) $record['txt'];
			}
		}

		return $values;
	}

	/**
	 * Find the first TXT record at a host that opens with a given tag.
	 *
	 * @param string $host Fully qualified host name to query.
	 * @param string $tag  Case-insensitive prefix identifying the record, such as "v=spf1".
	 * @return string|null The matching record, or null when there is none.
	 */
	public static function txt_record_starting_with( $host, $tag ) {
		foreach ( self::txt_records( $host ) as $record ) {
			if ( 0 === stripos( ltrim( $record ), $tag ) ) {
				return $record;
			}
		}

		return null;
	}
}
