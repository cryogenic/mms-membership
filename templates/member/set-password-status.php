<?php
/**
 * Set-password status panel (done or invalid link).
 *
 * @package MMS_Membership
 *
 * @var string $mms_state      done|invalid.
 * @var string $mms_message    Error message for invalid links.
 * @var string $mms_login_url  Login URL for the done state.
 * @var string $mms_login_name Member login name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_state = isset( $mms_state ) ? $mms_state : 'invalid';
?>
<div class="mms-registration mms-set-password-status">

	<?php if ( 'done' === $mms_state ) : ?>
		<h2><?php esc_html_e( 'Password saved', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'Your password has been set. You can now log in to view your membership status and complete the payment.', 'mms-membership' ); ?></p>
		<?php if ( ! empty( $mms_login_name ) ) : ?>
			<p class="mms-reference">
				<span><?php esc_html_e( 'Username', 'mms-membership' ); ?></span>
				<strong><?php echo esc_html( $mms_login_name ); ?></strong>
			</p>
		<?php endif; ?>
		<p>
			<a class="mms-button" href="<?php echo esc_url( isset( $mms_login_url ) ? $mms_login_url : wp_login_url() ); ?>">
				<?php esc_html_e( 'Log in', 'mms-membership' ); ?>
			</a>
		</p>
	<?php else : ?>
		<h2><?php esc_html_e( 'This link is not valid', 'mms-membership' ); ?></h2>
		<p><?php echo esc_html( isset( $mms_message ) && '' !== $mms_message ? $mms_message : __( 'Please use the link from the most recent email we sent you, or contact MACM for assistance.', 'mms-membership' ) ); ?></p>
	<?php endif; ?>
</div>
