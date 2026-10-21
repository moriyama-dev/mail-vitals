<?php
/**
 * Where the plugin's configuration comes from.
 *
 * @package MailVitals
 */

namespace MailVitals\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the plugin's configuration.
 *
 * Ordinary settings live in an option. Secrets do not: passwords and API keys
 * are read from constants in wp-config.php and from nowhere else. There is no
 * code path that writes them to the database, which is a stronger guarantee
 * than remembering not to.
 */
final class Settings {

	/**
	 * Option holding the settings that are not secret.
	 *
	 * @var string
	 */
	const OPTION = 'mail_vitals_settings';

	/**
	 * Leave sending to WordPress.
	 *
	 * @var string
	 */
	const TRANSPORT_DEFAULT = 'default';

	/**
	 * Send over SMTP by configuring the mail library.
	 *
	 * @var string
	 */
	const TRANSPORT_SMTP = 'smtp';

	/**
	 * Send over the Brevo HTTP API, bypassing the mail library.
	 *
	 * @var string
	 */
	const TRANSPORT_BREVO = 'brevo';

	/**
	 * Settings as stored, with defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'transport'          => self::TRANSPORT_DEFAULT,
				'from_address'       => '',
				'from_name'          => '',
				'force_from'         => false,
				'smtp_host'          => '',
				'smtp_port'          => 587,
				'smtp_encryption'    => 'tls',
				'smtp_username'      => '',
				'log_enabled'        => true,
				'log_retention_days' => 30,
				'log_max_rows'       => 5000,
			)
		);
	}

	/**
	 * Whether sends should be written to the log at all.
	 *
	 * @return bool
	 */
	public static function log_enabled() {
		$settings = self::all();

		return ! empty( $settings['log_enabled'] );
	}

	/**
	 * How many days of log to keep. Zero means no age limit.
	 *
	 * @return int
	 */
	public static function log_retention_days() {
		$settings = self::all();

		return max( 0, (int) $settings['log_retention_days'] );
	}

	/**
	 * How many log entries to keep. Zero means no size limit.
	 *
	 * @return int
	 */
	public static function log_max_rows() {
		$settings = self::all();

		return max( 0, (int) $settings['log_max_rows'] );
	}

	/**
	 * Which transport should carry the site's mail.
	 *
	 * @return string One of the TRANSPORT_* constants.
	 */
	public static function transport() {
		$settings  = self::all();
		$transport = (string) $settings['transport'];

		$known = array( self::TRANSPORT_DEFAULT, self::TRANSPORT_SMTP, self::TRANSPORT_BREVO );

		return in_array( $transport, $known, true ) ? $transport : self::TRANSPORT_DEFAULT;
	}

	/**
	 * Read a secret from wp-config.php.
	 *
	 * @param string $name Constant name, without the plugin prefix.
	 * @return string Empty string when the constant is not defined.
	 */
	public static function secret( $name ) {
		$constant = 'MAIL_VITALS_' . strtoupper( $name );

		return defined( $constant ) ? (string) constant( $constant ) : '';
	}

	/**
	 * Everything the SMTP transport needs to connect.
	 *
	 * @return array
	 */
	public static function smtp() {
		$settings = self::all();

		return array(
			'host'       => (string) $settings['smtp_host'],
			'port'       => (int) $settings['smtp_port'],
			'encryption' => (string) $settings['smtp_encryption'],
			'username'   => (string) $settings['smtp_username'],
			'password'   => self::secret( 'smtp_password' ),
		);
	}

	/**
	 * The address the site should send from, when one is configured.
	 *
	 * @return string
	 */
	public static function from_address() {
		$settings = self::all();

		return is_email( $settings['from_address'] ) ? (string) $settings['from_address'] : '';
	}

	/**
	 * The display name the site should send under, when one is configured.
	 *
	 * @return string
	 */
	public static function from_name() {
		$settings = self::all();

		return (string) $settings['from_name'];
	}

	/**
	 * Whether the configured From details should override what callers ask for.
	 *
	 * @return bool
	 */
	public static function force_from() {
		$settings = self::all();

		return ! empty( $settings['force_from'] );
	}
}
