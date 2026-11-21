<?php
/**
 * Admin renewal and expiry report.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Admin_Renewals
 */
class MMS_Admin_Renewals {

	const PAGE = 'mms-renewals';

	/**
	 * Render the renewal report.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to view renewals.', 'mms-membership' ), 403 );
		}

		global $wpdb;

		$app     = MMS_DB::table( MMS_DB::APPLICATION );
		$meta    = MMS_DB::table( MMS_DB::APPLICATION_META );
		$payment = MMS_DB::table( MMS_DB::PAYMENT );
		$log     = MMS_DB::table( MMS_DB::ACTIVITY_LOG );

		$now       = current_time( 'mysql' );
		$in_30     = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+30 days' )->format( 'Y-m-d H:i:s' );
		$year_from = current_time( 'Y' ) . '-01-01 00:00:00';

		$renewals = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_count, COALESCE(SUM(amount), 0) AS total_amount FROM {$payment} WHERE status = 'paid' AND kind = 'renewal' AND paid_at >= %s",
				$year_from
			),
			ARRAY_A
		);

		$expiring = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$app} WHERE status = %s AND expires_at IS NOT NULL AND expires_at BETWEEN %s AND %s",
				MMS_Status::ACTIVE,
				$now,
				$in_30
			)
		);

		$expired = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$app} WHERE status = %s", MMS_Status::EXPIRED )
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.*, a.membership_no, a.reference, a.membership_type, a.started_at, a.expires_at, m.company_name
			FROM {$payment} p
			INNER JOIN {$app} a ON a.id = p.application_id
			LEFT JOIN {$meta} m ON m.application_id = a.id
			WHERE p.status = 'paid' AND p.kind = 'renewal'
			ORDER BY COALESCE(p.paid_at, p.created_at) DESC, p.id DESC
			LIMIT 100",
			ARRAY_A
		);

		$actions = array(
			'renewal_started',
			'renewal_payment_received',
			'membership_renewed',
			'membership_expired',
			'expiry_reminder_30',
			'expiry_reminder_14',
			'expiry_reminder_7',
			'expiry_reminder_0',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
		$activity     = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT l.*, a.membership_no, a.reference, m.company_name
				FROM {$log} l
				LEFT JOIN {$app} a ON a.id = l.application_id
				LEFT JOIN {$meta} m ON m.application_id = a.id
				WHERE l.action IN ({$placeholders})
				ORDER BY l.id DESC
				LIMIT 50",
				$actions
			),
			ARRAY_A
		);

		$labels = array(
			'renewal_started'          => __( 'Renewal bill created', 'mms-membership' ),
			'renewal_payment_received' => __( 'Renewal payment received', 'mms-membership' ),
			'membership_renewed'       => __( 'Membership renewed', 'mms-membership' ),
			'membership_expired'       => __( 'Membership expired', 'mms-membership' ),
			'expiry_reminder_30'       => __( '30-day reminder sent', 'mms-membership' ),
			'expiry_reminder_14'       => __( '14-day reminder sent', 'mms-membership' ),
			'expiry_reminder_7'        => __( '7-day reminder sent', 'mms-membership' ),
			'expiry_reminder_0'        => __( 'Expiry-day notice sent', 'mms-membership' ),
		);

		$next_run = wp_next_scheduled( MMS_Renewals::CRON_HOOK );

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero mms-hero-compact">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'Renewals & expiry', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub"><?php esc_html_e( 'Renewal payments, reminder delivery and expired memberships. The expiry check runs daily at 02:00 site time through WP-Cron.', 'mms-membership' ); ?></p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Applications::PAGE ) ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Company List', 'mms-membership' ); ?></a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Dashboard', 'mms-membership' ); ?></a>
				</div>
			</div>

			<div class="mms-stat-grid">
				<div class="mms-stat">
					<div class="mms-stat-top">
						<span class="mms-stat-label"><?php esc_html_e( 'Renewals this year', 'mms-membership' ); ?></span>
						<span class="mms-stat-icon"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span></span>
					</div>
					<span class="mms-stat-value"><?php echo esc_html( number_format_i18n( (int) ( $renewals['total_count'] ?? 0 ) ) ); ?></span>
					<span class="mms-stat-note"><?php echo esc_html( MMS_Payments::format_amount( $renewals['total_amount'] ?? 0, 'MYR' ) . ' ' . __( 'collected', 'mms-membership' ) ); ?></span>
				</div>
				<div class="mms-stat is-warn">
					<div class="mms-stat-top">
						<span class="mms-stat-label"><?php esc_html_e( 'Expiring in 30 days', 'mms-membership' ); ?></span>
						<span class="mms-stat-icon"><span class="dashicons dashicons-clock" aria-hidden="true"></span></span>
					</div>
					<span class="mms-stat-value"><?php echo esc_html( number_format_i18n( $expiring ) ); ?></span>
					<span class="mms-stat-note"><?php esc_html_e( 'Reminder emails are sent automatically.', 'mms-membership' ); ?></span>
				</div>
				<div class="mms-stat is-danger">
					<div class="mms-stat-top">
						<span class="mms-stat-label"><?php esc_html_e( 'Currently expired', 'mms-membership' ); ?></span>
						<span class="mms-stat-icon"><span class="dashicons dashicons-warning" aria-hidden="true"></span></span>
					</div>
					<span class="mms-stat-value"><?php echo esc_html( number_format_i18n( $expired ) ); ?></span>
					<span class="mms-stat-note"><?php esc_html_e( 'Renewal bills can be issued from the member account.', 'mms-membership' ); ?></span>
				</div>
				<div class="mms-stat is-ok">
					<div class="mms-stat-top">
						<span class="mms-stat-label"><?php esc_html_e( 'Next expiry check', 'mms-membership' ); ?></span>
						<span class="mms-stat-icon"><span class="dashicons dashicons-backup" aria-hidden="true"></span></span>
					</div>
					<span class="mms-stat-value mms-stat-value-sm"><?php echo $next_run ? esc_html( wp_date( 'j M Y, H:i', $next_run ) ) : esc_html__( 'Not scheduled', 'mms-membership' ); ?></span>
					<span class="mms-stat-note"><?php esc_html_e( 'WP-Cron, site time.', 'mms-membership' ); ?></span>
				</div>
			</div>

			<h2 class="mms-section-title"><?php esc_html_e( 'Renewal payments', 'mms-membership' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<div class="mms-table-card"><p class="mms-table-empty"><?php esc_html_e( 'No paid renewals recorded yet.', 'mms-membership' ); ?></p></div>
			<?php else : ?>
				<div class="mms-table-card">
				<table class="widefat striped mms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Paid', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Member', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Company', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Invoice', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Renewed term', 'mms-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$type      = MMS_Membership_Types::get_type( $row['membership_type'] );
							$member    = '' !== (string) $row['membership_no'] ? $row['membership_no'] : ( '' !== (string) $row['reference'] ? $row['reference'] : '#' . (int) $row['application_id'] );
							$detail    = add_query_arg(
								array(
									'page'        => MMS_Admin_Applications::PAGE,
									'application' => (int) $row['application_id'],
								),
								admin_url( 'admin.php' )
							);
							$paid_date = $row['paid_at'] ? $row['paid_at'] : $row['created_at'];
							?>
							<tr>
								<td><?php echo esc_html( $paid_date ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $paid_date ) : '—' ); ?></td>
								<td><a href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( $member ); ?></a></td>
								<td><?php echo esc_html( (string) $row['company_name'] ); ?></td>
								<td><?php echo esc_html( $row['invoice_no'] ); ?></td>
								<td><?php echo esc_html( MMS_Payments::format_amount( $row['amount'], $row['currency'] ) ); ?></td>
								<td>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: start date, 2: end date, 3: membership type */
											__( '%1$s to %2$s (%3$s)', 'mms-membership' ),
											$row['started_at'] ? mysql2date( get_option( 'date_format' ), $row['started_at'] ) : '—',
											$row['expires_at'] ? mysql2date( get_option( 'date_format' ), $row['expires_at'] ) : '—',
											$type ? $type['label'] : $row['membership_type']
										)
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>

			<h2 class="mms-section-title"><?php esc_html_e( 'Renewal & expiry activity', 'mms-membership' ); ?></h2>
			<?php if ( empty( $activity ) ) : ?>
				<div class="mms-table-card"><p class="mms-table-empty"><?php esc_html_e( 'No renewal or expiry activity recorded yet.', 'mms-membership' ); ?></p></div>
			<?php else : ?>
				<div class="mms-table-card">
				<table class="widefat striped mms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Event', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Member', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Company', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Details', 'mms-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $activity as $item ) : ?>
							<?php
							$member = '' !== (string) $item['membership_no']
								? $item['membership_no']
								: ( '' !== (string) $item['reference'] ? $item['reference'] : '#' . (int) $item['application_id'] );
							$detail = add_query_arg(
								array(
									'page'        => MMS_Admin_Applications::PAGE,
									'application' => (int) $item['application_id'],
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><?php echo esc_html( $item['created_at'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) : '—' ); ?></td>
								<td><?php echo esc_html( isset( $labels[ $item['action'] ] ) ? $labels[ $item['action'] ] : $item['action'] ); ?></td>
								<td><a href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( $member ); ?></a></td>
								<td><?php echo esc_html( (string) $item['company_name'] ); ?></td>
								<td><?php echo esc_html( (string) $item['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
