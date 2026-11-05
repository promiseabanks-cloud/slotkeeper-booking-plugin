<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customer_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'customers' );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public function find_by_email( string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s ORDER BY id DESC LIMIT 1", $email ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * Find-or-create by email, so a returning customer doesn't accumulate a
	 * duplicate row every time they book.
	 */
	public function find_or_create( string $name, string $email, string $phone, ?int $wp_user_id = null ): int {
		$existing = $email ? $this->find_by_email( $email ) : null;

		if ( $existing ) {
			global $wpdb;
			$wpdb->update(
				$this->table,
				array(
					'name'       => $name,
					'phone'      => $phone,
					'wp_user_id' => $wp_user_id,
				),
				array( 'id' => $existing['id'] )
			);
			return (int) $existing['id'];
		}

		global $wpdb;
		$wpdb->insert(
			$this->table,
			array(
				'name'       => $name,
				'email'      => $email ?: null,
				'phone'      => $phone ?: null,
				'wp_user_id' => $wp_user_id,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}
