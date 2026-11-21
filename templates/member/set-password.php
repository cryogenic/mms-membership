<?php
/**
 * Member set-password form.
 *
 * @package MMS_Membership
 *
 * @var string $mms_key     Token.
 * @var string $mms_login   User login.
 * @var int    $mms_user_id User ID.
 * @var array  $mms_errors  Validation errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_errors = (array) $mms_errors;
?>
<div class="mms-login-shell mms-setpw-shell">

	<aside class="mms-login-brand">
		<div class="mms-login-brand-main">
			<a class="mms-login-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM', 'mms-membership' ); ?>" />
			</a>

			<p class="mms-login-kicker"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
			<h2 class="mms-login-brand-title"><?php esc_html_e( 'You are almost there.', 'mms-membership' ); ?></h2>
			<p class="mms-login-brand-text"><?php esc_html_e( 'Set a password to finish activating your member account.', 'mms-membership' ); ?></p>

			<ol class="mms-setpw-steps">
				<li class="is-done"><?php esc_html_e( 'Application approved', 'mms-membership' ); ?></li>
				<li class="is-current"><?php esc_html_e( 'Set your password', 'mms-membership' ); ?></li>
				<li><?php esc_html_e( 'Log in and complete payment', 'mms-membership' ); ?></li>
			</ol>
		</div>

		<p class="mms-login-trust">
			<span class="mms-login-trust-dot" aria-hidden="true"></span>
			<?php esc_html_e( 'Secure access for MACM members', 'mms-membership' ); ?>
		</p>
	</aside>

	<section class="mms-login-panel">
		<p class="mms-login-eyebrow"><?php esc_html_e( 'Account setup', 'mms-membership' ); ?></p>
		<h2 class="mms-login-title"><?php esc_html_e( 'Set your password', 'mms-membership' ); ?></h2>
		<p class="mms-login-sub">
			<?php
			printf(
				/* translators: %s: member username */
				esc_html__( 'Choose a password for your member account (%s). After saving it, you can log in to view your membership status and complete the payment.', 'mms-membership' ),
				'<strong>' . esc_html( $mms_login ) . '</strong>'
			);
			?>
		</p>

		<?php if ( ! empty( $mms_errors ) ) : ?>
			<div class="mms-login-notice" role="alert">
				<ul>
					<?php foreach ( $mms_errors as $mms_error ) : ?>
						<li><?php echo esc_html( $mms_error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form class="mms-setpw-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Set_Password::ACTION ); ?>" />
			<input type="hidden" name="mms_key" value="<?php echo esc_attr( $mms_key ); ?>" />
			<input type="hidden" name="mms_login" value="<?php echo esc_attr( $mms_login ); ?>" />
			<?php wp_nonce_field( MMS_Set_Password::NONCE . '_' . (int) $mms_user_id ); ?>

			<div class="mms-login-field">
				<label for="mms-password"><?php esc_html_e( 'New password', 'mms-membership' ); ?></label>
				<div class="mms-setpw-input">
					<input type="password" id="mms-password" name="mms_password" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Set_Password::MIN_LENGTH ); ?>" data-mms-pw />
					<button type="button" class="mms-setpw-toggle" data-mms-toggle="mms-password" data-label-show="<?php esc_attr_e( 'Show', 'mms-membership' ); ?>" data-label-hide="<?php esc_attr_e( 'Hide', 'mms-membership' ); ?>" aria-label="<?php esc_attr_e( 'Show password', 'mms-membership' ); ?>" aria-pressed="false"><?php esc_html_e( 'Show', 'mms-membership' ); ?></button>
				</div>
				<ul class="mms-setpw-rules" data-mms-rules>
					<li data-rule="length">
						<?php
						printf(
							/* translators: %d: minimum password length */
							esc_html__( 'At least %d characters', 'mms-membership' ),
							(int) MMS_Set_Password::MIN_LENGTH
						);
						?>
					</li>
					<li data-rule="match"><?php esc_html_e( 'Both passwords match', 'mms-membership' ); ?></li>
				</ul>
			</div>

			<div class="mms-login-field">
				<label for="mms-password-confirm"><?php esc_html_e( 'Confirm new password', 'mms-membership' ); ?></label>
				<div class="mms-setpw-input">
					<input type="password" id="mms-password-confirm" name="mms_password_confirm" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Set_Password::MIN_LENGTH ); ?>" data-mms-pw />
					<button type="button" class="mms-setpw-toggle" data-mms-toggle="mms-password-confirm" data-label-show="<?php esc_attr_e( 'Show', 'mms-membership' ); ?>" data-label-hide="<?php esc_attr_e( 'Hide', 'mms-membership' ); ?>" aria-label="<?php esc_attr_e( 'Show password', 'mms-membership' ); ?>" aria-pressed="false"><?php esc_html_e( 'Show', 'mms-membership' ); ?></button>
				</div>
			</div>

			<button type="submit" class="mms-login-submit"><?php esc_html_e( 'Save password', 'mms-membership' ); ?></button>
		</form>

		<p class="mms-login-note mms-setpw-expiry">
			<?php esc_html_e( 'This one-time link can be used once and expires 72 hours after the approval email was sent.', 'mms-membership' ); ?>
		</p>

		<div class="mms-login-divider"><span><?php esc_html_e( 'Already set your password?', 'mms-membership' ); ?></span></div>

		<a class="mms-login-register" href="<?php echo esc_url( MMS_Members::login_url() ); ?>">
			<?php esc_html_e( 'Log in to your account', 'mms-membership' ); ?>
			<span aria-hidden="true">&rarr;</span>
		</a>
	</section>
</div>
