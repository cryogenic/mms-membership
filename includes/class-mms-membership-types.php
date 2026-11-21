<?php
/**
 * Membership types & supporting documents (default values).
 *
 * Fee values here are DEFAULT ASSUMPTIONS only. All values can be changed
 * through the `mms_settings` option (MMS → Settings) without touching code.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Membership_Types
 */
class MMS_Membership_Types {

	/**
	 * Default membership type catalogue.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults() {
		return array(
			'ordinary'  => array(
				'label'            => __( 'Ordinary Member', 'mms-membership' ),
				'code'             => 'OMC',
				'fee'              => 300.00,
				'currency'         => 'MYR',
				'duration_months'  => 12,
				'required_docs'    => array( 'ssm_cert', 'ic_pic', 'pic_appointment' ),
				'optional_docs'    => array( 'company_profile', 'bank_statement' ),
			),
			'associate' => array(
				'label'            => __( 'Associate Member', 'mms-membership' ),
				'code'             => 'AMC',
				'fee'              => 500.00,
				'currency'         => 'MYR',
				'duration_months'  => 12,
				'required_docs'    => array( 'ssm_cert', 'ic_pic', 'pic_appointment', 'company_profile' ),
				'optional_docs'    => array( 'bank_statement' ),
			),
			'corporate' => array(
				'label'            => __( 'Corporate Member', 'mms-membership' ),
				'code'             => 'CMC',
				'fee'              => 1000.00,
				'currency'         => 'MYR',
				'duration_months'  => 12,
				'required_docs'    => array( 'ssm_cert', 'ic_pic', 'pic_appointment', 'company_profile', 'bank_statement' ),
				'optional_docs'    => array(),
			),
		);
	}

	/**
	 * Default state / federal territory codes (JPN standard).
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function default_states() {
		return array(
			array( 'code' => '01', 'name' => __( 'Johor', 'mms-membership' ) ),
			array( 'code' => '02', 'name' => __( 'Kedah', 'mms-membership' ) ),
			array( 'code' => '03', 'name' => __( 'Kelantan', 'mms-membership' ) ),
			array( 'code' => '04', 'name' => __( 'Melaka', 'mms-membership' ) ),
			array( 'code' => '05', 'name' => __( 'Negeri Sembilan', 'mms-membership' ) ),
			array( 'code' => '06', 'name' => __( 'Pahang', 'mms-membership' ) ),
			array( 'code' => '07', 'name' => __( 'Pulau Pinang', 'mms-membership' ) ),
			array( 'code' => '08', 'name' => __( 'Perak', 'mms-membership' ) ),
			array( 'code' => '09', 'name' => __( 'Perlis', 'mms-membership' ) ),
			array( 'code' => '10', 'name' => __( 'Selangor', 'mms-membership' ) ),
			array( 'code' => '11', 'name' => __( 'Terengganu', 'mms-membership' ) ),
			array( 'code' => '12', 'name' => __( 'Sabah', 'mms-membership' ) ),
			array( 'code' => '13', 'name' => __( 'Sarawak', 'mms-membership' ) ),
			array( 'code' => '14', 'name' => __( 'WP Kuala Lumpur', 'mms-membership' ) ),
			array( 'code' => '15', 'name' => __( 'WP Labuan', 'mms-membership' ) ),
			array( 'code' => '16', 'name' => __( 'WP Putrajaya', 'mms-membership' ) ),
		);
	}

	/**
	 * State code catalogue in effect (option → default), filterable.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_states() {
		$settings = get_option( 'mms_settings', array() );
		$states   = isset( $settings['state_codes'] ) && is_array( $settings['state_codes'] ) && ! empty( $settings['state_codes'] )
			? $settings['state_codes']
			: self::default_states();

		/**
		 * Filter the state code catalogue.
		 *
		 * @param array $states State rows (code, name).
		 */
		return apply_filters( 'mms_state_codes', $states );
	}

	/**
	 * Code for a state name (empty when unknown).
	 *
	 * @param string $name State name.
	 * @return string
	 */
	public static function state_code( $name ) {
		$name = trim( (string) $name );

		if ( '' === $name ) {
			return '';
		}

		foreach ( self::get_states() as $state ) {
			if ( isset( $state['name'] ) && 0 === strcasecmp( trim( (string) $state['name'] ), $name ) ) {
				return isset( $state['code'] ) ? trim( (string) $state['code'] ) : '';
			}
		}

		return '';
	}

	/**
	 * Certificate code for a membership type (falls back to the slug).
	 *
	 * @param string $slug Membership type slug.
	 * @return string
	 */
	public static function type_code( $slug ) {
		$type = self::get_type( $slug );

		if ( $type && ! empty( $type['code'] ) ) {
			return strtoupper( (string) $type['code'] );
		}

		return strtoupper( preg_replace( '/[^a-z0-9]/i', '', (string) $slug ) );
	}

	/**
	 * Default document type catalogue.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function document_types() {
		return array(
			'ssm_cert'        => array(
				'label'    => __( 'Company registration certificate (SSM / ROS)', 'mms-membership' ),
				'accepted' => 'pdf,jpg,jpeg,png',
				'max_mb'   => 5,
			),
			'ic_pic'          => array(
				'label'    => __( 'Person In Charge IC / passport copy', 'mms-membership' ),
				'accepted' => 'pdf,jpg,jpeg,png',
				'max_mb'   => 5,
			),
			'pic_appointment' => array(
				'label'    => __( 'Person In Charge appointment letter', 'mms-membership' ),
				'accepted' => 'pdf,jpg,jpeg,png',
				'max_mb'   => 5,
			),
			'company_profile' => array(
				'label'    => __( 'Company profile', 'mms-membership' ),
				'accepted' => 'pdf',
				'max_mb'   => 5,
			),
			'bank_statement'  => array(
				'label'    => __( 'Bank statement (latest 3 months)', 'mms-membership' ),
				'accepted' => 'pdf',
				'max_mb'   => 5,
			),
		);
	}

	/**
	 * Document catalogue in effect (option → default), filterable.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_document_types() {
		$settings = get_option( 'mms_settings', array() );
		$docs     = isset( $settings['document_types'] ) && is_array( $settings['document_types'] ) && ! empty( $settings['document_types'] )
			? $settings['document_types']
			: self::document_types();

		/**
		 * Filter the document catalogue.
		 *
		 * @param array $docs Document types.
		 */
		return apply_filters( 'mms_document_types', $docs );
	}

	/**
	 * A single document type by key.
	 *
	 * @param string $key Document key.
	 * @return array<string,mixed>|null
	 */
	public static function get_document_type( $key ) {
		$docs = self::get_document_types();

		return isset( $docs[ $key ] ) ? $docs[ $key ] : null;
	}

	/**
	 * Membership types in effect (option → default), filterable.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_types() {
		$settings = get_option( 'mms_settings', array() );
		$types    = isset( $settings['membership_types'] ) && is_array( $settings['membership_types'] )
			? $settings['membership_types']
			: self::defaults();

		/**
		 * Filter membership types.
		 *
		 * @param array $types Membership types.
		 */
		return apply_filters( 'mms_membership_types', $types );
	}

	/**
	 * A single membership type by slug.
	 *
	 * @param string $slug Membership type slug.
	 * @return array<string,mixed>|null
	 */
	public static function get_type( $slug ) {
		$types = self::get_types();

		return isset( $types[ $slug ] ) ? $types[ $slug ] : null;
	}

	/**
	 * Fee for a membership type (0 if unknown).
	 *
	 * @param string $slug Membership type slug.
	 * @return float
	 */
	public static function get_fee( $slug ) {
		$type = self::get_type( $slug );

		return ( null !== $type && isset( $type['fee'] ) ) ? (float) $type['fee'] : 0.0;
	}
}
