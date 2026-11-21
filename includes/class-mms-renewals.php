<?php
/**
 * Daily expiry checks and renewal reminders.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Renewals
 */
class MMS_Renewals {

	const CRON_HOOK = 'mms_daily_expiry_check';
	const LOCK_KEY  = 'mms_expiry_lock';

	const REMINDER_DAYS = array( 0, 7, 14, 30 );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	/**
	 * Schedule the daily expiry check when it is not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$tz   = wp_timezone();
		$next = new DateTimeImmutable( 'today 02:00', $tz );

		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}

		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	/**
	 * Remove the scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Run reminders, then expire memberships whose end date has passed.
	 *
	 * @return void
	 */
	public static function run() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS );

		if ( class_exists( 'MMS_Install' ) ) {
			MMS_Install::maybe_upgrade();
		}

		self::send_reminders();
		self::expire_memberships();

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Send the 30/14/7/0-day reminders for active memberships.
	 *
	 * @return void
	 */
	private static function send_reminders() {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$until = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+31 days' )->format( 'Y-m-d H:i:s' );
		$table = MMS_DB::table( MMS_DB::APPLICATION );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s ORDER BY expires_at ASC",
				MMS_Status::ACTIVE,
				$now,
				$until
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $application ) {
			$days = self::days_left( $application['expires_at'] );
			if ( null === $days || $days < 0 || $days > 30 ) {
				continue;
			}

			$threshold = self::threshold_for( $days );
			if ( null === $threshold ) {
				continue;
			}

			$action = 'expiry_reminder_' . $threshold;
			if ( self::reminder_logged( $application, $action ) ) {
				continue;
			}

			if ( self::has_open_renewal( $application['id'] ) ) {
				continue;
			}

			self::send_notice( $application, $days, $threshold, false );
		}
	}

	/**
	 * Mark active memberships as expired once their end date has passed.
	 *
	 * @return void
	 */
	private static function expire_memberships() {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::APPLICATION );
		$now   = current_time( 'mysql' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY expires_at ASC",
				MMS_Status::ACTIVE,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $application ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
					MMS_Status::EXPIRED,
					$now,
					(int) $application['id'],
					MMS_Status::ACTIVE
				)
			);

			if ( ! $updated ) {
				continue;
			}

			MMS_Applications::log(
				$application['id'],
				'membership_expired',
				sprintf(
					/* translators: 1: membership number, 2: expiry date */
					__( 'Membership %1$s expired on %2$s.', 'mms-membership' ),
					! empty( $application['membership_no'] ) ? $application['membership_no'] : '—',
					mysql2date( get_option( 'date_format' ), $application['expires_at'] )
				)
			);

			if ( ! self::reminder_logged( $application, 'expiry_reminder_0' ) ) {
				self::send_notice( $application, 0, 0, true );
			}
		}
	}

	/**
	 * Calendar-day difference between today and an expiry date.
	 *
	 * @param string $expires_at MySQL datetime.
	 * @return int|null
	 */
	private static function days_left( $expires_at ) {
		$tz     = wp_timezone();
		$expiry = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $expires_at, $tz );

		if ( ! $expiry ) {
			return null;
		}

		$today = new DateTimeImmutable( 'today', $tz );

		return (int) $today->diff( $expiry->setTime( 0, 0, 0 ) )->format( '%r%a' );
	}

	/**
	 * Reminder bucket for a number of days left.
	 *
	 * @param int $days Days until expiry.
	 * @return int|null
	 */
	private static function threshold_for( $days ) {
		foreach ( self::REMINDER_DAYS as $threshold ) {
			if ( $days <= $threshold ) {
				return $threshold;
			}
		}

		return null;
	}

	/**
	 * Whether a reminder was already sent during the current membership term.
	 *
	 * @param array  $application Application row.
	 * @param string $action      Activity action.
	 * @return bool
	 */
	private static function reminder_logged( $application, $action ) {
		global $wpdb;

		$since = ! empty( $application['started_at'] ) ? $application['started_at'] : $application['created_at'];
		if ( ! $since ) {
			return false;
		}

		$table = MMS_DB::table( MMS_DB::ACTIVITY_LOG );
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE application_id = %d AND action = %s AND created_at >= %s LIMIT 1",
				(int) $application['id'],
				$action,
				$since
			)
		);

		return ! empty( $found );
	}

	/**
	 * Whether a renewal bill is still open.
	 *
	 * @param int $application_id Application ID.
	 * @return bool
	 */
	private static function has_open_renewal( $application_id ) {
		global $wpdb;

		$table  = MMS_DB::table( MMS_DB::PAYMENT );
		$cutoff = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
		$found  = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE application_id = %d AND kind = 'renewal' AND status = 'pending' AND provider_ref <> '' AND created_at >= %s LIMIT 1",
				(int) $application_id,
				$cutoff
			)
		);

		return ! empty( $found );
	}

	/**
	 * Email a renewal reminder or an expiry notice.
	 *
	 * @param array $application Application row.
	 * @param int   $days        Days until expiry (0 once expired).
	 * @param int   $threshold   Reminder bucket.
	 * @param bool  $expired     Whether the membership has just expired.
	 * @return bool
	 */
	private static function send_notice( $application, $days, $threshold, $expired ) {
		$meta  = MMS_Applications::get_meta( $application['id'] );
		$email = self::contact_email( $meta );
		$no    = ! empty( $application['membership_no'] ) ? $application['membership_no'] : '';

		if ( ! $email ) {
			return false;
		}

		$company    = isset( $meta['company_name'] ) ? $meta['company_name'] : '';
		$expiry     = mysql2date( get_option( 'date_format' ), $application['expires_at'] );
		$portal     = MMS_Members::portal_url();
		$renew_link = '<a href="' . esc_url( $portal ) . '" style="display:inline-block;padding:10px 20px;background:#0f4c5c;color:#fff;text-decoration:none;border-radius:4px;">' . esc_html__( 'Renew membership', 'mms-membership' ) . '</a>';

		if ( $expired ) {
			$subject = sprintf(
				/* translators: %s: membership number */
				__( 'MACM membership expired — %s', 'mms-membership' ),
				$no
			);
			$heading = __( 'Your membership has expired', 'mms-membership' );
			$intro   = sprintf(
				/* translators: 1: company name, 2: expiry date */
				esc_html__( 'The MACM membership for %1$s expired on %2$s. Renew now to restore your membership benefits and receive an updated certificate.', 'mms-membership' ),
				esc_html( $company ),
				esc_html( $expiry )
			);
		} elseif ( 0 === $days ) {
			$subject = sprintf(
				/* translators: %s: membership number */
				__( 'MACM membership expires today — %s', 'mms-membership' ),
				$no
			);
			$heading = __( 'Your membership expires today', 'mms-membership' );
			$intro   = sprintf(
				/* translators: 1: company name, 2: expiry date */
				esc_html__( 'The MACM membership for %1$s expires today (%2$s). Renew now to avoid any interruption.', 'mms-membership' ),
				esc_html( $company ),
				esc_html( $expiry )
			);
		} else {
			$subject = sprintf(
				/* translators: 1: membership number, 2: days */
				__( 'MACM membership expires in %2$d days — %1$s', 'mms-membership' ),
				$no,
				(int) $days
			);
			$heading = __( 'Membership renewal reminder', 'mms-membership' );
			$intro   = sprintf(
				/* translators: 1: company name, 2: days, 3: expiry date */
				esc_html__( 'The MACM membership for %1$s expires in %2$d days, on %3$s. Renew before the expiry date to keep your membership active.', 'mms-membership' ),
				esc_html( $company ),
				(int) $days,
				esc_html( $expiry )
			);
		}

		$type       = MMS_Membership_Types::get_type( $application['membership_type'] );
		$type_label = $type ? $type['label'] : $application['membership_type'];

		$rows = array(
			__( 'Membership number', 'mms-membership' ) => $no,
			__( 'Membership type', 'mms-membership' )   => $type_label,
			__( 'Expiry date', 'mms-membership' )       => $expiry,
		);

		$table = '';
		foreach ( $rows as $label => $value ) {
			$table .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e5e5;color:#50575e;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #e5e5e5;font-weight:600;">' . esc_html( $value ) . '</td></tr>';
		}

		$body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1d2327;">'
			. '<h2 style="color:#0f4c5c;">' . esc_html( $heading ) . '</h2>'
			. '<p>' . $intro . '</p>'
			. '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;">' . $table . '</table>'
			. '<p>' . $renew_link . '</p>'
			. '<p style="font-size:13px;color:#646970;">' . esc_html__( 'You can renew from the member dashboard. If you have already renewed, please ignore this message.', 'mms-membership' ) . '</p>'
			. '</div>';

		$sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		$log  = 'expiry_reminder_' . (int) $threshold;

		if ( $sent ) {
			MMS_Applications::log(
				$application['id'],
				$log,
				$expired
					? sprintf(
						/* translators: %s: recipient email */
						__( 'Expiry notice sent to %s.', 'mms-membership' ),
						$email
					)
					: sprintf(
						/* translators: 1: days, 2: recipient email */
						__( '%1$d-day renewal reminder sent to %2$s.', 'mms-membership' ),
						(int) $days,
						$email
					)
			);
		} else {
			MMS_Applications::log(
				$application['id'],
				'email_failed',
				__( 'A renewal reminder could not be sent — check the site mail configuration.', 'mms-membership' )
			);
		}

		return $sent;
	}

	/**
	 * Prefer the Person In Charge email, falling back to the company email.
	 *
	 * @param array $meta Application meta row.
	 * @return string
	 */
	private static function contact_email( $meta ) {
		foreach ( array( 'pic_email', 'company_email' ) as $key ) {
			$email = isset( $meta[ $key ] ) ? trim( (string) $meta[ $key ] ) : '';
			if ( '' !== $email && is_email( $email ) ) {
				return $email;
			}
		}

		return '';
	}
}
