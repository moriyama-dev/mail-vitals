<?php
/**
 * Checks that the way this site sends mail can actually work.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

use MailVitals\Alerts\Alert;
use MailVitals\Mailer\Dispatcher;
use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks at the server and the configured routes as they are, not as they
 * were meant to be.
 *
 * A setting that is filled in is not the same as one that works. PHP's mail()
 * can be switched off by the host, or handed to a program that is not there;
 * an SMTP server can be unreachable because the host blocks the port; a Brevo
 * key can be the wrong kind of key. Each of these looks fine on a settings
 * screen and fails only when a message is sent. This finds them first, and
 * without sending anything.
 *
 * It also points out settings that are not doing anything: a key in
 * wp-config.php for a route that is not used is usually a sign that the site
 * is not sending the way its owner thinks it is.
 */
final class MailSetup {

	/**
	 * Examine everything.
	 *
	 * @param bool $fresh Reach the routes again instead of using a kept result.
	 * @return Finding[]
	 */
	public static function run( $fresh = false ) {
		$active   = Dispatcher::active();
		$routes   = Dispatcher::routes();
		$in_use   = array_map(
			static function ( $route ) {
				return $route->id();
			},
			$routes
		);
		$findings = array(
			self::php_mail(
				null === $active,
				$in_use,
				! function_exists( 'mail' ) || self::is_disabled( 'mail' ),
				PHP_OS_FAMILY,
				(string) ini_get( 'sendmail_path' ),
				(string) ini_get( 'open_basedir' )
			),
		);

		$unusable = self::chosen_but_unusable( Settings::transport(), null === $active );

		if ( null !== $unusable ) {
			$findings[] = $unusable;
		}

		foreach ( $routes as $index => $route ) {
			$backup = $index > 0;

			if ( Settings::TRANSPORT_SMTP === $route->id() ) {
				$smtp       = Settings::smtp();
				$findings[] = self::smtp_route(
					$smtp,
					Probe::smtp( $smtp['host'], $smtp['port'], $smtp['encryption'], $fresh ),
					$backup
				);
			}

			if ( Settings::TRANSPORT_BREVO === $route->id() ) {
				$key        = Settings::secret( 'brevo_api_key' );
				$findings[] = self::brevo_route(
					$key,
					self::is_smtp_key( $key ) ? null : Probe::brevo( $key, $fresh ),
					$backup
				);
			}
		}

		$unused = self::unused( $in_use, Settings::transport(), Settings::secret( 'brevo_api_key' ), Settings::secret( 'smtp_password' ), Settings::smtp() );

		if ( null !== $unused ) {
			$findings[] = $unused;
		}

		return $findings;
	}

