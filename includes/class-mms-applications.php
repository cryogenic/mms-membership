<?php
/**
 * Application records: create, update, meta, documents and activity log.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Applications
 */
class MMS_Applications {

	/**
	 * Meta columns that may be written / queried.
	 *
	 * @var array<int,string>
	 */
	private static $meta_columns = array(
		'company_name',
		'company_reg_no',
		'company_address',
		'company_phone',
		'company_email',
		'company_website',
		'company_state',
		'pic_name',
		'pic_ic',
		'pic_position',
		'pic_email',
		'pic_phone',
	);

	/**
	 * Company / PIC row for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array<string,mixed>
	 */
	public static function get_meta( $application_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . MMS_DB::table( MMS_DB::APPLICATION_META ) . ' WHERE application_id = %d', (int) $application_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Uploaded documents for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_documents( $application_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . MMS_DB::table( MMS_DB::DOCUMENT ) . ' WHERE application_id = %d ORDER BY id ASC', (int) $application_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return the application's resume token, creating one when missing.
	 *
	 * @param int $application_id Application ID.
	 * @return string
	 */
	public static function ensure_token( $application_id ) {
		global $wpdb;

		$application = self::get( $application_id );
		if ( ! $application ) {
			return '';
		}

		if ( ! empty( $application['resume_token'] ) ) {
			return $application['resume_token'];
		}

		$token = wp_generate_password( 32, false, false );

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array( 'resume_token' => $token ),
			array( 'id' => (int) $application_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $token;
	}

	/**
	 * Find an application by its resume token.
	 *
	 * @param string $token Resume token.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;

		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( strlen( $token ) < 20 ) {
			return null;
		}

		$table = MMS_DB::table( MMS_DB::APPLICATION );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE resume_token = %s LIMIT 1", $token ),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['resume_token'] ) || ! hash_equals( $row['resume_token'], $token ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Remove the resume token (link stops working).
	 *
	 * @param int $application_id Application ID.
	 * @return void
	 */
	public static function clear_token( $application_id ) {
		global $wpdb;

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array( 'resume_token' => '' ),
			array( 'id' => (int) $application_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Resume-upload page URL for a token.
	 *
	 * @param string $token Resume token.
	 * @return string
	 */
	public static function resume_url( $token ) {
		$page = get_page_by_path( 'membership-application-documents' );

		if ( ! $page || 'publish' !== $page->post_status ) {
			$page = get_page_by_path( 'membership-registration' );
		}

		$base = ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : home_url( '/' );

		return add_query_arg( 'mms_resume', rawurlencode( (string) $token ), $base );
	}

	/**
	 * Insert a new draft application.
	 *
	 * @param string $membership_type Membership type slug.
	 * @return int Application ID (0 on failure).
	 */
	public static function create( $membership_type ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'reference'       => null,
				'membership_type' => $membership_type,
				'status'          => MMS_Status::DRAFT,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Reference number for an application, e.g. MMS-2026-0001.
	 *
	 * @param int $id Application ID.
	 * @return string
	 */
	public static function build_reference( $id ) {
		return sprintf( 'MMS-%s-%04d', gmdate( 'Y' ), (int) $id );
	}

	/**
	 * Mark an application as submitted (assigns the reference number).
	 *
	 * @param int $id Application ID.
	 * @return string Reference number (empty on failure).
	 */
	public static function mark_submitted( $id ) {
		global $wpdb;

		$reference = self::build_reference( $id );
		$now       = current_time( 'mysql' );

		$updated = $wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'reference'    => $reference,
				'status'       => MMS_Status::SUBMITTED,
				'submitted_at' => $now,
				'updated_at'   => $now,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false === $updated ? '' : $reference;
	}

	/**
	 * Save (insert or update) the company / PIC meta row.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $data Sanitised meta values.
	 * @return void
	 */
	public static function save_meta( $application_id, $data ) {
		global $wpdb;

		$application_id = (int) $application_id;
		if ( ! $application_id ) {
			return;
		}

		$row = array( 'application_id' => $application_id );
		foreach ( self::$meta_columns as $column ) {
			$row[ $column ] = isset( $data[ $column ] ) ? $data[ $column ] : '';
		}

		$row['extra']      = isset( $data['extra'] ) ? wp_json_encode( $data['extra'] ) : '';
		$row['created_at'] = current_time( 'mysql' );
		$row['updated_at'] = $row['created_at'];

		$table    = MMS_DB::table( MMS_DB::APPLICATION_META );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT application_id FROM {$table} WHERE application_id = %d", $application_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			unset( $row['created_at'] );
			$wpdb->update( $table, $row, array( 'application_id' => $application_id ) );
			return;
		}

		$wpdb->insert( $table, $row );
	}

	/**
	 * Store one uploaded document record.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $document Document data (doc_type, original_name, stored_path, mime_type, file_size).
	 * @return int Document ID.
	 */
	public static function add_document( $application_id, $document ) {
		global $wpdb;

		$wpdb->insert(
			MMS_DB::table( MMS_DB::DOCUMENT ),
			array(
				'application_id' => (int) $application_id,
				'doc_type'       => $document['doc_type'],
				'original_name'  => $document['original_name'],
				'stored_path'    => $document['stored_path'],
				'mime_type'      => $document['mime_type'],
				'file_size'      => (int) $document['file_size'],
				'review_status'  => 'pending',
				'uploaded_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Append an activity log entry.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $action Action slug.
	 * @param string $details Human readable details.
	 * @param int    $user_id Acting user (0 for public submissions).
	 * @return void
	 */
	public static function log( $application_id, $action, $details = '', $user_id = 0 ) {
		global $wpdb;

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$wpdb->insert(
			MMS_DB::table( MMS_DB::ACTIVITY_LOG ),
			array(
				'application_id' => (int) $application_id,
				'user_id'        => (int) $user_id,
				'action'         => $action,
				'details'        => $details,
				'ip_address'     => substr( $ip, 0, 45 ),
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Check whether a meta value is already registered on another application.
	 *
	 * @param string $column Meta column name.
	 * @param string $value Value to look for.
	 * @return bool
	 */
	public static function meta_exists( $column, $value ) {
		global $wpdb;

		$value = trim( (string) $value );
		if ( '' === $value || ! in_array( $column, self::$meta_columns, true ) ) {
			return false;
		}

		$table = MMS_DB::table( MMS_DB::APPLICATION_META );

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT application_id FROM {$table} WHERE {$column} = %s LIMIT 1", $value )
		);

		return ! empty( $found );
	}

	/**
	 * Link an application to a WordPress member account.
	 *
	 * @param int $application_id Application ID.
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function link_member( $application_id, $user_id ) {
		global $wpdb;

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array( 'member_user_id' => (int) $user_id ),
			array( 'id' => (int) $application_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Applications linked to a member account, newest first.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type array<int,string> $statuses Only return these statuses.
	 *     @type int               $limit    Maximum rows (0 = no limit).
	 *     @type int               $offset   Rows to skip.
	 * }
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_member( $user_id, $args = array() ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'statuses' => array(),
				'limit'    => 0,
				'offset'   => 0,
			)
		);

		$table  = MMS_DB::table( MMS_DB::APPLICATION );
		$sql    = "SELECT * FROM {$table} WHERE member_user_id = %d";
		$params = array( $user_id );

		$statuses = array_filter( array_map( 'sanitize_key', (array) $args['statuses'] ) );
		if ( $statuses ) {
			$sql     .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params   = array_merge( $params, array_values( $statuses ) );
		}

		$sql .= ' ORDER BY id DESC';

		$limit = max( 0, (int) $args['limit'] );
		if ( $limit ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, (int) $args['offset'] );
		}

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest application linked to a member account.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_member( $user_id ) {
		$rows = self::for_member( $user_id, array( 'limit' => 1 ) );

		return $rows ? $rows[0] : null;
	}

	/**
	 * Fetch a single application row.
	 *
	 * @param int $id Application ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::APPLICATION );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $row ? $row : null;
	}
}
