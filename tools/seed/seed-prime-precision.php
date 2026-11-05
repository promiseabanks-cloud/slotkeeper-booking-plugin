<?php
/**
 * Seeds the Prime Precision salon catalog into SlotKeeper.
 *
 * Creates one provider ("the stylist"), that provider's shared weekly hours,
 * and the 76 hairstyle services as `staff` resources assigned to the provider
 * (each with its duration, USD price, and paid/free flag). It is idempotent:
 * every service row carries a `seed_source_id` in its meta, so a second run
 * updates the matching rows instead of creating duplicates, and the provider's
 * weekly rules are rebuilt from scratch each time.
 *
 * Run it on the target site with WP-CLI, from anywhere:
 *
 *     wp eval-file wp-content/plugins/slotkeeper/tools/seed/seed-prime-precision.php
 *
 * (The catalog lives in prime-precision-catalog.json next to this file.)
 *
 * ------------------------------------------------------------------------
 * KNOWN LIMITATION — overnight hours.
 * The salon's real hours are 12:00–03:00 the next morning, every day. The
 * availability engine models within-a-single-day windows only, so each day is
 * seeded as TWO windows — 12:00–23:59 and 00:00–03:00 — which reproduces the
 * open/closed hours faithfully EXCEPT that a single booking cannot be
 * generated across the midnight boundary. In practice that means a style can
 * start any time 12:00–23:59 or 00:00–03:00, but a slot cannot straddle
 * midnight, and a style longer than ~11h59 (only "Large Knotless Thigh
 * Length", 720 min) will not fit the noon–midnight window. Lifting this needs
 * a cross-midnight availability change (tracked separately).
 * ------------------------------------------------------------------------
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Tools;

use SlotKeeper\Database\Schema;
use SlotKeeper\Repositories\Provider_Repository;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Repositories\Availability_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress (use: wp eval-file ...).\n" );
	exit( 1 );
}

if ( ! class_exists( Provider_Repository::class ) ) {
	fwrite( STDERR, "SlotKeeper does not appear to be active on this site.\n" );
	exit( 1 );
}

/** Day name -> day_of_week (0 = Sunday, per PHP date('w')). */
const SK_SEED_DOW = array(
	'sun' => 0,
	'mon' => 1,
	'tue' => 2,
	'wed' => 3,
	'thu' => 4,
	'fri' => 5,
	'sat' => 6,
);

/**
 * Tiny logger so the run reads the same under WP-CLI or a plain include.
 */
$sk_seed_log = static function ( string $line ): void {
	if ( defined( 'WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::log( $line );
	} else {
		echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- CLI tool output.
	}
};

// --- Load the catalog ----------------------------------------------------
$catalog_path = __DIR__ . '/prime-precision-catalog.json';
if ( ! is_readable( $catalog_path ) ) {
	fwrite( STDERR, "Catalog not found at {$catalog_path}\n" );
	exit( 1 );
}
$catalog = json_decode( (string) file_get_contents( $catalog_path ), true );
if ( ! is_array( $catalog ) || empty( $catalog['services'] ) ) {
	fwrite( STDERR, "Catalog is empty or invalid JSON.\n" );
	exit( 1 );
}

global $wpdb;

$providers    = new Provider_Repository();
$resources    = new Resource_Repository();
$availability = new Availability_Repository();

// --- 1. Provider (find-or-create by name) --------------------------------
$provider_name = (string) ( $catalog['provider']['name'] ?? 'Prime Precision Stylist' );
$provider_id   = 0;
foreach ( $providers->all( 'active' ) as $existing ) {
	if ( $existing['name'] === $provider_name ) {
		$provider_id = (int) $existing['id'];
		break;
	}
}
if ( ! $provider_id ) {
	$provider_id = $providers->create( $provider_name );
	$sk_seed_log( "Created provider #{$provider_id}: {$provider_name}" );
} else {
	$sk_seed_log( "Reusing provider #{$provider_id}: {$provider_name}" );
}

// --- 2. Weekly hours (rebuilt each run) -----------------------------------
$rules_table = Schema::table( 'availability_rules' );
$wpdb->delete( $rules_table, array( 'provider_id' => $provider_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB -- seed tool, own table.

$rule_count = 0;
foreach ( (array) ( $catalog['weekly_hours'] ?? array() ) as $day => $hours ) {
	if ( ! isset( SK_SEED_DOW[ $day ] ) ) {
		continue;
	}
	$dow   = SK_SEED_DOW[ $day ];
	$start = (string) $hours['start'];
	$end   = (string) $hours['end'];

	if ( ! empty( $hours['overnight'] ) && $end <= $start ) {
		// Split the cross-midnight window into two within-day windows.
		$availability->add_provider_rule( $provider_id, $dow, $start, '23:59:00' );
		$availability->add_provider_rule( $provider_id, $dow, '00:00:00', $end . ':00' );
		$rule_count += 2;
	} else {
		$availability->add_provider_rule( $provider_id, $dow, $start . ':00', $end . ':00' );
		++$rule_count;
	}
}
$sk_seed_log( "Set {$rule_count} weekly availability rules (overnight hours split at midnight)." );

// --- 3. Services (find-or-create by seed_source_id) -----------------------
// Map existing seeded resources: seed_source_id -> resource id.
$existing_by_source = array();
foreach ( $resources->all( 'staff', 'active' ) as $row ) {
	$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
	if ( is_array( $meta ) && isset( $meta['seed_source_id'] ) ) {
		$existing_by_source[ (int) $meta['seed_source_id'] ] = (int) $row['id'];
	}
}

$created = 0;
$updated = 0;
foreach ( $catalog['services'] as $svc ) {
	$source_id = (int) $svc['source_id'];
	$fields    = array(
		'type'             => 'staff',
		'name'             => (string) $svc['name'],
		'provider_id'      => $provider_id,
		'capacity'         => 1,
		'duration_minutes' => (int) $svc['duration_minutes'],
		'price_minor'      => isset( $svc['price_minor'] ) ? ( $svc['price_minor'] === null ? null : (int) $svc['price_minor'] ) : null,
		'requires_payment' => ! empty( $svc['requires_payment'] ) ? 1 : 0,
		'status'           => 'active',
		'meta'             => array(
			'seed_source_id'       => $source_id,
			'source_woo_product_id' => $svc['source_woo_product_id'] ?? null,
		),
	);

	if ( isset( $existing_by_source[ $source_id ] ) ) {
		$resources->update( $existing_by_source[ $source_id ], $fields );
		++$updated;
	} else {
		$resources->create( $fields );
		++$created;
	}
}

$sk_seed_log( "Services: {$created} created, {$updated} updated (" . count( $catalog['services'] ) . ' total).' );
$sk_seed_log( 'Done.' );