	/**
	 * Whether PHP's own mail() can work, when WordPress is relying on it.
	 *
	 * @param bool     $used          Whether WordPress sends with mail(), which it does when no route is in charge.
	 * @param string[] $routes        Routes in use, for saying what is used instead.
	 * @param bool     $disabled      Whether mail() has been switched off.
	 * @param string   $os_family     PHP_OS_FAMILY.
	 * @param string   $sendmail_path The sendmail_path setting.
	 * @param string   $open_basedir  The open_basedir setting.
	 * @param bool     $exists        Override for whether the program exists, for tests. Null to look.
	 * @return Finding
	 */
	public static function php_mail( $used, array $routes, $disabled, $os_family, $sendmail_path, $open_basedir, $exists = null ) {
		$label  = __( 'PHP mail()', 'mail-vitals' );
		$detail = array(
			'used'          => (bool) $used,
			'disabled'      => (bool) $disabled,
			'sendmail_path' => $sendmail_path,
		);

		$problem = null;
		$program = '';

		if ( $disabled ) {
			$problem = __( 'This server has switched PHP\'s mail() off (disable_functions).', 'mail-vitals' );
		} elseif ( 'Windows' === $os_family ) {
			return new Finding( 'php_mail', $label, $used ? Finding::STATUS_UNKNOWN : Finding::STATUS_GOOD, $used ? __( 'On Windows, PHP\'s mail() sends through the SMTP server set in php.ini, which this check does not look at.', 'mail-vitals' ) : self::not_used( $routes ), $detail );
		} else {
			$program = self::program_of( $sendmail_path );

			if ( '' === $program ) {
				$problem = __( 'PHP\'s sendmail_path is empty, so mail() has nothing to hand messages to.', 'mail-vitals' );
			} else {
				if ( null === $exists ) {
					$exists = '' !== $open_basedir ? null : file_exists( $program );
				}

				$detail['program'] = $program;

				if ( null === $exists ) {
					return new Finding(
						'php_mail',
						$label,
						$used ? Finding::STATUS_UNKNOWN : Finding::STATUS_GOOD,
						$used
							/* translators: %s: path of the mail program PHP hands messages to. */
							? sprintf( __( 'PHP hands mail to "%s", but open_basedir keeps this check from seeing whether it exists.', 'mail-vitals' ), $program )
							: self::not_used( $routes ),
						$detail
					);
				}

				if ( ! $exists ) {
					$problem = sprintf(
						/* translators: %s: path of the mail program PHP hands messages to. */
						__( 'PHP hands mail to "%s", which does not exist on this server.', 'mail-vitals' ),
						$program
					);
				}
			}
		}

		if ( null === $problem ) {
			return new Finding(
				'php_mail',
				$label,
				Finding::STATUS_GOOD,
				$used
					/* translators: %s: path of the mail program PHP hands messages to. */
					? sprintf( __( 'PHP hands mail to "%s", which exists. Whether it delivers cannot be told without sending a message.', 'mail-vitals' ), $program )
					: self::not_used( $routes ),
				$detail
			);
		}

		if ( ! $used ) {
			return new Finding(
				'php_mail',
				$label,
				Finding::STATUS_GOOD,
				$problem . ' ' . self::not_used( $routes ) . ' ' . __( 'It would matter if the route were removed.', 'mail-vitals' ),
				$detail
			);
		}

		return new Finding(
			'php_mail',
			$label,
			Finding::STATUS_CRITICAL,
			$problem . ' ' . __( 'WordPress is sending with mail(), so every message fails. Set up an SMTP server or Brevo on this screen, or ask the host to make mail() work.', 'mail-vitals' ),
			$detail
		);
	}

	/**
	 * A route that is chosen but cannot be used, so WordPress quietly sends
	 * on its own instead.
	 *
	 * @param string $chosen      The transport setting.
	 * @param bool   $none_active Whether no route is actually in charge.
	 * @return Finding|null Null when the choice is in effect.
	 */
	public static function chosen_but_unusable( $chosen, $none_active ) {
		if ( Settings::TRANSPORT_DEFAULT === $chosen || ! $none_active ) {
			return null;
		}

		$summary = Settings::TRANSPORT_SMTP === $chosen
			? __( 'The SMTP server is selected, but its host or port is not filled in, so it is not used.', 'mail-vitals' )
			: __( 'Brevo is selected, but MAIL_VITALS_BREVO_API_KEY is not set in wp-config.php, so it is not used.', 'mail-vitals' );

		return new Finding(
			'selected_route',
			__( 'Selected route', 'mail-vitals' ),
			Finding::STATUS_CRITICAL,
			$summary . ' ' . __( 'WordPress is sending on its own with PHP\'s mail() instead.', 'mail-vitals' ),
			array( 'chosen' => $chosen )
		);
	}

