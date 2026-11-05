<?php
/**
 * Table creation. `install()` is idempotent — safe to call on every
 * activation and every version bump, per WordPress `dbDelta()` convention.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'sk_' . $name;
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$resources = self::table( 'resources' );
		$rules     = self::table( 'availability_rules' );
		$exceptions = self::table( 'availability_exceptions' );
		$customers = self::table( 'customers' );
		$bookings  = self::table( 'bookings' );
		$payments  = self::table( 'payments' );
		$notif_log = self::table( 'notifications_log' );

		$sql = "
CREATE TABLE {$resources} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(20) NOT NULL,
  name VARCHAR(191) NOT NULL,
  description TEXT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  duration_minutes SMALLINT UNSIGNED NULL,
  price_minor BIGINT UNSIGNED NULL,
  requires_payment TINYINT(1) NOT NULL DEFAULT 0,
  buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  session_start DATETIME NULL,
  session_end DATETIME NULL,
  meta LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY type_status (type, status)
) {$charset_collate};

CREATE TABLE {$rules} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_day (resource_id, day_of_week)
) {$charset_collate};

CREATE TABLE {$exceptions} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NOT NULL,
  exception_date DATE NOT NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 1,
  start_time TIME NULL,
  end_time TIME NULL,
  reason VARCHAR(191) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_date (resource_id, exception_date)
) {$charset_collate};

CREATE TABLE {$customers} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(32) NULL,
  wp_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY phone (phone),
  KEY wp_user_id (wp_user_id)
) {$charset_collate};

CREATE TABLE {$bookings} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  booking_type VARCHAR(20) NOT NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NOT NULL,
  seats_booked SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  payment_status VARCHAR(20) NOT NULL DEFAULT 'not_required',
  source VARCHAR(10) NOT NULL DEFAULT 'web',
  notes TEXT NULL,
  cancel_token VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_window (resource_id, start_datetime, end_datetime),
  KEY customer_id (customer_id),
  KEY cancel_token (cancel_token)
) {$charset_collate};

CREATE TABLE {$payments} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  gateway VARCHAR(20) NOT NULL,
  amount_minor BIGINT UNSIGNED NOT NULL,
  total_minor BIGINT UNSIGNED NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'NGN',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  gateway_reference VARCHAR(191) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY gateway_reference (gateway_reference)
) {$charset_collate};

CREATE TABLE {$notif_log} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  channel VARCHAR(20) NOT NULL,
  template VARCHAR(60) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_channel_template (booking_id, channel, template)
) {$charset_collate};
";

		dbDelta( $sql );

		update_option( 'sk_db_version', SLOTKEEPER_DB_VERSION );
	}

	public static function needs_upgrade(): bool {
		return get_option( 'sk_db_version' ) !== SLOTKEEPER_DB_VERSION;
	}

	/**
	 * Called only from `uninstall.php`, and only when the founder/admin has
	 * explicitly opted into data deletion in settings. Never called from the
	 * normal deactivation path.
	 */
	public static function drop_all(): void {
		global $wpdb;
		foreach ( array( 'resources', 'availability_rules', 'availability_exceptions', 'customers', 'bookings', 'payments', 'notifications_log' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore -- table name is our own constant, not user input.
		}
		delete_option( 'sk_db_version' );
		delete_option( \SlotKeeper\Support\Settings::option_key() );
	}
}
