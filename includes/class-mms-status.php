<?php
/**
 * Application / membership statuses — single source of truth.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Status
 */
class MMS_Status {

	const DRAFT           = 'draft';
	const SUBMITTED       = 'submitted';
	const UNDER_REVIEW    = 'under_review';
	const NEED_INFO       = 'need_info';
	const REJECTED        = 'rejected';
	const APPROVED_UNPAID = 'approved_unpaid';
	const ACTIVE          = 'active';
	const EXPIRED         = 'expired';

	/**
	 * All valid application statuses.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function all() {
		return array(
			self::DRAFT           => __( 'Draft', 'mms-membership' ),
			self::SUBMITTED       => __( 'Submitted — pending review', 'mms-membership' ),
			self::UNDER_REVIEW    => __( 'Under review', 'mms-membership' ),
			self::NEED_INFO       => __( 'Additional information required', 'mms-membership' ),
			self::REJECTED        => __( 'Rejected', 'mms-membership' ),
			self::APPROVED_UNPAID => __( 'Approved — payment pending', 'mms-membership' ),
			self::ACTIVE          => __( 'Active', 'mms-membership' ),
			self::EXPIRED         => __( 'Expired', 'mms-membership' ),
		);
	}

	/**
	 * Document review statuses.
	 *
	 * @return array<string,string>
	 */
	public static function document_statuses() {
		return array(
			'pending' => __( 'Not reviewed', 'mms-membership' ),
			'ok'      => __( 'Sufficient', 'mms-membership' ),
			'reject'  => __( 'Insufficient', 'mms-membership' ),
		);
	}

	/**
	 * Payment statuses.
	 *
	 * @return array<string,string>
	 */
	public static function payment_statuses() {
		return array(
			'pending' => __( 'Pending payment', 'mms-membership' ),
			'paid'    => __( 'Paid', 'mms-membership' ),
			'failed'  => __( 'Failed', 'mms-membership' ),
			'refund'  => __( 'Refunded', 'mms-membership' ),
		);
	}

	/**
	 * Check whether a status slug is valid.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function is_valid( $status ) {
		return array_key_exists( $status, self::all() );
	}

	/**
	 * Label for a single status.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function label( $status ) {
		$all = self::all();

		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}
}
