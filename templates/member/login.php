<?php
/**
 * Member login form / already-logged-in panel.
 *
 * @package MMS_Membership
 *
 * @var bool   $mms_logged_in  Whether the visitor is logged in.
 * @var string $mms_portal_url Portal URL.
 * @var string $mms_error      Error code (empty|invalid).
 * @var string $mms_username   Previously submitted username.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_error = isset( $mms_error ) ? $mms_error : '';
?>
<div class="mms-registration mms-member-login">

	<?php if ( ! empty( $mms_logged_in ) ) : ?>
		<h2><?php esc_html_e( 'You are already logged in', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'Open your membership dashboard to view your status and payment details.', 'mms-membership' ); ?></p>
		<p>
			<a class="mms-button" href="<?php echo esc_url( $mms_portal_url ); ?>"><?php esc_html_e( 'Go to dashboard', 'mms-membership' ); ?></a>
			<a class="mms-button mms-button-secondary" href="<?php echo esc_url( wp_logout_url( MMS_Members::login_url() ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
		</p>
	<?php else : ?>
		<h2><?php esc_html_e( 'Member login', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'Log in with the email address and password for your MMS member account.', 'mms-membership' ); ?></p>

		<?php if ( 'empty' === $mms_error ) : ?>
			<div class="mms-notice mms-notice-error" role="alert">
				<p><?php esc_html_e( 'Please enter your email address and password.', 'mms-membership' ); ?></p>
			</div>
		<?php elseif ( 'invalid' === $mms_error ) : ?>
			<div class="mms-notice mms-notice-error" role="alert">
				<p><?php esc_html_e( 'The email address or password is not correct. Please try again.', 'mms-membership' ); ?></p>
			</div>
		<?php endif; ?>

		<form class="mms-form mms-member-login-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Member_Login::ACTION ); ?>" />
			<?php wp_nonce_field( MMS_Member_Login::NONCE ); ?>
			<p class="mms-hp" aria-hidden="true">
				<label for="mms-login-website"><?php esc_html_e( 'Website', 'mms-membership' ); ?></label>
				<input type="text" id="mms-login-website" name="mms_website" value="" tabindex="-1" autocomplete="off" />
			</p>

			<div class="mms-field-grid">
				<div class="mms-field mms-field-wide">
					<label for="mms-username"><?php esc_html_e( 'Email address or username', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-username" name="mms_username" value="<?php echo esc_attr( $mms_username ); ?>" autocomplete="username" required />
				</div>

				<div class="mms-field mms-field-wide">
					<label for="mms-login-password"><?php esc_html_e( 'Password', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="password" id="mms-login-password" name="mms_password" autocomplete="current-password" required />
				</div>
			</div>

			<p class="mms-terms">
				<label>
					<input type="checkbox" name="mms_remember" value="1" />
					<?php esc_html_e( 'Keep me logged in', 'mms-membership' ); ?>
				</label>
			</p>

			<div class="mms-step-actions">
				<button type="submit" class="mms-button"><?php esc_html_e( 'Log in', 'mms-membership' ); ?></button>
				<a class="mms-button mms-button-secondary" href="<?php echo esc_url( wp_lostpassword_url( MMS_Members::login_url() ) ); ?>"><?php esc_html_e( 'Forgot password?', 'mms-membership' ); ?></a>
			</div>
		</form>

		<p class="mms-muted">
			<?php esc_html_e( 'Your account is created when your membership application is approved. Use the set-password link in the approval email if you have not set a password yet.', 'mms-membership' ); ?>
		</p>
	<?php endif; ?>
</div>
