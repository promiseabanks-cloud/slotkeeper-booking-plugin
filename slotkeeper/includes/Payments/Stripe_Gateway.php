<?php
/**
 * Stripe Checkout integration — the same hosted-redirect shape as the
 * Paystack gateway: SlotKeeper only ever asks Stripe for a hosted Checkout
 * Session URL and verifies status afterwards. It never touches card data and
 * never holds funds itself.
 *
 * Secret key comes from the dashboard (SlotKeeper > Integrations, encrypted
 * at rest via Secrets) or a `SLOTKEEPER_STRIPE_SECRET_KEY` wp-config.php
 * constant, which always wins when defined. The webhook signing secret
 * (`whsec_...`) is stored the same way; without it the webhook route rejects
 * everything and confirmation falls back to the on-return verify() — the
 * same self-healing path the Paystack flow already relies on.
 *
 * Amounts are passed in the currency's minor unit (what SlotKeeper stores and
 * what Stripe's `unit_amount` expects), so no conversion is needed here.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Stripe_Gateway implements Payment_Gateway {

	private const API_BASE              = 'https://api.stripe.com/v1';
	private const SECRET_CONSTANT       = 'SLOTKEEPER_STRIPE_SECRET_KEY';
	private const WEBHOOK_CONSTANT      = 'SLOTKEEPER_STRIPE_WEBHOOK_SECRET';
	private const SIGNATURE_TOLERANCE   = 300; // seconds; rejects replayed webhooks older than this.

	public function key(): string {
		return 'stripe';
	}

	public function is_configured(): bool {
		return Secrets::is_set( Secrets::STRIPE_SECRET_KEY, self::SECRET_CONSTANT );
	}

	public function create_checkout( array $booking, int $amount_minor, string $currency, string $return_url ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_stripe_not_configured', __( 'Stripe is not configured.', 'slotkeeper' ) );
		}

		$customer = ( new \SlotKeeper\Repositories\Customer_Repository() )->find( (int) $booking['customer_id'] );
		if ( empty( $customer['email'] ) ) {
			return new \WP_Error( 'sk_no_email', __( 'A valid email is required to collect payment via Stripe.', 'slotkeeper' ) );
		}

		// Browser returns to the page the widget sat on, carrying the booking's
		// cancel token so the widget can look up real status on return. The
		// webhook remains the source of truth; this is the "welcome back" landing.
		$success_url = add_query_arg( 'sk_paid_token', $booking['cancel_token'], $return_url );
		$cancel_url  = add_query_arg( 'sk_cancelled', '1', $return_url );

		$params = array(
			'mode'                 => 'payment',
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'client_reference_id'  => 'sk_' . $booking['id'],
			'customer_email'       => $customer['email'],
			'line_items'           => array(
				array(
					'quantity'   => 1,
					'price_data' => array(
						'currency'     => strtolower( $currency ),
						'unit_amount'  => $amount_minor,
						'product_data' => array(
							'name' => $this->line_item_name( $booking ),
						),
					),
				),
			),
			'metadata'             => array(
				'booking_id' => (string) $booking['id'],
			),
			'payment_intent_data'  => array(
				'metadata' => array( 'booking_id' => (string) $booking['id'] ),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/checkout/sessions',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->secret(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $params ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) || empty( $body['url'] ) ) {
			$message = $body['error']['message'] ?? __( 'Stripe did not return a checkout URL.', 'slotkeeper' );
			return new \WP_Error( 'sk_stripe_init_failed', $message );
		}

		return array(
			'checkout_url' => $body['url'],
			'reference'    => $body['id'], // Checkout Session id (cs_...).
		);
	}

	public function verify( string $reference ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_stripe_not_configured', __( 'Stripe is not configured.', 'slotkeeper' ) );
		}

		$response = wp_remote_get(
			self::API_BASE . '/checkout/sessions/' . rawurlencode( $reference ),
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->secret() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['id'] ) ) {
			$message = $body['error']['message'] ?? __( 'Stripe verification failed.', 'slotkeeper' );
			return new \WP_Error( 'sk_stripe_verify_failed', $message );
		}

		return array(
			'status'       => 'paid' === ( $body['payment_status'] ?? '' ) ? 'paid' : 'failed',
			'reference'    => $body['id'],
			'amount_minor' => (int) ( $body['amount_total'] ?? 0 ),
		);
	}

	/**
	 * Verifies Stripe's `Stripe-Signature` header against the raw body using
	 * the webhook signing secret. Follows Stripe's documented scheme: the
	 * signed payload is `{timestamp}.{raw_body}`, HMAC-SHA256 with the secret,
	 * compared constant-time against each `v1=` entry, within a replay window.
	 * Called by `Webhook_Rest_Controller`.
	 */
	public static function verify_webhook_signature( string $raw_body, string $signature_header ): bool {
		$secret = Secrets::get( Secrets::STRIPE_WEBHOOK_SECRET, self::WEBHOOK_CONSTANT );
		if ( ! $secret || ! $signature_header ) {
			return false;
		}

		$timestamp = '';
		$signatures = array();
		foreach ( explode( ',', $signature_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( count( $pair ) !== 2 ) {
				continue;
			}
			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( '' === $timestamp || empty( $signatures ) ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > self::SIGNATURE_TOLERANCE ) {
			return false; // Stale timestamp — reject replays.
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	private function line_item_name( array $booking ): string {
		$resource = ( new \SlotKeeper\Repositories\Resource_Repository() )->find( (int) $booking['resource_id'] );
		return $resource['name'] ?? __( 'Booking', 'slotkeeper' );
	}

	private function secret(): string {
		return (string) Secrets::get( Secrets::STRIPE_SECRET_KEY, self::SECRET_CONSTANT );
	}
}
