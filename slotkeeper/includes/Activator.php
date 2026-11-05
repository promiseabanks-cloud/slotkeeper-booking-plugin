<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper;

use SlotKeeper\Database\Schema;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate(): void {
		Schema::install();
		self::register_capability();

		// Seed settings only if none exist yet — never overwrite an existing
		// configuration on reactivation.
		if ( false === get_option( Settings::option_key(), false ) ) {
			Settings::update( Settings::defaults() );
		}
	}

	private static function register_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'sk_manage_bookings' ) ) {
			$role->add_cap( 'sk_manage_bookings' );
		}
	}
}
