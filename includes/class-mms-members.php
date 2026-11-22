<?php
/**
 * Member accounts: WordPress user creation, admin password resets and one-time set-password links.
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

	const META_APPLICATION        = 'mms_application_id';
	const META_ACTIVE_APPLICATION = '_mms_active_application';
	const META_SETPW_HASH         = '_mms_setpw_hash';
	const META_SETPW_EXPIRES      = '_mms_setpw_expires';

	/**
	 * Set-password links are valid for 72 hours.
	 */
	const SETPW_TTL = 259200;

	/**
	 * Create (or reuse) the member account for an approved application.
	 *
	 * @param array $application Application row.
	 * @param array $meta Company / PIC meta row.
	 * @param array $credentials Optional `user_login` and `user_pass` for manual registrations.
	 * @return array<string,mixed>|WP_Error Array with user_id, created flag and login.
	 */
	public static function create_from_application( $application, $meta, $credentials = array() ) {
		$email = isset( $meta['pic_email'] ) ? sanitize_email( $meta['pic_email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'mms_invalid_email', __( 'The Person In Charge email address is not valid.', 'mms-membership' ) );
		}

		$login    = isset( $credentials['user_login'] ) ? self::normalize_login( $credentials['user_login'] ) : '';
		$password = isset( $credentials['user_pass'] ) ? (string) $credentials['user_pass'] : '';

		$created  = false;
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof WP_User ) {
			if ( '' !== $login || '' !== $password ) {
				return new WP_Error(
					'mms_account_exists',
					sprintf(
						/* translators: %s: username */
						__( 'A member account already exists for this email address (username: %s).', 'mms-membership' ),
						$existing->user_login
					)
				);
			}

			$user_id = (int) $existing->ID;
		} else {
			$user_id = self::insert_user( $email, $meta, $login, $password );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$created = true;
		}

		update_user_meta( $user_id, self::META_APPLICATION, (int) $application['id'] );

		if ( class_exists( 'MMS_Applications' ) ) {
			MMS_Applications::link_member( (int) $application['id'], $user_id );
		}

		$user = get_userdata( $user_id );

		return array(
			'user_id' => $user_id,
			'created' => $created,
			'login'   => $user instanceof WP_User ? $user->user_login : $login,
		);
	}

	/**
	 * Insert a new WordPress user with the member role.
	 *
	 * @param string $email    Account email.
	 * @param array  $meta     Applicant meta.
	 * @param string $login    Requested username (blank to derive one).
	 * @param string $password Requested password (blank to generate one).
	 * @return int|WP_Error User ID.
	 */
	private static function insert_user( $email, $meta, $login = '', $password = '' ) {
		$login = '' !== $login ? self::normalize_login( $login ) : self::suggest_login( $email );

		if ( '' === $login ) {
			return new WP_Error( 'mms_invalid_username', __( 'The username is not valid.', 'mms-membership' ) );
		}

		if ( username_exists( $login ) ) {
			return new WP_Error( 'mms_username_taken', __( 'That username is already taken.', 'mms-membership' ) );
		}

		if ( '' === $password ) {
			$password = wp_generate_password( 32, true, true );
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
				'user_pass'    => $password,
				'display_name' => '' !== $name ? $name : $login,
				'role'         => self::ROLE,
			)
		);

		return is_wp_error( $user_id ) ? $user_id : (int) $user_id;
	}

	/**
	 * Normalise a user-entered username (WordPress rules, max 60 characters).
	 *
	 * @param string $login Raw username.
	 * @return string
	 */
	public static function normalize_login( $login ) {
		$login = sanitize_user( trim( (string) $login ), true );

		return substr( $login, 0, 60 );
	}

	/**
	 * Suggest an available username from an email address.
	 *
	 * @param string $email Account email.
	 * @return string
	 */
	public static function suggest_login( $email ) {
		$local = strstr( (string) $email, '@', true );
		$base  = self::normalize_login( $local );

		if ( '' === $base ) {
			$base = 'member';
		}

		$login = $base;
		$i     = 1;

		while ( username_exists( $login ) ) {
			++$i;
			$suffix = (string) $i;
			$login  = substr( $base, 0, 60 - strlen( $suffix ) ) . $suffix;
		}

		return $login;
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
	 * Random alphanumeric password without ambiguous characters.
	 *
	 * @return string
	 */
	public static function generate_password() {
		$chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
		$length = strlen( $chars );
		$result = '';

		for ( $i = 0; $i < 16; $i++ ) {
			$result .= $chars[ random_int( 0, $length - 1 ) ];
		}

		return $result;
	}

	/**
	 * Replace a member password from the admin, ending any existing sessions.
	 *
	 * @param WP_User $user     User.
	 * @param string  $password New password (already validated).
	 * @return void
	 */
	public static function reset_password( $user, $password ) {
		wp_set_password( $password, $user->ID );
		self::clear_token( $user->ID );

		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}
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
	 * Tell a member that a company was added to their existing account.
	 *
	 * No username or password is sent; the member keeps their current login.
	 *
	 * @param array   $application Application row (status may be overridden by the caller).
	 * @param array   $meta        Company / PIC meta row.
	 * @param WP_User $user        Existing member account.
	 * @return bool Whether the email was accepted for delivery.
	 */
	public static function notify_company_added( $application, $meta, $user ) {
		if ( ! $user instanceof WP_User || '' === (string) $user->user_email || ! is_email( $user->user_email ) ) {
			return false;
		}

		$app_id    = isset( $application['id'] ) ? (int) $application['id'] : 0;
		$reference = isset( $application['reference'] ) && '' !== (string) $application['reference']
			? (string) $application['reference']
			: '#' . $app_id;
		$company   = isset( $meta['company_name'] ) ? (string) $meta['company_name'] : '';
		$name      = '';

		if ( ! empty( $meta['pic_name'] ) ) {
			$name = (string) $meta['pic_name'];
		} elseif ( '' !== $company ) {
			$name = $company;
		} else {
			$name = $user->display_name;
		}

		$type      = isset( $application['membership_type'] ) ? MMS_Membership_Types::get_type( $application['membership_type'] ) : null;
		$type_name = $type ? $type['label'] : '';
		$status    = isset( $application['status'] ) ? (string) $application['status'] : '';

		$context = array(
			'name'            => $name,
			'company_name'    => $company,
			'reference'       => $reference,
			'membership_type' => $type_name,
			'membership_no'   => isset( $application['membership_no'] ) ? (string) $application['membership_no'] : '',
		);

		$subject = MMS_Emails::subject( 'company', $context );
		$message = MMS_Emails::body( 'company', $context );

		$row = '<tr><td style="padding:6px 12px 6px 0;color:#646970;">%1$s</td><td style="padding:6px 0;font-weight:600;">%2$s</td></tr>';

		$details = sprintf( $row, esc_html__( 'Company', 'mms-membership' ), esc_html( '' !== $company ? $company : '—' ) );
		$details .= sprintf( $row, esc_html__( 'Reference number', 'mms-membership' ), esc_html( $reference ) );

		if ( '' !== (string) $context['membership_no'] ) {
			$details .= sprintf( $row, esc_html__( 'Membership number', 'mms-membership' ), esc_html( (string) $context['membership_no'] ) );
		}

		if ( '' !== $status ) {
			$details .= sprintf( $row, esc_html__( 'Status', 'mms-membership' ), esc_html( MMS_Status::label( $status ) ) );
		}

		$portal = self::portal_url();
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$body = '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f0f0f1;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">'
			. '<div style="max-width:600px;margin:0 auto;padding:24px 12px;">'
			. '<div style="background:#ffffff;border-radius:6px;padding:32px;">'
			. '<div style="margin:0 0 16px;font-size:15px;line-height:1.55;">' . $message . '</div>'
			. '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;font-size:15px;">' . $details . '</table>'
			. '<p style="margin:0 0 8px;"><a href="' . esc_url( $portal ) . '" style="display:inline-block;padding:12px 22px;background:#0f4c5c;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:600;">' . esc_html__( 'Open the member portal', 'mms-membership' ) . '</a></p>'
			. '<p style="margin:0 0 20px;font-size:13px;color:#646970;">' . esc_html__( 'Sign in with your existing username and password. If you were not expecting this change, contact MACM.', 'mms-membership' ) . '</p>'
			. '<p style="margin:24px 0 0;font-size:13px;color:#646970;">' . esc_html( $site ) . '</p>'
			. '</div></div></body></html>';

		$sent = wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( $app_id ) {
			MMS_Applications::log(
				$app_id,
				$sent ? 'email_sent' : 'email_failed',
				$sent
					/* translators: %s: recipient email */
					? sprintf( __( 'Company-added email sent to %s.', 'mms-membership' ), $user->user_email )
					/* translators: %s: recipient email */
					: sprintf( __( 'Company-added email could not be sent to %s — check the site mail configuration.', 'mms-membership' ), $user->user_email )
			);
		}

		return (bool) $sent;
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
	 * Public registration page URL (falls back to the site home).
	 *
	 * @return string
	 */
	public static function registration_url() {
		$page = get_page_by_path( 'membership-registration' );

		return ( $page && 'publish' === $page->post_status ) ? get_permalink( $page ) : home_url( '/' );
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
	 * All applications (companies) linked to a member account.
	 *
	 * Includes legacy rows linked only through the old single-application meta.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function applications_for_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! class_exists( 'MMS_Applications' ) ) {
			return array();
		}

		$applications = MMS_Applications::for_member( $user_id );
		$seen         = array();

		foreach ( $applications as $application ) {
			$seen[ (int) $application['id'] ] = true;
		}

		$legacy_id = (int) get_user_meta( $user_id, self::META_APPLICATION, true );
		if ( $legacy_id && ! isset( $seen[ $legacy_id ] ) ) {
			$legacy = MMS_Applications::get( $legacy_id );
			if ( $legacy && self::user_owns_application( $user_id, $legacy ) ) {
				$applications[] = $legacy;
			}
		}

		return $applications;
	}

	/**
	 * Whether an application belongs to a member account.
	 *
	 * @param int   $user_id     User ID.
	 * @param array $application Application row.
	 * @return bool
	 */
	public static function user_owns_application( $user_id, $application ) {
		$user_id = (int) $user_id;

		if ( ! $user_id || ! is_array( $application ) || empty( $application['id'] ) ) {
			return false;
		}

		$member_id = isset( $application['member_user_id'] ) ? (int) $application['member_user_id'] : 0;
		if ( $member_id === $user_id ) {
			return true;
		}

		// Legacy records may not have member_user_id backfilled yet.
		return 0 === $member_id && (int) get_user_meta( $user_id, self::META_APPLICATION, true ) === (int) $application['id'];
	}

	/**
	 * Resolve one application for a member, enforcing ownership.
	 *
	 * With no application ID: the remembered company, otherwise the newest one.
	 *
	 * @param int $user_id        User ID.
	 * @param int $application_id Optional application ID.
	 * @return array<string,mixed>|WP_Error|null Null when the account has no companies.
	 */
	public static function resolve_application( $user_id, $application_id = 0 ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || ! class_exists( 'MMS_Applications' ) ) {
			return null;
		}

		$application_id = (int) $application_id;

		if ( $application_id ) {
			$application = MMS_Applications::get( $application_id );

			if ( ! $application ) {
				return new WP_Error( 'mms_application_not_found', __( 'That company record could not be found.', 'mms-membership' ) );
			}

			if ( ! self::user_owns_application( $user_id, $application ) ) {
				return new WP_Error( 'mms_application_forbidden', __( 'That company is not linked to this account.', 'mms-membership' ) );
			}

			return $application;
		}

		$active_id = self::active_application_id( $user_id );
		if ( $active_id ) {
			$active = MMS_Applications::get( $active_id );
			if ( $active ) {
				return $active;
			}
		}

		$applications = self::applications_for_user( $user_id );

		return $applications ? $applications[0] : null;
	}

	/**
	 * Remembered company for an account, if it still belongs to them.
	 *
	 * @param int $user_id User ID.
	 * @return int Application ID (0 when none).
	 */
	public static function active_application_id( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return 0;
		}

		$application_id = (int) get_user_meta( $user_id, self::META_ACTIVE_APPLICATION, true );
		if ( ! $application_id ) {
			return 0;
		}

		$application = MMS_Applications::get( $application_id );

		if ( ! $application || ! self::user_owns_application( $user_id, $application ) ) {
			delete_user_meta( $user_id, self::META_ACTIVE_APPLICATION );
			return 0;
		}

		return $application_id;
	}

	/**
	 * Remember the company an account last worked with.
	 *
	 * @param int $user_id        User ID.
	 * @param int $application_id Application ID (0 clears the selection).
	 * @return bool
	 */
	public static function set_active_application( $user_id, $application_id ) {
		$user_id        = (int) $user_id;
		$application_id = (int) $application_id;

		if ( ! $user_id ) {
			return false;
		}

		if ( ! $application_id ) {
			delete_user_meta( $user_id, self::META_ACTIVE_APPLICATION );
			return true;
		}

		$application = MMS_Applications::get( $application_id );
		if ( ! $application || ! self::user_owns_application( $user_id, $application ) ) {
			return false;
		}

		update_user_meta( $user_id, self::META_ACTIVE_APPLICATION, $application_id );

		return true;
	}

	/**
	 * Application linked to a member account.
	 *
	 * Kept for existing callers; use resolve_application() when an explicit
	 * company ID must be validated.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	public static function application_for_user( $user_id ) {
		$application = self::resolve_application( $user_id );

		return is_wp_error( $application ) ? null : $application;
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
