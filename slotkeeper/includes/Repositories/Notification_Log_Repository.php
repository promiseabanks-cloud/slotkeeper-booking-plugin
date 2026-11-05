<?php
/**
 * The idempotency guard for outbound notifications: nothing sends a message
 * without checking `already_sent()` first.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Repositories;

use SlotKeeper\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notification_Log_Repository {

	private string $table;

	public function __construct() {
		$this->table = Schema::table( 'notifications_log' );
	}

	public function already_sent( int $booking_id, string $channel, string $template ): bool {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE booking_id = %d AND channel = %s AND template = %s AND status = 'sent'", // phpcs:ignore
				$booking_id,
				$channel,
				$template
			)
		);
		return (int) $count > 0;
	}

	public function record( int $booking_id, string $channel, string $template, string $status, ?string $error_message = null ): int {
		global $wpdb;
		$now = current_time( 'mysql' );

		// error_message is TEXT (was VARCHAR(255) pre-v3 — a real Meta API
		// error ran to 371 chars and made $wpdb->insert() fail outright, with
		// nothing thrown and no row written; the whole notification vanished
		// without a trace). Still capped defensively: some future error
		// string could be pathologically large, and a hard cap plus an
		// error_log() fallback beats a second silent loss either way.
		if ( null !== $error_message && strlen( $error_message ) > 2000 ) {
			$error_message = substr( $error_message, 0, 2000 ) . '…';
		}

		$inserted = $wpdb->insert(
			$this->table,
			array(
				'booking_id'    => $booking_id,
				'channel'       => $channel,
				'template'      => $template,
				'status'        => $status,
				'error_message' => $error_message,
				'sent_at'       => 'sent' === $status ? $now : null,
				'created_at'    => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// Never let a logging failure be silent twice — this is the
			// audit trail for outbound notifications, so if it can't write
			// itself it at least needs to shout into the PHP error log.
			error_log( sprintf( 'SlotKeeper: failed to write notification log row (booking #%d, %s/%s): %s', $booking_id, $channel, $template, $wpdb->last_error ) ); // phpcs:ignore
			return 0;
		}

		return (int) $wpdb->insert_id;
	}
}
