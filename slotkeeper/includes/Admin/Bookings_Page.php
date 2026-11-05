<?php
/**
 * The admin path proves the whole engine end to end (create → persist →
 * notify) before any public-facing widget exists — see 00_Flow_Map.md build
 * order.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Domain\Availability_Calculator;
use SlotKeeper\Domain\Booking_Service;
use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Customer_Repository;
use SlotKeeper\Repositories\Resource_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bookings_Page {

	private const NONCE_ACTION = 'sk_create_booking';

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		$resources_repo = new Resource_Repository();
		$bookings_repo  = new Booking_Repository();
		$service        = new Booking_Service();

		if ( isset( $_POST['sk_booking_submit'] ) ) {
			$this->handle_create( $service );
		}

		if ( isset( $_GET['sk_action'], $_GET['booking_id'] ) && check_admin_referer( 'sk_booking_action_' . absint( $_GET['booking_id'] ) ) ) {
			$this->handle_action( sanitize_key( $_GET['sk_action'] ), absint( $_GET['booking_id'] ), $service, $bookings_repo );
		}

		$resources   = $resources_repo->all();
		$resource_id = absint( $_GET['resource_id'] ?? 0 );
		$resource    = $resource_id ? $resources_repo->find( $resource_id ) : null;
		$slots       = array();

		if ( $resource && in_array( $resource['type'], array( 'staff', 'table' ), true ) ) {
			$slots = ( new Availability_Calculator() )->open_slots(
				$resource_id,
				gmdate( 'Y-m-d' ),
				gmdate( 'Y-m-d', strtotime( '+14 days' ) )
			);
		}

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-bookings' ); ?>

			<?php if ( empty( $resources ) ) : ?>
				<p>
					<?php
					printf(
						wp_kses_post( __( 'No resources yet — <a href="%s">add one first</a>.', 'slotkeeper' ) ),
						esc_url( admin_url( 'admin.php?page=slotkeeper-resources' ) )
					);
					?>
				</p>
			<?php else : ?>

				<h2><?php esc_html_e( 'Create a booking', 'slotkeeper' ); ?></h2>
				<form method="get" style="margin-bottom:12px;">
					<input type="hidden" name="page" value="slotkeeper-bookings">
					<select name="resource_id" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( '— choose a resource —', 'slotkeeper' ); ?></option>
						<?php foreach ( $resources as $r ) : ?>
							<option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $resource_id, $r['id'] ); ?>><?php echo esc_html( $r['name'] ); ?> (<?php echo esc_html( $r['type'] ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</form>

				<?php if ( $resource ) : ?>
					<form method="post" class="sk-form">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="resource_id" value="<?php echo esc_attr( $resource_id ); ?>">

						<?php if ( in_array( $resource['type'], array( 'staff', 'table' ), true ) ) : ?>
							<p>
								<label for="sk_slot"><?php esc_html_e( 'Slot', 'slotkeeper' ); ?></label>
								<select id="sk_slot" name="slot" required>
									<?php if ( empty( $slots ) ) : ?>
										<option value=""><?php esc_html_e( 'No open slots in the next 14 days — check availability hours.', 'slotkeeper' ); ?></option>
									<?php endif; ?>
									<?php foreach ( $slots as $slot ) : ?>
										<option value="<?php echo esc_attr( $slot['start'] . '|' . $slot['end'] ); ?>">
											<?php echo esc_html( wp_date( 'D, j M Y g:ia', strtotime( $slot['start'] ) ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
							<?php if ( 'table' === $resource['type'] ) : ?>
								<p><label for="sk_seats"><?php esc_html_e( 'Party size', 'slotkeeper' ); ?></label> <input type="number" id="sk_seats" name="seats" min="1" value="1" style="width:80px;"></p>
							<?php endif; ?>

						<?php elseif ( 'rental_item' === $resource['type'] ) : ?>
							<p><label for="sk_start"><?php esc_html_e( 'From', 'slotkeeper' ); ?></label> <input type="datetime-local" id="sk_start" name="range_start" required></p>
							<p><label for="sk_end"><?php esc_html_e( 'To', 'slotkeeper' ); ?></label> <input type="datetime-local" id="sk_end" name="range_end" required></p>

						<?php elseif ( 'class_session' === $resource['type'] ) : ?>
							<?php $remaining = ( new Availability_Calculator() )->class_remaining_capacity( $resource_id ); ?>
							<p><?php echo esc_html( sprintf( __( '%d seat(s) remaining for this session.', 'slotkeeper' ), $remaining ?? 0 ) ); ?></p>
							<p><label for="sk_seats_class"><?php esc_html_e( 'Seats to book', 'slotkeeper' ); ?></label> <input type="number" id="sk_seats_class" name="seats" min="1" value="1" style="width:80px;"></p>
						<?php endif; ?>

						<p><label for="sk_name"><?php esc_html_e( 'Customer name', 'slotkeeper' ); ?></label> <input type="text" id="sk_name" name="name" class="regular-text" required></p>
						<p><label for="sk_email"><?php esc_html_e( 'Email', 'slotkeeper' ); ?></label> <input type="email" id="sk_email" name="email" class="regular-text"></p>
						<p><label for="sk_phone"><?php esc_html_e( 'Phone', 'slotkeeper' ); ?></label> <input type="text" id="sk_phone" name="phone" class="regular-text" placeholder="+234..."></p>
						<p><label for="sk_notes"><?php esc_html_e( 'Notes', 'slotkeeper' ); ?></label> <textarea id="sk_notes" name="notes" class="large-text" rows="2"></textarea></p>

						<?php submit_button( __( 'Create booking', 'slotkeeper' ), 'primary', 'sk_booking_submit' ); ?>
					</form>
				<?php endif; ?>

			<?php endif; ?>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'Upcoming bookings', 'slotkeeper' ); ?></h2>
			<?php $this->render_list( $bookings_repo->upcoming(), $resources_repo ); ?>
		</div>
		<?php
	}

	private function render_list( array $bookings, Resource_Repository $resources_repo ): void {
		if ( empty( $bookings ) ) {
			echo '<p>' . esc_html__( 'No upcoming bookings.', 'slotkeeper' ) . '</p>';
			return;
		}

		$customers_repo = new Customer_Repository();

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'slotkeeper' ); ?></th>
					<th><?php esc_html_e( 'Resource', 'slotkeeper' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'slotkeeper' ); ?></th>
					<th><?php esc_html_e( 'Status', 'slotkeeper' ); ?></th>
					<th><?php esc_html_e( 'Payment', 'slotkeeper' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'slotkeeper' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $bookings as $booking ) : ?>
					<?php
					$resource = $resources_repo->find( (int) $booking['resource_id'] );
					$customer = $customers_repo->find( (int) $booking['customer_id'] );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( 'D, j M Y g:ia', strtotime( $booking['start_datetime'] ) ) ); ?></td>
						<td><?php echo esc_html( $resource['name'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $customer['name'] ?? '—' ); ?></td>
						<td><span class="sk-badge sk-badge-<?php echo esc_attr( $this->status_badge_class( $booking['status'] ) ); ?>"><?php echo esc_html( ucfirst( $booking['status'] ) ); ?></span></td>
						<td><span class="sk-badge sk-badge-<?php echo esc_attr( $this->payment_badge_class( $booking['payment_status'] ) ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $booking['payment_status'] ) ) ); ?></span></td>
						<td>
							<?php if ( 'pending' === $booking['status'] ) : ?>
								<a href="<?php echo esc_url( $this->action_url( $booking['id'], 'confirm' ) ); ?>"><?php esc_html_e( 'Confirm', 'slotkeeper' ); ?></a> |
							<?php endif; ?>
							<?php if ( 'confirmed' === $booking['status'] ) : ?>
								<a href="<?php echo esc_url( $this->action_url( $booking['id'], 'complete' ) ); ?>"><?php esc_html_e( 'Mark complete', 'slotkeeper' ); ?></a> |
								<a href="<?php echo esc_url( $this->action_url( $booking['id'], 'no_show' ) ); ?>"><?php esc_html_e( 'No-show', 'slotkeeper' ); ?></a> |
							<?php endif; ?>
							<?php if ( ! in_array( $booking['status'], array( 'cancelled', 'completed' ), true ) ) : ?>
								<a href="<?php echo esc_url( $this->action_url( $booking['id'], 'cancel' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel this booking?', 'slotkeeper' ) ); ?>');"><?php esc_html_e( 'Cancel', 'slotkeeper' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function action_url( int $booking_id, string $action ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?page=slotkeeper-bookings&sk_action=' . $action . '&booking_id=' . $booking_id ),
			'sk_booking_action_' . $booking_id
		);
	}

	private function status_badge_class( string $status ): string {
		return array(
			'pending'   => 'pending',
			'confirmed' => 'good',
			'cancelled' => 'warn',
			'completed' => 'good',
			'no_show'   => 'warn',
		)[ $status ] ?? 'muted';
	}

	private function payment_badge_class( string $payment_status ): string {
		return array(
			'paid'         => 'good',
			'unpaid'       => 'pending',
			'refunded'     => 'warn',
			'not_required' => 'muted',
		)[ $payment_status ] ?? 'muted';
	}

	private function handle_action( string $action, int $booking_id, Booking_Service $service, Booking_Repository $bookings_repo ): void {
		switch ( $action ) {
			case 'confirm':
				$bookings_repo->update_status( $booking_id, 'confirmed' );
				break;
			case 'complete':
				$bookings_repo->update_status( $booking_id, 'completed' );
				break;
			case 'no_show':
				$bookings_repo->update_status( $booking_id, 'no_show' );
				break;
			case 'cancel':
				$service->cancel( $booking_id, null, null ); // Admin path — current_user_can check happens inside.
				break;
		}
	}

	private function handle_create( Booking_Service $service ): void {
		check_admin_referer( self::NONCE_ACTION );

		$resource_id = absint( $_POST['resource_id'] ?? 0 );
		$resource    = ( new Resource_Repository() )->find( $resource_id );

		if ( ! $resource ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Resource not found.', 'slotkeeper' ) . '</p></div>';
			return;
		}

		$args = array(
			'resource_id' => $resource_id,
			'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'       => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'       => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'notes'       => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'source'      => 'admin',
		);

		if ( in_array( $resource['type'], array( 'staff', 'table' ), true ) ) {
			$slot = explode( '|', sanitize_text_field( wp_unslash( $_POST['slot'] ?? '' ) ) );
			if ( 2 !== count( $slot ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Pick a slot.', 'slotkeeper' ) . '</p></div>';
				return;
			}
			$args['start_datetime'] = $slot[0];
			$args['end_datetime']   = $slot[1];
			$args['seats_booked']   = 'table' === $resource['type'] ? max( 1, absint( $_POST['seats'] ?? 1 ) ) : 1;
		} elseif ( 'rental_item' === $resource['type'] ) {
			$args['start_datetime'] = str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['range_start'] ?? '' ) ) ) . ':00';
			$args['end_datetime']   = str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['range_end'] ?? '' ) ) ) . ':00';
			$args['seats_booked']   = 1;
		} elseif ( 'class_session' === $resource['type'] ) {
			$args['start_datetime'] = $resource['session_start'];
			$args['end_datetime']   = $resource['session_end'];
			$args['seats_booked']   = max( 1, absint( $_POST['seats'] ?? 1 ) );
		}

		$result = $service->book( $args );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Booking created.', 'slotkeeper' ) . '</p></div>';
	}
}
