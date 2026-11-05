<?php
/**
 * Receives asynchronous payment-provider webhooks. Authorization here is a
 * verified signature, not a WP session or token — the same pattern as any
 * payment webhook (Stripe, Paystack, etc.), distinct enough from the
 * guest-token model in Booking_Rest_Controller to warrant its own class.
 *
 * The primary confirmation path — `Payment_Reconciler::mark_paid()` also
 * has a fallback caller (Booking_Rest_Controller's lookup endpoint) for
 * when a webhook never arrives at all; see that class for why that isn't
 * just theoretical.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Rest;

use SlotKeeper\Payments\Payment_Reconciler;
use SlotKeeper\Payments\Paystack_Gateway;
use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Payment_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Webhook_Rest_Controller {

	private const NAMESPACE = 'slotkeeper/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/paystack',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'paystack_webhook' ),
				'permission_callback' => '__return_true', // Authorized by signature below, not by WP auth.
			)
		);
	}

	public function paystack_webhook( \WP_REST_Request $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'X-Paystack-Signature' );

		if ( ! $signature || ! Paystack_Gateway::verify_webhook_signature( $raw_body, $signature ) ) {
			return new \WP_Error( 'sk_invalid_signature', __( 'Invalid signature.', 'slotkeeper' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw_body, true );
		$event   = $payload['event'] ?? '';

		if ( 'charge.success' !== $event ) {
			// Acknowledge every event type so Paystack doesn't retry it
			// forever, but only 'charge.success' does anything.
			return rest_ensure_response( array( 'received' => true, 'processed' => false ) );
		}

		$reference = sanitize_text_field( (string) ( $payload['data']['reference'] ?? '' ) );
		$payment   = ( new Payment_Repository() )->find_by_reference( $reference );

		if ( ! $payment ) {
			return new \WP_Error( 'sk_unknown_reference', __( 'Unknown payment reference.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		if ( 'paid' === $payment['status'] ) {
			// Paystack retries webhooks; a replay of an already-processed
			// payment is a no-op, not an error.
			return rest_ensure_response( array( 'received' => true, 'already_processed' => true ) );
		}

		$booking = ( new Booking_Repository() )->find( (int) $payment['booking_id'] );
		if ( $booking ) {
			Payment_Reconciler::mark_paid( $payment, $booking );
		}

		return rest_ensure_response( array( 'received' => true, 'processed' => true ) );
	}
}
