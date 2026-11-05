<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Payments\Paystack_Gateway;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Resources_Page {

	private const NONCE_ACTION = 'sk_save_resource';

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		$repo = new Resource_Repository();

		if ( isset( $_POST['sk_resource_submit'] ) ) {
			$this->handle_create( $repo );
		}

		if ( isset( $_GET['sk_deactivate'] ) && check_admin_referer( 'sk_deactivate_resource_' . absint( $_GET['sk_deactivate'] ) ) ) {
			$repo->deactivate( absint( $_GET['sk_deactivate'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Resource deactivated.', 'slotkeeper' ) . '</p></div>';
		}

		$enabled_booking_types = Settings::get( 'enabled_booking_types', array() );
		$resources             = $repo->all();
		$payment_gateway_ready = 'paystack' === Settings::get( 'payment_gateway' ) && ( new Paystack_Gateway() )->is_configured();

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-resources' ); ?>

			<?php if ( empty( $enabled_booking_types ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: link to settings */
						wp_kses_post( __( 'No booking types are enabled yet. Turn at least one on in <a href="%s">Settings</a> before adding resources.', 'slotkeeper' ) ),
						esc_url( admin_url( 'admin.php?page=slotkeeper-settings' ) )
					);
					?>
				</p></div>
			<?php else : ?>

				<h2><?php esc_html_e( 'Add a resource', 'slotkeeper' ); ?></h2>
				<form method="post" class="sk-form">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="sk_res_type"><?php esc_html_e( 'Type', 'slotkeeper' ); ?></label></th>
							<td>
								<select id="sk_res_type" name="booking_type" required>
									<?php foreach ( $enabled_booking_types as $type ) : ?>
										<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( ucfirst( $type ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sk_res_name"><?php esc_html_e( 'Name', 'slotkeeper' ); ?></label></th>
							<td><input type="text" id="sk_res_name" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Amaka (stylist), Table 4, Canopy Tent, Yoga — Sat 9am', 'slotkeeper' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sk_res_description"><?php esc_html_e( 'Description', 'slotkeeper' ); ?></label></th>
							<td><textarea id="sk_res_description" name="description" class="large-text" rows="2"></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="sk_res_capacity"><?php esc_html_e( 'Capacity', 'slotkeeper' ); ?></label></th>
							<td>
								<input type="number" id="sk_res_capacity" name="capacity" min="1" value="1" style="width:80px;">
								<p class="description"><?php esc_html_e( 'Seats per booking window: 1 for staff/rentals, table seats for reservations, class size for classes.', 'slotkeeper' ); ?></p>
							</td>
						</tr>
						<tr class="sk-row-duration">
							<th scope="row"><label for="sk_res_duration"><?php esc_html_e( 'Duration (minutes)', 'slotkeeper' ); ?></label></th>
							<td><input type="number" id="sk_res_duration" name="duration_minutes" min="5" style="width:100px;"> <span class="description"><?php esc_html_e( 'Appointments/reservations only — how long one slot lasts.', 'slotkeeper' ); ?></span></td>
						</tr>
						<tr>
							<th scope="row"><label for="sk_res_buffer"><?php esc_html_e( 'Buffer after (minutes)', 'slotkeeper' ); ?></label></th>
							<td><input type="number" id="sk_res_buffer" name="buffer_minutes" min="0" value="0" style="width:100px;"></td>
						</tr>
						<tr>
							<th scope="row"><label for="sk_res_price"><?php esc_html_e( 'Price', 'slotkeeper' ); ?></label></th>
							<td>
								<input type="number" id="sk_res_price" name="price" min="0" step="0.01" style="width:120px;">
								<span class="description"><?php echo esc_html( Settings::get( 'currency', 'NGN' ) ); ?>, leave blank if free / not charged through this plugin yet.</span>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payment', 'slotkeeper' ); ?></th>
							<td>
								<label><input type="checkbox" name="requires_payment" value="1"> <?php esc_html_e( 'Require payment at booking (via Paystack)', 'slotkeeper' ); ?></label>
								<?php if ( ! $payment_gateway_ready ) : ?>
									<p class="description" style="color:#a00;">
										<?php
										printf(
											wp_kses_post( __( 'Paystack isn\'t configured yet — set it up in <a href="%s">Integrations</a> first, or this resource will show a price but never actually collect payment.', 'slotkeeper' ) ),
											esc_url( admin_url( 'admin.php?page=slotkeeper-integrations' ) )
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="sk-row-session">
							<th scope="row"><label for="sk_res_session_start"><?php esc_html_e( 'Session start', 'slotkeeper' ); ?></label></th>
							<td>
								<input type="datetime-local" id="sk_res_session_start" name="session_start">
								<span class="description"><?php esc_html_e( 'Classes only.', 'slotkeeper' ); ?></span>
							</td>
						</tr>
						<tr class="sk-row-session">
							<th scope="row"><label for="sk_res_session_end"><?php esc_html_e( 'Session end', 'slotkeeper' ); ?></label></th>
							<td><input type="datetime-local" id="sk_res_session_end" name="session_end"></td>
						</tr>
					</table>
					<?php submit_button( __( 'Add resource', 'slotkeeper' ), 'primary', 'sk_resource_submit' ); ?>
				</form>
				<script>
				( function () {
					var typeSelect = document.getElementById( 'sk_res_type' );
					if ( ! typeSelect ) {
						return;
					}
					function toggle() {
						var isClass = typeSelect.value === 'classes';
						var isTimed = typeSelect.value === 'appointments' || typeSelect.value === 'reservations';
						document.querySelectorAll( '.sk-row-session' ).forEach( function ( row ) {
							row.style.display = isClass ? '' : 'none';
						} );
						document.querySelectorAll( '.sk-row-duration' ).forEach( function ( row ) {
							row.style.display = isTimed ? '' : 'none';
						} );
					}
					typeSelect.addEventListener( 'change', toggle );
					toggle();
				} )();
				</script>

			<?php endif; ?>

			<h2><?php esc_html_e( 'Existing resources', 'slotkeeper' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Type', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Capacity', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Status', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'slotkeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $resources ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No resources yet.', 'slotkeeper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $resources as $resource ) : ?>
						<tr>
							<td><?php echo esc_html( $resource['name'] ); ?></td>
							<td><?php echo esc_html( $resource['type'] ); ?></td>
							<td><?php echo esc_html( $resource['capacity'] ); ?></td>
							<td>
								<?php if ( ! empty( $resource['requires_payment'] ) ) : ?>
									<span class="sk-badge sk-badge-pending"><?php esc_html_e( 'Required', 'slotkeeper' ); ?></span>
								<?php else : ?>
									<span class="sk-badge sk-badge-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'active' === $resource['status'] ) : ?>
									<span class="sk-badge sk-badge-good"><?php esc_html_e( 'Active', 'slotkeeper' ); ?></span>
								<?php else : ?>
									<span class="sk-badge sk-badge-muted"><?php esc_html_e( 'Inactive', 'slotkeeper' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-availability&resource_id=' . $resource['id'] ) ); ?>"><?php esc_html_e( 'Availability', 'slotkeeper' ); ?></a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=slotkeeper-resources&sk_deactivate=' . $resource['id'] ), 'sk_deactivate_resource_' . $resource['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Deactivate this resource? Existing bookings are kept.', 'slotkeeper' ) ); ?>');"><?php esc_html_e( 'Deactivate', 'slotkeeper' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function handle_create( Resource_Repository $repo ): void {
		check_admin_referer( self::NONCE_ACTION );

		$booking_type  = sanitize_key( wp_unslash( $_POST['booking_type'] ?? '' ) );
		$resource_type = Settings::BOOKING_TYPE_TO_RESOURCE_TYPE[ $booking_type ] ?? null;

		if ( ! $resource_type || ! in_array( $booking_type, Settings::get( 'enabled_booking_types', array() ), true ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid or disabled booking type.', 'slotkeeper' ) . '</p></div>';
			return;
		}

		$price = isset( $_POST['price'] ) && '' !== $_POST['price'] ? (float) wp_unslash( $_POST['price'] ) : null;

		$data = array(
			'type'             => $resource_type,
			'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'capacity'         => max( 1, absint( $_POST['capacity'] ?? 1 ) ),
			'duration_minutes' => ! empty( $_POST['duration_minutes'] ) ? absint( $_POST['duration_minutes'] ) : null,
			'buffer_minutes'   => absint( $_POST['buffer_minutes'] ?? 0 ),
			'price_minor'      => null !== $price ? (int) round( $price * 100 ) : null,
			// A price is required for "requires payment" to mean anything —
			// silently ignore the checkbox rather than error on a free resource.
			'requires_payment' => null !== $price && ! empty( $_POST['requires_payment'] ),
		);

		if ( 'class_session' === $resource_type ) {
			$start = sanitize_text_field( wp_unslash( $_POST['session_start'] ?? '' ) );
			$end   = sanitize_text_field( wp_unslash( $_POST['session_end'] ?? '' ) );
			if ( ! $start || ! $end ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Classes need a session start and end.', 'slotkeeper' ) . '</p></div>';
				return;
			}
			$data['session_start'] = str_replace( 'T', ' ', $start ) . ':00';
			$data['session_end']   = str_replace( 'T', ' ', $end ) . ':00';
		}

		if ( empty( $data['name'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Name is required.', 'slotkeeper' ) . '</p></div>';
			return;
		}

		$repo->create( $data );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Resource added.', 'slotkeeper' ) . '</p></div>';
	}
}
