<?php
/**
 * Showing the retry queue.
 *
 * @package MailVitals
 */

namespace MailVitals\Tests\Queue;

use MailVitals\Admin\SettingsPage;
use MailVitals\Log\Schema;
use MailVitals\Queue\Queue;

/**
 * What the settings screen can see of a queued message.
 */
final class QueueTest extends \WP_UnitTestCase {

	/**
	 * Tables in place.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Schema::install();
	}

	/**
	 * Queue a message.
	 *
	 * @param string $subject Subject.
	 * @param int    $due     Seconds from now until it is due.
	 * @return int Row id.
	 */
	private function queue( $subject, $due ) {
		global $wpdb;

		Queue::push(
			array(
				'to'      => array( 'jane@example.com', 'sam@example.com' ),
				'subject' => $subject,
				'message' => 'Secret body text',
			)
		);

		$id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Setting up a row.
		$wpdb->update( Schema::queue_table(), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $due ) ), array( 'id' => $id ) );

		return $id;
	}

	/**
	 * The next message to be tried comes first, without its body.
	 *
	 * @return void
	 */
	public function test_waiting_lists_the_next_first_without_bodies() {
		$this->queue( 'Later', 3600 );
		$this->queue( 'Sooner', 60 );

		$waiting = Queue::waiting();

		$this->assertSame( array( 'Sooner', 'Later' ), wp_list_pluck( $waiting, 'subject' ) );
		$this->assertSame( 'jane@example.com, sam@example.com', $waiting[0]['recipients'] );
		$this->assertTrue( $waiting[0]['readable'] );
		$this->assertStringNotContainsString( 'Secret body', wp_json_encode( $waiting ) );
	}

	/**
	 * A damaged row is shown as such rather than hidden.
	 *
	 * @return void
	 */
	public function test_an_unreadable_message_is_marked() {
		global $wpdb;

		$id = $this->queue( 'Damaged', 60 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Damaging a row on purpose.
		$wpdb->update( Schema::queue_table(), array( 'payload' => '{"to":' ), array( 'id' => $id ) );

		$waiting = Queue::waiting();

		$this->assertFalse( $waiting[0]['readable'] );
		$this->assertSame( '', $waiting[0]['recipients'] );
	}

	/**
	 * The settings screen shows the queue, overdue messages included, but
	 * never a body.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_shows_the_queue() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->queue( 'Order 1001', -7200 );

		ob_start();
		SettingsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Retry queue', $html );
		$this->assertStringContainsString( 'Order 1001', $html );
		$this->assertStringContainsString( 'Due 2 hours ago', $html );
		$this->assertStringContainsString( 'Scheduled events are not running', $html );
		$this->assertStringNotContainsString( 'Secret body', $html );
	}
}
