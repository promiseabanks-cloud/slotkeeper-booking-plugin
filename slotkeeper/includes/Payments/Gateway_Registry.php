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

		$gateway = match ( $key ) {
			'paystack'    => new Paystack_Gateway(),
			'flutterwave' => new Flutterwave_Gateway(),
			default       => null,
		};

		return ( $gateway && $gateway->is_configured() ) ? $gateway : null;
	}
}
