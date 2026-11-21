<?php
/**
 * Resume upload form — replacement for documents marked Insufficient.
 *
 * @package MMS_Membership
 *
 * @var array  $mms_app         Application row.
 * @var string $mms_token       Resume token.
 * @var array  $mms_meta        Company / PIC row.
 * @var array  $mms_type        Membership type definition.
 * @var array  $mms_rejected    Documents marked Insufficient.
 * @var array  $mms_docs        Document catalogue.
 * @var array  $mms_doc_status  Document review status labels.
 * @var array  $mms_errors      Error messages.
 * @var string $mms_action      Form action URL.
 * @var string $mms_return      Page URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_company = isset( $mms_meta['company_name'] ) ? $mms_meta['company_name'] : '';
$mms_ref     = '' !== (string) $mms_app['reference'] ? $mms_app['reference'] : '#' . (int) $mms_app['id'];
?>
<div class="mms-registration mms-resume">

	<h2><?php esc_html_e( 'Upload the requested documents', 'mms-membership' ); ?></h2>

	<p class="mms-muted">
		<?php
		printf(
			/* translators: 1: reference number, 2: company name */
			esc_html__( 'Application %1$s — %2$s', 'mms-membership' ),
			esc_html( $mms_ref ),
			esc_html( $mms_company )
		);
		?>
	</p>

	<?php if ( ! empty( $mms_errors ) ) : ?>
		<div class="mms-notice mms-notice-error" role="alert">
			<p><strong><?php esc_html_e( 'Please fix the following before uploading:', 'mms-membership' ); ?></strong></p>
			<ul>
				<?php foreach ( $mms_errors as $mms_error ) : ?>
					<li><?php echo esc_html( $mms_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<p><?php esc_html_e( 'Only the documents listed below need a new file. Uploading them moves your application back to review automatically.', 'mms-membership' ); ?></p>

	<form class="mms-form mms-resume-form" method="post" action="<?php echo esc_url( $mms_action ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Resume::ACTION ); ?>" />
		<input type="hidden" name="mms_resume" value="<?php echo esc_attr( $mms_token ); ?>" />
		<input type="hidden" name="mms_return" value="<?php echo esc_url( $mms_return ); ?>" />
		<?php wp_nonce_field( MMS_Resume::NONCE . '_' . (int) $mms_app['id'] ); ?>

		<?php foreach ( $mms_rejected as $mms_document ) : ?>
			<?php
			$mms_key   = $mms_document['doc_type'];
			$mms_def   = isset( $mms_docs[ $mms_key ] ) ? $mms_docs[ $mms_key ] : null;
			$mms_label = $mms_def ? $mms_def['label'] : $mms_key;
			?>
			<div class="mms-resume-doc">
				<div class="mms-resume-head">
					<strong><?php echo esc_html( $mms_label ); ?></strong>
					<span class="mms-doc-badge is-required"><?php esc_html_e( 'Insufficient', 'mms-membership' ); ?></span>
				</div>

				<?php if ( ! empty( $mms_document['admin_note'] ) ) : ?>
					<p class="mms-resume-note"><?php echo esc_html( $mms_document['admin_note'] ); ?></p>
				<?php endif; ?>

				<p class="mms-muted">
					<?php
					printf(
						/* translators: 1: current file name, 2: upload date */
						esc_html__( 'Current file: %1$s (uploaded %2$s)', 'mms-membership' ),
						esc_html( $mms_document['original_name'] ),
						esc_html( $mms_document['uploaded_at'] ? mysql2date( get_option( 'date_format' ), $mms_document['uploaded_at'] ) : '—' )
					);
					?>
				</p>

				<?php if ( $mms_def ) : ?>
					<label for="mms-resume-<?php echo esc_attr( $mms_key ); ?>"><?php esc_html_e( 'New file', 'mms-membership' ); ?></label>
					<input type="file" id="mms-resume-<?php echo esc_attr( $mms_key ); ?>" name="resume_doc_<?php echo esc_attr( $mms_key ); ?>" accept=".<?php echo esc_attr( str_replace( ',', ',.', strtolower( $mms_def['accepted'] ) ) ); ?>" />
					<span class="mms-muted">
						<?php
						printf(
							/* translators: 1: accepted formats, 2: maximum size in MB */
							esc_html__( '%1$s · max %2$d MB', 'mms-membership' ),
							esc_html( strtoupper( str_replace( ',', ', ', $mms_def['accepted'] ) ) ),
							(int) $mms_def['max_mb']
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<p class="mms-step-actions">
			<button type="submit" class="mms-button"><?php esc_html_e( 'Upload documents', 'mms-membership' ); ?></button>
		</p>
	</form>

	<?php
	$mms_others = array_filter(
		isset( $mms_documents ) ? $mms_documents : array(),
		function ( $document ) {
			return 'reject' !== $document['review_status'];
		}
	);
	?>
	<?php if ( ! empty( $mms_others ) ) : ?>
		<h3><?php esc_html_e( 'Other documents on file', 'mms-membership' ); ?></h3>
		<ul class="mms-resume-others">
			<?php foreach ( $mms_others as $mms_other ) : ?>
				<?php $mms_other_label = isset( $mms_docs[ $mms_other['doc_type'] ] ) ? $mms_docs[ $mms_other['doc_type'] ]['label'] : $mms_other['doc_type']; ?>
				<li>
					<?php echo esc_html( $mms_other_label ); ?> —
					<?php echo esc_html( isset( $mms_doc_status[ $mms_other['review_status'] ] ) ? $mms_doc_status[ $mms_other['review_status'] ] : $mms_other['review_status'] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
