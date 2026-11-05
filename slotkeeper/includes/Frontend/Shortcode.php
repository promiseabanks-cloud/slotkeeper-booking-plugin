<?php
/**
 * Registers `[slotkeeper_booking]`. Assets are enqueued from inside the
 * shortcode callback itself (not unconditionally on every page load) —
 * WordPress supports this as long as it happens before `wp_footer`, which
 * holds for normal content rendering.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shortcode {

	private static int $instance_count = 0;

	public function register(): void {
		add_shortcode( 'slotkeeper_booking', array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'resource_id' => '',
				'type'        => '',
			),
			$atts,
			'slotkeeper_booking'
		);

		self::$instance_count++;
		$mount_id = 'sk-widget-' . self::$instance_count;

		$this->enqueue_assets();

		// Preselect a resource. A `resource_id` attribute wins; otherwise honor
		// a `?sk_resource=<id>` query arg, which lets a "Book this style" link
		// from elsewhere on the site (e.g. a price list) land on one style.
		// Read-only display preselection, so no nonce — the id is just a hint.
		$resource_id = $atts['resource_id'] ? (int) $atts['resource_id'] : 0;
		if ( ! $resource_id && isset( $_GET['sk_resource'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preselect hint, not a state change.
			$resource_id = absint( wp_unslash( $_GET['sk_resource'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$config = array(
			'restBase'    => esc_url_raw( rest_url( 'slotkeeper/v1' ) ),
			'resourceId'  => $resource_id ?: null,
			'type'        => $atts['type'] ? sanitize_key( $atts['type'] ) : null,
			'currency'    => \SlotKeeper\Support\Settings::get( 'currency', 'NGN' ),
			'i18n'        => $this->strings(),
		);

		return sprintf(
			'<div id="%1$s" class="sk-widget-root" data-sk-config="%2$s"></div><noscript>%3$s</noscript>',
			esc_attr( $mount_id ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'This booking widget needs JavaScript enabled to work — please contact us directly to book.', 'slotkeeper' )
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
		wp_enqueue_script( 'slotkeeper-widget', SLOTKEEPER_PLUGIN_URL . 'assets/js/widget.js', array(), SLOTKEEPER_VERSION, true );
	}

	/**
	 * @return array<string,string>
	 */
	private function strings(): array {
		return array(
			'choose_title'      => __( "Choose what you're booking", 'slotkeeper' ),
			'when_title'        => __( 'Pick a time', 'slotkeeper' ),
			'range_title'       => __( 'Pick your dates', 'slotkeeper' ),
			'details_title'     => __( 'Your details', 'slotkeeper' ),
			'no_slots'          => __( 'Nothing open right now — try another option, or check back soon.', 'slotkeeper' ),
			'field_name'        => __( 'Name', 'slotkeeper' ),
			'field_email'       => __( 'Email', 'slotkeeper' ),
			'field_phone'       => __( 'Phone', 'slotkeeper' ),
			'field_notes'       => __( 'Notes (optional)', 'slotkeeper' ),
			'err_name'          => __( 'Enter your name.', 'slotkeeper' ),
			'err_email'         => __( 'Enter a valid email — your confirmation goes here.', 'slotkeeper' ),
			'err_phone'         => __( 'Enter a valid phone number.', 'slotkeeper' ),
			'submit'            => __( 'Confirm booking', 'slotkeeper' ),
			'submitting'        => __( 'Confirming your booking…', 'slotkeeper' ),
			'success_title'     => __( "You're booked", 'slotkeeper' ),
			'success_note'      => __( 'A confirmation has been sent to your email.', 'slotkeeper' ),
			'error_taken'       => __( 'That was just taken by someone else — please pick another.', 'slotkeeper' ),
			'error_generic'     => __( "We couldn't reach the server. Your details are still here — try again.", 'slotkeeper' ),
			'back'              => __( '‹ Back', 'slotkeeper' ),
			'continue'          => __( 'Continue', 'slotkeeper' ),
			'retry'             => __( 'Retry', 'slotkeeper' ),
			'party_size'        => __( 'Party size', 'slotkeeper' ),
			'seats'             => __( 'Seats', 'slotkeeper' ),
			'pick_up'           => __( 'Pick up', 'slotkeeper' ),
			'return'            => __( 'Return', 'slotkeeper' ),
			'price_label'       => __( 'Price', 'slotkeeper' ),
			'price_due'         => __( 'Due now', 'slotkeeper' ),
			'continue_to_payment' => __( 'Continue to payment', 'slotkeeper' ),
			'confirming_payment' => __( 'Confirming your payment…', 'slotkeeper' ),
			'payment_pending_title' => __( 'Still confirming', 'slotkeeper' ),
			'payment_pending_body'  => __( "Your payment is still being confirmed — this can take a minute. We'll email you the moment it's done; no need to pay again.", 'slotkeeper' ),
			'payment_issue_title'   => __( "We couldn't confirm that booking", 'slotkeeper' ),
			'payment_issue_body'    => __( 'Something went wrong with the payment. If you were charged, contact us directly and we\'ll sort it out — otherwise, please try booking again.', 'slotkeeper' ),
		);
	}
}
