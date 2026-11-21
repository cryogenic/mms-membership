<?php
/**
 * MMS settings screen — membership types, fees and document catalogue.
 *
 * All values are stored in the `mms_settings` option.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Settings
 */
class MMS_Settings {

	const OPTION     = 'mms_settings';
	const CAPABILITY = 'mms_manage_settings';
	const NONCE      = 'mms_save_settings';

	/**
	 * Save settings from the admin-post request.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE );

		$settings = get_option( self::OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$docs  = self::sanitize_document_types( isset( $_POST['docs'] ) ? wp_unslash( $_POST['docs'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$types = self::sanitize_membership_types( isset( $_POST['types'] ) ? wp_unslash( $_POST['types'] ) : array(), array_keys( $docs ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$states = self::sanitize_states( isset( $_POST['states'] ) ? wp_unslash( $_POST['states'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $docs ) ) {
			$docs = MMS_Membership_Types::document_types();
		}

		if ( empty( $states ) ) {
			$states = MMS_Membership_Types::default_states();
		}

		if ( empty( $types ) ) {
			self::redirect( 'empty' );
		}

		$settings['membership_types'] = $types;
		$settings['document_types']   = $docs;
		$settings['state_codes']      = $states;
		if ( empty( $settings['currency'] ) ) {
			$settings['currency'] = 'MYR';
		}

		$current_payment = isset( $settings['payment'] ) && is_array( $settings['payment'] ) ? $settings['payment'] : array();
		$new_secret      = isset( $_POST['payment_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_secret_key'] ) ) : '';
		$settings['payment'] = array(
			'enabled'       => ! empty( $_POST['payment_enabled'] ) ? 1 : 0,
			'mode'          => ( isset( $_POST['payment_mode'] ) && 'live' === $_POST['payment_mode'] ) ? 'live' : 'sandbox',
			'category_code' => isset( $_POST['payment_category_code'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_category_code'] ) ) : '',
			'secret_key'    => '' !== $new_secret ? $new_secret : ( isset( $current_payment['secret_key'] ) ? $current_payment['secret_key'] : '' ),
		);

		$logo_id = isset( $settings['invoice_logo_id'] ) ? absint( $settings['invoice_logo_id'] ) : 0;

		if ( ! empty( $_POST['remove_invoice_logo'] ) ) {
			$logo_id = 0;
		}

		if ( ! empty( $_FILES['invoice_logo']['name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$upload = media_handle_upload(
				'invoice_logo',
				0,
				array(),
				array(
					'test_form' => false,
					'mimes'     => array(
							'png'          => 'image/png',
							'jpg|jpeg|jpe' => 'image/jpeg',
						),
				)
			);

			if ( is_wp_error( $upload ) ) {
				self::redirect( 'logo_error' );
			}

			$logo_id = (int) $upload;
		}

		$settings['invoice_logo_id'] = $logo_id;

		update_option( self::OPTION, $settings, false );

		self::redirect( 'saved' );
	}

	/**
	 * Sanitize membership type rows.
	 *
	 * @param array $rows Raw form rows.
	 * @param array $doc_keys Valid document keys.
	 * @return array<string,array<string,mixed>>
	 */
	private static function sanitize_membership_types( $rows, $doc_keys ) {
		$types = array();

		if ( ! is_array( $rows ) ) {
			return $types;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$slug  = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			$currency = isset( $row['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', $row['currency'] ) ) : '';
			if ( 3 !== strlen( $currency ) ) {
				$currency = 'MYR';
			}

			$required = array();
			$optional = array();

			if ( isset( $row['required_docs'] ) && is_array( $row['required_docs'] ) ) {
				$required = array_values( array_intersect( array_map( 'sanitize_key', $row['required_docs'] ), $doc_keys ) );
			}
			if ( isset( $row['optional_docs'] ) && is_array( $row['optional_docs'] ) ) {
				$optional = array_values( array_intersect( array_map( 'sanitize_key', $row['optional_docs'] ), $doc_keys ) );
			}
			$optional = array_values( array_diff( $optional, $required ) );

			$code = isset( $row['code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $row['code'] ) ) : '';
			$code = substr( $code, 0, 6 );
			if ( '' === $code ) {
				$code = strtoupper( substr( preg_replace( '/[^a-z0-9]/i', '', $slug ), 0, 4 ) );
			}

			$types[ $slug ] = array(
				'label'           => $label,
				'code'            => $code,
				'fee'             => round( max( 0, (float) ( isset( $row['fee'] ) ? $row['fee'] : 0 ) ), 2 ),
				'currency'        => $currency,
				'duration_months' => max( 1, (int) ( isset( $row['duration_months'] ) ? $row['duration_months'] : 12 ) ),
				'required_docs'   => $required,
				'optional_docs'   => $optional,
			);
		}

		return $types;
	}

	/**
	 * Sanitize document catalogue rows.
	 *
	 * @param array $rows Raw form rows.
	 * @return array<string,array<string,mixed>>
	 */
	private static function sanitize_document_types( $rows ) {
		$docs = array();

		if ( ! is_array( $rows ) ) {
			return $docs;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key   = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			if ( '' === $key || '' === $label ) {
				continue;
			}

			$accepted = isset( $row['accepted'] ) ? strtolower( sanitize_text_field( $row['accepted'] ) ) : 'pdf,jpg,jpeg,png';
			$exts     = array_filter( array_map( 'trim', explode( ',', $accepted ) ) );
			$exts     = array_values( array_unique( array_filter( $exts, function ( $ext ) {
				return (bool) preg_match( '/^[a-z0-9]{2,5}$/', $ext );
			} ) ) );

			$docs[ $key ] = array(
				'label'    => $label,
				'accepted' => $exts ? implode( ',', $exts ) : 'pdf',
				'max_mb'   => min( 50, max( 1, (int) ( isset( $row['max_mb'] ) ? $row['max_mb'] : 5 ) ) ),
			);
		}

		return $docs;
	}

	/**
	 * Sanitize state code rows.
	 *
	 * @param array $rows Raw form rows.
	 * @return array<int,array<string,string>>
	 */
	private static function sanitize_states( $rows ) {
		$states = array();

		if ( ! is_array( $rows ) ) {
			return $states;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			$code = isset( $row['code'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $row['code'] ) ) : '';
			$code = substr( $code, 0, 4 );

			if ( '' === $name || '' === $code ) {
				continue;
			}

			$states[] = array(
				'code' => $code,
				'name' => $name,
			);
		}

		return $states;
	}

	/**
	 * Redirect back to the settings screen with a message.
	 *
	 * @param string $msg Message code.
	 * @return void
	 */
	private static function redirect( $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'mms-settings',
					'mms_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings form.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view these settings.', 'mms-membership' ) );
		}

