<?php
/**
 * Minimal PSR-4-style autoloader. No Composer dependency — many client hosts
 * this plugin ends up on won't run `composer install`, so the plugin has to
 * work as a plain zip-and-activate.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	public static function autoload( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = SLOTKEEPER_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}
