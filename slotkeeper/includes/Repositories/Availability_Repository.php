<?php
/**
 * Owns both recurring weekly rules and date-specific exceptions — kept in
 * one repository since they're always read together when computing slots.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Availability_Repository {

	private string $rules_table;
	private string $exceptions_table;

	public function __construct() {
		$this->rules_table      = Schema::table( 'availability_rules' );
		$this->exceptions_table = Schema::table( 'availability_exceptions' );
	}

	/**
	 * @return array<int, array<string, mixed>> rules for one resource, keyed by nothing in particular.
	 */
	public function rules_for( int $resource_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->rules_table} WHERE resource_id = %d ORDER BY day_of_week, start_time", $resource_id ), // phpcs:ignore
			ARRAY_A
		);
	}

	public function add_rule( int $resource_id, int $day_of_week, string $start_time, string $end_time ): int {
		global $wpdb;
		$wpdb->insert(
			$this->rules_table,
			array(
				'resource_id' => $resource_id,
				'day_of_week' => $day_of_week,
				'start_time'  => $start_time,
				'end_time'    => $end_time,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function delete_rule( int $rule_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->rules_table, array( 'id' => $rule_id ), array( '%d' ) );
	}

	public function delete_rules_for( int $resource_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->rules_table, array( 'resource_id' => $resource_id ), array( '%d' ) );
	}

	/**
	 * Exceptions for a resource within a date range (inclusive), used when
	 * computing slots for a window.
	 */
	public function exceptions_for( int $resource_id, string $from_date, string $to_date ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->exceptions_table} WHERE resource_id = %d AND exception_date BETWEEN %s AND %s", // phpcs:ignore
				$resource_id,
				$from_date,
				$to_date
			),
			ARRAY_A
		);
	}

	public function add_exception( int $resource_id, string $date, bool $is_closed, ?string $start_time, ?string $end_time, ?string $reason ): int {
		global $wpdb;
		$wpdb->insert(
			$this->exceptions_table,
			array(
				'resource_id'    => $resource_id,
				'exception_date' => $date,
				'is_closed'      => $is_closed ? 1 : 0,
				'start_time'     => $start_time,
				'end_time'       => $end_time,
				'reason'         => $reason,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	public function delete_exception( int $exception_id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( $this->exceptions_table, array( 'id' => $exception_id ), array( '%d' ) );
	}
}
