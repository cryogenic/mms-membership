<?php
/**
 * Member set-password page: one-time token link from the approval email.
 *
 * Shortcode: [mms_member_set_password]
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Set_Password
 */
class MMS_Set_Password {

	const SHORTCODE = 'mms_member_set_password';
	const ACTION    = 'mms_set_password';
	const NONCE     = 'mms_set_password';

	const MIN_LENGTH = 8;

	/**
	 * Single instance.
	 *
	 * @var MMS_Set_Password|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Set_Password
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
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Render the set-password form or a status panel.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );

		$done = isset( $_GET['mms_pw'] ) && 'done' === sanitize_key( wp_unslash( $_GET['mms_pw'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $done ) {
			$mms_state      = 'done';
			$mms_login_url  = MMS_Members::login_url();
			$mms_login_name = isset( $_GET['mms_login'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			ob_start();
			include MMS_MEMBERSHIP_DIR . 'templates/member/set-password-status.php';
			return ob_get_clean();
		}

		$key   = isset( $_GET['mms_key'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$login = isset( $_GET['mms_login'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$user = MMS_Members::verify_link( $login, $key );

		if ( is_wp_error( $user ) ) {
			$mms_state   = 'invalid';
			$mms_message = $user->get_error_message();

			ob_start();
			include MMS_MEMBERSHIP_DIR . 'templates/member/set-password-status.php';
			return ob_get_clean();
		}

		$mms_key     = $key;
		$mms_login   = $user->user_login;
		$mms_user_id = (int) $user->ID;
		$mms_errors  = $this->pull_errors( $user->ID );

		ob_start();
		include MMS_MEMBERSHIP_DIR . 'templates/member/set-password.php';
		return ob_get_clean();
	}

	/**
	 * Save the new password.
	 *
	 * @return void
	 */
	public function handle_submit() {
		$key   = isset( $_POST['mms_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mms_key'] ) ) : '';
		$login = isset( $_POST['mms_login'] ) ? sanitize_text_field( wp_unslash( $_POST['mms_login'] ) ) : '';

		$user = MMS_Members::verify_link( $login, $key );

		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( $this->page_url() );
			exit;
		}

		check_admin_referer( self::NONCE . '_' . $user->ID );

		$password = isset( $_POST['mms_password'] ) ? (string) wp_unslash( $_POST['mms_password'] ) : '';
		$confirm  = isset( $_POST['mms_password_confirm'] ) ? (string) wp_unslash( $_POST['mms_password_confirm'] ) : '';

		$errors = array();

		if ( strlen( $password ) < self::MIN_LENGTH ) {
			/* translators: %d: minimum password length */
			$errors[] = sprintf( __( 'The password must be at least %d characters long.', 'mms-membership' ), self::MIN_LENGTH );
		}

		if ( $password !== $confirm ) {
			$errors[] = __( 'The two passwords do not match.', 'mms-membership' );
		}

		if ( ! empty( $errors ) ) {
			set_transient( $this->errors_key( $user->ID ), $errors, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( MMS_Members::set_password_url( $user->ID, $key ) );
			exit;
		}

		MMS_Members::set_password( $user, $password );

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_pw'    => 'done',
					'mms_login' => rawurlencode( $user->user_login ),
				),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Base set-password page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		$page = get_page_by_path( 'member-set-password' );

		return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : home_url( '/' );
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
	 * Transient key for form errors.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function errors_key( $user_id ) {
		return 'mms_setpw_errors_' . (int) $user_id;
	}
}
