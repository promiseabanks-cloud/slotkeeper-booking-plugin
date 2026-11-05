<?php
/**
 * PayPal (Orders v2) integration — the same hosted-redirect shape as the
 * Paystack and Stripe gateways: SlotKeeper asks PayPal for a hosted approval
 * URL, the buyer approves on PayPal, and on their return the order is
 * captured and verified. SlotKeeper never touches card data or holds funds.
 *
 * Credentials come from the dashboard (SlotKeeper > Integrations, encrypted
 * at rest via Secrets) or `SLOTKEEPER_PAYPAL_CLIENT_ID` /
 * `SLOTKEEPER_PAYPAL_SECRET` wp-config.php constants, which win when defined.
 * Live vs sandbox is a non-secret setting (`paypal_mode`).
 *
 * Unlike Paystack/Stripe, PayPal takes the amount as a decimal string in the
 * currency's *major* unit, so amounts are converted via Support\Money.
 *
 * Confirmation is primarily the on-return verify()+capture (the reconcile-on-
 * lookup path); the webhook is a secondary signal and is only trusted when a
 * webhook id is configured so its signature can be verified with PayPal.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

use SlotKeeper\Support\Money;
use SlotKeeper\Support\Secrets;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PayPal_Gateway implements Payment_Gateway {

	private const CLIENT_CONSTANT  = 'SLOTKEEPER_PAYPAL_CLIENT_ID';
	private const SECRET_CONSTANT  = 'SLOTKEEPER_PAYPAL_SECRET';
	private const WEBHOOK_CONSTANT = 'SLOTKEEPER_PAYPAL_WEBHOOK_ID';

	public function key(): string {
		return 'paypal';
	}

	public function is_configured(): bool {
		return Secrets::is_set( Secrets::PAYPAL_CLIENT_ID, self::CLIENT_CONSTANT )
			&& Secrets::is_set( Secrets::PAYPAL_SECRET, self::SECRET_CONSTANT );
	}

	public function create_checkout( array $booking, int $amount_minor, string $currency, string $return_url ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_paypal_not_configured', __( 'PayPal is not configured.', 'slotkeeper' ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$return_to = add_query_arg( 'sk_paid_token', $booking['cancel_token'], $return_url );
		$cancel_to = add_query_arg( 'sk_cancelled', '1', $return_url );

		$payload = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'custom_id' => (string) $booking['id'],
					'amount'    => array(
						'currency_code' => strtoupper( $currency ),
						'value'         => Money::to_major_string( $amount_minor, $currency ),
					),
				),
			),
			'application_context' => array(
				'return_url'          => $return_to,
				'cancel_url'          => $cancel_to,
				'shipping_preference' => 'NO_SHIPPING',
				'user_action'         => 'PAY_NOW',
				'brand_name'          => Settings::get( 'business_name', get_bloginfo( 'name' ) ),
			),
		);

		$response = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id'] ) || empty( $body['links'] ) ) {
			$message = $body['message'] ?? __( 'PayPal did not return an approval URL.', 'slotkeeper' );
			return new \WP_Error( 'sk_paypal_init_failed', $message );
		}

		$approve = '';
		foreach ( $body['links'] as $link ) {
			if ( 'approve' === ( $link['rel'] ?? '' ) ) {
				$approve = $link['href'];
				break;
			}
		}

		if ( ! $approve ) {
			return new \WP_Error( 'sk_paypal_init_failed', __( 'PayPal did not return an approval URL.', 'slotkeeper' ) );
		}

		return array(
			'checkout_url' => $approve,
			'reference'    => $body['id'], // PayPal order id.
		);
	}

	/**
	 * Called both on the buyer's return (reconcile-on-lookup) and from the
	 * webhook. An APPROVED order is captured here; an already-captured or
	 * COMPLETED order reports paid without double-capturing.
	 */
	public function verify( string $reference ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_paypal_not_configured', __( 'PayPal is not configured.', 'slotkeeper' ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$order = $this->get_order( $reference, $token );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$status = $order['status'] ?? '';

		if ( 'APPROVED' === $status ) {
			$order = $this->capture_order( $reference, $token );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$status = $order['status'] ?? '';
		}

		return array(
			'status'       => 'COMPLETED' === $status ? 'paid' : 'failed',
			'reference'    => $reference,
			'amount_minor' => $this->captured_amount_minor( $order ),
		);
	}

	/**
	 * Asks PayPal to confirm a received webhook is genuine. Requires a
	 * configured webhook id (so the signature can be checked); without one we
	 * can't verify and the webhook must be rejected — the on-return verify()
	 * still confirms the payment either way.
	 *
	 * @param array<string,string> $headers Transmission headers from the request.
	 */
	public function verify_webhook( array $headers, string $raw_body ): bool {
		$webhook_id = Secrets::get( Secrets::PAYPAL_WEBHOOK_ID, self::WEBHOOK_CONSTANT );
		if ( ! $webhook_id || ! $this->is_configured() ) {
			return false;
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return false;
		}

		$event = json_decode( $raw_body, true );
		if ( ! is_array( $event ) ) {
			return false;
		}

		$payload = array(
			'transmission_id'   => $headers['paypal-transmission-id'] ?? '',
			'transmission_time' => $headers['paypal-transmission-time'] ?? '',
			'cert_url'          => $headers['paypal-cert-url'] ?? '',
			'auth_algo'         => $headers['paypal-auth-algo'] ?? '',
			'transmission_sig'  => $headers['paypal-transmission-sig'] ?? '',
			'webhook_id'        => $webhook_id,
			'webhook_event'     => $event,
		);

		$response = wp_remote_post(
			$this->api_base() . '/v1/notifications/verify-webhook-signature',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return 'SUCCESS' === ( $body['verification_status'] ?? '' );
	}

	// ---- internals -------------------------------------------------------

	private function get_order( string $order_id, string $token ) {
		$response = wp_remote_get(
			$this->api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ),
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id'] ) ) {
			return new \WP_Error( 'sk_paypal_verify_failed', $body['message'] ?? __( 'PayPal verification failed.', 'slotkeeper' ) );
		}
		return $body;
	}

	private function capture_order( string $order_id, string $token ) {
		$response = wp_remote_post(
			$this->api_base() . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// A race with the webhook (or a double return) can capture first; PayPal
		// then answers ORDER_ALREADY_CAPTURED. That's success, not failure — re-read the order.
		if ( isset( $body['name'] ) && 'UNPROCESSABLE_ENTITY' === $body['name'] ) {
			$issue = $body['details'][0]['issue'] ?? '';
			if ( 'ORDER_ALREADY_CAPTURED' === $issue ) {
				return $this->get_order( $order_id, $token );
			}
		}

		if ( empty( $body['id'] ) ) {
			return new \WP_Error( 'sk_paypal_capture_failed', $body['message'] ?? __( 'PayPal capture failed.', 'slotkeeper' ) );
		}
		return $body;
	}

	private function captured_amount_minor( array $order ): int {
		$capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
		if ( $capture && isset( $capture['amount']['value'], $capture['amount']['currency_code'] ) ) {
			return Money::to_minor( (float) $capture['amount']['value'], (string) $capture['amount']['currency_code'] );
		}
		$amount = $order['purchase_units'][0]['amount'] ?? null;
		if ( $amount && isset( $amount['value'], $amount['currency_code'] ) ) {
			return Money::to_minor( (float) $amount['value'], (string) $amount['currency_code'] );
		}
		return 0;
	}

	private function access_token() {
		$client = Secrets::get( Secrets::PAYPAL_CLIENT_ID, self::CLIENT_CONSTANT );
		$secret = Secrets::get( Secrets::PAYPAL_SECRET, self::SECRET_CONSTANT );

		$response = wp_remote_post(
			$this->api_base() . '/v1/oauth2/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client . ':' . $secret ), // phpcs:ignore -- HTTP Basic auth encoding, not obfuscation.
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new \WP_Error( 'sk_paypal_auth_failed', $body['error_description'] ?? __( 'Could not authenticate with PayPal.', 'slotkeeper' ) );
		}
		return (string) $body['access_token'];
	}

	private function api_base(): string {
		return 'sandbox' === Settings::get( 'paypal_mode', 'live' )
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
	}
}
