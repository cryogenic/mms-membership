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
	}

	/**
	 * Render the login form.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );

		$mms_logged_in  = is_user_logged_in();
		$mms_portal_url = MMS_Members::portal_url();
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
