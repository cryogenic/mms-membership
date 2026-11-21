<?php
/**
 * Public member directory.
 *
 * Shortcode: [mms_directory]
 *
 * Lists active and expired members with search and pagination, and shows
 * company details on the same page.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Directory
 */
class MMS_Directory {

	const SHORTCODE  = 'mms_directory';
	const QUERY_ARG  = 'mms_member';
	const SEARCH_ARG = 'mms_q';
	const PAGE_ARG   = 'mms_page';
	const PER_PAGE   = 9;

	/**
	 * Single instance.
	 *
	 * @var MMS_Directory|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Directory
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
	}

	/**
	 * Render the directory or a single member.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );

		$atts = shortcode_atts(
			array(
				'title' => __( 'Member directory', 'mms-membership' ),
			),
			$atts,
			self::SHORTCODE
		);

		$base_url = get_permalink();
		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}

		$member_id = isset( $_GET[ self::QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search    = isset( $_GET[ self::SEARCH_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page      = isset( $_GET[ self::PAGE_ARG ] ) ? max( 1, absint( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$mms_title    = (string) $atts['title'];
		$mms_base_url = $base_url;
		$mms_search   = $search;

		ob_start();

		if ( $member_id > 0 ) {
			$mms_member   = self::get_member( $member_id );
			$mms_back_url = self::list_url( $base_url, $search, $page );

			if ( $mms_member ) {
				include MMS_MEMBERSHIP_DIR . 'templates/directory/detail.php';
			} else {
				include MMS_MEMBERSHIP_DIR . 'templates/directory/not-found.php';
			}
		} else {
			$mms_total       = self::count_members( $search );
			$mms_total_pages = max( 1, (int) ceil( $mms_total / self::PER_PAGE ) );
			$mms_page        = min( $page, $mms_total_pages );
			$mms_members     = $mms_total > 0 ? self::get_members( $search, $mms_page ) : array();
			$mms_from        = $mms_total > 0 ? ( ( $mms_page - 1 ) * self::PER_PAGE ) + 1 : 0;
			$mms_to          = min( $mms_total, $mms_page * self::PER_PAGE );
			$mms_current_url = self::list_url( $base_url, $search, $mms_page );
			$mms_page_links  = self::page_links( $base_url, $search, $mms_page, $mms_total_pages );

			include MMS_MEMBERSHIP_DIR . 'templates/directory/list.php';
		}

		return ob_get_clean();
	}

	/**
	 * Count published members, optionally filtered by a search term.
	 *
	 * @param string $search Search term.
	 * @return int
	 */
	public static function count_members( $search = '' ) {
		global $wpdb;

		$application = MMS_DB::table( MMS_DB::APPLICATION );
		$meta        = MMS_DB::table( MMS_DB::APPLICATION_META );

		list( $where, $params ) = self::list_where( $search );

		$sql = "SELECT COUNT(*)
			FROM {$application} a
			INNER JOIN {$meta} m ON m.application_id = a.id
			WHERE {$where}";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Published members for one page, optionally filtered by a search term.
	 *
	 * @param string $search   Search term.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_members( $search = '', $page = 1, $per_page = self::PER_PAGE ) {
		global $wpdb;

		$application = MMS_DB::table( MMS_DB::APPLICATION );
		$meta        = MMS_DB::table( MMS_DB::APPLICATION_META );

		list( $where, $params ) = self::list_where( $search );

		$params[] = (int) $per_page;
		$params[] = ( (int) $page - 1 ) * (int) $per_page;

		$sql = "SELECT a.id, a.membership_no, a.status, a.started_at, a.expires_at, m.company_name
			FROM {$application} a
			INNER JOIN {$meta} m ON m.application_id = a.id
			WHERE {$where}
			ORDER BY m.company_name ASC, a.id ASC
			LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A single published member row.
	 *
	 * @param int $member_id Application ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_member( $member_id ) {
		global $wpdb;

		$application = MMS_DB::table( MMS_DB::APPLICATION );
		$meta        = MMS_DB::table( MMS_DB::APPLICATION_META );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT a.id, a.membership_no, a.status, a.started_at, a.expires_at,
					m.company_name, m.company_reg_no, m.company_address, m.company_phone
				FROM {$application} a
				INNER JOIN {$meta} m ON m.application_id = a.id
				WHERE a.id = %d AND a.status IN (%s, %s)",
				(int) $member_id,
				MMS_Status::ACTIVE,
				MMS_Status::EXPIRED
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * WHERE clause and placeholders for the published member list.
	 *
	 * @param string $search Search term.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private static function list_where( $search ) {
		global $wpdb;

		$where  = 'a.status IN (%s, %s)';
		$params = array( MMS_Status::ACTIVE, MMS_Status::EXPIRED );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( m.company_name LIKE %s OR m.company_reg_no LIKE %s OR a.membership_no LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		return array( $where, $params );
	}

	/**
	 * Directory URL with the current search and page.
	 *
	 * @param string $base_url Directory page URL.
	 * @param string $search   Search term.
	 * @param int    $page     Page number (1 omits the argument).
	 * @return string
	 */
	public static function list_url( $base_url, $search = '', $page = 1 ) {
		$url = $base_url;

		if ( '' !== $search ) {
			$url = add_query_arg( self::SEARCH_ARG, $search, $url );
		}

		if ( $page > 1 ) {
			$url = add_query_arg( self::PAGE_ARG, (int) $page, $url );
		}

		return $url;
	}

	/**
	 * Pagination links for the directory.
	 *
	 * @param string $base_url    Directory page URL.
	 * @param string $search      Search term.
	 * @param int    $page        Current page.
	 * @param int    $total_pages Total pages.
	 * @return array<int,string>
	 */
	private static function page_links( $base_url, $search, $page, $total_pages ) {
		if ( $total_pages < 2 ) {
			return array();
		}

		$base = add_query_arg( self::PAGE_ARG, 'MMSPAGE', self::list_url( $base_url, $search, 1 ) );
		$base = str_replace( 'MMSPAGE', '%#%', esc_url_raw( $base ) );

		$links = paginate_links(
			array(
				'base'      => $base,
				'format'    => '',
				'current'   => (int) $page,
				'total'     => (int) $total_pages,
				'mid_size'  => 1,
				'end_size'  => 1,
				'prev_text' => __( '‹ Previous', 'mms-membership' ),
				'next_text' => __( 'Next ›', 'mms-membership' ),
				'type'      => 'array',
			)
		);

		return is_array( $links ) ? $links : array();
	}

	/**
	 * Whether the membership is currently in force.
	 *
	 * @param array<string,mixed> $member Member row.
	 * @return bool
	 */
	public static function is_current( $member ) {
		if ( MMS_Status::ACTIVE !== $member['status'] ) {
			return false;
		}

		$expires = self::timestamp( $member['expires_at'] );

		return 0 === $expires || $expires >= time();
	}

	/**
	 * Localised date from a MySQL datetime.
	 *
	 * @param string|null $value MySQL datetime.
	 * @return string
	 */
	public static function format_date( $value ) {
		$timestamp = self::timestamp( $value );

		return $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';
	}

	/**
	 * Readable validity range.
	 *
	 * @param array<string,mixed> $member Member row.
	 * @return string
	 */
	public static function validity( $member ) {
		$start = self::format_date( $member['started_at'] );
		$end   = self::format_date( $member['expires_at'] );

		if ( '' === $start && '' === $end ) {
			return '';
		}

		if ( '' === $start ) {
			/* translators: %s: expiry date */
			return sprintf( __( 'Until %s', 'mms-membership' ), $end );
		}

		if ( '' === $end ) {
			/* translators: %s: start date */
			return sprintf( __( 'From %s', 'mms-membership' ), $start );
		}

		return $start . ' – ' . $end;
	}

	/**
	 * First letter of the company name, for the card avatar.
	 *
	 * @param string $name Company name.
	 * @return string
	 */
	public static function initial( $name ) {
		$name = trim( (string) $name );

		return '' !== $name ? function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) ) : '?';
	}

	/**
	 * Timestamp for a stored datetime, or 0 when empty.
	 *
	 * @param string|null $value MySQL datetime.
	 * @return int
	 */
	private static function timestamp( $value ) {
		if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );

		return $timestamp ? $timestamp : 0;
	}
}
