<?php
/**
 * Exposes the diagnostics to agents through the WordPress Abilities API.
 *
 * @package MailVitals
 */

namespace MailVitals;

use MailVitals\Diagnostics\DomainAuth;
use MailVitals\Diagnostics\Finding;
use MailVitals\Diagnostics\MailInterference;
use MailVitals\Diagnostics\MailSetup;
use MailVitals\Diagnostics\QueueHealth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's abilities.
 *
 * Read-only on purpose. An ability that could change how a site sends mail is
 * not something to hand to an automated caller.
 */
final class Abilities {

	/**
	 * Hook the registration into the Abilities API.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Declare every ability this plugin provides.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		wp_register_ability(
			'mail-vitals/delivery-health',
			array(
				'label'               => __( 'Mail delivery health', 'mail-vitals' ),
				'description'         => __( 'Reports whether this site\'s sending domain is set up so that the mail it sends will be accepted, and whether other code is taking part in sending it, and whether the server and the configured routes can send.', 'mail-vitals' ),
				// Core registers only "site" and "user"; mail configuration is
				// information about the site, so it belongs under "site".
				'category'            => 'site',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'fresh' => array(
							'type'        => 'boolean',
							'description' => __( 'Query DNS again instead of reusing the cached result.', 'mail-vitals' ),
							'default'     => false,
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'domain'   => array( 'type' => 'string' ),
						'status'   => array( 'type' => 'string' ),
						'findings' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( self::class, 'delivery_health' ),
				'permission_callback' => static function () {
					return current_user_can( 'view_site_health_checks' );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'readonly'     => true,
				),
			)
		);
	}

	/**
	 * Produce the delivery health report.
	 *
	 * @param array $input Validated input matching the declared schema.
	 * @return array
	 */
	public static function delivery_health( $input = array() ) {
		$fresh      = ! empty( $input['fresh'] );
		$check      = DomainAuth::for_this_site();
		$findings   = $check->run( $fresh );
		$findings[] = QueueHealth::check();
		$findings[] = MailInterference::check();
		$findings   = array_merge( $findings, MailSetup::run( $fresh ) );

		$status = Finding::STATUS_GOOD;

		foreach ( $findings as $finding ) {
			if ( Finding::STATUS_CRITICAL === $finding->status() ) {
				$status = Finding::STATUS_CRITICAL;
				break;
			}

			if ( $finding->is_problem() ) {
				$status = Finding::STATUS_WARNING;
			}
		}

		return array(
			'domain'   => $check->domain(),
			'status'   => $status,
			'findings' => array_map(
				static function ( Finding $finding ) {
					return $finding->to_array();
				},
				$findings
			),
		);
	}
}
