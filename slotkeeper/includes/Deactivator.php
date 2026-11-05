<?php
/**
 * Deactivation never touches data — only an explicit uninstall (gated behind
 * a settings opt-in, see uninstall.php) may delete anything.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate(): void {
		// Nothing scheduled yet to clear. Placeholder kept so a future
		// wp_cron hook has an obvious, already-wired place to unschedule from.
	}
}
