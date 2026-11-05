<?php
/**
 * The one place SlotKeeper's admin tab bar is defined — every admin page
 * renders the same nav, so the plugin reads as one connected app instead of
 * a scatter of unrelated screens reachable only through the (increasingly
 * crowded, now that Business Engine shares the sidebar) WP admin menu.
 *
 * Reuses WordPress core's own `nav-tab-wrapper` markup/classes rather than
 * inventing custom tab CSS — it already behaves correctly and matches the
 * rest of wp-admin; only the active-state color is SlotKeeper's own.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Nav {

	private const TABS = array(
		'slotkeeper'               => array( 'label' => 'Dashboard', 'icon' => 'dashicons-chart-bar' ),
		'slotkeeper-bookings'      => array( 'label' => 'Bookings', 'icon' => 'dashicons-calendar-alt' ),
		'slotkeeper-providers'     => array( 'label' => 'Providers', 'icon' => 'dashicons-businessperson' ),
		'slotkeeper-resources'     => array( 'label' => 'Resources', 'icon' => 'dashicons-groups' ),
		'slotkeeper-availability'  => array( 'label' => 'Availability', 'icon' => 'dashicons-clock' ),
		'slotkeeper-settings'      => array( 'label' => 'Settings', 'icon' => 'dashicons-admin-generic' ),
		'slotkeeper-integrations'  => array( 'label' => 'Integrations', 'icon' => 'dashicons-admin-plugins' ),
	);

	public static function render( string $active_slug ): void {
		echo '<h1 class="sk-app-title"><span class="dashicons dashicons-calendar-alt"></span> SlotKeeper</h1>';
		echo '<nav class="nav-tab-wrapper sk-tabs">';
		foreach ( self::TABS as $slug => $tab ) {
			$classes = 'nav-tab' . ( $slug === $active_slug ? ' nav-tab-active' : '' );
			printf(
				'<a class="%1$s" href="%2$s"><span class="dashicons %3$s"></span> %4$s</a>',
				esc_attr( $classes ),
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_attr( $tab['icon'] ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
	}
}