	/**
	 * Whether the SMTP server can be logged in to and reached.
	 *
	 * @param array $smtp   Settings::smtp().
	 * @param array $probe  What Probe::smtp() found.
	 * @param bool  $backup Whether this is the backup route.
	 * @return Finding
	 */
	public static function smtp_route( array $smtp, array $probe, $backup ) {
		$address   = $smtp['host'] . ':' . $smtp['port'];
		$sentences = array();
		$status    = Finding::STATUS_GOOD;

		if ( '' !== $smtp['username'] && '' === $smtp['password'] ) {
			$status      = Finding::STATUS_CRITICAL;
			$sentences[] = __( 'A username is set but MAIL_VITALS_SMTP_PASSWORD is not, so Mail Vitals connects without logging in, and most servers refuse mail sent that way.', 'mail-vitals' );
		} elseif ( '' === $smtp['username'] && '' !== $smtp['password'] ) {
			$status      = Finding::STATUS_WARNING;
			$sentences[] = __( 'MAIL_VITALS_SMTP_PASSWORD is set but no username is, so the password is not used and the connection is made without logging in.', 'mail-vitals' );
		}

		switch ( $probe['status'] ) {
			case 'reached':
				$sentences[] = sprintf(
					/* translators: 1: host and port, 2: the server's greeting. */
					__( '%1$s answered as a mail server ("%2$s"). Logging in is not tried, so a wrong password would only show when sending.', 'mail-vitals' ),
					$address,
					$probe['greeting']
				);
				break;

			case 'not_smtp':
				$status      = self::worse( $status, Finding::STATUS_WARNING );
				$sentences[] = '' === $probe['greeting'] && 'ssl' !== $smtp['encryption'] && 465 === (int) $smtp['port']
					? sprintf(
						/* translators: %s: host and port. */
						__( '%s accepted the connection but said nothing. Port 465 expects an encrypted connection from the start; choose SSL as the encryption.', 'mail-vitals' ),
						$address
					)
					: sprintf(
						/* translators: 1: host and port, 2: what it sent instead of a mail server's greeting. */
						__( '%1$s accepted the connection but did not answer as a mail server ("%2$s").', 'mail-vitals' ),
						$address,
						$probe['greeting']
					);
				break;

			case 'unreachable':
				$status      = Finding::STATUS_CRITICAL;
				$sentences[] = sprintf(
					/* translators: 1: host and port, 2: error message. */
					__( 'Could not connect to %1$s: %2$s. A mistyped host, or a hosting provider that blocks outgoing mail ports, are the usual causes.', 'mail-vitals' ),
					$address,
					$probe['error']
				);
				break;

			default:
				$status      = self::worse( $status, Finding::STATUS_UNKNOWN );
				$sentences[] = $probe['error'];
		}

		return new Finding(
			'route_smtp',
			$backup ? __( 'SMTP server (backup)', 'mail-vitals' ) : __( 'SMTP server', 'mail-vitals' ),
			$status,
			implode( ' ', $sentences ),
			array(
				'host'       => $smtp['host'],
				'port'       => (int) $smtp['port'],
				'encryption' => $smtp['encryption'],
				'probe'      => $probe['status'],
			)
		);
	}

	/**
	 * Whether the Brevo key is the right kind, and accepted.
	 *
	 * @param string     $key    The key.
	 * @param array|null $probe  What Probe::brevo() found, or null when the key was not tried.
	 * @param bool       $backup Whether this is the backup route.
	 * @return Finding
	 */
	public static function brevo_route( $key, $probe, $backup ) {
		$label = $backup ? __( 'Brevo API (backup)', 'mail-vitals' ) : __( 'Brevo API', 'mail-vitals' );

		if ( self::is_smtp_key( $key ) ) {
			return new Finding(
				'route_brevo',
				$label,
				Finding::STATUS_CRITICAL,
				__( 'MAIL_VITALS_BREVO_API_KEY holds a Brevo SMTP key (it starts with "xsmtpsib-"). The API needs an API key, which Brevo issues separately and which starts with "xkeysib-".', 'mail-vitals' ),
				array( 'probe' => 'not_tried' )
			);
		}

		$status = 'accepted' === $probe['status'] ? Finding::STATUS_GOOD : ( 'rejected' === $probe['status'] ? Finding::STATUS_CRITICAL : Finding::STATUS_WARNING );

		if ( 'accepted' === $probe['status'] ) {
			$summary = __( 'Brevo accepted the key. Nothing was sent to check this.', 'mail-vitals' );
		} elseif ( 'rejected' === $probe['status'] ) {
			/* translators: %s: Brevo's answer. */
			$summary = sprintf( __( 'Brevo refused the key (%s). It may have been deleted or mistyped, or Brevo may only accept it from listed IP addresses.', 'mail-vitals' ), $probe['error'] );
		} else {
			/* translators: %s: error message. */
			$summary = sprintf( __( 'Could not reach Brevo to check the key: %s', 'mail-vitals' ), $probe['error'] );
		}

		return new Finding( 'route_brevo', $label, $status, $summary, array( 'probe' => $probe['status'] ) );
	}

