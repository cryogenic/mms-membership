<?php
/**
 * Public registration form — four steps in a single form.
 *
 * @package MMS_Membership
 *
 * @var array $mms_types      Membership types.
 * @var array $mms_docs       Document catalogue.
 * @var array $mms_old        Previously submitted values.
 * @var array $mms_errors     Validation errors.
 * @var string $mms_action    Form action URL.
 * @var string $mms_return    Return URL.
 * @var array $mms_doc_types  Document key => required/optional membership types.
 * @var array $mms_states     State / federal territory catalogue.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mms_old  = wp_parse_args(
	(array) $mms_old,
	array(
		'membership_type' => '',
		'company_name'    => '',
		'company_reg_no'  => '',
		'company_state'   => '',
		'company_address' => '',
		'company_phone'   => '',
		'company_email'   => '',
		'company_website' => '',
		'pic_name'        => '',
		'pic_ic'          => '',
		'pic_position'    => '',
		'pic_email'       => '',
		'pic_phone'       => '',
	)
);
$mms_errors = (array) $mms_errors;
?>
<div class="mms-registration mms-no-js">

	<?php if ( ! empty( $mms_errors ) ) : ?>
		<div class="mms-notice mms-notice-error" role="alert">
			<p><strong><?php esc_html_e( 'Please fix the following before submitting:', 'mms-membership' ); ?></strong></p>
			<ul>
				<?php foreach ( $mms_errors as $mms_error ) : ?>
					<li><?php echo esc_html( $mms_error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form class="mms-form mms-steps" method="post" action="<?php echo esc_url( $mms_action ); ?>" enctype="multipart/form-data" novalidate>
		<input type="hidden" name="action" value="<?php echo esc_attr( MMS_Registration::ACTION ); ?>" />
		<input type="hidden" name="mms_return" value="<?php echo esc_url( $mms_return ); ?>" />
		<?php wp_nonce_field( MMS_Registration::NONCE ); ?>
		<div class="mms-hp" aria-hidden="true">
			<label for="mms-hp-field"><?php esc_html_e( 'Leave this field empty', 'mms-membership' ); ?></label>
			<input type="text" id="mms-hp-field" name="mms_hp" value="" tabindex="-1" autocomplete="off" />
		</div>

		<ol class="mms-progress" aria-label="<?php esc_attr_e( 'Registration steps', 'mms-membership' ); ?>">
			<li class="is-active" data-step-indicator="0"><span>1</span><?php esc_html_e( 'Membership type', 'mms-membership' ); ?></li>
			<li data-step-indicator="1"><span>2</span><?php esc_html_e( 'Company & PIC', 'mms-membership' ); ?></li>
			<li data-step-indicator="2"><span>3</span><?php esc_html_e( 'Documents', 'mms-membership' ); ?></li>
			<li data-step-indicator="3"><span>4</span><?php esc_html_e( 'Review & submit', 'mms-membership' ); ?></li>
		</ol>

		<!-- Step 1: membership type -->
		<section class="mms-step is-active" data-step="0">
			<h2 class="mms-step-title"><?php esc_html_e( 'Choose a membership type', 'mms-membership' ); ?></h2>

			<div class="mms-type-grid">
				<?php foreach ( $mms_types as $mms_slug => $mms_type ) : ?>
					<label class="mms-type-card">
						<input type="radio" name="membership_type" value="<?php echo esc_attr( $mms_slug ); ?>" <?php checked( $mms_old['membership_type'], $mms_slug ); ?> required />
						<span class="mms-type-body">
							<span class="mms-type-name"><?php echo esc_html( $mms_type['label'] ); ?></span>
							<span class="mms-type-fee"><?php echo esc_html( number_format_i18n( (float) $mms_type['fee'], 2 ) . ' ' . $mms_type['currency'] ); ?></span>
							<span class="mms-type-meta">
								<?php
								printf(
									/* translators: %d: number of months */
									esc_html__( '%d months membership', 'mms-membership' ),
									(int) $mms_type['duration_months']
								);
								?>
							</span>
							<span class="mms-type-docs">
								<?php
								$mms_required = array();
								foreach ( (array) $mms_type['required_docs'] as $mms_key ) {
									if ( isset( $mms_docs[ $mms_key ] ) ) {
										$mms_required[] = $mms_docs[ $mms_key ]['label'];
									}
								}
								$mms_optional_count = 0;
								foreach ( (array) $mms_type['optional_docs'] as $mms_key ) {
									if ( isset( $mms_docs[ $mms_key ] ) ) {
										$mms_optional_count++;
									}
								}
								printf(
									/* translators: 1: required documents list, 2: number of optional documents */
									esc_html__( 'Required: %1$s. Optional: %2$d.', 'mms-membership' ),
									esc_html( implode( ', ', $mms_required ) ),
									(int) $mms_optional_count
								);
								?>
							</span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<p class="mms-step-actions">
				<button type="button" class="mms-button mms-next"><?php esc_html_e( 'Continue', 'mms-membership' ); ?></button>
			</p>
		</section>

		<!-- Step 2: company & PIC -->
		<section class="mms-step" data-step="1" hidden>
			<h2 class="mms-step-title"><?php esc_html_e( 'Company details', 'mms-membership' ); ?></h2>

			<div class="mms-field-grid">
				<p class="mms-field">
					<label for="mms-company-name"><?php esc_html_e( 'Company name', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-company-name" name="company_name" value="<?php echo esc_attr( $mms_old['company_name'] ); ?>" required />
				</p>
				<p class="mms-field">
					<label for="mms-company-reg"><?php esc_html_e( 'SSM / ROS registration number', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-company-reg" name="company_reg_no" value="<?php echo esc_attr( $mms_old['company_reg_no'] ); ?>" required />
				</p>
				<p class="mms-field">
					<label for="mms-company-state"><?php esc_html_e( 'State', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<select id="mms-company-state" name="company_state" required>
						<option value=""><?php esc_html_e( 'Select state', 'mms-membership' ); ?></option>
						<?php foreach ( (array) $mms_states as $mms_state ) : ?>
							<option value="<?php echo esc_attr( $mms_state['name'] ); ?>" <?php selected( $mms_old['company_state'], $mms_state['name'] ); ?>><?php echo esc_html( $mms_state['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="mms-field mms-field-wide">
					<label for="mms-company-address"><?php esc_html_e( 'Company address', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<textarea id="mms-company-address" name="company_address" rows="3" required><?php echo esc_textarea( $mms_old['company_address'] ); ?></textarea>
				</p>
				<p class="mms-field">
					<label for="mms-company-phone"><?php esc_html_e( 'Company phone', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="tel" id="mms-company-phone" name="company_phone" value="<?php echo esc_attr( $mms_old['company_phone'] ); ?>" placeholder="03-1234 5678" required />
				</p>
				<p class="mms-field">
					<label for="mms-company-email"><?php esc_html_e( 'Company email', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="email" id="mms-company-email" name="company_email" value="<?php echo esc_attr( $mms_old['company_email'] ); ?>" required />
				</p>
				<p class="mms-field mms-field-wide">
					<label for="mms-company-website"><?php esc_html_e( 'Company website (optional)', 'mms-membership' ); ?></label>
					<input type="url" id="mms-company-website" name="company_website" value="<?php echo esc_attr( $mms_old['company_website'] ); ?>" placeholder="https://" />
				</p>
			</div>

			<h2 class="mms-step-title"><?php esc_html_e( 'Person In Charge (PIC)', 'mms-membership' ); ?></h2>

			<div class="mms-field-grid">
				<p class="mms-field">
					<label for="mms-pic-name"><?php esc_html_e( 'PIC name', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-pic-name" name="pic_name" value="<?php echo esc_attr( $mms_old['pic_name'] ); ?>" required />
				</p>
				<p class="mms-field">
					<label for="mms-pic-ic"><?php esc_html_e( 'PIC IC / passport number', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-pic-ic" name="pic_ic" value="<?php echo esc_attr( $mms_old['pic_ic'] ); ?>" inputmode="numeric" placeholder="900101015555" required />
				</p>
				<p class="mms-field">
					<label for="mms-pic-position"><?php esc_html_e( 'PIC position', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="text" id="mms-pic-position" name="pic_position" value="<?php echo esc_attr( $mms_old['pic_position'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Managing Director', 'mms-membership' ); ?>" required />
				</p>
				<p class="mms-field">
					<label for="mms-pic-email"><?php esc_html_e( 'PIC email', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="email" id="mms-pic-email" name="pic_email" value="<?php echo esc_attr( $mms_old['pic_email'] ); ?>" required />
				</p>
				<p class="mms-field">
					<label for="mms-pic-phone"><?php esc_html_e( 'PIC phone', 'mms-membership' ); ?> <span class="mms-required">*</span></label>
					<input type="tel" id="mms-pic-phone" name="pic_phone" value="<?php echo esc_attr( $mms_old['pic_phone'] ); ?>" placeholder="012-345 6789" required />
				</p>
			</div>

			<p class="mms-step-actions">
				<button type="button" class="mms-button mms-button-secondary mms-prev"><?php esc_html_e( 'Back', 'mms-membership' ); ?></button>
				<button type="button" class="mms-button mms-next"><?php esc_html_e( 'Continue', 'mms-membership' ); ?></button>
			</p>
		</section>

		<!-- Step 3: documents -->
		<section class="mms-step" data-step="2" hidden>
			<h2 class="mms-step-title"><?php esc_html_e( 'Supporting documents', 'mms-membership' ); ?></h2>
			<p class="mms-muted"><?php esc_html_e( 'The list below follows the membership type you selected. Each file must match the formats and size limit shown.', 'mms-membership' ); ?></p>

			<div class="mms-doc-list">
				<?php foreach ( $mms_docs as $mms_key => $mms_doc ) : ?>
					<?php
					$mms_required_in = isset( $mms_doc_types[ $mms_key ]['required'] ) ? (array) $mms_doc_types[ $mms_key ]['required'] : array();
					$mms_optional_in = isset( $mms_doc_types[ $mms_key ]['optional'] ) ? (array) $mms_doc_types[ $mms_key ]['optional'] : array();
					if ( empty( $mms_required_in ) && empty( $mms_optional_in ) ) {
						continue;
					}
					?>
					<div class="mms-doc-row" data-doc="<?php echo esc_attr( $mms_key ); ?>" data-required-in="<?php echo esc_attr( implode( ',', $mms_required_in ) ); ?>" data-optional-in="<?php echo esc_attr( implode( ',', $mms_optional_in ) ); ?>">
						<div class="mms-doc-head">
							<label for="mms-doc-<?php echo esc_attr( $mms_key ); ?>">
								<?php echo esc_html( $mms_doc['label'] ); ?>
								<span class="mms-doc-badge" data-badge>—</span>
							</label>
							<span class="mms-doc-hint">
								<?php
								printf(
									/* translators: 1: accepted formats, 2: maximum size in MB */
									esc_html__( '%1$s · max %2$d MB', 'mms-membership' ),
									esc_html( strtoupper( str_replace( ',', ', ', $mms_doc['accepted'] ) ) ),
									(int) $mms_doc['max_mb']
								);
								?>
							</span>
						</div>
						<input type="file" id="mms-doc-<?php echo esc_attr( $mms_key ); ?>" name="mms_doc_<?php echo esc_attr( $mms_key ); ?>" accept=".<?php echo esc_attr( str_replace( ',', ',.', strtolower( $mms_doc['accepted'] ) ) ); ?>" />
					</div>
				<?php endforeach; ?>
			</div>

			<p class="mms-step-actions">
				<button type="button" class="mms-button mms-button-secondary mms-prev"><?php esc_html_e( 'Back', 'mms-membership' ); ?></button>
				<button type="button" class="mms-button mms-next"><?php esc_html_e( 'Review', 'mms-membership' ); ?></button>
			</p>
		</section>

		<!-- Step 4: review & submit -->
		<section class="mms-step" data-step="3" hidden>
			<h2 class="mms-step-title"><?php esc_html_e( 'Review your application', 'mms-membership' ); ?></h2>

			<div class="mms-review" data-mms-review></div>

			<div class="mms-terms">
				<label>
					<input type="checkbox" name="mms_terms" value="1" />
					<?php esc_html_e( 'I confirm that the information and documents provided are accurate and complete, and I accept the membership terms and conditions.', 'mms-membership' ); ?>
				</label>
			</div>

			<p class="mms-step-actions">
				<button type="button" class="mms-button mms-button-secondary mms-prev"><?php esc_html_e( 'Back', 'mms-membership' ); ?></button>
				<button type="submit" class="mms-button mms-submit"><?php esc_html_e( 'Submit application', 'mms-membership' ); ?></button>
			</p>
		</section>
	</form>
</div>
