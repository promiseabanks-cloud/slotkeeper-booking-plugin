<?php
/**
 * Registers `[slotkeeper_manage_booking]` — Flow 06, the self-service
 * cancellation page. Reached via a `?token=` link in the confirmation
 * email/WhatsApp message, never linked from anywhere inside wp-admin.
 * Same abuse posture as the booking widget: guest-only, rate-limited,
 * token-authorized (see SECURITY.md).
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manage_Booking_Shortcode {

	public function register(): void {
		add_shortcode( 'slotkeeper_manage_booking', array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public function render( $atts ): string {
		$this->enqueue_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only token lookup, not a state-changing GET.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$config = array(
			'restBase' => esc_url_raw( rest_url( 'slotkeeper/v1' ) ),
			'token'    => $token,
			'i18n'     => $this->strings(),
		);

		return sprintf(
			'<div id="sk-manage-root" class="sk-widget-root" data-sk-config="%1$s"></div><noscript>%2$s</noscript>',
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'This page needs JavaScript enabled to manage your booking — please contact us directly.', 'slotkeeper' )
		);
	}

	private function enqueue_assets(): void {
		wp_enqueue_style(
			'slotkeeper-widget-fonts',
			'https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@500;600;700&family=Hanken+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap',
			array(),
			null
		);
		wp_enqueue_style( 'slotkeeper-widget', SLOTKEEPER_PLUGIN_URL . 'assets/css/widget.css', array( 'slotkeeper-widget-fonts' ), SLOTKEEPER_VERSION );
		wp_enqueue_script( 'slotkeeper-manage', SLOTKEEPER_PLUGIN_URL . 'assets/js/manage.js', array(), SLOTKEEPER_VERSION, true );
	}

	/**
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'loading'          => __( 'Looking up your booking…', 'slotkeeper' ),
			'not_found_title'  => __( "We couldn't find that booking", 'slotkeeper' ),
			'not_found_body'   => __( 'This link may have expired or already been used. Contact us directly if you need help.', 'slotkeeper' ),
			'cancelled_title'  => __( 'Already cancelled', 'slotkeeper' ),
			'cancelled_body'   => __( 'This booking was already cancelled — nothing more to do here.', 'slotkeeper' ),
			'past_title'       => __( "This booking's date has passed", 'slotkeeper' ),
			'past_body'        => __( "It's too late to cancel this one — contact us directly if something's wrong.", 'slotkeeper' ),
			'manage_title'     => __( 'Your booking', 'slotkeeper' ),
			'with'             => __( 'With', 'slotkeeper' ),
			'when'             => __( 'When', 'slotkeeper' ),
			'seats'            => __( 'Seats', 'slotkeeper' ),
			'status'           => __( 'Status', 'slotkeeper' ),
			'cancel_button'    => __( 'Cancel this booking', 'slotkeeper' ),
			'confirm_prompt'   => __( 'Cancel this booking? This can\'t be undone.', 'slotkeeper' ),
			'confirm_yes'      => __( 'Yes, cancel it', 'slotkeeper' ),
			'confirm_no'       => __( 'No, keep it', 'slotkeeper' ),
			'cancelling'       => __( 'Cancelling…', 'slotkeeper' ),
			'done_title'       => __( "It's cancelled", 'slotkeeper' ),
			'done_body'        => __( 'This booking has been cancelled. No further action needed.', 'slotkeeper' ),
			'error_generic'    => __( "We couldn't reach the server — please try again.", 'slotkeeper' ),
			'retry'            => __( 'Retry', 'slotkeeper' ),
		);
	}
}
