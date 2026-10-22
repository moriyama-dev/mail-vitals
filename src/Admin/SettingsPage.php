<?php
/**
 * The plugin's screen under Settings.
 *
 * @package MailVitals
 */

namespace MailVitals\Admin;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\Channels;
use MailVitals\Alerts\Monitor;
use MailVitals\Diagnostics\DomainAuth;
use MailVitals\Diagnostics\Finding;
use MailVitals\Mailer\Dispatcher;
use MailVitals\Log\Pruner;
use MailVitals\Log\Store;
use MailVitals\Mailer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets an administrator configure and inspect mail delivery.
 *
 * Passwords and API keys have no field here on purpose. They are read from
 * wp-config.php constants, and giving them an input would mean writing code
 * that stores them in the database, which is the thing being avoided. The
 * screen reports whether each constant is set instead.
 */
final class SettingsPage {

	/**
	 * Slug of the screen.
	 *
	 * @var string
	 */
	const SLUG = 'mail-vitals';

	/**
	 * Settings group the option belongs to.
	 *
	 * @var string
	 */
	const GROUP = 'mail_vitals';

	/**
	 * Capability required to see or change anything here.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Add the screen and register the option.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
	}

	/**
	 * Put the screen under Settings, where site-wide options live.
	 *
	 * @return void
	 */
	public static function add_page() {
		add_options_page(
			__( 'Mail Vitals', 'mail-vitals' ),
			__( 'Mail Vitals', 'mail-vitals' ),
			self::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Declare the option so the Settings API handles saving and nonces.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Clean every submitted value before it is stored.
	 *
	 * Anything not recognised is dropped rather than corrected, so a malformed
	 * submission cannot leave the plugin in a state the rest of the code does
	 * not expect.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$current = Settings::all();

		$transports = array( Settings::TRANSPORT_DEFAULT, Settings::TRANSPORT_SMTP, Settings::TRANSPORT_BREVO );
		$transport  = isset( $input['transport'] ) ? sanitize_key( $input['transport'] ) : '';

		$encryptions = array( 'none', 'tls', 'ssl' );
		$encryption  = isset( $input['smtp_encryption'] ) ? sanitize_key( $input['smtp_encryption'] ) : '';

		$from = isset( $input['from_address'] ) ? sanitize_email( $input['from_address'] ) : '';
		$port = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : 0;

		return array(
			'transport'          => in_array( $transport, $transports, true ) ? $transport : Settings::TRANSPORT_DEFAULT,
			'from_address'       => is_email( $from ) ? $from : '',
			'from_name'          => isset( $input['from_name'] ) ? sanitize_text_field( $input['from_name'] ) : '',
			'force_from'         => ! empty( $input['force_from'] ),
			'smtp_host'          => isset( $input['smtp_host'] ) ? sanitize_text_field( $input['smtp_host'] ) : '',
			'smtp_port'          => ( $port > 0 && $port <= 65535 ) ? $port : (int) $current['smtp_port'],
			'smtp_encryption'    => in_array( $encryption, $encryptions, true ) ? $encryption : 'tls',
			'smtp_username'      => isset( $input['smtp_username'] ) ? sanitize_text_field( $input['smtp_username'] ) : '',
			'log_enabled'        => ! empty( $input['log_enabled'] ),
			'log_retention_days' => isset( $input['log_retention_days'] ) ? min( 3650, absint( $input['log_retention_days'] ) ) : 30,
			'log_max_rows'       => isset( $input['log_max_rows'] ) ? min( 1000000, absint( $input['log_max_rows'] ) ) : 5000,
		);
	}

	/**
	 * Draw the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = Settings::all();
		$active   = Dispatcher::active();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Mail Vitals', 'mail-vitals' ); ?></h1>

			<?php self::render_notices(); ?>
			<?php self::render_status( $active ); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php echo esc_html__( 'How mail is sent', 'mail-vitals' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Transport', 'mail-vitals' ); ?></th>
						<td>
							<?php self::render_transport_choices( $settings['transport'] ); ?>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Who mail comes from', 'mail-vitals' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mail-vitals-from-address"><?php echo esc_html__( 'From address', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="email" class="regular-text" id="mail-vitals-from-address"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[from_address]"
								value="<?php echo esc_attr( $settings['from_address'] ); ?>">
							<p class="description">
								<?php echo esc_html__( 'Leave empty to let WordPress derive one from the site address. A derived address on a host name without a dot, such as "localhost", is rejected before anything is sent.', 'mail-vitals' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-from-name"><?php echo esc_html__( 'From name', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="mail-vitals-from-name"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[from_name]"
								value="<?php echo esc_attr( $settings['from_name'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Override', 'mail-vitals' ); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[force_from]"
									<?php checked( ! empty( $settings['force_from'] ) ); ?>>
								<?php echo esc_html__( 'Use these details even when a plugin asks to send as something else', 'mail-vitals' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'SMTP server', 'mail-vitals' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mail-vitals-smtp-host"><?php echo esc_html__( 'Host', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="mail-vitals-smtp-host"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[smtp_host]"
								value="<?php echo esc_attr( $settings['smtp_host'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-smtp-port"><?php echo esc_html__( 'Port', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" max="65535" class="small-text" id="mail-vitals-smtp-port"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[smtp_port]"
								value="<?php echo esc_attr( (string) $settings['smtp_port'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-smtp-encryption"><?php echo esc_html__( 'Encryption', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<select id="mail-vitals-smtp-encryption" name="<?php echo esc_attr( Settings::OPTION ); ?>[smtp_encryption]">
								<?php foreach ( self::encryption_choices() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['smtp_encryption'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-smtp-username"><?php echo esc_html__( 'Username', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="mail-vitals-smtp-username"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[smtp_username]"
								value="<?php echo esc_attr( $settings['smtp_username'] ); ?>">
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Log', 'mail-vitals' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Record sends', 'mail-vitals' ); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[log_enabled]"
									<?php checked( ! empty( $settings['log_enabled'] ) ); ?>>
								<?php echo esc_html__( 'Keep a record of every message this site sends', 'mail-vitals' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-retention"><?php echo esc_html__( 'Keep for', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="3650" class="small-text" id="mail-vitals-retention"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[log_retention_days]"
								value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>">
							<?php echo esc_html__( 'days', 'mail-vitals' ); ?>
							<p class="description"><?php echo esc_html__( 'Zero keeps entries regardless of age.', 'mail-vitals' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mail-vitals-max-rows"><?php echo esc_html__( 'At most', 'mail-vitals' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="1000000" class="small-text" id="mail-vitals-max-rows"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[log_max_rows]"
								value="<?php echo esc_attr( (string) $settings['log_max_rows'] ); ?>">
							<?php echo esc_html__( 'entries', 'mail-vitals' ); ?>
							<p class="description"><?php echo esc_html__( 'Both limits apply. Age alone would let a busy site store millions of entries; size alone would let a quiet one keep entries from years ago.', 'mail-vitals' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_secrets(); ?>
			<?php self::render_test_form(); ?>
			<?php self::render_alerts(); ?>
			<?php self::render_diagnostics(); ?>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	/**
	 * The transports an administrator can choose between.
	 *
	 * @param string $current Currently stored transport.
	 * @return void
	 */
	private static function render_transport_choices( $current ) {
		$choices = array(
			Settings::TRANSPORT_DEFAULT => __( 'Leave it to WordPress', 'mail-vitals' ),
			Settings::TRANSPORT_SMTP    => __( 'SMTP server', 'mail-vitals' ),
			Settings::TRANSPORT_BREVO   => __( 'Brevo API', 'mail-vitals' ),
		);

		foreach ( $choices as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="radio" name="%1$s[transport]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( Settings::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed attribute string.
				esc_html( $label )
			);
		}
	}

