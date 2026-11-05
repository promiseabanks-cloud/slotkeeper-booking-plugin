<?php
/**
 * The only way the public-facing widget talks to the backend — never direct
 * DB access from the browser. Every route here is intentionally reachable by
 * an unauthenticated guest (see SECURITY.md's auth matrix); abuse controls
 * (rate limit, honeypot) substitute for a login this actor will never have.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Rest;

use SlotKeeper\Domain\Availability_Calculator;
use SlotKeeper\Domain\Booking_Service;
use SlotKeeper\Payments\Gateway_Registry;
use SlotKeeper\Payments\Payment_Gateway;
use SlotKeeper\Payments\Payment_Reconciler;
use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Repositories\Payment_Repository;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Support\Deposit;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Controller {

	private const NAMESPACE = 'slotkeeper/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/resources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_resources' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'resource_id' => array( 'type' => 'integer', 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'resource_id' => array( 'type' => 'integer', 'required' => true ),
					'from'        => array( 'type' => 'string', 'required' => false ),
					'to'          => array( 'type' => 'string', 'required' => false ),
					'start'       => array( 'type' => 'string', 'required' => false ),
					'end'         => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'lookup_booking' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ---- GET /resources --------------------------------------------------

	public function get_resources( \WP_REST_Request $request ) {
		$repo           = new Resource_Repository();
		$resource_types = array_values( Settings::BOOKING_TYPE_TO_RESOURCE_TYPE );

		$single = $request->get_param( 'resource_id' );
		if ( $single ) {
			$resource = $repo->find( (int) $single );
			if ( ! $resource || 'active' !== $resource['status'] || ! in_array( $resource['type'], $resource_types, true ) ) {
				return new \WP_Error( 'sk_not_found', __( 'Resource not found.', 'slotkeeper' ), array( 'status' => 404 ) );
			}
			return rest_ensure_response( array( $this->format_resource( $resource ) ) );
		}

		$enabled_types  = Settings::get( 'enabled_booking_types', array() );
		$allowed_kinds  = array_map(
			static fn( $t ) => Settings::BOOKING_TYPE_TO_RESOURCE_TYPE[ $t ] ?? null,
			$enabled_types
		);
		$allowed_kinds  = array_filter( $allowed_kinds );

		$resources = $repo->all_for_resource_types( $allowed_kinds );

		return rest_ensure_response( array_map( array( $this, 'format_resource' ), $resources ) );
	}

	private function format_resource( array $r ): array {
		$requires_payment = ! empty( $r['requires_payment'] ) && $r['price_minor'];
		$price            = $r['price_minor'] ? (int) $r['price_minor'] : null;

		$out = array(
			'id'               => (int) $r['id'],
			'type'             => $r['type'],
			'name'             => $r['name'],
			'capacity'         => (int) $r['capacity'],
			'price'            => $price,
			'requires_payment' => $requires_payment,
		);

		$payment_note = '';
		if ( $requires_payment ) {
			$currency = Settings::get( 'currency', 'NGN' );
			$deposit  = Deposit::amount_to_charge( (int) $r['price_minor'], $currency, $r );
			$out['deposit'] = $deposit;
			if ( $deposit < (int) $r['price_minor'] ) {
				/* translators: %s: formatted deposit amount */
				$payment_note = ' · ' . sprintf( __( '%s deposit to confirm', 'slotkeeper' ), $this->money( $deposit ) );
			} else {
				$payment_note = ' · ' . __( 'payment required to confirm', 'slotkeeper' );
			}
		}

		switch ( $r['type'] ) {
			case 'staff':
				$out['meta']             = ( $r['duration_minutes'] ? $r['duration_minutes'] . ' min' : '' ) . ( $price ? ' · ' . $this->money( $price ) : '' ) . $payment_note;
				$out['duration_minutes'] = $r['duration_minutes'] ? (int) $r['duration_minutes'] : null;
				break;

			case 'table':
				$out['meta'] = sprintf( /* translators: %d: seats */ __( 'Seats up to %d', 'slotkeeper' ), (int) $r['capacity'] ) . $payment_note;
				break;

			case 'rental_item':
				$out['meta'] = ( $price ? $this->money( $price ) . ' / day' : '' ) . $payment_note;
				break;

			case 'class_session':
				$booked                = ( new Availability_Calculator() )->class_remaining_capacity( (int) $r['id'] );
				$out['remaining']      = $booked;
				$out['session_start']  = $r['session_start'];
				$out['session_end']    = $r['session_end'];
				$out['meta']           = $this->format_session_label( $r ) . ( null !== $booked ? sprintf( ' · %d of %d seats left', $booked, (int) $r['capacity'] ) : '' ) . $payment_note;
				break;
		}

		return $out;
	}

	private function format_session_label( array $r ): string {
		if ( empty( $r['session_start'] ) ) {
			return '';
		}
		$start = strtotime( $r['session_start'] );
		$end   = ! empty( $r['session_end'] ) ? strtotime( $r['session_end'] ) : null;
		$label = wp_date( 'D j M, g:ia', $start );
		if ( $end ) {
			$label .= ' – ' . wp_date( 'g:ia', $end );
		}
		return $label;
	}

	private function money( int $minor ): string {
		$currency = Settings::get( 'currency', 'NGN' );
		$symbol   = array( 'NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€' )[ $currency ] ?? ( $currency . ' ' );
		return $symbol . number_format( $minor / 100 );
	}

	// ---- GET /availability -----------------------------------------------

	public function get_availability( \WP_REST_Request $request ) {
		$resource_repo = new Resource_Repository();
		$resource      = $resource_repo->find( (int) $request->get_param( 'resource_id' ) );

		if ( ! $resource || 'active' !== $resource['status'] ) {
			return new \WP_Error( 'sk_not_found', __( 'Resource not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		$calc = new Availability_Calculator();

		if ( 'rental_item' === $resource['type'] ) {
			$start = sanitize_text_field( (string) $request->get_param( 'start' ) );
			$end   = sanitize_text_field( (string) $request->get_param( 'end' ) );
			if ( ! $start || ! $end ) {
				return new \WP_Error( 'sk_bad_request', __( 'start and end are required for a rental.', 'slotkeeper' ), array( 'status' => 400 ) );
			}
			$available = $calc->is_range_available( (int) $resource['id'], $start, $end );
			return rest_ensure_response( array( 'type' => 'range', 'available' => $available ) );
		}

		if ( 'class_session' === $resource['type'] ) {
			$remaining = $calc->class_remaining_capacity( (int) $resource['id'] );
			return rest_ensure_response( array( 'type' => 'session', 'remaining' => $remaining ) );
		}

		$from = sanitize_text_field( (string) $request->get_param( 'from' ) ) ?: gmdate( 'Y-m-d' );
		$to   = sanitize_text_field( (string) $request->get_param( 'to' ) ) ?: gmdate( 'Y-m-d', strtotime( '+14 days' ) );

		$slots = $calc->open_slots( (int) $resource['id'], $from, $to, 1 );

		// Group into days for the day-rail UI — the calculator returns a flat list.
		$days = array();
		foreach ( $slots as $slot ) {
			$date_key = substr( $slot['start'], 0, 10 );
			if ( ! isset( $days[ $date_key ] ) ) {
				$days[ $date_key ] = array(
					'date'  => $date_key,
					'label' => wp_date( 'D j M', strtotime( $slot['start'] ) ),
					'slots' => array(),
				);
			}
			$days[ $date_key ]['slots'][] = array(
				'start' => $slot['start'],
				'end'   => $slot['end'],
				'label' => wp_date( 'g:ia', strtotime( $slot['start'] ) ),
			);
		}

		return rest_ensure_response( array( 'type' => 'slots', 'days' => array_values( $days ) ) );
	}

	// ---- POST /bookings ----------------------------------------------------

	public function create_booking( \WP_REST_Request $request ) {
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		// Honeypot: a real visitor never sees or fills this field. Rejected
		// with the same shape as an ordinary validation error so a bot can't
		// distinguish detection from a normal mistake.
		if ( ! empty( $request->get_param( 'website' ) ) ) {
			return new \WP_Error( 'sk_invalid', __( 'Something went wrong. Please try again.', 'slotkeeper' ), array( 'status' => 422 ) );
		}

		$resource_id = (int) $request->get_param( 'resource_id' );
		$resource    = ( new Resource_Repository() )->find( $resource_id );

		if ( ! $resource || 'active' !== $resource['status'] ) {
			return new \WP_Error( 'sk_not_found', __( 'Resource not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		if ( ! $name || ! is_email( $email ) || ! preg_match( '/^\+?[0-9]{10,14}$/', $phone ) ) {
			return new \WP_Error( 'sk_invalid', __( 'Please provide a valid name, email, and phone number.', 'slotkeeper' ), array( 'status' => 422 ) );
		}

		// Server decides whether payment is required — never the client. A
		// resource only actually requires it if it's flagged AND has a price
		// AND a gateway is genuinely configured; if the admin flagged a
		// resource but never finished setting up Paystack, fail clearly here
		// rather than either silently letting it through free or failing
		// deep inside checkout after a booking already exists.
		$wants_payment = ! empty( $resource['requires_payment'] ) && $resource['price_minor'];
		$gateway       = $wants_payment ? Gateway_Registry::active() : null;

		if ( $wants_payment && ! $gateway ) {
			return new \WP_Error( 'sk_payment_unavailable', __( "This isn't available for online booking right now — please contact us directly.", 'slotkeeper' ), array( 'status' => 503 ) );
		}

		$args = array(
			'resource_id'      => $resource_id,
			'name'             => $name,
			'email'            => $email,
			'phone'            => $phone,
			'notes'            => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'source'           => 'web',
			'requires_payment' => (bool) $gateway,
		);

		if ( 'rental_item' === $resource['type'] ) {
			$args['start_datetime'] = sanitize_text_field( (string) $request->get_param( 'start' ) );
			$args['end_datetime']   = sanitize_text_field( (string) $request->get_param( 'end' ) );
			$args['seats_booked']   = 1;
		} elseif ( 'class_session' === $resource['type'] ) {
			$args['start_datetime'] = $resource['session_start'];
			$args['end_datetime']   = $resource['session_end'];
			$args['seats_booked']   = max( 1, (int) $request->get_param( 'seats' ) );
		} else {
			$args['start_datetime'] = sanitize_text_field( (string) $request->get_param( 'start' ) );
			$args['end_datetime']   = sanitize_text_field( (string) $request->get_param( 'end' ) );
			$args['seats_booked']   = 'table' === $resource['type'] ? max( 1, (int) $request->get_param( 'seats' ) ) : 1;
		}

		if ( empty( $args['start_datetime'] ) || empty( $args['end_datetime'] ) ) {
			return new \WP_Error( 'sk_invalid', __( 'Please pick a time before confirming.', 'slotkeeper' ), array( 'status' => 422 ) );
		}

		$service = new Booking_Service();
		$result  = $service->book( $args );

		if ( is_wp_error( $result ) ) {
			// Every current book() failure is a conflict (slot/seats/range no
			// longer available) or a lock timeout — 409 is the honest status
			// for "someone else got there first", which is what the widget
			// is built to explain to the customer.
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}

		$response = array(
			'booking_id'   => $result['booking_id'],
			'status'       => $result['status'],
			'cancel_token' => $result['cancel_token'],
		);

		if ( $gateway ) {
			$checkout = $this->start_checkout( $gateway, $resource, $result, $args, $request );
			if ( is_wp_error( $checkout ) ) {
				// Roll back silently — the customer never reached Paystack,
				// so Booking_Service::cancel()'s customer-facing "your
				// booking was cancelled" email would be actively confusing
				// here. This is an internal correction, not a real
				// cancellation event, so it bypasses that path on purpose.
				( new Booking_Repository() )->update_status( (int) $result['booking_id'], 'cancelled' );
				$checkout->add_data( array( 'status' => 502 ) );
				return $checkout;
			}
			$response['checkout_url'] = $checkout['checkout_url'];
		}

		return rest_ensure_response( $response );
	}

	/**
	 * @param array{booking_id:int,cancel_token:string,status:string} $booked
	 * @param array<string,mixed> $args The same args passed to Booking_Service::book().
	 * @return array{checkout_url:string,reference:string}|\WP_Error
	 */
	private function start_checkout( Payment_Gateway $gateway, array $resource, array $booked, array $args, \WP_REST_Request $request ) {
		$booking = ( new Booking_Repository() )->find( (int) $booked['booking_id'] );
		if ( ! $booking ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ) );
		}

		$full_minor = (int) $resource['price_minor'];

		if ( 'rental_item' === $resource['type'] ) {
			$days        = max( 1, (int) ceil( ( strtotime( $args['end_datetime'] ) - strtotime( $args['start_datetime'] ) ) / DAY_IN_SECONDS ) );
			$full_minor *= $days;
		}

		$currency = Settings::get( 'currency', 'NGN' );

		// What we collect online now — the whole price, or a deposit — per the
		// site's deposit policy (or a per-service override). The balance, if
		// any, is settled in person. The full price is recorded as total_minor
		// so the outstanding balance stays derivable from the payment row.
		$charge_minor = Deposit::amount_to_charge( $full_minor, $currency, $resource );

		$return_url = esc_url_raw( (string) $request->get_param( 'page_url' ) ) ?: home_url( '/' );

		$checkout = $gateway->create_checkout( $booking, $charge_minor, $currency, $return_url );
		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		( new Payment_Repository() )->create(
			array(
				'booking_id'        => $booking['id'],
				'gateway'           => $gateway->key(),
				'amount_minor'      => $charge_minor,
				'total_minor'       => $full_minor,
				'currency'          => $currency,
				'status'            => 'pending',
				'gateway_reference' => $checkout['reference'],
			)
		);

		return $checkout;
	}

	// ---- GET /bookings/lookup ---------------------------------------------

	public function lookup_booking( \WP_REST_Request $request ) {
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! $token ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		$booking = ( new Booking_Repository() )->find_by_cancel_token( $token );
		if ( ! $booking ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		// Self-healing fallback for a webhook that never arrives — every
		// lookup on a still-pending, still-unpaid booking actively asks the
		// gateway whether it actually went through, rather than trusting
		// the webhook blindly. A no-op when there's nothing to reconcile.
		$reconciled = Payment_Reconciler::reconcile_if_needed( $booking );
		if ( $reconciled ) {
			$booking = $reconciled;
		}

		return rest_ensure_response( $this->format_booking_for_manage( $booking ) );
	}

	// ---- POST /bookings/cancel ---------------------------------------------

	public function cancel_booking( \WP_REST_Request $request ) {
		$rate_limit = $this->check_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( ! $token ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		$repo    = new Booking_Repository();
		$booking = $repo->find_by_cancel_token( $token );
		if ( ! $booking ) {
			return new \WP_Error( 'sk_not_found', __( 'Booking not found.', 'slotkeeper' ), array( 'status' => 404 ) );
		}

		// current_user_can() inside Booking_Service::cancel() is always false
		// here (guest request, no session) — that's correct: only the token
		// match authorizes this actor, same rule as SECURITY.md's matrix.
		$result = ( new Booking_Service() )->cancel( (int) $booking['id'], null, $token );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 403 ) );
			return $result;
		}

		$fresh = $repo->find( (int) $booking['id'] );
		return rest_ensure_response( $this->format_booking_for_manage( $fresh ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function format_booking_for_manage( array $booking ): array {
		$resource = ( new Resource_Repository() )->find( (int) $booking['resource_id'] );
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );

		$when = null;
		if ( $resource ) {
			if ( 'rental_item' === $resource['type'] ) {
				$when = wp_date( 'j M', strtotime( $booking['start_datetime'] ) ) . ' – ' . wp_date( 'j M Y', strtotime( $booking['end_datetime'] ) );
			} else {
				$when = wp_date( 'D, j M Y g:ia', strtotime( $booking['start_datetime'] ) );
			}
		}

		$is_past       = strtotime( $booking['end_datetime'] ) < time();
		$is_cancellable = ! $is_past && in_array( $booking['status'], array( 'pending', 'confirmed' ), true );

		return array(
			'booking_id'    => (int) $booking['id'],
			'status'        => $booking['status'],
			'resource_name' => $resource['name'] ?? '',
			'customer_name' => $customer['name'] ?? '',
			'when'          => $when,
			'seats'         => (int) $booking['seats_booked'],
			'cancellable'   => $is_cancellable,
		);
	}

	/**
	 * A short-window per-IP counter using WordPress transients — no external
	 * infrastructure required. Best-effort: honors common proxy/CDN headers
	 * when present, falls back to REMOTE_ADDR.
	 */
	private function check_rate_limit() {
		$ip  = $this->client_ip();
		$key = 'sk_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= 8 ) {
			return new \WP_Error( 'sk_rate_limited', __( 'Too many attempts — please wait a moment and try again.', 'slotkeeper' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function client_ip(): string {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore -- read-only, used only as a rate-limit bucket key, not trusted for authorization.
				$value = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				return trim( explode( ',', $value )[0] );
			}
		}
		return 'unknown';
	}
}
