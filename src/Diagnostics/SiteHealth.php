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
 * Adds one Site Health test covering mail authentication.
 *
 * Site Health is where a site owner already looks when something is wrong, so
 * the findings are reported there rather than behind a menu item of our own.
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
