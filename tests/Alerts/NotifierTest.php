<?php
/**
 * What each channel sends.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Alerts;

use MailVitals\Alerts\Alert;
use MailVitals\Alerts\Channels;
use MailVitals\Alerts\Http;
use MailVitals\Alerts\SlackNotifier;
use MailVitals\Alerts\WebhookNotifier;

/**
 * Requests are intercepted at pre_http_request; nothing leaves the machine.
 */
final class NotifierTest extends \WP_UnitTestCase {

	/**
	 * Requests captured, each with url and args.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Status code the fake endpoint answers with.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * Intercept outgoing requests.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->requests = array();
		$this->status   = 200;

		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	/**
	 * Record a request and answer it.
	 *
	 * @param false|array $response Response so far.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Destination.
	 * @return array
	 */
	public function capture( $response, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => 200 === $this->status ? 'ok' : 'invalid_token',
			'response' => array(
				'code'    => $this->status,
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * A failure alert with an address in its error.
	 *
	 * @return Alert
	 */
	private function alert() {
		return new Alert(
			array(
				'type'       => Alert::FAILING,
				'severity'   => Alert::SEVERITY_HIGH,
				'transport'  => 'smtp',
				'cause'      => 'auth',
				'code'       => 535,
				'count'      => 1,
				'total'      => 3,
				'first_seen' => 1800000000,
				'last_seen'  => 1800000060,
				'error'      => '535 5.7.8 <jane@example.com> credentials rejected',
			)
		);
	}

	/**
	 * Both channels are configured from wp-config.php constants.
	 *
	 * @return void
	 */
	public function test_channels_are_configured_from_constants() {
		$ids = array_map(
			static function ( $channel ) {
				return $channel->id();
			},
			Channels::configured()
		);

		$this->assertSame( array( 'slack', 'webhook' ), $ids, 'Email is off until an address is set.' );
	}

	/**
	 * Slack gets plain text with its markup characters escaped.
	 *
	 * @return void
	 */
	public function test_slack_posts_escaped_text() {
		$this->assertTrue( ( new SlackNotifier() )->send( $this->alert() ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( MAIL_VITALS_SLACK_WEBHOOK_URL, $this->requests[0]['url'] );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );

		$body = json_decode( $this->requests[0]['args']['body'], true );

		$this->assertStringContainsString( '&lt;j***@example.com&gt;', $body['text'] );
		$this->assertStringNotContainsString( 'jane@', $body['text'] );
		$this->assertFalse( $body['unfurl_links'] );
	}

	/**
	 * Slack only accepts an https URL.
	 *
	 * @return void
	 */
	public function test_urls_are_checked() {
		$this->assertTrue( Http::valid_url( 'https://hooks.slack.com/services/x', true ) );
		$this->assertFalse( Http::valid_url( 'http://hooks.slack.com/services/x', true ) );
		$this->assertTrue( Http::valid_url( 'http://localhost:8080/hook' ) );
		$this->assertFalse( Http::valid_url( 'ftp://example.com/' ) );
		$this->assertFalse( Http::valid_url( 'not a url' ) );
		$this->assertFalse( Http::valid_url( '' ) );
	}

	/**
	 * The webhook body has a stable shape and is signed.
	 *
	 * @return void
	 */
	public function test_webhook_posts_a_signed_body() {
		$this->assertTrue( ( new WebhookNotifier() )->send( $this->alert() ) );

		$request = $this->requests[0];
		$body    = $request['args']['body'];
		$data    = json_decode( $body, true );

		$this->assertSame( MAIL_VITALS_WEBHOOK_URL, $request['url'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $body, MAIL_VITALS_WEBHOOK_SECRET ), $request['args']['headers'][ WebhookNotifier::SIGNATURE_HEADER ] );
		$this->assertSame( Alert::FAILING, $request['args']['headers']['X-Mail-Vitals-Event'] );

		$this->assertSame( WebhookNotifier::SCHEMA, $data['schema'] );
		$this->assertSame( 'failing', $data['event'] );
		$this->assertSame( 'high', $data['severity'] );
		$this->assertSame( 'smtp', $data['route'] );
		$this->assertSame( 'auth', $data['cause'] );
		$this->assertSame( 535, $data['code'] );
		$this->assertSame( 3, $data['failures_total'] );
		$this->assertSame( '2027-01-15T08:00:00+00:00', $data['first_seen'] );
		$this->assertStringNotContainsString( 'jane@', $body );
	}

	/**
	 * A refusal from the channel is an error, with its status in it.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_an_error() {
		$this->status = 403;

		$result = ( new SlackNotifier() )->send( $this->alert() );

		$this->assertWPError( $result );
		$this->assertSame( 'mail_vitals_alert_refused', $result->get_error_code() );
		$this->assertStringContainsString( '403', $result->get_error_message() );
	}

	/**
	 * A test alert names itself as one.
	 *
	 * @return void
	 */
	public function test_the_test_alert() {
		$alert = Alert::test( 1800000000 );

		$this->assertSame( Alert::TEST, $alert->type() );
		$this->assertTrue( ( new WebhookNotifier() )->send( $alert ) );
		$this->assertSame( 'test', json_decode( $this->requests[0]['args']['body'], true )['event'] );
	}
}
