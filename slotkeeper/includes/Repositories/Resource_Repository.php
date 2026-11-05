<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Resource_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'resources' );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), // phpcs:ignore -- table name is our own constant.
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Active resource ids that share one provider's calendar — the set whose
	 * bookings all consume the same person's time, so an overlap check for one
	 * service has to see bookings of every other service that provider offers.
	 *
	 * @return array<int,int>
	 */
	public function active_ids_for_provider( int $provider_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$this->table} WHERE provider_id = %d AND status = 'active'", $provider_id ) // phpcs:ignore -- table name is our own constant.
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( ?string $type = null, string $status = 'active' ): array {
		global $wpdb;

		if ( $type ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE type = %s AND status = %s ORDER BY name ASC", // phpcs:ignore
				$type,
				$status
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE status = %s ORDER BY name ASC", // phpcs:ignore
				$status
			);
		}

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Resources whose `type` is in the given list — used by the public REST
	 * layer, which only knows the site's *enabled booking types* (settings
	 * concept), not the `sk_resources.type` values directly.
	 *
	 * @param array<int,string> $resource_types e.g. ['staff','table']
	 * @return array<int, array<string, mixed>>
	 */
	public function all_for_resource_types( array $resource_types, string $status = 'active' ): array {
		global $wpdb;

		if ( empty( $resource_types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $resource_types ), '%s' ) );
		$params       = array_merge( $resource_types, array( $status ) );

		$sql = "SELECT * FROM {$this->table} WHERE type IN ({$placeholders}) AND status = %s ORDER BY name ASC"; // phpcs:ignore

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore
	}

	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert(
			$this->table,
			array(
				'type'              => $data['type'],
				'name'              => $data['name'],
				'category'          => $data['category'] ?? null,
				'description'       => $data['description'] ?? null,
				'provider_id'       => ! empty( $data['provider_id'] ) ? (int) $data['provider_id'] : null,
				'capacity'          => $data['capacity'] ?? 1,
				'duration_minutes'  => $data['duration_minutes'] ?? null,
				'price_minor'       => $data['price_minor'] ?? null,
				'requires_payment'  => ! empty( $data['requires_payment'] ) ? 1 : 0,
				'buffer_minutes'    => $data['buffer_minutes'] ?? 0,
				'status'            => $data['status'] ?? 'active',
				'session_start'     => $data['session_start'] ?? null, // class_session type only.
				'session_end'       => $data['session_end'] ?? null,   // class_session type only.
				'meta'              => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
				'created_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = wp_json_encode( $data['meta'] );
		}

		$result = $wpdb->update( $this->table, $data, array( 'id' => $id ) );
		return false !== $result;
	}

	public function has_future_bookings( int $resource_id ): bool {
		global $wpdb;
		$bookings_table = Schema::table( 'bookings' );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings_table} WHERE resource_id = %d AND status IN ('pending','confirmed') AND end_datetime >= %s", // phpcs:ignore
				$resource_id,
				current_time( 'mysql' )
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Soft-delete: resources with future bookings can't be hard-removed, so
	 * marking inactive is the only delete path exposed anywhere in the admin.
	 */
	public function deactivate( int $id ): bool {
		return $this->update( $id, array( 'status' => 'inactive' ) );
	}
}
