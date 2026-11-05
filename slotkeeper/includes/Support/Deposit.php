<?php
/**
 * Works out how much to collect *now* for a booking — the whole price, or a
 * deposit — from the site's deposit policy. The remainder is settled in
 * person (at the appointment), which is how most salons and studios actually
 * run: a deposit secures the slot, the balance is paid on the day.
 *
 * Policy lives in `sk_settings` as `deposit_type` (full|percent|fixed) and
 * `deposit_value`, set on the Settings screen. A resource can override it per
 * service via its `meta` JSON (`deposit_type` / `deposit_value`) without a
 * schema change — the per-resource value wins when present.
 *
 * The amount charged is always clamped to at least 1 minor unit and never
 * more than the full price, so a misconfigured policy can't produce a zero
 * or over-charge.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deposit {

	public const TYPE_FULL    = 'full';
	public const TYPE_PERCENT = 'percent';
	public const TYPE_FIXED   = 'fixed';

	public const TYPES = array( self::TYPE_FULL, self::TYPE_PERCENT, self::TYPE_FIXED );

	/**
	 * Amount (minor units) to collect now for a resource whose full price is
	 * $full_minor. Falls back to the site policy when the resource doesn't
	 * override it.
	 *
	 * @param array<string,mixed>|null $resource A row from Resource_Repository, or null to use the site policy only.
	 */
	public static function amount_to_charge( int $full_minor, string $currency, ?array $resource = null ): int {
		if ( $full_minor <= 0 ) {
			return 0;
		}

		list( $type, $value ) = self::policy_for( $resource );

		switch ( $type ) {
			case self::TYPE_PERCENT:
				$percent = max( 0.0, min( 100.0, (float) $value ) );
				$charge  = (int) round( $full_minor * $percent / 100 );
				break;

			case self::TYPE_FIXED:
				$charge = Money::to_minor( (float) $value, $currency );
				break;

			case self::TYPE_FULL:
			default:
				return $full_minor;
		}

		// Never charge nothing for a paid booking, never charge more than the full price.
		return max( 1, min( $charge, $full_minor ) );
	}

	/** True when the policy collects less than the full price for this resource. */
	public static function is_partial( int $full_minor, string $currency, ?array $resource = null ): bool {
		return self::amount_to_charge( $full_minor, $currency, $resource ) < $full_minor;
	}

	/**
	 * Resolve the effective (type, value), resource override first then site
	 * policy.
	 *
	 * @param array<string,mixed>|null $resource
	 * @return array{0:string,1:float}
	 */
	private static function policy_for( ?array $resource ): array {
		if ( $resource && ! empty( $resource['meta'] ) ) {
			$meta = is_array( $resource['meta'] ) ? $resource['meta'] : json_decode( (string) $resource['meta'], true );
			if ( is_array( $meta ) && ! empty( $meta['deposit_type'] ) && in_array( $meta['deposit_type'], self::TYPES, true ) ) {
				return array( (string) $meta['deposit_type'], (float) ( $meta['deposit_value'] ?? 0 ) );
			}
		}

		$type = Settings::get( 'deposit_type', self::TYPE_FULL );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = self::TYPE_FULL;
		}
		return array( $type, (float) Settings::get( 'deposit_value', 0 ) );
	}
}
