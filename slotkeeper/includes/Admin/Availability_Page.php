<?php
/**
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Repositories\Availability_Repository;
use SlotKeeper\Repositories\Resource_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Availability_Page {

	private const RULE_NONCE      = 'sk_save_rule';
	private const EXCEPTION_NONCE = 'sk_save_exception';

	private const DAYS = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		$resources_repo     = new Resource_Repository();
		$availability_repo  = new Availability_Repository();
		$resources          = $resources_repo->all();

		if ( empty( $resources ) ) {
			$this->render_notice( __( 'Add a resource first.', 'slotkeeper' ) );
			return;
		}

		$resource_id = absint( $_GET['resource_id'] ?? ( $resources[0]['id'] ?? 0 ) );
		$resource    = $resource_id ? $resources_repo->find( $resource_id ) : null;

		if ( ! $resource ) {
			$this->render_notice( __( 'Resource not found.', 'slotkeeper' ) );
			return;
		}

		if ( isset( $_POST['sk_rule_submit'] ) ) {
			$this->handle_add_rule( $availability_repo, $resource_id );
		}
		if ( isset( $_POST['sk_exception_submit'] ) ) {
			$this->handle_add_exception( $availability_repo, $resource_id );
		}
		if ( isset( $_GET['sk_delete_rule'] ) && check_admin_referer( 'sk_delete_rule_' . absint( $_GET['sk_delete_rule'] ) ) ) {
			$availability_repo->delete_rule( absint( $_GET['sk_delete_rule'] ) );
		}
		if ( isset( $_GET['sk_delete_exception'] ) && check_admin_referer( 'sk_delete_exception_' . absint( $_GET['sk_delete_exception'] ) ) ) {
			$availability_repo->delete_exception( absint( $_GET['sk_delete_exception'] ) );
		}

		$rules      = $availability_repo->rules_for( $resource_id );
		$exceptions = $availability_repo->exceptions_for( $resource_id, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+1 year' ) ) );

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-availability' ); ?>

			<form method="get" style="margin-bottom:16px;">
				<input type="hidden" name="page" value="slotkeeper-availability">
				<label for="sk_resource_select"><?php esc_html_e( 'Resource:', 'slotkeeper' ); ?></label>
				<select id="sk_resource_select" name="resource_id" onchange="this.form.submit()">
					<?php foreach ( $resources as $r ) : ?>
						<option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $resource_id, $r['id'] ); ?>><?php echo esc_html( $r['name'] ); ?> (<?php echo esc_html( $r['type'] ); ?>)</option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php if ( 'class_session' === $resource['type'] ) : ?>

				<p class="description"><?php esc_html_e( 'Classes use their own fixed session start/end (set when you created the resource) — weekly rules and exceptions don’t apply here.', 'slotkeeper' ); ?></p>

			<?php else : ?>

				<h2><?php esc_html_e( 'Weekly hours', 'slotkeeper' ); ?></h2>
				<table class="widefat striped" style="max-width:640px;">
					<thead><tr><th><?php esc_html_e( 'Day', 'slotkeeper' ); ?></th><th><?php esc_html_e( 'Open', 'slotkeeper' ); ?></th><th><?php esc_html_e( 'Close', 'slotkeeper' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php if ( empty( $rules ) ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No weekly hours set — this resource has no open windows yet.', 'slotkeeper' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rules as $rule ) : ?>
							<tr>
								<td><?php echo esc_html( self::DAYS[ (int) $rule['day_of_week'] ] ); ?></td>
								<td><?php echo esc_html( $rule['start_time'] ); ?></td>
								<td><?php echo esc_html( $rule['end_time'] ); ?></td>
								<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=slotkeeper-availability&resource_id=' . $resource_id . '&sk_delete_rule=' . $rule['id'] ), 'sk_delete_rule_' . $rule['id'] ) ); ?>"><?php esc_html_e( 'Remove', 'slotkeeper' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" class="sk-form" style="margin-top:12px;">
					<?php wp_nonce_field( self::RULE_NONCE ); ?>
					<select name="day_of_week">
						<?php foreach ( self::DAYS as $num => $label ) : ?>
							<option value="<?php echo esc_attr( $num ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="time" name="start_time" value="09:00" required>
					<?php esc_html_e( 'to', 'slotkeeper' ); ?>
					<input type="time" name="end_time" value="17:00" required>
					<?php submit_button( __( 'Add hours', 'slotkeeper' ), 'secondary', 'sk_rule_submit', false ); ?>
				</form>

				<h2 style="margin-top:32px;"><?php esc_html_e( 'Exceptions (holidays, special hours)', 'slotkeeper' ); ?></h2>
				<table class="widefat striped" style="max-width:640px;">
					<thead><tr><th><?php esc_html_e( 'Date', 'slotkeeper' ); ?></th><th><?php esc_html_e( 'Detail', 'slotkeeper' ); ?></th><th></th></tr></thead>
					<tbody>
						<?php if ( empty( $exceptions ) ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'None set.', 'slotkeeper' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $exceptions as $exception ) : ?>
							<tr>
								<td><?php echo esc_html( $exception['exception_date'] ); ?></td>
								<td><?php echo $exception['is_closed'] ? esc_html__( 'Closed', 'slotkeeper' ) : esc_html( $exception['start_time'] . '–' . $exception['end_time'] ); ?> <?php echo esc_html( $exception['reason'] ); ?></td>
								<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=slotkeeper-availability&resource_id=' . $resource_id . '&sk_delete_exception=' . $exception['id'] ), 'sk_delete_exception_' . $exception['id'] ) ); ?>"><?php esc_html_e( 'Remove', 'slotkeeper' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<form method="post" class="sk-form" style="margin-top:12px;">
					<?php wp_nonce_field( self::EXCEPTION_NONCE ); ?>
					<input type="date" name="exception_date" required>
					<label><input type="checkbox" name="is_closed" value="1" checked onchange="document.getElementById('sk-exc-hours').style.display=this.checked?'none':'inline';"> <?php esc_html_e( 'Closed all day', 'slotkeeper' ); ?></label>
					<span id="sk-exc-hours" style="display:none;">
						<input type="time" name="exc_start_time"> <?php esc_html_e( 'to', 'slotkeeper' ); ?> <input type="time" name="exc_end_time">
					</span>
					<input type="text" name="reason" placeholder="<?php esc_attr_e( 'Reason (optional)', 'slotkeeper' ); ?>">
					<?php submit_button( __( 'Add exception', 'slotkeeper' ), 'secondary', 'sk_exception_submit', false ); ?>
				</form>

			<?php endif; ?>
		</div>
		<?php
	}

	private function render_notice( string $message ): void {
		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-availability' ); ?>
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private function handle_add_rule( Availability_Repository $repo, int $resource_id ): void {
		check_admin_referer( self::RULE_NONCE );
		$repo->add_rule(
			$resource_id,
			absint( $_POST['day_of_week'] ?? 0 ),
			sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '09:00' ) ) . ':00',
			sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '17:00' ) ) . ':00'
		);
	}

	private function handle_add_exception( Availability_Repository $repo, int $resource_id ): void {
		check_admin_referer( self::EXCEPTION_NONCE );
		$is_closed = ! empty( $_POST['is_closed'] );
		$repo->add_exception(
			$resource_id,
			sanitize_text_field( wp_unslash( $_POST['exception_date'] ?? '' ) ),
			$is_closed,
			$is_closed ? null : sanitize_text_field( wp_unslash( $_POST['exc_start_time'] ?? '' ) ) . ':00',
			$is_closed ? null : sanitize_text_field( wp_unslash( $_POST['exc_end_time'] ?? '' ) ) . ':00',
			sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) )
		);
	}
}
