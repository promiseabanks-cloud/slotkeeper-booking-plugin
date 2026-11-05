<?php
/**
 * Meta WhatsApp Cloud API — chosen over Twilio because Twilio's free trial
 * blocks real message content (proven the hard way on Banks Business
 * Engine), while Meta's Cloud API is free and already verified working in
 * production there.
 *
 * IMPORTANT real-world limit, carried over from that same experience: Meta
 * only allows a free-form text message within a 24-hour window after the
 * recipient last messaged the business's WhatsApp number. Outside that
 * window, only a pre-approved message *template* can be sent. A booking
 * confirmation is business-initiated, so a first-time customer who has
 * never messaged the business needs the template path to ever receive it.
 *
 * Founder approved exactly one template so far — Meta's "appointments"
 * template (English/`en`), body: "Hi {{1}}, your booking for {{2}} on
 * {{3}} is confirmed. We look forward to having you around." — confirmed
 * 2026-08-30 from the WhatsApp Manager template detail screen. It only
 * covers the *confirmed* case, and it does NOT carry the resource/service
 * name, the business name, or the manage-booking link that the free-text
 * version and the email confirmation both include — Meta template content
 * can't be extended without resubmitting and waiting for re-approval, so
 * `send()` sends the narrower approved wording for 'booking_confirmed' and
 * still falls back to free-form text (24-hour-window limited) for
 * 'booking_pending_payment' and 'booking_cancelled', which have no
 * approved template yet.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Notifications;

use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WhatsApp_Channel implements Notification_Channel {

	private const TOKEN_CONSTANT    = 'SLOTKEEPER_WHATSAPP_META_TOKEN';
	private const PHONE_ID_CONSTANT = 'SLOTKEEPER_WHATSAPP_META_PHONE_ID';

	/** Meta template name + language code, exactly as approved in WhatsApp Manager. */
	private const CONFIRMED_TEMPLATE_NAME     = 'appointments';
	private const CONFIRMED_TEMPLATE_LANGUAGE = 'en';

	public function key(): string {
		return 'whatsapp';
	}

	public function is_configured(): bool {
		return Secrets::is_set( Secrets::WHATSAPP_TOKEN, self::TOKEN_CONSTANT )
			&& Secrets::is_set( Secrets::WHATSAPP_PHONE_ID, self::PHONE_ID_CONSTANT );
	}

	/** Customer-facing send — recipient and copy both come from the booking. */
	public function send( array $booking, string $template ) {
		$customer = ( new Customer_Repository() )->find( (int) $booking['customer_id'] );
		$phone    = $customer['phone'] ?? '';

		if ( empty( $phone ) ) {
			return new \WP_Error( 'sk_no_phone', __( 'Customer has no phone number.', 'slotkeeper' ) );
		}

		if ( 'booking_confirmed' === $template ) {
			return $this->send_confirmed_template( $phone, $booking, $customer );
		}

		// No approved template for this case yet — only reaches a customer
		// who has messaged the business's WhatsApp number in the last 24h.
		return $this->send_text( $phone, Message_Builder::body( $booking, $template ) );
	}

	/** Builds the 3 approved-template variables from a booking and sends it. */
	private function send_confirmed_template( string $phone, array $booking, array $customer ) {
		$tz   = wp_timezone();
		$ts   = strtotime( $booking['start_datetime'] );
		$time = wp_date( get_option( 'time_format' ), $ts, $tz );
		$date = wp_date( get_option( 'date_format' ), $ts, $tz );
		$name = $customer['name'] ?? '';

		return $this->send_template(
			$phone,
			self::CONFIRMED_TEMPLATE_NAME,
			self::CONFIRMED_TEMPLATE_LANGUAGE,
			array( $name, $time, $date )
		);
	}

	/**
	 * Sends an approved Meta message template — the only send type that
	 * reliably reaches a customer who has never messaged the business
	 * before, since it isn't subject to the 24-hour session-window rule
	 * that plain text is.
	 *
	 * @param string   $phone          E.164, with or without a leading '+'.
	 * @param string   $template_name  Exact name as registered in WhatsApp Manager.
	 * @param string   $language_code  Exact language code as registered (e.g. 'en').
	 * @param string[] $body_params    Values for {{1}}, {{2}}, ... in order.
	 * @return true|\WP_Error
	 */
	public function send_template( string $phone, string $template_name, string $language_code, array $body_params ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_whatsapp_not_configured', __( 'WhatsApp is not configured.', 'slotkeeper' ) );
		}

		if ( empty( $phone ) ) {
			return new \WP_Error( 'sk_no_phone', __( 'No phone number given.', 'slotkeeper' ) );
		}

		$phone_id = Secrets::get( Secrets::WHATSAPP_PHONE_ID, self::PHONE_ID_CONSTANT );
		$token    = Secrets::get( Secrets::WHATSAPP_TOKEN, self::TOKEN_CONSTANT );

		$parameters = array_map(
			static fn( $value ) => array(
				'type' => 'text',
				'text' => (string) $value,
			),
			$body_params
		);

		$response = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$phone_id}/messages",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'to'                => ltrim( $phone, '+' ),
						'type'              => 'template',
						'template'          => array(
							'name'       => $template_name,
							'language'   => array( 'code' => $language_code ),
							'components' => array(
								array(
									'type'       => 'body',
									'parameters' => $parameters,
								),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'sk_whatsapp_api_error', wp_remote_retrieve_body( $response ) );
		}

		return true;
	}

	/**
	 * Lower-level free-text send to an arbitrary number — used by `send()`
	 * above (for templates that don't have an approved Meta template yet)
	 * and by `Admin_Notifier` for the founder's own new-booking alert,
	 * which has a different recipient and different copy than the customer
	 * confirmation and doesn't fit the booking-scoped `Notification_Channel`
	 * interface. The founder always counts as having messaged the business
	 * number, so the 24-hour window is never the practical blocker here.
	 *
	 * @return true|\WP_Error
	 */
	public function send_text( string $phone, string $body ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'sk_whatsapp_not_configured', __( 'WhatsApp is not configured.', 'slotkeeper' ) );
		}

		if ( empty( $phone ) ) {
			return new \WP_Error( 'sk_no_phone', __( 'No phone number given.', 'slotkeeper' ) );
		}

		$phone_id = Secrets::get( Secrets::WHATSAPP_PHONE_ID, self::PHONE_ID_CONSTANT );
		$token    = Secrets::get( Secrets::WHATSAPP_TOKEN, self::TOKEN_CONSTANT );

		$response = wp_remote_post(
			"https://graph.facebook.com/v19.0/{$phone_id}/messages",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'to'                => ltrim( $phone, '+' ),
						'type'              => 'text',
						'text'              => array( 'body' => $body ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'sk_whatsapp_api_error', wp_remote_retrieve_body( $response ) );
		}

		return true;
	}
}
