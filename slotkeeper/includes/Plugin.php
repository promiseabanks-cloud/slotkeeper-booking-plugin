<?php
/**
 * Single boot point. Every class referenced here was created and verified
 * to exist in isolation first — see AGENTS.md's proof requirements — before
 * being wired into a path that runs on every request.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper;

use SlotKeeper\Admin\Admin_Menu;
use SlotKeeper\Calendar\Calendar_Dispatcher;
use SlotKeeper\Database\Schema;
use SlotKeeper\Frontend\Manage_Booking_Shortcode;
use SlotKeeper\Frontend\Shortcode;
use SlotKeeper\Notifications\Admin_Notifier;
use SlotKeeper\Notifications\Notification_Dispatcher;
use SlotKeeper\Rest\Booking_Rest_Controller;
use SlotKeeper\Rest\Webhook_Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'slotkeeper', false, dirname( plugin_basename( SLOTKEEPER_PLUGIN_FILE ) ) . '/languages' );

		// Idempotent safety net: covers a version bump that changed the
		// schema without a deactivate/reactivate cycle (e.g. an auto-update).
		if ( Schema::needs_upgrade() ) {
			Schema::install();
		}

		if ( is_admin() ) {
			( new Admin_Menu() )->register();
		}

		( new Notification_Dispatcher() )->register();
		( new Admin_Notifier() )->register();
		( new Calendar_Dispatcher() )->register();
		( new Booking_Rest_Controller() )->register();
		( new Webhook_Rest_Controller() )->register();
		( new Shortcode() )->register();
		( new Manage_Booking_Shortcode() )->register();
	}
}
