<?php
/**
 * Member portal dashboard.
 *
 * @package MMS_Membership
 *
 * @var bool        $mms_logged_in        Whether the visitor is logged in.
 * @var string      $mms_login_url        Login page URL.
 * @var string      $mms_registration_url Registration page URL.
 * @var string      $mms_portal_url       Portal URL.
 * @var string      $mms_msg              Message code.
 * @var array|null  $mms_app              Application row.
 * @var bool        $mms_editable         Whether profile/password forms are available.
 * @var array       $mms_meta             Company / PIC row.
 * @var array|null  $mms_type             Membership type definition.
 * @var array       $mms_docs             Uploaded documents.
 * @var array       $mms_catalog          Document catalogue.
 * @var array       $mms_doc_state        Document status labels.
 * @var array       $mms_errors           Validation errors.
 * @var string      $mms_ref              Reference number.
 * @var string      $mms_fee              Formatted fee.
 * @var string      $mms_status           Status slug.
 * @var WP_User     $mms_user             Current user.
 * @var array       $mms_payments         Payment rows, newest first.
 * @var array|null  $mms_pay_open         Latest pending bill matching the open payment kind.
 * @var string      $mms_pay_kind         Open payment kind (initial, renewal or empty).
 * @var bool        $mms_pay_enabled      Whether ToyyibPay is enabled.
 * @var array       $mms_pay_statuses     Payment status labels.
 * @var string      $mms_pay_state        Payment return state code.
 * @var string      $mms_pay_error        Payment error message.
 * @var array       $mms_company_list     Selectable companies.
 * @var string      $mms_company_error    Invalid company selection message.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_messages = array(
	'profile_saved'  => __( 'Your contact details have been saved.', 'mms-membership' ),
	'password_saved' => __( 'Your password has been changed.', 'mms-membership' ),
	'locked'         => __( 'Profile and password changes are available once your membership is active.', 'mms-membership' ),
	'no_account'     => __( 'No membership application is linked to this account.', 'mms-membership' ),
);

$mms_pay_messages = array(
	'paid'    => array( 'success', __( 'Payment received. Your membership is now active.', 'mms-membership' ) ),
	'failed'  => array( 'error', __( 'Your payment was not completed. You can try again below.', 'mms-membership' ) ),
	'pending' => array( 'info', __( 'Your payment is still pending. The status will update automatically once the gateway confirms it.', 'mms-membership' ) ),
);

$mms_registration_url = isset( $mms_registration_url ) ? $mms_registration_url : MMS_Members::registration_url();
?>

<?php if ( empty( $mms_logged_in ) ) : ?>

	<div class="mms-portal-shell mms-portal-shell-guest">
		<div class="mms-login-shell mms-portal-guest">

			<aside class="mms-login-brand">
				<div class="mms-login-brand-main">
					<a class="mms-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
					</a>

					<p class="mms-login-kicker"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h2 class="mms-login-brand-title"><?php esc_html_e( 'Your membership, in one place.', 'mms-membership' ); ?></h2>
					<p class="mms-login-brand-text"><?php esc_html_e( 'Sign in to manage your company membership, certificates, invoices and renewals from one secure account.', 'mms-membership' ); ?></p>

					<ul class="mms-login-points">
						<li><?php esc_html_e( 'Track your membership status and expiry date', 'mms-membership' ); ?></li>
						<li><?php esc_html_e( 'Download your certificate, invoices and receipts', 'mms-membership' ); ?></li>
						<li><?php esc_html_e( 'Renew online in a few minutes', 'mms-membership' ); ?></li>
					</ul>
				</div>

				<p class="mms-login-trust">
					<span class="mms-login-trust-dot" aria-hidden="true"></span>
					<?php esc_html_e( 'Secure access for MACM members', 'mms-membership' ); ?>
				</p>
			</aside>

			<section class="mms-login-panel">
				<p class="mms-login-eyebrow"><?php esc_html_e( 'Member access', 'mms-membership' ); ?></p>
				<h2 class="mms-login-title"><?php esc_html_e( 'Sign in required', 'mms-membership' ); ?></h2>
				<p class="mms-login-sub"><?php esc_html_e( 'Log in with your member account to view your membership dashboard.', 'mms-membership' ); ?></p>

				<div class="mms-login-actions">
					<a class="mms-login-submit" href="<?php echo esc_url( $mms_login_url ); ?>"><?php esc_html_e( 'Member login', 'mms-membership' ); ?></a>
				</div>

				<div class="mms-login-divider"><span><?php esc_html_e( 'Not a member yet?', 'mms-membership' ); ?></span></div>

				<a class="mms-login-register" href="<?php echo esc_url( $mms_registration_url ); ?>">
					<?php esc_html_e( 'Apply for membership', 'mms-membership' ); ?>
					<span aria-hidden="true">&rarr;</span>
				</a>

				<p class="mms-login-note"><?php esc_html_e( 'Your account is created when your membership application is approved.', 'mms-membership' ); ?></p>
			</section>
		</div>
	</div>

<?php elseif ( ! $mms_app ) : ?>

	<div class="mms-portal-shell mms-portal-shell-guest">
		<div class="mms-login-shell mms-portal-guest">

			<aside class="mms-login-brand">
				<div class="mms-login-brand-main">
					<a class="mms-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
						<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
					</a>

					<p class="mms-login-kicker"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h2 class="mms-login-brand-title"><?php esc_html_e( 'We could not find your membership.', 'mms-membership' ); ?></h2>
					<p class="mms-login-brand-text"><?php esc_html_e( 'This account is signed in, but no membership application is linked to it yet.', 'mms-membership' ); ?></p>

					<ul class="mms-login-points">
						<li><?php esc_html_e( 'If you just applied, linking happens once the application is approved', 'mms-membership' ); ?></li>
						<li><?php esc_html_e( 'Use the same email address you applied with', 'mms-membership' ); ?></li>
						<li><?php esc_html_e( 'Contact MACM if this looks wrong', 'mms-membership' ); ?></li>
					</ul>
				</div>

				<p class="mms-login-trust">
					<span class="mms-login-trust-dot" aria-hidden="true"></span>
					<?php esc_html_e( 'Secure access for MACM members', 'mms-membership' ); ?>
				</p>
			</aside>

			<section class="mms-login-panel">
				<p class="mms-login-eyebrow"><?php esc_html_e( 'Account', 'mms-membership' ); ?></p>
				<h2 class="mms-login-title"><?php esc_html_e( 'No membership linked', 'mms-membership' ); ?></h2>
				<p class="mms-login-sub"><?php esc_html_e( 'You are signed in, but there is no membership application attached to this account.', 'mms-membership' ); ?></p>

				<div class="mms-login-notice" role="alert">
					<p><?php esc_html_e( 'No membership application is linked to this account. Please contact MACM for assistance.', 'mms-membership' ); ?></p>
				</div>

				<div class="mms-login-actions">
					<a class="mms-login-submit" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to MACM website', 'mms-membership' ); ?></a>
					<a class="mms-login-register" href="<?php echo esc_url( wp_logout_url( $mms_login_url ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
				</div>

				<?php if ( $mms_user ) : ?>
					<p class="mms-login-note">
						<?php
						printf(
							/* translators: 1: display name, 2: account email */
							esc_html__( 'Signed in as %1$s (%2$s).', 'mms-membership' ),
							esc_html( $mms_user->display_name ),
							esc_html( $mms_user->user_email )
						);
						?>
					</p>
				<?php endif; ?>
			</section>
		</div>
	</div>