	/**
	 * Encryption options for the SMTP connection.
	 *
	 * @return array
	 */
	private static function encryption_choices() {
		return array(
			'tls'  => __( 'TLS (usually port 587)', 'mail-vitals' ),
			'ssl'  => __( 'SSL (usually port 465)', 'mail-vitals' ),
			'none' => __( 'None', 'mail-vitals' ),
		);
	}

	/**
	 * Say which transport is actually in charge right now.
	 *
	 * A transport can be selected and still not be used, because it is not
	 * configured. Saying so plainly saves a long hunt for why nothing changed.
	 *
	 * @param \MailVitals\Mailer\Transport|null $active The transport in use.
	 * @return void
	 */
	private static function render_status( $active ) {
		$chosen = Settings::transport();

		if ( Settings::TRANSPORT_DEFAULT === $chosen ) {
			return;
		}

		if ( null !== $active ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: name of the transport in use. */
						__( 'Mail is being sent through: %s', 'mail-vitals' ),
						$active->id()
					)
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'The selected transport is not fully configured, so WordPress is still sending mail its own way. Nothing here is taking effect yet.', 'mail-vitals' )
		);
	}

	/**
	 * Report whether the secrets are in place, without ever showing them.
	 *
	 * @return void
	 */
	private static function render_secrets() {
		$secrets = array(
			'MAIL_VITALS_SMTP_PASSWORD'  => Settings::secret( 'smtp_password' ),
			'MAIL_VITALS_BREVO_API_KEY'  => Settings::secret( 'brevo_api_key' ),
			'MAIL_VITALS_WEBHOOK_SECRET' => Settings::secret( 'webhook_secret' ),
		);
		?>
		<h2><?php echo esc_html__( 'Credentials', 'mail-vitals' ); ?></h2>
		<p>
			<?php echo esc_html__( 'Passwords and API keys are read from wp-config.php and are never written to the database. There is no field for them here because there is no code to store one.', 'mail-vitals' ); ?>
		</p>
		<table class="widefat striped" style="max-width:40em">
			<tbody>
			<?php foreach ( $secrets as $constant => $value ) : ?>
				<tr>
					<td><code><?php echo esc_html( $constant ); ?></code></td>
					<td>
						<?php
						echo '' !== $value
							? esc_html__( 'Set', 'mail-vitals' )
							: esc_html__( 'Not set', 'mail-vitals' );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php echo esc_html__( 'To set one, add a line such as the following to wp-config.php:', 'mail-vitals' ); ?>
			<br>
			<code>define( 'MAIL_VITALS_SMTP_PASSWORD', 'your password' );</code>
		</p>
		<?php
	}

	/**
	 * Offer a test send.
	 *
	 * @return void
	 */
	private static function render_test_form() {
		$user = wp_get_current_user();
		?>
		<h2><?php echo esc_html__( 'Send a test message', 'mail-vitals' ); ?></h2>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( TestSend::ACTION ); ?>">
			<?php wp_nonce_field( TestSend::ACTION ); ?>
			<p>
				<label for="mail-vitals-test-to"><?php echo esc_html__( 'Send to', 'mail-vitals' ); ?></label><br>
				<input type="email" class="regular-text" id="mail-vitals-test-to" name="to"
					value="<?php echo esc_attr( $user->user_email ); ?>" required>
			</p>
			<?php submit_button( __( 'Send test message', 'mail-vitals' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Show the alert channels, the problems they are reporting, and a test.
	 *
	 * Like the other secrets, the channel URLs are only reported as set or not.
	 * A webhook URL lets anyone who has it post to the channel.
	 *
	 * @return void
	 */
	private static function render_alerts() {
		$channels   = Channels::all();
		$errors     = Monitor::channel_errors();
		$incidents  = Monitor::incidents();
		$configured = Channels::configured();
		?>
		<h2><?php echo esc_html__( 'Alerts', 'mail-vitals' ); ?></h2>
		<p>
			<?php echo esc_html__( 'When mail starts failing, a notice goes to each channel below: at once, then after 15 minutes, 1 hour and 6 hours, then once a day while the problem lasts, and once more when it is over. Failures for the same reason on the same route count as one problem. Notices carry no recipient or subject, and email addresses in error text are masked.', 'mail-vitals' ); ?>
		</p>
		<table class="widefat striped" style="max-width:60em">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Channel', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Constant in wp-config.php', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mail-vitals' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $channels as $channel ) : ?>
				<tr>
					<td><?php echo esc_html( $channel->label() ); ?></td>
					<td><code><?php echo esc_html( $channel->constant() ); ?></code></td>
					<td>
						<?php
						echo $channel->is_configured()
							? esc_html__( 'Set', 'mail-vitals' )
							: esc_html__( 'Not set', 'mail-vitals' );

						if ( isset( $errors[ $channel->id() ] ) ) {
							printf(
								'<br><span style="color:#b32d2e">%s</span>',
								esc_html(
									sprintf(
										/* translators: 1: date and time, 2: error message. */
										__( 'Last attempt failed (%1$s): %2$s', 'mail-vitals' ),
										wp_date( 'Y-m-d H:i', (int) $errors[ $channel->id() ]['at'] ),
										$errors[ $channel->id() ]['error']
									)
								)
							);
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php echo esc_html__( 'A webhook can be signed: set MAIL_VITALS_WEBHOOK_SECRET and the body is sent with an HMAC-SHA256 signature in the X-Mail-Vitals-Signature header.', 'mail-vitals' ); ?>
			<br>
			<?php echo esc_html__( 'Email alerts are only sent over a route other than the one that is failing, so they need both an SMTP server and Brevo set up. With a single route, use Slack or a webhook to hear about its failures. Several addresses can be listed, separated by commas.', 'mail-vitals' ); ?>
			<br>
			<code>define( 'MAIL_VITALS_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/…' );</code>
			<br>
			<code>define( 'MAIL_VITALS_ALERT_EMAIL', 'ops@example.com' );</code>
		</p>

		<h3><?php echo esc_html__( 'Problems being reported', 'mail-vitals' ); ?></h3>
		<?php if ( empty( $incidents ) ) : ?>
			<p><?php echo esc_html__( 'None.', 'mail-vitals' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Route', 'mail-vitals' ); ?></th>
						<th><?php echo esc_html__( 'Problem', 'mail-vitals' ); ?></th>
						<th><?php echo esc_html__( 'Since', 'mail-vitals' ); ?></th>
						<th><?php echo esc_html__( 'Failures', 'mail-vitals' ); ?></th>
						<th><?php echo esc_html__( 'Notices sent', 'mail-vitals' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $incidents as $incident ) : ?>
					<tr>
						<td><?php echo esc_html( Alert::route_label( $incident['transport'] ) ); ?></td>
						<td>
							<?php echo esc_html( Alert::cause_label( $incident['cause'] ) ); ?>
							<?php if ( Alert::SEVERITY_LOW === $incident['severity'] ) : ?>
								<br><?php echo esc_html__( 'Mail is still getting through over the other route.', 'mail-vitals' ); ?>
							<?php endif; ?>
							<?php if ( '' !== $incident['notice_error'] ) : ?>
								<br><span style="color:#b32d2e"><?php echo esc_html( $incident['notice_error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $incident['first_seen'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $incident['total'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $incident['notices'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( TestAlert::ACTION ); ?>">
			<?php wp_nonce_field( TestAlert::ACTION ); ?>
			<?php submit_button( __( 'Send a test alert', 'mail-vitals' ), 'secondary', 'submit', true, empty( $configured ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
	}

	/**
	 * Show the domain authentication findings.
	 *
	 * @return void
	 */
	private static function render_diagnostics() {
		$findings = DomainAuth::for_this_site()->run();
		?>
		<h2><?php echo esc_html__( 'Domain authentication', 'mail-vitals' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Check', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Result', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'What it means', 'mail-vitals' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $findings as $finding ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $finding->label() ); ?></strong></td>
					<td><?php echo esc_html( self::status_label( $finding->status() ) ); ?></td>
					<td><?php echo esc_html( $finding->summary() ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show the most recent entries in the send log.
	 *
	 * @return void
	 */
	private static function render_log() {
		$entries = Store::page( array( 'limit' => 25 ) );
		$total   = Store::count();
		$failed  = Store::count( Store::STATUS_FAILED );
		?>
		<h2><?php echo esc_html__( 'Send log', 'mail-vitals' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: 1: total number of logged messages, 2: number that failed. */
				esc_html__( '%1$d messages logged, %2$d of them refused. Message bodies are not kept: what is recorded is the envelope and the reason a message was refused, verbatim.', 'mail-vitals' ),
				(int) $total,
				(int) $failed
			);
			?>
		</p>
		<?php if ( empty( $entries ) ) : ?>
			<p><?php echo esc_html__( 'Nothing has been sent since the log was started.', 'mail-vitals' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'When', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Result', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Via', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'To', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Subject', 'mail-vitals' ); ?></th>
					<th><?php echo esc_html__( 'Reason', 'mail-vitals' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( get_date_from_gmt( $entry['sent_at'], 'Y-m-d H:i' ) ); ?></td>
					<td>
						<?php
						echo Store::STATUS_SENT === $entry['status']
							? esc_html__( 'Sent', 'mail-vitals' )
							: '<strong>' . esc_html__( 'Refused', 'mail-vitals' ) . '</strong>';
						?>
					</td>
					<td><?php echo esc_html( $entry['transport'] ); ?></td>
					<td><?php echo esc_html( $entry['recipients'] ); ?></td>
					<td><?php echo esc_html( $entry['subject'] ); ?></td>
					<td>
						<?php if ( '' !== $entry['error'] ) : ?>
							<code><?php echo esc_html( $entry['error'] ); ?></code>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Show the outcome of a test send, when one just happened.
	 *
	 * @return void
	 */
	private static function render_notices() {
		$result = isset( $_GET['mail-vitals-test'] ) ? sanitize_key( wp_unslash( $_GET['mail-vitals-test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a result flag, not acting on it.

		if ( 'sent' === $result ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'The test message was accepted for delivery.', 'mail-vitals' )
			);
		}

		if ( 'failed' === $result ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'The test message was refused. The reason is in the send log, below.', 'mail-vitals' )
			);
		}

		$alert = isset( $_GET['mail-vitals-alert'] ) ? sanitize_key( wp_unslash( $_GET['mail-vitals-alert'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a result flag, not acting on it.

		$notices = array(
			'sent'   => array( 'success', __( 'The test alert was delivered.', 'mail-vitals' ) ),
			'failed' => array( 'error', __( 'The test alert could not be delivered. The reason is shown next to each channel under Alerts.', 'mail-vitals' ) ),
			'none'   => array( 'warning', __( 'No alert channel is set up yet.', 'mail-vitals' ) ),
		);

		if ( isset( $notices[ $alert ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notices[ $alert ][0] ),
				esc_html( $notices[ $alert ][1] )
			);
		}
	}

	/**
	 * Human-readable name for a finding status.
	 *
	 * @param string $status One of the Finding STATUS_* constants.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case Finding::STATUS_GOOD:
				return __( 'Good', 'mail-vitals' );
			case Finding::STATUS_WARNING:
				return __( 'Worth fixing', 'mail-vitals' );
			case Finding::STATUS_CRITICAL:
				return __( 'Blocking delivery', 'mail-vitals' );
			default:
				return __( 'Could not check', 'mail-vitals' );
		}
	}
}
