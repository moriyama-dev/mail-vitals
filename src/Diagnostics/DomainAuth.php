<?php
/**
 * Checks the DNS records that decide whether mail is trusted.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inspects SPF, DKIM and DMARC for the domain this site sends from.
 *
 * These three records decide most delivery outcomes. A site can have a
 * perfectly configured mail plugin and still have every message filtered
 * because the domain never authorised the server that sends for it.
 */
final class DomainAuth {

	/**
	 * How long a completed check is reused, in seconds.
	 *
	 * @var int
	 */
	const CACHE_LIFETIME = HOUR_IN_SECONDS;

	/**
	 * Prefix of the transient holding a cached check.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'mail_vitals_domain_auth_';

	/**
	 * Selectors to try when looking for a DKIM key.
	 *
	 * DKIM keys live at a selector chosen by whoever set them up, and there is
	 * no way to enumerate them over DNS. This covers the selectors the common
	 * providers use; a key published anywhere else is reported as "not found",
	 * which the summary says explicitly so the result is not misread.
	 *
	 * @var string[]
	 */
	const DKIM_SELECTORS = array(
		'default',
		'dkim',
		'google',
		'k1',
		'mail',
		'mg',
		'pm',
		's1',
		's2',
		'selector1',
		'selector2',
		'zoho',
	);

	/**
	 * The domain being inspected.
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * Inspect a specific domain.
	 *
	 * @param string $domain Domain to inspect, without a leading "@".
	 */
	public function __construct( $domain ) {
		$this->domain = strtolower( ltrim( trim( $domain ), '@' ) );
	}

	/**
	 * Build a check for the domain this site actually sends from.
	 *
	 * Reads the address WordPress will put in the From header, filters and all,
	 * rather than the site URL, because a plugin may well have changed it.
	 *
	 * @return self
	 */
	public static function for_this_site() {
		/** This filter is documented in wp-includes/pluggable.php */
		$from = apply_filters( 'wp_mail_from', self::default_from_address() );
		$at   = strrpos( (string) $from, '@' );

		return new self( false === $at ? '' : substr( (string) $from, $at + 1 ) );
	}

	/**
	 * The From address WordPress uses when nothing overrides it.
	 *
	 * Mirrors the derivation in wp_mail() so that the unfiltered value matches
	 * what would really be sent.
	 *
	 * @return string
	 */
	private static function default_from_address() {
		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';

		if ( 'www.' === substr( $host, 0, 4 ) ) {
			$host = substr( $host, 4 );
		}

		return 'wordpress@' . $host;
	}

	/**
	 * The domain being inspected.
	 *
	 * @return string
	 */
	public function domain() {
		return $this->domain;
	}

	/**
	 * Run every check, reusing a recent result when there is one.
	 *
	 * @param bool $fresh Skip the cache and query DNS again.
	 * @return Finding[]
	 */
	public function run( $fresh = false ) {
		$key = self::CACHE_PREFIX . md5( $this->domain );

		if ( ! $fresh ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return array_map( array( $this, 'hydrate' ), $cached );
			}
		}

		$findings = array(
			$this->check_domain(),
			$this->check_spf(),
			$this->check_dkim(),
			$this->check_dmarc(),
		);

		$findings = array_values( array_filter( $findings ) );

		set_transient(
			$key,
			array_map(
				static function ( Finding $finding ) {
					return $finding->to_array();
				},
				$findings
			),
			self::CACHE_LIFETIME
		);

