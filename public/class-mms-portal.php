<?php
/**
 * Member portal: membership status, company details and profile forms.
 *
 * Shortcode: [mms_member_dashboard]
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Portal
 */
class MMS_Portal {

	const SHORTCODE = 'mms_member_dashboard';

	const PROFILE_ACTION  = 'mms_member_profile';
	const PROFILE_NONCE   = 'mms_member_profile';
	const PASSWORD_ACTION = 'mms_member_password';
	const PASSWORD_NONCE  = 'mms_member_password';

	const MIN_PASSWORD = 8;

	/**
	 * Single instance.
	 *
	 * @var MMS_Portal|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Portal
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'admin_post_' . self::PROFILE_ACTION, array( $this, 'handle_profile' ) );
		add_action( 'admin_post_' . self::PASSWORD_ACTION, array( $this, 'handle_password' ) );
	}

	/**
	 * Render the dashboard.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_style( 'mms-login', MMS_MEMBERSHIP_URL . 'assets/css/mms-login.css', array( 'mms-public' ), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_style( 'mms-portal', MMS_MEMBERSHIP_URL . 'assets/css/mms-portal.css', array( 'mms-login' ), MMS_MEMBERSHIP_VERSION );

		$mms_logged_in        = is_user_logged_in();
		$mms_login_url        = MMS_Members::login_url();
		$mms_registration_url = MMS_Members::registration_url();
		$mms_portal_url = MMS_Members::portal_url();
		$mms_msg        = isset( $_GET['mms_msg'] ) ? sanitize_key( wp_unslash( $_GET['mms_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mms_pay_state  = isset( $_GET['mms_payment'] ) ? sanitize_key( wp_unslash( $_GET['mms_payment'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mms_pay_error  = '';
		$mms_app        = null;
		$mms_companies  = array();
		$mms_company_error = '';

		if ( $mms_logged_in ) {
			$user          = wp_get_current_user();
			$mms_companies = $this->visible_companies( $user->ID );

			$mms_company_id = isset( $_GET['mms_company'] ) ? absint( wp_unslash( $_GET['mms_company'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $mms_company_id ) {
				$selected = MMS_Members::resolve_application( $user->ID, $mms_company_id );

				if ( is_wp_error( $selected ) ) {
					$mms_company_error = $selected->get_error_message();
				} elseif ( ! $this->is_visible( $selected ) ) {
					$mms_company_error = __( 'That company is not available from this account.', 'mms-membership' );
				} else {
					$mms_app = $selected;
					MMS_Members::set_active_application( $user->ID, (int) $selected['id'] );
				}
			}

			if ( ! $mms_app && ! empty( $mms_companies ) ) {
				$active_id = MMS_Members::active_application_id( $user->ID );

				foreach ( $mms_companies as $company ) {
					if ( $active_id && (int) $company['id'] === $active_id ) {
						$mms_app = $company;
						break;
					}
				}

				if ( ! $mms_app ) {
					$mms_app = $mms_companies[0];
				}
			}
		}

		$mms_editable = $mms_app && in_array( $mms_app['status'], array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true );
		$mms_meta     = $mms_app
			? array_merge(
				array_fill_keys( array( 'company_name', 'company_reg_no', 'company_address', 'company_phone', 'company_email', 'company_website', 'pic_name', 'pic_ic', 'pic_position', 'pic_email', 'pic_phone' ), '' ),
				MMS_Applications::get_meta( $mms_app['id'] )
			)
			: array();
		$mms_type     = $mms_app ? MMS_Membership_Types::get_type( $mms_app['membership_type'] ) : null;
		$mms_docs     = $mms_app ? MMS_Applications::get_documents( $mms_app['id'] ) : array();
		$mms_catalog  = MMS_Membership_Types::get_document_types();
		$mms_doc_state = MMS_Status::document_statuses();
		$mms_errors   = ( $mms_logged_in && $mms_app ) ? $this->pull_errors( $user->ID ) : array();
		$mms_ref      = $mms_app ? ( '' !== (string) $mms_app['reference'] ? $mms_app['reference'] : '#' . (int) $mms_app['id'] ) : '';
		$mms_fee      = $mms_type ? self::format_fee( $mms_type ) : '—';
		$mms_status   = $mms_app ? $mms_app['status'] : '';
		$mms_user     = $mms_logged_in ? wp_get_current_user() : null;
		$mms_payments = $mms_app ? MMS_Payments::for_application( $mms_app['id'] ) : array();
		$mms_pay_kind = $mms_app ? MMS_Payments::kind_for( $mms_app ) : '';
		$mms_pay_open = ( $mms_app && '' !== $mms_pay_kind ) ? MMS_Payments::latest_pending( $mms_app['id'], $mms_pay_kind ) : null;
		$mms_pay_enabled = MMS_Payments::is_enabled();
		$mms_pay_statuses = MMS_Payments::statuses();
		$mms_open_invoice = null;
		$mms_certificate_url = '';

		if ( $mms_app && MMS_Status::APPROVED_UNPAID === $mms_status ) {
			$mms_open_invoice = MMS_Payments::pending_invoice( $mms_app['id'], MMS_Payments::KIND_INITIAL );

			if ( ! $mms_open_invoice ) {
				MMS_Payments::ensure_invoice( $mms_app, $mms_meta, $mms_type, MMS_Payments::KIND_INITIAL );
				$mms_payments     = MMS_Payments::for_application( $mms_app['id'] );
				$mms_open_invoice = MMS_Payments::pending_invoice( $mms_app['id'], MMS_Payments::KIND_INITIAL );
			}
		}

		if ( $mms_app && ! empty( $mms_app['membership_no'] ) && in_array( $mms_status, array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) ) {
			$mms_certificate_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'         => MMS_Certificate::ACTION,
						'application_id' => (int) $mms_app['id'],
					),
					admin_url( 'admin-post.php' )
				),
				MMS_Certificate::NONCE . '_' . (int) $mms_app['id']
			);
		}

		$mms_company_list = array();

		foreach ( $mms_companies as $company ) {
			$mms_company_list[] = array(
				'id'           => (int) $company['id'],
				'label'        => self::company_label( $company ),
				'status'       => $company['status'],
				'status_label' => MMS_Status::label( $company['status'] ),
				'active'       => ( $mms_app && (int) $mms_app['id'] === (int) $company['id'] ),
			);
		}

		if ( 'error' === $mms_pay_state && isset( $_GET['mms_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$mms_key = sanitize_key( wp_unslash( $_GET['mms_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $mms_key ) {
				$mms_pay_error = get_transient( 'mms_payment_err_' . $mms_key );
				delete_transient( 'mms_payment_err_' . $mms_key );
			}
		}

		ob_start();
		include MMS_MEMBERSHIP_DIR . 'templates/member/dashboard.php';
		return ob_get_clean();
	}

	/**
	 * Save editable contact details.
	 *
	 * @return void
	 */
	public function handle_profile() {
		$user = $this->require_member();

		check_admin_referer( self::PROFILE_NONCE . '_' . $user->ID );

		$application = $this->require_editable( $user, isset( $_POST['company_id'] ) ? absint( wp_unslash( $_POST['company_id'] ) ) : 0 );

		$old  = MMS_Applications::get_meta( $application['id'] );
		$data = $old;

		$data['company_phone']   = isset( $_POST['company_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['company_phone'] ) ) : '';
		$data['company_email']   = isset( $_POST['company_email'] ) ? sanitize_email( wp_unslash( $_POST['company_email'] ) ) : '';
		$data['company_website'] = isset( $_POST['company_website'] ) ? esc_url_raw( wp_unslash( $_POST['company_website'] ) ) : '';
		$data['pic_phone']       = isset( $_POST['pic_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['pic_phone'] ) ) : '';

		$errors = array();

		if ( ! empty( $_POST['company_email'] ) && ! is_email( $data['company_email'] ) ) {
			$errors[] = __( 'The company email address is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['company_phone'] && ! self::is_phone( $data['company_phone'] ) ) {
			$errors[] = __( 'The company phone number is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['pic_phone'] && ! self::is_phone( $data['pic_phone'] ) ) {
			$errors[] = __( 'The Person In Charge phone number is not valid.', 'mms-membership' );
		}

		if ( ! empty( $_POST['company_website'] ) && '' === $data['company_website'] ) {
			$errors[] = __( 'The company website address is not valid.', 'mms-membership' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( $this->errors_key( $user->ID ), $errors, 10 * MINUTE_IN_SECONDS );
			$this->back();
		}

		MMS_Applications::save_meta( $application['id'], $data );

		$changed = array();
		foreach ( array( 'company_phone', 'company_email', 'company_website', 'pic_phone' ) as $field ) {
			if ( (string) $old[ $field ] !== (string) $data[ $field ] ) {
				$changed[] = $field;
			}
		}

		MMS_Applications::log(
			$application['id'],
			'profile_updated',
			empty( $changed )
				? __( 'Member opened the profile form without changing any values.', 'mms-membership' )
				: sprintf(
					/* translators: %s: comma separated field names */
					__( 'Member updated contact details: %s.', 'mms-membership' ),
					implode( ', ', $changed )
				),
			$user->ID
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_msg'     => 'profile_saved',
					'mms_company' => (int) $application['id'],
				),
				MMS_Members::portal_url()
			)
		);
		exit;
	}

	/**
	 * Change the member password.
	 *
	 * @return void
	 */
	public function handle_password() {
		$user = $this->require_member();

		check_admin_referer( self::PASSWORD_NONCE . '_' . $user->ID );

		$application = $this->require_editable( $user, isset( $_POST['company_id'] ) ? absint( wp_unslash( $_POST['company_id'] ) ) : 0 );

		$current = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
		$new     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		$confirm = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

		$errors = array();

		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			$errors[] = __( 'The current password is not correct.', 'mms-membership' );
		}

		if ( strlen( $new ) < self::MIN_PASSWORD ) {
			/* translators: %d: minimum password length */
			$errors[] = sprintf( __( 'The new password must be at least %d characters long.', 'mms-membership' ), self::MIN_PASSWORD );
		}

		if ( $new !== $confirm ) {
			$errors[] = __( 'The two new passwords do not match.', 'mms-membership' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( $this->errors_key( $user->ID ), $errors, 10 * MINUTE_IN_SECONDS );
			$this->back();
		}

		wp_set_password( $new, $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		MMS_Applications::log(
			$application['id'],
			'password_changed',
			__( 'Member changed their password from the portal.', 'mms-membership' ),
			$user->ID
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_msg'     => 'password_saved',
					'mms_company' => (int) $application['id'],
				),
				MMS_Members::portal_url()
			)
		);
		exit;
	}

	/**
	 * Ensure the visitor is a logged-in member.
	 *
	 * @return WP_User
	 */
	private function require_member() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( MMS_Members::login_url() );
			exit;
		}

		return wp_get_current_user();
	}

	/**
	 * Ensure the selected company belongs to the member and can be edited.
	 *
	 * @param WP_User $user           Current user.
	 * @param int     $application_id Selected company (0 = remembered/default).
	 * @return array<string,mixed>
	 */
	private function require_editable( $user, $application_id = 0 ) {
		$application = MMS_Members::resolve_application( $user->ID, $application_id );

		if ( is_wp_error( $application ) ) {
			wp_die( esc_html( $application->get_error_message() ), '', array( 'response' => 403 ) );
		}

		if ( ! $application ) {
			wp_safe_redirect( add_query_arg( 'mms_msg', 'no_account', MMS_Members::portal_url() ) );
			exit;
		}

		if ( ! in_array( $application['status'], array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) ) {
			$url = add_query_arg(
				array(
					'mms_msg'     => 'locked',
					'mms_company' => (int) $application['id'],
				),
				MMS_Members::portal_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		MMS_Members::set_active_application( $user->ID, (int) $application['id'] );

		return $application;
	}

	/**
	 * Companies that may be shown in the portal (draft and rejected are hidden).
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function visible_companies( $user_id ) {
		$companies = array();

		foreach ( MMS_Members::applications_for_user( $user_id ) as $company ) {
			if ( $this->is_visible( $company ) ) {
				$companies[] = $company;
			}
		}

		return $companies;
	}

	/**
	 * Whether an application is visible in the portal.
	 *
	 * @param array $application Application row.
	 * @return bool
	 */
	private function is_visible( $application ) {
		return ! in_array( $application['status'], array( MMS_Status::DRAFT, MMS_Status::REJECTED ), true );
	}

	/**
	 * Human readable company label for the selector.
	 *
	 * @param array $application Application row.
	 * @return string
	 */
	private static function company_label( $application ) {
		$meta = MMS_Applications::get_meta( (int) $application['id'] );
		$name = isset( $meta['company_name'] ) ? trim( (string) $meta['company_name'] ) : '';

		if ( '' !== $name ) {
			return $name;
		}

		$reference = isset( $application['reference'] ) ? (string) $application['reference'] : '';

		return '' !== $reference ? $reference : '#' . (int) $application['id'];
	}

	/**
	 * Redirect back to the portal.
	 *
	 * @return void
	 */
	private function back() {
		wp_safe_redirect( MMS_Members::portal_url() );
		exit;
	}

	/**
	 * Fetch and clear stored validation errors.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string>
	 */
	private function pull_errors( $user_id ) {
		$errors = get_transient( $this->errors_key( $user_id ) );
		delete_transient( $this->errors_key( $user_id ) );

		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Transient key for portal form errors.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function errors_key( $user_id ) {
		return 'mms_portal_errors_' . (int) $user_id;
	}

	/**
	 * Basic Malaysian phone number check (9–12 digits, optional +).
	 *
	 * @param string $value Raw phone number.
	 * @return bool
	 */
	private static function is_phone( $value ) {
		$clean = preg_replace( '/[^0-9+]/', '', (string) $value );

		return (bool) preg_match( '/^\+?[0-9]{9,12}$/', $clean );
	}

	/**
	 * Format a membership fee for display.
	 *
	 * @param array $type Membership type definition.
	 * @return string
	 */
	private static function format_fee( $type ) {
		$currency = ! empty( $type['currency'] ) ? strtoupper( $type['currency'] ) : 'MYR';
		$fee      = isset( $type['fee'] ) ? (float) $type['fee'] : 0.0;
		$amount   = number_format_i18n( $fee, 2 );

		return ( 'MYR' === $currency ) ? 'RM ' . $amount : $currency . ' ' . $amount;
	}
}
