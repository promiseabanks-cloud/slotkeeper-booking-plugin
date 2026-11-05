<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Payment_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'payments' );
	}

	public function find_by_reference( string $reference ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE gateway_reference = %s", $reference ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/** Sum of paid payments this calendar month (site timezone) — dashboard revenue tile. */
	public function sum_paid_this_month(): int {
		global $wpdb;
		$month_start = current_time( 'Y-m-01 00:00:00' );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_minor), 0) FROM {$this->table} WHERE status = 'paid' AND updated_at >= %s", // phpcs:ignore
				$month_start
			)
		);
	}

	/**
	 * Most recent payment attempt for a booking — used by the lookup
	 * endpoint's webhook-missed fallback (see Payment_Reconciler).
	 */
	public function find_latest_for_booking( int $booking_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE booking_id = %d ORDER BY id DESC LIMIT 1", $booking_id ), // phpcs:ignore
			ARRAY_A
		);
		return $row ?: null;
	}

	public function create( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			$this->table,
			array(
				'booking_id'        => $data['booking_id'],
				'gateway'           => $data['gateway'],
				'amount_minor'      => $data['amount_minor'],
				'currency'          => $data['currency'] ?? 'NGN',
				'status'            => $data['status'] ?? 'pending',
				'gateway_reference' => $data['gateway_reference'] ?? null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
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
}
