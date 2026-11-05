<?php
/**
 * Plugin Name:       SlotKeeper
 * Description:       A configurable booking engine — appointments, reservations, rentals, or classes — for any business running WordPress.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Banks Solution
 * Text Domain:       slotkeeper
 * Domain Path:       /languages
 *
 * @package SlotKeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'SLOTKEEPER_VERSION', '0.4.0' );
define( 'SLOTKEEPER_DB_VERSION', '4' ); // v4: sk_payments.total_minor added — records the full price alongside amount_minor so a deposit's outstanding balance is derivable. v3: sk_notifications_log.error_message widened to TEXT — a Meta API error (371 chars) silently failed to insert against the old VARCHAR(255).
define( 'SLOTKEEPER_PLUGIN_FILE', __FILE__ );
define( 'SLOTKEEPER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLOTKEEPER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SLOTKEEPER_PLUGIN_DIR . 'includes/Autoloader.php';
\SlotKeeper\Autoloader::register();

register_activation_hook( __FILE__, array( '\SlotKeeper\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SlotKeeper\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once every dependency is loadable. Kept on `plugins_loaded`
 * (not directly at file scope) so a fatal in any class never breaks the whole
 * site load path before WordPress itself has finished bootstrapping.
 */
function slotkeeper_boot(): void {
	if ( ! class_exists( '\SlotKeeper\Plugin' ) ) {
		return;
	}
	\SlotKeeper\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'slotkeeper_boot' );
