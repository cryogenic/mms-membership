<?php
/**
 * Admin review panel: applications list, filters, detail screen, document review and member passwords.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Admin_Applications
 */
class MMS_Admin_Applications {

	const PAGE           = 'mms-applications';
	const NONCE_DOC      = 'mms_update_document';
	const NONCE_DECISION = 'mms_application_decision';
	const NONCE_CERT     = 'mms_admin_certificate';
	const NONCE_BULK     = 'mms_application_bulk';
	const NONCE_PAYMENT  = 'mms_manual_payment';
	const NONCE_PASSWORD = 'mms_admin_member_password';
	const NONCE_ACCOUNT  = 'mms_admin_member_account';
	const NONCE_UPDATE   = 'mms_admin_member_account_update';
	const PER_PAGE       = 20;

	/**
	 * Register the POST handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_mms_update_document', array( __CLASS__, 'handle_document_review' ) );
		add_action( 'admin_post_mms_view_document', array( __CLASS__, 'serve_document' ) );
		add_action( 'admin_post_mms_application_decision', array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_post_mms_admin_certificate', array( __CLASS__, 'handle_certificate' ) );
		add_action( 'admin_post_mms_bulk_action', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_mms_manual_payment', array( __CLASS__, 'handle_manual_payment' ) );
		add_action( 'admin_post_mms_admin_member_password', array( __CLASS__, 'handle_member_password' ) );
		add_action( 'admin_post_mms_admin_member_create', array( __CLASS__, 'handle_member_account_create' ) );
		add_action( 'admin_post_mms_admin_member_update', array( __CLASS__, 'handle_member_account_update' ) );
	}

	/**
	 * Admin-only download URL for a generated membership certificate.
	 *
	 * @param int $application_id Application ID.
	 * @return string
	 */
	public static function certificate_url( $application_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'         => 'mms_admin_certificate',
					'application_id' => (int) $application_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_CERT . '_' . (int) $application_id
		);
	}

	/**
	 * Stream a membership certificate to the administrator.
	 *
	 * @return void
	 */
	public static function handle_certificate() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to download certificates.', 'mms-membership' ), 403 );
		}

		$application_id = isset( $_GET['application_id'] ) ? absint( $_GET['application_id'] ) : 0;
		check_admin_referer( self::NONCE_CERT . '_' . $application_id );

		$application = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), 404 );
		}

		if ( empty( $application['membership_no'] ) || ! in_array( $application['status'], array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) ) {
			wp_die( esc_html__( 'A certificate is only available once the membership is active.', 'mms-membership' ), 403 );
		}

		$pdf = MMS_Certificate::generate(
			$application,
			MMS_Applications::get_meta( $application_id ),
			MMS_Membership_Types::get_type( $application['membership_type'] )
		);

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), 500 );
		}

		MMS_Applications::log(
			$application_id,
			'certificate_downloaded',
			sprintf(
				/* translators: 1: membership number, 2: administrator display name */
				__( 'Certificate %1$s downloaded by %2$s.', 'mms-membership' ),
				$application['membership_no'],
				wp_get_current_user()->display_name
			),
			get_current_user_id()
		);

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( MMS_Certificate::filename( $application ) ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Manual payment channels.
	 *
	 * @return array<string,string>
	 */
	public static function manual_channels() {
		return array(
			'bank_transfer' => __( 'Bank transfer', 'mms-membership' ),
			'cash'          => __( 'Cash', 'mms-membership' ),
			'cheque'        => __( 'Cheque', 'mms-membership' ),
			'other'         => __( 'Other', 'mms-membership' ),
		);
	}

	/**
	 * Record a manual payment and activate the membership.
	 *
	 * @return void
	 */
	public static function handle_manual_payment() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to record payments.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_PAYMENT );

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$application    = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), 404 );
		}

		$kind = MMS_Payments::kind_for( $application );

		if ( '' === $kind ) {
			self::back_to( $application_id, 'payment_not_open' );
		}

		$type = MMS_Membership_Types::get_type( $application['membership_type'] );

		if ( ! $type ) {
			self::back_to( $application_id, 'payment_invalid' );
		}

		$meta       = MMS_Applications::get_meta( $application_id );
		$payment_id = isset( $_POST['payment_id'] ) ? absint( $_POST['payment_id'] ) : 0;
		$payment    = $payment_id ? MMS_Payments::get( $payment_id ) : null;

		if ( $payment && (int) $payment['application_id'] !== $application_id ) {
			self::back_to( $application_id, 'payment_invalid' );
		}

		if ( ! $payment ) {
			$payment = MMS_Payments::ensure_invoice( $application, $meta, $type, $kind );
		}

		if ( ! $payment ) {
			self::back_to( $application_id, 'payment_missing' );
		}

		if ( 'paid' === $payment['status'] ) {
			self::back_to( $application_id, 'payment_paid' );
		}

		$amount_raw = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$amount     = round( (float) str_replace( array( ',', ' ' ), '', $amount_raw ), 2 );

		if ( $amount <= 0 ) {
			self::back_to( $application_id, 'payment_invalid' );
		}

		$paid_date = isset( $_POST['paid_date'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_date'] ) ) : '';
		$tz        = wp_timezone();
		$paid_dt   = DateTimeImmutable::createFromFormat( '!Y-m-d', $paid_date, $tz );

		if ( ! $paid_dt || $paid_date > current_time( 'Y-m-d' ) ) {
			self::back_to( $application_id, 'payment_invalid' );
		}

		$paid_at   = $paid_dt->format( 'Y-m-d' ) . ' 00:00:00';
		$reference = isset( $_POST['payment_reference'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_reference'] ) ) : '';
		$note      = isset( $_POST['payment_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['payment_note'] ) ) : '';
		$channel   = isset( $_POST['payment_channel'] ) ? sanitize_key( wp_unslash( $_POST['payment_channel'] ) ) : '';
		$channels  = self::manual_channels();
		$channel   = isset( $channels[ $channel ] ) ? $channels[ $channel ] : $channels['other'];

		$raw = wp_json_encode(
			array(
				'source'      => 'admin_manual',
				'recorded_by' => get_current_user_id(),
				'channel'     => $channel,
				'reference'   => $reference,
				'note'        => $note,
				'amount'      => $amount,
				'paid_date'   => $paid_date,
			)
		);

		global $wpdb;

		$wpdb->update(
			MMS_DB::table( MMS_DB::PAYMENT ),
			array(
				'amount'          => $amount,
				'provider'        => 'manual',
				'channel'         => $channel,
				'transaction_ref' => $reference,
				'raw_response'    => $raw,
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => (int) $payment['id'] ),
			array( '%f', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$payment['amount']          = $amount;
		$payment['provider']        = 'manual';
		$payment['channel']         = $channel;
		$payment['transaction_ref'] = $reference;

		$done = MMS_Payments::complete_payment( $payment, $reference, $raw, $paid_at );

		if ( ! $done ) {
			self::back_to( $application_id, 'payment_paid' );
		}

		MMS_Applications::log(
			$application_id,
			'payment_recorded_manually',
			sprintf(
				/* translators: 1: formatted amount, 2: channel, 3: reference */
				__( 'Manual payment of %1$s recorded via %2$s%3$s.', 'mms-membership' ),
				MMS_Payments::format_amount( $amount, $payment['currency'] ),
				$channel,
				'' !== $reference ? ' (' . $reference . ')' : ''
			),
			get_current_user_id()
		);

		self::back_to( $application_id, 'manual_payment_saved' );
	}

	/**
	 * Replace the linked member password and email the new login details.
	 *
	 * @return void
	 */
	public static function handle_member_password() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to change member passwords.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_PASSWORD );

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$application    = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), 404 );
		}

		$user = ! empty( $application['member_user_id'] ) ? get_userdata( (int) $application['member_user_id'] ) : false;

		if ( ! $user ) {
			self::back_to( $application_id, 'member_password_no_account' );
		}

		if ( user_can( $user, 'mms_review_application' ) ) {
			self::back_to( $application_id, 'member_password_admin' );
		}

		// Passwords keep every character the admin typed; only the length is enforced.
		$password = isset( $_POST['new_password'] ) && is_string( $_POST['new_password'] )
			? (string) wp_unslash( $_POST['new_password'] )
			: '';

		if ( strlen( $password ) < 8 || strlen( $password ) > 200 ) {
			self::back_to( $application_id, 'member_password_short' );
		}

		MMS_Members::reset_password( $user, $password );

		MMS_Applications::log(
			$application_id,
			'password_reset_by_admin',
			sprintf(
				/* translators: 1: administrator display name, 2: member username */
				__( 'Password reset by %1$s for member account %2$s.', 'mms-membership' ),
				wp_get_current_user()->display_name,
				$user->user_login
			),
			get_current_user_id()
		);

		$meta = MMS_Applications::get_meta( $application_id );
		$sent = self::send_password_email( $application, $meta, $user, $password );

		MMS_Applications::log(
			$application_id,
			$sent ? 'email_sent' : 'email_failed',
			$sent
				/* translators: %s: recipient email */
				? sprintf( __( 'New password emailed to %s.', 'mms-membership' ), $user->user_email )
				/* translators: %s: recipient email */
				: sprintf( __( 'New password email could not be sent to %s — check the site mail configuration.', 'mms-membership' ), $user->user_email )
		);

		self::back_to( $application_id, $sent ? 'member_password_saved' : 'member_password_email_failed' );
	}

	/**
	 * Create a member account for a company that has none, using an admin-set password.
	 *
	 * @return void
	 */
	public static function handle_member_account_create() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to create member accounts.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_ACCOUNT );

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$application    = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), 404 );
		}

		if ( ! empty( $application['member_user_id'] ) ) {
			self::back_to( $application_id, 'member_account_exists' );
		}

		$meta  = MMS_Applications::get_meta( $application_id );
		$email = isset( $_POST['account_email'] ) ? sanitize_email( wp_unslash( $_POST['account_email'] ) ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			self::back_to( $application_id, 'member_account_invalid' );
		}

		// Use the admin-entered account email without changing the application's PIC details.
		$meta['pic_email'] = $email;

		$login = isset( $_POST['user_login'] ) ? MMS_Members::normalize_login( wp_unslash( $_POST['user_login'] ) ) : '';

		// Passwords keep every character the admin typed; only the length is enforced.
		$password = isset( $_POST['new_password'] ) && is_string( $_POST['new_password'] )
			? (string) wp_unslash( $_POST['new_password'] )
			: '';

		if ( strlen( $password ) < 8 || strlen( $password ) > 200 ) {
			self::back_to( $application_id, 'member_password_short' );
		}

		$result = MMS_Admin_Company::create_account(
			$application_id,
			$meta,
			array(
				'user_login' => $login,
				'user_pass'  => $password,
			)
		);

		if ( is_wp_error( $result ) ) {
			$notice = 'mms_username_taken' === $result->get_error_code() ? 'member_account_username_taken' : 'member_account_failed';
			self::back_to( $application_id, $notice );
		}

		self::back_to( $application_id, $result['sent'] ? 'member_account_created' : 'member_account_email_failed' );
	}

	/**
	 * Update the username and email of an existing member account.
	 *
	 * @return void
	 */
	public static function handle_member_account_update() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit member accounts.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_UPDATE );

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$application    = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), 404 );
		}

		$user = ! empty( $application['member_user_id'] ) ? get_userdata( (int) $application['member_user_id'] ) : false;

		if ( ! $user ) {
			self::back_to( $application_id, 'member_password_no_account' );
		}

		if ( user_can( $user, 'mms_review_application' ) ) {
			self::back_to( $application_id, 'member_account_admin' );
		}

		$login = isset( $_POST['user_login'] ) ? MMS_Members::normalize_login( wp_unslash( $_POST['user_login'] ) ) : '';
		$email = isset( $_POST['account_email'] ) ? sanitize_email( wp_unslash( $_POST['account_email'] ) ) : '';

		if ( '' === $login ) {
			self::back_to( $application_id, 'member_account_username_invalid' );
		}

		if ( '' === $email || ! is_email( $email ) ) {
			self::back_to( $application_id, 'member_account_invalid' );
		}

		$login_owner = get_user_by( 'login', $login );
		if ( $login_owner instanceof WP_User && (int) $login_owner->ID !== (int) $user->ID ) {
			self::back_to( $application_id, 'member_account_username_taken' );
		}

		$email_owner = email_exists( $email );
		if ( $email_owner && (int) $email_owner !== (int) $user->ID ) {
			self::back_to( $application_id, 'member_account_email_taken' );
		}

		$changed = array();

		if ( $login !== $user->user_login ) {
			$changed[] = 'username';
		}

		if ( 0 !== strcasecmp( $email, $user->user_email ) ) {
			$changed[] = 'email';
		}

		if ( ! empty( $changed ) ) {
			$rename = null;

			if ( $login !== $user->user_login ) {
				// Core drops user_login on updates, so inject it for this one write only.
				$rename = static function ( $data, $is_update, $user_id ) use ( $login, $user ) {
					if ( $is_update && (int) $user_id === (int) $user->ID ) {
						$data['user_login'] = $login;
					}

					return $data;
				};

				add_filter( 'wp_pre_insert_user_data', $rename, 10, 3 );
			}

			$result = wp_update_user(
				array(
					'ID'         => $user->ID,
					'user_email' => $email,
				)
			);

			if ( $rename ) {
				remove_filter( 'wp_pre_insert_user_data', $rename, 10 );
			}

			if ( is_wp_error( $result ) ) {
				self::back_to( $application_id, 'member_account_update_failed' );
			}

			MMS_Applications::log(
				$application_id,
				'member_account_updated',
				sprintf(
					/* translators: 1: administrator display name, 2: comma separated fields */
					__( 'Member account updated by %1$s: %2$s.', 'mms-membership' ),
					wp_get_current_user()->display_name,
					implode( ', ', $changed )
				),
				get_current_user_id()
			);
		}

		self::back_to( $application_id, 'member_account_updated' );
	}

	/**
	 * Email the new password and username to the member.
	 *
	 * @param array   $application Application row.
	 * @param array   $meta        Company / PIC meta.
	 * @param WP_User $user        Linked member account.
	 * @param string  $password    Plain password.
	 * @return bool Whether the email was accepted for delivery.
	 */
	private static function send_password_email( $application, $meta, $user, $password ) {
		$email = (string) $user->user_email;

		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name      = ! empty( $meta['pic_name'] ) ? $meta['pic_name'] : ( isset( $meta['company_name'] ) ? $meta['company_name'] : '' );
		$reference = '' !== (string) $application['reference'] ? $application['reference'] : '#' . (int) $application['id'];
		$type      = MMS_Membership_Types::get_type( $application['membership_type'] );
		$login     = MMS_Members::login_url();

		$context = array(
			'name'            => $name,
			'company_name'    => isset( $meta['company_name'] ) ? $meta['company_name'] : '',
			'reference'       => $reference,
			'membership_type' => $type ? $type['label'] : '',
			'username'        => $user->user_login,
			'password'        => $password,
		);

		$subject = MMS_Emails::subject( 'password', $context );
		$message = MMS_Emails::body( 'password', $context );

		$row      = '<tr><td style="padding:6px 12px 6px 0;color:#646970;">%1$s</td><td style="padding:6px 0;font-weight:600;">%2$s</td></tr>';
		$details  = sprintf( $row, esc_html__( 'Username', 'mms-membership' ), esc_html( $user->user_login ) );
		$details .= sprintf(
			$row,
			esc_html__( 'New password', 'mms-membership' ),
			'<span style="font-family:Consolas,Monaco,monospace;">' . esc_html( $password ) . '</span>'
		);
		$details .= sprintf( $row, esc_html__( 'Reference number', 'mms-membership' ), esc_html( $reference ) );

		$body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f0f0f1;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">'
			. '<div style="background:#ffffff;border-radius:6px;padding:32px;">'
			. '<div style="margin:0 0 16px;font-size:15px;line-height:1.55;">' . $message . '</div>'
			. '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;font-size:15px;">' . $details . '</table>'
			. '<p style="margin:0 0 8px;"><a href="' . esc_url( $login ) . '" style="display:inline-block;padding:12px 22px;background:#0f4c5c;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html__( 'Log in to the member portal', 'mms-membership' ) . '</a></p>'
			. '<p style="margin:0 0 20px;font-size:13px;color:#646970;">' . esc_html__( 'For your security, change this password after signing in. If you did not expect this change, contact MACM immediately.', 'mms-membership' ) . '</p>'
			. '<p style="margin:24px 0 0;font-size:13px;color:#646970;">' . esc_html( $site ) . '</p>'
			. '</div></div></body></html>';

		return wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Redirect back to the detail screen with a message code.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $message Message code.
	 * @return void
	 */
	private static function back_to( $application_id, $message ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE,
					'application' => (int) $application_id,
					'mms_msg'     => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Entry point: detail screen when `application` is set, otherwise the list.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to review applications.', 'mms-membership' ), 403 );
		}

		$id = isset( $_GET['application'] ) ? absint( $_GET['application'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id ) {
			self::render_detail( $id );
			return;
		}

		self::render_list();
	}

	/**
	 * Read the current list filters from the query string.
	 *
	 * @return array{status:string,membership_type:string,search:string,order:string,paged:int}
	 */
	private static function current_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$type   = isset( $_GET['membership_type'] ) ? sanitize_key( wp_unslash( $_GET['membership_type'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$order  = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'newest';
		$paged  = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $order, array( 'newest', 'oldest' ), true ) ) {
			$order = 'newest';
		}

		return array(
			'status'          => $status,
			'membership_type' => $type,
			'search'          => $search,
			'order'           => $order,
			'paged'           => max( 1, $paged ),
		);
	}

	/**
	 * Query applications with filters.
	 *
	 * @param array $filters Filters from current_filters().
	 * @return array{rows:array<int,object>,total:int}
	 */
	private static function fetch( $filters ) {
		global $wpdb;

		$app  = MMS_DB::table( MMS_DB::APPLICATION );
		$meta = MMS_DB::table( MMS_DB::APPLICATION_META );
		$doc  = MMS_DB::table( MMS_DB::DOCUMENT );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $filters['status'] && MMS_Status::is_valid( $filters['status'] ) ) {
			$where[]  = 'a.status = %s';
			$params[] = $filters['status'];
		}

		if ( '' !== $filters['membership_type'] ) {
			$where[]  = 'a.membership_type = %s';
			$params[] = $filters['membership_type'];
		}

		if ( '' !== $filters['search'] ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(a.reference LIKE %s OR m.company_name LIKE %s OR m.company_reg_no LIKE %s OR m.pic_name LIKE %s OR m.pic_email LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$app} a LEFT JOIN {$meta} m ON m.application_id = a.id WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page  = self::PER_PAGE;
		$offset    = ( $filters['paged'] - 1 ) * $per_page;
		$direction = 'oldest' === $filters['order'] ? 'ASC' : 'DESC';

		$rows_sql = "SELECT a.*, m.company_name, m.company_reg_no, m.pic_name, m.pic_email,
			( SELECT COUNT(*) FROM {$doc} d WHERE d.application_id = a.id ) AS doc_total,
			( SELECT COUNT(*) FROM {$doc} d WHERE d.application_id = a.id AND d.review_status <> 'pending' ) AS doc_reviewed
			FROM {$app} a
			LEFT JOIN {$meta} m ON m.application_id = a.id
			WHERE {$where_sql}
			ORDER BY COALESCE(a.submitted_at, a.created_at) {$direction}, a.id {$direction}
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Applications list screen.
	 *
	 * @return void
	 */
	private static function render_list() {
		$filters  = self::current_filters();
		$result   = self::fetch( $filters );
		$types    = MMS_Membership_Types::get_types();
		$statuses = MMS_Status::all();
		$pages    = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );

		$message      = isset( $_GET['mms_msg'] ) ? sanitize_key( wp_unslash( $_GET['mms_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_done    = isset( $_GET['mms_bulk'] ) ? absint( $_GET['mms_bulk'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_skipped = isset( $_GET['mms_skipped'] ) ? absint( $_GET['mms_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bulk_blocked = isset( $_GET['mms_blocked'] ) ? absint( $_GET['mms_blocked'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero mms-hero-compact">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'MACM Membership', 'mms-membership' ); ?></p>
					<h1><?php esc_html_e( 'Company List', 'mms-membership' ); ?></h1>
					<p class="mms-hero-sub"><?php esc_html_e( 'Every company registration, its documents and review status.', 'mms-membership' ); ?></p>
				</div>
				<div class="mms-hero-actions">
					<a class="mms-btn mms-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . MMS_Admin_Company::PAGE ) ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add company', 'mms-membership' ); ?></a>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=mms-membership' ) ); ?>"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Dashboard', 'mms-membership' ); ?></a>
				</div>
			</div>

			<?php if ( 'bulk_none' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Select at least one application and a bulk action before applying.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'bulk_done' === $message ) : ?>
				<div class="notice <?php echo $bulk_done > 0 ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of updated applications */
							esc_html( _n( '%d application updated.', '%d applications updated.', $bulk_done, 'mms-membership' ) ),
							(int) $bulk_done
						);
						if ( $bulk_skipped > 0 ) {
							printf(
								/* translators: %d: number of skipped applications */
								esc_html( ' ' . _n( '%d skipped — not waiting for review.', '%d skipped — not waiting for review.', $bulk_skipped, 'mms-membership' ) ),
								(int) $bulk_skipped
							);
						}
						if ( $bulk_blocked > 0 ) {
							printf(
								/* translators: %d: number of applications blocked by insufficient documents */
								esc_html( ' ' . _n( '%d not approved — a document is marked Insufficient.', '%d not approved — documents are marked Insufficient.', $bulk_blocked, 'mms-membership' ) ),
								(int) $bulk_blocked
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="mms-toolbar">
			<form method="get" class="mms-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />

				<label for="mms-filter-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'mms-membership' ); ?></label>
				<select name="status" id="mms-filter-status">
					<option value=""><?php esc_html_e( 'All statuses', 'mms-membership' ); ?></option>
					<?php foreach ( $statuses as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['status'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="mms-filter-type" class="screen-reader-text"><?php esc_html_e( 'Membership type', 'mms-membership' ); ?></label>
				<select name="membership_type" id="mms-filter-type">
					<option value=""><?php esc_html_e( 'All membership types', 'mms-membership' ); ?></option>
					<?php foreach ( $types as $slug => $type ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filters['membership_type'], $slug ); ?>><?php echo esc_html( $type['label'] ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="mms-filter-search" class="screen-reader-text"><?php esc_html_e( 'Search applications', 'mms-membership' ); ?></label>
				<input type="search" id="mms-filter-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Reference, company, SSM no. or email', 'mms-membership' ); ?>" />

				<label for="mms-filter-order" class="screen-reader-text"><?php esc_html_e( 'Sort by date', 'mms-membership' ); ?></label>
				<select name="order" id="mms-filter-order">
					<option value="newest" <?php selected( $filters['order'], 'newest' ); ?>><?php esc_html_e( 'Newest first', 'mms-membership' ); ?></option>
					<option value="oldest" <?php selected( $filters['order'], 'oldest' ); ?>><?php esc_html_e( 'Oldest first', 'mms-membership' ); ?></option>
				</select>

				<?php submit_button( __( 'Filter', 'mms-membership' ), 'secondary', '', false ); ?>

				<?php if ( '' !== $filters['status'] || '' !== $filters['membership_type'] || '' !== $filters['search'] || 'newest' !== $filters['order'] ) : ?>
					<a class="mms-filters-reset" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Reset', 'mms-membership' ); ?></a>
				<?php endif; ?>
			</form>

				<p class="mms-toolbar-count">
					<?php
					printf(
						/* translators: %d: number of companies */
						esc_html( _n( '%d company found.', '%d companies found.', $result['total'], 'mms-membership' ) ),
						(int) $result['total']
					);
					?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-bulk-form">
				<input type="hidden" name="action" value="mms_bulk_action" />
				<?php wp_nonce_field( self::NONCE_BULK ); ?>

			<div class="mms-table-card">
				<div class="mms-bulk-bar">
					<label for="mms-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Bulk action', 'mms-membership' ); ?></label>
					<select name="bulk_action" id="mms-bulk-action">
						<option value=""><?php esc_html_e( 'Bulk actions', 'mms-membership' ); ?></option>
						<option value="under_review"><?php esc_html_e( 'Mark as under review', 'mms-membership' ); ?></option>
						<option value="approved_unpaid"><?php esc_html_e( 'Approve — payment pending', 'mms-membership' ); ?></option>
					</select>
					<button type="submit" class="button mms-bulk-apply" disabled><?php esc_html_e( 'Apply', 'mms-membership' ); ?></button>
					<span class="mms-bulk-count">0 selected</span>
					<span class="mms-bulk-hint"><?php esc_html_e( 'Approval is skipped when a document is marked Insufficient.', 'mms-membership' ); ?></span>
				</div>
			<table class="widefat striped mms-table mms-applications-table">
				<thead>
					<tr>
						<th class="mms-col-check"><input type="checkbox" id="mms-check-all" aria-label="<?php esc_attr_e( 'Select all applications', 'mms-membership' ); ?>" /></th>
						<th><?php esc_html_e( 'Reference', 'mms-membership' ); ?></th>
						<th><?php esc_html_e( 'Company', 'mms-membership' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mms-membership' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mms-membership' ); ?></th>
						<th><?php esc_html_e( 'Documents', 'mms-membership' ); ?></th>
						<th><?php esc_html_e( 'Submitted', 'mms-membership' ); ?></th>
						<th class="mms-col-act"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $result['rows'] ) ) : ?>
					<tr>
						<td colspan="8" class="mms-table-empty"><?php esc_html_e( 'No applications match these filters.', 'mms-membership' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<?php
						$detail_url = add_query_arg(
							array(
								'page'        => self::PAGE,
								'application' => (int) $row->id,
							),
							admin_url( 'admin.php' )
						);
						$type_label = isset( $types[ $row->membership_type ] ) ? $types[ $row->membership_type ]['label'] : $row->membership_type;
						$doc_total  = (int) $row->doc_total;
						$doc_done   = (int) $row->doc_reviewed;
						?>
						<tr>
							<td class="mms-col-check"><input type="checkbox" class="mms-check-row" name="application_ids[]" value="<?php echo (int) $row->id; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: application reference */ __( 'Select application %s', 'mms-membership' ), '' !== (string) $row->reference ? $row->reference : '#' . (int) $row->id ) ); ?>" /></td>
							<td><strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( '' !== (string) $row->reference ? $row->reference : '#' . (int) $row->id ); ?></a></strong></td>
							<td>
								<?php if ( $row->company_name ) : ?>
									<a href="<?php echo esc_url( $detail_url ); ?>"><strong><?php echo esc_html( $row->company_name ); ?></strong></a>
								<?php else : ?>
									—
								<?php endif; ?>
								<?php if ( ! empty( $row->company_reg_no ) ) : ?>
									<br /><span class="mms-muted"><?php echo esc_html( $row->company_reg_no ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $type_label ); ?></td>
							<td><span class="mms-pill mms-status mms-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( MMS_Status::label( $row->status ) ); ?></span></td>
							<td>
								<?php
								printf(
									/* translators: 1: reviewed documents, 2: total documents */
									esc_html__( '%1$d / %2$d reviewed', 'mms-membership' ),
									(int) $doc_done,
									(int) $doc_total
								);
								?>
							</td>
							<td><?php echo esc_html( $row->submitted_at ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->submitted_at ) : '—' ); ?></td>
							<td>
								<div class="mms-row-actions">
									<a class="button button-small" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Review', 'mms-membership' ); ?></a>
									<?php if ( ! empty( $row->membership_no ) && in_array( $row->status, array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) ) : ?>
										<a class="button button-small" href="<?php echo esc_url( self::certificate_url( (int) $row->id ) ); ?>"><?php esc_html_e( 'Certificate', 'mms-membership' ); ?></a>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg(
									array(
										'page'            => self::PAGE,
										'status'          => $filters['status'],
										'membership_type' => $filters['membership_type'],
										's'               => $filters['search'],
										'order'           => $filters['order'],
										'paged'           => '%#%',
									),
									admin_url( 'admin.php' )
								),
								'format'    => '',
								'current'   => $filters['paged'],
								'total'     => $pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
			</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Application detail screen.
	 *
	 * @param int $id Application ID.
	 * @return void
	 */
	private static function render_detail( $id ) {
		global $wpdb;

		$application = MMS_Applications::get( $id );

		if ( ! $application ) {
			?>
			<div class="wrap mms-wrap mms-admin-ui">
				<h1><?php esc_html_e( 'Application not found', 'mms-membership' ); ?></h1>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( '&larr; Back to Company List', 'mms-membership' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$meta      = MMS_Applications::get_meta( $id );
		$documents = MMS_Applications::get_documents( $id );

		$logs = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . MMS_DB::table( MMS_DB::ACTIVITY_LOG ) . ' WHERE application_id = %d ORDER BY id DESC LIMIT 50', $id ),
			ARRAY_A
		);

		$type        = MMS_Membership_Types::get_type( $application['membership_type'] );
		$type_label  = $type ? $type['label'] : $application['membership_type'];
		$doc_labels  = MMS_Membership_Types::get_document_types();
		$doc_status  = MMS_Status::document_statuses();
		$message     = isset( $_GET['mms_msg'] ) ? sanitize_key( wp_unslash( $_GET['mms_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$back_url    = admin_url( 'admin.php?page=' . self::PAGE );
		$certificate_url = ( ! empty( $application['membership_no'] ) && in_array( $application['status'], array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) )
			? self::certificate_url( (int) $application['id'] )
			: '';

		$pay_kind = MMS_Payments::kind_for( $application );
		$payments = MMS_Payments::for_application( $id );

		$member_user     = ! empty( $application['member_user_id'] )
			? get_userdata( (int) $application['member_user_id'] )
			: false;
		$member_is_admin = $member_user && user_can( $member_user, 'mms_review_application' );
		$member_password = MMS_Members::generate_password();
		$member_pic_email = isset( $meta['pic_email'] ) ? sanitize_email( $meta['pic_email'] ) : '';
		$member_login     = MMS_Members::suggest_login( $member_pic_email );

		if ( MMS_Status::APPROVED_UNPAID === $application['status'] && ! MMS_Payments::pending_invoice( $id, MMS_Payments::KIND_INITIAL ) ) {
			MMS_Payments::ensure_invoice( $application, $meta, $type, MMS_Payments::KIND_INITIAL );
			$payments = MMS_Payments::for_application( $id );
		}

		$open_invoice = '' !== $pay_kind ? MMS_Payments::pending_invoice( $id, $pay_kind ) : null;
		$manual_open  = '' !== $pay_kind;
		$manual_amount = $open_invoice ? (float) $open_invoice['amount'] : ( $type ? (float) $type['fee'] : 0.0 );
		$channels      = self::manual_channels();
		$pay_labels    = MMS_Payments::statuses();

		?>
		<div class="wrap mms-wrap mms-admin-ui">
			<div class="mms-hero mms-hero-compact">
				<div>
					<p class="mms-hero-eyebrow"><?php esc_html_e( 'Application review', 'mms-membership' ); ?></p>
					<h1>
						<?php
						printf(
							/* translators: %s: reference number */
							esc_html__( 'Application %s', 'mms-membership' ),
							esc_html( '' !== (string) $application['reference'] ? $application['reference'] : '#' . (int) $application['id'] )
						);
						?>
					</h1>
					<p class="mms-hero-sub">
						<?php echo esc_html( $type_label ); ?>
						<?php if ( $type ) : ?>
							· <?php echo esc_html( number_format_i18n( (float) $type['fee'], 2 ) . ' ' . $type['currency'] ); ?>
							· <?php echo esc_html( (int) $type['duration_months'] . ' ' . __( 'months', 'mms-membership' ) ); ?>
						<?php endif; ?>
					</p>
				</div>
				<div class="mms-hero-actions">
					<?php if ( '' !== $certificate_url ) : ?>
						<a class="mms-btn mms-btn-primary" href="<?php echo esc_url( $certificate_url ); ?>"><span class="dashicons dashicons-media-document" aria-hidden="true"></span><?php esc_html_e( 'Download certificate', 'mms-membership' ); ?></a>
					<?php endif; ?>
					<a class="mms-btn mms-btn-ghost" href="<?php echo esc_url( $back_url ); ?>"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span><?php esc_html_e( 'All companies', 'mms-membership' ); ?></a>
				</div>
			</div>

			<?php if ( 'company_created' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Company added. Review the details below.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'doc_saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Document review saved.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'decision_saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Decision saved and the applicant has been notified.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'decision_docs' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This application cannot be approved while a document is marked Insufficient. Mark the documents Sufficient first, or request more information.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'decision_note' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'A note to the applicant is required when requesting information or rejecting an application.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'decision_status' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This application is no longer waiting for a review decision.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'manual_payment_saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Payment recorded. The invoice is paid and the receipt is now available.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'payment_paid' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This invoice has already been paid.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'payment_not_open' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This membership is not currently awaiting a payment.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'payment_missing' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The invoice could not be found or created.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'payment_invalid' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Check the payment amount and date, then try again.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_password_saved' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The password was changed and the new login details were emailed to the member.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_password_email_failed' === $message ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The password was changed, but the email could not be sent. Share the new password with the member through a secure channel.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_password_short' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The new password must be at least 8 characters long.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_password_no_account' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No member account is linked to this company, so the password could not be changed.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_password_admin' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The linked account has administrator access. Change its password from the Users screen instead.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_created' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Member account created and the login details were emailed to the member.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_email_failed' === $message ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The member account was created, but the login email could not be sent. Share the username and password with the member through a secure channel.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_failed' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The member account could not be created. The Person In Charge email may already belong to another user, or the username is already taken.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_username_taken' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That username is already taken. Choose another username and submit again.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_invalid' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enter a valid account email address to create the member account.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_exists' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'This company already has a linked member account. Use the reset password form below it.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_updated' === $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Member account details saved.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_email_taken' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That email address is already used by another account. Choose a different email.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_username_invalid' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enter a valid username using letters, numbers, spaces, underscores and hyphens only.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_update_failed' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The member account details could not be saved. Check the username and email address, then try again.', 'mms-membership' ); ?></p></div>
			<?php elseif ( 'member_account_admin' === $message ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The linked account has administrator access. Edit it from the Users screen instead.', 'mms-membership' ); ?></p></div>
			<?php endif; ?>

			<div class="mms-detail-summary">
				<span class="mms-pill mms-status mms-status-<?php echo esc_attr( $application['status'] ); ?>"><?php echo esc_html( MMS_Status::label( $application['status'] ) ); ?></span>
				<span class="mms-detail-meta">
					<strong><?php echo esc_html( ! empty( $meta['company_name'] ) ? $meta['company_name'] : $type_label ); ?></strong>
				</span>
				<span class="mms-detail-meta">
					<?php
					printf(
						/* translators: %s: date and time */
						esc_html__( 'Submitted %s', 'mms-membership' ),
						esc_html( $application['submitted_at'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $application['submitted_at'] ) : '—' )
					);
					?>
				</span>
				<?php if ( ! empty( $application['certificate_no'] ) ) : ?>
					<span class="mms-detail-meta">
						<?php
						printf(
							/* translators: %s: certificate number */
							esc_html__( 'Certificate %s', 'mms-membership' ),
							esc_html( $application['certificate_no'] )
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<div class="mms-detail-grid">
				<div class="mms-card">
					<h2><?php esc_html_e( 'Company details', 'mms-membership' ); ?></h2>
					<table class="mms-kv">
						<tbody>
							<?php
							$company_rows = array(
								__( 'Company name', 'mms-membership' )      => 'company_name',
								__( 'SSM / ROS number', 'mms-membership' )  => 'company_reg_no',
								__( 'State', 'mms-membership' )             => 'company_state',
								__( 'Address', 'mms-membership' )           => 'company_address',
								__( 'Phone', 'mms-membership' )             => 'company_phone',
								__( 'Email', 'mms-membership' )             => 'company_email',
								__( 'Website', 'mms-membership' )           => 'company_website',
							);
							foreach ( $company_rows as $label => $key ) :
								$value = isset( $meta[ $key ] ) ? $meta[ $key ] : '';
								?>
								<tr>
									<th><?php echo esc_html( $label ); ?></th>
									<td>
										<?php
										if ( '' === trim( (string) $value ) ) {
											echo '—';
										} elseif ( 'company_email' === $key ) {
											echo '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>';
										} elseif ( 'company_website' === $key ) {
											echo '<a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $value ) . '</a>';
										} else {
											echo nl2br( esc_html( $value ) );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="mms-card">
					<h2><?php esc_html_e( 'Person In Charge', 'mms-membership' ); ?></h2>
					<table class="mms-kv">
						<tbody>
							<?php
							$pic_rows = array(
								__( 'Name', 'mms-membership' )     => 'pic_name',
								__( 'IC / passport', 'mms-membership' ) => 'pic_ic',
								__( 'Position', 'mms-membership' ) => 'pic_position',
								__( 'Email', 'mms-membership' )    => 'pic_email',
								__( 'Phone', 'mms-membership' )    => 'pic_phone',
							);
							foreach ( $pic_rows as $label => $key ) :
								$value = isset( $meta[ $key ] ) ? $meta[ $key ] : '';
								?>
								<tr>
									<th><?php echo esc_html( $label ); ?></th>
									<td>
										<?php
										if ( '' === trim( (string) $value ) ) {
											echo '—';
										} elseif ( 'pic_email' === $key ) {
											echo '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>';
										} else {
											echo esc_html( $value );
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

			<h2 class="mms-section-title"><?php esc_html_e( 'Member account', 'mms-membership' ); ?></h2>

			<div class="mms-card mms-member-account">
				<?php if ( ! $member_user ) : ?>
					<p class="mms-muted"><?php esc_html_e( 'No member account is linked to this company yet. Create one below — the login details will be emailed immediately.', 'mms-membership' ); ?></p>

					<form class="mms-member-password-form mms-member-create-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mms_admin_member_create" />
						<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
						<?php wp_nonce_field( self::NONCE_ACCOUNT ); ?>

						<h3><?php esc_html_e( 'Create member account', 'mms-membership' ); ?></h3>
						<p class="mms-muted"><?php esc_html_e( 'Type the account email manually if the application has no Person In Charge email.', 'mms-membership' ); ?></p>

						<div class="mms-form-field">
							<label for="mms-member-email"><?php esc_html_e( 'Account email', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<input type="email" id="mms-member-email" name="account_email" value="<?php echo esc_attr( $member_pic_email ); ?>" autocomplete="email" maxlength="100" required />
							<span class="mms-form-hint"><?php esc_html_e( 'The login details will be sent to this address.', 'mms-membership' ); ?></span>
						</div>

						<div class="mms-form-field">
							<label for="mms-member-username"><?php esc_html_e( 'Username', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<input type="text" id="mms-member-username" name="user_login" value="<?php echo esc_attr( $member_login ); ?>" autocomplete="off" spellcheck="false" maxlength="60" required />
							<span class="mms-username-status" id="mms-member-username-status" aria-live="polite"><?php esc_html_e( 'Checking availability…', 'mms-membership' ); ?></span>
						</div>

						<div class="mms-form-field">
							<label for="mms-member-create-password"><?php esc_html_e( 'Password', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
							<div class="mms-password-row">
								<input type="text" id="mms-member-create-password" name="new_password" value="<?php echo esc_attr( $member_password ); ?>" autocomplete="new-password" spellcheck="false" minlength="8" maxlength="200" required />
								<button type="button" class="mms-btn mms-btn-outline mms-generate-member-password"><?php esc_html_e( 'Generate', 'mms-membership' ); ?></button>
							</div>
							<span class="mms-form-hint"><?php esc_html_e( 'At least 8 characters. The member can change this password after signing in.', 'mms-membership' ); ?></span>
						</div>

						<div class="mms-form-actions">
							<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Create account & email login details', 'mms-membership' ); ?></button>
						</div>
					</form>
				<?php else : ?>
					<?php if ( $member_is_admin ) : ?>
						<table class="mms-kv">
							<tbody>
								<tr>
									<th><?php esc_html_e( 'Username', 'mms-membership' ); ?></th>
									<td><strong><?php echo esc_html( $member_user->user_login ); ?></strong></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Email', 'mms-membership' ); ?></th>
									<td><a href="mailto:<?php echo esc_attr( $member_user->user_email ); ?>"><?php echo esc_html( $member_user->user_email ); ?></a></td>
								</tr>
							</tbody>
						</table>
						<p class="mms-muted"><?php esc_html_e( 'This account has administrator access. Edit it from the Users screen instead.', 'mms-membership' ); ?></p>
					<?php else : ?>
						<form class="mms-member-password-form mms-member-edit-form" data-mms-user-id="<?php echo (int) $member_user->ID; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mms_admin_member_update" />
							<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
							<?php wp_nonce_field( self::NONCE_UPDATE ); ?>

							<h3><?php esc_html_e( 'Account details', 'mms-membership' ); ?></h3>
							<p class="mms-muted"><?php esc_html_e( 'Update the login username or email address. The password is not changed here.', 'mms-membership' ); ?></p>

							<div class="mms-form-field">
								<label for="mms-member-edit-username"><?php esc_html_e( 'Username', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
								<input type="text" id="mms-member-edit-username" name="user_login" value="<?php echo esc_attr( $member_user->user_login ); ?>" autocomplete="off" spellcheck="false" maxlength="60" required />
								<span class="mms-username-status" id="mms-member-edit-username-status" aria-live="polite"><?php esc_html_e( 'Checking availability…', 'mms-membership' ); ?></span>
								<span class="mms-form-hint"><?php esc_html_e( 'The member signs in with this username. Tell them if you change it.', 'mms-membership' ); ?></span>
							</div>

							<div class="mms-form-field">
								<label for="mms-member-edit-email"><?php esc_html_e( 'Email', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
								<input type="email" id="mms-member-edit-email" name="account_email" value="<?php echo esc_attr( $member_user->user_email ); ?>" autocomplete="email" maxlength="100" required />
								<span class="mms-form-hint"><?php esc_html_e( 'Used for login emails and password resets.', 'mms-membership' ); ?></span>
							</div>

							<div class="mms-form-actions">
								<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Save account details', 'mms-membership' ); ?></button>
							</div>
						</form>

						<form class="mms-member-password-form mms-member-reset-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mms_admin_member_password" />
							<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
							<?php wp_nonce_field( self::NONCE_PASSWORD ); ?>

							<h3><?php esc_html_e( 'Reset password', 'mms-membership' ); ?></h3>
							<p class="mms-muted"><?php esc_html_e( 'Keep the generated password, or type a new one. The password is saved immediately and emailed to the member together with their username.', 'mms-membership' ); ?></p>

							<div class="mms-form-field">
								<label for="mms-member-password"><?php esc_html_e( 'New password', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
								<div class="mms-password-row">
									<input type="text" id="mms-member-password" name="new_password" value="<?php echo esc_attr( $member_password ); ?>" autocomplete="new-password" spellcheck="false" minlength="8" maxlength="200" required />
									<button type="button" class="mms-btn mms-btn-outline mms-generate-member-password"><?php esc_html_e( 'Generate', 'mms-membership' ); ?></button>
								</div>
								<span class="mms-form-hint"><?php esc_html_e( 'At least 8 characters. The member can change this password after signing in.', 'mms-membership' ); ?></span>
							</div>

							<div class="mms-form-actions">
								<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Save password & email member', 'mms-membership' ); ?></button>
							</div>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<h2 class="mms-section-title"><?php esc_html_e( 'Supporting documents', 'mms-membership' ); ?></h2>

			<?php if ( empty( $documents ) ) : ?>
				<p class="mms-muted"><?php esc_html_e( 'No documents were uploaded with this application.', 'mms-membership' ); ?></p>
			<?php else : ?>
				<?php foreach ( $documents as $document ) : ?>
					<?php
					$doc_key     = $document['doc_type'];
					$doc_label   = isset( $doc_labels[ $doc_key ] ) ? $doc_labels[ $doc_key ]['label'] : $doc_key;
					$is_required = $type && in_array( $doc_key, (array) $type['required_docs'], true );
					$view_url    = self::document_url( (int) $document['id'] );
					$is_image    = in_array( $document['mime_type'], array( 'image/jpeg', 'image/png' ), true );
					$status      = isset( $doc_status[ $document['review_status'] ] ) ? $document['review_status'] : 'pending';
					?>
					<div class="mms-doc-card mms-doc-<?php echo esc_attr( $status ); ?>">
						<div class="mms-doc-card-head">
							<div>
								<strong><?php echo esc_html( $doc_label ); ?></strong>
								<?php if ( $is_required ) : ?>
									<span class="mms-badge mms-badge-required"><?php esc_html_e( 'Required', 'mms-membership' ); ?></span>
								<?php endif; ?>
								<div class="mms-muted">
									<?php
									printf(
										/* translators: 1: file name, 2: file size, 3: upload date */
										esc_html__( '%1$s · %2$s · uploaded %3$s', 'mms-membership' ),
										esc_html( $document['original_name'] ),
										esc_html( size_format( (int) $document['file_size'] ) ),
										esc_html( $document['uploaded_at'] ? mysql2date( get_option( 'date_format' ), $document['uploaded_at'] ) : '—' )
									);
									?>
								</div>
							</div>
							<span class="mms-doc-status mms-doc-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $doc_status[ $status ] ); ?></span>
						</div>

						<div class="mms-doc-body">
							<div class="mms-doc-preview">
								<?php if ( $is_image ) : ?>
									<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer">
										<img src="<?php echo esc_url( $view_url ); ?>" alt="<?php echo esc_attr( $doc_label ); ?>" />
									</a>
								<?php elseif ( 'application/pdf' === $document['mime_type'] ) : ?>
									<iframe src="<?php echo esc_url( $view_url ); ?>" title="<?php echo esc_attr( $doc_label ); ?>" loading="lazy"></iframe>
								<?php else : ?>
									<p class="mms-muted"><?php esc_html_e( 'This file cannot be previewed here.', 'mms-membership' ); ?></p>
								<?php endif; ?>
								<p class="mms-doc-links">
									<a href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in new tab', 'mms-membership' ); ?></a>
								</p>
							</div>

							<form class="mms-doc-review" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="mms_update_document" />
								<input type="hidden" name="document_id" value="<?php echo (int) $document['id']; ?>" />
								<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
								<?php wp_nonce_field( self::NONCE_DOC ); ?>

								<fieldset>
									<legend><?php esc_html_e( 'Document review', 'mms-membership' ); ?></legend>
									<?php foreach ( $doc_status as $value => $label ) : ?>
										<label class="mms-radio">
											<input type="radio" name="review_status" value="<?php echo esc_attr( $value ); ?>" <?php checked( $status, $value ); ?> />
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>

								<p>
									<label for="mms-note-<?php echo (int) $document['id']; ?>"><?php esc_html_e( 'Note to applicant (optional)', 'mms-membership' ); ?></label>
									<textarea id="mms-note-<?php echo (int) $document['id']; ?>" name="admin_note" rows="2" class="large-text"><?php echo esc_textarea( (string) $document['admin_note'] ); ?></textarea>
								</p>

								<?php submit_button( __( 'Save review', 'mms-membership' ), 'secondary', 'submit', false ); ?>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php
			$insufficient = array_values(
				array_filter(
					$documents,
					function ( $document ) {
						return 'reject' === $document['review_status'];
					}
				)
			);
			$can_decide = in_array(
				$application['status'],
				array( MMS_Status::SUBMITTED, MMS_Status::UNDER_REVIEW, MMS_Status::NEED_INFO ),
				true
			);
			?>

			<h2 class="mms-section-title"><?php esc_html_e( 'Decision', 'mms-membership' ); ?></h2>

			<div class="mms-card mms-decision">
				<?php if ( ! empty( $insufficient ) ) : ?>
					<p class="mms-decision-warning">
						<?php
						printf(
							/* translators: %d: number of documents marked insufficient */
							esc_html( _n( '%d document is marked Insufficient. Approval is blocked until it is resolved.', '%d documents are marked Insufficient. Approval is blocked until they are resolved.', count( $insufficient ), 'mms-membership' ) ),
							count( $insufficient )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $application['notes'] ) ) : ?>
					<p class="mms-muted mms-last-note">
						<strong><?php esc_html_e( 'Last decision note:', 'mms-membership' ); ?></strong>
						<?php echo nl2br( esc_html( $application['notes'] ) ); ?>
					</p>
				<?php endif; ?>

				<?php if ( MMS_Status::NEED_INFO === $application['status'] && ! empty( $application['resume_token'] ) ) : ?>
					<p class="mms-resume-link">
						<strong><?php esc_html_e( 'Upload link sent to the applicant:', 'mms-membership' ); ?></strong><br />
						<input type="text" readonly class="large-text code" onclick="this.select();" value="<?php echo esc_attr( MMS_Applications::resume_url( $application['resume_token'] ) ); ?>" />
					</p>
				<?php endif; ?>

				<?php if ( $can_decide ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-decision-form">
						<input type="hidden" name="action" value="mms_application_decision" />
						<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
						<?php wp_nonce_field( self::NONCE_DECISION ); ?>

						<fieldset>
							<legend><?php esc_html_e( 'Review outcome', 'mms-membership' ); ?></legend>
							<label class="mms-radio">
								<input type="radio" name="decision" value="under_review" <?php checked( $application['status'], MMS_Status::UNDER_REVIEW ); ?> />
								<?php esc_html_e( 'Mark as under review', 'mms-membership' ); ?>
							</label>
							<label class="mms-radio">
								<input type="radio" name="decision" value="need_info" <?php checked( $application['status'], MMS_Status::NEED_INFO ); ?> />
								<?php esc_html_e( 'Request more information', 'mms-membership' ); ?>
							</label>
							<label class="mms-radio">
								<input type="radio" name="decision" value="rejected" />
								<?php esc_html_e( 'Reject application', 'mms-membership' ); ?>
							</label>
							<label class="mms-radio">
								<input type="radio" name="decision" value="approved_unpaid" />
								<?php esc_html_e( 'Approve — payment pending', 'mms-membership' ); ?>
							</label>
						</fieldset>

						<p>
							<label for="mms-decision-note"><?php esc_html_e( 'Note to applicant', 'mms-membership' ); ?></label>
							<textarea id="mms-decision-note" name="decision_note" rows="3" class="large-text"><?php echo esc_textarea( (string) $application['notes'] ); ?></textarea>
							<span class="mms-muted"><?php esc_html_e( 'Required when requesting more information or rejecting. Sent to the applicant by email.', 'mms-membership' ); ?></span>
						</p>

						<?php submit_button( __( 'Save decision', 'mms-membership' ), 'primary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<p class="mms-muted">
						<?php
						printf(
							/* translators: %s: current application status */
							esc_html__( 'This application is currently “%s”. Further status changes happen in the payment and renewal phases.', 'mms-membership' ),
							esc_html( MMS_Status::label( $application['status'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $application['reviewed_at'] ) ) : ?>
					<p class="mms-muted">
						<?php
						printf(
							/* translators: %s: date and time */
							esc_html__( 'Last reviewed: %s', 'mms-membership' ),
							esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $application['reviewed_at'] ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<h2 class="mms-section-title"><?php esc_html_e( 'Payments', 'mms-membership' ); ?></h2>
			<div class="mms-card mms-payments-card">
				<?php if ( empty( $payments ) ) : ?>
					<p class="mms-muted"><?php esc_html_e( 'No invoice has been generated for this application yet.', 'mms-membership' ); ?></p>
				<?php else : ?>
					<div class="mms-table-scroll">
						<table class="widefat mms-table mms-payments-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'mms-membership' ); ?></th>
									<th><?php esc_html_e( 'Invoice', 'mms-membership' ); ?></th>
									<th><?php esc_html_e( 'Type', 'mms-membership' ); ?></th>
									<th><?php esc_html_e( 'Amount', 'mms-membership' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mms-membership' ); ?></th>
									<th><?php esc_html_e( 'Documents', 'mms-membership' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $payments as $payment ) : ?>
									<?php
									$pay_status = isset( $pay_labels[ $payment['status'] ] ) ? $pay_labels[ $payment['status'] ] : $payment['status'];
									$pay_date   = 'paid' === $payment['status'] && ! empty( $payment['paid_at'] ) ? $payment['paid_at'] : $payment['created_at'];
									?>
									<tr>
										<td><?php echo esc_html( $pay_date ? mysql2date( get_option( 'date_format' ), $pay_date ) : '—' ); ?></td>
										<td><strong><?php echo esc_html( $payment['invoice_no'] ); ?></strong></td>
										<td><?php echo esc_html( MMS_Payments::kind_label( $payment['kind'] ) ); ?></td>
										<td><?php echo esc_html( MMS_Payments::format_amount( $payment['amount'], $payment['currency'] ) ); ?></td>
										<td><span class="mms-pill mms-payment-status is-<?php echo esc_attr( $payment['status'] ); ?>"><?php echo esc_html( $pay_status ); ?></span></td>
										<td class="mms-payment-docs">
											<a href="<?php echo esc_url( MMS_Billing::invoice_url( $payment['id'], true ) ); ?>"><?php esc_html_e( 'Invoice PDF', 'mms-membership' ); ?></a>
											<?php if ( 'paid' === $payment['status'] ) : ?>
												<a href="<?php echo esc_url( MMS_Billing::receipt_url( $payment['id'], true ) ); ?>"><?php esc_html_e( 'Receipt PDF', 'mms-membership' ); ?></a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<?php if ( $manual_open ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mms-manual-payment">
						<input type="hidden" name="action" value="mms_manual_payment" />
						<input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>" />
						<input type="hidden" name="payment_id" value="<?php echo $open_invoice ? (int) $open_invoice['id'] : 0; ?>" />
						<?php wp_nonce_field( self::NONCE_PAYMENT ); ?>

						<h3><?php esc_html_e( 'Record a manual payment', 'mms-membership' ); ?></h3>
						<p class="mms-muted"><?php esc_html_e( 'Use this for bank transfers, cash or cheques. Recording a payment marks the invoice paid, activates the membership and makes the receipt available.', 'mms-membership' ); ?></p>

						<div class="mms-form-grid">
							<div class="mms-form-field">
								<label for="mms-payment-amount"><?php esc_html_e( 'Amount (MYR)', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
								<input type="number" id="mms-payment-amount" name="amount" step="0.01" min="0.01" value="<?php echo esc_attr( number_format( $manual_amount, 2, '.', '' ) ); ?>" required />
							</div>
							<div class="mms-form-field">
								<label for="mms-payment-date"><?php esc_html_e( 'Payment date', 'mms-membership' ); ?> <span class="mms-req">*</span></label>
								<input type="date" id="mms-payment-date" name="paid_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required />
							</div>
							<div class="mms-form-field">
								<label for="mms-payment-channel"><?php esc_html_e( 'Channel', 'mms-membership' ); ?></label>
								<select id="mms-payment-channel" name="payment_channel">
									<?php foreach ( $channels as $channel_key => $channel_label ) : ?>
										<option value="<?php echo esc_attr( $channel_key ); ?>"><?php echo esc_html( $channel_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="mms-form-field">
								<label for="mms-payment-reference"><?php esc_html_e( 'Reference', 'mms-membership' ); ?></label>
								<input type="text" id="mms-payment-reference" name="payment_reference" maxlength="100" placeholder="<?php esc_attr_e( 'Bank transaction / cheque number', 'mms-membership' ); ?>" />
							</div>
							<div class="mms-form-field is-wide">
								<label for="mms-payment-note"><?php esc_html_e( 'Internal note (optional)', 'mms-membership' ); ?></label>
								<textarea id="mms-payment-note" name="payment_note" rows="2"></textarea>
							</div>
						</div>

						<div class="mms-form-actions">
							<button type="submit" class="mms-btn mms-btn-primary"><?php esc_html_e( 'Record payment', 'mms-membership' ); ?></button>
						</div>
					</form>
				<?php endif; ?>
			</div>

			<h2 class="mms-section-title"><?php esc_html_e( 'Activity log', 'mms-membership' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p class="mms-muted"><?php esc_html_e( 'No activity recorded yet.', 'mms-membership' ); ?></p>
			<?php else : ?>
				<div class="mms-table-card">
				<table class="widefat striped mms-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Action', 'mms-membership' ); ?></th>
							<th><?php esc_html_e( 'Details', 'mms-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log['created_at'] ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['created_at'] ) : '—' ); ?></td>
								<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
								<td><?php echo esc_html( (string) $log['details'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the review decision for an application and notify the applicant.
	 *
	 * @return void
	 */
	public static function handle_decision() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to decide on applications.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_DECISION );

		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;
		$application    = MMS_Applications::get( $application_id );

		if ( ! $application ) {
			self::back_to( $application_id, 'not_found' );
		}

		$decidable = array( MMS_Status::SUBMITTED, MMS_Status::UNDER_REVIEW, MMS_Status::NEED_INFO );
		if ( ! in_array( $application['status'], $decidable, true ) ) {
			self::back_to( $application_id, 'decision_status' );
		}

		$decisions = array(
			MMS_Status::UNDER_REVIEW    => __( 'Under review', 'mms-membership' ),
			MMS_Status::NEED_INFO       => __( 'Additional information requested', 'mms-membership' ),
			MMS_Status::REJECTED        => __( 'Rejected', 'mms-membership' ),
			MMS_Status::APPROVED_UNPAID => __( 'Approved — payment pending', 'mms-membership' ),
		);

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		if ( ! isset( $decisions[ $decision ] ) ) {
			self::back_to( $application_id, 'decision_status' );
		}

		$note = isset( $_POST['decision_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['decision_note'] ) ) : '';

		if ( in_array( $decision, array( MMS_Status::NEED_INFO, MMS_Status::REJECTED ), true ) && '' === trim( $note ) ) {
			self::back_to( $application_id, 'decision_note' );
		}

		$documents = MMS_Applications::get_documents( $application_id );

		if ( MMS_Status::APPROVED_UNPAID === $decision ) {
			foreach ( $documents as $document ) {
				if ( 'reject' === $document['review_status'] ) {
					self::back_to( $application_id, 'decision_docs' );
				}
			}
		}

		self::apply_decision( $application, $decision, $note );

		self::back_to( $application_id, 'decision_saved' );
	}

	/**
	 * Apply a review decision, log it and notify the applicant.
	 *
	 * Shared by the detail screen and the bulk action handler.
	 *
	 * @param array  $application Application row.
	 * @param string $decision New status slug.
	 * @param string $note Reviewer note.
	 * @return void
	 */
	private static function apply_decision( $application, $decision, $note ) {
		global $wpdb;

		$application_id = (int) $application['id'];
		$documents      = MMS_Applications::get_documents( $application_id );
		$now            = current_time( 'mysql' );

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'status'      => $decision,
				'notes'       => $note,
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => $application_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		MMS_Applications::log(
			$application_id,
			'decision',
			sprintf(
				/* translators: 1: new status label, 2: optional note */
				__( 'Status changed to “%1$s”.%2$s', 'mms-membership' ),
				MMS_Status::label( $decision ),
				'' !== trim( $note ) ? ' — ' . $note : ''
			),
			get_current_user_id()
		);

		$token = MMS_Status::NEED_INFO === $decision ? MMS_Applications::ensure_token( $application_id ) : '';
		if ( '' === $token ) {
			MMS_Applications::clear_token( $application_id );
		}

		$meta             = MMS_Applications::get_meta( $application_id );
		$set_password_url = '';

		if ( MMS_Status::APPROVED_UNPAID === $decision ) {
			$member = MMS_Members::create_from_application( $application, $meta );

			if ( is_wp_error( $member ) ) {
				MMS_Applications::log(
					$application_id,
					'member_account_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Member account could not be created: %s', 'mms-membership' ),
						$member->get_error_message()
					),
					get_current_user_id()
				);
			} else {
				MMS_Applications::log(
					$application_id,
					$member['created'] ? 'member_account_created' : 'member_account_linked',
					sprintf(
						/* translators: 1: user ID, 2: account email */
						__( 'Member account #%1$d (%2$s) %3$s.', 'mms-membership' ),
						$member['user_id'],
						isset( $meta['pic_email'] ) ? $meta['pic_email'] : '',
						$member['created'] ? __( 'created', 'mms-membership' ) : __( 'already existed and was linked', 'mms-membership' )
					),
					get_current_user_id()
				);

				$password_token   = MMS_Members::issue_set_password_token( $member['user_id'] );
				$set_password_url = MMS_Members::set_password_url( $member['user_id'], $password_token );

				MMS_Applications::log(
					$application_id,
					'set_password_link',
					__( 'One-time set-password link generated (valid 72 hours).', 'mms-membership' ),
					get_current_user_id()
				);
			}

			$invoice = MMS_Payments::ensure_invoice(
				array_merge( $application, array( 'status' => $decision ) ),
				$meta,
				MMS_Membership_Types::get_type( $application['membership_type'] ),
				MMS_Payments::KIND_INITIAL
			);

			if ( ! $invoice ) {
				MMS_Applications::log(
					$application_id,
					'invoice_failed',
					__( 'The approval invoice could not be generated.', 'mms-membership' ),
					get_current_user_id()
				);
			}
		}

		self::send_decision_email( $application, $meta, $documents, $decision, $note, $token, $set_password_url );
	}

	/**
	 * Apply a decision to several applications from the list screen.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to review applications.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_BULK );

		$list_url = admin_url( 'admin.php?page=' . self::PAGE );
		$ids      = array();

		if ( isset( $_POST['application_ids'] ) ) {
			foreach ( (array) wp_unslash( $_POST['application_ids'] ) as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		$bulk    = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$allowed = array( MMS_Status::UNDER_REVIEW, MMS_Status::APPROVED_UNPAID );

		if ( empty( $ids ) || ! in_array( $bulk, $allowed, true ) ) {
			wp_safe_redirect( add_query_arg( 'mms_msg', 'bulk_none', $list_url ) );
			exit;
		}

		$decidable = array( MMS_Status::SUBMITTED, MMS_Status::UNDER_REVIEW, MMS_Status::NEED_INFO );
		$done      = 0;
		$skipped   = 0;
		$blocked   = 0;

		foreach ( $ids as $application_id ) {
			$application = MMS_Applications::get( $application_id );

			if ( ! $application || ! in_array( $application['status'], $decidable, true ) ) {
				$skipped++;
				continue;
			}

			if ( MMS_Status::APPROVED_UNPAID === $bulk ) {
				$insufficient = false;
				foreach ( MMS_Applications::get_documents( $application_id ) as $document ) {
					if ( 'reject' === $document['review_status'] ) {
						$insufficient = true;
						break;
					}
				}

				if ( $insufficient ) {
					$blocked++;
					continue;
				}
			}

			self::apply_decision( $application, $bulk, '' );
			$done++;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_msg'     => 'bulk_done',
					'mms_bulk'    => $done,
					'mms_skipped' => $skipped,
					'mms_blocked' => $blocked,
				),
				$list_url
			)
		);
		exit;
	}

	/**
	 * Email the applicant about a review decision.
	 *
	 * @param array  $application Application row.
	 * @param array  $meta Company / PIC row.
	 * @param array  $documents Documents.
	 * @param string $decision New status slug.
	 * @param string $note Reviewer note.
	 * @param string $token Resume token (need_info only).
	 * @param string $set_password_url One-time set-password URL (approval only).
	 * @return void
	 */
	private static function send_decision_email( $application, $meta, $documents, $decision, $note, $token = '', $set_password_url = '' ) {
		$email = isset( $meta['pic_email'] ) ? $meta['pic_email'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$reference = '' !== (string) $application['reference'] ? $application['reference'] : '#' . (int) $application['id'];
		$name      = isset( $meta['pic_name'] ) ? $meta['pic_name'] : '';
		$type      = MMS_Membership_Types::get_type( $application['membership_type'] );
		$type_name = $type ? $type['label'] : $application['membership_type'];

		$subject = '';
		$lines   = array();

		if ( MMS_Status::APPROVED_UNPAID === $decision && '' !== $set_password_url ) {
			self::send_approval_email( $application, $meta, $reference, $type_name, $note, $set_password_url );
			return;
		}

		switch ( $decision ) {
			case MMS_Status::UNDER_REVIEW:
				/* translators: %s: reference number */
				$subject   = sprintf( __( 'Application %s is under review', 'mms-membership' ), $reference );
				$lines[]   = __( 'We have started reviewing your application. No action is needed from you at this stage.', 'mms-membership' );
				break;

			case MMS_Status::NEED_INFO:
				/* translators: %s: reference number */
				$subject   = sprintf( __( 'Action needed for application %s', 'mms-membership' ), $reference );
				$lines[]   = __( 'We need additional information before your application can be approved.', 'mms-membership' );
				$lines[]   = __( 'Reviewer note:', 'mms-membership' ) . ' ' . $note;

				$doc_labels = MMS_Membership_Types::get_document_types();
				$problems   = array();
				foreach ( $documents as $document ) {
					if ( 'reject' !== $document['review_status'] ) {
						continue;
					}
					$label      = isset( $doc_labels[ $document['doc_type'] ] ) ? $doc_labels[ $document['doc_type'] ]['label'] : $document['doc_type'];
					$problems[] = '- ' . $label . ( ! empty( $document['admin_note'] ) ? ': ' . $document['admin_note'] : '' );
				}

				if ( ! empty( $problems ) ) {
					$lines[] = '';
					$lines[] = __( 'Documents that need attention:', 'mms-membership' );
					$lines   = array_merge( $lines, $problems );
				}

				$lines[] = '';

				if ( '' !== $token ) {
					$lines[] = __( 'Upload the replacement documents here:', 'mms-membership' );
					$lines[] = MMS_Applications::resume_url( $token );
				} else {
					$lines[] = __( 'Please reply to this email with the requested documents or corrections.', 'mms-membership' );
				}
				break;

			case MMS_Status::REJECTED:
				/* translators: %s: reference number */
				$subject   = sprintf( __( 'Application %s — outcome', 'mms-membership' ), $reference );
				$lines[]   = __( 'We are unable to approve your application at this time.', 'mms-membership' );
				$lines[]   = __( 'Reason:', 'mms-membership' ) . ' ' . $note;
				$lines[]   = '';
				$lines[]   = __( 'If you believe this is a mistake or your circumstances have changed, please contact us.', 'mms-membership' );
				break;

			case MMS_Status::APPROVED_UNPAID:
				/* translators: %s: reference number */
				$subject   = sprintf( __( 'Application %s approved', 'mms-membership' ), $reference );
				$lines[]   = __( 'Good news — your membership application has been approved.', 'mms-membership' );
				/* translators: %s: membership type label */
				$lines[]   = sprintf( __( 'Membership type: %s', 'mms-membership' ), $type_name );
				$lines[]   = __( 'Payment instructions will be sent in a separate email. Your membership becomes active once payment is received.', 'mms-membership' );
				if ( '' !== trim( $note ) ) {
					$lines[] = '';
					$lines[] = __( 'Note:', 'mms-membership' ) . ' ' . $note;
				}
				break;
		}

		if ( '' === $subject ) {
			return;
		}

		$body = array(
			/* translators: %s: applicant name */
			sprintf( __( 'Dear %s,', 'mms-membership' ), $name ),
			'',
		);

		$body = array_merge( $body, $lines );
		$body[] = '';
		/* translators: %s: reference number */
		$body[] = sprintf( __( 'Reference number: %s', 'mms-membership' ), $reference );
		$body[] = '';
		$body[] = $site;

		$sent = wp_mail( $email, $subject, implode( "\n", $body ) );

		MMS_Applications::log(
			$application['id'],
			$sent ? 'email_sent' : 'email_failed',
			$sent
				? sprintf(
					/* translators: %s: decision label */
					__( 'Decision email sent to the applicant: %s', 'mms-membership' ),
					MMS_Status::label( $decision )
				)
				: sprintf(
					/* translators: %s: decision label */
					__( 'Decision email (%s) could not be sent — check the site mail configuration.', 'mms-membership' ),
					MMS_Status::label( $decision )
				)
		);
	}

	/**
	 * HTML approval email with the one-time set-password link.
	 *
	 * @param array  $application Application row.
	 * @param array  $meta Company / PIC row.
	 * @param string $reference Reference number.
	 * @param string $type_name Membership type label.
	 * @param string $note Reviewer note.
	 * @param string $set_password_url One-time set-password URL.
	 * @return void
	 */
	private static function send_approval_email( $application, $meta, $reference, $type_name, $note, $set_password_url ) {
		$email = isset( $meta['pic_email'] ) ? $meta['pic_email'] : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name   = isset( $meta['pic_name'] ) ? $meta['pic_name'] : '';
		$type   = MMS_Membership_Types::get_type( $application['membership_type'] );
		$amount = self::format_fee( $type );
		$status = __( 'Payment pending', 'mms-membership' );
		$login  = MMS_Members::login_url();

		$context = array(
			'name'            => $name,
			'company_name'    => isset( $meta['company_name'] ) ? $meta['company_name'] : '',
			'reference'       => $reference,
			'membership_type' => $type_name,
			'amount'          => $amount,
		);

		$subject = MMS_Emails::subject( 'approved', $context );
		$message = MMS_Emails::body( 'approved', $context );

		$row = '<tr><td style="padding:6px 12px 6px 0;color:#646970;">%1$s</td><td style="padding:6px 0;font-weight:600;">%2$s</td></tr>';

		$details  = sprintf( $row, esc_html__( 'Reference number', 'mms-membership' ), esc_html( $reference ) );
		$details .= sprintf( $row, esc_html__( 'Membership type', 'mms-membership' ), esc_html( $type_name ) );
		$details .= sprintf( $row, esc_html__( 'Amount due', 'mms-membership' ), esc_html( $amount ) );
		$details .= sprintf( $row, esc_html__( 'Status', 'mms-membership' ), esc_html( $status ) );

		$note_html = '';
		if ( '' !== trim( $note ) ) {
			$note_html = '<p style="margin:16px 0 0;padding:12px 14px;background:#f6f7f7;border-left:3px solid #0f4c5c;">'
				. '<strong>' . esc_html__( 'Note from MACM:', 'mms-membership' ) . '</strong><br />'
				. nl2br( esc_html( $note ) ) . '</p>';
		}

		$body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f0f0f1;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">'
			. '<div style="background:#ffffff;border-radius:6px;padding:32px;">'
			. '<div style="margin:0 0 16px;font-size:15px;line-height:1.55;">' . $message . '</div>'
			. '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;font-size:15px;">' . $details . '</table>'
			. '<p style="margin:0 0 12px;">' . esc_html__( 'Set your password to access the member portal:', 'mms-membership' ) . '</p>'
			. '<p style="margin:0 0 8px;"><a href="' . esc_url( $set_password_url ) . '" style="display:inline-block;padding:12px 22px;background:#0f4c5c;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html__( 'Set your password', 'mms-membership' ) . '</a></p>'
			. '<p style="margin:0 0 20px;font-size:13px;color:#646970;">' . esc_html__( 'This one-time link is valid for 72 hours. If it has expired, contact MACM for a new link.', 'mms-membership' ) . '</p>'
			. '<p style="margin:0 0 4px;">' . esc_html__( 'After setting your password, log in to view your membership status and complete the payment:', 'mms-membership' ) . '</p>'
			. '<p style="margin:0 0 20px;"><a href="' . esc_url( $login ) . '" style="color:#0f4c5c;font-weight:600;">' . esc_html__( 'Log in & pay', 'mms-membership' ) . '</a></p>'
			. $note_html
			. '<p style="margin:24px 0 0;font-size:13px;color:#646970;">' . esc_html( $site ) . '</p>'
			. '</div></div></body></html>';

		$sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		MMS_Applications::log(
			$application['id'],
			$sent ? 'email_sent' : 'email_failed',
			$sent
				? sprintf(
					/* translators: %s: recipient email */
					__( 'Approval email with set-password link sent to %s.', 'mms-membership' ),
					$email
				)
				: __( 'Approval email could not be sent — check the site mail configuration.', 'mms-membership' )
		);
	}

	/**
	 * Format a membership fee for display (e.g. "RM 300.00").
	 *
	 * @param array|null $type Membership type definition.
	 * @return string
	 */
	private static function format_fee( $type ) {
		if ( ! $type ) {
			return '—';
		}

		$currency = ! empty( $type['currency'] ) ? strtoupper( $type['currency'] ) : 'MYR';
		$fee      = isset( $type['fee'] ) ? (float) $type['fee'] : 0.0;
		$amount   = number_format_i18n( $fee, 2 );

		return ( 'MYR' === $currency ) ? 'RM ' . $amount : $currency . ' ' . $amount;
	}

	/**
	 * Capability-protected URL for viewing a stored document.
	 *
	 * @param int $document_id Document ID.
	 * @return string
	 */
	private static function document_url( $document_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'mms_view_document',
					'document_id' => (int) $document_id,
				),
				admin_url( 'admin-post.php' )
			),
			'mms_view_document_' . (int) $document_id
		);
	}

	/**
	 * Save one document review (status + note).
	 *
	 * @return void
	 */
	public static function handle_document_review() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to review documents.', 'mms-membership' ), 403 );
		}

		check_admin_referer( self::NONCE_DOC );

		global $wpdb;

		$document_id    = isset( $_POST['document_id'] ) ? absint( $_POST['document_id'] ) : 0;
		$application_id = isset( $_POST['application_id'] ) ? absint( $_POST['application_id'] ) : 0;

		$table = MMS_DB::table( MMS_DB::DOCUMENT );
		$doc   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND application_id = %d", $document_id, $application_id ),
			ARRAY_A
		);

		$redirect = add_query_arg(
			array(
				'page'        => self::PAGE,
				'application' => $application_id,
			),
			admin_url( 'admin.php' )
		);

		if ( ! $doc ) {
			wp_safe_redirect( add_query_arg( 'mms_msg', 'doc_missing', $redirect ) );
			exit;
		}

		$status = isset( $_POST['review_status'] ) ? sanitize_key( wp_unslash( $_POST['review_status'] ) ) : 'pending';
		if ( ! array_key_exists( $status, MMS_Status::document_statuses() ) ) {
			$status = 'pending';
		}

		$note = isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : '';

		$wpdb->update(
			$table,
			array(
				'review_status' => $status,
				'admin_note'    => $note,
			),
			array( 'id' => $document_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$doc_types = MMS_Membership_Types::get_document_types();
		$doc_label = isset( $doc_types[ $doc['doc_type'] ] ) ? $doc_types[ $doc['doc_type'] ]['label'] : $doc['doc_type'];
		$statuses  = MMS_Status::document_statuses();

		MMS_Applications::log(
			$application_id,
			'document_review',
			sprintf(
				/* translators: 1: document name, 2: review status label */
				__( 'Document “%1$s” marked: %2$s', 'mms-membership' ),
				$doc_label,
				$statuses[ $status ]
			) . ( '' !== $note ? ' — ' . $note : '' ),
			get_current_user_id()
		);

		wp_safe_redirect( add_query_arg( 'mms_msg', 'doc_saved', $redirect ) );
		exit;
	}

	/**
	 * Stream a stored document to a reviewer (never exposed by direct URL).
	 *
	 * @return void
	 */
	public static function serve_document() {
		if ( ! current_user_can( 'mms_review_application' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this document.', 'mms-membership' ), 403 );
		}

		$document_id = isset( $_GET['document_id'] ) ? absint( $_GET['document_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'mms_view_document_' . $document_id );

		global $wpdb;

		$table = MMS_DB::table( MMS_DB::DOCUMENT );
		$doc   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $document_id ),
			ARRAY_A
		);

		if ( ! $doc ) {
			wp_die( esc_html__( 'Document not found.', 'mms-membership' ), 404 );
		}

		$uploads = wp_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$path    = realpath( $uploads['basedir'] . '/' . ltrim( $doc['stored_path'], '/\\' ) );

		if ( ! $base || ! $path || 0 !== strpos( $path, $base ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Document not found.', 'mms-membership' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $doc['mime_type'] ? $doc['mime_type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $doc['original_name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
