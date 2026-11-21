<?php
/**
 * ToyyibPay gateway: bill creation, callback handling and activation.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Payments
 */
class MMS_Payments {

	const SETTINGS_KEY = 'payment';

	const ACTION_START    = 'mms_start_payment';
	const ACTION_CALLBACK = 'mms_payment_callback';
	const NONCE_START     = 'mms_start_payment';

	const PROVIDER = 'toyyibpay';

	const KIND_INITIAL = 'initial';
	const KIND_RENEWAL = 'renewal';

	const RENEWAL_WINDOW_DAYS = 30;

	/**
	 * Gateway settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$settings = get_option( 'mms_settings', array() );
		$payment  = isset( $settings[ self::SETTINGS_KEY ] ) && is_array( $settings[ self::SETTINGS_KEY ] )
			? $settings[ self::SETTINGS_KEY ]
			: array();

		return array_merge(
			array(
				'enabled'       => 0,
				'mode'          => 'sandbox',
				'category_code' => '',
				'secret_key'    => '',
			),
			$payment
		);
	}

	/**
	 * Whether online payment is enabled and configured.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::settings();

		return ! empty( $settings['enabled'] )
			&& '' !== trim( (string) $settings['category_code'] )
			&& '' !== trim( (string) $settings['secret_key'] );
	}

	/**
	 * Whether sandbox credentials are in use.
	 *
	 * @return bool
	 */
	public static function is_sandbox() {
		$settings = self::settings();

		return 'live' !== $settings['mode'];
	}

	/**
	 * Gateway base URL (no trailing slash).
	 *
	 * @return string
	 */
	public static function api_base() {
		return self::is_sandbox() ? 'https://dev.toyyibpay.com' : 'https://toyyibpay.com';
	}

	/**
	 * Public payment page for a bill code.
	 *
	 * @param string $billcode Bill code.
	 * @return string
	 */
	public static function bill_url( $billcode ) {
		return self::api_base() . '/' . rawurlencode( $billcode );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_START, array( __CLASS__, 'handle_start' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_return' ) );
	}

	/**
	 * Start (or resume) a payment bill for the current member.
	 *
	 * @return void
	 */
	public static function handle_start() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( MMS_Members::login_url() );
			exit;
		}

		$user        = wp_get_current_user();
		$application = MMS_Members::application_for_user( $user->ID );

		if ( ! $application ) {
			self::redirect_portal( 'error', __( 'No membership application is linked to this account.', 'mms-membership' ) );
		}

		check_admin_referer( self::NONCE_START . '_' . (int) $application['id'] );

		$kind = self::kind_for( $application );
		if ( '' === $kind ) {
			if ( MMS_Status::ACTIVE === $application['status'] ) {
				self::redirect_portal(
					'error',
					sprintf(
						/* translators: %d: number of days */
						__( 'Your membership is active. Renewal becomes available %d days before the expiry date.', 'mms-membership' ),
						self::RENEWAL_WINDOW_DAYS
					)
				);
			}

			self::redirect_portal( 'error', __( 'This application is not awaiting payment.', 'mms-membership' ) );
		}

		if ( ! self::is_enabled() ) {
			self::redirect_portal( 'error', __( 'Online payment is not available yet. Please contact MACM.', 'mms-membership' ) );
		}

		$type = MMS_Membership_Types::get_type( $application['membership_type'] );
		if ( ! $type ) {
			self::redirect_portal( 'error', __( 'The membership type for this application is not configured.', 'mms-membership' ) );
		}

		$meta  = MMS_Applications::get_meta( $application['id'] );
		$bill  = self::create_bill( $application, $meta, $type, $kind );

		if ( is_wp_error( $bill ) ) {
			self::redirect_portal( 'error', $bill->get_error_message() );
		}

