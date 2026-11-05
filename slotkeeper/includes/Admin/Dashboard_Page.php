<?php
/**
 * The plugin's new landing page (SlotKeeper > Dashboard, now the top-level
 * menu target — Bookings moved to its own `slotkeeper-bookings` slug to
 * make room). Built directly in response to founder feedback that the
 * admin felt "too bare" and that it wasn't obvious where things lived —
 * the setup checklist here exists specifically to surface exactly that.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Payments\Gateway_Registry;
use SlotKeeper\Repositories\Booking_Repository;
use SlotKeeper\Repositories\Payment_Repository;
use SlotKeeper\Repositories\Resource_Repository;
use SlotKeeper\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Page {

	public function render(): void {
		if ( ! current_user_can( 'sk_manage_bookings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'slotkeeper' ) );
		}

		$bookings_repo = new Booking_Repository();
		$resources     = ( new Resource_Repository() )->all();
		$settings      = Settings::all();

		$today_count    = $bookings_repo->count_today();
		$week_count     = $bookings_repo->count_upcoming_within_days( 7 );
		$resource_count = count( $resources );
		$gateway        = Gateway_Registry::active();
		$revenue_minor  = $gateway ? ( new Payment_Repository() )->sum_paid_this_month() : null;

		$checklist = $this->build_checklist( $settings, $resource_count );
		$activity  = $bookings_repo->recent( 8 );

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper' ); ?>

			<div class="sk-stat-grid">
				<div class="sk-stat-card">
					<span class="dashicons dashicons-clock"></span>
					<div class="sk-stat-value"><?php echo esc_html( $today_count ); ?></div>
					<div class="sk-stat-label"><?php esc_html_e( "Today's bookings", 'slotkeeper' ); ?></div>
				</div>
				<div class="sk-stat-card">
					<span class="dashicons dashicons-calendar-alt"></span>
					<div class="sk-stat-value"><?php echo esc_html( $week_count ); ?></div>
					<div class="sk-stat-label"><?php esc_html_e( 'Next 7 days', 'slotkeeper' ); ?></div>
				</div>
				<div class="sk-stat-card">
					<span class="dashicons dashicons-groups"></span>
					<div class="sk-stat-value"><?php echo esc_html( $resource_count ); ?></div>
					<div class="sk-stat-label"><?php esc_html_e( 'Active resources', 'slotkeeper' ); ?></div>
				</div>
				<?php if ( null !== $revenue_minor ) : ?>
					<div class="sk-stat-card">
						<span class="dashicons dashicons-money-alt"></span>
						<div class="sk-stat-value"><?php echo esc_html( $this->money( $revenue_minor, $settings['currency'] ) ); ?></div>
						<div class="sk-stat-label"><?php esc_html_e( 'Collected this month', 'slotkeeper' ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<div class="sk-dash-columns">
				<div class="sk-dash-main">
					<div class="sk-card">
						<h2><?php esc_html_e( 'Recent activity', 'slotkeeper' ); ?></h2>
						<?php $this->render_activity( $activity ); ?>
					</div>
				</div>

				<div class="sk-dash-side">
					<div class="sk-card">
						<h2><?php esc_html_e( 'Getting set up', 'slotkeeper' ); ?></h2>
						<ul class="sk-checklist">
							<?php foreach ( $checklist as $item ) : ?>
								<li class="<?php echo $item['done'] ? 'sk-check-done' : 'sk-check-pending'; ?>">
									<span class="dashicons <?php echo $item['done'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span>
									<span>
										<?php echo esc_html( $item['label'] ); ?>
										<?php if ( ! $item['done'] && $item['action_url'] ) : ?>
											— <a href="<?php echo esc_url( $item['action_url'] ); ?>"><?php echo esc_html( $item['action_label'] ); ?></a>
										<?php endif; ?>
									</span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>

					<div class="sk-card">
						<h2><?php esc_html_e( 'Quick links', 'slotkeeper' ); ?></h2>
						<div class="sk-quick-links">
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-resources' ) ); ?>"><?php esc_html_e( 'Add a resource', 'slotkeeper' ); ?></a>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-bookings' ) ); ?>"><?php esc_html_e( 'Create a booking', 'slotkeeper' ); ?></a>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-integrations' ) ); ?>"><?php esc_html_e( 'Connect Paystack / WhatsApp', 'slotkeeper' ); ?></a>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<int, array{label:string,done:bool,action_url:?string,action_label:?string}>
	 */
	private function build_checklist( array $settings, int $resource_count ): array {
		global $wpdb;

		$has_booking_type = ! empty( $settings['enabled_booking_types'] );
		$has_resource      = $resource_count > 0;
		$has_hours         = (bool) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sk_availability_rules" ); // phpcs:ignore -- no user input, fixed table name.
		$has_widget_page   = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s", // phpcs:ignore
				'%' . $wpdb->esc_like( '[slotkeeper_booking' ) . '%'
			)
		);
		$has_manage_page = ! empty( $settings['manage_booking_page_id'] ) && 'publish' === get_post_status( $settings['manage_booking_page_id'] );

		return array(
			array(
				'label'        => __( 'Turn on at least one booking type', 'slotkeeper' ),
				'done'         => $has_booking_type,
				'action_url'   => admin_url( 'admin.php?page=slotkeeper-settings' ),
				'action_label' => __( 'Go to Settings', 'slotkeeper' ),
			),
			array(
				'label'        => __( 'Add a resource (a service, table, item, or class)', 'slotkeeper' ),
				'done'         => $has_resource,
				'action_url'   => admin_url( 'admin.php?page=slotkeeper-resources' ),
				'action_label' => __( 'Add one', 'slotkeeper' ),
			),
			array(
				'label'        => __( 'Set weekly hours for at least one resource', 'slotkeeper' ),
				'done'         => $has_hours,
				'action_url'   => admin_url( 'admin.php?page=slotkeeper-availability' ),
				'action_label' => __( 'Set hours', 'slotkeeper' ),
			),
			array(
				'label'        => __( 'Put the booking widget on a page ([slotkeeper_booking])', 'slotkeeper' ),
				'done'         => $has_widget_page,
				'action_url'   => admin_url( 'edit.php?post_type=page' ),
				'action_label' => __( 'View pages', 'slotkeeper' ),
			),
			array(
				'label'        => __( 'Set a manage-booking page so cancel links work', 'slotkeeper' ),
				'done'         => $has_manage_page,
				'action_url'   => admin_url( 'admin.php?page=slotkeeper-settings' ),
				'action_label' => __( 'Set it', 'slotkeeper' ),
			),
		);
	}

	private function render_activity( array $bookings ): void {
		if ( empty( $bookings ) ) {
			echo '<p class="sk-empty-note">' . esc_html__( 'No bookings yet — activity will show up here once customers start booking.', 'slotkeeper' ) . '</p>';
			return;
		}

		$resources_repo = new Resource_Repository();
		$customers_repo = new \SlotKeeper\Repositories\Customer_Repository();

		echo '<ul class="sk-activity-list">';
		foreach ( $bookings as $booking ) {
			$resource = $resources_repo->find( (int) $booking['resource_id'] );
			$customer = $customers_repo->find( (int) $booking['customer_id'] );
			$ago      = human_time_diff( strtotime( $booking['updated_at'] ), current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

			printf(
				'<li><span class="sk-badge sk-badge-%1$s">%2$s</span> <strong>%3$s</strong> — %4$s with %5$s <span class="sk-activity-time">%6$s</span></li>',
				esc_attr( $this->badge_class( $booking['status'] ) ),
				esc_html( ucfirst( $booking['status'] ) ),
				esc_html( $customer['name'] ?? __( 'A customer', 'slotkeeper' ) ),
				esc_html( $this->action_word( $booking['status'] ) ),
				esc_html( $resource['name'] ?? '' ),
				/* translators: %s: time since, e.g. "2 hours" */
				esc_html( sprintf( __( '%s ago', 'slotkeeper' ), $ago ) )
			);
		}
		echo '</ul>';
	}

	private function action_word( string $status ): string {
		return array(
			'pending'   => __( 'requested', 'slotkeeper' ),
			'confirmed' => __( 'booked', 'slotkeeper' ),
			'cancelled' => __( 'cancelled', 'slotkeeper' ),
			'completed' => __( 'completed', 'slotkeeper' ),
			'no_show'   => __( 'no-show', 'slotkeeper' ),
		)[ $status ] ?? $status;
	}

	private function badge_class( string $status ): string {
		return array(
			'pending'   => 'pending',
			'confirmed' => 'good',
			'cancelled' => 'warn',
			'completed' => 'good',
			'no_show'   => 'warn',
		)[ $status ] ?? 'muted';
	}

	private function money( int $minor, string $currency ): string {
		$symbol = array( 'NGN' => '₦', 'USD' => '$', 'GBP' => '£', 'EUR' => '€' )[ $currency ] ?? ( $currency . ' ' );
		return $symbol . number_format( $minor / 100 );
	}
}
