<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Payment_Gateway {

	public function key(): string;

	public function is_configured(): bool;

	/**
	 * @param array<string,mixed> $booking A row from Booking_Repository.
	 * @param string $return_url Where the gateway sends the browser back
	 *   after payment — the host page the widget was embedded on, so the
	 *   customer lands back where they started, not the site homepage.
	 * @return array{checkout_url:string,reference:string}|\WP_Error
	 */
	public function create_checkout( array $booking, int $amount_minor, string $currency, string $return_url );

	/**
	 * @return array{status:string,reference:string,amount_minor:int}|\WP_Error
	 */
	public function verify( string $reference );
}
