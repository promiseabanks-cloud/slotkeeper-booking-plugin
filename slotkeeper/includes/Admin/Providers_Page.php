<?php
/**
 * Manage providers — whoever performs the work (a stylist, therapist,
 * trainer). Services (resources) are assigned to a provider on the Resources
 * screen and then share that provider's schedule and calendar, so one stylist
 * offering many services keeps a single set of hours and can't be booked into
 * two services at once.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Repositories\Provider_Repository;
use SlotKeeper\Repositories\Resource_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Providers_Page {

	private const NONCE_ACTION = 'sk_save_provider';

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		$repo           = new Provider_Repository();
		$resources_repo = new Resource_Repository();

		if ( isset( $_POST['sk_provider_submit'] ) ) {
			$this->handle_create( $repo );
		}

		if ( isset( $_GET['sk_deactivate_provider'] ) && check_admin_referer( 'sk_deactivate_provider_' . absint( $_GET['sk_deactivate_provider'] ) ) ) {
			$repo->deactivate( absint( $_GET['sk_deactivate_provider'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Provider deactivated. The services they offered keep their bookings but no longer take new ones until reassigned.', 'slotkeeper' ) . '</p></div>';
		}

		$providers = $repo->all();

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-providers' ); ?>

			<p class="description"><?php esc_html_e( 'A provider is whoever performs the booking — a stylist, therapist, or trainer. Assign services to a provider on the Resources screen; they then share one schedule and one calendar.', 'slotkeeper' ); ?></p>

			<h2><?php esc_html_e( 'Add a provider', 'slotkeeper' ); ?></h2>
			<form method="post" class="sk-form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="text" name="name" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g. Amaka', 'slotkeeper' ); ?>">
				<?php submit_button( __( 'Add provider', 'slotkeeper' ), 'primary', 'sk_provider_submit', false ); ?>
			</form>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Providers', 'slotkeeper' ); ?></h2>
			<table class="widefat striped" style="max-width:720px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Services', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Schedule', 'slotkeeper' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'slotkeeper' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $providers ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No providers yet.', 'slotkeeper' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $providers as $provider ) : ?>
						<tr>
							<td><?php echo esc_html( $provider['name'] ); ?></td>
							<td><?php echo (int) count( $resources_repo->active_ids_for_provider( (int) $provider['id'] ) ); ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-availability&scope=provider&provider_id=' . $provider['id'] ) ); ?>"><?php esc_html_e( 'Edit hours', 'slotkeeper' ); ?></a></td>
							<td><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=slotkeeper-providers&sk_deactivate_provider=' . $provider['id'] ), 'sk_deactivate_provider_' . $provider['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Deactivate this provider?', 'slotkeeper' ) ); ?>');"><?php esc_html_e( 'Deactivate', 'slotkeeper' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function handle_create( Provider_Repository $repo ): void {
		check_admin_referer( self::NONCE_ACTION );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'A name is required.', 'slotkeeper' ) . '</p></div>';
			return;
		}
		$repo->create( $name );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Provider added. Set their weekly hours under Availability.', 'slotkeeper' ) . '</p></div>';
	}
}
