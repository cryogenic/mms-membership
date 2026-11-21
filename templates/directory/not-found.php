<?php
/**
 * Public directory: member not found.
 *
 * @package MMS_Membership
 *
 * @var string $mms_back_url Directory URL with the current search/page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mms-registration mms-directory">

	<p class="mms-dir-back">
		<a href="<?php echo esc_url( $mms_back_url ); ?>"><?php esc_html_e( '← Back to directory', 'mms-membership' ); ?></a>
	</p>

	<div class="mms-notice mms-notice-error" role="alert">
		<p><?php esc_html_e( 'This member could not be found. It may have been removed or is not published in the directory.', 'mms-membership' ); ?></p>
	</div>

</div>
