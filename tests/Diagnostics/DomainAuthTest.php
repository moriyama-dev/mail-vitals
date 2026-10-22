<?php
/**
 * Whether the sending domain can carry authentication at all.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Diagnostics;

use MailVitals\Diagnostics\DomainAuth;
use MailVitals\Diagnostics\Finding;

/**
 * Only cases decided before any DNS lookup, so nothing leaves the machine.
 */
final class DomainAuthTest extends \WP_UnitTestCase {

	/**
	 * Findings for a domain, by id.
	 *
	 * @param string $domain Domain part of the From address.
	 * @return Finding[]
	 */
	private function findings( $domain ) {
		$by_id = array();

		foreach ( ( new DomainAuth( $domain ) )->run( true ) as $finding ) {
			$by_id[ $finding->id() ] = $finding;
		}

		return $by_id;
	}

	/**
	 * Data: IP addresses in the forms an email address can carry.
	 *
	 * @return array
	 */
	public function ip_addresses() {
		return array(
			'IPv4'             => array( '192.168.1.1' ),
			'IPv4 in brackets' => array( '[203.0.113.5]' ),
			'IPv6 in brackets' => array( '[2001:db8::1]' ),
			'IPv6 with prefix' => array( '[IPv6:2001:db8::1]' ),
		);
	}

	/**
	 * An IP address is not a sending domain, and nothing is looked up for it.
	 *
	 * @dataProvider ip_addresses
	 *
	 * @param string $address Domain part of the From address.
	 * @return void
	 */
	public function test_an_ip_address_is_critical( $address ) {
		$findings = $this->findings( $address );

		$this->assertSame( Finding::STATUS_CRITICAL, $findings['sending_domain']->status() );
		$this->assertStringContainsString( 'IP address', $findings['sending_domain']->summary() );

		foreach ( array( 'spf', 'dkim', 'dmarc' ) as $id ) {
			$this->assertSame( Finding::STATUS_UNKNOWN, $findings[ $id ]->status(), $id . ' is skipped.' );
		}
	}

	/**
	 * A host name with no dot is still reported as before.
	 *
	 * @return void
	 */
	public function test_a_name_without_a_dot_is_critical() {
		$findings = $this->findings( 'localhost' );

		$this->assertSame( Finding::STATUS_CRITICAL, $findings['sending_domain']->status() );
		$this->assertStringContainsString( 'not a usable domain', $findings['sending_domain']->summary() );
	}
}
