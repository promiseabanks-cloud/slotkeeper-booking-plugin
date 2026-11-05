<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Calendar_Sync {

	public function key(): string;

	public function is_configured(): bool;

	/**
	 * Create (or ensure) the calendar event for a booking.
	 *
	 * @param array<string,mixed> $booking A row from Booking_Repository.
	 * @return string|\WP_Error The remote event id on success.
	 */
	public function push( array $booking );

	/**
	 * Remove a previously created calendar event.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel( string $event_id );
}
