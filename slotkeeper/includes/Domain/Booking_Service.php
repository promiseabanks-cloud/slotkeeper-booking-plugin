<?php
/**
 * The one place a booking gets created or cancelled. Owns the
 * double-booking-proof critical section and fires the events everything
 * else (notifications, later: payments, calendar sync) hangs off — mirrors
 * the event-bus pattern already proven in Banks Business Engine, kept
 * independent here since this plugin shares no code with it.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Domain;

use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Repositories\Resource_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Service {

	private Resource_Repository $resources;
	private Booking_Repository $bookings;
	private Customer_Repository $customers;
	private Availability_Calculator $availability;

	public function __construct() {
		$this->resources    = new Resource_Repository();
		$this->bookings     = new Booking_Repository();
		$this->customers    = new Customer_Repository();
		$this->availability = new Availability_Calculator( $this->resources );
	}

	/**
	 * @param array{
	 *   resource_id:int, start_datetime:string, end_datetime:string,
	 *   seats_booked?:int, name:string, email:string, phone:string,
	 *   wp_user_id?:int, notes?:string, source?:string, requires_payment?:bool
	 * } $args
	 * @return array{booking_id:int, cancel_token:string, status:string}|\WP_Error
	 */
	public function book( array $args ) {
		$resource = $this->resources->find( (int) $args['resource_id'] );
		if ( ! $resource || 'active' !== $resource['status'] ) {
			return new \WP_Error( 'sk_resource_unavailable', __( 'That resource is not available for booking.', 'slotkeeper' ) );
		}

		$seats = max( 1, (int) ( $args['seats_booked'] ?? 1 ) );
		$lock_key = 'sk_resource_' . (int) $resource['id'];

		if ( ! $this->acquire_lock( $lock_key ) ) {
			return new \WP_Error( 'sk_lock_timeout', __( 'This slot is being booked by someone else right now — please try again.', 'slotkeeper' ) );
		}

		try {
			$available = $this->revalidate( $resource, $args['start_datetime'], $args['end_datetime'], $seats );
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$customer_id = $this->customers->find_or_create(
				sanitize_text_field( $args['name'] ),
				sanitize_email( $args['email'] ?? '' ),
				$this->sanitize_phone( $args['phone'] ?? '' ),
				isset( $args['wp_user_id'] ) ? (int) $args['wp_user_id'] : null
			);

			$requires_payment = ! empty( $args['requires_payment'] );

			$booking_id = $this->bookings->create(
				array(
					'resource_id'    => $resource['id'],
					'customer_id'    => $customer_id,
					'booking_type'   => $resource['type'],
					'start_datetime' => $args['start_datetime'],
					'end_datetime'   => $args['end_datetime'],
					'seats_booked'   => $seats,
					'status'         => $requires_payment ? 'pending' : 'confirmed',
					'payment_status' => $requires_payment ? 'unpaid' : 'not_required',
					'source'         => $args['source'] ?? 'web',
					'notes'          => isset( $args['notes'] ) ? sanitize_textarea_field( $args['notes'] ) : null,
				)
			);

			$booking = $this->bookings->find( $booking_id );

			/**
			 * Fires after a booking row exists. Notification_Dispatcher and
			 * (later) Calendar sync listen here — this method never calls
			 * them directly, so adding a new listener never means editing
			 * this file.
			 */
			do_action( 'sk_booking_created', $booking_id, $booking );

			return array(
				'booking_id'   => $booking_id,
				'cancel_token' => $booking['cancel_token'],
				'status'       => $booking['status'],
			);
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/**
	 * @param int $booking_id
	 * @param int|null $wp_user_id Pass the current logged-in user, or null for guest.
	 * @param string|null $token The guest cancel token, checked when $wp_user_id can't own it.
	 */
	public function cancel( int $booking_id, ?int $wp_user_id, ?string $token ) {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ) );
		}

		$is_admin       = current_user_can( 'sk_manage_bookings' );
		$owns_by_token  = $token && hash_equals( $booking['cancel_token'], $token );
		$owns_by_user   = $wp_user_id && $this->booking_belongs_to_user( $booking, $wp_user_id );

		if ( ! $is_admin && ! $owns_by_token && ! $owns_by_user ) {
			return new \WP_Error( 'sk_forbidden', __( 'You are not allowed to cancel this booking.', 'slotkeeper' ) );
		}

		if ( 'cancelled' === $booking['status'] ) {
			return true; // Already cancelled — idempotent, not an error.
		}

		$this->bookings->update_status( $booking_id, 'cancelled' );
		do_action( 'sk_booking_cancelled', $booking_id, $booking );

		return true;
	}

	private function booking_belongs_to_user( array $booking, int $wp_user_id ): bool {
		$customer = $this->customers->find( (int) $booking['customer_id'] );
		return $customer && (int) ( $customer['wp_user_id'] ?? 0 ) === $wp_user_id;
	}

	private function revalidate( array $resource, string $start, string $end, int $seats ) {
		switch ( $resource['type'] ) {
			case 'rental_item':
				if ( ! $this->availability->is_range_available( (int) $resource['id'], $start, $end ) ) {
					return new \WP_Error( 'sk_unavailable', __( 'That date range is no longer available.', 'slotkeeper' ) );
				}
				break;

			case 'class_session':
				$remaining = $this->availability->class_remaining_capacity( (int) $resource['id'] );
				if ( null === $remaining || $remaining < $seats ) {
					return new \WP_Error( 'sk_full', __( 'That class no longer has enough open seats.', 'slotkeeper' ) );
				}
				break;

			default: // staff (appointments), table (reservations).
				$booked = $this->bookings->seats_booked_in_window( (int) $resource['id'], $start, $end );
				if ( ( $booked + $seats ) > (int) $resource['capacity'] ) {
					return new \WP_Error( 'sk_unavailable', __( 'That slot was just taken — please pick another.', 'slotkeeper' ) );
				}
		}

		return true;
	}

	private function sanitize_phone( string $phone ): string {
		return preg_replace( '/[^0-9+]/', '', $phone );
	}

	/**
	 * A short-lived MySQL named lock scoped to one resource, so two
	 * near-simultaneous requests for the same slot can't both pass the
	 * availability check before either one has inserted its row.
	 */
	private function acquire_lock( string $key ): bool {
		global $wpdb;
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, 5 ) );
		return '1' === (string) $got;
	}

	private function release_lock( string $key ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
	}
}
