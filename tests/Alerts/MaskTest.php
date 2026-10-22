<?php
/**
 * Masking text that leaves the site.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Alerts\Mask;

/**
 * Addresses are masked and long text is cut.
 */
final class MaskTest extends \WP_UnitTestCase {

	/**
	 * Addresses keep their first letter and their domain.
	 *
	 * @return void
	 */
	public function test_masks_addresses() {
		$this->assertSame(
			'Recipient failed: u***@example.com: 550 <j***@mail.example.co.jp> unknown',
			Mask::emails( 'Recipient failed: user.name+tag@example.com: 550 <jane@mail.example.co.jp> unknown' )
		);
	}

	/**
	 * Text without addresses is left alone.
	 *
	 * @return void
	 */
	public function test_leaves_other_text() {
		$this->assertSame( 'SMTP Error: Could not authenticate.', Mask::emails( 'SMTP Error: Could not authenticate.' ) );
	}

	/**
	 * Long text is cut on a character boundary.
	 *
	 * @return void
	 */
	public function test_cuts_long_text() {
		$text = Mask::error_text( str_repeat( 'あ', 400 ) );

		$this->assertSame( Mask::MAX_LENGTH + 1, mb_strlen( $text ) );
		$this->assertTrue( (bool) preg_match( '//u', $text ), 'Still valid UTF-8.' );
	}
}
