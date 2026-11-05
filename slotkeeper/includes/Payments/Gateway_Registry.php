<?php
/**
 * Resolves the site's active, configured payment gateway from settings, so
 * callers never hard-code "Paystack" — Flutterwave slots in here unchanged
 * once it's built.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gateway_Registry {

	/**
	 * The configured-and-ready gateway, or null if none is set up. Never
	 * returns a gateway that isn't actually configured — a caller checking
	 * for null already has its answer, no separate is_configured() call needed.
	 */
	public static function active(): ?Payment_Gateway {
		$key = Settings::get( 'payment_gateway', 'none' );

		$gateway = self::make( $key );

		return ( $gateway && $gateway->is_configured() ) ? $gateway : null;
	}

	/**
	 * Instantiate a gateway by key, configured or not — used by the admin
	 * screens (which list gateways regardless of setup state) and the webhook
	 * router (which resolves the gateway a given endpoint belongs to). Returns
	 * null for an unknown key.
	 */
	public static function make( string $key ): ?Payment_Gateway {
		return match ( $key ) {
			'paystack'    => new Paystack_Gateway(),
			'stripe'      => new Stripe_Gateway(),
			'paypal'      => new PayPal_Gateway(),
			'flutterwave' => new Flutterwave_Gateway(),
			default       => null,
		};
	}

	/**
	 * Every gateway SlotKeeper knows about, instantiated — for the settings
	 * dropdown and the integrations screen, in display order.
	 *
	 * @return array<int,Payment_Gateway>
	 */
	public static function all(): array {
		return array(
			new Paystack_Gateway(),
			new Stripe_Gateway(),
			new PayPal_Gateway(),
			new Flutterwave_Gateway(),
		);
	}
}
