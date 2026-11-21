<?php
/**
 * Admin side: MMS menu and scaffold overview screen.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Admin
 */
class MMS_Admin {

	/**
	 * Single instance.
	 *
	 * @var MMS_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin pages that load the MMS assets.
	 *
	 * @var array<int,string>
	 */
	private $pages = array( 'toplevel_page_mms-membership', 'mms_page_mms-settings', 'mms_page_mms-applications', 'mms_page_mms-renewals', 'mms_page_mms-company-new', 'mms_page_mms-logs' );

	/**
	 * Get the instance.
	 *
	 * @return MMS_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-settings.php';
		require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-admin-applications.php';
		require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-admin-renewals.php';
		require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-admin-company.php';
		require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-admin-logs.php';

		MMS_Admin_Applications::init();
		MMS_Admin_Company::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mms_save_settings', array( 'MMS_Settings', 'handle_save' ) );
	}

	/**
	 * Register the MMS menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'MMS — Membership', 'mms-membership' ),
			__( 'MMS', 'mms-membership' ),
			'mms_review_application',
			'mms-membership',
			array( $this, 'render_overview' ),
			'dashicons-groups',
			26
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Dashboard', 'mms-membership' ),
			__( 'Dashboard', 'mms-membership' ),
			'mms_review_application',
			'mms-membership',
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Company List', 'mms-membership' ),
			__( 'Company List', 'mms-membership' ),
			'mms_review_application',
			MMS_Admin_Applications::PAGE,
			array( 'MMS_Admin_Applications', 'render' )
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Add Company', 'mms-membership' ),
			__( 'Add company', 'mms-membership' ),
			'mms_review_application',
			MMS_Admin_Company::PAGE,
			array( 'MMS_Admin_Company', 'render' )
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Renewals & Expiry', 'mms-membership' ),
			__( 'Renewals', 'mms-membership' ),
			'mms_review_application',
			MMS_Admin_Renewals::PAGE,
			array( 'MMS_Admin_Renewals', 'render' )
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Membership Settings', 'mms-membership' ),
			__( 'Settings', 'mms-membership' ),
			MMS_Settings::CAPABILITY,
			'mms-settings',
			array( 'MMS_Settings', 'render' )
		);

		add_submenu_page(
			'mms-membership',
			__( 'MMS Activity Log', 'mms-membership' ),
			__( 'Log', 'mms-membership' ),
			'mms_review_application',
			MMS_Admin_Logs::PAGE,
			array( 'MMS_Admin_Logs', 'render' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'mms-admin',
			MMS_MEMBERSHIP_URL . 'assets/css/mms-admin.css',
			array(),
			MMS_MEMBERSHIP_VERSION
		);
		wp_enqueue_style(
			'mms-dashboard',
			MMS_MEMBERSHIP_URL . 'assets/css/mms-dashboard.css',
			array( 'mms-admin' ),
			MMS_MEMBERSHIP_VERSION
		);
		wp_enqueue_script(
			'mms-admin',
			MMS_MEMBERSHIP_URL . 'assets/js/mms-admin.js',
			array(),
			MMS_MEMBERSHIP_VERSION,
			true
		);

		wp_localize_script(
			'mms-admin',
			'mmsAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'usernameNonce' => wp_create_nonce( 'mms_check_username' ),
				'checkingText'  => __( 'Checking availability…', 'mms-membership' ),
				'errorText'     => __( 'Availability could not be checked. Try again.', 'mms-membership' ),
			)
		);
	}

	/**
	 * Premium overview: key totals, kanban pipeline and company list.
	 *
	 * @return void
	 */
	public function render_overview() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mms-membership' ) );
		}

		global $wpdb;

		$types      = MMS_Membership_Types::get_types();
		$docs       = MMS_Membership_Types::get_document_types();
		$app_table  = MMS_DB::table( MMS_DB::APPLICATION );
		$meta_table = MMS_DB::table( MMS_DB::APPLICATION_META );
		$statuses   = MMS_Status::all();

		$counts = array_fill_keys( array_keys( $statuses ), 0 );
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$app_table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['total'];
			}
		}

		$total  = array_sum( $counts );
		$soon   = current_datetime()->modify( '+30 days' )->format( 'Y-m-d H:i:s' );

		$expiring_soon = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$app_table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <> '0000-00-00 00:00:00' AND expires_at <= %s",
				MMS_Status::ACTIVE,
				$soon
			)
		);

		$active_now = max( 0, (int) $counts[ MMS_Status::ACTIVE ] - $expiring_soon );
		$pending    = (int) $counts[ MMS_Status::SUBMITTED ] + (int) $counts[ MMS_Status::UNDER_REVIEW ];
		$unpaid     = (int) $counts[ MMS_Status::APPROVED_UNPAID ];

		$recent = $wpdb->get_results( "SELECT a.*, m.company_name, m.company_reg_no, m.pic_name, m.pic_email FROM {$app_table} a LEFT JOIN {$meta_table} m ON m.application_id = a.id ORDER BY COALESCE(a.submitted_at, a.created_at) DESC, a.id DESC LIMIT 300" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$recent = is_array( $recent ) ? $recent : array();

		$analytics = self::dashboard_analytics();
		$months    = $analytics['months'];
		$app_chart = self::line_chart( $analytics['applications'] );
		$rev_chart = self::bar_chart( $analytics['revenue'] );
		$expiry    = $analytics['expiry'];

		$app_total    = array_sum( $analytics['applications'] );
		$rev_total    = array_sum( $analytics['revenue'] );
		$expiry_90    = $expiry['d30'] + $expiry['d60'] + $expiry['d90'];
		$expiry_total = $expiry_90 + $expiry['overdue'];
		$last_month   = $months[ count( $months ) - 1 ];
		$first_month  = $months[0];
		$date_range   = $first_month['label'] . ' ' . $first_month['year'] . ' – ' . $last_month['label'] . ' ' . $last_month['year'];
		$renewals_url = admin_url( 'admin.php?page=' . MMS_Admin_Renewals::PAGE );

		$columns = array(
			'review'    => array(
				'label'  => __( 'Pending review', 'mms-membership' ),
				'tone'   => 'info',
				'count'  => $pending,
				'args'   => array(),
				'empty'  => __( 'No applications waiting for review.', 'mms-membership' ),
			),
			'need_info' => array(
				'label' => __( 'Need info', 'mms-membership' ),
				'tone'  => 'warn',
				'count' => (int) $counts[ MMS_Status::NEED_INFO ],
				'args'  => array( 'status' => MMS_Status::NEED_INFO ),
				'empty' => __( 'Nothing waiting on applicants.', 'mms-membership' ),
			),
			'unpaid'    => array(
				'label' => __( 'Unpaid', 'mms-membership' ),
				'tone'  => 'warn',
				'count' => $unpaid,
				'args'  => array( 'status' => MMS_Status::APPROVED_UNPAID ),
				'empty' => __( 'Every approved company has paid.', 'mms-membership' ),
			),
			'expiring'  => array(
				'label' => __( 'Expiring in 30 days', 'mms-membership' ),
				'tone'  => 'expiring',
				'count' => $expiring_soon,
				'args'  => array(),
				'link'  => admin_url( 'admin.php?page=' . MMS_Admin_Renewals::PAGE ),
				'empty' => __( 'No memberships expiring soon.', 'mms-membership' ),
			),
			'active'    => array(
				'label' => __( 'Active', 'mms-membership' ),
				'tone'  => 'ok',
				'count' => $active_now,
				'args'  => array( 'status' => MMS_Status::ACTIVE ),
				'empty' => __( 'No active memberships yet.', 'mms-membership' ),
			),
			'expired'   => array(
				'label' => __( 'Expired', 'mms-membership' ),
				'tone'  => 'danger',
				'count' => (int) $counts[ MMS_Status::EXPIRED ],
				'args'  => array( 'status' => MMS_Status::EXPIRED ),
				'empty' => __( 'No expired memberships.', 'mms-membership' ),
			),
			'rejected'  => array(
				'label' => __( 'Rejected', 'mms-membership' ),
				'tone'  => 'danger',
				'count' => (int) $counts[ MMS_Status::REJECTED ],
				'args'  => array( 'status' => MMS_Status::REJECTED ),
				'empty' => __( 'No rejected applications.', 'mms-membership' ),
			),
		);

		if ( (int) $counts[ MMS_Status::DRAFT ] > 0 ) {
			$columns = array(
				'draft' => array(
					'label' => __( 'Draft', 'mms-membership' ),
					'tone'  => '',
					'count' => (int) $counts[ MMS_Status::DRAFT ],
					'args'  => array( 'status' => MMS_Status::DRAFT ),
					'empty' => __( 'No draft registrations.', 'mms-membership' ),
				),
			) + $columns;
		}

		$buckets = array_fill_keys( array_keys( $columns ), array() );

		foreach ( $recent as $row ) {
			$status = $row->status;
			$bucket = '';

			if ( in_array( $status, array( MMS_Status::SUBMITTED, MMS_Status::UNDER_REVIEW ), true ) ) {
				$bucket = 'review';
			} elseif ( MMS_Status::ACTIVE === $status ) {
				$has_expiry = ! empty( $row->expires_at ) && '0000-00-00 00:00:00' !== $row->expires_at;
				$bucket     = ( $has_expiry && $row->expires_at <= $soon ) ? 'expiring' : 'active';
			} elseif ( isset( $buckets[ $status ] ) ) {
				$bucket = $status;
			}

			if ( '' !== $bucket && isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ][] = $row;
			}
		}

		$detail_base = admin_url( 'admin.php?page=' . MMS_Admin_Applications::PAGE );

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'Membership dashboard', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub">
						<?php
						printf(
							/* translators: %s: current date */
							esc_html__( 'Company registrations, payments and renewals at a glance — %s.', 'mms-membership' ),
							esc_html( wp_date( get_option( 'date_format' ) ) )
						);
						?>
					</p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Company::PAGE ) ); ?>">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add company', 'mms-membership' ); ?>
					</a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( $detail_base ); ?>">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'Company List', 'mms-membership' ); ?>
					</a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Renewals::PAGE ) ); ?>">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php esc_html_e( 'Renewals', 'mms-membership' ); ?>
					</a>
				</div>
			</div>

			<div class="mms-stat-grid">
				<?php
				$stats = array(
					array(
						'label' => __( 'Registered companies', 'mms-membership' ),
						'value' => $total,
						'icon'  => 'dashicons-groups',
						'class' => '',
						'note'  => sprintf(
							/* translators: %d: number of active memberships */
							__( '%d active memberships', 'mms-membership' ),
							(int) $counts[ MMS_Status::ACTIVE ]
						),
					),
					array(
						'label' => __( 'Pending review', 'mms-membership' ),
						'value' => $pending,
						'icon'  => 'dashicons-search',
						'class' => '',
						'note'  => __( 'Submitted & under review', 'mms-membership' ),
					),
					array(
						'label' => __( 'Unpaid', 'mms-membership' ),
						'value' => $unpaid,
						'icon'  => 'dashicons-money-alt',
						'class' => 'is-warn',
						'note'  => __( 'Approved, awaiting payment', 'mms-membership' ),
					),
					array(
						'label' => __( 'Active', 'mms-membership' ),
						'value' => (int) $counts[ MMS_Status::ACTIVE ],
						'icon'  => 'dashicons-yes-alt',
						'class' => 'is-ok',
						'note'  => __( 'Paid and in force', 'mms-membership' ),
					),
					array(
						'label' => __( 'Expiring soon', 'mms-membership' ),
						'value' => $expiring_soon,
						'icon'  => 'dashicons-calendar-alt',
						'class' => 'is-warn',
						'note'  => __( 'Within the next 30 days', 'mms-membership' ),
					),
					array(
						'label' => __( 'Expired', 'mms-membership' ),
						'value' => (int) $counts[ MMS_Status::EXPIRED ],
						'icon'  => 'dashicons-warning',
						'class' => 'is-danger',
						'note'  => __( 'Renew to reactivate', 'mms-membership' ),
					),
				);

				foreach ( $stats as $stat ) :
					?>
					<div class="mms-stat <?php echo esc_attr( $stat['class'] ); ?>">
						<div class="mms-stat-top">
							<span class="mms-stat-label"><?php echo esc_html( $stat['label'] ); ?></span>
							<span class="mms-stat-icon"><span class="dashicons <?php echo esc_attr( $stat['icon'] ); ?>" aria-hidden="true"></span></span>
						</div>
						<span class="mms-stat-value"><?php echo esc_html( number_format_i18n( (int) $stat['value'] ) ); ?></span>
						<span class="mms-stat-note"><?php echo esc_html( $stat['note'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mms-section mms-analytics">
				<div class="mms-section-head">
					<div>
						<h2><?php esc_html_e( 'Membership performance', 'mms-membership' ); ?></h2>
						<p>
							<?php
							printf(
								/* translators: %s: month range, e.g. Dec 2025 – Nov 2026 */
								esc_html__( 'Applications and fees collected, %s.', 'mms-membership' ),
								esc_html( $date_range )
							);
							?>
						</p>
					</div>
				</div>

				<div class="mms-analytics-grid">
					<div class="mms-chart-card">
						<div class="mms-chart-head">
							<div>
								<span class="mms-chart-label"><?php esc_html_e( 'New applications', 'mms-membership' ); ?></span>
								<strong class="mms-chart-value"><?php echo esc_html( number_format_i18n( (int) $app_total ) ); ?></strong>
								<span class="mms-chart-note"><?php esc_html_e( 'Received in the last 12 months', 'mms-membership' ); ?></span>
							</div>
							<div class="mms-chart-side">
								<span class="mms-chart-chip"><?php echo esc_html( $last_month['label'] ); ?></span>
								<strong><?php echo esc_html( number_format_i18n( (int) $analytics['applications'][ count( $analytics['applications'] ) - 1 ] ) ); ?></strong>
								<span class="mms-chart-side-note"><?php esc_html_e( 'this month', 'mms-membership' ); ?></span>
							</div>
						</div>

						<?php if ( 0 === (int) $app_total ) : ?>
							<p class="mms-chart-empty"><?php esc_html_e( 'No applications were received in this period.', 'mms-membership' ); ?></p>
						<?php else : ?>
							<svg class="mms-chart-svg" viewBox="0 0 <?php echo esc_attr( $app_chart['width'] ); ?> <?php echo esc_attr( $app_chart['height'] ); ?>" role="img" aria-label="<?php esc_attr_e( 'New applications per month', 'mms-membership' ); ?>">
								<title><?php echo esc_html( sprintf( /* translators: %s: month range */ __( 'New applications per month, %s', 'mms-membership' ), $date_range ) ); ?></title>
								<defs>
									<linearGradient id="mmsChartArea" x1="0" y1="0" x2="0" y2="1">
										<stop offset="0%" stop-color="#0f4c5c" stop-opacity="0.22" />
										<stop offset="100%" stop-color="#0f4c5c" stop-opacity="0" />
									</linearGradient>
								</defs>

								<?php foreach ( $app_chart['grid'] as $grid ) : ?>
									<line x1="<?php echo esc_attr( $app_chart['pad_left'] ); ?>" y1="<?php echo esc_attr( $grid['y'] ); ?>" x2="<?php echo esc_attr( $app_chart['width'] - $app_chart['pad_right'] ); ?>" y2="<?php echo esc_attr( $grid['y'] ); ?>" stroke="#e8edee" stroke-width="1" />
									<text x="<?php echo esc_attr( $app_chart['pad_left'] - 10 ); ?>" y="<?php echo esc_attr( $grid['y'] + 4 ); ?>" text-anchor="end" font-size="11" fill="#7b8a8f"><?php echo esc_html( $grid['label'] ); ?></text>
								<?php endforeach; ?>

								<path d="<?php echo esc_attr( $app_chart['area'] ); ?>" fill="url(#mmsChartArea)" />
								<polyline points="<?php echo esc_attr( $app_chart['line'] ); ?>" fill="none" stroke="#0f4c5c" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />

								<?php foreach ( $app_chart['dots'] as $index => $dot ) : ?>
									<circle cx="<?php echo esc_attr( $dot['x'] ); ?>" cy="<?php echo esc_attr( $dot['y'] ); ?>" r="3.5" fill="#fff" stroke="#0f4c5c" stroke-width="2">
										<title><?php echo esc_html( sprintf( '%1$s: %2$s', $months[ $index ]['label'] . ' ' . $months[ $index ]['year'], number_format_i18n( (int) $dot['value'] ) ) ); ?></title>
									</circle>
									<text x="<?php echo esc_attr( $dot['x'] ); ?>" y="<?php echo esc_attr( $app_chart['height'] - 10 ); ?>" text-anchor="middle" font-size="10" fill="#7b8a8f"><?php echo esc_html( $months[ $index ]['label'] ); ?></text>
								<?php endforeach; ?>
							</svg>
						<?php endif; ?>
					</div>

					<div class="mms-chart-card">
						<div class="mms-chart-head">
							<div>
								<span class="mms-chart-label"><?php esc_html_e( 'Fees collected', 'mms-membership' ); ?></span>
								<strong class="mms-chart-value"><?php echo esc_html( 'RM ' . number_format_i18n( (float) $rev_total, 0 ) ); ?></strong>
								<span class="mms-chart-note"><?php esc_html_e( 'Paid in the last 12 months', 'mms-membership' ); ?></span>
							</div>
							<div class="mms-chart-side">
								<span class="mms-chart-chip"><?php echo esc_html( $last_month['label'] ); ?></span>
								<strong><?php echo esc_html( 'RM ' . number_format_i18n( (float) $analytics['revenue'][ count( $analytics['revenue'] ) - 1 ], 0 ) ); ?></strong>
								<span class="mms-chart-side-note"><?php esc_html_e( 'this month', 'mms-membership' ); ?></span>
							</div>
						</div>

						<?php if ( $rev_total <= 0 ) : ?>
							<p class="mms-chart-empty"><?php esc_html_e( 'No paid fees were recorded in this period.', 'mms-membership' ); ?></p>
						<?php else : ?>
							<svg class="mms-chart-svg" viewBox="0 0 <?php echo esc_attr( $rev_chart['width'] ); ?> <?php echo esc_attr( $rev_chart['height'] ); ?>" role="img" aria-label="<?php esc_attr_e( 'Fees collected per month', 'mms-membership' ); ?>">
								<title><?php echo esc_html( sprintf( /* translators: %s: month range */ __( 'Fees collected per month, %s', 'mms-membership' ), $date_range ) ); ?></title>
								<defs>
									<linearGradient id="mmsChartBar" x1="0" y1="0" x2="0" y2="1">
										<stop offset="0%" stop-color="#146170" />
										<stop offset="100%" stop-color="#0f4c5c" />
									</linearGradient>
								</defs>

								<?php foreach ( $rev_chart['grid'] as $grid ) : ?>
									<line x1="<?php echo esc_attr( $rev_chart['pad_left'] ); ?>" y1="<?php echo esc_attr( $grid['y'] ); ?>" x2="<?php echo esc_attr( $rev_chart['width'] - $rev_chart['pad_right'] ); ?>" y2="<?php echo esc_attr( $grid['y'] ); ?>" stroke="#e8edee" stroke-width="1" />
									<text x="<?php echo esc_attr( $rev_chart['pad_left'] - 10 ); ?>" y="<?php echo esc_attr( $grid['y'] + 4 ); ?>" text-anchor="end" font-size="11" fill="#7b8a8f"><?php echo esc_html( $grid['label'] ); ?></text>
								<?php endforeach; ?>

								<?php foreach ( $rev_chart['bars'] as $index => $bar ) : ?>
									<rect x="<?php echo esc_attr( $bar['x'] ); ?>" y="<?php echo esc_attr( $bar['y'] ); ?>" width="<?php echo esc_attr( $bar['w'] ); ?>" height="<?php echo esc_attr( $bar['h'] ); ?>" rx="5" fill="url(#mmsChartBar)">
										<title><?php echo esc_html( sprintf( '%1$s: %2$s', $months[ $index ]['label'] . ' ' . $months[ $index ]['year'], 'RM ' . number_format_i18n( (float) $bar['value'], 0 ) ) ); ?></title>
									</rect>
									<text x="<?php echo esc_attr( $bar['x'] + ( $bar['w'] / 2 ) ); ?>" y="<?php echo esc_attr( $rev_chart['height'] - 10 ); ?>" text-anchor="middle" font-size="10" fill="#7b8a8f"><?php echo esc_html( $months[ $index ]['label'] ); ?></text>
								<?php endforeach; ?>
							</svg>
						<?php endif; ?>
					</div>
				</div>

				<div class="mms-expiry-card">
					<div class="mms-expiry-head">
						<div>
							<span class="mms-chart-label"><?php esc_html_e( 'Expiry forecast', 'mms-membership' ); ?></span>
							<strong class="mms-chart-value">
								<?php echo esc_html( number_format_i18n( (int) $expiry_90 ) ); ?>
								<small><?php esc_html_e( 'expiring within 90 days', 'mms-membership' ); ?></small>
							</strong>
						</div>
						<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( $renewals_url ); ?>">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<?php esc_html_e( 'Open renewals', 'mms-membership' ); ?>
						</a>
					</div>

					<?php if ( $expiry_total > 0 ) : ?>
						<div class="mms-expiry-bar" aria-hidden="true">
							<?php
							$segments = array(
								'overdue' => 'danger',
								'd30'     => 'warn',
								'd60'     => 'info',
								'd90'     => 'ok',
							);
							foreach ( $segments as $key => $tone ) :
								if ( $expiry[ $key ] <= 0 ) {
									continue;
								}
								?>
								<span class="mms-expiry-segment" data-tone="<?php echo esc_attr( $tone ); ?>" style="width:<?php echo esc_attr( round( $expiry[ $key ] / $expiry_total * 100, 2 ) ); ?>%"></span>
							<?php endforeach; ?>
						</div>

						<div class="mms-expiry-buckets">
							<?php
							$buckets_meta = array(
								'overdue' => array(
									'label' => __( 'Overdue', 'mms-membership' ),
									'note'  => __( 'Past the expiry date', 'mms-membership' ),
									'tone'  => 'danger',
								),
								'd30'     => array(
									'label' => __( '0–30 days', 'mms-membership' ),
									'note'  => __( 'Renew now', 'mms-membership' ),
									'tone'  => 'warn',
								),
								'd60'     => array(
									'label' => __( '31–60 days', 'mms-membership' ),
									'note'  => __( 'Coming up soon', 'mms-membership' ),
									'tone'  => 'info',
								),
								'd90'     => array(
									'label' => __( '61–90 days', 'mms-membership' ),
									'note'  => __( 'Plan ahead', 'mms-membership' ),
									'tone'  => 'ok',
								),
							);
							foreach ( $buckets_meta as $key => $bucket ) :
								?>
								<a class="mms-expiry-bucket" data-tone="<?php echo esc_attr( $bucket['tone'] ); ?>" href="<?php echo esc_url( $renewals_url ); ?>">
									<span class="mms-expiry-count"><?php echo esc_html( number_format_i18n( (int) $expiry[ $key ] ) ); ?></span>
									<span class="mms-expiry-label"><?php echo esc_html( $bucket['label'] ); ?></span>
									<span class="mms-expiry-note"><?php echo esc_html( $bucket['note'] ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="mms-expiry-empty"><?php esc_html_e( 'No memberships are expiring in the next 90 days.', 'mms-membership' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="mms-section">
				<div class="mms-section-head">
					<h2><?php esc_html_e( 'Company pipeline', 'mms-membership' ); ?></h2>
					<p><?php esc_html_e( 'Every registered company, grouped by the stage of its membership.', 'mms-membership' ); ?></p>
				</div>

				<div class="mms-kanban">
					<?php foreach ( $columns as $key => $column ) : ?>
						<?php
						$cards = isset( $buckets[ $key ] ) ? $buckets[ $key ] : array();
						$shown = array_slice( $cards, 0, 8 );
						$link  = isset( $column['link'] ) ? $column['link'] : add_query_arg( $column['args'], $detail_base );
						?>
						<div class="mms-kanban-col" data-tone="<?php echo esc_attr( $column['tone'] ); ?>">
							<div class="mms-kanban-head">
								<span class="mms-kanban-title">
									<span class="mms-kanban-dot" aria-hidden="true"></span>
									<?php echo esc_html( $column['label'] ); ?>
								</span>
								<span class="mms-kanban-count"><?php echo esc_html( number_format_i18n( (int) $column['count'] ) ); ?></span>
							</div>
							<div class="mms-kanban-body">
								<?php if ( empty( $shown ) ) : ?>
									<p class="mms-kanban-empty"><?php echo esc_html( $column['empty'] ); ?></p>
								<?php else : ?>
									<?php foreach ( $shown as $row ) : ?>
										<?php
										$row_name  = '' !== (string) $row->company_name ? $row->company_name : ( '' !== (string) $row->reference ? $row->reference : '#' . (int) $row->id );
										$row_ref   = '' !== (string) $row->reference ? $row->reference : '#' . (int) $row->id;
										$row_type  = isset( $types[ $row->membership_type ] ) ? $types[ $row->membership_type ]['label'] : $row->membership_type;
										$row_url   = add_query_arg( 'application', (int) $row->id, $detail_base );
										$flag      = '';
										$when      = '';

										if ( 'expiring' === $key && ! empty( $row->expires_at ) ) {
											$flag = __( 'Expires soon', 'mms-membership' );
											$when = sprintf(
												/* translators: %s: expiry date */
												__( 'expires %s', 'mms-membership' ),
												mysql2date( 'j M Y', $row->expires_at )
											);
										} elseif ( 'unpaid' === $key ) {
											$flag = __( 'Payment pending', 'mms-membership' );
										} elseif ( 'need_info' === $key ) {
											$flag = __( 'Waiting on applicant', 'mms-membership' );
										}

										if ( '' === $when && ! empty( $row->expires_at ) && in_array( $key, array( 'active', 'expired' ), true ) ) {
											$when = sprintf(
												/* translators: %s: expiry date */
												'expired' === $key ? __( 'expired %s', 'mms-membership' ) : __( 'valid to %s', 'mms-membership' ),
												mysql2date( 'j M Y', $row->expires_at )
											);
										} elseif ( '' === $when && ! empty( $row->submitted_at ) ) {
											$when = sprintf(
												/* translators: %s: submission date */
												__( 'submitted %s', 'mms-membership' ),
												mysql2date( 'j M Y', $row->submitted_at )
											);
										}
										?>
										<a class="mms-kanban-card" href="<?php echo esc_url( $row_url ); ?>">
											<strong><?php echo esc_html( $row_name ); ?></strong>
											<span><?php echo esc_html( $row_ref . ' · ' . $row_type ); ?></span>
											<?php if ( '' !== $when ) : ?>
												<span><?php echo esc_html( $when ); ?></span>
											<?php endif; ?>
											<?php if ( '' !== $flag ) : ?>
												<span class="mms-kanban-flag"><?php echo esc_html( $flag ); ?></span>
											<?php endif; ?>
										</a>
									<?php endforeach; ?>
									<?php if ( (int) $column['count'] > count( $shown ) ) : ?>
										<a class="mms-kanban-more" href="<?php echo esc_url( $link ); ?>">
											<?php
											printf(
												/* translators: %d: number of companies */
												esc_html__( 'View all %d →', 'mms-membership' ),
												(int) $column['count']
											);
											?>
										</a>
									<?php endif; ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="mms-section">
				<div class="mms-section-head">
					<h2><?php esc_html_e( 'Company list', 'mms-membership' ); ?></h2>
					<a href="<?php echo esc_url( $detail_base ); ?>"><?php esc_html_e( 'View all companies →', 'mms-membership' ); ?></a>
				</div>

				<div class="mms-panel">
					<table class="mms-company-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Company', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Reference', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Membership no.', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Expiry', 'mms-membership' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $recent ) ) : ?>
								<tr>
									<td colspan="7" class="mms-table-empty"><?php esc_html_e( 'No companies yet. Use “Add company” to register the first one.', 'mms-membership' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( array_slice( $recent, 0, 15 ) as $row ) : ?>
									<?php
									$row_name   = '' !== (string) $row->company_name ? $row->company_name : '—';
									$row_ref    = '' !== (string) $row->reference ? $row->reference : '#' . (int) $row->id;
									$row_type   = isset( $types[ $row->membership_type ] ) ? $types[ $row->membership_type ]['label'] : $row->membership_type;
									$row_url    = add_query_arg( 'application', (int) $row->id, $detail_base );
									$has_cert   = '' !== (string) $row->membership_no && in_array( $row->status, array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true );
									?>
									<tr>
										<td>
											<a class="mms-company-name" href="<?php echo esc_url( $row_url ); ?>"><?php echo esc_html( $row_name ); ?></a>
											<?php if ( ! empty( $row->company_reg_no ) ) : ?>
												<span class="mms-company-reg"><?php echo esc_html( $row->company_reg_no ); ?></span>
											<?php endif; ?>
										</td>
										<td class="mms-mono"><?php echo esc_html( $row_ref ); ?></td>
										<td><?php echo esc_html( $row_type ); ?></td>
										<td><span class="mms-pill is-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( MMS_Status::label( $row->status ) ); ?></span></td>
										<td class="mms-mono"><?php echo esc_html( '' !== (string) $row->membership_no ? $row->membership_no : '—' ); ?></td>
										<td><?php echo esc_html( ! empty( $row->expires_at ) ? mysql2date( 'j M Y', $row->expires_at ) : '—' ); ?></td>
										<td>
											<div class="mms-row-actions">
												<a href="<?php echo esc_url( $row_url ); ?>"><?php esc_html_e( 'View', 'mms-membership' ); ?></a>
												<?php if ( $has_cert ) : ?>
													<a href="<?php echo esc_url( MMS_Admin_Applications::certificate_url( (int) $row->id ) ); ?>"><?php esc_html_e( 'Certificate PDF', 'mms-membership' ); ?></a>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="mms-section">
				<div class="mms-section-head">
					<h2><?php esc_html_e( 'Membership types & fees', 'mms-membership' ); ?></h2>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mms-settings' ) ); ?>"><?php esc_html_e( 'Edit types and fees →', 'mms-membership' ); ?></a>
				</div>

				<div class="mms-panel">
					<table class="mms-company-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Type', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Fee', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Duration', 'mms-membership' ); ?></th>
								<th><?php esc_html_e( 'Required documents', 'mms-membership' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $types as $type ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $type['label'] ); ?></strong></td>
									<td><?php echo esc_html( number_format_i18n( (float) $type['fee'], 2 ) . ' ' . $type['currency'] ); ?></td>
									<td><?php echo esc_html( (int) $type['duration_months'] . ' ' . __( 'months', 'mms-membership' ) ); ?></td>
									<td>
										<?php
										$labels = array();
										foreach ( $type['required_docs'] as $doc_key ) {
											$labels[] = isset( $docs[ $doc_key ] ) ? $docs[ $doc_key ]['label'] : $doc_key;
										}
										echo esc_html( implode( ', ', $labels ) );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Twelve-month application / revenue series and expiry buckets (10-minute cache).
	 *
	 * @return array<string,mixed>
	 */
	private static function dashboard_analytics() {
		$cached = get_transient( 'mms_dashboard_analytics' );

		if ( is_array( $cached ) && isset( $cached['months'], $cached['applications'], $cached['revenue'], $cached['expiry'] ) ) {
			return $cached;
		}

		global $wpdb;

		$app_table = MMS_DB::table( MMS_DB::APPLICATION );
		$pay_table = MMS_DB::table( MMS_DB::PAYMENT );

		$start = current_datetime()->modify( 'first day of this month' )->setTime( 0, 0, 0 )->modify( '-11 months' );
		$since = $start->format( 'Y-m-d H:i:s' );

		$months = array();
		$keys   = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$month    = $start->modify( '+' . $i . ' months' );
			$key      = $month->format( 'Y-m' );
			$keys[]   = $key;
			$months[] = array(
				'key'   => $key,
				'label' => wp_date( 'M', $month->getTimestamp() ),
				'year'  => $month->format( 'Y' ),
			);
		}

		$applications = array_fill_keys( $keys, 0 );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT DATE_FORMAT(COALESCE(submitted_at, created_at), '%%Y-%%m') AS ym, COUNT(*) AS total FROM {$app_table} WHERE COALESCE(submitted_at, created_at) >= %s GROUP BY ym",
				$since
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $applications[ $row['ym'] ] ) ) {
				$applications[ $row['ym'] ] = (int) $row['total'];
			}
		}

		$revenue  = array_fill_keys( $keys, 0.0 );
		$payments = array_fill_keys( $keys, 0 );
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT DATE_FORMAT(paid_at, '%%Y-%%m') AS ym, SUM(amount) AS total, COUNT(*) AS total_rows FROM {$pay_table} WHERE status = %s AND paid_at IS NOT NULL AND paid_at <> '0000-00-00 00:00:00' AND paid_at >= %s GROUP BY ym",
				'paid',
				$since
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $revenue[ $row['ym'] ] ) ) {
				$revenue[ $row['ym'] ]  = (float) $row['total'];
				$payments[ $row['ym'] ] = (int) $row['total_rows'];
			}
		}

		$now = current_datetime();
		$at  = $now->format( 'Y-m-d H:i:s' );
		$d30 = $now->modify( '+30 days' )->format( 'Y-m-d H:i:s' );
		$d60 = $now->modify( '+60 days' )->format( 'Y-m-d H:i:s' );
		$d90 = $now->modify( '+90 days' )->format( 'Y-m-d H:i:s' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN expires_at < %s THEN 1 ELSE 0 END) AS overdue,
					SUM(CASE WHEN expires_at >= %s AND expires_at <= %s THEN 1 ELSE 0 END) AS d30,
					SUM(CASE WHEN expires_at > %s AND expires_at <= %s THEN 1 ELSE 0 END) AS d60,
					SUM(CASE WHEN expires_at > %s AND expires_at <= %s THEN 1 ELSE 0 END) AS d90
				FROM {$app_table}
				WHERE status = %s AND expires_at IS NOT NULL AND expires_at <> '0000-00-00 00:00:00'",
				$at,
				$at,
				$d30,
				$d30,
				$d60,
				$d60,
				$d90,
				MMS_Status::ACTIVE
			),
			ARRAY_A
		);

		$expiry = array(
			'overdue' => isset( $row['overdue'] ) ? (int) $row['overdue'] : 0,
			'd30'     => isset( $row['d30'] ) ? (int) $row['d30'] : 0,
			'd60'     => isset( $row['d60'] ) ? (int) $row['d60'] : 0,
			'd90'     => isset( $row['d90'] ) ? (int) $row['d90'] : 0,
		);

		$analytics = array(
			'months'       => $months,
			'applications' => array_values( $applications ),
			'revenue'      => array_values( $revenue ),
			'payments'     => array_values( $payments ),
			'expiry'       => $expiry,
		);

		set_transient( 'mms_dashboard_analytics', $analytics, 10 * MINUTE_IN_SECONDS );

		return $analytics;
	}

	/**
	 * Line chart geometry for a monthly series.
	 *
	 * @param array<int,int|float> $series Values, oldest first.
	 * @return array<string,mixed>
	 */
	private static function line_chart( $series ) {
		$width      = 640;
		$height     = 230;
		$pad_left   = 46;
		$pad_right  = 16;
		$pad_top    = 18;
		$pad_bottom = 34;

		$count     = max( 1, count( $series ) );
		$plot_w    = $width - $pad_left - $pad_right;
		$plot_h    = $height - $pad_top - $pad_bottom;
		$max_value = max( array_map( 'intval', (array) $series ) );
		$axis_max  = $max_value > 0 ? max( 4, (int) ceil( $max_value / 4 ) * 4 ) : 4;
		$baseline  = $pad_top + $plot_h;
		$step      = $count > 1 ? $plot_w / ( $count - 1 ) : 0;

		$dots = array();
		$line = array();

		foreach ( array_values( $series ) as $index => $value ) {
			$x      = $pad_left + $index * $step;
			$y      = $pad_top + $plot_h * ( 1 - ( $value / $axis_max ) );
			$dots[] = array(
				'x'     => round( $x, 1 ),
				'y'     => round( $y, 1 ),
				'value' => (int) $value,
			);
			$line[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		$first_x = round( $pad_left, 1 );
		$last_x  = round( $pad_left + ( $count - 1 ) * $step, 1 );

		$grid = array();
		for ( $i = 0; $i <= 4; $i++ ) {
			$grid[] = array(
				'y'     => round( $pad_top + $plot_h * ( 1 - $i / 4 ), 1 ),
				'label' => number_format_i18n( (int) ( $axis_max * $i / 4 ) ),
			);
		}

		return array(
			'width'     => $width,
			'height'    => $height,
			'pad_left'  => $pad_left,
			'pad_right' => $pad_right,
			'baseline'  => $baseline,
			'axis_max'  => $axis_max,
			'dots'      => $dots,
			'line'      => implode( ' ', $line ),
			'area'      => 'M ' . $first_x . ',' . $baseline . ' L ' . implode( ' L ', $line ) . ' L ' . $last_x . ',' . $baseline . ' Z',
			'grid'      => $grid,
		);
	}

	/**
	 * Bar chart geometry for a monthly series.
	 *
	 * @param array<int,int|float> $series Values, oldest first.
	 * @return array<string,mixed>
	 */
	private static function bar_chart( $series ) {
		$width      = 640;
		$height     = 230;
		$pad_left   = 56;
		$pad_right  = 16;
		$pad_top    = 18;
		$pad_bottom = 34;

		$count    = max( 1, count( $series ) );
		$plot_w   = $width - $pad_left - $pad_right;
		$plot_h   = $height - $pad_top - $pad_bottom;
		$axis_max = self::nice_axis_max( max( array_map( 'floatval', (array) $series ) ) );
		$baseline = $pad_top + $plot_h;
		$slot     = $plot_w / $count;
		$bar_w    = min( 44, $slot * 0.56 );

		$bars = array();
		foreach ( array_values( $series ) as $index => $value ) {
			$x      = $pad_left + $index * $slot + ( $slot - $bar_w ) / 2;
			$y      = $pad_top + $plot_h * ( 1 - ( $value / $axis_max ) );
			$bars[] = array(
				'x'     => round( $x, 1 ),
				'y'     => round( $y, 1 ),
				'w'     => round( $bar_w, 1 ),
				'h'     => round( max( 0, $baseline - $y ), 1 ),
				'value' => (float) $value,
			);
		}

		$grid = array();
		for ( $i = 0; $i <= 4; $i++ ) {
			$grid[] = array(
				'y'     => round( $pad_top + $plot_h * ( 1 - $i / 4 ), 1 ),
				'label' => self::short_number( $axis_max * $i / 4 ),
			);
		}

		return array(
			'width'     => $width,
			'height'    => $height,
			'pad_left'  => $pad_left,
			'pad_right' => $pad_right,
			'baseline'  => $baseline,
			'axis_max'  => $axis_max,
			'bars'      => $bars,
			'grid'      => $grid,
		);
	}

	/**
	 * Round a maximum up to a 1/2/5 × 10^n axis value.
	 *
	 * @param float $max Largest series value.
	 * @return float
	 */
	private static function nice_axis_max( $max ) {
		$max = (float) $max;

		if ( $max <= 0 ) {
			return 1000.0;
		}

		$magnitude = pow( 10, floor( log10( $max ) ) );
		$normal    = $max / $magnitude;

		if ( $normal <= 1 ) {
			$nice = 1;
		} elseif ( $normal <= 2 ) {
			$nice = 2;
		} elseif ( $normal <= 5 ) {
			$nice = 5;
		} else {
			$nice = 10;
		}

		return $nice * $magnitude;
	}

	/**
	 * Compact axis label for money values, e.g. 1.5k.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function short_number( $value ) {
		$value = (float) $value;

		if ( $value >= 1000 ) {
			$short = $value / 1000;
			return ( floor( $short ) === $short ? number_format_i18n( $short, 0 ) : number_format_i18n( $short, 1 ) ) . 'k';
		}

		return number_format_i18n( (int) round( $value ) );
	}
}
