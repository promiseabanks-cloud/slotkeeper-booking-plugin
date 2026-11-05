<?php
/**
 * Deferred, on purpose (see 00_Brief_Analysis.md / CLIENT_QUESTIONS.md):
 * WhatsApp via Meta Cloud API covers the same need for free and was built
 * first. This class exists so the settings screen and dispatcher have a
 * real, registered SMS option that honestly reports "not built yet" instead
 * of a hidden gap — wiring a real gateway (e.g. Termii) later means writing
 * one class, not touching the dispatcher or settings UI.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SMS_Channel implements Notification_Channel {

	public function key(): string {
		return 'sms';
	}

	public function is_configured(): bool {
		return false;
	}

	public function send( array $booking, string $template ) {
		return new \WP_Error( 'sk_sms_not_implemented', __( 'SMS is not implemented yet.', 'slotkeeper' ) );
	}
}
