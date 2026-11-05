<?php
/**
 * Deferred (see 00_Brief_Analysis.md). Paystack covers Nigerian card/bank
 * payments today; Flutterwave is the documented next gateway to add, same
 * interface, no changes needed elsewhere when it's built.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Flutterwave_Gateway implements Payment_Gateway {

	public function key(): string {
		return 'flutterwave';
	}

	public function is_configured(): bool {
		return false;
	}

	public function create_checkout( array $booking, int $amount_minor, string $currency, string $return_url ) {
		return new \WP_Error( 'sk_flutterwave_not_implemented', __( 'Flutterwave is not implemented yet.', 'slotkeeper' ) );
	}

	public function verify( string $reference ) {
		return new \WP_Error( 'sk_flutterwave_not_implemented', __( 'Flutterwave is not implemented yet.', 'slotkeeper' ) );
	}
}
