<?php
/**
 * One place that answers "is this resource free at this time" for all four
 * booking types, so appointments/reservations/rentals/classes each call in
 * with different parameters instead of four separate overlap implementations.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Domain;

use SlotKeeper\Repositories\Availability_Repository;
use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Resource_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Availability_Calculator {

	private Resource_Repository $resources;
	private Availability_Repository $availability;
	private Booking_Repository $bookings;

	public function __construct(
		?Resource_Repository $resources = null,
		?Availability_Repository $availability = null,
		?Booking_Repository $bookings = null
	) {
		$this->resources    = $resources ?? new Resource_Repository();
		$this->availability = $availability ?? new Availability_Repository();
		$this->bookings     = $bookings ?? new Booking_Repository();
	}

	/**
	 * Appointments and reservations: fixed-length slots, stepped across each
	 * open window in the date range.
	 *
	 * @return array<int, array{start:string,end:string}>
	 */
	public function open_slots( int $resource_id, string $from_date, string $to_date, int $seats_needed = 1 ): array {
		$resource = $this->resources->find( $resource_id );
		if ( ! $resource || 'active' !== $resource['status'] ) {
			return array();
		}

		$duration = (int) ( $resource['duration_minutes'] ?? 30 );
		$buffer   = (int) ( $resource['buffer_minutes'] ?? 0 );
		$capacity = (int) $resource['capacity'];
		$tz       = wp_timezone();

		$rules      = $this->resolve_rules( $resource );
		$exceptions = $this->index_exceptions( $this->resolve_exceptions( $resource, $from_date, $to_date ) );
		$occupancy  = $this->occupancy_ids( $resource );

		$slots = array();
		$cursor = new \DateTimeImmutable( $from_date, $tz );
		$end_date = new \DateTimeImmutable( $to_date, $tz );

		while ( $cursor <= $end_date ) {
			$date_key = $cursor->format( 'Y-m-d' );
			$windows  = $this->windows_for_date( $cursor, $rules, $exceptions[ $date_key ] ?? null );

			foreach ( $windows as $window ) {
				$slots = array_merge(
					$slots,
					$this->step_window( $cursor, $window, $duration, $buffer, $occupancy, $capacity, $seats_needed )
				);
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return $slots;
	}

	/**
	 * Rentals: no discrete slots, just "is this whole range free". A rental
	 * resource with zero weekly rules is treated as available around the
	 * clock, restricted only by exceptions (blackout dates) and existing
	 * bookings — most rental items don't have "opening hours".
	 */
	public function is_range_available( int $resource_id, string $start_datetime, string $end_datetime ): bool {
		$resource = $this->resources->find( $resource_id );
		if ( ! $resource || 'active' !== $resource['status'] ) {
			return false;
		}

		$rules = $this->resolve_rules( $resource );
		if ( ! empty( $rules ) ) {
			// Resource has explicit hours — every day the range touches must
			// fall inside an open window. Rare for rentals, but respected if set.
			$tz     = wp_timezone();
			$cursor = new \DateTimeImmutable( $start_datetime, $tz );
			$end    = new \DateTimeImmutable( $end_datetime, $tz );
			$exceptions = $this->index_exceptions(
				$this->resolve_exceptions( $resource, $cursor->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) )
			);

			$day = $cursor;
			while ( $day->format( 'Y-m-d' ) <= $end->format( 'Y-m-d' ) ) {
				$windows = $this->windows_for_date( $day, $rules, $exceptions[ $day->format( 'Y-m-d' ) ] ?? null );
				if ( empty( $windows ) ) {
					return false;
				}
				$day = $day->modify( '+1 day' );
			}
		}

		$booked = $this->bookings->seats_booked_in_window_multi( $this->occupancy_ids( $resource ), $start_datetime, $end_datetime );
		return $booked < max( 1, (int) $resource['capacity'] );
	}

	/**
	 * Classes: the resource IS the fixed occurrence (session_start/session_end
	 * on the resource row). Remaining capacity, not a slot list.
	 */
	public function class_remaining_capacity( int $resource_id ): ?int {
		$resource = $this->resources->find( $resource_id );
		if ( ! $resource || 'class_session' !== $resource['type'] || empty( $resource['session_start'] ) ) {
			return null;
		}

		$booked = $this->bookings->seats_booked_in_window(
			$resource_id,
			$resource['session_start'],
			$resource['session_end']
		);

		return max( 0, (int) $resource['capacity'] - $booked );
	}

	// ---- provider / resource resolution --------------------------------

	/**
	 * Weekly rules that govern a resource: the provider's shared schedule when
	 * the resource belongs to one, otherwise the resource's own rules.
	 *
	 * @param array<string,mixed> $resource
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_rules( array $resource ): array {
		$provider_id = (int) ( $resource['provider_id'] ?? 0 );
		return $provider_id > 0
			? $this->availability->rules_for_provider( $provider_id )
			: $this->availability->rules_for( (int) $resource['id'] );
	}

	/**
	 * @param array<string,mixed> $resource
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_exceptions( array $resource, string $from_date, string $to_date ): array {
		$provider_id = (int) ( $resource['provider_id'] ?? 0 );
		return $provider_id > 0
			? $this->availability->exceptions_for_provider( $provider_id, $from_date, $to_date )
			: $this->availability->exceptions_for( (int) $resource['id'], $from_date, $to_date );
	}

	/**
	 * The resource ids whose bookings occupy the same calendar as this one —
	 * every active service a provider offers (so one stylist can't be in two
	 * services at once), or just this resource when it has no provider.
	 *
	 * @param array<string,mixed> $resource
	 * @return array<int,int>
	 */
	private function occupancy_ids( array $resource ): array {
		$provider_id = (int) ( $resource['provider_id'] ?? 0 );
		if ( $provider_id <= 0 ) {
			return array( (int) $resource['id'] );
		}

		$ids = $this->resources->active_ids_for_provider( $provider_id );
		if ( ! in_array( (int) $resource['id'], $ids, true ) ) {
			$ids[] = (int) $resource['id']; // Always count the resource being booked, even mid-status-change.
		}
		return $ids;
	}

	// ---- internals -----------------------------------------------------

	private function index_exceptions( array $exceptions ): array {
		$indexed = array();
		foreach ( $exceptions as $exception ) {
			$indexed[ $exception['exception_date'] ] = $exception;
		}
		return $indexed;
	}

	/**
	 * @return array<int, array{start:string,end:string}> HH:MM:SS pairs open on this date.
	 */
	private function windows_for_date( \DateTimeImmutable $date, array $rules, ?array $exception ): array {
		if ( $exception && (int) $exception['is_closed'] === 1 ) {
			return array();
		}

		if ( $exception && $exception['start_time'] && $exception['end_time'] ) {
			return array( array( 'start' => $exception['start_time'], 'end' => $exception['end_time'] ) );
		}

		$dow = (int) $date->format( 'w' );
		$windows = array();
		foreach ( $rules as $rule ) {
			if ( (int) $rule['day_of_week'] === $dow ) {
				$windows[] = array( 'start' => $rule['start_time'], 'end' => $rule['end_time'] );
			}
		}
		return $windows;
	}

	/**
	 * @param array<int,int> $occupancy_ids Resource ids whose bookings share this calendar.
	 * @return array<int, array{start:string,end:string}>
	 */
	private function step_window( \DateTimeImmutable $date, array $window, int $duration, int $buffer, array $occupancy_ids, int $capacity, int $seats_needed ): array {
		$tz = $date->getTimezone();
		$day = $date->format( 'Y-m-d' );

		$slot_start = new \DateTimeImmutable( "{$day} {$window['start']}", $tz );
		$window_end = new \DateTimeImmutable( "{$day} {$window['end']}", $tz );
		$now        = new \DateTimeImmutable( 'now', $tz );

		$slots = array();
		$step  = $duration + $buffer;

		while ( true ) {
			$slot_end = $slot_start->modify( "+{$duration} minutes" );
			if ( $slot_end > $window_end ) {
				break;
			}

			if ( $slot_start > $now ) {
				$booked = $this->bookings->seats_booked_in_window_multi(
					$occupancy_ids,
					$slot_start->format( 'Y-m-d H:i:s' ),
					$slot_end->format( 'Y-m-d H:i:s' )
				);

				if ( ( $booked + $seats_needed ) <= $capacity ) {
					$slots[] = array(
						'start' => $slot_start->format( 'Y-m-d H:i:s' ),
						'end'   => $slot_end->format( 'Y-m-d H:i:s' ),
					);
				}
			}

			$slot_start = $slot_start->modify( "+{$step} minutes" );
		}

		return $slots;
	}
}