<?php else : ?>

	<?php
	$mms_company_name = isset( $mms_meta['company_name'] ) && '' !== trim( (string) $mms_meta['company_name'] ) ? $mms_meta['company_name'] : $mms_ref;
	$mms_type_label   = $mms_type ? $mms_type['label'] : __( 'Membership', 'mms-membership' );
	$mms_initial      = function_exists( 'mb_substr' ) ? mb_substr( (string) $mms_user->display_name, 0, 1 ) : substr( (string) $mms_user->display_name, 0, 1 );
	$mms_initial      = '' !== $mms_initial ? $mms_initial : ( function_exists( 'mb_substr' ) ? mb_substr( (string) $mms_user->user_login, 0, 1 ) : substr( (string) $mms_user->user_login, 0, 1 ) );
	$mms_paid         = null;

	foreach ( $mms_payments as $mms_payment_row ) {
		if ( 'paid' === $mms_payment_row['status'] ) {
			$mms_paid = $mms_payment_row;
			break;
		}
	}
	?>

	<div class="mms-portal mms-portal-shell">

		<header class="mms-portal-topbar">
			<a class="mms-portal-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
			</a>

			<div class="mms-portal-account">
				<span class="mms-portal-avatar" aria-hidden="true"><?php echo esc_html( $mms_initial ); ?></span>
				<span class="mms-portal-account-text">
					<span><?php esc_html_e( 'Signed in as', 'mms-membership' ); ?></span>
					<strong><?php echo esc_html( $mms_user->display_name ); ?></strong>
				</span>
				<a class="mms-portal-logout" href="<?php echo esc_url( wp_logout_url( $mms_login_url ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
			</div>
		</header>

		<?php if ( count( $mms_company_list ) > 1 ) : ?>
			<nav class="mms-portal-companies" aria-label="<?php esc_attr_e( 'Your companies', 'mms-membership' ); ?>">
				<span class="mms-portal-companies-label"><?php esc_html_e( 'Companies', 'mms-membership' ); ?></span>
				<div class="mms-portal-company-list">
					<?php foreach ( $mms_company_list as $mms_company ) : ?>
						<?php if ( $mms_company['active'] ) : ?>
							<span class="mms-portal-company is-active" aria-current="true">
								<strong><?php echo esc_html( $mms_company['label'] ); ?></strong>
								<span class="mms-portal-company-status is-<?php echo esc_attr( $mms_company['status'] ); ?>"><?php echo esc_html( $mms_company['status_label'] ); ?></span>
							</span>
						<?php else : ?>
							<a class="mms-portal-company" href="<?php echo esc_url( add_query_arg( 'mms_company', (int) $mms_company['id'], $mms_portal_url ) ); ?>">
								<strong><?php echo esc_html( $mms_company['label'] ); ?></strong>
								<span class="mms-portal-company-status is-<?php echo esc_attr( $mms_company['status'] ); ?>"><?php echo esc_html( $mms_company['status_label'] ); ?></span>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</nav>
		<?php endif; ?>

		<section class="mms-portal-hero">
			<div class="mms-portal-hero-main">
				<span class="mms-portal-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></span>
				<h2><?php echo esc_html( $mms_company_name ); ?></h2>
				<p class="mms-portal-hero-meta"><?php echo esc_html( $mms_ref . ' · ' . $mms_type_label ); ?></p>
			</div>
			<div class="mms-portal-hero-side">
				<span class="mms-status-pill is-<?php echo esc_attr( $mms_status ); ?>"><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></span>
				<?php if ( ! empty( $mms_app['membership_no'] ) ) : ?>
					<span class="mms-portal-hero-no"><?php esc_html_e( 'Membership no.', 'mms-membership' ); ?> <?php echo esc_html( $mms_app['membership_no'] ); ?></span>
				<?php endif; ?>
				<?php if ( MMS_Status::APPROVED_UNPAID === $mms_status || MMS_Status::EXPIRED === $mms_status ) : ?>
					<a class="mms-portal-hero-cta" href="#mms-payment"><?php echo MMS_Status::EXPIRED === $mms_status ? esc_html__( 'Renew now', 'mms-membership' ) : esc_html__( 'Complete payment', 'mms-membership' ); ?></a>
				<?php endif; ?>
			</div>
		</section>

		<div class="mms-portal-stats">
			<div class="mms-portal-stat">
				<span><?php esc_html_e( 'Status', 'mms-membership' ); ?></span>
				<strong><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></strong>
			</div>
			<div class="mms-portal-stat">
				<span><?php esc_html_e( 'Membership type', 'mms-membership' ); ?></span>
				<strong><?php echo esc_html( $mms_type_label ); ?></strong>
			</div>
			<div class="mms-portal-stat">
				<span><?php echo esc_html( MMS_Status::EXPIRED === $mms_status ? __( 'Expired on', 'mms-membership' ) : __( 'Valid until', 'mms-membership' ) ); ?></span>
				<strong><?php echo esc_html( ! empty( $mms_app['expires_at'] ) ? mysql2date( get_option( 'date_format' ), $mms_app['expires_at'] ) : '—' ); ?></strong>
			</div>
			<div class="mms-portal-stat">
				<span><?php echo esc_html( MMS_Status::ACTIVE === $mms_status ? __( 'Fee paid', 'mms-membership' ) : __( 'Amount due', 'mms-membership' ) ); ?></span>
				<strong><?php echo esc_html( $mms_fee ); ?></strong>
			</div>
		</div>

		<?php if ( '' !== $mms_company_error ) : ?>
			<div class="mms-notice mms-notice-error" role="alert">
				<p><?php echo esc_html( $mms_company_error ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $mms_messages[ $mms_msg ] ) ) : ?>
			<div class="mms-notice <?php echo 'locked' === $mms_msg ? 'mms-notice-error' : 'mms-notice-success'; ?>" role="status">
				<p><?php echo esc_html( $mms_messages[ $mms_msg ] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( isset( $mms_pay_messages[ $mms_pay_state ] ) ) : ?>
			<div class="mms-notice mms-notice-<?php echo esc_attr( $mms_pay_messages[ $mms_pay_state ][0] ); ?>" role="status">
				<p><?php echo esc_html( $mms_pay_messages[ $mms_pay_state ][1] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $mms_pay_error ) ) : ?>
			<div class="mms-notice mms-notice-error" role="alert">
				<p><?php echo esc_html( is_string( $mms_pay_error ) ? $mms_pay_error : __( 'The payment could not be started. Please try again.', 'mms-membership' ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $mms_errors ) ) : ?>
			<div class="mms-notice mms-notice-error" role="alert">
				<p><strong><?php esc_html_e( 'Please fix the following:', 'mms-membership' ); ?></strong></p>
				<ul>
					<?php foreach ( $mms_errors as $mms_error ) : ?>
						<li><?php echo esc_html( $mms_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		$mms_render_pay_button = static function ( $label ) use ( $mms_app, $mms_pay_enabled ) {
			if ( ! $mms_pay_enabled ) {
				?>
				<div class="mms-portal-actions">
					<button type="button" class="mms-button" disabled><?php echo esc_html( $label ); ?></button>
				</div>
				<p class="mms-muted"><?php esc_html_e( 'Online payment is not available yet. Please contact MACM if you need to pay now.', 'mms-membership' ); ?></p>
				<?php
				return;
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-payment-form mms-portal-actions">
				<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Payments::ACTION_START ); ?>" />
				<input type="hidden" name="company_id" value="<?php echo esc_attr( (int) $mms_app['id'] ); ?>" />
				<?php wp_nonce_field( MMS_Payments::NONCE_START . '_' . (int) $mms_app['id'] ); ?>
				<button type="submit" class="mms-button"><?php echo esc_html( $label ); ?></button>
			</form>
			<p class="mms-muted"><?php esc_html_e( 'You will be redirected to ToyyibPay to pay by online banking (FPX) or card. Your membership activates automatically once payment is confirmed.', 'mms-membership' ); ?></p>
			<?php
		};
		?>

		<div class="mms-portal-grid">
			<div class="mms-portal-main">

				<div class="mms-portal-card mms-portal-payment" id="mms-payment">
					<h3><?php esc_html_e( 'Payment & renewal', 'mms-membership' ); ?></h3>

					<?php if ( MMS_Status::APPROVED_UNPAID === $mms_status ) : ?>
						<div class="mms-portal-paybox">
							<div class="mms-portal-paybox-head">
								<span class="mms-portal-paybox-label"><?php esc_html_e( 'Amount due', 'mms-membership' ); ?></span>
								<strong class="mms-portal-paybox-amount"><?php echo esc_html( $mms_fee ); ?></strong>
							</div>
							<p>
								<?php
								printf(
									/* translators: %s: formatted fee */
									esc_html__( 'Your application is approved. Pay %s to activate your membership.', 'mms-membership' ),
									esc_html( $mms_fee )
								);
								?>
							</p>
							<?php $mms_render_pay_button( $mms_pay_open ? __( 'Continue payment', 'mms-membership' ) : __( 'Pay now', 'mms-membership' ) ); ?>
							<?php if ( $mms_open_invoice ) : ?>
								<div class="mms-portal-actions">
									<a class="mms-button mms-button-secondary" href="<?php echo esc_url( MMS_Billing::invoice_url( (int) $mms_open_invoice['id'] ) ); ?>"><?php esc_html_e( 'Download invoice (PDF)', 'mms-membership' ); ?></a>
								</div>
							<?php endif; ?>
						</div>

					<?php elseif ( MMS_Status::ACTIVE === $mms_status ) : ?>
						<?php if ( MMS_Payments::KIND_RENEWAL === $mms_pay_kind ) : ?>
							<p>
								<?php
								printf(
									/* translators: %s: expiry date */
									esc_html__( 'Your membership is active until %s. You can renew now; the new term starts after your current expiry date.', 'mms-membership' ),
									esc_html( mysql2date( get_option( 'date_format' ), $mms_app['expires_at'] ) )
								);
								?>
							</p>
							<?php if ( $mms_pay_open ) : ?>
								<p class="mms-muted"><?php esc_html_e( 'A renewal payment is awaiting completion.', 'mms-membership' ); ?></p>
							<?php endif; ?>
							<?php $mms_render_pay_button( $mms_pay_open ? __( 'Continue renewal payment', 'mms-membership' ) : __( 'Renew membership', 'mms-membership' ) ); ?>
						<?php else : ?>
							<p class="mms-portal-ok"><?php esc_html_e( 'Payment received — your membership is active.', 'mms-membership' ); ?></p>
						<?php endif; ?>

						<?php if ( $mms_paid ) : ?>
							<p class="mms-muted">
								<?php
								printf(
									/* translators: 1: amount, 2: invoice number, 3: payment date */
									esc_html__( '%1$s paid on %3$s (invoice %2$s).', 'mms-membership' ),
									esc_html( MMS_Payments::format_amount( $mms_paid['amount'], $mms_paid['currency'] ) ),
									esc_html( $mms_paid['invoice_no'] ),
									esc_html( $mms_paid['paid_at'] ? mysql2date( get_option( 'date_format' ), $mms_paid['paid_at'] ) : '—' )
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( ! empty( $mms_certificate_url ) || $mms_paid ) : ?>
							<div class="mms-portal-actions">
								<?php if ( ! empty( $mms_certificate_url ) ) : ?>
									<a class="mms-button" href="<?php echo esc_url( $mms_certificate_url ); ?>"><?php esc_html_e( 'Download membership certificate (PDF)', 'mms-membership' ); ?></a>
								<?php endif; ?>
								<?php if ( $mms_paid ) : ?>
									<a class="mms-button mms-button-secondary" href="<?php echo esc_url( MMS_Billing::receipt_url( (int) $mms_paid['id'] ) ); ?>"><?php esc_html_e( 'Download receipt (PDF)', 'mms-membership' ); ?></a>
								<?php endif; ?>
							</div>
						<?php endif; ?>

					<?php elseif ( MMS_Status::EXPIRED === $mms_status ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: expiry date */
								esc_html__( 'Your membership expired on %s. Renew now to reactivate your membership and receive an updated certificate.', 'mms-membership' ),
								esc_html( mysql2date( get_option( 'date_format' ), $mms_app['expires_at'] ) )
							);
							?>
						</p>
						<?php if ( $mms_pay_open ) : ?>
							<p class="mms-muted"><?php esc_html_e( 'A renewal payment is awaiting completion.', 'mms-membership' ); ?></p>
						<?php endif; ?>
						<?php $mms_render_pay_button( $mms_pay_open ? __( 'Continue renewal payment', 'mms-membership' ) : __( 'Renew membership', 'mms-membership' ) ); ?>
						<?php if ( ! empty( $mms_certificate_url ) ) : ?>
							<div class="mms-portal-actions">
								<a class="mms-button" href="<?php echo esc_url( $mms_certificate_url ); ?>"><?php esc_html_e( 'Download membership certificate (PDF)', 'mms-membership' ); ?></a>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<p><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $mms_payments ) ) : ?>
						<h4><?php esc_html_e( 'Payment history', 'mms-membership' ); ?></h4>
						<div class="mms-portal-table-wrap">
							<table class="mms-portal-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Invoice', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Type', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Amount', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Status', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Date', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Documents', 'mms-membership' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $mms_payments as $mms_payment_row ) : ?>
										<?php $mms_row_kind = isset( $mms_payment_row['kind'] ) ? $mms_payment_row['kind'] : MMS_Payments::KIND_INITIAL; ?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Invoice', 'mms-membership' ); ?>"><?php echo esc_html( $mms_payment_row['invoice_no'] ); ?></td>
											<td data-label="<?php esc_attr_e( 'Type', 'mms-membership' ); ?>"><?php echo esc_html( MMS_Payments::kind_label( $mms_row_kind ) ); ?></td>
											<td data-label="<?php esc_attr_e( 'Amount', 'mms-membership' ); ?>"><?php echo esc_html( MMS_Payments::format_amount( $mms_payment_row['amount'], $mms_payment_row['currency'] ) ); ?></td>
											<td data-label="<?php esc_attr_e( 'Status', 'mms-membership' ); ?>"><span class="mms-portal-tag is-<?php echo esc_attr( $mms_payment_row['status'] ); ?>"><?php echo esc_html( isset( $mms_pay_statuses[ $mms_payment_row['status'] ] ) ? $mms_pay_statuses[ $mms_payment_row['status'] ] : $mms_payment_row['status'] ); ?></span></td>
											<td data-label="<?php esc_attr_e( 'Date', 'mms-membership' ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ), $mms_payment_row['paid_at'] ? $mms_payment_row['paid_at'] : $mms_payment_row['created_at'] ) ); ?></td>
											<td class="mms-payment-docs" data-label="<?php esc_attr_e( 'Documents', 'mms-membership' ); ?>">
												<a href="<?php echo esc_url( MMS_Billing::invoice_url( (int) $mms_payment_row['id'] ) ); ?>"><?php esc_html_e( 'Invoice', 'mms-membership' ); ?></a>
												<?php if ( 'paid' === $mms_payment_row['status'] ) : ?>
													<a href="<?php echo esc_url( MMS_Billing::receipt_url( (int) $mms_payment_row['id'] ) ); ?>"><?php esc_html_e( 'Receipt', 'mms-membership' ); ?></a>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<div class="mms-portal-card">
					<h3><?php esc_html_e( 'Company details', 'mms-membership' ); ?></h3>
					<dl class="mms-portal-facts">
						<div><dt><?php esc_html_e( 'Company name', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['company_name'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Registration no. (SSM / ROS)', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['company_reg_no'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'State', 'mms-membership' ); ?></dt><dd><?php echo esc_html( ! empty( $mms_meta['company_state'] ) ? $mms_meta['company_state'] : '—' ); ?></dd></div>
						<div class="mms-portal-wide"><dt><?php esc_html_e( 'Address', 'mms-membership' ); ?></dt><dd><?php echo nl2br( esc_html( $mms_meta['company_address'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Phone', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['company_phone'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Email', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['company_email'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Website', 'mms-membership' ); ?></dt><dd><?php echo esc_html( '' !== $mms_meta['company_website'] ? $mms_meta['company_website'] : '—' ); ?></dd></div>
					</dl>
				</div>

				<div class="mms-portal-card">
					<h3><?php esc_html_e( 'Person In Charge', 'mms-membership' ); ?></h3>
					<dl class="mms-portal-facts">
						<div><dt><?php esc_html_e( 'Name', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['pic_name'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Position', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['pic_position'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Email', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['pic_email'] ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Phone', 'mms-membership' ); ?></dt><dd><?php echo esc_html( $mms_meta['pic_phone'] ); ?></dd></div>
					</dl>
				</div>

				<div class="mms-portal-card">
					<h3><?php esc_html_e( 'Supporting documents', 'mms-membership' ); ?></h3>
					<?php if ( empty( $mms_docs ) ) : ?>
						<p class="mms-muted"><?php esc_html_e( 'No documents on file.', 'mms-membership' ); ?></p>
					<?php else : ?>
						<div class="mms-portal-table-wrap">
							<table class="mms-portal-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Document', 'mms-membership' ); ?></th>
										<th><?php esc_html_e( 'Review status', 'mms-membership' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $mms_docs as $mms_doc ) : ?>
										<?php $mms_label = isset( $mms_catalog[ $mms_doc['doc_type'] ] ) ? $mms_catalog[ $mms_doc['doc_type'] ]['label'] : $mms_doc['doc_type']; ?>
										<tr>
											<td data-label="<?php esc_attr_e( 'Document', 'mms-membership' ); ?>"><?php echo esc_html( $mms_label ); ?></td>
											<td data-label="<?php esc_attr_e( 'Review status', 'mms-membership' ); ?>"><span class="mms-portal-tag is-<?php echo esc_attr( $mms_doc['review_status'] ); ?>"><?php echo esc_html( isset( $mms_doc_state[ $mms_doc['review_status'] ] ) ? $mms_doc_state[ $mms_doc['review_status'] ] : $mms_doc['review_status'] ); ?></span></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $mms_editable ) : ?>
					<div class="mms-portal-card">
						<h3><?php esc_html_e( 'Update contact details', 'mms-membership' ); ?></h3>
						<p class="mms-muted"><?php esc_html_e( 'Company name, registration number, address and Person In Charge identity can only be changed by MACM. Use this form for contact details.', 'mms-membership' ); ?></p>
						<form class="mms-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Portal::PROFILE_ACTION ); ?>" />
							<input type="hidden" name="company_id" value="<?php echo esc_attr( (int) $mms_app['id'] ); ?>" />
							<?php wp_nonce_field( MMS_Portal::PROFILE_NONCE . '_' . (int) $mms_user->ID ); ?>
							<div class="mms-field-grid">
								<div class="mms-field">
									<label for="mms-company-phone"><?php esc_html_e( 'Company phone', 'mms-membership' ); ?></label>
									<input type="tel" id="mms-company-phone" name="company_phone" value="<?php echo esc_attr( $mms_meta['company_phone'] ); ?>" />
								</div>
								<div class="mms-field">
									<label for="mms-company-email"><?php esc_html_e( 'Company email', 'mms-membership' ); ?></label>
									<input type="email" id="mms-company-email" name="company_email" value="<?php echo esc_attr( $mms_meta['company_email'] ); ?>" />
								</div>
								<div class="mms-field">
									<label for="mms-company-website"><?php esc_html_e( 'Company website', 'mms-membership' ); ?></label>
									<input type="url" id="mms-company-website" name="company_website" value="<?php echo esc_attr( $mms_meta['company_website'] ); ?>" />
								</div>
								<div class="mms-field">
									<label for="mms-pic-phone"><?php esc_html_e( 'Person In Charge phone', 'mms-membership' ); ?></label>
									<input type="tel" id="mms-pic-phone" name="pic_phone" value="<?php echo esc_attr( $mms_meta['pic_phone'] ); ?>" />
								</div>
							</div>
							<div class="mms-step-actions">
								<button type="submit" class="mms-button"><?php esc_html_e( 'Save contact details', 'mms-membership' ); ?></button>
							</div>
						</form>
					</div>

					<div class="mms-portal-card">
						<h3><?php esc_html_e( 'Change password', 'mms-membership' ); ?></h3>
						<form class="mms-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Portal::PASSWORD_ACTION ); ?>" />
							<input type="hidden" name="company_id" value="<?php echo esc_attr( (int) $mms_app['id'] ); ?>" />
							<?php wp_nonce_field( MMS_Portal::PASSWORD_NONCE . '_' . (int) $mms_user->ID ); ?>
							<div class="mms-field-grid">
								<div class="mms-field mms-field-wide">
									<label for="mms-current-password"><?php esc_html_e( 'Current password', 'mms-membership' ); ?></label>
									<input type="password" id="mms-current-password" name="current_password" autocomplete="current-password" required />
								</div>
								<div class="mms-field">
									<label for="mms-new-password"><?php esc_html_e( 'New password', 'mms-membership' ); ?></label>
									<input type="password" id="mms-new-password" name="new_password" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Portal::MIN_PASSWORD ); ?>" />
								</div>
								<div class="mms-field">
									<label for="mms-confirm-password"><?php esc_html_e( 'Confirm new password', 'mms-membership' ); ?></label>
									<input type="password" id="mms-confirm-password" name="confirm_password" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Portal::MIN_PASSWORD ); ?>" />
								</div>
							</div>
							<div class="mms-step-actions">
								<button type="submit" class="mms-button"><?php esc_html_e( 'Change password', 'mms-membership' ); ?></button>
							</div>
						</form>
					</div>
				<?php else : ?>
					<div class="mms-portal-card">
						<h3><?php esc_html_e( 'Profile & security', 'mms-membership' ); ?></h3>
						<div class="mms-notice mms-notice-info" role="status">
							<p><?php esc_html_e( 'Profile and password changes are available once your membership is active.', 'mms-membership' ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<aside class="mms-portal-aside">
				<div class="mms-portal-card">
					<h3><?php esc_html_e( 'Membership summary', 'mms-membership' ); ?></h3>
					<dl class="mms-portal-facts mms-portal-facts-stack">
						<div>
							<dt><?php esc_html_e( 'Reference number', 'mms-membership' ); ?></dt>
							<dd><?php echo esc_html( $mms_ref ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Membership type', 'mms-membership' ); ?></dt>
							<dd><?php echo esc_html( $mms_type ? $mms_type['label'] : '—' ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Status', 'mms-membership' ); ?></dt>
							<dd><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></dd>
						</div>
						<div>
							<dt><?php echo esc_html( MMS_Status::ACTIVE === $mms_status ? __( 'Fee paid', 'mms-membership' ) : __( 'Amount due', 'mms-membership' ) ); ?></dt>
							<dd><?php echo esc_html( $mms_fee ); ?></dd>
						</div>
						<?php if ( ! empty( $mms_app['membership_no'] ) ) : ?>
							<div>
								<dt><?php esc_html_e( 'Membership number', 'mms-membership' ); ?></dt>
								<dd><?php echo esc_html( $mms_app['membership_no'] ); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $mms_app['certificate_no'] ) ) : ?>
							<div>
								<dt><?php esc_html_e( 'Certificate number', 'mms-membership' ); ?></dt>
								<dd><?php echo esc_html( $mms_app['certificate_no'] ); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $mms_app['expires_at'] ) ) : ?>
							<div>
								<dt><?php echo esc_html( MMS_Status::EXPIRED === $mms_status ? __( 'Expired on', 'mms-membership' ) : __( 'Valid until', 'mms-membership' ) ); ?></dt>
								<dd><?php echo esc_html( mysql2date( get_option( 'date_format' ), $mms_app['expires_at'] ) ); ?></dd>
							</div>
						<?php endif; ?>
					</dl>
				</div>

				<div class="mms-portal-card mms-portal-account-card">
					<h3><?php esc_html_e( 'Account', 'mms-membership' ); ?></h3>
					<dl class="mms-portal-facts mms-portal-facts-stack">
						<div>
							<dt><?php esc_html_e( 'Username', 'mms-membership' ); ?></dt>
							<dd><?php echo esc_html( $mms_user->user_login ); ?></dd>
						</div>
						<div>
							<dt><?php esc_html_e( 'Email', 'mms-membership' ); ?></dt>
							<dd><?php echo esc_html( $mms_user->user_email ); ?></dd>
						</div>
					</dl>
					<a class="mms-button mms-button-secondary mms-portal-logout-btn" href="<?php echo esc_url( wp_logout_url( $mms_login_url ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
				</div>
			</aside>
		</div>
	</div>

<?php endif; ?>
