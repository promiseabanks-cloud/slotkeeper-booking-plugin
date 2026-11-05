<?php
/**
 * Listens for `sk_booking_created` / `sk_booking_cancelled` and fans out to
 * every enabled, configured channel — Booking_Service never calls a channel
 * directly, so this is the only file that changes when a channel is added.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Repositories\Notification_Log_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notification_Dispatcher {

	/** @var array<string, Notification_Channel> */
	private array $channels;

	private Notification_Log_Repository $log;

	public function __construct() {
		$this->log = new Notification_Log_Repository();

		$email    = new Email_Channel();
		$whatsapp = new WhatsApp_Channel();
		$sms      = new SMS_Channel();

		$this->channels = array(
			$email->key()    => $email,
			$whatsapp->key() => $whatsapp,
			$sms->key()      => $sms,
		);
	}

	public function register(): void {
		add_action( 'sk_booking_created', array( $this, 'on_booking_created' ), 10, 2 );
		add_action( 'sk_booking_cancelled', array( $this, 'on_booking_cancelled' ), 10, 2 );
	}

	public function on_booking_created( int $booking_id, array $booking ): void {
		$template = 'unpaid' === $booking['payment_status'] ? 'booking_pending_payment' : 'booking_confirmed';
		$this->dispatch( $booking_id, $booking, $template );
	}

	public function on_booking_cancelled( int $booking_id, array $booking ): void {
		$this->dispatch( $booking_id, $booking, 'booking_cancelled' );
	}

	private function dispatch( int $booking_id, array $booking, string $template ): void {
		$enabled = Settings::get( 'notifications', array() );

		foreach ( $this->channels as $key => $channel ) {
			if ( empty( $enabled[ $key ] ) || ! $channel->is_configured() ) {
				continue;
			}

			if ( $this->log->already_sent( $booking_id, $key, $template ) ) {
				continue;
			}

			// A booking must never fail to save because a notification channel
			// misbehaves — one bad channel is logged and skipped, not fatal.
			try {
				$result = $channel->send( $booking, $template );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'sk_channel_exception', $e->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				$this->log->record( $booking_id, $key, $template, 'failed', $result->get_error_message() );
			} else {
				$this->log->record( $booking_id, $key, $template, 'sent' );
			}
		}
	}
}
