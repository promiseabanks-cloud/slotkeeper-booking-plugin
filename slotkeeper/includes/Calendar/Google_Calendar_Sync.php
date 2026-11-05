<?php
/**
 * Sync itself deferred, on purpose (see 00_Brief_Analysis.md). BBE proved a
 * Google Service Account (no interactive per-user OAuth) is the right shape
 * for a non-technical client admin — that requires a JWT-signing token
 * exchange this pass doesn't build yet.
 *
 * Credential *storage* is real: the dashboard (SlotKeeper > Integrations)
 * accepts and encrypts the service-account JSON key now, so nothing needs
 * re-entering once the sync itself is built. `is_configured()` reflects
 * only "a credential is saved" — `push()` still honestly reports
 * not-implemented regardless, so the settings screen never claims a feature
 * that doesn't work yet.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Calendar;

use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Calendar_Sync implements Calendar_Sync {

	private const CONSTANT_NAME = 'SLOTKEEPER_GOOGLE_SERVICE_ACCOUNT_JSON';

	public function key(): string {
		return 'google_calendar';
	}

	public function is_configured(): bool {
		return Secrets::is_set( Secrets::GOOGLE_SERVICE_ACCOUNT_JSON, self::CONSTANT_NAME );
	}

	public function push( array $booking ) {
		return new \WP_Error( 'sk_google_calendar_not_implemented', __( 'Google Calendar sync is not implemented yet — your credential is saved and ready for when it is.', 'slotkeeper' ) );
	}
}
