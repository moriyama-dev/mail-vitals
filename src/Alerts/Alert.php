<?php
/**
 * One notice about mail delivery.
 *
 * @package MailVitals
 */

namespace MailVitals\Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a notifier is asked to tell someone.
 *
 * Carries no recipient and no subject: a notice goes to a chat or another
 * service outside the site, and who was being written to is not needed to
 * know that a route is down. The error text is masked before it is shown.
 */
final class Alert {

	/**
	 * A route has started failing.
	 *
	 * @var string
	 */
	const FAILING = 'failing';

	/**
	 * A route already reported is still failing.
	 *
	 * @var string
	 */
	const STILL_FAILING = 'still_failing';

	/**
	 * A route reported as failing is delivering again.
	 *
	 * @var string
	 */
	const RECOVERED = 'recovered';

	/**
	 * Sent by hand to check that alerts arrive.
	 *
	 * @var string
	 */
	const TEST = 'test';

	/**
	 * Messages still reached their recipients over another route.
	 *
	 * @var string
	 */
	const SEVERITY_LOW = 'low';

	/**
	 * Messages are not being delivered.
	 *
	 * @var string
	 */
	const SEVERITY_HIGH = 'high';

	/**
	 * Nothing is wrong: a recovery or a test.
	 *
	 * @var string
	 */
	const SEVERITY_INFO = 'info';

	/**
	 * Notice details.
	 *
	 * @var array
	 */
	private $fields;

	/**
	 * Describe a notice.
	 *
	 * @param array $fields type, severity, transport, cause, code, count, total,
	 *                      first_seen, last_seen (Unix times), error.
	 */
	public function __construct( array $fields ) {
		$this->fields = wp_parse_args(
			$fields,
			array(
				'type'       => self::TEST,
				'severity'   => self::SEVERITY_INFO,
				'transport'  => '',
				'cause'      => '',
				'code'       => 0,
				'count'      => 0,
				'total'      => 0,
				'first_seen' => 0,
				'last_seen'  => 0,
				'error'      => '',
			)
		);
	}

