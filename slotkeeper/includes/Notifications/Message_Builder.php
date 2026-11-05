<?php
/**
 * Turns a booking row into human-readable subject/body text, shared by
 * every channel so wording only changes in one place.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Message_Builder {

	public static function subject( string $template ): string {
		$business = Settings::get( 'business_name' );
		switch ( $template ) {
			case 'booking_confirmed':
				return sprintf( /* translators: %s: business name */ __( 'Your booking with %s is confirmed', 'slotkeeper' ), $business );
			case 'booking_pending_payment':
				return sprintf( __( 'Complete payment to confirm your booking with %s', 'slotkeeper' ), $business );
			case 'booking_cancelled':
				return sprintf( __( 'Your booking with %s was cancelled', 'slotkeeper' ), $business );
			default:
				return $business;
		}
	}

	public static function body( array $booking, string $template ): string {
		$resource = ( new Resource_Repository() )->find( (int) $booking['resource_id'] );
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );
		$tz       = wp_timezone();

		$when = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $booking['start_datetime'] ),
			$tz
		);

		$name  = $resource['name'] ?? __( 'your booking', 'slotkeeper' );
		$who   = $customer['name'] ?? '';

		$manage_line = self::manage_link_line( $booking );

		switch ( $template ) {
			case 'booking_confirmed':
				return sprintf(
					/* translators: 1: customer name 2: resource name 3: date/time 4: business name 5: manage-booking line, empty if not configured */
					__( "Hi %1\$s,\n\nYour booking for %2\$s on %3\$s is confirmed. See you then!\n%5\$s\n— %4\$s", 'slotkeeper' ),
					$who,
					$name,
					$when,
					Settings::get( 'business_name' ),
					$manage_line
				);

			case 'booking_pending_payment':
				return sprintf(
					__( "Hi %1\$s,\n\nYour booking for %2\$s on %3\$s is held, pending payment. Please complete payment to confirm it.\n%5\$s\n— %4\$s", 'slotkeeper' ),
					$who,
					$name,
					$when,
					Settings::get( 'business_name' ),
					$manage_line
				);

			case 'booking_cancelled':
				return sprintf(
					__( "Hi %1\$s,\n\nYour booking for %2\$s on %3\$s has been cancelled.\n\n— %4\$s", 'slotkeeper' ),
					$who,
					$name,
					$when,
					Settings::get( 'business_name' )
				);

			default:
				return '';
		}
	}

	/**
	 * HTML version of body(), for the email channel only. Same data and wording
	 * as the plain-text body (which WhatsApp/SMS still use), wrapped in the
	 * modern Email_Template. Kept here so copy for a template lives next to its
	 * plain-text twin.
	 */
	public static function html_body( array $booking, string $template ): string {
		$resource = ( new Resource_Repository() )->find( (int) $booking['resource_id'] );
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );

		$when = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $booking['start_datetime'] ),
			wp_timezone()
		);

		$name = $resource['name'] ?? __( 'your booking', 'slotkeeper' );
		$who  = $customer['name'] ?? '';
		$rows = array(
			array( __( 'Service', 'slotkeeper' ), $name ),
			array( __( 'When', 'slotkeeper' ), $when ),
		);

		$manage_url = self::manage_url( $booking );
		$cta        = $manage_url ? array( 'url' => $manage_url, 'label' => __( 'Manage your booking', 'slotkeeper' ) ) : array();

		switch ( $template ) {
			case 'booking_confirmed':
				return Email_Template::wrap(
					__( "You're booked", 'slotkeeper' ),
					sprintf( __( 'Hi %1$s, your booking for %2$s is confirmed. We look forward to seeing you.', 'slotkeeper' ), $who, $name ),
					$rows,
					$cta,
					$manage_url ? __( 'Need to make a change? Use the button above to manage or cancel your booking.', 'slotkeeper' ) : ''
				);

			case 'booking_pending_payment':
				return Email_Template::wrap(
					__( 'Almost there', 'slotkeeper' ),
					sprintf( __( 'Hi %1$s, your booking for %2$s is held pending payment. Please complete payment to confirm it.', 'slotkeeper' ), $who, $name ),
					$rows,
					$cta,
					__( 'Your slot is held for a short while so no one else can take it.', 'slotkeeper' )
				);

			case 'booking_cancelled':
				return Email_Template::wrap(
					__( 'Booking cancelled', 'slotkeeper' ),
					sprintf( __( 'Hi %1$s, your booking for %2$s has been cancelled. We hope to see you another time.', 'slotkeeper' ), $who, $name ),
					$rows
				);

			default:
				return Email_Template::wrap( Settings::get( 'business_name' ), self::body( $booking, $template ) );
		}
	}

	/**
	 * Founder-facing "you got a new booking" alert — deliberately separate
	 * from `body()`, which is always addressed to the customer. Different
	 * audience, different wording, never the same message reused.
	 */
	public static function admin_alert_body( array $booking ): string {
		$resource = ( new Resource_Repository() )->find( (int) $booking['resource_id'] );
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );
		$when     = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $booking['start_datetime'] ),
			wp_timezone()
		);

		$status_line = 'unpaid' === $booking['payment_status']
			? __( 'Pending payment', 'slotkeeper' )
			: __( 'Confirmed', 'slotkeeper' );

		return sprintf(
			/* translators: 1: resource name 2: date/time 3: customer name 4: customer phone 5: status */
			__( "*New booking* 📅\n%1\$s\n%2\$s\n\nCustomer: %3\$s (%4\$s)\nStatus: %5\$s", 'slotkeeper' ),
			$resource['name'] ?? '',
			$when,
			$customer['name'] ?? '',
			$customer['phone'] ?? '',
			$status_line
		);
	}

	/**
	 * "Need to cancel?" line + link, only when the founder has configured a
	 * manage-booking page — otherwise silently omitted rather than pointing
	 * customers at a page that doesn't exist. Own line so sprintf can drop
	 * it cleanly when empty.
	 */
	private static function manage_link_line( array $booking ): string {
		$url = self::manage_url( $booking );
		if ( '' === $url ) {
			return '';
		}

		return "\n" . sprintf(
			/* translators: %s: manage-booking URL */
			__( "Need to cancel? Manage your booking here: %s\n", 'slotkeeper' ),
			$url
		);
	}

	/**
	 * The manage-booking URL for this booking, or '' when the founder hasn't
	 * configured a manage-booking page. Shared by the plain-text line and the
	 * HTML email button so both agree on when a cancel link exists.
	 */
	private static function manage_url( array $booking ): string {
		$page_id = (int) Settings::get( 'manage_booking_page_id', 0 );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		return add_query_arg( 'token', $booking['cancel_token'], get_permalink( $page_id ) );
	}
}
