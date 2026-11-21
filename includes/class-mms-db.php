<?php
/**
 * Helper nama jadual pangkalan data MMS.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_DB
 */
class MMS_DB {

	const APPLICATION    = 'application';
	const APPLICATION_META = 'application_meta';
	const DOCUMENT       = 'document';
	const PAYMENT        = 'payment';
	const ACTIVITY_LOG   = 'activity_log';

	/**
	 * Nama jadual penuh (dengan prefix WordPress).
	 *
	 * @param string $key Kunci jadual (lihat konstanta).
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;

		return $wpdb->prefix . 'mms_' . $key;
	}

	/**
	 * Senarai semua jadual MMS.
	 *
	 * @return array<int,string>
	 */
	public static function tables() {
		return array(
			self::table( self::APPLICATION ),
			self::table( self::APPLICATION_META ),
			self::table( self::DOCUMENT ),
			self::table( self::PAYMENT ),
			self::table( self::ACTIVITY_LOG ),
		);
	}
}
