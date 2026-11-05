<?php
/**
 * The one place a payment gets marked paid and its booking confirmed —
 * shared by the webhook receiver (the primary path) and the booking-lookup
 * endpoint's fallback (for when a webhook never arrives, or hasn't yet).
 *
 * Built 2026-08-28 after live testing proved the gap directly: a real test
 * payment succeeded on Paystack's side but the webhook never reached the
 * site (likely never registered in the Paystack dashboard). A webhook-only
 * design leaves the customer stuck seeing "still confirming" forever in
 * that case — this makes the return-from-Paystack page self-healing.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Payment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payment_Reconciler {

	/**
	 * Idempotent — safe to call from both the webhook and a polling
	 * fallback without risk of double-processing or a duplicate
	 * notification (the dispatcher's own log already dedupes by template).
	 */
	public static function mark_paid( array $payment, array $booking ): void {
		if ( 'paid' === $payment['status'] ) {
			return;
		}

		( new Payment_Repository() )->update_status( (int) $payment['id'], 'paid' );

		$booking_repo = new Booking_Repository();
		$booking_repo->update_payment_status( (int) $booking['id'], 'paid' );
		$booking_repo->update_status( (int) $booking['id'], 'confirmed' );

		$fresh = $booking_repo->find( (int) $booking['id'] );
		do_action( 'sk_booking_created', $booking['id'], $fresh );
	}

	/**
	 * Actively asks the gateway whether a still-pending payment actually
	 * went through, for when the webhook hasn't (yet). Returns the fresh
	 * booking row if it reconciled to paid, or null if there's nothing to
	 * do (already paid, still genuinely pending, or nothing to check).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function reconcile_if_needed( array $booking ): ?array {
		if ( 'pending' !== $booking['status'] || 'unpaid' !== $booking['payment_status'] ) {
			return null;
		}

		$payment = ( new Payment_Repository() )->find_latest_for_booking( (int) $booking['id'] );
		if ( ! $payment || 'pending' !== $payment['status'] || ! $payment['gateway_reference'] ) {
			return null;
		}

		$gateway = Gateway_Registry::active();
		if ( ! $gateway || $gateway->key() !== $payment['gateway'] ) {
			return null;
		}

		$result = $gateway->verify( $payment['gateway_reference'] );
		if ( is_wp_error( $result ) || 'paid' !== $result['status'] ) {
			return null;
		}

		self::mark_paid( $payment, $booking );

		return ( new Booking_Repository() )->find( (int) $booking['id'] );
	}
}
