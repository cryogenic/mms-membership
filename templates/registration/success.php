<?php
/**
 * Registration success panel.
 *
 * @package MMS_Membership
 *
 * @var string $reference Reference number.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mms-registration mms-success">
	<h2><?php esc_html_e( 'Application received', 'mms-membership' ); ?></h2>

	<p><?php esc_html_e( 'Thank you. Your application and supporting documents have been submitted for review.', 'mms-membership' ); ?></p>

	<p class="mms-reference">
		<span><?php esc_html_e( 'Reference number', 'mms-membership' ); ?></span>
		<strong><?php echo esc_html( $reference ); ?></strong>
	</p>

	<p><?php esc_html_e( 'A confirmation email has been sent to the Person In Charge email address. Please keep the reference number for future enquiries.', 'mms-membership' ); ?></p>

	<h3><?php esc_html_e( 'What happens next', 'mms-membership' ); ?></h3>
	<ol class="mms-next-steps">
		<li><?php esc_html_e( 'MACM reviews your documents and company details.', 'mms-membership' ); ?></li>
		<li><?php esc_html_e( 'You will be contacted if any information or document needs to be updated.', 'mms-membership' ); ?></li>
		<li><?php esc_html_e( 'Once approved, you will receive the payment instructions to activate your membership.', 'mms-membership' ); ?></li>
	</ol>
</div>
