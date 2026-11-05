<?php
/**
 * Renders a customer email as a modern, responsive HTML document.
 *
 * Email clients are not browsers: no external stylesheet, no CSS custom
 * properties, limited flexbox. So this is deliberately old-school —
 * table-based layout with inline styles and literal colours (the salon
 * palette: ink #220e06, gold #c69362, warm cream #f4eee7). The content
 * pieces come from Message_Builder; the footer is built from the
 * business-details Settings so a site owner edits it in one place.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Template {

	/**
	 * @param string                     $heading  Short serif headline.
	 * @param string                     $intro    Lead paragraph (plain text, will be escaped).
	 * @param array<int,array{0:string,1:string}> $rows  Label/value detail rows.
	 * @param array{url?:string,label?:string} $cta   Optional primary button.
	 * @param string                     $note     Optional small print under the panel.
	 */
	public static function wrap( string $heading, string $intro, array $rows = array(), array $cta = array(), string $note = '' ): string {
		$business = Settings::get( 'business_name' );
		$serif    = "'Cormorant Garamond', Georgia, 'Times New Roman', serif";
		$sans     = "'Raleway', 'Segoe UI', Helvetica, Arial, sans-serif";

		$rows_html = '';
		foreach ( $rows as $row ) {
			$rows_html .=
				'<tr>' .
					'<td style="padding:10px 0;border-bottom:1px solid #e5e1db;font-family:' . $sans . ';font-size:13px;color:#8d8680;">' . esc_html( $row[0] ) . '</td>' .
					'<td style="padding:10px 0;border-bottom:1px solid #e5e1db;font-family:' . $sans . ';font-size:14px;color:#220e06;font-weight:600;text-align:right;">' . esc_html( $row[1] ) . '</td>' .
				'</tr>';
		}
		$details = $rows_html
			? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0;background:#faf6f1;border:1px solid #e5e1db;border-radius:14px;">' .
				'<tr><td style="padding:6px 20px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows_html . '</table></td></tr>' .
			  '</table>'
			: '';

		$button = '';
		if ( ! empty( $cta['url'] ) && ! empty( $cta['label'] ) ) {
			$button =
				'<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 8px;"><tr><td style="border-radius:999px;background:#220e06;">' .
					'<a href="' . esc_url( $cta['url'] ) . '" style="display:inline-block;padding:15px 30px;font-family:' . $sans . ';font-size:14px;font-weight:600;letter-spacing:.02em;color:#ffffff;text-decoration:none;border-radius:999px;">' . esc_html( $cta['label'] ) . '</a>' .
				'</td></tr></table>';
		}

		$note_html = $note
			? '<p style="margin:18px 0 0;font-family:' . $sans . ';font-size:12px;line-height:1.6;color:#8d8680;">' . esc_html( $note ) . '</p>'
			: '';

		$preheader = esc_html( $intro );

		return '<!doctype html>' .
		'<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
		'<meta name="color-scheme" content="light"><title>' . esc_html( $heading ) . '</title></head>' .
		'<body style="margin:0;padding:0;background:#f4eee7;">' .
		'<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $preheader . '</div>' .
		'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4eee7;">' .
			'<tr><td align="center" style="padding:32px 16px;">' .
				'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 10px 30px rgba(34,14,6,.08);">' .

					// Masthead — ink band, serif wordmark, thin gold rule.
					'<tr><td style="background:#220e06;padding:30px 36px 26px;text-align:center;">' .
						'<div style="font-family:' . $serif . ';font-size:26px;font-weight:600;letter-spacing:.01em;color:#faf6f1;">' . esc_html( $business ) . '</div>' .
						'<div style="height:2px;width:46px;margin:14px auto 0;background:#c69362;"></div>' .
					'</td></tr>' .

					// Body.
					'<tr><td style="padding:34px 36px 30px;">' .
						'<h1 style="margin:0 0 12px;font-family:' . $serif . ';font-weight:500;font-size:30px;line-height:1.1;color:#220e06;">' . esc_html( $heading ) . '</h1>' .
						'<p style="margin:0;font-family:' . $sans . ';font-size:15px;line-height:1.6;color:#5f5852;">' . esc_html( $intro ) . '</p>' .
						$details .
						$button .
						$note_html .
					'</td></tr>' .

					// Footer — business details from Settings.
					'<tr><td style="background:#faf6f1;border-top:1px solid #e5e1db;padding:26px 36px;text-align:center;">' .
						self::footer_html( $business, $serif, $sans ) .
					'</td></tr>' .

				'</table>' .
			'</td></tr>' .
		'</table></body></html>';
	}

	/**
	 * The footer block: name, address, hours, phone, email, social — each line
	 * printed only when the matching Setting is filled in.
	 */
	private static function footer_html( string $business, string $serif, string $sans ): string {
		$address = (string) Settings::get( 'business_address', '' );
		$hours   = (string) Settings::get( 'business_hours', '' );
		$phone   = (string) Settings::get( 'business_phone', '' );
		$email   = (string) Settings::get( 'business_email', '' );
		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		$lines = '<div style="font-family:' . $serif . ';font-size:18px;font-weight:600;color:#220e06;">' . esc_html( $business ) . '</div>';

		if ( '' !== trim( $address ) ) {
			$lines .= '<div style="margin-top:8px;font-family:' . $sans . ';font-size:12.5px;line-height:1.6;color:#8d8680;">' . nl2br( esc_html( $address ) ) . '</div>';
		}
		if ( '' !== trim( $hours ) ) {
			$lines .= '<div style="margin-top:6px;font-family:' . $sans . ';font-size:12.5px;color:#8d8680;">' . esc_html( $hours ) . '</div>';
		}

		$contact = array();
		if ( '' !== trim( $phone ) ) {
			$tel       = preg_replace( '/[^0-9+]/', '', $phone );
			$contact[] = '<a href="tel:' . esc_attr( $tel ) . '" style="color:#9a6a3e;text-decoration:none;">' . esc_html( $phone ) . '</a>';
		}
		if ( '' !== trim( $email ) && is_email( $email ) ) {
			$contact[] = '<a href="mailto:' . esc_attr( $email ) . '" style="color:#9a6a3e;text-decoration:none;">' . esc_html( $email ) . '</a>';
		}
		if ( $contact ) {
			$lines .= '<div style="margin-top:10px;font-family:' . $sans . ';font-size:12.5px;color:#8d8680;">' . implode( '&nbsp;&nbsp;·&nbsp;&nbsp;', $contact ) . '</div>';
		}

		$socials = array(
			'Instagram' => (string) Settings::get( 'social_instagram', '' ),
			'TikTok'    => (string) Settings::get( 'social_tiktok', '' ),
			'Facebook'  => (string) Settings::get( 'social_facebook', '' ),
		);
		$social_links = array();
		foreach ( $socials as $label => $url ) {
			if ( '' !== trim( $url ) ) {
				$social_links[] = '<a href="' . esc_url( $url ) . '" style="color:#9a6a3e;text-decoration:none;font-weight:600;">' . esc_html( $label ) . '</a>';
			}
		}
		if ( $social_links ) {
			$lines .= '<div style="margin-top:12px;font-family:' . $sans . ';font-size:12px;letter-spacing:.04em;">' . implode( '&nbsp;&nbsp;·&nbsp;&nbsp;', $social_links ) . '</div>';
		}

		return $lines;
	}
}
