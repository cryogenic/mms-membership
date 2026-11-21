<?php
/**
 * Bahagian awam (front-end): aset dan borang pendaftaran.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Public
 */
class MMS_Public {

	/**
	 * Instance tunggal.
	 *
	 * @var MMS_Public|null
	 */
	private static $instance = null;

	/**
	 * Dapatkan instance.
	 *
	 * @return MMS_Public
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Konstruktor: daftar hook bahagian awam.
	 */
	private function __construct() {
		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-registration.php';
		MMS_Registration::instance();

		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-resume.php';
		MMS_Resume::instance();

		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-set-password.php';
		MMS_Set_Password::instance();

		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-member-login.php';
		MMS_Member_Login::instance();

		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-portal.php';
		MMS_Portal::instance();

		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-directory.php';
		MMS_Directory::instance();

		MMS_Members::init_hooks();
		MMS_Payments::init();
		MMS_Certificate::init();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Muat aset bahagian awam.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'mms-public',
			MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css',
			array(),
			MMS_MEMBERSHIP_VERSION
		);
	}
}
