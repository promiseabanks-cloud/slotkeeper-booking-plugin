<?php
/**
 * Registers the wp-admin menu and routes each submenu to its page class.
 * Every submenu is capability-gated on `sk_manage_bookings`, not
 * `manage_options` — see SECURITY.md for why.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	private const CAP = 'sk_manage_bookings';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'SlotKeeper', 'slotkeeper' ),
			__( 'SlotKeeper', 'slotkeeper' ),
			self::CAP,
			'slotkeeper',
			array( new Dashboard_Page(), 'render' ),
			'dashicons-calendar-alt',
			26
		);

		// Dashboard is the top-level landing page. Deliberately NOT also
		// registering an explicit add_submenu_page() for the same
		// 'slotkeeper' slug here — add_menu_page() already registers that
		// hook internally, and doing it twice made WordPress invoke
		// Dashboard_Page::render() twice per page load (confirmed live:
		// two full copies of the page in the DOM). This exact bug is what
		// caused the Bookings page's mysterious double-form-submission
		// behavior observed earlier, before Bookings had its own
		// non-colliding slug — the double-booking lock genuinely proved
		// itself against that, it just wasn't a real double-click.
		add_submenu_page( 'slotkeeper', __( 'Bookings', 'slotkeeper' ), __( 'Bookings', 'slotkeeper' ), self::CAP, 'slotkeeper-bookings', array( new Bookings_Page(), 'render' ) );
		add_submenu_page( 'slotkeeper', __( 'Resources', 'slotkeeper' ), __( 'Resources', 'slotkeeper' ), self::CAP, 'slotkeeper-resources', array( new Resources_Page(), 'render' ) );
		add_submenu_page( 'slotkeeper', __( 'Availability', 'slotkeeper' ), __( 'Availability', 'slotkeeper' ), self::CAP, 'slotkeeper-availability', array( new Availability_Page(), 'render' ) );
		add_submenu_page( 'slotkeeper', __( 'Settings', 'slotkeeper' ), __( 'Settings', 'slotkeeper' ), self::CAP, 'slotkeeper-settings', array( new Settings_Page(), 'render' ) );
		add_submenu_page( 'slotkeeper', __( 'Integrations', 'slotkeeper' ), __( 'Integrations', 'slotkeeper' ), self::CAP, 'slotkeeper-integrations', array( new Integrations_Page(), 'render' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'slotkeeper' ) ) {
			return;
		}
		wp_enqueue_style( 'slotkeeper-admin', SLOTKEEPER_PLUGIN_URL . 'assets/css/admin.css', array(), SLOTKEEPER_VERSION );
	}
}
