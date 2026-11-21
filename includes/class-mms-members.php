<?php
/**
 * Member accounts: WordPress user creation and one-time set-password links.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Members
 */
class MMS_Members {

	const ROLE = 'mms_member';

	const META_APPLICATION      = 'mms_application_id';
	const META_SETPW_HASH       = '_mms_setpw_hash';
	const META_SETPW_EXPIRES    = '_mms_setpw_expires';

	/**
	 * Set-password links are valid for 72 hours.
	 */
	const SETPW_TTL = 259200;

	/**
	 * Create (or reuse) the member account for an approved application.
	 *
	 * @param array $application Application row.
	 * @param array $meta Company / PIC meta row.
	 * @return array<string,mixed>|WP_Error Array with user_id and created flag.
	 */
	public static function create_from_application( $application, $meta ) {
		$email = isset( $meta['pic_email'] ) ? sanitize_email( $meta['pic_email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mms_invalid_email', __( 'The Person In Charge email address is not valid.', 'mms-membership' ) );
		}

		$created  = false;
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof WP_User ) {
			$user_id = (int) $existing->ID;
		} else {
			$user_id = self::insert_user( $email, $meta );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$created = true;
		}

		update_user_meta( $user_id, self::META_APPLICATION, (int) $application['id'] );

		if ( class_exists( 'MMS_Applications' ) ) {
			MMS_Applications::link_member( (int) $application['id'], $user_id );
		}

