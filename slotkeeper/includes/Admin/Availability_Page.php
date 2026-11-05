<?php
/**
 * Edits weekly hours and date exceptions for either a **provider** (a shared
 * schedule every one of that provider's services uses) or a single
 * **resource** (its own schedule, for resources with no provider). The two
 * scopes share one UI; only the data source and the add handlers differ.
 *
 * @package SlotKeeper
 */

namespace SlotKeeper\Admin;

use SlotKeeper\Repositories\Availability_Repository;
use SlotKeeper\Repositories\Provider_Repository;
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

		$availability_repo = new Availability_Repository();
		$providers         = ( new Provider_Repository() )->all();
		$resources_repo    = new Resource_Repository();
		$resources         = $resources_repo->all();

		$scope = ( 'provider' === ( $_GET['scope'] ?? '' ) ) ? 'provider' : 'resource'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector.

		?>
		<div class="wrap sk-wrap">
			<?php Admin_Nav::render( 'slotkeeper-availability' ); ?>

			<p class="sk-scope-switch" style="margin:8px 0 16px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-availability&scope=provider' ) ); ?>" class="button <?php echo 'provider' === $scope ? 'button-primary' : ''; ?>"><?php esc_html_e( 'By provider (shared schedule)', 'slotkeeper' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=slotkeeper-availability&scope=resource' ) ); ?>" class="button <?php echo 'resource' === $scope ? 'button-primary' : ''; ?>"><?php esc_html_e( 'By individual resource', 'slotkeeper' ); ?></a>
			</p>
			<?php

			if ( 'provider' === $scope ) {
				$this->render_provider_scope( $availability_repo, $providers );
			} else {
				$this->render_resource_scope( $availability_repo, $resources_repo, $resources );
			}

			?>
		</div>
		<?php
	}

	// ---- provider scope --------------------------------------------------

	private function render_provider_scope( Availability_Repository $repo, array $providers ): void {
		if ( empty( $providers ) ) {
			printf(
				'<p>%s</p>',
				wp_kses_post(
					sprintf(
						/* translators: %s: link to the Providers screen */
						__( 'No providers yet. Add one on the <a href="%s">Providers</a> screen, then set its shared hours here.', 'slotkeeper' ),
						esc_url( admin_url( 'admin.php?page=slotkeeper-providers' ) )
					)
				)
			);
			return;
		}

		$provider_id = absint( $_GET['provider_id'] ?? ( $providers[0]['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider    = null;
		foreach ( $providers as $p ) {
			if ( (int) $p['id'] === $provider_id ) {
				$provider = $p;
				break;
			}
		}
		if ( ! $provider ) {
			$provider    = $providers[0];
			$provider_id = (int) $provider['id'];
		}

		if ( isset( $_POST['sk_rule_submit'] ) ) {
			check_admin_referer( self::RULE_NONCE );
			$repo->add_provider_rule(
				$provider_id,
				absint( $_POST['day_of_week'] ?? 0 ),
				sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '09:00' ) ) . ':00',
				sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '17:00' ) ) . ':00'
			);
		}
		if ( isset( $_POST['sk_exception_submit'] ) ) {
			check_admin_referer( self::EXCEPTION_NONCE );
			$is_closed = ! empty( $_POST['is_closed'] );
			$repo->add_provider_exception(
				$provider_id,
				sanitize_text_field( wp_unslash( $_POST['exception_date'] ?? '' ) ),
				$is_closed,
				$is_closed ? null : sanitize_text_field( wp_unslash( $_POST['exc_start_time'] ?? '' ) ) . ':00',
				$is_closed ? null : sanitize_text_field( wp_unslash( $_POST['exc_end_time'] ?? '' ) ) . ':00',
				sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) )
			);
		}
		$this->handle_deletes( $repo );

		$base = admin_url( 'admin.php?page=slotkeeper-availability&scope=provider&provider_id=' . $provider_id );
		$rules      = $repo->rules_for_provider( $provider_id );
		$exceptions = $repo->exceptions_for_provider( $provider_id, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+1 year' ) ) );

		?>
		<form method="get" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="slotkeeper-availability">
			<input type="hidden" name="scope" value="provider">
			<label for="sk_provider_select"><?php esc_html_e( 'Provider:', 'slotkeeper' ); ?></label>
			<select id="sk_provider_select" name="provider_id" onchange="this.form.submit()">
				<?php foreach ( $providers as $p ) : ?>
					<option value="<?php echo esc_attr( $p['id'] ); ?>" <?php selected( $provider_id, $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Every service assigned to this provider shares the hours below.', 'slotkeeper' ); ?></span>
		</form>
		<?php
		$this->render_hours_and_exceptions( $rules, $exceptions, $base );
	}

	// ---- resource scope --------------------------------------------------

	private function render_resource_scope( Availability_Repository $repo, Resource_Repository $resources_repo, array $resources ): void {
		if ( empty( $resources ) ) {
			echo '<p>' . esc_html__( 'Add a resource first.', 'slotkeeper' ) . '</p>';
			return;
		}

		$resource_id = absint( $_GET['resource_id'] ?? ( $resources[0]['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$resource    = $resource_id ? $resources_repo->find( $resource_id ) : null;
		if ( ! $resource ) {
			echo '<p>' . esc_html__( 'Resource not found.', 'slotkeeper' ) . '</p>';
			return;
		}

		if ( isset( $_POST['sk_rule_submit'] ) ) {
			check_admin_referer( self::RULE_NONCE );
			$repo->add_rule(
				$resource_id,
				absint( $_POST['day_of_week'] ?? 0 ),
				sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '09:00' ) ) . ':00',
				sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '17:00' ) ) . ':00'
			);
		}
		if ( isset( $_POST['sk_exception_submit'] ) ) {
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
		$this->handle_deletes( $repo );

		$base = admin_url( 'admin.php?page=slotkeeper-availability&scope=resource&resource_id=' . $resource_id );

		?>
		<form method="get" style="margin-bottom:16px;">
			<input type="hidden" name="page" value="slotkeeper-availability">
			<input type="hidden" name="scope" value="resource">
			<label for="sk_resource_select"><?php esc_html_e( 'Resource:', 'slotkeeper' ); ?></label>
			<select id="sk_resource_select" name="resource_id" onchange="this.form.submit()">
				<?php foreach ( $resources as $r ) : ?>
					<option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $resource_id, $r['id'] ); ?>><?php echo esc_html( $r['name'] ); ?> (<?php echo esc_html( $r['type'] ); ?>)</option>
				<?php endforeach; ?>
			</select>
		</form>
		<?php

		if ( ! empty( $resource['provider_id'] ) ) {
			echo '<p class="description">' . esc_html__( 'This resource follows its provider\'s shared schedule — edit it under "By provider". Rules set here are ignored while a provider is assigned.', 'slotkeeper' ) . '</p>';
			return;
		}

		if ( 'class_session' === $resource['type'] ) {
			echo '<p class="description">' . esc_html__( 'Classes use their own fixed session start/end (set when you created the resource) — weekly rules and exceptions don’t apply here.', 'slotkeeper' ) . '</p>';
			return;
		}

		$rules      = $repo->rules_for( $resource_id );
		$exceptions = $repo->exceptions_for( $resource_id, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+1 year' ) ) );
		$this->render_hours_and_exceptions( $rules, $exceptions, $base );
	}

	// ---- shared UI -------------------------------------------------------

	private function handle_deletes( Availability_Repository $repo ): void {
		if ( isset( $_GET['sk_delete_rule'] ) && check_admin_referer( 'sk_delete_rule_' . absint( $_GET['sk_delete_rule'] ) ) ) {
			$repo->delete_rule( absint( $_GET['sk_delete_rule'] ) );
		}
		if ( isset( $_GET['sk_delete_exception'] ) && check_admin_referer( 'sk_delete_exception_' . absint( $_GET['sk_delete_exception'] ) ) ) {
			$repo->delete_exception( absint( $_GET['sk_delete_exception'] ) );
		}
	}

	private function render_hours_and_exceptions( array $rules, array $exceptions, string $base ): void {
		?>
		<h2><?php esc_html_e( 'Weekly hours', 'slotkeeper' ); ?></h2>
		<table class="widefat striped" style="max-width:640px;">
			<thead><tr><th><?php esc_html_e( 'Day', 'slotkeeper' ); ?></th><th><?php esc_html_e( 'Open', 'slotkeeper' ); ?></th><th><?php esc_html_e( 'Close', 'slotkeeper' ); ?></th><th></th></tr></thead>
			<tbody>
				<?php if ( empty( $rules ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No weekly hours set yet — no open windows.', 'slotkeeper' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $rule ) : ?>
					<tr>
						<td><?php echo esc_html( self::DAYS[ (int) $rule['day_of_week'] ] ); ?></td>
						<td><?php echo esc_html( $rule['start_time'] ); ?></td>
						<td><?php echo esc_html( $rule['end_time'] ); ?></td>
						<td><a href="<?php echo esc_url( wp_nonce_url( $base . '&sk_delete_rule=' . $rule['id'], 'sk_delete_rule_' . $rule['id'] ) ); ?>"><?php esc_html_e( 'Remove', 'slotkeeper' ); ?></a></td>
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
						<td><a href="<?php echo esc_url( wp_nonce_url( $base . '&sk_delete_exception=' . $exception['id'], 'sk_delete_exception_' . $exception['id'] ) ); ?>"><?php esc_html_e( 'Remove', 'slotkeeper' ); ?></a></td>
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
		<?php
	}
}
