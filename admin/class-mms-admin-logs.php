<?php
/**
 * Admin activity log screen.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Admin_Logs
 */
class MMS_Admin_Logs {

	const PAGE     = 'mms-logs';
	const PER_PAGE = 30;

	/**
	 * Read the current filters from the query string.
	 *
	 * @return array{action:string,search:string,paged:int}
	 */
	private static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'action' => $action,
			'search' => $search,
			'paged'  => max( 1, $paged ),
		);
	}

	/**
	 * Query log rows with filters.
	 *
	 * @param array $filters Filters from current_filters().
	 * @return array{rows:array<int,object>,total:int}
	 */
	private static function fetch( $filters ) {
		global $wpdb;

		$log   = MMS_DB::table( MMS_DB::ACTIVITY_LOG );
		$app   = MMS_DB::table( MMS_DB::APPLICATION );
		$meta  = MMS_DB::table( MMS_DB::APPLICATION_META );
		$users = $wpdb->users;

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $filters['action'] ) {
			$where[]  = 'l.action = %s';
			$params[] = $filters['action'];
		}

		if ( '' !== $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(l.details LIKE %s OR l.ip_address LIKE %s OR a.reference LIKE %s OR m.company_name LIKE %s OR u.display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$from_sql  = "FROM {$log} l
			LEFT JOIN {$app} a ON a.id = l.application_id
			LEFT JOIN {$meta} m ON m.application_id = l.application_id
			LEFT JOIN {$users} u ON u.ID = l.user_id";

		$count_sql = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = ( $filters['paged'] - 1 ) * self::PER_PAGE;

		$rows_sql = "SELECT l.*, a.reference, m.company_name, u.display_name AS user_name
			{$from_sql}
			WHERE {$where_sql}
			ORDER BY l.created_at DESC, l.id DESC
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( self::PER_PAGE, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Known action labels.
	 *
	 * @return array<string,string>
	 */
	private static function action_labels() {
		return array(
			'submitted'                  => __( 'Application submitted', 'mms-membership' ),
			'decision'                   => __( 'Review decision', 'mms-membership' ),
			'document_replaced'          => __( 'Document replaced', 'mms-membership' ),
			'documents_resubmitted'      => __( 'Documents resubmitted', 'mms-membership' ),
			'document_reviewed'          => __( 'Document reviewed', 'mms-membership' ),
			'invoice_created'            => __( 'Invoice created', 'mms-membership' ),
			'payment_received'           => __( 'Payment received', 'mms-membership' ),
			'renewal_payment_received'   => __( 'Renewal payment received', 'mms-membership' ),
			'payment_recorded_manually'  => __( 'Manual payment recorded', 'mms-membership' ),
			'certificate_downloaded'     => __( 'Certificate downloaded', 'mms-membership' ),
			'email_sent'                 => __( 'Email sent', 'mms-membership' ),
			'email_failed'               => __( 'Email failed', 'mms-membership' ),
			'membership_expired'         => __( 'Membership expired', 'mms-membership' ),
			'renewal_reminder'           => __( 'Renewal reminder sent', 'mms-membership' ),
			'member_account_created'     => __( 'Member account created', 'mms-membership' ),
			'member_account_updated'     => __( 'Member account updated', 'mms-membership' ),
			'password_set'               => __( 'Member set password', 'mms-membership' ),
			'password_reset_by_admin'    => __( 'Password reset by admin', 'mms-membership' ),
		);
	}

	/**
	 * All action labels that can be filtered, including legacy actions.
	 *
	 * @return array<string,string>
	 */
	private static function filter_actions() {
		global $wpdb;

		$labels = self::action_labels();
		$found  = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . MMS_DB::table( MMS_DB::ACTIVITY_LOG ) . ' ORDER BY action ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $found as $action ) {
			if ( '' !== $action && ! isset( $labels[ $action ] ) ) {
				$labels[ $action ] = self::humanize( $action );
			}
		}

		asort( $labels );

		return $labels;
	}

	/**
	 * Human readable fallback for unknown actions.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	private static function humanize( $action ) {
		return ucwords( str_replace( array( '_', '-' ), ' ', (string) $action ) );
	}

	/**
	 * Label for an action.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	private static function action_label( $action ) {
		$labels = self::action_labels();

		return isset( $labels[ $action ] ) ? $labels[ $action ] : self::humanize( $action );
	}

	/**
	 * Colour tone for an action pill.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	private static function tone( $action ) {
		if ( in_array( $action, array( 'payment_received', 'renewal_payment_received', 'payment_recorded_manually' ), true ) ) {
			return 'ok';
		}

		if ( 'email_failed' === $action || 'rejected' === $action ) {
			return 'danger';
		}

		if ( in_array( $action, array( 'document_replaced', 'documents_resubmitted', 'password_reset_by_admin' ), true ) ) {
			return 'warn';
		}

		if ( in_array( $action, array( 'submitted', 'decision', 'invoice_created', 'membership_expired', 'password_set', 'member_account_updated' ), true ) ) {
			return 'info';
		}

		return 'neutral';
	}

	/**
	 * Activity log screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'mms-membership' ) );
		}

		$filters = self::current_filters();
		$result  = self::fetch( $filters );
		$actions = self::filter_actions();
		$pages   = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		$base    = admin_url( 'admin.php?page=' . self::PAGE );

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero mms-hero-compact">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'Activity Log', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub"><?php esc_html_e( 'Every action recorded across applications, documents, payments and memberships.', 'mms-membership' ); ?></p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Dashboard', 'mms-membership' ); ?></a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Applications::PAGE ) ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Company List', 'mms-membership' ); ?></a>
				</div>
			</div>

			<div class="mms-toolbar">
				<form method="get" class="mms-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />

					<label for="mms-log-action" class="screen-reader-text"><?php esc_html_e( 'Activity type', 'mms-membership' ); ?></label>
					<select name="action" id="mms-log-action">
						<option value=""><?php esc_html_e( 'All activity', 'mms-membership' ); ?></option>
						<?php foreach ( $actions as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['action'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>

					<label for="mms-log-search" class="screen-reader-text"><?php esc_html_e( 'Search activity', 'mms-membership' ); ?></label>
					<input type="search" id="mms-log-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Company, reference, details or IP', 'mms-membership' ); ?>" />

					<?php submit_button( __( 'Filter', 'mms-membership' ), 'secondary', '', false ); ?>

					<?php if ( '' !== $filters['action'] || '' !== $filters['search'] ) : ?>
						<a class="mms-filters-reset" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Reset', 'mms-membership' ); ?></a>
					<?php endif; ?>
				</form>

				<p class="mms-toolbar-count">
					<?php
					printf(
						/* translators: %d: number of activity events */
						esc_html( _n( '%d event found.', '%d events found.', $result['total'], 'mms-membership' ) ),
						(int) $result['total']
					);
					?>
				</p>
			</div>

			<div class="mms-table-card">
				<table class="widefat striped mms-table mms-log-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Activity', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Company', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Details', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'User', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'IP', 'mms-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $result['rows'] ) ) : ?>
							<tr>
								<td colspan="6" class="mms-table-empty"><?php esc_html_e( 'No activity matches these filters.', 'mms-membership' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $result['rows'] as $row ) : ?>
								<?php
								$company   = isset( $row->company_name ) ? (string) $row->company_name : '';
								$reference = isset( $row->reference ) ? (string) $row->reference : '';
								$detail_id = (int) $row->application_id;
								$detail_url = $detail_id
									? add_query_arg(
										array(
											'page'        => MMS_Admin_Applications::PAGE,
											'application' => $detail_id,
										),
										admin_url( 'admin.php' )
									)
									: '';
								$user_id   = (int) $row->user_id;
								$user_name = isset( $row->user_name ) && '' !== (string) $row->user_name ? $row->user_name : '';
								?>
								<tr>
									<td class="mms-log-when"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td>
									<td><span class="mms-log-pill" data-tone="<?php echo esc_attr( self::tone( $row->action ) ); ?>"><?php echo esc_html( self::action_label( $row->action ) ); ?></span></td>
									<td>
										<?php if ( '' !== $company ) : ?>
											<?php if ( '' !== $detail_url ) : ?>
												<a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $company ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $company ); ?>
											<?php endif; ?>
											<?php if ( '' !== $reference ) : ?>
												<br /><span class="mms-muted mms-mono"><?php echo esc_html( $reference ); ?></span>
											<?php endif; ?>
										<?php elseif ( '' !== $reference ) : ?>
											<span class="mms-mono"><?php echo esc_html( $reference ); ?></span>
										<?php else : ?>
											<span class="mms-muted">—</span>
										<?php endif; ?>
									</td>
									<td class="mms-log-details"><?php echo esc_html( $row->details ); ?></td>
									<td>
										<?php
										if ( $user_id > 0 ) {
											echo esc_html( '' !== $user_name ? $user_name : '#' . $user_id );
										} else {
											esc_html_e( 'System', 'mms-membership' );
										}
										?>
									</td>
									<td class="mms-log-ip"><?php echo esc_html( '' !== (string) $row->ip_address ? $row->ip_address : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg(
										array(
											'page'   => self::PAGE,
											'action' => $filters['action'],
											's'      => $filters['search'],
											'paged'  => '%#%',
										),
										admin_url( 'admin.php' )
									),
									'format'    => '',
									'current'   => $filters['paged'],
									'total'     => $pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
