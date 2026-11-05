<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Notifications\Email_Channel;
use SlotKeeper\Notifications\SMS_Channel;
use SlotKeeper\Notifications\WhatsApp_Channel;
use SlotKeeper\Payments\Gateway_Registry;
use SlotKeeper\Support\Deposit;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Page {

	private const NONCE_ACTION = 'sk_save_settings';

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		if ( isset( $_POST['sk_settings_submit'] ) ) {
			$this->handle_save();
		}

		$settings = Settings::all();
		$channels = array( new Email_Channel(), new WhatsApp_Channel(), new SMS_Channel() );
		$gateways = Gateway_Registry::all();

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-settings' ); ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sk_business_name"><?php esc_html_e( 'Business name', 'slotkeeper' ); ?></label></th>
						<td><input type="text" id="sk_business_name" name="business_name" class="regular-text" value="<?php echo esc_attr( $settings['business_name'] ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_currency"><?php esc_html_e( 'Currency', 'slotkeeper' ); ?></label></th>
						<td>
							<select id="sk_currency" name="currency">
								<?php foreach ( array( 'NGN', 'USD', 'GBP', 'EUR' ) as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['currency'], $code ); ?>><?php echo esc_html( $code ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Booking types in use', 'slotkeeper' ); ?></th>
						<td>
							<?php foreach ( Settings::BOOKING_TYPES as $type ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="enabled_booking_types[]" value="<?php echo esc_attr( $type ); ?>" <?php checked( in_array( $type, $settings['enabled_booking_types'], true ) ); ?>>
									<?php echo esc_html( ucfirst( $type ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Only the resource types you check here are offered when you create a Resource.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notification channels', 'slotkeeper' ); ?></th>
						<td>
							<?php foreach ( $channels as $channel ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="notifications[<?php echo esc_attr( $channel->key() ); ?>]" value="1" <?php checked( ! empty( $settings['notifications'][ $channel->key() ] ) ); ?> <?php disabled( ! $channel->is_configured() ); ?>>
									<?php echo esc_html( ucfirst( $channel->key() ) ); ?>
									<?php if ( ! $channel->is_configured() ) : ?>
										<em style="color:#a00;"><?php esc_html_e( '(not configured yet)', 'slotkeeper' ); ?></em>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_admin_whatsapp"><?php esc_html_e( 'New-booking alerts (WhatsApp)', 'slotkeeper' ); ?></label></th>
						<td>
							<input type="text" id="sk_admin_whatsapp" name="admin_whatsapp_number" class="regular-text" placeholder="+234..." value="<?php echo esc_attr( $settings['admin_whatsapp_number'] ); ?>">
							<p class="description"><?php esc_html_e( 'Your own WhatsApp number — you get a message here every time someone books, separate from the confirmation the customer receives. Leave blank to turn this off.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_payment_gateway"><?php esc_html_e( 'Payment gateway', 'slotkeeper' ); ?></label></th>
						<td>
							<select id="sk_payment_gateway" name="payment_gateway">
								<option value="none" <?php selected( $settings['payment_gateway'], 'none' ); ?>><?php esc_html_e( 'None — bookings never require payment', 'slotkeeper' ); ?></option>
								<?php
								$gateway_labels = array(
									'paystack'    => 'Paystack',
									'stripe'      => 'Stripe',
									'paypal'      => 'PayPal',
									'flutterwave' => 'Flutterwave',
								);
								?>
								<?php foreach ( $gateways as $gateway ) : ?>
									<option value="<?php echo esc_attr( $gateway->key() ); ?>" <?php selected( $settings['payment_gateway'], $gateway->key() ); ?> <?php disabled( ! $gateway->is_configured() ); ?>>
										<?php echo esc_html( $gateway_labels[ $gateway->key() ] ?? ucfirst( $gateway->key() ) ); ?>
										<?php echo $gateway->is_configured() ? '' : esc_html__( ' (not configured yet)', 'slotkeeper' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_paypal_mode"><?php esc_html_e( 'PayPal environment', 'slotkeeper' ); ?></label></th>
						<td>
							<select id="sk_paypal_mode" name="paypal_mode">
								<option value="live" <?php selected( $settings['paypal_mode'], 'live' ); ?>><?php esc_html_e( 'Live', 'slotkeeper' ); ?></option>
								<option value="sandbox" <?php selected( $settings['paypal_mode'], 'sandbox' ); ?>><?php esc_html_e( 'Sandbox (testing)', 'slotkeeper' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Only applies when PayPal is the selected gateway. Use Sandbox with PayPal sandbox credentials while testing.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Taken at booking', 'slotkeeper' ); ?></th>
						<td>
							<select id="sk_deposit_type" name="deposit_type">
								<option value="<?php echo esc_attr( Deposit::TYPE_FULL ); ?>" <?php selected( $settings['deposit_type'], Deposit::TYPE_FULL ); ?>><?php esc_html_e( 'Full price', 'slotkeeper' ); ?></option>
								<option value="<?php echo esc_attr( Deposit::TYPE_PERCENT ); ?>" <?php selected( $settings['deposit_type'], Deposit::TYPE_PERCENT ); ?>><?php esc_html_e( 'A percentage deposit', 'slotkeeper' ); ?></option>
								<option value="<?php echo esc_attr( Deposit::TYPE_FIXED ); ?>" <?php selected( $settings['deposit_type'], Deposit::TYPE_FIXED ); ?>><?php esc_html_e( 'A fixed-amount deposit', 'slotkeeper' ); ?></option>
							</select>
							<input type="number" id="sk_deposit_value" name="deposit_value" min="0" step="0.01" style="width:90px;margin-left:8px;" value="<?php echo esc_attr( (string) $settings['deposit_value'] ); ?>">
							<p class="description"><?php esc_html_e( 'How much a customer pays online to confirm a booking; any balance is collected in person. For a percentage, enter the percent (e.g. 50). For a fixed deposit, enter the amount in your currency (e.g. 30). Ignored when "Full price" is selected, or when a booking has no price.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sk_manage_page"><?php esc_html_e( 'Manage-booking page', 'slotkeeper' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'manage_booking_page_id',
									'id'                => 'sk_manage_page',
									'show_option_none'  => __( '— none selected —', 'slotkeeper' ),
									'option_none_value' => '0',
									'selected'          => (int) $settings['manage_booking_page_id'],
								)
							);
							?>
							<p class="description">
								<?php esc_html_e( 'Create a page containing the shortcode', 'slotkeeper' ); ?> <code>[slotkeeper_manage_booking]</code>
								<?php esc_html_e( 'and select it here — the confirmation email links to it so customers can cancel their own booking. Leave unset and no cancel link is sent.', 'slotkeeper' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On uninstall', 'slotkeeper' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
								<?php esc_html_e( 'Delete all SlotKeeper data when this plugin is deleted', 'slotkeeper' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. Deactivating the plugin never deletes data — this only applies if you also delete it from the Plugins screen.', 'slotkeeper' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'slotkeeper' ), 'primary', 'sk_settings_submit' ); ?>
			</form>
		</div>
		<?php
	}

	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION );

		$enabled_types = array_values( array_intersect(
			array_map( 'sanitize_key', wp_unslash( (array) ( $_POST['enabled_booking_types'] ?? array() ) ) ),
			Settings::BOOKING_TYPES
		) );

		$raw_notifications = wp_unslash( (array) ( $_POST['notifications'] ?? array() ) );
		$notifications      = array(
			'email'    => ! empty( $raw_notifications['email'] ),
			'whatsapp' => ! empty( $raw_notifications['whatsapp'] ),
			'sms'      => ! empty( $raw_notifications['sms'] ),
		);

		$admin_whatsapp = sanitize_text_field( wp_unslash( $_POST['admin_whatsapp_number'] ?? '' ) );
		if ( $admin_whatsapp && ! preg_match( '/^\+?[0-9]{10,14}$/', $admin_whatsapp ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That doesn\'t look like a valid phone number — new-booking alerts were not changed.', 'slotkeeper' ) . '</p></div>';
			$admin_whatsapp = Settings::get( 'admin_whatsapp_number', '' ); // Keep the existing value rather than saving something broken.
		}

		$deposit_type = sanitize_key( wp_unslash( $_POST['deposit_type'] ?? Deposit::TYPE_FULL ) );
		if ( ! in_array( $deposit_type, Deposit::TYPES, true ) ) {
			$deposit_type = Deposit::TYPE_FULL;
		}
		$deposit_value = max( 0, (float) ( $_POST['deposit_value'] ?? 0 ) );
		if ( Deposit::TYPE_PERCENT === $deposit_type ) {
			$deposit_value = min( 100, $deposit_value ); // A deposit can't exceed 100% of the price.
		}

		$paypal_mode = 'sandbox' === sanitize_key( wp_unslash( $_POST['paypal_mode'] ?? 'live' ) ) ? 'sandbox' : 'live';

		Settings::update(
			array(
				'business_name'             => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
				'currency'                  => sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'NGN' ) ),
				'enabled_booking_types'     => $enabled_types,
				'notifications'             => $notifications,
				'admin_whatsapp_number'     => $admin_whatsapp,
				'payment_gateway'           => sanitize_key( wp_unslash( $_POST['payment_gateway'] ?? 'none' ) ),
				'paypal_mode'               => $paypal_mode,
				'deposit_type'              => $deposit_type,
				'deposit_value'             => $deposit_value,
				'manage_booking_page_id'    => absint( $_POST['manage_booking_page_id'] ?? 0 ),
				'delete_data_on_uninstall'  => ! empty( $_POST['delete_data_on_uninstall'] ),
			)
		);

		add_settings_error( 'sk_settings', 'sk_settings_saved', __( 'Settings saved.', 'slotkeeper' ), 'success' );
		settings_errors( 'sk_settings' );
	}
}
