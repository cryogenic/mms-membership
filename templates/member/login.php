<?php
/**
 * Member login form / already-logged-in panel.
 *
 * @package MMS_Membership
 *
 * @var bool   $mms_logged_in        Whether the visitor is logged in.
 * @var string $mms_portal_url       Portal URL.
 * @var string $mms_registration_url Public registration URL.
 * @var string $mms_error            Error code (empty|invalid).
 * @var string $mms_username         Previously submitted username.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_error = isset( $mms_error ) ? $mms_error : '';
?>
<div class="mms-login-shell">

	<aside class="mms-login-brand">
		<div class="mms-login-brand-main">
			<a class="mms-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
			</a>

			<p class="mms-login-kicker"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
			<h2 class="mms-login-brand-title"><?php esc_html_e( 'Your membership, in one place.', 'mms-membership' ); ?></h2>
			<p class="mms-login-brand-text"><?php esc_html_e( 'Manage your company membership, certificates and renewals from one secure account.', 'mms-membership' ); ?></p>

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

		<?php if ( ! empty( $mms_logged_in ) ) : ?>
			<p class="mms-login-eyebrow"><?php esc_html_e( 'Member access', 'mms-membership' ); ?></p>
			<h2 class="mms-login-title"><?php esc_html_e( 'You are already logged in', 'mms-membership' ); ?></h2>
			<p class="mms-login-sub"><?php esc_html_e( 'Open your membership dashboard to view your status and payment details.', 'mms-membership' ); ?></p>

			<div class="mms-login-actions">
				<a class="mms-login-submit" href="<?php echo esc_url( $mms_portal_url ); ?>"><?php esc_html_e( 'Go to dashboard', 'mms-membership' ); ?></a>
				<a class="mms-login-register" href="<?php echo esc_url( wp_logout_url( MMS_Members::login_url() ) ); ?>"><?php esc_html_e( 'Log out', 'mms-membership' ); ?></a>
			</div>
		<?php else : ?>
			<p class="mms-login-eyebrow"><?php esc_html_e( 'Member access', 'mms-membership' ); ?></p>
			<h2 class="mms-login-title"><?php esc_html_e( 'Welcome back', 'mms-membership' ); ?></h2>
			<p class="mms-login-sub"><?php esc_html_e( 'Sign in with the email address and password for your MMS member account.', 'mms-membership' ); ?></p>

			<?php if ( 'empty' === $mms_error ) : ?>
				<div class="mms-login-notice" role="alert">
					<p><?php esc_html_e( 'Please enter your email address and password.', 'mms-membership' ); ?></p>
				</div>
			<?php elseif ( 'invalid' === $mms_error ) : ?>
				<div class="mms-login-notice" role="alert">
					<p><?php esc_html_e( 'The email address or password is not correct. Please try again.', 'mms-membership' ); ?></p>
				</div>
			<?php endif; ?>

			<form class="mms-login-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Member_Login::ACTION ); ?>" />
				<?php wp_nonce_field( MMS_Member_Login::NONCE ); ?>
				<p class="mms-hp" aria-hidden="true">
					<label for="mms-login-website"><?php esc_html_e( 'Website', 'mms-membership' ); ?></label>
					<input type="text" id="mms-login-website" name="mms_website" value="" tabindex="-1" autocomplete="off" />
				</p>

				<div class="mms-login-field">
					<label for="mms-username"><?php esc_html_e( 'Email address or username', 'mms-membership' ); ?></label>
					<input type="text" id="mms-username" name="mms_username" value="<?php echo esc_attr( $mms_username ); ?>" autocomplete="username" required />
				</div>

				<div class="mms-login-field">
					<label for="mms-login-password"><?php esc_html_e( 'Password', 'mms-membership' ); ?></label>
					<input type="password" id="mms-login-password" name="mms_password" autocomplete="current-password" required />
				</div>

				<div class="mms-login-row">
					<label class="mms-login-remember">
						<input type="checkbox" name="mms_remember" value="1" />
						<span><?php esc_html_e( 'Keep me logged in', 'mms-membership' ); ?></span>
					</label>
					<a class="mms-login-forgot" href="<?php echo esc_url( wp_lostpassword_url( MMS_Members::login_url() ) ); ?>" data-mms-reset-open aria-haspopup="dialog" aria-controls="mms-reset-modal"><?php esc_html_e( 'Forgot password?', 'mms-membership' ); ?></a>
				</div>

				<button type="submit" class="mms-login-submit"><?php esc_html_e( 'Log in', 'mms-membership' ); ?></button>
			</form>

			<div class="mms-login-divider"><span><?php esc_html_e( 'New to MACM?', 'mms-membership' ); ?></span></div>

			<a class="mms-login-register" href="<?php echo esc_url( $mms_registration_url ); ?>">
				<?php esc_html_e( 'New registration', 'mms-membership' ); ?>
				<span aria-hidden="true">&rarr;</span>
			</a>

			<p class="mms-login-note">
				<?php esc_html_e( 'Your account is created when your membership application is approved. Use the set-password link in the approval email if you have not set a password yet.', 'mms-membership' ); ?>
			</p>

			<div class="mms-reset-modal" id="mms-reset-modal" hidden>
				<div class="mms-reset-backdrop" data-mms-reset-close></div>
				<div class="mms-reset-dialog" role="dialog" aria-modal="true" aria-labelledby="mms-reset-title">
					<button type="button" class="mms-reset-close" data-mms-reset-close aria-label="<?php esc_attr_e( 'Close', 'mms-membership' ); ?>">&times;</button>

					<p class="mms-login-eyebrow"><?php esc_html_e( 'Account recovery', 'mms-membership' ); ?></p>
					<h3 class="mms-reset-title" id="mms-reset-title"><?php esc_html_e( 'Reset your password', 'mms-membership' ); ?></h3>
					<p class="mms-reset-text"><?php esc_html_e( 'Enter the email address for your member account. If an account exists, we will send a reset link.', 'mms-membership' ); ?></p>

					<form class="mms-reset-form" method="post" action="<?php echo esc_url( wp_lostpassword_url( MMS_Members::login_url() ) ); ?>">
						<p class="mms-hp" aria-hidden="true">
							<label for="mms-reset-website"><?php esc_html_e( 'Website', 'mms-membership' ); ?></label>
							<input type="text" id="mms-reset-website" name="mms_website" value="" tabindex="-1" autocomplete="off" />
						</p>

						<div class="mms-login-field">
							<label for="mms-reset-email"><?php esc_html_e( 'Email address', 'mms-membership' ); ?></label>
							<input type="email" id="mms-reset-email" name="user_login" autocomplete="email" required />
						</div>

						<p class="mms-reset-message" role="status" aria-live="polite" hidden></p>

						<button type="submit" class="mms-login-submit"><?php esc_html_e( 'Send reset link', 'mms-membership' ); ?></button>
					</form>

					<p class="mms-login-note mms-reset-back">
						<button type="button" class="mms-reset-backlink" data-mms-reset-close><?php esc_html_e( 'Back to sign in', 'mms-membership' ); ?></button>
					</p>
				</div>
			</div>
		<?php endif; ?>

	</section>
</div>
