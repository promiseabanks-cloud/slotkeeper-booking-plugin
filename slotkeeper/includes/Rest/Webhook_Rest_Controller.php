<?php
/**
 * Receives asynchronous payment-provider webhooks. Authorization here is a
 * verified signature, not a WP session or token — the same pattern as any
 * payment webhook (Paystack, Stripe, PayPal), distinct enough from the
 * guest-token model in Booking_Rest_Controller to warrant its own class.
 *
 * Every gateway gets its own route so each can verify its own signature
 * scheme, but they all converge on `Payment_Reconciler::mark_paid()` — which
 * also has a fallback caller (Booking_Rest_Controller's lookup endpoint) for
 * when a webhook never arrives at all; see that class for why that isn't just
 * theoretical.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Rest;

use SlotKeeper\Payments\PayPal_Gateway;
use SlotKeeper\Payments\Payment_Reconciler;
use SlotKeeper\Payments\Paystack_Gateway;
use SlotKeeper\Payments\Stripe_Gateway;
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
		foreach ( array( 'paystack', 'stripe', 'paypal' ) as $provider ) {
			register_rest_route(
				self::NAMESPACE,
				'/webhooks/' . $provider,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $provider . '_webhook' ),
					'permission_callback' => '__return_true', // Authorized by signature in each handler, not by WP auth.
				)
			);
		}
	}

	// ---- Paystack --------------------------------------------------------

	public function paystack_webhook( \WP_REST_Request $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'X-Paystack-Signature' );

		if ( ! $signature || ! Paystack_Gateway::verify_webhook_signature( $raw_body, $signature ) ) {
			return $this->invalid_signature();
		}

		$payload = json_decode( $raw_body, true );
		if ( 'charge.success' !== ( $payload['event'] ?? '' ) ) {
			// Acknowledge every event type so Paystack doesn't retry forever,
			// but only 'charge.success' does anything.
			return $this->acknowledge( false );
		}

		$reference = sanitize_text_field( (string) ( $payload['data']['reference'] ?? '' ) );
		return $this->confirm_by_reference( $reference );
	}

	// ---- Stripe ----------------------------------------------------------

	public function stripe_webhook( \WP_REST_Request $request ) {
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'Stripe-Signature' );

		if ( ! $signature || ! Stripe_Gateway::verify_webhook_signature( $raw_body, $signature ) ) {
			return $this->invalid_signature();
		}

		$payload = json_decode( $raw_body, true );
		if ( 'checkout.session.completed' !== ( $payload['type'] ?? '' ) ) {
			return $this->acknowledge( false );
		}

		// Only treat a session that actually paid as paid; an incomplete
		// session can fire this type too.
		$session = $payload['data']['object'] ?? array();
		if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
			return $this->acknowledge( false );
		}

		$reference = sanitize_text_field( (string) ( $session['id'] ?? '' ) );
		return $this->confirm_by_reference( $reference );
	}

	// ---- PayPal ----------------------------------------------------------

	public function paypal_webhook( \WP_REST_Request $request ) {
		$raw_body = $request->get_body();
		$headers  = array(
			'paypal-transmission-id'   => (string) $request->get_header( 'PayPal-Transmission-Id' ),
			'paypal-transmission-time' => (string) $request->get_header( 'PayPal-Transmission-Time' ),
			'paypal-cert-url'          => (string) $request->get_header( 'PayPal-Cert-Url' ),
			'paypal-auth-algo'         => (string) $request->get_header( 'PayPal-Auth-Algo' ),
			'paypal-transmission-sig'  => (string) $request->get_header( 'PayPal-Transmission-Sig' ),
		);

		if ( ! ( new PayPal_Gateway() )->verify_webhook( $headers, $raw_body ) ) {
			return $this->invalid_signature();
		}

		$payload = json_decode( $raw_body, true );
		if ( 'PAYMENT.CAPTURE.COMPLETED' !== ( $payload['event_type'] ?? '' ) ) {
			return $this->acknowledge( false );
		}

		// PayPal's capture carries the booking id we set as custom_id — match on
		// that rather than the order id, which the capture resource doesn't echo.
		$booking_id = (int) ( $payload['resource']['custom_id'] ?? 0 );
		return $this->confirm_by_booking( $booking_id );
	}

	// ---- shared ----------------------------------------------------------

	private function confirm_by_reference( string $reference ) {
		if ( '' === $reference ) {
			return new \WP_Error( 'sk_unknown_reference', __( 'Missing payment reference.', 'slotkeeper' ), array( 'status' => 400 ) );
		}

		$payment = ( new Payment_Repository() )->find_by_reference( $reference );
		if ( ! $payment ) {
			return new \WP_Error( 'sk_unknown_reference', __( 'Unknown payment reference.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		return $this->mark_and_acknowledge( $payment );
	}

	private function confirm_by_booking( int $booking_id ) {
		if ( $booking_id <= 0 ) {
			return new \WP_Error( 'sk_unknown_reference', __( 'Missing booking reference.', 'slotkeeper' ), array( 'status' => 400 ) );
		}

		$payment = ( new Payment_Repository() )->find_latest_for_booking( $booking_id );
		if ( ! $payment ) {
			return new \WP_Error( 'sk_unknown_reference', __( 'Unknown payment reference.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		return $this->mark_and_acknowledge( $payment );
	}

	private function mark_and_acknowledge( array $payment ) {
		if ( 'paid' === $payment['status'] ) {
			// Providers retry webhooks; a replay of an already-processed payment
			// is a no-op, not an error.
			return rest_ensure_response( array( 'received' => true, 'already_processed' => true ) );
		}

		$booking = ( new Booking_Repository() )->find( (int) $payment['booking_id'] );
		if ( $booking ) {
			Payment_Reconciler::mark_paid( $payment, $booking );
		}

		return $this->acknowledge( true );
	}

	private function acknowledge( bool $processed ) {
		return rest_ensure_response( array( 'received' => true, 'processed' => $processed ) );
	}

	private function invalid_signature() {
		return new \WP_Error( 'sk_invalid_signature', __( 'Invalid signature.', 'slotkeeper' ), array( 'status' => 401 ) );
	}
}