		return $findings;
	}

	/**
	 * Rebuild a finding from its stored array form.
	 *
	 * @param array $stored Array produced by Finding::to_array().
	 * @return Finding
	 */
	private function hydrate( array $stored ) {
		return new Finding(
			$stored['id'],
			$stored['label'],
			$stored['status'],
			$stored['summary'],
			$stored['detail']
		);
	}

	/**
	 * Check that the sending domain is one that can carry authentication.
	 *
	 * A host name with no dot, such as the "localhost" a local install derives,
	 * is rejected outright by the mail library before anything is sent. This is
	 * reported first because every later check is meaningless without it.
	 *
	 * @return Finding
	 */
	private function check_domain() {
		$label = __( 'Sending domain', 'mail-vitals' );

		if ( '' === $this->domain || false === strpos( $this->domain, '.' ) ) {
			return new Finding(
				'sending_domain',
				$label,
				Finding::STATUS_CRITICAL,
				sprintf(
					/* translators: %s: the domain part of the site's From address. */
					__( 'Mail is sent from "%s", which is not a usable domain. Messages are rejected before they leave the server, and no amount of mail configuration will change that. Set a real domain in the From address.', 'mail-vitals' ),
					$this->domain
				),
				array( 'domain' => $this->domain )
			);
		}

		return new Finding(
			'sending_domain',
			$label,
			Finding::STATUS_GOOD,
			sprintf(
				/* translators: %s: the domain part of the site's From address. */
				__( 'Mail is sent from the domain "%s".', 'mail-vitals' ),
				$this->domain
			),
			array( 'domain' => $this->domain )
		);
	}

	/**
	 * Check that the domain publishes an SPF record.
	 *
	 * @return Finding|null Null when the domain is unusable and was already reported.
	 */
	private function check_spf() {
		$label = __( 'SPF record', 'mail-vitals' );

		$unavailable = $this->unavailable_finding( 'spf', $label );
		if ( $unavailable instanceof Finding ) {
			return $unavailable;
		}

		$record = Dns::txt_record_starting_with( $this->domain, 'v=spf1' );

		if ( null === $record ) {
			return new Finding(
				'spf',
				$label,
				Finding::STATUS_CRITICAL,
				__( 'No SPF record was found. Receiving servers have no way to tell which machines may send for this domain, so mail from it is widely treated as suspicious.', 'mail-vitals' ),
				array( 'domain' => $this->domain )
			);
		}

		if ( preg_match( '/\s\+all\b/i', $record ) ) {
			return new Finding(
				'spf',
				$label,
				Finding::STATUS_CRITICAL,
				__( 'The SPF record ends in "+all", which authorises every server on the internet to send as this domain. That is the same as publishing no policy, and some providers treat it as worse.', 'mail-vitals' ),
				array(
					'domain' => $this->domain,
					'record' => $record,
				)
			);
		}

		/*
		 * A "redirect=" modifier hands the whole policy to another domain, so a
		 * record carrying one is complete without an "all" mechanism of its own.
		 * Treating it as incomplete would wrongly flag every site that delegates
		 * its mail to a provider, which is most of them.
		 */
		$delegates = (bool) preg_match( '/\bredirect\s*=\s*\S+/i', $record );

		if ( ! $delegates && ! preg_match( '/\s[-~]all\b/i', $record ) ) {
			return new Finding(
				'spf',
				$label,
				Finding::STATUS_WARNING,
				__( 'An SPF record exists but does not end in "-all" or "~all", so it never says what to do about servers it has not listed. Receivers are left to guess.', 'mail-vitals' ),
				array(
					'domain' => $this->domain,
					'record' => $record,
				)
			);
		}

		if ( $delegates ) {
			return new Finding(
				'spf',
				$label,
				Finding::STATUS_GOOD,
				__( 'An SPF record is published and delegates its policy to another domain with "redirect=".', 'mail-vitals' ),
				array(
					'domain' => $this->domain,
					'record' => $record,
				)
			);
		}

		return new Finding(
			'spf',
			$label,
			Finding::STATUS_GOOD,
			__( 'An SPF record is published and states a policy for unlisted servers.', 'mail-vitals' ),
			array(
				'domain' => $this->domain,
				'record' => $record,
			)
		);
	}

	/**
	 * Look for a DKIM key at the selectors the common providers use.
	 *
	 * @return Finding|null Null when the domain is unusable and was already reported.
	 */
	private function check_dkim() {
		$label = __( 'DKIM key', 'mail-vitals' );

		$unavailable = $this->unavailable_finding( 'dkim', $label );
		if ( $unavailable instanceof Finding ) {
			return $unavailable;
		}

		/*
		 * Some domains answer every name under _domainkey with a wildcard
		 * record. Asking for a selector that cannot legitimately exist tells us
		 * whether that is happening; without this probe, such a domain appears
		 * to publish a key at every selector we try, which means nothing.
		 */
		$probe = 'mv' . strtolower( wp_generate_password( 16, false, false ) ) . '._domainkey.' . $this->domain;

		if ( null !== Dns::txt_record_starting_with( $probe, 'v=DKIM1' ) ) {
			return new Finding(
				'dkim',
				$label,
				Finding::STATUS_UNKNOWN,
				__( 'This domain returns a DKIM record for every selector asked for, including one invented at random, so nothing can be concluded about which keys actually exist. A wildcard DNS record is the usual cause.', 'mail-vitals' ),
				array(
					'domain'   => $this->domain,
					'wildcard' => true,
				)
			);
		}

		$found = array();

		foreach ( self::DKIM_SELECTORS as $selector ) {
			$host   = $selector . '._domainkey.' . $this->domain;
			$record = Dns::txt_record_starting_with( $host, 'v=DKIM1' );

			if ( null !== $record && self::is_live_key( $record ) ) {
				$found[] = $selector;
			}
		}

		if ( empty( $found ) ) {
			return new Finding(
				'dkim',
				$label,
				Finding::STATUS_WARNING,
				__( 'No DKIM key was found at any of the selectors the common mail providers use. DNS cannot be asked to list selectors, so a key published under a name of your own would not be seen here. Without DKIM, nothing cryptographically ties a message to this domain.', 'mail-vitals' ),
				array(
					'domain'            => $this->domain,
					'selectors_checked' => self::DKIM_SELECTORS,
				)
			);
		}

		return new Finding(
			'dkim',
			$label,
			Finding::STATUS_GOOD,
			sprintf(
				/* translators: %s: comma-separated list of DKIM selector names. */
				__( 'A DKIM key is published at the selector "%s".', 'mail-vitals' ),
				implode( '", "', $found )
			),
			array(
				'domain'            => $this->domain,
				'selectors_found'   => $found,
				'selectors_checked' => self::DKIM_SELECTORS,
			)
		);
	}

	/**
	 * Whether a DKIM record carries a key rather than a revocation.
	 *
	 * An empty "p=" is how a key is withdrawn. The record still parses and
	 * still begins with "v=DKIM1", so it has to be rejected explicitly or a
	 * revoked selector counts as a working one.
	 *
	 * @param string $record The DKIM TXT record to examine.
	 * @return bool
	 */
	private static function is_live_key( $record ) {
		return (bool) preg_match( '#\bp\s*=\s*[A-Za-z0-9+/=]+#', $record );
	}

	/**
	 * Check that the domain publishes a DMARC policy.
	 *
	 * @return Finding|null Null when the domain is unusable and was already reported.
	 */
	private function check_dmarc() {
		$label = __( 'DMARC policy', 'mail-vitals' );

		$unavailable = $this->unavailable_finding( 'dmarc', $label );
		if ( $unavailable instanceof Finding ) {
			return $unavailable;
		}

		$record = Dns::txt_record_starting_with( '_dmarc.' . $this->domain, 'v=DMARC1' );

		if ( null === $record ) {
			return new Finding(
				'dmarc',
				$label,
				Finding::STATUS_WARNING,
				__( 'No DMARC policy was found. Gmail and Yahoo now require one from anyone sending them bulk mail, and without it SPF and DKIM failures carry no stated consequence.', 'mail-vitals' ),
				array( 'domain' => $this->domain )
			);
		}

		if ( preg_match( '/\bp\s*=\s*none\b/i', $record ) ) {
			return new Finding(
				'dmarc',
				$label,
				Finding::STATUS_WARNING,
				__( 'A DMARC policy is published but set to "p=none", which only asks for reports and tells receivers to take no action. That is a reasonable place to start and a poor place to stay.', 'mail-vitals' ),
				array(
					'domain' => $this->domain,
					'record' => $record,
				)
			);
		}

		return new Finding(
			'dmarc',
			$label,
			Finding::STATUS_GOOD,
			__( 'A DMARC policy is published and asks receivers to act on failures.', 'mail-vitals' ),
			array(
				'domain' => $this->domain,
				'record' => $record,
			)
		);
	}

	/**
	 * Produce a finding when a DNS check cannot be carried out at all.
	 *
	 * @param string $id    Identifier of the check being skipped.
	 * @param string $label Human-readable name of the check being skipped.
	 * @return Finding|null Null when the check can proceed normally.
	 */
	private function unavailable_finding( $id, $label ) {
		if ( '' === $this->domain || false === strpos( $this->domain, '.' ) ) {
			return new Finding(
				$id,
				$label,
				Finding::STATUS_UNKNOWN,
				__( 'Skipped, because the site has no usable sending domain to look up.', 'mail-vitals' ),
				array( 'domain' => $this->domain )
			);
		}

		if ( ! Dns::is_available() ) {
			return new Finding(
				$id,
				$label,
				Finding::STATUS_UNKNOWN,
				__( 'This server does not allow PHP to look up DNS records, so the check could not run. This is not the same as the record being missing.', 'mail-vitals' ),
				array( 'domain' => $this->domain )
			);
		}

		return null;
	}
}
