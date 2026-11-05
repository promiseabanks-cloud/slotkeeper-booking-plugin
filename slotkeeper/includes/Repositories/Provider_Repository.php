<?php
/**
 * A provider is whoever actually performs the work — a stylist, a therapist,
 * a trainer. Services (resources) point at a provider and share that one
 * schedule, so a salon with one stylist and 76 styles keeps a single set of
 * opening hours instead of 76 copies, and the stylist can't be double-booked
 * across two different services at the same time. Adding a second stylist
 * later is just another provider row — nothing about the model changes.
 *
 * A resource with no provider keeps its own schedule and its own occupancy,
 * exactly as before providers existed.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'providers' );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore -- table name is our own constant.
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $status = 'active' ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE status = %s ORDER BY name ASC", $status ), // phpcs:ignore
			ARRAY_A
		);
	}

	public function create( string $name ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$this->table,
			array(
				'name'       => $name,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( $this->table, $data, array( 'id' => $id ) );
	}

	public function deactivate( int $id ): bool {
		return $this->update( $id, array( 'status' => 'inactive' ) );
	}
}
