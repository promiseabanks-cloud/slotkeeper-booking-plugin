<?php
/**
 * Google Calendar sync via a **service account** — no interactive per-user
 * OAuth, which is the right shape for a non-technical business owner (the
 * BBE precedent the earlier stub referenced). The owner shares their Google
 * calendar with the service account's email and pastes the calendar id; this
 * class signs a JWT with the service-account key, exchanges it for an access
 * token, and writes events to that calendar.
 *
 * Credential storage was already in place (the service-account JSON, encrypted
 * via Secrets, or a `SLOTKEEPER_GOOGLE_SERVICE_ACCOUNT_JSON` constant). This
 * adds the actual token exchange and the event create/delete calls.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Calendar;

use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Calendar_Sync implements Calendar_Sync {

	private const CONSTANT_NAME     = 'SLOTKEEPER_GOOGLE_SERVICE_ACCOUNT_JSON';
	private const CALENDAR_CONSTANT = 'SLOTKEEPER_GOOGLE_CALENDAR_ID';
	private const TOKEN_URL         = 'https://oauth2.googleapis.com/token';
	private const SCOPE             = 'https://www.googleapis.com/auth/calendar';
	private const TOKEN_TRANSIENT   = 'sk_gcal_token';

	public function key(): string {
		return 'google_calendar';
	}

	/** Sync needs both a service-account key and a target calendar id. */
	public function is_configured(): bool {
		return Secrets::is_set( Secrets::GOOGLE_SERVICE_ACCOUNT_JSON, self::CONSTANT_NAME )
			&& Secrets::is_set( Secrets::GOOGLE_CALENDAR_ID, self::CALENDAR_CONSTANT );
	}

	public function push( array $booking ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_google_calendar_not_configured', __( 'Google Calendar is not configured.', 'slotkeeper' ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$resource = ( new Resource_Repository() )->find( (int) $booking['resource_id'] );
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );
		$tz       = wp_timezone_string();

		$summary = sprintf(
			/* translators: 1: service name, 2: customer name */
			__( '%1$s — %2$s', 'slotkeeper' ),
			$resource['name'] ?? __( 'Booking', 'slotkeeper' ),
			$customer['name'] ?? __( 'Customer', 'slotkeeper' )
		);

		$event = array(
			'summary'     => $summary,
			'description' => $this->describe( $booking, $customer ),
			'start'       => array( 'dateTime' => $this->rfc3339( $booking['start_datetime'] ), 'timeZone' => $tz ),
			'end'         => array( 'dateTime' => $this->rfc3339( $booking['end_datetime'] ), 'timeZone' => $tz ),
		);

		$response = wp_remote_post(
			$this->events_url(),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $event ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id'] ) ) {
			$message = $body['error']['message'] ?? __( 'Google Calendar did not return an event id.', 'slotkeeper' );
			return new \WP_Error( 'sk_google_calendar_create_failed', $message );
		}

		return (string) $body['id'];
	}

	public function cancel( string $event_id ) {
		if ( ! $this->is_configured() || '' === $event_id ) {
			return new \WP_Error( 'sk_google_calendar_not_configured', __( 'Google Calendar is not configured.', 'slotkeeper' ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_request(
			$this->events_url() . '/' . rawurlencode( $event_id ),
			array(
				'method'  => 'DELETE',
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 204 = deleted; 404/410 = already gone, which is fine for our purposes.
		if ( 204 === $code || 404 === $code || 410 === $code || 200 === $code ) {
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return new \WP_Error( 'sk_google_calendar_delete_failed', $body['error']['message'] ?? __( 'Could not remove the calendar event.', 'slotkeeper' ) );
	}

	// ---- internals -------------------------------------------------------

	private function events_url(): string {
		$calendar_id = (string) Secrets::get( Secrets::GOOGLE_CALENDAR_ID, self::CALENDAR_CONSTANT );
		return 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events';
	}

	/**
	 * A cached service-account access token, or a WP_Error. Tokens last an
	 * hour; the transient is held a little under that.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$creds = json_decode( (string) Secrets::get( Secrets::GOOGLE_SERVICE_ACCOUNT_JSON, self::CONSTANT_NAME ), true );
		if ( ! is_array( $creds ) || empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
			return new \WP_Error( 'sk_google_calendar_bad_key', __( 'The Google service-account key is missing or malformed.', 'slotkeeper' ) );
		}

		$assertion = $this->signed_jwt( $creds['client_email'], $creds['private_key'] );
		if ( is_wp_error( $assertion ) ) {
			return $assertion;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$message = $body['error_description'] ?? ( $body['error'] ?? __( 'Google did not return an access token.', 'slotkeeper' ) );
			return new \WP_Error( 'sk_google_calendar_auth_failed', is_string( $message ) ? $message : __( 'Google authentication failed.', 'slotkeeper' ) );
		}

		$ttl = max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 300 );
		set_transient( self::TOKEN_TRANSIENT, (string) $body['access_token'], $ttl );

		return (string) $body['access_token'];
	}

	/**
	 * Build and RS256-sign the JWT assertion a service account exchanges for
	 * an access token.
	 *
	 * @return string|\WP_Error
	 */
	private function signed_jwt( string $client_email, string $private_key ) {
		$now    = time();
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		$claim  = array(
			'iss'   => $client_email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$signing_input = $this->base64url( (string) wp_json_encode( $header ) ) . '.' . $this->base64url( (string) wp_json_encode( $claim ) );

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return new \WP_Error( 'sk_google_calendar_sign_failed', __( 'Could not sign the Google authentication request — check the service-account key.', 'slotkeeper' ) );
		}

		return $signing_input . '.' . $this->base64url( $signature );
	}

	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore -- URL-safe base64 for JWT, not obfuscation.
	}

	private function rfc3339( string $mysql_datetime ): string {
		return str_replace( ' ', 'T', $mysql_datetime );
	}

	/**
	 * @param array<string,mixed> $booking
	 * @param array<string,mixed>|null $customer
	 */
	private function describe( array $booking, ?array $customer ): string {
		$lines = array();
		if ( ! empty( $customer['phone'] ) ) {
			$lines[] = __( 'Phone: ', 'slotkeeper' ) . $customer['phone'];
		}
		if ( ! empty( $customer['email'] ) ) {
			$lines[] = __( 'Email: ', 'slotkeeper' ) . $customer['email'];
		}
		if ( ! empty( $booking['notes'] ) ) {
			$lines[] = __( 'Notes: ', 'slotkeeper' ) . $booking['notes'];
		}
		$lines[] = __( 'Booked via SlotKeeper. Ref #', 'slotkeeper' ) . $booking['id'];
		return implode( "\n", $lines );
	}
}
