<?php
/**
 * Surfaces the diagnostics inside WordPress's own Site Health screen.
 *
 * @package MailVitals
 */

namespace MailVitals\Diagnostics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Site Health tests covering mail authentication and other code that
 * takes part in sending.
 *
 * Site Health is where a site owner already looks when something is wrong, so
 * the findings are reported there rather than behind a menu item of our own.
 * The two are separate tests because they answer separate questions: whether
 * receiving servers will trust the mail, and who on this site is sending it.
 */
final class SiteHealth {

	/**
	 * Hook the test into Site Health.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
	}

	/**
	 * Declare the test.
	 *
	 * Registered as a direct test, which is safe because the underlying check
	 * caches its DNS lookups for an hour; only the first view of the screen
	 * after a change pays for the queries.
	 *
	 * @param array $tests Tests collected so far.
	 * @return array
	 */
	public static function add_test( $tests ) {
		$tests['direct']['mail_vitals_domain_auth'] = array(
			'label' => __( 'Mail authentication', 'mail-vitals' ),
			'test'  => array( self::class, 'run_test' ),
		);

		// Direct: connecting to a route is kept for ten minutes by Probe.
		$tests['direct']['mail_vitals_mail_setup'] = array(
			'label' => __( 'Mail setup', 'mail-vitals' ),
			'test'  => array( self::class, 'run_setup_test' ),
		);

		// Direct as well: it only reads the hooks already in memory.
		$tests['direct']['mail_vitals_mail_interference'] = array(
			'label' => __( 'Other code handling mail', 'mail-vitals' ),
			'test'  => array( self::class, 'run_interference_test' ),
		);

		return $tests;
	}

	/**
	 * Run the check and shape it the way Site Health expects.
	 *
	 * @return array
	 */
	public static function run_test() {
		$check      = DomainAuth::for_this_site();
		$findings   = $check->run();
		$findings[] = QueueHealth::check();
		$problems   = array_filter(
			$findings,
			static function ( Finding $finding ) {
				return $finding->is_problem();
			}
		);

		$result = array(
			'label'       => __( 'Mail from this site is properly authenticated', 'mail-vitals' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Email', 'mail-vitals' ),
				'color' => 'blue',
			),
			'description' => self::describe( $findings ),
			'actions'     => '',
			'test'        => 'mail_vitals_domain_auth',
		);

		if ( empty( $problems ) ) {
			return $result;
		}

		$critical = array_filter(
			$problems,
			static function ( Finding $finding ) {
				return Finding::STATUS_CRITICAL === $finding->status();
			}
		);

		$result['status'] = empty( $critical ) ? 'recommended' : 'critical';
		$result['label']  = empty( $critical )
			? __( 'Mail authentication for this site could be stronger', 'mail-vitals' )
			: __( 'Mail from this site is likely to be rejected', 'mail-vitals' );

		return $result;
	}

	/**
	 * Report whether the server and the configured routes can send.
	 *
	 * @return array
	 */
	public static function run_setup_test() {
		$findings = MailSetup::run();
		$statuses = array_map(
			static function ( Finding $finding ) {
				return $finding->status();
			},
			$findings
		);

		if ( in_array( Finding::STATUS_CRITICAL, $statuses, true ) ) {
			$status = 'critical';
			$label  = __( 'Mail cannot leave this site as it is set up', 'mail-vitals' );
		} elseif ( in_array( Finding::STATUS_WARNING, $statuses, true ) ) {
			$status = 'recommended';
			$label  = __( 'The mail setup has something worth fixing', 'mail-vitals' );
		} else {
			$status = 'good';
			$label  = __( 'The mail setup can send', 'mail-vitals' );
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Email', 'mail-vitals' ),
				'color' => 'blue',
			),
			'description' => self::describe( $findings ),
			'actions'     => '',
			'test'        => 'mail_vitals_mail_setup',
		);
	}

	/**
	 * Report other code that takes part in sending.
	 *
	 * @return array
	 */
	public static function run_interference_test() {
		$finding  = MailInterference::check();
		$statuses = array(
			Finding::STATUS_GOOD     => 'good',
			Finding::STATUS_WARNING  => 'recommended',
			Finding::STATUS_CRITICAL => 'critical',
		);
		$labels   = array(
			Finding::STATUS_GOOD     => __( 'No other code is getting in the way of sending mail', 'mail-vitals' ),
			Finding::STATUS_WARNING  => __( 'Other code is taking part in sending mail', 'mail-vitals' ),
			Finding::STATUS_CRITICAL => __( 'wp_mail() is replaced, so the route set in Mail Vitals may never be used', 'mail-vitals' ),
		);
		$status   = isset( $statuses[ $finding->status() ] ) ? $finding->status() : Finding::STATUS_GOOD;
		$detail   = $finding->detail();

		return array(
			'label'       => $labels[ $status ],
			'status'      => $statuses[ $status ],
			'badge'       => array(
				'label' => __( 'Email', 'mail-vitals' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $finding->summary() ) . '</p>' . self::describe_callbacks( $detail['callbacks'] ),
			'actions'     => '',
			'test'        => 'mail_vitals_mail_interference',
		);
	}

	/**
	 * Render the callbacks from other code as a list.
	 *
	 * @param array $callbacks Callbacks as reported by MailInterference.
	 * @return string
	 */
	private static function describe_callbacks( array $callbacks ) {
		if ( empty( $callbacks ) ) {
			return '';
		}

		$rows = '';

		foreach ( $callbacks as $callback ) {
			$rows .= sprintf(
				'<li><code>%1$s</code> %2$s — %3$s (<code>%4$s</code>)</li>',
				esc_html( $callback['hook'] ),
				esc_html(
					sprintf(
						/* translators: %d: hook priority. */
						__( 'at priority %d', 'mail-vitals' ),
						$callback['priority']
					)
				),
				esc_html( $callback['owner'] ),
				esc_html( '' !== $callback['file'] ? $callback['file'] : $callback['callback'] )
			);
		}

		return '<ul>' . $rows . '</ul>';
	}

	/**
	 * Render the findings as the body of the Site Health panel.
	 *
	 * @param Finding[] $findings Findings to render.
	 * @return string
	 */
	private static function describe( array $findings ) {
		$rows = '';

		foreach ( $findings as $finding ) {
			$rows .= sprintf(
				'<li><strong>%1$s</strong> — %2$s</li>',
				esc_html( $finding->label() ),
				esc_html( $finding->summary() )
			);
		}

		return '<ul>' . $rows . '</ul>';
	}
}
