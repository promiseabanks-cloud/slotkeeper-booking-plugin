<?php
/**
 * The founder's own "you got a new booking" WhatsApp alert — separate from
 * Notification_Dispatcher, which only ever addresses the customer. Founder
 * request 2026-08-28: "i want both the founder and the client to get two
 * different whatsapp notification when a booking is done."
 *
 * Reuses the same `sk_notifications_log` table as customer notifications
 * for dedup/audit, under a distinct channel key ('whatsapp_admin') so it
 * never collides with or double-counts against the customer's own log rows.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Repositories\Notification_Log_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Notifier {

	private const CHANNEL_KEY = 'whatsapp_admin';
	private const TEMPLATE    = 'admin_new_booking';

	public function register(): void {
		add_action( 'sk_booking_created', array( $this, 'on_booking_created' ), 10, 2 );
	}

	public function on_booking_created( int $booking_id, array $booking ): void {
		$number = trim( (string) Settings::get( 'admin_whatsapp_number', '' ) );
		if ( ! $number ) {
			return; // No admin number configured — nothing to do, not an error.
		}

		$channel = new WhatsApp_Channel();
		if ( ! $channel->is_configured() ) {
			return;
		}

		$log = new Notification_Log_Repository();
		if ( $log->already_sent( $booking_id, self::CHANNEL_KEY, self::TEMPLATE ) ) {
			return; // Same protection every other notification path gets — never a duplicate alert.
		}

		try {
			$result = $channel->send_text( $number, Message_Builder::admin_alert_body( $booking ) );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'sk_channel_exception', $e->getMessage() );
		}

		$log->record(
			$booking_id,
			self::CHANNEL_KEY,
			self::TEMPLATE,
			is_wp_error( $result ) ? 'failed' : 'sent',
			is_wp_error( $result ) ? $result->get_error_message() : null
		);
	}
}