		$types = MMS_Membership_Types::get_types();
		$docs  = MMS_Membership_Types::get_document_types();
		$states = MMS_Membership_Types::get_states();
		$payment = MMS_Payments::settings();
		$all_settings = get_option( self::OPTION, array() );
		$logo_id      = isset( $all_settings['invoice_logo_id'] ) ? absint( $all_settings['invoice_logo_id'] ) : 0;

		$message = isset( $_GET['mms_msg'] ) ? sanitize_key( wp_unslash( $_GET['mms_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero mms-hero-compact">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'MMS Membership Settings', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub"><?php esc_html_e( 'Membership types, fees, supporting document catalogue and online payment configuration.', 'mms-membership' ); ?></p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Dashboard', 'mms-membership' ); ?></a>
				</div>
			</div>

			<?php if ( 'saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'empty' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No valid membership type to save. Make sure at least one row has a key (slug) and a name.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'logo_error' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The logo could not be uploaded. Use a PNG or JPEG image.', 'mms-membership' ); ?></p></div>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-settings-form">
				<input type="hidden" name="action" value="mms_save_settings" />
				<?php wp_nonce_field( self::NONCE ); ?>

			<div class="mms-form-card">
				<h2><?php esc_html_e( 'Membership types & fees', 'mms-membership' ); ?></h2>
				<p><?php esc_html_e( 'The key (slug) is used in URLs and application records — change it only if necessary. Tick the required and optional documents for each type.', 'mms-membership' ); ?></p>

				<div class="mms-table-scroll">
				<table class="widefat mms-table mms-repeat mms-settings-table" id="mms-types-table">
					<thead>
						<tr>
							<th class="mms-col-num">#</th>
							<th><?php esc_html_e( 'Key', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Type name', 'mms-membership' ); ?></th>
							<th class="mms-col-code"><?php esc_html_e( 'Certificate code', 'mms-membership' ); ?></th>
							<th class="mms-col-fee"><?php esc_html_e( 'Fee', 'mms-membership' ); ?></th>
							<th class="mms-col-cur"><?php esc_html_e( 'Currency', 'mms-membership' ); ?></th>
							<th class="mms-col-dur"><?php esc_html_e( 'Duration (months)', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Required documents', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Optional documents', 'mms-membership' ); ?></th>
							<th class="mms-col-act"></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$i = 0;
					foreach ( $types as $slug => $type ) :
						?>
						<tr>
							<td class="mms-row-no"><?php echo (int) ( $i + 1 ); ?></td>
							<td><input type="text" name="types[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" class="mms-input-slug" /></td>
							<td><input type="text" name="types[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $type['label'] ); ?>" class="regular-text" /></td>
							<td><input type="text" maxlength="6" name="types[<?php echo (int) $i; ?>][code]" value="<?php echo esc_attr( isset( $type['code'] ) ? $type['code'] : '' ); ?>" class="mms-input-code" placeholder="OMC" /></td>
							<td><input type="number" step="0.01" min="0" name="types[<?php echo (int) $i; ?>][fee]" value="<?php echo esc_attr( number_format( (float) $type['fee'], 2, '.', '' ) ); ?>" class="small-text" /></td>
							<td><input type="text" maxlength="3" name="types[<?php echo (int) $i; ?>][currency]" value="<?php echo esc_attr( $type['currency'] ); ?>" class="mms-input-cur" /></td>
							<td><input type="number" min="1" step="1" name="types[<?php echo (int) $i; ?>][duration_months]" value="<?php echo esc_attr( (int) $type['duration_months'] ); ?>" class="small-text" /></td>
							<td class="mms-docs-cell">
								<?php foreach ( $docs as $doc_key => $doc ) : ?>
									<label class="mms-doc-option">
										<input type="checkbox" name="types[<?php echo (int) $i; ?>][required_docs][]" value="<?php echo esc_attr( $doc_key ); ?>" <?php checked( in_array( $doc_key, (array) $type['required_docs'], true ) ); ?> />
										<?php echo esc_html( $doc['label'] ); ?>
									</label>
								<?php endforeach; ?>
							</td>
							<td class="mms-docs-cell">
								<?php foreach ( $docs as $doc_key => $doc ) : ?>
									<label class="mms-doc-option">
										<input type="checkbox" name="types[<?php echo (int) $i; ?>][optional_docs][]" value="<?php echo esc_attr( $doc_key ); ?>" <?php checked( in_array( $doc_key, (array) $type['optional_docs'], true ) ); ?> />
										<?php echo esc_html( $doc['label'] ); ?>
									</label>
								<?php endforeach; ?>
							</td>
							<td><button type="button" class="button-link mms-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'mms-membership' ); ?>">&times;</button></td>
						</tr>
						<?php
						$i++;
					endforeach;
					?>
					</tbody>
				</table>
				</div>
				<p class="mms-table-actions"><button type="button" class="button mms-add-row" data-mms-add="mms-types-table" data-mms-template="mms-type-template"><?php esc_html_e( '+ Add membership type', 'mms-membership' ); ?></button></p>
			</div>

			<div class="mms-form-card">
				<h2><?php esc_html_e( 'Certificate numbering', 'mms-membership' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: format, 2: example */
						esc_html__( 'Certificates use %1$s, for example %2$s. Membership and serial numbers are random four-digit numbers; the state code comes from the state selected by the applicant.', 'mms-membership' ),
						'<code>{Membership Type}/{Membership No}/{State Code}/{Serial Number}</code>',
						'<code>OMC/4821/10/3765</code>'
					);
					?>
				</p>

				<div class="mms-table-scroll">
				<table class="widefat mms-table mms-settings-table" id="mms-states-table">
					<thead>
						<tr>
							<th class="mms-col-num">#</th>
							<th><?php esc_html_e( 'State / Federal Territory', 'mms-membership' ); ?></th>
							<th class="mms-col-code"><?php esc_html_e( 'State code', 'mms-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$k = 0;
					foreach ( $states as $state ) :
						?>
						<tr>
							<td class="mms-row-no"><?php echo (int) ( $k + 1 ); ?></td>
							<td><input type="text" name="states[<?php echo (int) $k; ?>][name]" value="<?php echo esc_attr( isset( $state['name'] ) ? $state['name'] : '' ); ?>" class="regular-text" /></td>
							<td><input type="text" maxlength="4" name="states[<?php echo (int) $k; ?>][code]" value="<?php echo esc_attr( isset( $state['code'] ) ? $state['code'] : '' ); ?>" class="mms-input-code" /></td>
						</tr>
						<?php
						$k++;
					endforeach;
					?>
					</tbody>
				</table>
				</div>
			</div>

			<div class="mms-form-card">
				<h2><?php esc_html_e( 'Supporting document catalogue', 'mms-membership' ); ?></h2>
				<p><?php esc_html_e( 'Documents listed here appear as options in the required/optional document columns above after you save.', 'mms-membership' ); ?></p>

				<div class="mms-table-scroll">
				<table class="widefat mms-table mms-repeat mms-settings-table" id="mms-docs-table">
					<thead>
						<tr>
							<th class="mms-col-num">#</th>
							<th><?php esc_html_e( 'Key', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Document name', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Accepted formats', 'mms-membership' ); ?></th>
							<th class="mms-col-dur"><?php esc_html_e( 'Max (MB)', 'mms-membership' ); ?></th>
							<th class="mms-col-act"></th>
						</tr>
					</thead>
					<tbody>
					<?php
					$j = 0;
					foreach ( $docs as $doc_key => $doc ) :
						?>
						<tr>
							<td class="mms-row-no"><?php echo (int) ( $j + 1 ); ?></td>
							<td><input type="text" name="docs[<?php echo (int) $j; ?>][key]" value="<?php echo esc_attr( $doc_key ); ?>" class="mms-input-slug" /></td>
							<td><input type="text" name="docs[<?php echo (int) $j; ?>][label]" value="<?php echo esc_attr( $doc['label'] ); ?>" class="regular-text" /></td>
							<td><input type="text" name="docs[<?php echo (int) $j; ?>][accepted]" value="<?php echo esc_attr( $doc['accepted'] ); ?>" class="regular-text" placeholder="pdf,jpg,png" /></td>
							<td><input type="number" min="1" max="50" step="1" name="docs[<?php echo (int) $j; ?>][max_mb]" value="<?php echo esc_attr( (int) $doc['max_mb'] ); ?>" class="small-text" /></td>
							<td><button type="button" class="button-link mms-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'mms-membership' ); ?>">&times;</button></td>
						</tr>
						<?php
						$j++;
					endforeach;
					?>
					</tbody>
				</table>
				</div>
				<p class="mms-table-actions"><button type="button" class="button mms-add-row" data-mms-add="mms-docs-table" data-mms-template="mms-docs-template"><?php esc_html_e( '+ Add document type', 'mms-membership' ); ?></button></p>
			</div>

			<div class="mms-form-card">
				<h2><?php esc_html_e( 'Invoice & receipt branding', 'mms-membership' ); ?></h2>
				<p><?php esc_html_e( 'The logo appears at the top of membership invoices and receipts. If no logo is uploaded, the bundled MACM logo is used.', 'mms-membership' ); ?></p>

				<div class="mms-logo-field">
					<?php if ( $logo_id ) : ?>
						<?php echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'mms-logo-preview' ) ); ?>
					<?php else : ?>
						<img class="mms-logo-preview" src="<?php echo esc_url( MMS_MEMBERSHIP_URL . 'assets/images/macm-logo.png' ); ?>" alt="<?php esc_attr_e( 'MACM logo', 'mms-membership' ); ?>" />
					<?php endif; ?>

					<div class="mms-form-field">
						<label for="mms-invoice-logo"><?php esc_html_e( 'Logo file (PNG or JPEG)', 'mms-membership' ); ?></label>
						<input type="file" id="mms-invoice-logo" name="invoice_logo" accept="image/png,image/jpeg" />
						<span class="mms-form-hint"><?php esc_html_e( 'Recommended: a wide, high-resolution logo on a transparent or white background.', 'mms-membership' ); ?></span>
					</div>

					<?php if ( $logo_id ) : ?>
						<label class="mms-check">
							<input type="checkbox" name="remove_invoice_logo" value="1" />
							<?php esc_html_e( 'Remove the custom logo and use the bundled MACM logo.', 'mms-membership' ); ?>
						</label>
					<?php endif; ?>
				</div>
			</div>

			<div class="mms-form-card">
				<h2><?php esc_html_e( 'Online payments (ToyyibPay)', 'mms-membership' ); ?></h2>
				<p><?php esc_html_e( 'Bills are created when a member clicks “Pay now” in the portal. Use sandbox credentials while testing; the callback URL only works on a publicly reachable site, so local tests rely on the return URL verification.', 'mms-membership' ); ?></p>

				<table class="form-table mms-payment-form" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Online payment', 'mms-membership' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="payment_enabled" value="1" <?php checked( ! empty( $payment['enabled'] ) ); ?> />
								<?php esc_html_e( 'Show the Pay now button for approved, unpaid applications', 'mms-membership' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mms-payment-mode"><?php esc_html_e( 'Mode', 'mms-membership' ); ?></label></th>
						<td>
							<select id="mms-payment-mode" name="payment_mode">
								<option value="sandbox" <?php selected( 'live' !== $payment['mode'] ); ?>><?php esc_html_e( 'Sandbox (dev.toyyibpay.com)', 'mms-membership' ); ?></option>
								<option value="live" <?php selected( 'live' === $payment['mode'] ); ?>><?php esc_html_e( 'Live (toyyibpay.com)', 'mms-membership' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mms-payment-category"><?php esc_html_e( 'Category code', 'mms-membership' ); ?></label></th>
						<td>
							<input type="text" id="mms-payment-category" name="payment_category_code" value="<?php echo esc_attr( $payment['category_code'] ); ?>" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mms-payment-secret"><?php esc_html_e( 'Secret key', 'mms-membership' ); ?></label></th>
						<td>
							<input type="password" id="mms-payment-secret" name="payment_secret_key" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $payment['secret_key'] ? __( 'Saved — leave blank to keep', 'mms-membership' ) : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Stored in the database and used only on the server. Leave blank to keep the current key.', 'mms-membership' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="mms-form-actions">
					<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Save settings', 'mms-membership' ); ?></button>
					<a class="mms-btn mms-btn-outline" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><?php esc_html_e( 'Cancel', 'mms-membership' ); ?></a>
				</div>
			</div>
			</form>

			<template id="mms-type-template">
				<tr>
					<td class="mms-row-no"></td>
					<td><input type="text" name="types[__i__][slug]" value="" class="mms-input-slug" /></td>
					<td><input type="text" name="types[__i__][label]" value="" class="regular-text" /></td>
					<td><input type="text" maxlength="6" name="types[__i__][code]" value="" class="mms-input-code" placeholder="OMC" /></td>
					<td><input type="number" step="0.01" min="0" name="types[__i__][fee]" value="0.00" class="small-text" /></td>
					<td><input type="text" maxlength="3" name="types[__i__][currency]" value="MYR" class="mms-input-cur" /></td>
					<td><input type="number" min="1" step="1" name="types[__i__][duration_months]" value="12" class="small-text" /></td>
					<td class="mms-docs-cell">
						<?php foreach ( $docs as $doc_key => $doc ) : ?>
							<label class="mms-doc-option">
								<input type="checkbox" name="types[__i__][required_docs][]" value="<?php echo esc_attr( $doc_key ); ?>" />
								<?php echo esc_html( $doc['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</td>
					<td class="mms-docs-cell">
						<?php foreach ( $docs as $doc_key => $doc ) : ?>
							<label class="mms-doc-option">
								<input type="checkbox" name="types[__i__][optional_docs][]" value="<?php echo esc_attr( $doc_key ); ?>" />
								<?php echo esc_html( $doc['label'] ); ?>
							</label>
						<?php endforeach; ?>
					</td>
					<td><button type="button" class="button-link mms-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'mms-membership' ); ?>">&times;</button></td>
				</tr>
			</template>

			<template id="mms-docs-template">
				<tr>
					<td class="mms-row-no"></td>
					<td><input type="text" name="docs[__i__][key]" value="" class="mms-input-slug" /></td>
					<td><input type="text" name="docs[__i__][label]" value="" class="regular-text" /></td>
					<td><input type="text" name="docs[__i__][accepted]" value="pdf,jpg,jpeg,png" class="regular-text" placeholder="pdf,jpg,png" /></td>
					<td><input type="number" min="1" max="50" step="1" name="docs[__i__][max_mb]" value="5" class="small-text" /></td>
					<td><button type="button" class="button-link mms-remove-row" aria-label="<?php esc_attr_e( 'Remove row', 'mms-membership' ); ?>">&times;</button></td>
				</tr>
			</template>
		</div>
		<?php
	}
}