		wp_redirect( self::bill_url( $bill['billcode'] ), 302, 'mms-membership' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Create (or reuse) a ToyyibPay bill for an application.
	 *
	 * @param array  $application Application row.
	 * @param array  $meta        Company / PIC row.
	 * @param array  $type        Membership type definition.
	 * @param string $kind        Payment kind (initial or renewal).
	 * @return array<string,mixed>|WP_Error Bill code, URL and payment ID.
	 */
	public static function create_bill( $application, $meta, $type, $kind = '' ) {
		if ( '' === $kind ) {
			$kind = self::kind_for( $application );
		}

		if ( '' === $kind ) {
			return new WP_Error( 'mms_not_payable', __( 'This membership is not eligible for payment.', 'mms-membership' ) );
		}

		$pending = self::latest_pending( $application['id'], $kind );
		if ( $pending ) {
			$created = $pending['created_at'] ? strtotime( $pending['created_at'] ) : 0;
			if ( $created && ( time() - $created ) < 6 * DAY_IN_SECONDS ) {
				return array(
					'billcode'   => $pending['provider_ref'],
					'url'        => self::bill_url( $pending['provider_ref'] ),
					'payment_id' => (int) $pending['id'],
					'reused'     => true,
				);
			}
		}

		$settings    = self::settings();
		$reference   = '' !== $application['reference'] ? $application['reference'] : 'APP-' . (int) $application['id'];
		$invoice_row = self::pending_invoice( $application['id'], $kind, true );
		$amount_rm   = $invoice_row ? (float) $invoice_row['amount'] : round( (float) $type['fee'], 2 );
		$currency    = $invoice_row ? $invoice_row['currency'] : ( isset( $type['currency'] ) ? $type['currency'] : 'MYR' );
		$amount      = round( $amount_rm * 100 );
		$invoice     = $invoice_row ? $invoice_row['invoice_no'] : self::next_invoice_no( $application );

		$payload = array(
			'userSecretKey'           => $settings['secret_key'],
			'categoryCode'            => $settings['category_code'],
			'billName'                => 'MACM Membership Fee',
			'billDescription'         => self::truncate( $type['label'] . ' membership fee', 100 ),
			'billPriceSetting'        => 1,
			'billPayorInfo'           => 1,
			'billAmount'              => $amount,
			'billReturnUrl'           => MMS_Members::portal_url(),
			'billCallbackUrl'         => add_query_arg( 'action', self::ACTION_CALLBACK, admin_url( 'admin-post.php' ) ),
			'billExternalReferenceNo' => $reference,
			'billTo'                  => isset( $meta['pic_name'] ) ? $meta['pic_name'] : '',
			'billEmail'               => isset( $meta['pic_email'] ) ? $meta['pic_email'] : '',
			'billPhone'               => self::phone_digits( isset( $meta['pic_phone'] ) ? $meta['pic_phone'] : '' ),
			'billSplitPayment'        => 0,
			'billPaymentChannel'      => 2,
			'billExpiryDays'          => 7,
		);

		$response = wp_remote_post(
			self::api_base() . '/index.php/api/createBill',
			array(
				'timeout' => 25,
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			MMS_Applications::log(
				$application['id'],
				'payment_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the payment gateway: %s', 'mms-membership' ),
					$response->get_error_message()
				)
			);

			return new WP_Error( 'mms_gateway_unreachable', __( 'The payment gateway could not be reached. Please try again in a moment.', 'mms-membership' ) );
		}

		$body = trim( wp_remote_retrieve_body( $response ) );
		$data = json_decode( $body, true );
		$code = '';

		if ( is_array( $data ) && isset( $data[0]['BillCode'] ) ) {
			$code = (string) $data[0]['BillCode'];
		} elseif ( is_array( $data ) && isset( $data['BillCode'] ) ) {
			$code = (string) $data['BillCode'];
		}

		if ( '' === $code ) {
			$message = '';
			if ( is_array( $data ) && isset( $data['msg'] ) ) {
				$message = (string) $data['msg'];
			} elseif ( '' !== $body && strlen( $body ) < 200 ) {
				$message = $body;
			}

			MMS_Applications::log(
				$application['id'],
				'payment_error',
				sprintf(
					/* translators: %s: gateway response */
					__( 'Bill creation failed: %s', 'mms-membership' ),
					'' !== $message ? $message : __( 'unknown gateway response', 'mms-membership' )
				)
			);

			return new WP_Error( 'mms_gateway_bill_failed', __( 'The payment bill could not be created. Please try again or contact MACM.', 'mms-membership' ) );
		}

		global $wpdb;

		$now = current_time( 'mysql' );

		if ( $invoice_row ) {
			$wpdb->update(
				MMS_DB::table( MMS_DB::PAYMENT ),
				array(
					'provider'     => self::PROVIDER,
					'provider_ref' => $code,
					'updated_at'   => $now,
				),
				array( 'id' => (int) $invoice_row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			$payment_id = (int) $invoice_row['id'];
		} else {
			$wpdb->insert(
				MMS_DB::table( MMS_DB::PAYMENT ),
				array(
					'application_id' => (int) $application['id'],
					'invoice_no'     => $invoice,
					'amount'         => $amount_rm,
					'currency'       => $currency,
					'status'         => 'pending',
					'kind'           => $kind,
					'provider'       => self::PROVIDER,
					'provider_ref'   => $code,
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$payment_id = (int) $wpdb->insert_id;
		}

		MMS_Applications::log(
			$application['id'],
			self::KIND_RENEWAL === $kind ? 'renewal_started' : 'payment_started',
			self::KIND_RENEWAL === $kind
				? sprintf(
					/* translators: 1: invoice number, 2: formatted amount, 3: bill code */
					__( 'Renewal bill %1$s created for %2$s (bill %3$s).', 'mms-membership' ),
					$invoice,
					self::format_amount( $amount_rm, $currency ),
					$code
				)
				: sprintf(
					/* translators: 1: invoice number, 2: formatted amount, 3: bill code */
					__( 'Payment bill %1$s created for %2$s (bill %3$s).', 'mms-membership' ),
					$invoice,
					self::format_amount( $amount_rm, $currency ),
					$code
				),
			get_current_user_id()
		);

		return array(
			'billcode'   => $code,
			'url'        => self::bill_url( $code ),
			'payment_id' => $payment_id,
			'reused'     => false,
		);
	}

	/**
	 * Handle ToyyibPay server-to-server callback.
	 *
	 * @return void
	 */
	public static function handle_callback() {
		$status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$bill    = isset( $_POST['billcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billcode'] ) ) : '';
		$order   = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$refno   = isset( $_POST['refno'] ) ? sanitize_text_field( wp_unslash( $_POST['refno'] ) ) : '';
		$hash    = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';

		$settings = self::settings();
		$expected = md5( $settings['secret_key'] . $status . $order . $refno . 'ok' );

		if ( '' === $bill || '' === $hash || ! hash_equals( $expected, $hash ) ) {
			self::log_invalid_callback( $bill, 'invalid hash' );
			self::respond( 403, 'INVALID HASH' );
		}

		$payment = self::find_by_bill( $bill );
		if ( ! $payment ) {
			self::log_invalid_callback( $bill, 'unknown bill code' );
			self::respond( 404, 'UNKNOWN BILL' );
		}

		$application = MMS_Applications::get( $payment['application_id'] );
		if ( ! $application ) {
			self::respond( 404, 'UNKNOWN APPLICATION' );
		}

		$reference = '' !== $application['reference'] ? $application['reference'] : 'APP-' . (int) $application['id'];
		if ( $order !== $reference ) {
			self::log_invalid_callback( $bill, 'order id mismatch' );
			self::respond( 403, 'ORDER MISMATCH' );
		}

		$raw = wp_json_encode(
			array(
				'status'    => $status,
				'billcode'  => $bill,
				'order_id'  => $order,
				'refno'     => $refno,
				'amount'    => $amount,
				'reason'    => isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '',
				'time'      => isset( $_POST['transaction_time'] ) ? sanitize_text_field( wp_unslash( $_POST['transaction_time'] ) ) : '',
			)
		);

		if ( '1' === $status ) {
			if ( ! self::amount_matches( $amount, $payment['amount'] ) ) {
				self::log_invalid_callback( $bill, 'amount mismatch' );
				self::respond( 403, 'AMOUNT MISMATCH' );
			}

			self::complete_payment( $payment, $refno, $raw );
		} elseif ( '3' === $status ) {
			self::mark_failed( $payment, __( 'Payment failed at the gateway.', 'mms-membership' ), $raw );
		}

		self::respond( 200, 'OK' );
	}

	/**
	 * Verify a payment after the payer returns from ToyyibPay.
	 *
	 * @return void
	 */
	public static function maybe_handle_return() {
		if ( ! is_user_logged_in() || ! is_page( 'member-dashboard' ) ) {
			return;
		}

		$bill   = isset( $_GET['billcode'] ) ? sanitize_text_field( wp_unslash( $_GET['billcode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status_id'] ) ? sanitize_text_field( wp_unslash( $_GET['status_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $bill || '' === $status ) {
			return;
		}

		$user        = wp_get_current_user();
		$application = MMS_Members::application_for_user( $user->ID );
		$payment     = self::find_by_bill( $bill );

		if ( ! $application || ! $payment || (int) $payment['application_id'] !== (int) $application['id'] ) {
			self::redirect_portal( 'error', __( 'That payment does not belong to your membership account.', 'mms-membership' ) );
		}

		if ( 'paid' === $payment['status'] ) {
			self::redirect_portal( 'paid', '' );
		}

		if ( 'failed' === $payment['status'] ) {
			self::redirect_portal( 'failed', '' );
		}

		$state = self::verify_bill( $bill, $payment );

		if ( 'paid' === $state['state'] ) {
			self::redirect_portal( 'paid', '' );
		} elseif ( 'failed' === $state['state'] ) {
			self::redirect_portal( 'failed', '' );
		}

		self::redirect_portal( 'pending', '' );
	}

	/**
	 * Ask the gateway for the authoritative payment state.
	 *
	 * @param string $bill    Bill code.
	 * @param array  $payment Payment row.
	 * @return array<string,mixed> State and raw record.
	 */
	private static function verify_bill( $bill, $payment ) {
		$settings = self::settings();

		$response = wp_remote_post(
			self::api_base() . '/index.php/api/getBillTransactions',
			array(
				'timeout' => 25,
				'body'    => array(
					'billCode'      => $bill,
					'userSecretKey' => $settings['secret_key'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'state' => 'unknown' );
		}

		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array( 'state' => 'pending' );
		}

		foreach ( $rows as $row ) {
			if ( isset( $row['billpaymentStatus'] ) && '1' === (string) $row['billpaymentStatus'] ) {
				self::complete_payment(
					$payment,
					isset( $row['billpaymentInvoiceNo'] ) ? $row['billpaymentInvoiceNo'] : '',
					wp_json_encode( $row )
				);

				return array( 'state' => 'paid', 'row' => $row );
			}
		}

		foreach ( $rows as $row ) {
			if ( isset( $row['billpaymentStatus'] ) && '3' === (string) $row['billpaymentStatus'] ) {
				self::mark_failed( $payment, __( 'Payment was unsuccessful at the gateway.', 'mms-membership' ), wp_json_encode( $row ) );

				return array( 'state' => 'failed', 'row' => $row );
			}
		}

		return array( 'state' => 'pending', 'row' => $rows[0] );
	}

	/**
	 * Mark a bill as paid and activate the membership (idempotent).
	 *
	 * @param array  $payment Payment row.
	 * @param string $refno   Gateway transaction reference.
	 * @param string $raw     Raw gateway payload.
	 * @param string $paid_at Optional payment timestamp (Y-m-d H:i:s).
	 * @return bool True when this call performed the activation.
	 */
	public static function complete_payment( $payment, $refno = '', $raw = '', $paid_at = '' ) {
		global $wpdb;

		if ( 'paid' === $payment['status'] ) {
			return false;
		}

		$now     = current_time( 'mysql' );
		$paid_at = '' !== $paid_at ? $paid_at : $now;
		$table   = MMS_DB::table( MMS_DB::PAYMENT );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'paid', transaction_ref = %s, raw_response = %s, paid_at = %s, updated_at = %s WHERE id = %d AND status <> 'paid'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$refno,
				$raw,
				$paid_at,
				$now,
				(int) $payment['id']
			)
		);

		if ( ! $updated ) {
			return false;
		}

		$application = MMS_Applications::get( $payment['application_id'] );
		if ( ! $application ) {
			return false;
		}

		$kind       = isset( $payment['kind'] ) && '' !== $payment['kind'] ? $payment['kind'] : self::KIND_INITIAL;
		$is_renewal = self::KIND_RENEWAL === $kind || MMS_Status::EXPIRED === $application['status'];

		if ( ! $is_renewal && MMS_Status::ACTIVE === $application['status'] ) {
			return true;
		}

		MMS_Applications::log(
			$application['id'],
			$is_renewal ? 'renewal_payment_received' : 'payment_received',
			$is_renewal
				? sprintf(
					/* translators: 1: invoice number, 2: formatted amount, 3: transaction reference */
					__( 'Renewal payment received for %1$s %2$s (transaction %3$s).', 'mms-membership' ),
					$payment['invoice_no'],
					self::format_amount( $payment['amount'], $payment['currency'] ),
					'' !== $refno ? $refno : '—'
				)
				: sprintf(
					/* translators: 1: invoice number, 2: formatted amount, 3: transaction reference */
					__( 'Payment received for %1$s %2$s (transaction %3$s).', 'mms-membership' ),
					$payment['invoice_no'],
					self::format_amount( $payment['amount'], $payment['currency'] ),
					'' !== $refno ? $refno : '—'
				)
		);

		$type    = MMS_Membership_Types::get_type( $application['membership_type'] );
		$months  = ( $type && ! empty( $type['duration_months'] ) ) ? max( 1, (int) $type['duration_months'] ) : 12;
		$tz      = wp_timezone();
		$start   = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $paid_at, $tz );
		if ( ! $start ) {
			$start = new DateTimeImmutable( 'now', $tz );
		}
		$expires = $start->modify( '+' . $months . ' months' );
		$number  = ! empty( $application['membership_no'] ) ? $application['membership_no'] : self::membership_number( $application );

		if ( $is_renewal && ! empty( $application['expires_at'] ) ) {
			$current_expiry = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $application['expires_at'], $tz );
			if ( $current_expiry instanceof DateTimeImmutable && $current_expiry > $start ) {
				$start   = $current_expiry;
				$expires = $start->modify( '+' . $months . ' months' );
			}
		}

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'status'        => MMS_Status::ACTIVE,
				'membership_no' => $number,
				'started_at'    => $start->format( 'Y-m-d H:i:s' ),
				'expires_at'    => $expires->format( 'Y-m-d H:i:s' ),
				'updated_at'    => $now,
			),
			array( 'id' => (int) $application['id'] ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		MMS_Certificate::issue_number(
			array_merge(
				$application,
				array(
					'membership_no' => $number,
				)
			)
		);

		$channel = ! empty( $payment['channel'] ) ? $payment['channel'] : '';
		if ( '' === $channel && self::PROVIDER === $payment['provider'] ) {
			$channel = 'ToyyibPay';
		}

		$wpdb->update(
			$table,
			array(
				'channel'      => $channel,
				'period_start' => $start->format( 'Y-m-d H:i:s' ),
				'period_end'   => $expires->format( 'Y-m-d H:i:s' ),
				'updated_at'   => $now,
			),
			array( 'id' => (int) $payment['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$payment = array_merge(
			$payment,
			array(
				'channel'      => $channel,
				'period_start' => $start->format( 'Y-m-d H:i:s' ),
				'period_end'   => $expires->format( 'Y-m-d H:i:s' ),
				'paid_at'      => $paid_at,
				'status'       => 'paid',
			)
		);

		$application = MMS_Applications::get( $application['id'] ) ?: $application;

		MMS_Applications::log(
			$application['id'],
			$is_renewal ? 'membership_renewed' : 'membership_activated',
			$is_renewal
				? sprintf(
					/* translators: 1: membership number, 2: start date, 3: expiry date, 4: invoice number */
					__( 'Membership renewed as %1$s. New term %2$s to %3$s (invoice %4$s).', 'mms-membership' ),
					$number,
					$start->format( 'Y-m-d' ),
					$expires->format( 'Y-m-d' ),
					$payment['invoice_no']
				)
				: sprintf(
					/* translators: 1: membership number, 2: start date, 3: expiry date */
					__( 'Membership activated as %1$s (%2$s to %3$s).', 'mms-membership' ),
					$number,
					$start->format( 'Y-m-d' ),
					$expires->format( 'Y-m-d' )
				)
		);

		self::send_receipt( $application, $type, $payment, $number, $start, $expires, $is_renewal );

		return true;
	}

	/**
	 * Mark a bill as failed without touching the application status.
	 *
	 * @param array  $payment Payment row.
	 * @param string $reason  Reason.
	 * @param string $raw     Raw gateway payload.
	 * @return void
	 */
	public static function mark_failed( $payment, $reason, $raw = '' ) {
		global $wpdb;

		if ( 'paid' === $payment['status'] || 'failed' === $payment['status'] ) {
			return;
		}

		$now     = current_time( 'mysql' );
		$updated = $wpdb->update(
			MMS_DB::table( MMS_DB::PAYMENT ),
			array(
				'status'       => 'failed',
				'raw_response' => $raw,
				'updated_at'   => $now,
			),
			array( 'id' => (int) $payment['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( $updated ) {
			MMS_Applications::log(
				$payment['application_id'],
				'payment_failed',
				sprintf(
					/* translators: 1: invoice number, 2: reason */
					__( 'Payment %1$s did not complete: %2$s', 'mms-membership' ),
					$payment['invoice_no'],
					$reason
				)
			);
		}
	}

	/**
	 * Payment row by ToyyibPay bill code.
	 *
	 * @param string $bill Bill code.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_bill( $bill ) {
		global $wpdb;

		if ( '' === $bill ) {
			return null;
		}

		$table = MMS_DB::table( MMS_DB::PAYMENT );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE provider_ref = %s ORDER BY id DESC LIMIT 1", $bill ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Payment row by ID.
	 *
	 * @param int $payment_id Payment ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $payment_id ) {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::PAYMENT );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $payment_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Latest payment for an application.
	 *
	 * @param int $application_id Application ID.
	 * @return array<string,mixed>|null
	 */
	public static function latest( $application_id ) {
		$rows = self::for_application( $application_id );

		return $rows ? $rows[0] : null;
	}

	/**
	 * Latest pending bill for an application.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $kind           Optional payment kind filter.
	 * @return array<string,mixed>|null
	 */
	public static function latest_pending( $application_id, $kind = '' ) {
		foreach ( self::for_application( $application_id ) as $row ) {
			if ( 'pending' !== $row['status'] || '' === $row['provider_ref'] ) {
				continue;
			}

			$row_kind = isset( $row['kind'] ) && '' !== $row['kind'] ? $row['kind'] : self::KIND_INITIAL;
			if ( '' !== $kind && $kind !== $row_kind ) {
				continue;
			}

			return $row;
		}

		return null;
	}

	/**
	 * Payment kind for an application, or an empty string when payment is not open.
	 *
	 * @param array $application Application row.
	 * @return string
	 */
	public static function kind_for( $application ) {
		if ( ! $application ) {
			return '';
		}

		$status = isset( $application['status'] ) ? $application['status'] : '';

		if ( MMS_Status::APPROVED_UNPAID === $status ) {
			return self::KIND_INITIAL;
		}

		if ( MMS_Status::EXPIRED === $status ) {
			return self::KIND_RENEWAL;
		}

		if ( MMS_Status::ACTIVE !== $status || empty( $application['expires_at'] ) ) {
			return '';
		}

		$tz     = wp_timezone();
		$expiry = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $application['expires_at'], $tz );
		if ( ! $expiry ) {
			return '';
		}

		$now = new DateTimeImmutable( 'now', $tz );

		if ( $expiry <= $now || $expiry <= $now->modify( '+' . self::RENEWAL_WINDOW_DAYS . ' days' ) ) {
			return self::KIND_RENEWAL;
		}

		return '';
	}

	/**
	 * All payments for an application, newest first.
	 *
	 * @param int $application_id Application ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_application( $application_id ) {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::PAYMENT );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d ORDER BY id DESC", (int) $application_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Payment status labels.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'pending' => __( 'Pending', 'mms-membership' ),
			'paid'    => __( 'Paid', 'mms-membership' ),
			'failed'  => __( 'Failed', 'mms-membership' ),
		);
	}

	/**
	 * Payment kind labels.
	 *
	 * @return array<string,string>
	 */
	public static function kinds() {
		return array(
			self::KIND_INITIAL => __( 'Initial', 'mms-membership' ),
			self::KIND_RENEWAL => __( 'Renewal', 'mms-membership' ),
		);
	}

	/**
	 * Label for a payment kind.
	 *
	 * @param string $kind Payment kind.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		$kinds = self::kinds();

		return isset( $kinds[ $kind ] ) ? $kinds[ $kind ] : $kinds[ self::KIND_INITIAL ];
	}

	/**
	 * Format an amount with its currency.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function format_amount( $amount, $currency = 'MYR' ) {
		$formatted = number_format_i18n( (float) $amount, 2 );

		return 'MYR' === strtoupper( (string) $currency ) ? 'RM ' . $formatted : strtoupper( (string) $currency ) . ' ' . $formatted;
	}

	/**
	 * Membership number for an application (generated once).
	 *
	 * @param array $application Application row.
	 * @return string
	 */
	public static function membership_number( $application ) {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::APPLICATION );
		$id    = isset( $application['id'] ) ? (int) $application['id'] : 0;

		for ( $attempt = 0; $attempt < 30; $attempt++ ) {
			$candidate = sprintf( '%04d', wp_rand( 1000, 9999 ) );
			$existing  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE membership_no = %s", $candidate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( ! $existing || (int) $existing === $id ) {
				return $candidate;
			}
		}

		return sprintf( '%04d', 1000 + ( $id % 9000 ) );
	}

	/**
	 * Latest pending invoice for an application and kind.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $kind           Optional payment kind filter.
	 * @param bool   $unbilled_only  Only rows that have not been sent to a gateway.
	 * @return array<string,mixed>|null
	 */
	public static function pending_invoice( $application_id, $kind = '', $unbilled_only = false ) {
		foreach ( self::for_application( $application_id ) as $row ) {
			if ( 'pending' !== $row['status'] ) {
				continue;
			}

			if ( $unbilled_only && '' !== $row['provider_ref'] ) {
				continue;
			}

			$row_kind = isset( $row['kind'] ) && '' !== $row['kind'] ? $row['kind'] : self::KIND_INITIAL;
			if ( '' !== $kind && $kind !== $row_kind ) {
				continue;
			}

			return $row;
		}

		return null;
	}

	/**
	 * Get or create the pending invoice for a payment kind.
	 *
	 * The invoice row exists before a gateway bill is created so members and
	 * administrators can download the invoice as soon as approval is granted.
	 *
	 * @param array      $application Application row.
	 * @param array|null $meta        Application meta (unused, kept for symmetry).
	 * @param array|null $type        Membership type definition.
	 * @param string     $kind        Payment kind.
	 * @return array<string,mixed>|null
	 */
	public static function ensure_invoice( $application, $meta = null, $type = null, $kind = '' ) {
		global $wpdb;

		if ( ! $application || empty( $application['id'] ) ) {
			return null;
		}

		if ( '' === $kind ) {
			$kind = self::kind_for( $application );
		}

		if ( '' === $kind ) {
			return null;
		}

		$existing = self::pending_invoice( $application['id'], $kind );
		if ( $existing ) {
			return $existing;
		}

		if ( null === $type ) {
			$type = MMS_Membership_Types::get_type( $application['membership_type'] );
		}

		if ( ! $type ) {
			return null;
		}

		$amount   = round( (float) $type['fee'], 2 );
		$currency = isset( $type['currency'] ) ? $type['currency'] : 'MYR';
		$now      = current_time( 'mysql' );
		$invoice  = self::next_invoice_no( $application );

		$inserted = $wpdb->insert(
			MMS_DB::table( MMS_DB::PAYMENT ),
			array(
				'application_id' => (int) $application['id'],
				'invoice_no'     => $invoice,
				'amount'         => $amount,
				'currency'       => $currency,
				'status'         => 'pending',
				'kind'           => $kind,
				'provider'       => 'invoice',
				'provider_ref'   => '',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return null;
		}

		$payment_id = (int) $wpdb->insert_id;

		MMS_Applications::log(
			$application['id'],
			'invoice_created',
			sprintf(
				/* translators: 1: invoice number, 2: formatted amount */
				__( 'Invoice %1$s created for %2$s.', 'mms-membership' ),
				$invoice,
				self::format_amount( $amount, $currency )
			),
			get_current_user_id()
		);

		return self::get( $payment_id );
	}

	/**
	 * Next invoice number for an application.
	 *
	 * @param array $application Application row.
	 * @return string
	 */
	private static function next_invoice_no( $application ) {
		global $wpdb;

		$table = MMS_DB::table( MMS_DB::PAYMENT );
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE application_id = %d", (int) $application['id'] )
		);
		$base  = '' !== $application['reference'] ? $application['reference'] : 'APP-' . (int) $application['id'];

		return $base . '-' . ( $count + 1 );
	}

	/**
	 * Compare a gateway amount (RM or cents) with the expected bill amount.
	 *
	 * @param string $amount Gateway amount.
	 * @param float  $expected Expected amount in RM.
	 * @return bool
	 */
	private static function amount_matches( $amount, $expected ) {
		$received = (float) $amount;
		$expected = (float) $expected;

		return abs( $received - $expected ) < 0.01 || abs( ( $received / 100 ) - $expected ) < 0.01;
	}

	/**
	 * Keep only digits from a phone number.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	private static function phone_digits( $phone ) {
		$digits = preg_replace( '/[^0-9]/', '', (string) $phone );

		return '' !== $digits ? $digits : '0123456789';
	}

	/**
	 * Truncate a string for gateway limits.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * Send the payment receipt email.
	 *
	 * @param array             $application Application row.
	 * @param array|null        $type        Membership type.
	 * @param array             $payment     Payment row.
	 * @param string            $number      Membership number.
	 * @param DateTimeImmutable $start       Start date.
	 * @param DateTimeImmutable $expires     Expiry date.
	 * @param bool              $is_renewal  Whether this is a renewal receipt.
	 * @return void
	 */
	private static function send_receipt( $application, $type, $payment, $number, $start, $expires, $is_renewal = false ) {
		$meta  = MMS_Applications::get_meta( $application['id'] );
		$email = isset( $meta['pic_email'] ) ? $meta['pic_email'] : '';
		$ref   = '' !== $application['reference'] ? $application['reference'] : '#' . (int) $application['id'];

		if ( ! $email || ! is_email( $email ) ) {
			return;
		}

		$currency = isset( $payment['currency'] ) ? $payment['currency'] : 'MYR';
		$rows     = array(
			__( 'Membership number', 'mms-membership' ) => $number,
			__( 'Membership type', 'mms-membership' )   => $type ? $type['label'] : $application['membership_type'],
			__( 'Amount paid', 'mms-membership' )       => self::format_amount( $payment['amount'], $currency ),
			__( 'Invoice', 'mms-membership' )           => $payment['invoice_no'],
			__( 'Valid from', 'mms-membership' )        => $start->format( 'j F Y' ),
			__( 'Valid until', 'mms-membership' )       => $expires->format( 'j F Y' ),
		);

		$table = '';
		foreach ( $rows as $label => $value ) {
			$table .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e5e5;color:#50575e;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:8px 12px;border-bottom:1px solid #e5e5e5;font-weight:600;">' . esc_html( $value ) . '</td></tr>';
		}

		$context = array(
			'name'            => isset( $meta['pic_name'] ) ? $meta['pic_name'] : '',
			'company_name'    => isset( $meta['company_name'] ) ? $meta['company_name'] : '',
			'reference'       => $ref,
			'membership_type' => $type ? $type['label'] : $application['membership_type'],
			'amount'          => self::format_amount( $payment['amount'], $currency ),
			'invoice_no'      => $payment['invoice_no'],
			'membership_no'   => $number,
			'start_date'      => $start->format( 'j F Y' ),
			'expiry_date'     => $expires->format( 'j F Y' ),
		);

		$body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#1d2327;">'
			. '<h2 style="color:#0f4c5c;">' . esc_html( $is_renewal ? __( 'Membership renewed', 'mms-membership' ) : __( 'Payment received', 'mms-membership' ) ) . '</h2>'
			. '<div style="margin:0 0 16px;font-size:15px;line-height:1.55;">' . MMS_Emails::body( 'payment', $context ) . '</div>'
			. '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;">' . $table . '</table>'
			. '<p style="font-size:13px;color:#646970;">' . sprintf(
				/* translators: %s: application reference */
				esc_html__( 'Reference: %s', 'mms-membership' ),
				esc_html( $ref )
			) . '</p>'
			. '<p>' . esc_html__( 'Your receipt and membership certificate are attached to this email. You can also download them at any time from your member dashboard.', 'mms-membership' ) . '</p>'
			. '<p><a href="' . esc_url( MMS_Members::portal_url() ) . '" style="display:inline-block;padding:10px 20px;background:#0f4c5c;color:#fff;text-decoration:none;border-radius:4px;">' . esc_html__( 'Open member dashboard', 'mms-membership' ) . '</a></p>'
			. '</div>';

		$attachments = array();
		$certificate = '';
		$cert_app    = array_merge(
			$application,
			array(
				'membership_no' => $number,
				'started_at'    => $start->format( 'Y-m-d H:i:s' ),
				'expires_at'    => $expires->format( 'Y-m-d H:i:s' ),
			)
		);
		$pdf = MMS_Certificate::generate( $cert_app, $meta, $type );

		if ( ! is_wp_error( $pdf ) ) {
			$cert_name   = 'MACM-Membership-' . sanitize_file_name( $number ) . '.pdf';
			$certificate = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), $cert_name );
			if ( false !== file_put_contents( $certificate, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$attachments[] = $certificate;
			}
		}

		$receipt = '';
		if ( class_exists( 'MMS_Billing' ) ) {
			$receipt_pdf = MMS_Billing::receipt_pdf( $cert_app, $meta, $type, $payment );

			if ( ! is_wp_error( $receipt_pdf ) ) {
				$receipt_name = 'MACM-Receipt-' . sanitize_file_name( $payment['invoice_no'] ) . '.pdf';
				$receipt      = trailingslashit( get_temp_dir() ) . wp_unique_filename( get_temp_dir(), $receipt_name );
				if ( false !== file_put_contents( $receipt, $receipt_pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					$attachments[] = $receipt;
				}
			}
		}

		$subject = MMS_Emails::subject( 'payment', $context );

		$sent = wp_mail(
			$email,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$attachments
		);

		if ( $certificate && file_exists( $certificate ) ) {
			wp_delete_file( $certificate );
		}

		if ( $receipt && file_exists( $receipt ) ) {
			wp_delete_file( $receipt );
		}

		MMS_Applications::log(
			$application['id'],
			$sent ? 'email_sent' : 'email_failed',
			$sent
				? ( $is_renewal
					? __( 'Renewal receipt sent to the Person In Charge.', 'mms-membership' )
					: __( 'Payment receipt sent to the Person In Charge.', 'mms-membership' ) )
				: __( 'Payment receipt could not be sent — check the site mail configuration.', 'mms-membership' )
		);
	}

	/**
	 * Redirect to the portal with a payment message.
	 *
	 * @param string $code    Message code.
	 * @param string $message Optional error message stored in a transient.
	 * @return void
	 */
	private static function redirect_portal( $code, $message ) {
		if ( '' !== $message ) {
			$key = wp_generate_password( 12, false, false );
			set_transient( 'mms_payment_err_' . $key, $message, HOUR_IN_SECONDS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'mms_payment' => $code,
						'mms_error'   => $key,
					),
					MMS_Members::portal_url()
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( 'mms_payment', $code, MMS_Members::portal_url() ) );
		exit;
	}

	/**
	 * Record an invalid callback for audit purposes.
	 *
	 * @param string $bill   Bill code.
	 * @param string $reason Reason.
	 * @return void
	 */
	private static function log_invalid_callback( $bill, $reason ) {
		$payment = self::find_by_bill( $bill );

		if ( $payment ) {
			MMS_Applications::log(
				$payment['application_id'],
				'payment_callback_rejected',
				sprintf(
					/* translators: %s: reason */
					__( 'Payment callback rejected: %s', 'mms-membership' ),
					$reason
				)
			);
		}
	}

	/**
	 * Send a plain-text gateway response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Body.
	 * @return void
	 */
	private static function respond( $status, $body ) {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( $body );
		exit;
	}
}
