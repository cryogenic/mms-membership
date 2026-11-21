<?php
/**
 * Public directory: single member details.
 *
 * @package MMS_Membership
 *
 * @var string              $mms_back_url Directory URL with the current search/page.
 * @var array<string,mixed> $mms_member   Member row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_current  = MMS_Directory::is_current( $mms_member );
$mms_validity = MMS_Directory::validity( $mms_member );
$mms_name     = '' !== (string) $mms_member['company_name'] ? $mms_member['company_name'] : __( 'Unnamed company', 'mms-membership' );
$mms_address  = trim( (string) $mms_member['company_address'] );
?>
<div class="mms-registration mms-directory">

	<p class="mms-dir-back">
		<a href="<?php echo esc_url( $mms_back_url ); ?>"><?php esc_html_e( '← Back to directory', 'mms-membership' ); ?></a>
	</p>

	<div class="mms-dir-card">
		<div class="mms-dir-head">
			<div class="mms-dir-head-main">
				<span class="mms-dir-avatar mms-dir-avatar-lg" aria-hidden="true"><?php echo esc_html( MMS_Directory::initial( $mms_name ) ); ?></span>
				<h2><?php echo esc_html( $mms_name ); ?></h2>
			</div>
			<span class="mms-dir-pill <?php echo $mms_current ? 'is-active' : 'is-expired'; ?>">
				<?php echo $mms_current ? esc_html__( 'Active', 'mms-membership' ) : esc_html__( 'Expired', 'mms-membership' ); ?>
			</span>
		</div>

		<dl class="mms-dir-facts">
			<div>
				<dt><?php esc_html_e( 'Membership no.', 'mms-membership' ); ?></dt>
				<dd><?php echo esc_html( '' !== (string) $mms_member['membership_no'] ? $mms_member['membership_no'] : '—' ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'SSM / ROS no.', 'mms-membership' ); ?></dt>
				<dd><?php echo esc_html( '' !== (string) $mms_member['company_reg_no'] ? $mms_member['company_reg_no'] : '—' ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Validity', 'mms-membership' ); ?></dt>
				<dd><?php echo esc_html( '' !== $mms_validity ? $mms_validity : '—' ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Phone', 'mms-membership' ); ?></dt>
				<dd><?php echo esc_html( '' !== (string) $mms_member['company_phone'] ? $mms_member['company_phone'] : '—' ); ?></dd>
			</div>
			<div class="mms-dir-wide">
				<dt><?php esc_html_e( 'Address', 'mms-membership' ); ?></dt>
				<dd><?php echo '' !== $mms_address ? nl2br( esc_html( $mms_address ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
			</div>
		</dl>
	</div>

</div>
