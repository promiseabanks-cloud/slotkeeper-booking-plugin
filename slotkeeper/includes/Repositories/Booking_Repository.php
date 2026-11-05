<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'bookings' );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public function find_by_cancel_token( string $token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE cancel_token = %s", $token ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function for_customer( int $customer_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE customer_id = %d ORDER BY start_datetime DESC", $customer_id ), // phpcs:ignore
			ARRAY_A
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function upcoming( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status IN ('pending','confirmed') ORDER BY start_datetime ASC LIMIT %d OFFSET %d", // phpcs:ignore
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/** Active (pending/confirmed) bookings whose start falls within the next N days, counting from now. */
	public function count_upcoming_within_days( int $days ): int {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$then = gmdate( 'Y-m-d H:i:s', strtotime( $now . " +{$days} days" ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status IN ('pending','confirmed') AND start_datetime BETWEEN %s AND %s", // phpcs:ignore
				$now,
				$then
			)
		);
	}

	/** Active bookings whose start falls on today's calendar date (site timezone). */
	public function count_today(): int {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status IN ('pending','confirmed') AND DATE(start_datetime) = %s", // phpcs:ignore
				$today
			)
		);
	}

	/**
	 * Most recently created or changed bookings, for the dashboard activity
	 * feed — not filtered by status, so cancellations show up too.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( int $limit = 8 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} ORDER BY updated_at DESC LIMIT %d", $limit ), // phpcs:ignore
			ARRAY_A
		);
	}

	/**
	 * Total seats already booked for a resource inside a window, counting
	 * only active (pending/confirmed) bookings. Used both for overlap
	 * checks (capacity 1 resources) and capacity checks (classes/tables).
	 */
	public function seats_booked_in_window( int $resource_id, string $start, string $end, ?int $exclude_booking_id = null ): int {
		global $wpdb;

		$sql = "SELECT COALESCE(SUM(seats_booked), 0) FROM {$this->table}
			WHERE resource_id = %d
			AND status IN ('pending','confirmed')
			AND start_datetime < %s
			AND end_datetime > %s";
		$params = array( $resource_id, $end, $start );

		if ( $exclude_booking_id ) {
			$sql     .= ' AND id != %d';
			$params[] = $exclude_booking_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore
	}

	/**
	 * Like seats_booked_in_window, but across several resources at once — the
	 * occupancy of a whole provider's calendar (all the services one stylist
	 * offers), so booking any one service sees bookings of every other. Pass a
	 * single id for the ordinary per-resource case.
	 *
	 * @param array<int,int> $resource_ids
	 */
	public function seats_booked_in_window_multi( array $resource_ids, string $start, string $end, ?int $exclude_booking_id = null ): int {
		global $wpdb;

		$resource_ids = array_values( array_unique( array_map( 'intval', $resource_ids ) ) );
		if ( empty( $resource_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $resource_ids ), '%d' ) );

		$sql = "SELECT COALESCE(SUM(seats_booked), 0) FROM {$this->table}
			WHERE resource_id IN ({$placeholders})
			AND status IN ('pending','confirmed')
			AND start_datetime < %s
			AND end_datetime > %s";
		$params = array_merge( $resource_ids, array( $end, $start ) );

		if ( $exclude_booking_id ) {
			$sql     .= ' AND id != %d';
			$params[] = $exclude_booking_id;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore
	}

	public function create( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			$this->table,
			array(
				'resource_id'    => $data['resource_id'],
				'customer_id'    => $data['customer_id'],
				'booking_type'   => $data['booking_type'],
				'start_datetime' => $data['start_datetime'],
				'end_datetime'   => $data['end_datetime'],
				'seats_booked'   => $data['seats_booked'] ?? 1,
				'status'         => $data['status'] ?? 'pending',
				'payment_status' => $data['payment_status'] ?? 'not_required',
				'source'         => $data['source'] ?? 'web',
				'notes'          => $data['notes'] ?? null,
				'cancel_token'   => $data['cancel_token'] ?? wp_generate_password( 32, false ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	public function set_calendar_event_id( int $id, string $event_id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table,
			array( 'calendar_event_id' => $event_id, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
	}

	public function clear_calendar_event_id( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table,
			array( 'calendar_event_id' => null, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
	}

	public function update_payment_status( int $id, string $payment_status ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			$this->table,
			array(
				'payment_status' => $payment_status,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}
}
