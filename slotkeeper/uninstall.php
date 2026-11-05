<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen (not on plain
 * deactivation). Only actually drops data if the admin explicitly opted in
 * via Settings > "Delete all data on uninstall" — defaults off.
 *
 * @package SlotKeeper
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'SLOTKEEPER_PLUGIN_DIR' ) ) {
	define( 'SLOTKEEPER_PLUGIN_DIR', __DIR__ . '/' );
}

require_once __DIR__ . '/includes/Autoloader.php';
\SlotKeeper\Autoloader::register();

$settings = \SlotKeeper\Support\Settings::all();

if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
	\SlotKeeper\Database\Schema::drop_all();
}
