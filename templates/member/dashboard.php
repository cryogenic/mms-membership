<?php
/**
 * Member portal dashboard.
 *
 * @package MMS_Membership
 *
 * @var bool        $mms_logged_in Whether the visitor is logged in.
 * @var string      $mms_login_url Login page URL.
 * @var string      $mms_portal_url Portal URL.
 * @var string      $mms_msg       Message code.
 * @var array|null  $mms_app       Application row.
 * @var bool        $mms_editable  Whether profile/password forms are available.
 * @var array       $mms_meta      Company / PIC row.
 * @var array|null  $mms_type      Membership type definition.
 * @var array       $mms_docs      Uploaded documents.
 * @var array       $mms_catalog   Document catalogue.
 * @var array       $mms_doc_state Document status labels.
 * @var array       $mms_errors    Validation errors.
 * @var string      $mms_ref       Reference number.
 * @var string      $mms_fee       Formatted fee.
 * @var string      $mms_status    Status slug.
 * @var WP_User     $mms_user      Current user.
 * @var array       $mms_payments  Payment rows, newest first.
 * @var array|null  $mms_pay_open  Latest pending bill matching the open payment kind.
 * @var string      $mms_pay_kind  Open payment kind (initial, renewal or empty).
 * @var bool        $mms_pay_enabled Whether ToyyibPay is enabled.
 * @var array       $mms_pay_statuses Payment status labels.
 * @var string      $mms_pay_state Payment return state code.
 * @var string      $mms_pay_error Payment error message.
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
?>
<div class="mms-registration mms-portal">

	<?php if ( empty( $mms_logged_in ) ) : ?>
		<h2><?php esc_html_e( 'Membership dashboard', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'Please log in to view your membership status and payment details.', 'mms-membership' ); ?></p>
		<p>
			<a class="mms-button" href="<?php echo esc_url( $mms_login_url ); ?>"><?php esc_html_e( 'Member login', 'mms-membership' ); ?></a>
		</p>
	<?php elseif ( ! $mms_app ) : ?>
		<h2><?php esc_html_e( 'Membership dashboard', 'mms-membership' ); ?></h2>
		<div class="mms-notice mms-notice-error" role="alert">
			<p><?php esc_html_e( 'No membership application is linked to this account. Please contact MACM for assistance.', 'mms-membership' ); ?></p>
		</div>
		<p><a class="mms-button mms-button-secondary" href="<?php echo esc_url( wp_logout_url( $mms_login_url ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a></p>
	<?php else : ?>

		<?php
		$mms_company_name = isset( $mms_meta['company_name'] ) && '' !== trim( (string) $mms_meta['company_name'] ) ? $mms_meta['company_name'] : $mms_ref;
		$mms_type_label   = $mms_type ? $mms_type['label'] : __( 'Membership', 'mms-membership' );
		?>

		<div class="mms-portal-hero">
			<div class="mms-portal-hero-main">
				<span class="mms-portal-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></span>
				<h2><?php echo esc_html( $mms_company_name ); ?></h2>
				<p class="mms-portal-hero-meta">
					<?php echo esc_html( $mms_ref . ' · ' . $mms_type_label ); ?>
				</p>
			</div>
			<div class="mms-portal-hero-side">
				<span class="mms-status-pill is-<?php echo esc_attr( $mms_status ); ?>"><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></span>
				<?php if ( ! empty( $mms_app['membership_no'] ) ) : ?>
					<span class="mms-portal-hero-no"><?php echo esc_html( $mms_app['membership_no'] ); ?></span>
				<?php endif; ?>
			</div>
		</div>

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

		<div class="mms-portal-card">
			<h3><?php esc_html_e( 'Membership summary', 'mms-membership' ); ?></h3>
			<dl class="mms-portal-facts">
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

		<?php
		$mms_paid = null;
		foreach ( $mms_payments as $mms_payment_row ) {
			if ( 'paid' === $mms_payment_row['status'] ) {
				$mms_paid = $mms_payment_row;
				break;
			}
		}
		?>
		<?php
		$mms_render_pay_button = static function ( $label ) use ( $mms_app, $mms_pay_enabled ) {
			if ( ! $mms_pay_enabled ) {
				?>
				<p><button type="button" class="mms-button" disabled><?php echo esc_html( $label ); ?></button></p>
				<p class="mms-muted"><?php esc_html_e( 'Online payment is not available yet. Please contact MACM if you need to pay now.', 'mms-membership' ); ?></p>
				<?php
				return;
			}
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-payment-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Payments::ACTION_START ); ?>" />
				<?php wp_nonce_field( MMS_Payments::NONCE_START . '_' . (int) $mms_app['id'] ); ?>
				<button type="submit" class="mms-button"><?php echo esc_html( $label ); ?></button>
			</form>
			<p class="mms-muted"><?php esc_html_e( 'You will be redirected to ToyyibPay to pay by online banking (FPX) or card. Your membership activates automatically once payment is confirmed.', 'mms-membership' ); ?></p>
			<?php
		};
		?>
		<div class="mms-portal-card mms-portal-payment">
			<h3><?php esc_html_e( 'Payment & renewal', 'mms-membership' ); ?></h3>
			<?php if ( MMS_Status::APPROVED_UNPAID === $mms_status ) : ?>
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
						<p><a class="mms-button mms-button-secondary" href="<?php echo esc_url( MMS_Billing::invoice_url( (int) $mms_open_invoice['id'] ) ); ?>"><?php esc_html_e( 'Download invoice (PDF)', 'mms-membership' ); ?></a></p>
					<?php endif; ?>
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
					<p><?php esc_html_e( 'Payment received — your membership is active.', 'mms-membership' ); ?></p>
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
				<?php if ( ! empty( $mms_certificate_url ) ) : ?>
					<p><a class="mms-button" href="<?php echo esc_url( $mms_certificate_url ); ?>"><?php esc_html_e( 'Download membership certificate (PDF)', 'mms-membership' ); ?></a></p>
				<?php endif; ?>
				<?php if ( $mms_paid ) : ?>
					<p><a class="mms-button mms-button-secondary" href="<?php echo esc_url( MMS_Billing::receipt_url( (int) $mms_paid['id'] ) ); ?>"><?php esc_html_e( 'Download receipt (PDF)', 'mms-membership' ); ?></a></p>
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
					<p><a class="mms-button" href="<?php echo esc_url( $mms_certificate_url ); ?>"><?php esc_html_e( 'Download membership certificate (PDF)', 'mms-membership' ); ?></a></p>
				<?php endif; ?>
			<?php else : ?>
				<p><?php echo esc_html( MMS_Status::label( $mms_status ) ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $mms_payments ) ) : ?>
				<h4><?php esc_html_e( 'Payment history', 'mms-membership' ); ?></h4>
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
								<td><?php echo esc_html( $mms_payment_row['invoice_no'] ); ?></td>
								<td><?php echo esc_html( MMS_Payments::kind_label( $mms_row_kind ) ); ?></td>
								<td><?php echo esc_html( MMS_Payments::format_amount( $mms_payment_row['amount'], $mms_payment_row['currency'] ) ); ?></td>
								<td><?php echo esc_html( isset( $mms_pay_statuses[ $mms_payment_row['status'] ] ) ? $mms_pay_statuses[ $mms_payment_row['status'] ] : $mms_payment_row['status'] ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $mms_payment_row['paid_at'] ? $mms_payment_row['paid_at'] : $mms_payment_row['created_at'] ) ); ?></td>
								<td class="mms-payment-docs">
									<a href="<?php echo esc_url( MMS_Billing::invoice_url( (int) $mms_payment_row['id'] ) ); ?>"><?php esc_html_e( 'Invoice', 'mms-membership' ); ?></a>
									<?php if ( 'paid' === $mms_payment_row['status'] ) : ?>
										<a href="<?php echo esc_url( MMS_Billing::receipt_url( (int) $mms_payment_row['id'] ) ); ?>"><?php esc_html_e( 'Receipt', 'mms-membership' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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
								<td><?php echo esc_html( $mms_label ); ?></td>
								<td><?php echo esc_html( isset( $mms_doc_state[ $mms_doc['review_status'] ] ) ? $mms_doc_state[ $mms_doc['review_status'] ] : $mms_doc['review_status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( $mms_editable ) : ?>
			<div class="mms-portal-card">
				<h3><?php esc_html_e( 'Update contact details', 'mms-membership' ); ?></h3>
				<p class="mms-muted"><?php esc_html_e( 'Company name, registration number, address and Person In Charge identity can only be changed by MACM. Use this form for contact details.', 'mms-membership' ); ?></p>
				<form class="mms-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Portal::PROFILE_ACTION ); ?>" />
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
			<div class="mms-notice" role="status">
				<p><?php esc_html_e( 'Profile and password changes are available once your membership is active.', 'mms-membership' ); ?></p>
			</div>
		<?php endif; ?>

		<p class="mms-portal-footer">
			<a class="mms-button mms-button-secondary" href="<?php echo esc_url( wp_logout_url( $mms_login_url ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
		</p>
	<?php endif; ?>
</div>