		return array(
			'user_id' => $user_id,
			'created' => $created,
		);
	}

	/**
	 * Insert a new WordPress user with the member role.
	 *
	 * @param string $email Account email.
	 * @param array  $meta Applicant meta.
	 * @return int|WP_Error User ID.
	 */
	private static function insert_user( $email, $meta ) {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( (string) $local, true );

		if ( '' === $base ) {
			$base = 'member';
		}

		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			++$i;
			$login = $base . $i;
		}

		$name = '';
		if ( ! empty( $meta['pic_name'] ) ) {
			$name = $meta['pic_name'];
		} elseif ( ! empty( $meta['company_name'] ) ) {
			$name = $meta['company_name'];
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => '' !== $name ? $name : $login,
				'role'         => self::ROLE,
			)
		);

		return is_wp_error( $user_id ) ? $user_id : (int) $user_id;
	}

	/**
	 * Issue a new one-time set-password token (replaces any previous one).
	 *
	 * @param int $user_id User ID.
	 * @return string Plain token for the URL.
	 */
	public static function issue_set_password_token( $user_id ) {
		$token = bin2hex( random_bytes( 24 ) );

		update_user_meta( $user_id, self::META_SETPW_HASH, self::hash_token( $token ) );
		update_user_meta( $user_id, self::META_SETPW_EXPIRES, time() + self::SETPW_TTL );

		return $token;
	}

	/**
	 * Set-password URL for a token.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function set_password_url( $user_id, $token ) {
		$user = get_userdata( $user_id );
		$page = get_page_by_path( 'member-set-password' );

		$base = ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : wp_login_url();

		return add_query_arg(
			array(
				'mms_key'   => rawurlencode( $token ),
				'mms_login' => $user ? rawurlencode( $user->user_login ) : '',
			),
			$base
		);
	}

	/**
	 * Verify a set-password link.
	 *
	 * @param string $login User login.
	 * @param string $key Plain token.
	 * @return WP_User|WP_Error
	 */
	public static function verify_link( $login, $key ) {
		$login = trim( (string) $login );
		$key   = preg_replace( '/[^a-f0-9]/i', '', (string) $key );

		if ( '' === $login || strlen( $key ) < 32 ) {
			return new WP_Error( 'mms_invalid_key', __( 'This link is not valid.', 'mms-membership' ) );
		}

		$user = get_user_by( 'login', $login );
		if ( ! $user ) {
			return new WP_Error( 'mms_invalid_key', __( 'This link is not valid.', 'mms-membership' ) );
		}

		$hash    = (string) get_user_meta( $user->ID, self::META_SETPW_HASH, true );
		$expires = (int) get_user_meta( $user->ID, self::META_SETPW_EXPIRES, true );

		if ( '' === $hash || ! $expires || ! hash_equals( $hash, self::hash_token( $key ) ) ) {
			return new WP_Error( 'mms_invalid_key', __( 'This link is not valid. Please use the link from the most recent email we sent you.', 'mms-membership' ) );
		}

		if ( time() > $expires ) {
			return new WP_Error( 'mms_expired_key', __( 'This link has expired. Please contact MACM for a new link.', 'mms-membership' ) );
		}

		return $user;
	}

	/**
	 * Set a new password and burn the token.
	 *
	 * @param WP_User $user User.
	 * @param string  $password New password (already validated).
	 * @return void
	 */
	public static function set_password( $user, $password ) {
		wp_set_password( $password, $user->ID );
		self::clear_token( $user->ID );

		$application_id = (int) get_user_meta( $user->ID, self::META_APPLICATION, true );
		if ( $application_id ) {
			MMS_Applications::log(
				$application_id,
				'password_set',
				sprintf(
					/* translators: %s: user login */
					__( 'Member set their password (%s).', 'mms-membership' ),
					$user->user_login
				),
				$user->ID
			);
		}
	}

	/**
	 * Remove any outstanding set-password token.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear_token( $user_id ) {
		delete_user_meta( $user_id, self::META_SETPW_HASH );
		delete_user_meta( $user_id, self::META_SETPW_EXPIRES );
	}

	/**
	 * Member login page URL (falls back to wp-login.php).
	 *
	 * @return string
	 */
	public static function login_url() {
		$page = get_page_by_path( 'member-login' );

		if ( $page && 'publish' === $page->post_status ) {
			return get_permalink( $page );
		}

		return wp_login_url();
	}

	/**
	 * Member portal URL (falls back to the site home).
	 *
	 * @return string
	 */
	public static function portal_url() {
		$page = get_page_by_path( 'member-dashboard' );

		return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : home_url( '/' );
	}

	/**
	 * Application linked to a member account.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function application_for_user( $user_id ) {
		$application = MMS_Applications::get_by_member( $user_id );

		if ( $application ) {
			return $application;
		}

		$application_id = (int) get_user_meta( $user_id, self::META_APPLICATION, true );
		if ( ! $application_id ) {
			return null;
		}

		$application = MMS_Applications::get( $application_id );
		if ( ! $application ) {
			return null;
		}

		if ( ! empty( $application['member_user_id'] ) && (int) $application['member_user_id'] !== (int) $user_id ) {
			return null;
		}

		return $application;
	}

	/**
	 * Register member-area hooks (admin bar, wp-admin block, login redirect).
	 *
	 * @return void
	 */
	public static function init_hooks() {
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ), 20 );
		// Priority 1: must fire before WooCommerce's prevent_admin_access (priority 10),
		// which would otherwise send members to the shop account page first.
		add_action( 'admin_init', array( __CLASS__, 'block_wp_admin' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 20, 3 );
	}

	/**
	 * Hide the admin bar for member accounts.
	 *
	 * @param bool $show Whether to show the admin bar.
	 * @return bool
	 */
	public static function filter_admin_bar( $show ) {
		$user = wp_get_current_user();

		if ( $user && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'mms_review_application' ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Block wp-admin for member accounts.
	 *
	 * @return void
	 */
	public static function block_wp_admin() {
		if ( ! is_user_logged_in() || wp_doing_ajax() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! in_array( self::ROLE, (array) $user->roles, true ) || user_can( $user, 'mms_review_application' ) ) {
			return;
		}

		global $pagenow;

		// admin-post.php hosts the front-end form handlers used by the member area.
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}

		wp_safe_redirect( self::portal_url() );
		exit;
	}

	/**
	 * Send members to the portal after logging in.
	 *
	 * @param string        $redirect_to Requested redirect URL.
	 * @param string        $requested   Requested redirect (raw).
	 * @param WP_User|WP_Error $user User or error.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'mms_review_application' ) ) {
			return self::portal_url();
		}

		return $redirect_to;
	}

	/**
	 * HMAC token hash for storage.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	private static function hash_token( $token ) {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