	/**
	 * A notice that only proves alerts get through.
	 *
	 * @param int $now Unix time.
	 * @return Alert
	 */
	public static function test( $now ) {
		return new self(
			array(
				'type'       => self::TEST,
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
	}

	/**
	 * One of the type constants.
	 *
	 * @return string
	 */
	public function type() {
		return (string) $this->fields['type'];
	}

	/**
	 * One of the severity constants.
	 *
	 * @return string
	 */
	public function severity() {
		return (string) $this->fields['severity'];
	}

	/**
	 * Route the notice is about.
	 *
	 * @return string
	 */
	public function transport() {
		return (string) $this->fields['transport'];
	}

	/**
	 * Kind of problem.
	 *
	 * @return string
	 */
	public function cause() {
		return (string) $this->fields['cause'];
	}

	/**
	 * Most recent HTTP status or SMTP reply code.
	 *
	 * @return int
	 */
	public function code() {
		return (int) $this->fields['code'];
	}

	/**
	 * Failures since the previous notice about this problem.
	 *
	 * @return int
	 */
	public function count() {
		return (int) $this->fields['count'];
	}

	/**
	 * Failures since the problem began.
	 *
	 * @return int
	 */
	public function total() {
		return (int) $this->fields['total'];
	}

	/**
	 * When the problem began, as a Unix time.
	 *
	 * @return int
	 */
	public function first_seen() {
		return (int) $this->fields['first_seen'];
	}

	/**
	 * When the problem was last seen, as a Unix time.
	 *
	 * @return int
	 */
	public function last_seen() {
		return (int) $this->fields['last_seen'];
	}

	/**
	 * Most recent error text, masked and shortened.
	 *
	 * @return string
	 */
	public function error() {
		return Mask::error_text( (string) $this->fields['error'] );
	}

	/**
	 * One line saying what happened.
	 *
	 * @return string
	 */
	public function title() {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$route = self::route_label( $this->transport() );

		switch ( $this->type() ) {
			case self::FAILING:
				if ( Classifier::ABANDONED === $this->cause() ) {
					/* translators: %s: site name. */
					return sprintf( __( '%s: a message was given up on after every retry', 'mail-vitals' ), $site );
				}
				/* translators: 1: site name, 2: name of the mail route. */
				return sprintf( __( '%1$s: mail is failing on %2$s', 'mail-vitals' ), $site, $route );

			case self::STILL_FAILING:
				if ( Classifier::ABANDONED === $this->cause() ) {
					/* translators: %s: site name. */
					return sprintf( __( '%s: more messages were given up on after every retry', 'mail-vitals' ), $site );
				}
				/* translators: 1: site name, 2: name of the mail route. */
				return sprintf( __( '%1$s: mail is still failing on %2$s', 'mail-vitals' ), $site, $route );

			case self::RECOVERED:
				if ( Classifier::ABANDONED === $this->cause() ) {
					/* translators: %s: site name. */
					return sprintf( __( '%s: mail is being delivered again', 'mail-vitals' ), $site );
				}
				/* translators: 1: site name, 2: name of the mail route. */
				return sprintf( __( '%1$s: mail is getting through on %2$s again', 'mail-vitals' ), $site, $route );

			default:
				/* translators: %s: site name. */
				return sprintf( __( '%s: test alert from Mail Vitals', 'mail-vitals' ), $site );
		}
	}

	/**
	 * Further lines explaining the notice.
	 *
	 * @return string[]
	 */
	public function lines() {
		if ( self::TEST === $this->type() ) {
			return array( __( 'Alerts from this site reach this channel. Nothing needs to be done.', 'mail-vitals' ) );
		}

		if ( self::RECOVERED === $this->type() ) {
			return array(
				sprintf(
					/* translators: 1: how long the problem lasted, 2: number of failures. */
					_n( 'The problem lasted %1$s and %2$d send failed.', 'The problem lasted %1$s and %2$d sends failed.', $this->total(), 'mail-vitals' ),
					human_time_diff( $this->first_seen(), $this->last_seen() ),
					$this->total()
				),
			);
		}

		$lines = array();

		if ( Classifier::ABANDONED === $this->cause() ) {
			$lines[] = __( 'These messages were not delivered and will not be retried again.', 'mail-vitals' );
		} else {
			$lines[] = self::cause_label( $this->cause() );
			$lines[] = self::SEVERITY_LOW === $this->severity()
				? __( 'Messages are still reaching their recipients over the other route, but the site has lost its backup.', 'mail-vitals' )
				: __( 'Messages are not being delivered. Failed messages are kept and retried where a route is configured.', 'mail-vitals' );
		}

		$lines[] = self::STILL_FAILING === $this->type()
			? sprintf(
				/* translators: 1: failures since the last notice, 2: failures in total, 3: date and time the problem began. */
				__( '%1$d more since the last notice, %2$d in total since %3$s.', 'mail-vitals' ),
				$this->count(),
				$this->total(),
				wp_date( 'Y-m-d H:i T', $this->first_seen() )
			)
			: sprintf(
				/* translators: %s: date and time the problem began. */
				__( 'First seen %s.', 'mail-vitals' ),
				wp_date( 'Y-m-d H:i T', $this->first_seen() )
			);

		if ( '' !== $this->error() ) {
			/* translators: %s: error text, with email addresses masked. */
			$lines[] = sprintf( __( 'Latest error: %s', 'mail-vitals' ), $this->error() );
		}

		$lines[] = __( 'The full error is in the send log under Settings → Mail Vitals.', 'mail-vitals' );

		return $lines;
	}

	/**
	 * Human name of a route.
	 *
	 * @param string $transport Route identifier.
	 * @return string
	 */
	public static function route_label( $transport ) {
		switch ( $transport ) {
			case 'smtp':
				return __( 'SMTP', 'mail-vitals' );
			case 'brevo':
				return __( 'the Brevo API', 'mail-vitals' );
			case 'queue':
				return __( 'the retry queue', 'mail-vitals' );
			default:
				return __( 'WordPress\'s own mail function', 'mail-vitals' );
		}
	}

	/**
	 * Plain explanation of a kind of problem.
	 *
	 * @param string $cause One of the Classifier cause constants.
	 * @return string
	 */
	public static function cause_label( $cause ) {
		switch ( $cause ) {
			case Classifier::CONNECTION:
				return __( 'The mail server could not be reached.', 'mail-vitals' );
			case Classifier::AUTH:
				return __( 'The mail server refused the login or API key.', 'mail-vitals' );
			case Classifier::SENDER:
				return __( 'The From address was refused.', 'mail-vitals' );
			case Classifier::RECIPIENT:
				return __( 'A recipient was refused.', 'mail-vitals' );
			case Classifier::RATE_LIMIT:
				return __( 'The mail provider is limiting how much the site may send.', 'mail-vitals' );
			case Classifier::PROVIDER:
				return __( 'The mail provider reported a problem on its side.', 'mail-vitals' );
			case Classifier::ABANDONED:
				return __( 'A message was given up on after every retry.', 'mail-vitals' );
			default:
				return __( 'The send failed for a reason Mail Vitals does not recognise.', 'mail-vitals' );
		}
	}
}
