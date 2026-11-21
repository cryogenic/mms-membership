<?php
/**
 * Admin: register a company manually (bypasses the public form).
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Admin_Company
 */
class MMS_Admin_Company {

	const PAGE  = 'mms-company-new';
	const NONCE = 'mms_company_create';

	const FORM_KEY = 'mms_company_form_';

	/**
	 * Register the POST handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_mms_company_create', array( __CLASS__, 'handle_create' ) );
	}

	/**
	 * Status options that can be set at creation time.
	 *
	 * @return array<string,string>
	 */
	private static function status_options() {
		return array(
			MMS_Status::DRAFT           => __( 'Draft — save without submitting', 'mms-membership' ),
			MMS_Status::SUBMITTED       => __( 'Submitted — pending review', 'mms-membership' ),
			MMS_Status::APPROVED_UNPAID => __( 'Approved — payment pending', 'mms-membership' ),
			MMS_Status::ACTIVE          => __( 'Active — paid membership', 'mms-membership' ),
		);
	}

	/**
	 * Add company screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to add companies.', 'mms-membership' ), 403 );
		}

		$transient = self::FORM_KEY . get_current_user_id();
		$state     = get_transient( $transient );
		delete_transient( $transient );

		$errors = isset( $state['errors'] ) ? (array) $state['errors'] : array();
		$old    = isset( $state['data'] ) ? (array) $state['data'] : array();

		$types = MMS_Membership_Types::get_types();
		$first = array_key_first( $types );
		$states = MMS_Membership_Types::get_states();

		$value = static function ( $key, $default = '' ) use ( $old ) {
			return isset( $old[ $key ] ) ? $old[ $key ] : $default;
		};

		$current_status = $value( 'status', MMS_Status::ACTIVE );
		if ( ! array_key_exists( $current_status, self::status_options() ) ) {
			$current_status = MMS_Status::ACTIVE;
		}
		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'Add company', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub">
						<?php esc_html_e( 'Register a company directly. Use this for existing members or companies that joined offline.', 'mms-membership' ); ?>
					</p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>">
						<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Dashboard', 'mms-membership' ); ?>
					</a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Applications::PAGE ) ); ?>">
						<?php esc_html_e( 'Applications', 'mms-membership' ); ?>
					</a>
				</div>
			</div>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="mms-errors">
					<p><?php esc_html_e( 'The company could not be saved:', 'mms-membership' ); ?></p>
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-company-form">
				<input type="hidden" name="action" value="mms_company_create" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="mms-form-card">
					<h2><?php esc_html_e( 'Company details', 'mms-membership' ); ?></h2>
					<p><?php esc_html_e( 'The name appears on the certificate and member portal.', 'mms-membership' ); ?></p>
					<div class="mms-form-grid">
						<div class="mms-form-field is-wide">
							<label for="mms-company-name"><?php esc_html_e( 'Company name', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<input type="text" id="mms-company-name" name="company_name" value="<?php echo esc_attr( $value( 'company_name' ) ); ?>" required maxlength="255" />
						</div>
						<div class="mms-form-field">
							<label for="mms-company-reg"><?php esc_html_e( 'SSM / ROS number', 'mms-membership' ); ?></label>
							<input type="text" id="mms-company-reg" name="company_reg_no" value="<?php echo esc_attr( $value( 'company_reg_no' ) ); ?>" maxlength="100" />
						</div>
						<div class="mms-form-field">
							<label for="mms-company-phone"><?php esc_html_e( 'Phone', 'mms-membership' ); ?></label>
							<input type="tel" id="mms-company-phone" name="company_phone" value="<?php echo esc_attr( $value( 'company_phone' ) ); ?>" maxlength="40" />
						</div>
						<div class="mms-form-field">
							<label for="mms-company-email"><?php esc_html_e( 'Company email', 'mms-membership' ); ?></label>
							<input type="email" id="mms-company-email" name="company_email" value="<?php echo esc_attr( $value( 'company_email' ) ); ?>" maxlength="190" />
						</div>
						<div class="mms-form-field">
							<label for="mms-company-website"><?php esc_html_e( 'Website', 'mms-membership' ); ?></label>
							<input type="url" id="mms-company-website" name="company_website" value="<?php echo esc_attr( $value( 'company_website' ) ); ?>" maxlength="190" placeholder="https://" />
						</div>
						<div class="mms-form-field is-wide">
							<label for="mms-company-address"><?php esc_html_e( 'Address', 'mms-membership' ); ?></label>
							<textarea id="mms-company-address" name="company_address" rows="3"><?php echo esc_textarea( $value( 'company_address' ) ); ?></textarea>
						</div>
						<div class="mms-form-field">
							<label for="mms-company-state"><?php esc_html_e( 'State', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<select id="mms-company-state" name="company_state" required>
								<option value=""><?php esc_html_e( 'Select state', 'mms-membership' ); ?></option>
								<?php foreach ( $states as $state ) : ?>
									<option value="<?php echo esc_attr( $state['name'] ); ?>" <?php selected( $value( 'company_state' ), $state['name'] ); ?>><?php echo esc_html( $state['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
				</div>

				<div class="mms-form-card">
					<h2><?php esc_html_e( 'Person In Charge', 'mms-membership' ); ?></h2>
					<p><?php esc_html_e( 'Receives all membership emails and the member account.', 'mms-membership' ); ?></p>
					<div class="mms-form-grid">
						<div class="mms-form-field">
							<label for="mms-pic-name"><?php esc_html_e( 'Name', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<input type="text" id="mms-pic-name" name="pic_name" value="<?php echo esc_attr( $value( 'pic_name' ) ); ?>" required maxlength="190" />
						</div>
						<div class="mms-form-field">
							<label for="mms-pic-ic"><?php esc_html_e( 'IC / passport', 'mms-membership' ); ?></label>
							<input type="text" id="mms-pic-ic" name="pic_ic" value="<?php echo esc_attr( $value( 'pic_ic' ) ); ?>" maxlength="30" />
						</div>
						<div class="mms-form-field">
							<label for="mms-pic-position"><?php esc_html_e( 'Position', 'mms-membership' ); ?></label>
							<input type="text" id="mms-pic-position" name="pic_position" value="<?php echo esc_attr( $value( 'pic_position' ) ); ?>" maxlength="120" />
						</div>
						<div class="mms-form-field">
							<label for="mms-pic-phone"><?php esc_html_e( 'Phone', 'mms-membership' ); ?></label>
							<input type="tel" id="mms-pic-phone" name="pic_phone" value="<?php echo esc_attr( $value( 'pic_phone' ) ); ?>" maxlength="40" />
						</div>
						<div class="mms-form-field">
							<label for="mms-pic-email"><?php esc_html_e( 'Email', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<input type="email" id="mms-pic-email" name="pic_email" value="<?php echo esc_attr( $value( 'pic_email' ) ); ?>" required maxlength="190" />
						</div>
					</div>
				</div>

				<div class="mms-form-card">
					<h2><?php esc_html_e( 'Membership', 'mms-membership' ); ?></h2>
					<p><?php esc_html_e( 'The reference and membership number are generated automatically.', 'mms-membership' ); ?></p>
					<div class="mms-form-grid">
						<div class="mms-form-field">
							<label for="mms-membership-type"><?php esc_html_e( 'Membership type', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<select id="mms-membership-type" name="membership_type" required>
								<?php foreach ( $types as $slug => $type ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $value( 'membership_type', (string) $first ), $slug ); ?>>
										<?php echo esc_html( $type['label'] . ' — ' . number_format_i18n( (float) $type['fee'], 2 ) . ' ' . $type['currency'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mms-form-field">
							<label for="mms-status"><?php esc_html_e( 'Status', 'mms-membership' ); ?></label>
							<select id="mms-status" name="status">
								<?php foreach ( self::status_options() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mms-form-field">
							<label for="mms-started"><?php esc_html_e( 'Membership start date', 'mms-membership' ); ?></label>
							<input type="date" id="mms-started" name="started_at" value="<?php echo esc_attr( $value( 'started_at' ) ); ?>" />
							<span class="mms-form-hint"><?php esc_html_e( 'Used when the status is Active. Defaults to today; the expiry date is calculated from the membership type.', 'mms-membership' ); ?></span>
						</div>
						<div class="mms-form-field">
							<label for="mms-amount"><?php esc_html_e( 'Amount received', 'mms-membership' ); ?></label>
							<input type="number" id="mms-amount" name="amount" value="<?php echo esc_attr( $value( 'amount' ) ); ?>" min="0" step="0.01" />
							<span class="mms-form-hint"><?php esc_html_e( 'For active memberships. Leave blank to use the type fee. A paid invoice is recorded automatically.', 'mms-membership' ); ?></span>
						</div>
						<div class="mms-form-field">
							<label for="mms-payment-ref"><?php esc_html_e( 'Payment reference', 'mms-membership' ); ?></label>
							<input type="text" id="mms-payment-ref" name="payment_ref" value="<?php echo esc_attr( $value( 'payment_ref' ) ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'Bank transfer / receipt no.', 'mms-membership' ); ?>" />
						</div>
					</div>

					<p style="margin:18px 0 0;">
						<label class="mms-check">
							<input type="checkbox" name="create_account" value="1" <?php checked( '1', $value( 'create_account', '1' ) ); ?> />
							<span><?php esc_html_e( 'Create a member account and email a set-password link to the Person In Charge.', 'mms-membership' ); ?></span>
						</label>
					</p>
				</div>

				<div class="mms-form-actions">
					<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Add company', 'mms-membership' ); ?></button>
					<a class="mms-btn mms-btn-outline" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><?php esc_html_e( 'Cancel', 'mms-membership' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Create the application, meta, optional payment and member account.
	 *
	 * @return void
	 */
	public static function handle_create() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to add companies.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$type_slug = isset( $_POST['membership_type'] ) ? sanitize_key( wp_unslash( $_POST['membership_type'] ) ) : '';
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : MMS_Status::DRAFT;
		$started   = isset( $_POST['started_at'] ) ? sanitize_text_field( wp_unslash( $_POST['started_at'] ) ) : '';
		$amount    = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$pay_ref   = isset( $_POST['payment_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_ref'] ) ) : '';
		$account   = isset( $_POST['create_account'] ) && '1' === $_POST['create_account'];

		$meta = array(
			'company_name'    => isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '',
			'company_reg_no'  => isset( $_POST['company_reg_no'] ) ? sanitize_text_field( wp_unslash( $_POST['company_reg_no'] ) ) : '',
			'company_state'   => isset( $_POST['company_state'] ) ? sanitize_text_field( wp_unslash( $_POST['company_state'] ) ) : '',
			'company_address' => isset( $_POST['company_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['company_address'] ) ) : '',
			'company_phone'   => isset( $_POST['company_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['company_phone'] ) ) : '',
			'company_email'   => isset( $_POST['company_email'] ) ? sanitize_email( wp_unslash( $_POST['company_email'] ) ) : '',
			'company_website' => isset( $_POST['company_website'] ) ? esc_url_raw( wp_unslash( $_POST['company_website'] ) ) : '',
			'pic_name'        => isset( $_POST['pic_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pic_name'] ) ) : '',
			'pic_ic'          => isset( $_POST['pic_ic'] ) ? sanitize_text_field( wp_unslash( $_POST['pic_ic'] ) ) : '',
			'pic_position'    => isset( $_POST['pic_position'] ) ? sanitize_text_field( wp_unslash( $_POST['pic_position'] ) ) : '',
			'pic_email'       => isset( $_POST['pic_email'] ) ? sanitize_email( wp_unslash( $_POST['pic_email'] ) ) : '',
			'pic_phone'       => isset( $_POST['pic_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['pic_phone'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$type = MMS_Membership_Types::get_type( $type_slug );

		$errors = array();

		if ( '' === $meta['company_name'] ) {
			$errors[] = __( 'Company name is required.', 'mms-membership' );
		}

		if ( '' === $meta['company_state'] || ! in_array( $meta['company_state'], wp_list_pluck( MMS_Membership_Types::get_states(), 'name' ), true ) ) {
			$errors[] = __( 'Choose a valid state.', 'mms-membership' );
		}

		if ( '' === $meta['pic_name'] ) {
			$errors[] = __( 'Person In Charge name is required.', 'mms-membership' );
		}

		if ( '' === $meta['pic_email'] || ! is_email( $meta['pic_email'] ) ) {
			$errors[] = __( 'A valid Person In Charge email is required.', 'mms-membership' );
		}

		if ( ! $type ) {
			$errors[] = __( 'Choose a valid membership type.', 'mms-membership' );
		}

		if ( ! array_key_exists( $status, self::status_options() ) ) {
			$errors[] = __( 'Choose a valid status.', 'mms-membership' );
		}

		$start_date = null;
		if ( MMS_Status::ACTIVE === $status ) {
			$tz         = wp_timezone();
			$start_date = '' !== $started
				? DateTimeImmutable::createFromFormat( '!Y-m-d', $started, $tz )
				: new DateTimeImmutable( 'today', $tz );

			if ( ! $start_date instanceof DateTimeImmutable ) {
				$errors[] = __( 'The membership start date is not valid.', 'mms-membership' );
				$start_date = null;
			}
		}

		if ( '' !== $amount && ! is_numeric( $amount ) ) {
			$errors[] = __( 'The amount received must be a number.', 'mms-membership' );
		}

		if ( ! empty( $errors ) ) {
			set_transient(
				self::FORM_KEY . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => array_merge(
						$meta,
						array(
							'membership_type' => $type_slug,
							'status'          => $status,
							'started_at'      => $started,
							'amount'          => $amount,
							'payment_ref'     => $pay_ref,
							'create_account'  => $account ? '1' : '',
						)
					),
				),
				10 * MINUTE_IN_SECONDS
			);

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}

		$app_id = MMS_Applications::create( $type_slug );

		if ( ! $app_id ) {
			wp_die( esc_html__( 'The company could not be created. Please try again.', 'mms-membership' ), 500 );
		}

		global $wpdb;

		$now       = current_time( 'mysql' );
		$reference = MMS_Applications::build_reference( $app_id );
		$started_sql = null;
		$expires_sql = null;
		$member_no   = null;

		if ( MMS_Status::ACTIVE === $status && $start_date instanceof DateTimeImmutable ) {
			$months      = max( 1, (int) $type['duration_months'] );
			$started_sql = $start_date->format( 'Y-m-d H:i:s' );
			$expires_sql = $start_date->modify( '+' . $months . ' months' )->format( 'Y-m-d H:i:s' );
			$member_no   = MMS_Payments::membership_number( array( 'id' => $app_id ) );
		}

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'reference'     => $reference,
				'status'        => $status,
				'submitted_at'  => MMS_Status::DRAFT === $status ? null : $now,
				'started_at'    => $started_sql,
				'expires_at'    => $expires_sql,
				'membership_no' => $member_no,
				'reviewed_by'   => in_array( $status, array( MMS_Status::DRAFT, MMS_Status::SUBMITTED ), true ) ? 0 : get_current_user_id(),
				'reviewed_at'   => in_array( $status, array( MMS_Status::DRAFT, MMS_Status::SUBMITTED ), true ) ? null : $now,
				'updated_at'    => $now,
			),
			array( 'id' => $app_id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		MMS_Applications::save_meta( $app_id, $meta );

		if ( MMS_Status::APPROVED_UNPAID === $status ) {
			$invoice = MMS_Payments::ensure_invoice(
				array_merge( MMS_Applications::get( $app_id ), array( 'status' => $status ) ),
				$meta,
				$type,
				MMS_Payments::KIND_INITIAL
			);

			if ( ! $invoice ) {
				MMS_Applications::log(
					$app_id,
					'invoice_failed',
					__( 'The approval invoice could not be generated.', 'mms-membership' ),
					get_current_user_id()
				);
			}
		}

		if ( MMS_Status::ACTIVE === $status && $member_no ) {
			MMS_Certificate::issue_number(
				array(
					'id'              => $app_id,
					'membership_no'   => $member_no,
					'membership_type' => $type_slug,
				)
			);
		}

		MMS_Applications::log(
			$app_id,
			'manual_created',
			sprintf(
				/* translators: 1: administrator display name, 2: status label */
				__( 'Company added manually by %1$s. Status: %2$s.', 'mms-membership' ),
				wp_get_current_user()->display_name,
				MMS_Status::label( $status )
			),
			get_current_user_id()
		);

		if ( MMS_Status::ACTIVE === $status ) {
			$fee      = '' !== $amount ? (float) $amount : (float) $type['fee'];
			$currency = ! empty( $type['currency'] ) ? strtoupper( $type['currency'] ) : 'MYR';

			$wpdb->insert(
				MMS_DB::table( MMS_DB::PAYMENT ),
				array(
					'application_id'  => $app_id,
					'invoice_no'      => $reference . '-1',
					'amount'          => $fee,
					'currency'        => $currency,
					'status'          => 'paid',
					'kind'            => MMS_Payments::KIND_INITIAL,
					'provider'        => 'manual',
					'provider_ref'    => $pay_ref,
					'transaction_ref' => $pay_ref,
					'channel'         => __( 'Manual entry', 'mms-membership' ),
					'raw_response'    => '',
					'period_start'    => $started_sql,
					'period_end'      => $expires_sql,
					'paid_at'         => $started_sql,
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			MMS_Applications::log(
				$app_id,
				'payment_received',
				sprintf(
					/* translators: 1: formatted amount, 2: payment reference or note */
					__( 'Manual payment of %1$s recorded%2$s.', 'mms-membership' ),
					MMS_Payments::format_amount( $fee, $currency ),
					'' !== $pay_ref ? ' (' . $pay_ref . ')' : ''
				),
				get_current_user_id()
			);
		}

		if ( $account && in_array( $status, array( MMS_Status::ACTIVE, MMS_Status::APPROVED_UNPAID ), true ) ) {
			self::create_account( $app_id, $meta );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => MMS_Admin_Applications::PAGE,
					'application' => $app_id,
					'mms_msg'     => 'company_created',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Create (or link) the member account and email a set-password link.
	 *
	 * @param int   $app_id Application ID.
	 * @param array $meta Company / PIC meta.
	 * @return void
	 */
	private static function create_account( $app_id, $meta ) {
		$application = MMS_Applications::get( $app_id );
		$member      = MMS_Members::create_from_application( $application, $meta );

		if ( is_wp_error( $member ) ) {
			MMS_Applications::log(
				$app_id,
				'member_account_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Member account could not be created: %s', 'mms-membership' ),
					$member->get_error_message()
				),
				get_current_user_id()
			);
			return;
		}

		MMS_Applications::log(
			$app_id,
			$member['created'] ? 'member_account_created' : 'member_account_linked',
			sprintf(
				/* translators: 1: user ID, 2: account email */
				__( 'Member account #%1$d (%2$s) %3$s from the admin.', 'mms-membership' ),
				$member['user_id'],
				$meta['pic_email'],
				$member['created'] ? __( 'created', 'mms-membership' ) : __( 'linked', 'mms-membership' )
			),
			get_current_user_id()
		);

		$token = MMS_Members::issue_set_password_token( $member['user_id'] );
		$url   = MMS_Members::set_password_url( $member['user_id'], $token );

		MMS_Applications::log(
			$app_id,
			'set_password_link',
			__( 'One-time set-password link generated (valid 72 hours).', 'mms-membership' ),
			get_current_user_id()
		);

		$reference = '' !== (string) $application['reference'] ? $application['reference'] : '#' . $app_id;
		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name      = '' !== $meta['pic_name'] ? $meta['pic_name'] : $meta['company_name'];

		/* translators: %s: reference number */
		$subject = sprintf( __( 'Your MACM member account — %s', 'mms-membership' ), $reference );
		$body    = array(
			/* translators: %s: Person In Charge name */
			sprintf( __( 'Dear %s,', 'mms-membership' ), $name ),
			'',
			__( 'A member account has been created for your company. Set your password using the link below to access the member portal.', 'mms-membership' ),
			$url,
			'',
			__( 'This one-time link is valid for 72 hours.', 'mms-membership' ),
			/* translators: %s: reference number */
			sprintf( __( 'Reference number: %s', 'mms-membership' ), $reference ),
			'',
			$site,
		);

		$sent = wp_mail( $meta['pic_email'], $subject, implode( "\n", $body ) );

		MMS_Applications::log(
			$app_id,
			$sent ? 'email_sent' : 'email_failed',
			$sent
				/* translators: %s: recipient email */
				? sprintf( __( 'Set-password email sent to %s.', 'mms-membership' ), $meta['pic_email'] )
				: __( 'Set-password email could not be sent — check the site mail configuration.', 'mms-membership' )
		);
	}
}
