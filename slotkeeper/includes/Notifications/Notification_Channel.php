<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Notification_Channel {

	/**
	 * Unique key used in settings and the notifications log — 'email',
	 * 'whatsapp', 'sms'.
	 */
	public function key(): string;

	/**
	 * Whether this channel has what it needs (API keys etc.) to actually
	 * send right now. The dispatcher skips a channel that isn't configured
	 * instead of failing loudly on every booking.
	 */
	public function is_configured(): bool;

	/**
	 * @param array<string,mixed> $booking A row from Booking_Repository.
	 * @param string $template One of 'booking_confirmed', 'booking_pending_payment', 'booking_cancelled'.
	 * @return true|\WP_Error
	 */
	public function send( array $booking, string $template );
}
