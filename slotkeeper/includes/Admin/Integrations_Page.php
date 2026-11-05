<?php
/**
 * Where the founder enters real credentials — Paystack secret key, WhatsApp
 * (Meta Cloud API) token, Google Calendar service-account JSON. Separate
 * from Settings_Page on purpose (mirrors the IntegrationsController pattern
 * already proven on Banks Business Engine): each connector gets its own
 * block here rather than growing the settings screen indefinitely.
 *
 * Secrets are never round-tripped back into the page — a saved value shows
 * as a masked placeholder, and submitting blank leaves it untouched. This
 * is the standard "leave blank to keep" pattern; it also means a saved
 * secret is never visible in page source/view-source.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Payments\Paystack_Gateway;
use SlotKeeper\Payments\PayPal_Gateway;
use SlotKeeper\Payments\Stripe_Gateway;
use SlotKeeper\Calendar\Google_Calendar_Sync;
use SlotKeeper\Notifications\WhatsApp_Channel;
use SlotKeeper\Support\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Integrations_Page {

	private const NONCE_ACTION = 'sk_save_integrations';

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		if ( isset( $_POST['sk_integrations_submit'] ) ) {
			$this->handle_save();
		}

		$paystack = new Paystack_Gateway();
		$stripe   = new Stripe_Gateway();
		$paypal   = new PayPal_Gateway();
		$whatsapp = new WhatsApp_Channel();
		$calendar = new Google_Calendar_Sync();

		$webhook_url        = rest_url( 'slotkeeper/v1/webhooks/paystack' );
		$stripe_webhook_url = rest_url( 'slotkeeper/v1/webhooks/stripe' );
		$paypal_webhook_url = rest_url( 'slotkeeper/v1/webhooks/paypal' );

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-integrations' ); ?>
			<p class="description"><?php esc_html_e( 'Real credentials for the payment and notification providers below. Stored encrypted; a saved value never shows again in full — leave a field blank to keep what you already have, or check its "Remove" box to clear it.', 'slotkeeper' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<h2><?php esc_html_e( 'Paystack', 'slotkeeper' ); ?> <?php $this->status_badge( $paystack->is_configured() ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_paystack_key"><?php esc_html_e( 'Secret key', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="password" id="sk_paystack_key" name="paystack_secret_key" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::PAYSTACK_SECRET_KEY, 'SLOTKEEPER_PAYSTACK_SECRET_KEY' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : 'sk_live_...'; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="paystack_secret_key_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'From your Paystack dashboard → Settings → API Keys & Webhooks. Use the Secret key, not the Public key.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'slotkeeper' ); ?></th>
						<td>
							<input type="text" readonly class="regular-text" style="width:100%;max-width:480px;" value="<?php echo esc_attr( $webhook_url ); ?>" onclick="this.select();">
							<p class="description"><?php esc_html_e( 'Paste this into Paystack → Settings → API Keys & Webhooks → Webhook URL. SlotKeeper can\'t register it there for you — Paystack requires that to be set from their own dashboard.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Stripe', 'slotkeeper' ); ?> <?php $this->status_badge( $stripe->is_configured() ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_stripe_key"><?php esc_html_e( 'Secret key', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="password" id="sk_stripe_key" name="stripe_secret_key" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::STRIPE_SECRET_KEY, 'SLOTKEEPER_STRIPE_SECRET_KEY' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : 'sk_live_...'; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="stripe_secret_key_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'From your Stripe dashboard → Developers → API keys. Use the Secret key (sk_live_… or sk_test_…).', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_stripe_whsec"><?php esc_html_e( 'Webhook signing secret', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="password" id="sk_stripe_whsec" name="stripe_webhook_secret" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::STRIPE_WEBHOOK_SECRET, 'SLOTKEEPER_STRIPE_WEBHOOK_SECRET' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : 'whsec_...'; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="stripe_webhook_secret_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'Optional but recommended. In Stripe → Developers → Webhooks, add an endpoint for the "checkout.session.completed" event pointing at the URL below, then paste its signing secret here. Without it, payment is still confirmed when the customer returns.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'slotkeeper' ); ?></th>
						<td>
							<input type="text" readonly class="regular-text" style="width:100%;max-width:480px;" value="<?php echo esc_attr( $stripe_webhook_url ); ?>" onclick="this.select();">
							<p class="description"><?php esc_html_e( 'Paste this into Stripe → Developers → Webhooks → Add endpoint, subscribed to "checkout.session.completed".', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'PayPal', 'slotkeeper' ); ?> <?php $this->status_badge( $paypal->is_configured() ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_paypal_client"><?php esc_html_e( 'Client ID', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="text" id="sk_paypal_client" name="paypal_client_id" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::PAYPAL_CLIENT_ID, 'SLOTKEEPER_PAYPAL_CLIENT_ID' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : ''; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="paypal_client_id_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_paypal_secret"><?php esc_html_e( 'Secret', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="password" id="sk_paypal_secret" name="paypal_secret" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::PAYPAL_SECRET, 'SLOTKEEPER_PAYPAL_SECRET' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : ''; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="paypal_secret_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'Client ID and Secret both from developer.paypal.com → Apps & Credentials. Match these to the Live/Sandbox environment you chose in Settings.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_paypal_webhook_id"><?php esc_html_e( 'Webhook ID', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="text" id="sk_paypal_webhook_id" name="paypal_webhook_id" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::PAYPAL_WEBHOOK_ID, 'SLOTKEEPER_PAYPAL_WEBHOOK_ID' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : ''; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="paypal_webhook_id_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'Optional. In PayPal → your app → Webhooks, add the URL below for the "PAYMENT.CAPTURE.COMPLETED" event, then paste the Webhook ID here so SlotKeeper can verify it. Without it, payment is still confirmed when the customer returns.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'slotkeeper' ); ?></th>
						<td>
							<input type="text" readonly class="regular-text" style="width:100%;max-width:480px;" value="<?php echo esc_attr( $paypal_webhook_url ); ?>" onclick="this.select();">
							<p class="description"><?php esc_html_e( 'Paste this into PayPal → your app → Webhooks, subscribed to "PAYMENT.CAPTURE.COMPLETED".', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'WhatsApp (Meta Cloud API)', 'slotkeeper' ); ?> <?php $this->status_badge( $whatsapp->is_configured() ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_wa_token"><?php esc_html_e( 'Access token', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="password" id="sk_wa_token" name="whatsapp_meta_token" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::WHATSAPP_TOKEN, 'SLOTKEEPER_WHATSAPP_META_TOKEN' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : ''; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="whatsapp_meta_token_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_wa_phone"><?php esc_html_e( 'Phone number ID', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="text" id="sk_wa_phone" name="whatsapp_meta_phone_id" class="regular-text" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::WHATSAPP_PHONE_ID, 'SLOTKEEPER_WHATSAPP_META_PHONE_ID' ) ? esc_attr__( '•••••••••• (saved — leave blank to keep)', 'slotkeeper' ) : ''; ?>">
							<label style="margin-left:10px;"><input type="checkbox" name="whatsapp_meta_phone_id_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'Both from Meta for Developers → your app → WhatsApp → API Setup. Meta only allows free-form messages within 24 hours of the customer messaging first — outside that window you need an approved message template.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Google Calendar', 'slotkeeper' ); ?> <?php $this->status_badge( $calendar->is_configured() ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_gcal_json"><?php esc_html_e( 'Service account JSON key', 'slotkeeper' ); ?></label></th>
						<td>
							<textarea id="sk_gcal_json" name="google_service_account_json" rows="4" class="large-text code" autocomplete="off" placeholder="<?php echo Secrets::is_set( Secrets::GOOGLE_SERVICE_ACCOUNT_JSON, 'SLOTKEEPER_GOOGLE_SERVICE_ACCOUNT_JSON' ) ? esc_attr__( 'A key is already saved — leave blank to keep it', 'slotkeeper' ) : '{ "type": "service_account", ... }'; ?>"></textarea>
							<label><input type="checkbox" name="google_service_account_json_clear" value="1"> <?php esc_html_e( 'Remove', 'slotkeeper' ); ?></label>
							<p class="description"><?php esc_html_e( 'Paste the full contents of the service-account key file from Google Cloud Console. Calendar sync itself isn\'t built yet — this just saves the credential so nothing needs re-entering once it is.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save integrations', 'slotkeeper' ), 'primary', 'sk_integrations_submit' ); ?>
			</form>
		</div>
		<?php
	}

	private function status_badge( bool $configured ): void {
		if ( $configured ) {
			echo '<span class="sk-badge sk-badge-good">' . esc_html__( 'Configured', 'slotkeeper' ) . '</span>';
		} else {
			echo '<span class="sk-badge sk-badge-muted">' . esc_html__( 'Not configured', 'slotkeeper' ) . '</span>';
		}
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION );

		$fields = array(
			'paystack_secret_key'          => Secrets::PAYSTACK_SECRET_KEY,
			'stripe_secret_key'            => Secrets::STRIPE_SECRET_KEY,
			'stripe_webhook_secret'        => Secrets::STRIPE_WEBHOOK_SECRET,
			'paypal_client_id'             => Secrets::PAYPAL_CLIENT_ID,
			'paypal_secret'                => Secrets::PAYPAL_SECRET,
			'paypal_webhook_id'            => Secrets::PAYPAL_WEBHOOK_ID,
			'whatsapp_meta_token'          => Secrets::WHATSAPP_TOKEN,
			'whatsapp_meta_phone_id'       => Secrets::WHATSAPP_PHONE_ID,
			'google_service_account_json'  => Secrets::GOOGLE_SERVICE_ACCOUNT_JSON,
		);

		foreach ( $fields as $post_key => $secret_key ) {
			if ( ! empty( $_POST[ $post_key . '_clear' ] ) ) {
				Secrets::delete( $secret_key );
				continue;
			}

			$raw = isset( $_POST[ $post_key ] ) ? wp_unslash( $_POST[ $post_key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized just below per field type.
			if ( ! is_string( $raw ) ) {
				continue; // Malformed input (e.g. an array) — ignore rather than fatal, same as leaving it blank.
			}
			$value = trim( $raw );

			if ( '' === $value ) {
				continue; // Leave-blank-to-keep — never overwrite a saved secret with emptiness.
			}

			$value = 'google_service_account_json' === $post_key
				? sanitize_textarea_field( $value )
				: sanitize_text_field( $value );

			Secrets::set( $secret_key, $value );
		}

		add_settings_error( 'sk_integrations', 'sk_integrations_saved', __( 'Integrations saved.', 'slotkeeper' ), 'success' );
		settings_errors( 'sk_integrations' );
	}
}
