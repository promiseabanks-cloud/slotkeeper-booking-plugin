<?php
/**
 * Currency-unit helpers. SlotKeeper stores every amount as an integer in the
 * currency's *minor* unit (kobo, cents, pence) — `price_minor`,
 * `amount_minor`, `total_minor`. Most gateways (Paystack, Stripe) also take
 * the minor unit, so no conversion is needed there; PayPal is the exception,
 * it wants a decimal string in the *major* unit ("10.00"), which is what
 * `to_major_string()` is for.
 *
 * Keeping the decimals-per-currency table in one place means a zero-decimal
 * currency (e.g. JPY) can't silently be charged 100× by code that assumed
 * two decimals.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Money {

	/**
	 * Minor-unit exponent per currency. Anything not listed falls back to 2,
	 * which covers every currency SlotKeeper currently offers (NGN, USD, GBP,
	 * EUR). Add a row here before offering a zero/three-decimal currency.
	 *
	 * @var array<string,int>
	 */
	private const DECIMALS = array(
		'NGN' => 2,
		'USD' => 2,
		'GBP' => 2,
		'EUR' => 2,
		'JPY' => 0,
	);

	private const SYMBOLS = array(
		'NGN' => '₦',
		'USD' => '$',
		'GBP' => '£',
		'EUR' => '€',
	);

	public static function decimals( string $currency ): int {
		return self::DECIMALS[ strtoupper( $currency ) ] ?? 2;
	}

	/** Smallest-unit factor, e.g. 100 for USD, 1 for JPY. */
	public static function factor( string $currency ): int {
		return (int) ( 10 ** self::decimals( $currency ) );
	}

	/** Major-unit number (e.g. 25 or 25.50) → minor units (2550). */
	public static function to_minor( float $major, string $currency ): int {
		return (int) round( $major * self::factor( $currency ) );
	}

	/** Minor units (2550) → float major units (25.5). */
	public static function to_major( int $minor, string $currency ): float {
		return $minor / self::factor( $currency );
	}

	/**
	 * Minor units → a fixed-decimal string in the major unit, as gateways
	 * like PayPal require ("25.50", or "2500" for a zero-decimal currency).
	 */
	public static function to_major_string( int $minor, string $currency ): string {
		return number_format( self::to_major( $minor, $currency ), self::decimals( $currency ), '.', '' );
	}

	/** Human-facing formatted amount, e.g. "$25" or "₦2,500". */
	public static function format( int $minor, string $currency ): string {
		$currency = strtoupper( $currency );
		$symbol   = self::SYMBOLS[ $currency ] ?? ( $currency . ' ' );
		$decimals = self::decimals( $currency );
		$major    = self::to_major( $minor, $currency );
		// Whole amounts read cleaner without ".00"; keep decimals only when the amount actually has them.
		$show     = ( $decimals > 0 && fmod( $major, 1.0 ) !== 0.0 ) ? $decimals : 0;
		return $symbol . number_format( $major, $show );
	}
}
