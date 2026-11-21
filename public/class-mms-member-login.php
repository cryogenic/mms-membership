<?php
/**
 * Member login page (front-end, keeps members out of wp-admin).
 *
 * Shortcode: [mms_member_login]
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Member_Login
 */
class MMS_Member_Login {

	const SHORTCODE = 'mms_member_login';
	const ACTION    = 'mms_member_login';
	const NONCE     = 'mms_member_login';
	const AJAX_ACTION = 'mms_lost_password';
	const NONCE_RESET = 'mms_lost_password';

	/**
	 * Single instance.
	 *
	 * @var MMS_Member_Login|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Member_Login
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
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_login' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_login' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_lost_password' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_screen' ) );
		add_filter( 'login_headerurl', array( $this, 'login_header_url' ) );
		add_filter( 'login_headertext', array( $this, 'login_header_text' ) );
	}

	/**
	 * Render the login form.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_style( 'mms-login', MMS_MEMBERSHIP_URL . 'assets/css/mms-login.css', array( 'mms-public' ), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'mms-login', MMS_MEMBERSHIP_URL . 'assets/js/mms-login.js', array(), MMS_MEMBERSHIP_VERSION, true );
		wp_localize_script(
			'mms-login',
			'mmsLogin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_RESET ),
				'sending' => __( 'Sending…', 'mms-membership' ),
				'success' => __( 'If an account exists for that email, a reset link is on its way. Check your inbox and spam folder.', 'mms-membership' ),
				'error'   => __( 'Something went wrong. Please try again.', 'mms-membership' ),
			)
		);

		$mms_logged_in        = is_user_logged_in();
		$mms_portal_url       = MMS_Members::portal_url();
		$mms_registration_url = MMS_Members::registration_url();
		$mms_error      = isset( $_GET['mms_login_error'] ) ? sanitize_key( wp_unslash( $_GET['mms_login_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mms_username   = isset( $_GET['mms_login_name'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_login_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		include MMS_MEMBERSHIP_DIR . 'templates/member/login.php';
		return ob_get_clean();
	}

	/**
	 * Process the login form.
	 *
	 * @return void
	 */
	public function handle_login() {
		check_admin_referer( self::NONCE );

		$username = isset( $_POST['mms_username'] ) ? sanitize_text_field( wp_unslash( $_POST['mms_username'] ) ) : '';
		$password = isset( $_POST['mms_password'] ) ? (string) wp_unslash( $_POST['mms_password'] ) : '';
		$remember = ! empty( $_POST['mms_remember'] );
		$honeypot = isset( $_POST['mms_website'] ) ? trim( (string) wp_unslash( $_POST['mms_website'] ) ) : '';

		if ( '' !== $honeypot ) {
			$this->back( 'invalid', $username );
		}

		if ( '' === $username || '' === $password ) {
			$this->back( 'empty', $username );
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$this->back( 'invalid', $username );
		}

		$target = ( user_can( $user, 'mms_review_application' ) || user_can( $user, 'manage_options' ) )
			? admin_url()
			: MMS_Members::portal_url();

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Send a password reset link for a member account (AJAX).
	 *
	 * Always answers with the same message so the form cannot be used to
	 * discover which email addresses have accounts.
	 *
	 * @return void
	 */
	public function ajax_lost_password() {
		if ( ! check_ajax_referer( self::NONCE_RESET, 'nonce', false ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session has expired. Reload the page and try again.', 'mms-membership' ) ),
				403
			);
		}

		// "user_login" keeps the no-JS fallback (core wp-login.php form) working.
		if ( isset( $_POST['email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ) );
		} elseif ( isset( $_POST['user_login'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['user_login'] ) );
		} else {
			$email = '';
		}

		$honeypot = isset( $_POST['mms_website'] ) ? trim( (string) wp_unslash( $_POST['mms_website'] ) ) : '';

		if ( '' !== $honeypot ) {
			$this->reset_sent();
		}

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid email address.', 'mms-membership' ) ), 400 );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' !== $ip && get_transient( 'mms_lp_ip_' . md5( $ip ) ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please wait a moment before requesting another reset link.', 'mms-membership' ) ),
				429
			);
		}

		if ( '' !== $ip ) {
			set_transient( 'mms_lp_ip_' . md5( $ip ), 1, MINUTE_IN_SECONDS );
		}

		$user = get_user_by( 'email', $email );

		if ( $user && in_array( 'mms_member', (array) $user->roles, true ) ) {
			$mail_key = 'mms_lp_mail_' . md5( strtolower( $email ) );

			if ( ! get_transient( $mail_key ) ) {
				set_transient( $mail_key, 1, 5 * MINUTE_IN_SECONDS );
				retrieve_password( $user->user_login );
			}
		}

		$this->reset_sent();
	}

	/**
	 * Generic success response for the reset form.
	 *
	 * @return void
	 */
	private function reset_sent() {
		wp_send_json_success(
			array(
				'message' => __( 'If an account exists for that email, a reset link is on its way. Check your inbox and spam folder.', 'mms-membership' ),
			)
		);
	}

	/**
	 * Brand the core wp-login.php screens that the reset email links to.
	 *
	 * @return void
	 */
	public function enqueue_login_screen() {
		wp_enqueue_style(
			'mms-login-screens',
			MMS_MEMBERSHIP_URL . 'assets/css/mms-login-screens.css',
			array( 'login' ),
			MMS_MEMBERSHIP_VERSION
		);
	}

	/**
	 * Point the core login logo at the site home.
	 *
	 * @return string
	 */
	public function login_header_url() {
		return home_url( '/' );
	}

	/**
	 * Logo title used by core login screens.
	 *
	 * @return string
	 */
	public function login_header_text() {
		return __( 'MACM Membership System', 'mms-membership' );
	}

	/**
	 * Redirect back to the login page with an error code.
	 *
	 * @param string $code Error code.
	 * @param string $username Submitted username.
	 * @return void
	 */
	private function back( $code, $username ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_login_error' => $code,
					'mms_login_name'  => rawurlencode( $username ),
				),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Base login page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		$page = get_page_by_path( 'member-login' );

		return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : MMS_Members::login_url();
	}
}
