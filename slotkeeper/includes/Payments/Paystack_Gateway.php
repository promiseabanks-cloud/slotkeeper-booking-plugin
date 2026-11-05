<?php
/**
 * Paystack checkout-links integration — the same shape already proven live
 * on Banks Business Engine: this plugin only ever requests a hosted
 * checkout link and verifies payment status afterwards. It never touches
 * card data and never holds funds itself, which is what keeps this out of
 * CBN payment-service-provider licensing territory.
 *
 * Secret key comes from the dashboard (SlotKeeper > Integrations, encrypted
 * at rest via Secrets) or a `SLOTKEEPER_PAYSTACK_SECRET_KEY` wp-config.php
 * constant, which always wins when defined. This class is real and callable
 * today — not called from the live booking-create flow yet, that's the
 * remaining part of Flow 07 (see PROJECT_BIBLE.md decision log).
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Paystack_Gateway implements Payment_Gateway {

	private const API_BASE       = 'https://api.paystack.co';
	private const CONSTANT_NAME  = 'SLOTKEEPER_PAYSTACK_SECRET_KEY';

	public function key(): string {
		return 'paystack';
	}

	public function is_configured(): bool {
		return Secrets::is_set( Secrets::PAYSTACK_SECRET_KEY, self::CONSTANT_NAME );
	}

	public function create_checkout( array $booking, int $amount_minor, string $currency, string $return_url ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_paystack_not_configured', __( 'Paystack is not configured.', 'slotkeeper' ) );
		}

		$customer = ( new \SlotKeeper\Repositories\Customer_Repository() )->find( (int) $booking['customer_id'] );
		if ( empty( $customer['email'] ) ) {
			return new \WP_Error( 'sk_no_email', __( 'A valid email is required to collect payment via Paystack.', 'slotkeeper' ) );
		}

		$reference = 'sk_' . $booking['id'] . '_' . wp_generate_password( 8, false );

		// Sends the browser back to the same page the widget was embedded
		// on (not the site homepage), carrying the booking's own cancel
		// token so the widget can look up real status on return — the
		// webhook is still the actual source of truth, this is only the
		// customer-facing "welcome back" landing.
		$callback_url = add_query_arg( 'sk_paid_token', $booking['cancel_token'], $return_url );

		$response = wp_remote_post(
			self::API_BASE . '/transaction/initialize',
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
				'body'    => wp_json_encode(
					array(
						'email'        => $customer['email'],
						'amount'       => $amount_minor, // Paystack expects the smallest currency unit already.
						'currency'     => $currency,
						'reference'    => $reference,
						'callback_url' => $callback_url,
						'metadata'     => array( 'booking_id' => $booking['id'] ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['status'] ) || empty( $body['data']['authorization_url'] ) ) {
			return new \WP_Error( 'sk_paystack_init_failed', $body['message'] ?? __( 'Paystack did not return a checkout URL.', 'slotkeeper' ) );
		}

		return array(
			'checkout_url' => $body['data']['authorization_url'],
			'reference'    => $body['data']['reference'],
		);
	}

	public function verify( string $reference ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_paystack_not_configured', __( 'Paystack is not configured.', 'slotkeeper' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . '/transaction/verify/' . rawurlencode( $reference ),
			array(
				'timeout' => 15,
				'headers' => $this->headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['status'] ) ) {
			return new \WP_Error( 'sk_paystack_verify_failed', $body['message'] ?? __( 'Verification failed.', 'slotkeeper' ) );
		}

		return array(
			'status'       => 'success' === ( $body['data']['status'] ?? '' ) ? 'paid' : 'failed',
			'reference'    => $body['data']['reference'],
			'amount_minor' => (int) ( $body['data']['amount'] ?? 0 ),
		);
	}

	/**
	 * Verifies Paystack's `x-paystack-signature` header against the raw
	 * request body. Called by `Webhook_Rest_Controller`.
	 */
	public static function verify_webhook_signature( string $raw_body, string $signature_header ): bool {
		$secret = Secrets::get( Secrets::PAYSTACK_SECRET_KEY, self::CONSTANT_NAME );
		if ( ! $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha512', $raw_body, $secret );
		return hash_equals( $expected, $signature_header );
	}

	private function headers(): array {
		return array(
			'Authorization' => 'Bearer ' . Secrets::get( Secrets::PAYSTACK_SECRET_KEY, self::CONSTANT_NAME ),
			'Content-Type'  => 'application/json',
		);
	}
}
