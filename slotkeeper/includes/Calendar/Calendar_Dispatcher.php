<?php
/**
 * Listens for `sk_booking_created` / `sk_booking_cancelled` and keeps the
 * Google Calendar in step — the same event-bus pattern as
 * Notification_Dispatcher, so Booking_Service never calls calendar code
 * directly. A confirmed booking gets an event; a cancelled one has its event
 * removed. Calendar failures are swallowed (logged to error_log), never fatal
 * to the booking itself.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Calendar;

use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar_Dispatcher {

	private Google_Calendar_Sync $sync;
	private Booking_Repository $bookings;

	public function __construct() {
		$this->sync     = new Google_Calendar_Sync();
		$this->bookings = new Booking_Repository();
	}

	public function register(): void {
		add_action( 'sk_booking_created', array( $this, 'on_booking_created' ), 10, 2 );
		add_action( 'sk_booking_cancelled', array( $this, 'on_booking_cancelled' ), 10, 2 );
	}

	public function on_booking_created( int $booking_id, array $booking ): void {
		if ( ! $this->active() ) {
			return;
		}

		// Only confirmed bookings go on the calendar — a pending, unpaid hold
		// shouldn't clutter it. A paid booking fires this again as 'confirmed'
		// once payment lands (see Payment_Reconciler), which is when it syncs.
		if ( 'confirmed' !== ( $booking['status'] ?? '' ) ) {
			return;
		}

		// Already synced (e.g. the event fired twice) — don't duplicate.
		if ( ! empty( $booking['calendar_event_id'] ) ) {
			return;
		}

		try {
			$event_id = $this->sync->push( $booking );
		} catch ( \Throwable $e ) {
			$event_id = new \WP_Error( 'sk_calendar_exception', $e->getMessage() );
		}

		if ( is_wp_error( $event_id ) ) {
			$this->log( $event_id->get_error_message() );
			return;
		}

		$this->bookings->set_calendar_event_id( $booking_id, $event_id );
	}

	public function on_booking_cancelled( int $booking_id, array $booking ): void {
		$event_id = (string) ( $booking['calendar_event_id'] ?? '' );
		if ( '' === $event_id || ! $this->sync->is_configured() ) {
			return;
		}

		try {
			$result = $this->sync->cancel( $event_id );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'sk_calendar_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$this->log( $result->get_error_message() );
			return;
		}

		$this->bookings->clear_calendar_event_id( $booking_id );
	}

	private function active(): bool {
		return ! empty( Settings::get( 'google_calendar_sync', false ) ) && $this->sync->is_configured();
	}

	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SlotKeeper Google Calendar: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG; a calendar failure must never break a booking.
		}
	}
}
