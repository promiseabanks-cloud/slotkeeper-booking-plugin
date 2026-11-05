<?php
/**
 * Dashboard-entered integration credentials (Paystack, WhatsApp, Google
 * Calendar) — encrypted at rest and stored in their own non-autoloaded
 * option, never in `sk_settings`. This is the path a non-technical business
 * owner actually uses; `wp-config.php` constants (see BACKEND_SETUP.md's
 * original design) still work and take priority when defined, for anyone
 * who prefers file-based secrets.
 *
 * Encryption key is derived from this WordPress install's own AUTH_KEY/
 * AUTH_SALT (already present in every real install — nothing new for the
 * site owner to manage). This is defense-in-depth against a leaked DB
 * export or dump, not a substitute for normal server security: anyone with
 * both DB and wp-config access can still decrypt, same as any WP session/
 * auth-cookie secret.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Secrets {

	private const OPTION_KEY = 'sk_secrets';
	private const CIPHER     = 'aes-256-cbc';

	public const PAYSTACK_SECRET_KEY        = 'paystack_secret_key';
	public const STRIPE_SECRET_KEY          = 'stripe_secret_key';
	public const STRIPE_WEBHOOK_SECRET      = 'stripe_webhook_secret';
	public const PAYPAL_CLIENT_ID           = 'paypal_client_id';
	public const PAYPAL_SECRET              = 'paypal_secret';
	public const PAYPAL_WEBHOOK_ID          = 'paypal_webhook_id';
	public const WHATSAPP_TOKEN             = 'whatsapp_meta_token';
	public const WHATSAPP_PHONE_ID          = 'whatsapp_meta_phone_id';
	public const GOOGLE_SERVICE_ACCOUNT_JSON = 'google_service_account_json';
	public const GOOGLE_CALENDAR_ID          = 'google_calendar_id';

	/**
	 * Reads a credential. `$constant_name`, when given and actually defined,
	 * always wins — preserves the original wp-config.php path untouched.
	 */
	public static function get( string $key, ?string $constant_name = null ): ?string {
		if ( $constant_name && defined( $constant_name ) && constant( $constant_name ) ) {
			return (string) constant( $constant_name );
		}

		$store = get_option( self::OPTION_KEY, array() );
		if ( empty( $store[ $key ] ) ) {
			return null;
		}

		return self::decrypt( $store[ $key ] );
	}

	public static function is_set( string $key, ?string $constant_name = null ): bool {
		$value = self::get( $key, $constant_name );
		return null !== $value && '' !== $value;
	}

	public static function set( string $key, string $value ): void {
		$store         = get_option( self::OPTION_KEY, array() );
		$store[ $key ] = self::encrypt( $value );
		update_option( self::OPTION_KEY, $store, false ); // autoload=false — never loaded on pages that don't need it.
	}

	public static function delete( string $key ): void {
		$store = get_option( self::OPTION_KEY, array() );
		unset( $store[ $key ] );
		update_option( self::OPTION_KEY, $store, false );
	}

	public static function delete_all(): void {
		delete_option( self::OPTION_KEY );
	}

	private static function encrypt( string $value ): string {
		$key = self::derive_key();
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$ct  = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return base64_encode( $iv . $ct ); // phpcs:ignore -- storage encoding, not obfuscating output.
	}

	private static function decrypt( string $stored ): ?string {
		$key      = self::derive_key();
		$raw      = base64_decode( $stored, true ); // phpcs:ignore
		if ( false === $raw ) {
			return null;
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = substr( $raw, 0, $iv_len );
		$ct     = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? null : $plain;
	}

	private static function derive_key(): string {
		// wp_salt('auth') is stable for the life of the install (changes only
		// if the site owner deliberately rotates AUTH_KEY/AUTH_SALT in
		// wp-config.php, which would also log out every session — a rare,
		// deliberate act, not something that happens silently).
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}
}
