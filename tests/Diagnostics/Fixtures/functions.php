<?php
/**
 * Stand-ins for another plugin's mail callbacks.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Diagnostics\Fixtures;

/**
 * Plain function, attached by name.
 *
 * @param object $mailer Mailer instance.
 * @return void
 */
function configure_mailer( $mailer ) {
	unset( $mailer );
}

/**
 * A closure defined in this file, as a plugin attaching an anonymous
 * function would leave it.
 *
 * @return \Closure
 */
function mailer_closure() {
	return static function ( $mailer ) {
		unset( $mailer );
	};
}
