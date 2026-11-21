<?php
/**
 * Plugin Name:       MMS Membership
 * Plugin URI:        https://macm.my
 * Description:       MACM membership registration and management module (MMS): member applications, document review, approval, payments and member portal.
 * Version:           0.13.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            JWorks Enterprise
 * Text Domain:       mms-membership
 * Domain Path:       /languages
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MMS_MEMBERSHIP_VERSION', '0.13.1' );
define( 'MMS_DB_VERSION', '0.11.0' );
define( 'MMS_MEMBERSHIP_FILE', __FILE__ );
define( 'MMS_MEMBERSHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'MMS_MEMBERSHIP_URL', plugin_dir_url( __FILE__ ) );

$mms_composer_autoload = MMS_MEMBERSHIP_DIR . 'vendor/autoload.php';
if ( file_exists( $mms_composer_autoload ) ) {
	require_once $mms_composer_autoload;
}

require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-status.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-db.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-files.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-membership-types.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-applications.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-members.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-payments.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-certificate.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-billing.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-renewals.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-emails.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-install.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-updater.php';
require_once MMS_MEMBERSHIP_DIR . 'includes/class-mms-plugin.php';

register_activation_hook( __FILE__, array( 'MMS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MMS_Install', 'deactivate' ) );

MMS_Updater::init();

add_action( 'plugins_loaded', array( 'MMS_Install', 'maybe_upgrade_locked' ), 5 );
add_action( 'plugins_loaded', array( 'MMS_Plugin', 'instance' ) );
