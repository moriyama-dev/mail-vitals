<?php
/**
 * Stand-in for another plugin's mailer class.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Diagnostics\Fixtures;

/**
 * Offers a callback of every method shape a plugin might attach.
 */
final class OtherMailer {

	/**
	 * Instance method, attached as array( $object, 'configure' ).
	 *
	 * @param object $mailer Mailer instance.
	 * @return void
	 */
	public function configure( $mailer ) {
		unset( $mailer );
	}

	/**
	 * Static method, attached as a pair or as a "Class::method" string.
	 *
	 * @param mixed $short_circuit Earlier result.
	 * @return mixed
	 */
	public static function short_circuit( $short_circuit ) {
		return $short_circuit;
	}

	/**
	 * Makes the object itself callable.
	 *
	 * @param object $mailer Mailer instance.
	 * @return void
	 */
	public function __invoke( $mailer ) {
		unset( $mailer );
	}

	/**
	 * Answers methods that are not declared, so a callback can name one.
	 *
	 * @param string $name      Method name.
	 * @param array  $arguments Arguments.
	 * @return null
	 */
	public function __call( $name, $arguments ) {
		unset( $name, $arguments );

		return null;
	}
}
