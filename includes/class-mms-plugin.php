<?php
/**
 * Pemuat utama plugin.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Plugin
 */
class MMS_Plugin {

	/**
	 * Instance tunggal.
	 *
	 * @var MMS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Dapatkan instance.
	 *
	 * @return MMS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Konstruktor: daftar hook.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( 'MMS_Install', 'maybe_upgrade_locked' ) );

		MMS_Renewals::init();
		MMS_Billing::init();

		// Public side is always loaded: it also handles admin-post.php submissions
		// (front-end form posts run with is_admin() === true).
		require_once MMS_MEMBERSHIP_DIR . 'public/class-mms-public.php';
		MMS_Public::instance();

		if ( is_admin() ) {
			require_once MMS_MEMBERSHIP_DIR . 'admin/class-mms-admin.php';
			MMS_Admin::instance();
		}
	}

	/**
	 * Muat terjemahan.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'mms-membership', false, dirname( plugin_basename( MMS_MEMBERSHIP_FILE ) ) . '/languages' );
	}
}