	/**
	 * Settings for a route that is not being used.
	 *
	 * @param string[] $in_use        Routes in use.
	 * @param string   $chosen        The transport setting.
	 * @param string   $brevo_key     MAIL_VITALS_BREVO_API_KEY.
	 * @param string   $smtp_password MAIL_VITALS_SMTP_PASSWORD.
	 * @param array    $smtp          Settings::smtp().
	 * @return Finding|null Null when everything configured is in use.
	 */
	public static function unused( array $in_use, $chosen, $brevo_key, $smtp_password, array $smtp ) {
		$sentences = array();
		$why       = Settings::TRANSPORT_DEFAULT === $chosen
			? __( 'Sending is set to "Leave it to WordPress".', 'mail-vitals' )
			: '';

		if ( '' !== $brevo_key && ! in_array( Settings::TRANSPORT_BREVO, $in_use, true ) ) {
			$sentences[] = __( 'MAIL_VITALS_BREVO_API_KEY is set in wp-config.php, but Brevo is not used.', 'mail-vitals' );
		}

		if ( ( '' !== $smtp_password || '' !== $smtp['host'] ) && ! in_array( Settings::TRANSPORT_SMTP, $in_use, true ) ) {
			$sentences[] = '' === $smtp['host']
				? __( 'MAIL_VITALS_SMTP_PASSWORD is set in wp-config.php, but no SMTP host is filled in, so the SMTP server is not used.', 'mail-vitals' )
				: __( 'An SMTP server is filled in, but it is not used.', 'mail-vitals' );
		}

		if ( empty( $sentences ) ) {
			return null;
		}

		if ( '' !== $why ) {
			$sentences[] = $why;
		}

		$sentences[] = __( 'If mail was meant to go that way, it is not.', 'mail-vitals' );

		return new Finding(
			'unused_settings',
			__( 'Settings not in use', 'mail-vitals' ),
			Finding::STATUS_WARNING,
			implode( ' ', $sentences ),
			array( 'in_use' => $in_use )
		);
	}

	/**
	 * Whether a key is Brevo's SMTP key rather than its API key.
	 *
	 * @param string $key Key from wp-config.php.
	 * @return bool
	 */
	public static function is_smtp_key( $key ) {
		return 0 === strpos( (string) $key, 'xsmtpsib-' );
	}

	/**
	 * Whether a function is listed in disable_functions.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	private static function is_disabled( $name ) {
		$disabled = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );

		return in_array( strtolower( $name ), $disabled, true );
	}

	/**
	 * The program a sendmail_path runs, without its arguments.
	 *
	 * @param string $sendmail_path The setting, such as "/usr/sbin/sendmail -t -i".
	 * @return string
	 */
	public static function program_of( $sendmail_path ) {
		$sendmail_path = trim( $sendmail_path );

		if ( '' === $sendmail_path ) {
			return '';
		}

		if ( '"' === $sendmail_path[0] || "'" === $sendmail_path[0] ) {
			$end = strpos( $sendmail_path, $sendmail_path[0], 1 );

			return false === $end ? substr( $sendmail_path, 1 ) : substr( $sendmail_path, 1, $end - 1 );
		}

		$parts = preg_split( '/\s+/', $sendmail_path );

		return $parts[0];
	}

	/**
	 * Saying that mail() is not what sends.
	 *
	 * @param string[] $routes Routes in use.
	 * @return string
	 */
	private static function not_used( array $routes ) {
		return sprintf(
			/* translators: %s: name of the route, such as "SMTP". */
			__( 'Not in use: mail goes through %s.', 'mail-vitals' ),
			Alert::route_label( isset( $routes[0] ) ? $routes[0] : Settings::TRANSPORT_DEFAULT )
		);
	}

	/**
	 * The more serious of two statuses.
	 *
	 * @param string $a One status.
	 * @param string $b Another.
	 * @return string
	 */
	private static function worse( $a, $b ) {
		$rank = array(
			Finding::STATUS_GOOD     => 0,
			Finding::STATUS_UNKNOWN  => 1,
			Finding::STATUS_WARNING  => 2,
			Finding::STATUS_CRITICAL => 3,
		);

		return $rank[ $b ] > $rank[ $a ] ? $b : $a;
	}
}
