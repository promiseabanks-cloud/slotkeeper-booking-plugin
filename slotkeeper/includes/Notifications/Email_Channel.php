<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Repositories\Customer_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Channel implements Notification_Channel {

	public function key(): string {
		return 'email';
	}

	public function is_configured(): bool {
		return true; // wp_mail always exists — no external credentials needed.
	}

	public function send( array $booking, string $template ) {
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );

		if ( empty( $customer['email'] ) || ! is_email( $customer['email'] ) ) {
			return new \WP_Error( 'sk_no_email', __( 'Customer has no valid email address.', 'slotkeeper' ) );
		}

		$sent = wp_mail(
			$customer['email'],
			Message_Builder::subject( $template ),
			Message_Builder::body( $booking, $template )
		);

		return $sent ? true : new \WP_Error( 'sk_wp_mail_failed', __( 'wp_mail() reported failure.', 'slotkeeper' ) );
	}
}
