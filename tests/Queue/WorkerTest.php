<?php
/**
 * What the retry worker does with messages it cannot send.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Queue;

use MailVitals\Alerts\Classifier;
use MailVitals\Alerts\Monitor;
use MailVitals\Log\Schema;
use MailVitals\Log\Store;
use MailVitals\Queue\Queue;
use MailVitals\Queue\Worker;
use MailVitals\Tests\Support\RecordingChannel;

/**
 * Nothing leaves the queue without a trace in the log.
 */
final class WorkerTest extends \WP_UnitTestCase {

	/**
	 * Channel under observation.
	 *
	 * @var RecordingChannel
	 */
	private $channel;

	/**
	 * Tables in place, a recording channel, empty alert state.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Schema::install();

		$this->channel = new RecordingChannel();
		Monitor::use_channels( array( $this->channel ) );
		delete_option( Monitor::OPTION );
	}

	/**
	 * Put the real channels back.
	 *
	 * @return void
	 */
	public function tear_down() {
		Monitor::use_channels( null );

		parent::tear_down();
	}

	/**
	 * A queued message whose stored contents can no longer be read.
	 *
	 * @return void
	 */
	private function queue_unreadable_message() {
		global $wpdb;

		$this->assertTrue(
			Queue::push(
				array(
					'to'      => 'jane@example.com',
					'subject' => 'Your order',
					'message' => 'Hello',
				)
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Damaging a row on purpose.
		$wpdb->update(
			Schema::queue_table(),
			array(
				'payload'         => '{"to":"jane@exa',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			),
			array( 'id' => (int) $wpdb->insert_id )
		);
	}

	/**
	 * An unreadable message is given up on with the reason in the log.
	 *
	 * @return void
	 */
	public function test_an_unreadable_message_is_logged_and_reported() {
		$this->queue_unreadable_message();

		$result = Worker::run();

		$this->assertSame( 1, $result['abandoned'] );
		$this->assertSame( 0, Queue::depth() );

		$entries = Store::page( array( 'status' => Store::STATUS_FAILED ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Your order', $entries[0]['subject'] );
		$this->assertStringContainsString( 'could no longer be read', $entries[0]['error'] );

		$this->assertCount( 1, $this->channel->alerts );
		$this->assertSame( Classifier::ABANDONED, $this->channel->alerts[0]->cause() );
	}
}
