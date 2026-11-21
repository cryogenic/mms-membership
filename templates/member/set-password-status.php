<?php
/**
 * Set-password status panel (done or invalid link).
 *
 * @package MMS_Membership
 *
 * @var string $mms_state      done|invalid.
 * @var string $mms_message    Error message for invalid links.
 * @var string $mms_login_url  Login URL.
 * @var string $mms_login_name Member login name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_state     = isset( $mms_state ) ? $mms_state : 'invalid';
$mms_login_url = isset( $mms_login_url ) ? $mms_login_url : MMS_Members::login_url();
$mms_done      = 'done' === $mms_state;
?>
<div class="mms-login-shell mms-setpw-shell">

	<aside class="mms-login-brand">
		<div class="mms-login-brand-main">
			<a class="mms-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
			</a>

			<p class="mms-login-kicker"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>

			<?php if ( $mms_done ) : ?>
				<h2 class="mms-login-brand-title"><?php esc_html_e( 'You are all set.', 'mms-membership' ); ?></h2>
				<p class="mms-login-brand-text"><?php esc_html_e( 'Your member account is ready. Log in to manage your membership and payment.', 'mms-membership' ); ?></p>
			<?php else : ?>
				<h2 class="mms-login-brand-title"><?php esc_html_e( 'This link has expired.', 'mms-membership' ); ?></h2>
				<p class="mms-login-brand-text"><?php esc_html_e( 'Password links can only be used once. Request a fresh link and try again.', 'mms-membership' ); ?></p>
			<?php endif; ?>

			<ol class="mms-setpw-steps">
				<li class="is-done"><?php esc_html_e( 'Application approved', 'mms-membership' ); ?></li>
				<li class="<?php echo $mms_done ? 'is-done' : 'is-current'; ?>"><?php esc_html_e( 'Set your password', 'mms-membership' ); ?></li>
				<li class="<?php echo $mms_done ? 'is-current' : ''; ?>"><?php esc_html_e( 'Log in and complete payment', 'mms-membership' ); ?></li>
			</ol>
		</div>

		<p class="mms-login-trust">
			<span class="mms-login-trust-dot" aria-hidden="true"></span>
			<?php esc_html_e( 'Secure access for MACM members', 'mms-membership' ); ?>
		</p>
	</aside>

	<section class="mms-login-panel">
		<p class="mms-login-eyebrow"><?php esc_html_e( 'Account setup', 'mms-membership' ); ?></p>

		<?php if ( $mms_done ) : ?>
			<div class="mms-setpw-badge is-success" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
			</div>

			<h2 class="mms-login-title"><?php esc_html_e( 'Password saved', 'mms-membership' ); ?></h2>
			<p class="mms-login-sub"><?php esc_html_e( 'You can now log in to view your membership status and complete the payment.', 'mms-membership' ); ?></p>

			<?php if ( ! empty( $mms_login_name ) ) : ?>
				<p class="mms-setpw-account">
					<span><?php esc_html_e( 'Username', 'mms-membership' ); ?></span>
					<strong><?php echo esc_html( $mms_login_name ); ?></strong>
				</p>
			<?php endif; ?>

			<div class="mms-setpw-actions">
				<a class="mms-login-submit" href="<?php echo esc_url( $mms_login_url ); ?>"><?php esc_html_e( 'Log in to your account', 'mms-membership' ); ?></a>
			</div>

			<p class="mms-login-note mms-setpw-expiry"><?php esc_html_e( 'Keep your password private. MACM will never ask for it by email.', 'mms-membership' ); ?></p>
		<?php else : ?>
			<div class="mms-setpw-badge is-error" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5" /><path d="M12 17h.01" /><circle cx="12" cy="12" r="9" /></svg>
			</div>

			<h2 class="mms-login-title"><?php esc_html_e( 'This link is not valid', 'mms-membership' ); ?></h2>
			<p class="mms-login-sub"><?php echo esc_html( isset( $mms_message ) && '' !== $mms_message ? $mms_message : __( 'Please use the link from the most recent email we sent you, or contact MACM for assistance.', 'mms-membership' ) ); ?></p>

			<div class="mms-setpw-actions">
				<a class="mms-login-submit" href="<?php echo esc_url( $mms_login_url . '#reset-password' ); ?>"><?php esc_html_e( 'Request a new link', 'mms-membership' ); ?></a>
				<a class="mms-login-register" href="<?php echo esc_url( $mms_login_url ); ?>"><?php esc_html_e( 'Back to member login', 'mms-membership' ); ?></a>
			</div>

			<p class="mms-login-note mms-setpw-expiry"><?php esc_html_e( 'Each password link can be used once and expires 72 hours after it was sent.', 'mms-membership' ); ?></p>
		<?php endif; ?>
	</section>
</div>
