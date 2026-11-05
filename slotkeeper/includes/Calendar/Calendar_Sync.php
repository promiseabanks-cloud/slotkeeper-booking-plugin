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
	 * @param array<string,mixed> $booking A row from Booking_Repository.
	 * @return true|\WP_Error
	 */
	public function push( array $booking );
}
