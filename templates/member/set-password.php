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
<div class="mms-registration mms-set-password">

	<h2><?php esc_html_e( 'Set your password', 'mms-membership' ); ?></h2>

	<p><?php esc_html_e( 'Choose a password for your MMS member account. After saving it, you can log in to view your membership status and complete the payment.', 'mms-membership' ); ?></p>

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

	<form class="mms-form mms-set-password-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Set_Password::ACTION ); ?>" />
		<input type="hidden" name="mms_key" value="<?php echo esc_attr( $mms_key ); ?>" />
		<input type="hidden" name="mms_login" value="<?php echo esc_attr( $mms_login ); ?>" />
		<?php wp_nonce_field( MMS_Set_Password::NONCE . '_' . (int) $mms_user_id ); ?>

		<div class="mms-field-grid">
			<div class="mms-field mms-field-wide">
				<label for="mms-password"><?php esc_html_e( 'New password', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
				<input type="password" id="mms-password" name="mms_password" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Set_Password::MIN_LENGTH ); ?>" />
				<p class="mms-muted">
					<?php
					printf(
						/* translators: %d: minimum password length */
						esc_html__( 'At least %d characters.', 'mms-membership' ),
						(int) MMS_Set_Password::MIN_LENGTH
					);
					?>
				</p>
			</div>

			<div class="mms-field mms-field-wide">
				<label for="mms-password-confirm"><?php esc_html_e( 'Confirm new password', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
				<input type="password" id="mms-password-confirm" name="mms_password_confirm" autocomplete="new-password" required minlength="<?php echo esc_attr( MMS_Set_Password::MIN_LENGTH ); ?>" />
			</div>
		</div>

		<p class="mms-muted"><?php esc_html_e( 'This link can be used once and expires 72 hours after the approval email was sent.', 'mms-membership' ); ?></p>

		<div class="mms-step-actions">
			<button type="submit" class="mms-button"><?php esc_html_e( 'Save password', 'mms-membership' ); ?></button>
		</div>
	</form>
</div>
