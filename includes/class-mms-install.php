<?php
/**
 * Pemasangan plugin: jadual DB, role, capability dan option default.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Install
 */
class MMS_Install {

	const DB_VERSION_OPTION = 'mms_db_version';
	const UPGRADE_LOCK       = 'mms_upgrade_lock';

	/**
	 * Jalankan semasa pengaktifan plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		self::install_roles();
		self::install_default_options();
		self::migrate_default_labels();
		self::migrate_certificate_settings();

		if ( class_exists( 'MMS_Renewals' ) ) {
			MMS_Renewals::schedule();
		}

		update_option( self::DB_VERSION_OPTION, MMS_DB_VERSION, false );
	}

	/**
	 * Jalankan semasa penyahaktifan plugin. Data tidak dipadam.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( class_exists( 'MMS_Renewals' ) ) {
			MMS_Renewals::unschedule();
		}

		flush_rewrite_rules();
	}

	/**
	 * Semak versi DB dan jalankan migrasi jika perlu.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== MMS_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Upgrade on the first request after a plugin update, with a lock.
	 *
	 * Runs on plugins_loaded so schema changes finish before business logic;
	 * admin_init stays as a fallback.
	 *
	 * @return void
	 */
	public static function maybe_upgrade_locked() {
		if ( get_option( self::DB_VERSION_OPTION ) === MMS_DB_VERSION ) {
			return;
		}

		if ( get_transient( self::UPGRADE_LOCK ) ) {
			return;
		}

		set_transient( self::UPGRADE_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		self::maybe_upgrade();

		delete_transient( self::UPGRADE_LOCK );
	}

	/**
	 * Cipta / kemas kini 5 jadual MMS menggunakan dbDelta().
	 *
	 * @return void
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$application     = MMS_DB::table( MMS_DB::APPLICATION );
		$meta            = MMS_DB::table( MMS_DB::APPLICATION_META );
		$document        = MMS_DB::table( MMS_DB::DOCUMENT );
		$payment         = MMS_DB::table( MMS_DB::PAYMENT );
		$activity        = MMS_DB::table( MMS_DB::ACTIVITY_LOG );

		$sql = array();

		$sql[] = "CREATE TABLE {$application} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			reference varchar(32) NOT NULL DEFAULT '',
			membership_type varchar(50) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			applicant_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			submitted_at datetime DEFAULT NULL,
			reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewed_at datetime DEFAULT NULL,
			notes longtext NULL,
			resume_token varchar(64) NOT NULL DEFAULT '',
			member_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			membership_no varchar(50) DEFAULT NULL,
			certificate_no varchar(50) NOT NULL DEFAULT '',
			started_at datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			UNIQUE KEY membership_no (membership_no),
			KEY certificate_no (certificate_no),
			KEY status (status),
			KEY resume_token (resume_token),
			KEY member_user_id (member_user_id),
			KEY membership_type (membership_type),
			KEY expires_at (expires_at),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$meta} (
			application_id bigint(20) unsigned NOT NULL,
			company_name varchar(255) NOT NULL DEFAULT '',
			company_reg_no varchar(100) NOT NULL DEFAULT '',
			company_address text NULL,
			company_phone varchar(40) NOT NULL DEFAULT '',
			company_email varchar(190) NOT NULL DEFAULT '',
			company_website varchar(190) NOT NULL DEFAULT '',
			company_state varchar(100) NOT NULL DEFAULT '',
			pic_name varchar(190) NOT NULL DEFAULT '',
			pic_ic varchar(30) NOT NULL DEFAULT '',
			pic_position varchar(120) NOT NULL DEFAULT '',
			pic_email varchar(190) NOT NULL DEFAULT '',
			pic_phone varchar(40) NOT NULL DEFAULT '',
			extra longtext NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (application_id),
			KEY company_reg_no (company_reg_no),
			KEY company_email (company_email),
			KEY company_state (company_state),
			KEY pic_email (pic_email)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$document} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL DEFAULT 0,
			doc_type varchar(50) NOT NULL DEFAULT '',
			original_name varchar(255) NOT NULL DEFAULT '',
			stored_path varchar(255) NOT NULL DEFAULT '',
			mime_type varchar(100) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			review_status varchar(20) NOT NULL DEFAULT 'pending',
			admin_note text NULL,
			uploaded_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY doc_type (doc_type),
			KEY review_status (review_status)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$payment} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL DEFAULT 0,
			invoice_no varchar(50) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL DEFAULT 'MYR',
			status varchar(20) NOT NULL DEFAULT 'pending',
			kind varchar(20) NOT NULL DEFAULT 'initial',
			provider varchar(30) NOT NULL DEFAULT 'toyyibpay',
			provider_ref varchar(100) NOT NULL DEFAULT '',
			transaction_ref varchar(100) NOT NULL DEFAULT '',
			channel varchar(40) NOT NULL DEFAULT '',
			raw_response longtext NULL,
			period_start datetime DEFAULT NULL,
			period_end datetime DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_no (invoice_no),
			KEY application_id (application_id),
			KEY status (status),
			KEY kind (kind),
			KEY provider_ref (provider_ref)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$activity} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			application_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL DEFAULT '',
			details longtext NULL,
			ip_address varchar(45) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY application_id (application_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Daftar role dan capability.
	 *
	 * @return void
	 */
	public static function install_roles() {
		// Member: front-end portal only, no wp-admin access.
		add_role(
			'mms_member',
			__( 'MMS Member', 'mms-membership' ),
			array( 'read' => true )
		);

		// Sub-admin: may review applications and manage members, cannot change settings.
		add_role(
			'mms_sub_admin',
			__( 'MMS Sub-Admin', 'mms-membership' ),
			array(
				'read'                   => true,
				'mms_review_application' => true,
				'mms_manage_members'     => true,
			)
		);

		self::update_role_labels();

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'mms_review_application' );
			$admin->add_cap( 'mms_manage_members' );
			$admin->add_cap( 'mms_manage_settings' );
		}
	}

	/**
	 * Ensure the MMS role display names are in English (add_role does not rename).
	 *
	 * @return void
	 */
	private static function update_role_labels() {
		$wp_roles = wp_roles();
		$labels   = array(
			'mms_member'    => __( 'MMS Member', 'mms-membership' ),
			'mms_sub_admin' => __( 'MMS Sub-Admin', 'mms-membership' ),
		);
		$changed  = false;

		foreach ( $labels as $role => $name ) {
			if ( isset( $wp_roles->roles[ $role ] ) && $wp_roles->roles[ $role ]['name'] !== $name ) {
				$wp_roles->roles[ $role ]['name'] = $name;
				$changed                          = true;
			}
		}

		if ( $changed ) {
			update_option( $wp_roles->role_key, $wp_roles->roles );
		}
	}

	/**
	 * Translate previously seeded Malay labels to English (one-time migration).
	 *
	 * @return void
	 */
	private static function migrate_default_labels() {
		$settings = get_option( 'mms_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$map = array(
			'Ahli Biasa'                           => 'Ordinary Member',
			'Ahli Bersekutu'                       => 'Associate Member',
			'Ahli Korporat'                        => 'Corporate Member',
			'Sijil pendaftaran SSM / ROS'          => 'Company registration certificate (SSM / ROS)',
			'Salinan IC / Pasport Person In Charge' => 'Person In Charge IC / passport copy',
			'Surat lantikan Person In Charge'      => 'Person In Charge appointment letter',
			'Profil syarikat'                      => 'Company profile',
			'Penyata bank (3 bulan terkini)'       => 'Bank statement (latest 3 months)',
		);

		$changed = false;

		if ( isset( $settings['membership_types'] ) && is_array( $settings['membership_types'] ) ) {
			foreach ( $settings['membership_types'] as $slug => $type ) {
				if ( isset( $type['label'], $map[ $type['label'] ] ) ) {
					$settings['membership_types'][ $slug ]['label'] = $map[ $type['label'] ];
					$changed                                        = true;
				}
			}
		}

		if ( isset( $settings['document_types'] ) && is_array( $settings['document_types'] ) ) {
			foreach ( $settings['document_types'] as $key => $doc ) {
				if ( isset( $doc['label'], $map[ $doc['label'] ] ) ) {
					$settings['document_types'][ $key ]['label'] = $map[ $doc['label'] ];
					$changed                                     = true;
				}
			}
		}

		if ( $changed ) {
			update_option( 'mms_settings', $settings, false );
		}
	}

	/**
	 * One-time migration: add certificate codes to saved membership types and
	 * seed the state code catalogue.
	 *
	 * @return void
	 */
	private static function migrate_certificate_settings() {
		if ( get_option( 'mms_certificate_settings' ) ) {
			return;
		}

		$settings = get_option( 'mms_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$codes = array(
			'ordinary'  => 'OMC',
			'associate' => 'AMC',
			'corporate' => 'CMC',
		);

		if ( isset( $settings['membership_types'] ) && is_array( $settings['membership_types'] ) ) {
			foreach ( $settings['membership_types'] as $slug => $type ) {
				if ( ! empty( $type['code'] ) ) {
					continue;
				}

				$fallback = strtoupper( substr( preg_replace( '/[^a-z0-9]/i', '', (string) $slug ), 0, 4 ) );
				$settings['membership_types'][ $slug ]['code'] = isset( $codes[ $slug ] ) ? $codes[ $slug ] : $fallback;
			}
		}

		if ( empty( $settings['state_codes'] ) ) {
			$settings['state_codes'] = MMS_Membership_Types::default_states();
		}

		update_option( 'mms_settings', $settings, false );
		update_option( 'mms_certificate_settings', 1, false );
	}

	/**
	 * Option default (jenis keahlian & dokumen).
	 *
	 * @return void
	 */
	public static function install_default_options() {
		$settings = get_option( 'mms_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( empty( $settings['membership_types'] ) ) {
			$settings['membership_types'] = MMS_Membership_Types::defaults();
		}
		if ( empty( $settings['document_types'] ) ) {
			$settings['document_types'] = MMS_Membership_Types::document_types();
		}
		if ( empty( $settings['state_codes'] ) ) {
			$settings['state_codes'] = MMS_Membership_Types::default_states();
		}
		if ( empty( $settings['currency'] ) ) {
			$settings['currency'] = 'MYR';
		}
		if ( empty( $settings['payment'] ) ) {
			$settings['payment'] = array(
				'enabled'       => 0,
				'mode'          => 'sandbox',
				'category_code' => '',
				'secret_key'    => '',
			);
		}

		update_option( 'mms_settings', $settings, false );
	}
}
