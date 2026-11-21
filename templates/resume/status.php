<?php
/**
 * Resume link status panel.
 *
 * @package MMS_Membership
 *
 * @var string     $mms_state invalid|done|closed.
 * @var array|null $mms_app   Application row when available.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_state = isset( $mms_state ) ? $mms_state : 'invalid';
$mms_app   = isset( $mms_app ) ? $mms_app : null;
?>
<div class="mms-registration mms-resume-status">

	<?php if ( 'invalid' === $mms_state ) : ?>
		<h2><?php esc_html_e( 'This link is not valid', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'The upload link is incorrect or has expired. Please use the link from the most recent email we sent you, or contact MACM for assistance.', 'mms-membership' ); ?></p>
	<?php elseif ( 'done' === $mms_state ) : ?>
		<h2><?php esc_html_e( 'Documents received', 'mms-membership' ); ?></h2>
		<p><?php esc_html_e( 'Thank you. Your new documents have been uploaded and your application is back under review.', 'mms-membership' ); ?></p>
		<?php if ( $mms_app ) : ?>
			<p class="mms-reference">
				<span><?php esc_html_e( 'Reference number', 'mms-membership' ); ?></span>
				<strong><?php echo esc_html( '' !== $mms_app['reference'] ? $mms_app['reference'] : '#' . (int) $mms_app['id'] ); ?></strong>
			</p>
			<p><?php esc_html_e( 'We will contact you again once the review is complete.', 'mms-membership' ); ?></p>
		<?php endif; ?>
	<?php else : ?>
		<h2><?php esc_html_e( 'No documents are requested', 'mms-membership' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: current application status */
				esc_html__( 'This application is currently “%s”, so there is nothing to upload. Please check your email for the latest update from MACM.', 'mms-membership' ),
				esc_html( $mms_app ? MMS_Status::label( $mms_app['status'] ) : '—' )
			);
			?>
		</p>
		<?php if ( $mms_app ) : ?>
			<p class="mms-reference">
				<span><?php esc_html_e( 'Reference number', 'mms-membership' ); ?></span>
				<strong><?php echo esc_html( '' !== $mms_app['reference'] ? $mms_app['reference'] : '#' . (int) $mms_app['id'] ); ?></strong>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
