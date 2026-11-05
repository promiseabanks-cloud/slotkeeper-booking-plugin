<?php
/**
 * Single source of truth for the plugin's one settings option. Nothing else
 * in the plugin should call `get_option( 'sk_settings' )` directly — every
 * read/write goes through here so the default shape only lives in one place.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	private const OPTION_KEY = 'sk_settings';

	/**
	 * Valid booking types. This list is the mechanism Banks Business Engine
	 * never finished: enabling one or more of these actually changes which
	 * admin screens and front-end behavior are active.
	 */
	public const BOOKING_TYPES = array( 'appointments', 'reservations', 'rentals', 'classes' );

	/**
	 * Maps a booking type (what the settings screen and admin talk about) to
	 * the `sk_resources.type` value (what the schema and calculator use).
	 * One direction lives here; the reverse lookup is a simple array_search.
	 */
	public const BOOKING_TYPE_TO_RESOURCE_TYPE = array(
		'appointments'  => 'staff',
		'reservations'  => 'table',
		'rentals'       => 'rental_item',
		'classes'       => 'class_session',
	);

	public static function defaults(): array {
		return array(
			'business_name'         => get_bloginfo( 'name' ),
			'timezone'               => wp_timezone_string(),
			'currency'               => 'NGN',
			'widget_theme'           => 'light', // light|dark|auto — colour scheme the public booking widget renders in.
			// Business details shown in the footer of customer emails. All optional;
			// a row is only printed when its value is set.
			'business_address'       => '',
			'business_phone'         => '',
			'business_email'         => '', // Reply-to / contact address; falls back to the WP admin email in emails if left blank.
			'business_hours'         => '',
			'social_instagram'       => '',
			'social_tiktok'          => '',
			'social_facebook'        => '',
			'enabled_booking_types'  => array( 'appointments' ),
			'notifications'          => array(
				'email'    => true,
				'whatsapp' => false,
				'sms'      => false,
			),
			'payment_gateway'        => 'none', // none|paystack|stripe|paypal|flutterwave
			'paypal_mode'            => 'live', // live|sandbox — PayPal only; not a secret.
			'google_calendar_sync'   => false, // Add confirmed bookings to Google Calendar — needs a configured service account + calendar id.
			'deposit_type'           => 'percent', // full|percent|fixed — what to collect at booking time.
			'deposit_value'          => 50, // percent (1–100) for 'percent', or a major-unit amount for 'fixed'.
			'manage_booking_page_id' => 0, // WP page containing [slotkeeper_manage_booking] — 0 = not set, no cancel link sent.
			'admin_whatsapp_number'  => '', // Founder's own number for new-booking alerts — empty = alerts off.
			'delete_data_on_uninstall' => false,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	public static function update( array $values ): bool {
		$merged = wp_parse_args( $values, self::all() );
		return update_option( self::OPTION_KEY, $merged );
	}

	public static function is_booking_type_enabled( string $type ): bool {
		return in_array( $type, self::get( 'enabled_booking_types', array() ), true );
	}

	public static function option_key(): string {
		return self::OPTION_KEY;
	}
}
