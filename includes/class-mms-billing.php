<?php
/**
 * Invoice and receipt PDF documents.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Billing
 */
class MMS_Billing {

	const ACTION_MEMBER_INVOICE = 'mms_member_invoice';
	const ACTION_MEMBER_RECEIPT = 'mms_member_receipt';
	const ACTION_ADMIN_INVOICE  = 'mms_admin_invoice';
	const ACTION_ADMIN_RECEIPT  = 'mms_admin_receipt';

	const NONCE_INVOICE = 'mms_invoice';
	const NONCE_RECEIPT = 'mms_receipt';

	const ORG_NAME    = 'Persatuan Pengurusan Pembersihan Malaysia';
	const ORG_REG     = 'PPM-006-14-07041994';
	const ORG_ADDRESS = '149, Jalan Pahang, Setapak, 53000 Kuala Lumpur.';
	const ORG_PHONE   = '016 - 558 3644';
	const ORG_WEBSITE = 'macm.my';

	/**
	 * Register download handlers.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_MEMBER_INVOICE, array( __CLASS__, 'handle_member_invoice' ) );
		add_action( 'admin_post_' . self::ACTION_MEMBER_RECEIPT, array( __CLASS__, 'handle_member_receipt' ) );
		add_action( 'admin_post_' . self::ACTION_ADMIN_INVOICE, array( __CLASS__, 'handle_admin_invoice' ) );
		add_action( 'admin_post_' . self::ACTION_ADMIN_RECEIPT, array( __CLASS__, 'handle_admin_receipt' ) );
	}

	/**
	 * Member invoice URL.
	 *
	 * @param int $payment_id Payment row id.
	 * @return string
	 */
	public static function invoice_url( $payment_id, $admin = false ) {
		return self::document_url( 'invoice', $payment_id, $admin );
	}

	/**
	 * Member receipt URL.
	 *
	 * @param int $payment_id Payment row id.
	 * @return string
	 */
	public static function receipt_url( $payment_id, $admin = false ) {
		return self::document_url( 'receipt', $payment_id, $admin );
	}

	/**
	 * Build a nonce-protected document URL.
	 *
	 * @param string $type       invoice|receipt.
	 * @param int    $payment_id Payment row id.
	 * @param bool   $admin      Admin context.
	 * @return string
	 */
	private static function document_url( $type, $payment_id, $admin ) {
		$payment_id = absint( $payment_id );
		$action     = $admin
			? ( 'invoice' === $type ? self::ACTION_ADMIN_INVOICE : self::ACTION_ADMIN_RECEIPT )
			: ( 'invoice' === $type ? self::ACTION_MEMBER_INVOICE : self::ACTION_MEMBER_RECEIPT );
		$nonce      = ( $admin ? 'mms_admin_' : 'mms_member_' ) . $type . '_' . $payment_id;

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => $action,
					'payment_id' => $payment_id,
				),
				admin_url( 'admin-post.php' )
			),
			$nonce
		);
	}

	/**
	 * Member invoice handler.
	 *
	 * @return void
	 */
	public static function handle_member_invoice() {
		self::stream( 'invoice', false );
	}

	/**
	 * Member receipt handler.
	 *
	 * @return void
	 */
	public static function handle_member_receipt() {
		self::stream( 'receipt', false );
	}

	/**
	 * Admin invoice handler.
	 *
	 * @return void
	 */
	public static function handle_admin_invoice() {
		self::stream( 'invoice', true );
	}

	/**
	 * Admin receipt handler.
	 *
	 * @return void
	 */
	public static function handle_admin_receipt() {
		self::stream( 'receipt', true );
	}

	/**
	 * Validate access and stream a PDF.
	 *
	 * @param string $type  invoice|receipt.
	 * @param bool   $admin Admin context.
	 * @return void
	 */
	private static function stream( $type, $admin ) {
		$payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $payment_id ) {
			wp_die( esc_html__( 'Payment not found.', 'mms-membership' ), '', array( 'response' => 404 ) );
		}

		$nonce = ( $admin ? 'mms_admin_' : 'mms_member_' ) . $type . '_' . $payment_id;

		if ( $admin ) {
			if ( ! current_user_can( 'mms_review_application' ) ) {
				wp_die( esc_html__( 'You are not allowed to download this document.', 'mms-membership' ), '', array( 'response' => 403 ) );
			}
			check_admin_referer( $nonce );
		} else {
			if ( ! is_user_logged_in() ) {
				auth_redirect();
			}
			check_admin_referer( $nonce );
		}

		$payment = MMS_Payments::get( $payment_id );

		if ( ! $payment ) {
			wp_die( esc_html__( 'Payment not found.', 'mms-membership' ), '', array( 'response' => 404 ) );
		}

		if ( ! $admin ) {
			$owned = MMS_Applications::get( $payment['application_id'] );

			if ( ! $owned || ! MMS_Members::user_owns_application( get_current_user_id(), $owned ) ) {
				wp_die( esc_html__( 'You are not allowed to download this document.', 'mms-membership' ), '', array( 'response' => 403 ) );
			}
		}

		if ( 'receipt' === $type && 'paid' !== $payment['status'] ) {
			wp_die( esc_html__( 'The receipt is available once the payment has been received.', 'mms-membership' ), '', array( 'response' => 403 ) );
		}

		$application = MMS_Applications::get( $payment['application_id'] );

		if ( ! $application ) {
			wp_die( esc_html__( 'Application not found.', 'mms-membership' ), '', array( 'response' => 404 ) );
		}

		$meta = MMS_Applications::get_meta( $application['id'] );
		$type_def = MMS_Membership_Types::get_type( $application['membership_type'] );

		$pdf = 'invoice' === $type
			? self::invoice_pdf( $application, $meta, $type_def, $payment )
			: self::receipt_pdf( $application, $meta, $type_def, $payment );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$name = ( 'invoice' === $type ? 'MACM-Invoice-' : 'MACM-Receipt-' ) . self::safe_name( $payment['invoice_no'] ? $payment['invoice_no'] : $payment['id'] ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Generate an invoice PDF.
	 *
	 * @param array       $application Application row.
	 * @param array       $meta        Application meta.
	 * @param array|null  $type        Membership type definition.
	 * @param array       $payment     Payment row.
	 * @return string|WP_Error
	 */
	public static function invoice_pdf( $application, $meta, $type, $payment ) {
		if ( ! class_exists( 'FPDF' ) ) {
			return new WP_Error( 'mms_pdf_missing', __( 'The PDF library is unavailable.', 'mms-membership' ) );
		}

		$paid   = 'paid' === $payment['status'];
		$status = $paid ? 'PAID' : ( 'failed' === $payment['status'] ? 'FAILED' : 'PAYMENT DUE' );

		$pdf = self::new_pdf( 'Invoice ' . $payment['invoice_no'] );
		self::draw_header( $pdf );

		$pdf->SetXY( 18, 47 );
		$pdf->SetFont( 'Helvetica', 'B', 26 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->Cell( 100, 12, 'INVOICE', 0, 0, 'L' );

		self::draw_status( $pdf, 150, 48, 42, $status );

		$pdf->SetXY( 18, 59 );
		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 112, 118, 130 );
		$pdf->Cell( 100, 5, 'MEMBERSHIP FEE', 0, 0, 'L' );

		self::draw_meta(
			$pdf,
			64,
			array(
				'Invoice No.'    => $payment['invoice_no'],
				'Invoice Date'   => self::date_label( $payment['created_at'] ),
				'Membership No.' => ! empty( $application['membership_no'] ) ? $application['membership_no'] : '-',
				'Certificate No.' => ! empty( $application['certificate_no'] ) ? $application['certificate_no'] : '-',
				'Status'         => self::invoice_status_label( $payment['status'] ),
			)
		);

		self::draw_billed_to( $pdf, $application, $meta );

		$period = self::period_label( $application, $payment, $type );
		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->SetLineWidth( 0.3 );
		$pdf->Line( 18, 110, 192, 110 );

		$pdf->SetFillColor( 26, 22, 84 );
		$pdf->Rect( 18, 114, 174, 9, 'F' );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetXY( 21, 116 );
		$pdf->Cell( 88, 5, 'DESCRIPTION', 0, 0, 'L' );
		$pdf->Cell( 38, 5, 'TERM', 0, 0, 'L' );
		$pdf->Cell( 42, 5, 'AMOUNT', 0, 0, 'R' );

		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->SetFont( 'Helvetica', 'B', 10 );
		$pdf->SetXY( 21, 128 );
		$pdf->Cell( 88, 5, self::txt( ( $type ? $type['label'] : ucfirst( (string) $application['membership_type'] ) ) . ' membership fee' ), 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 95, 102, 115 );
		$pdf->SetXY( 21, 134 );
		$pdf->Cell( 88, 4, ucfirst( (string) $payment['kind'] ) . ' membership' . ( $period ? ' | ' . $period : '' ), 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', '', 8.5 );
		$pdf->SetTextColor( 55, 63, 75 );
		$pdf->SetXY( 109, 128 );
		$pdf->Cell( 38, 5, self::duration_label( $type ), 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->SetXY( 150, 127 );
		$pdf->Cell( 42, 6, self::money( $payment['amount'], $payment['currency'] ), 0, 0, 'R' );

		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->Line( 18, 142, 192, 142 );

		$total_label = $paid ? 'TOTAL PAID' : 'TOTAL DUE';
		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetTextColor( 112, 118, 130 );
		$pdf->SetXY( 108, 146 );
		$pdf->Cell( 38, 6, $total_label, 0, 0, 'R' );
		$pdf->SetFont( 'Helvetica', 'B', 14 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->Cell( 42, 6, self::money( $payment['amount'], $payment['currency'] ), 0, 0, 'R' );

		$box_y = 160;

		if ( $paid ) {
			self::draw_note_box(
				$pdf,
				$box_y,
				'PAYMENT RECEIVED',
				array(
					'Date'        => self::date_label( $payment['paid_at'] ),
					'Method'      => self::payment_method( $payment ),
					'Reference'   => self::payment_reference( $payment ),
					'Invoice No.' => $payment['invoice_no'],
				),
				array( 240, 249, 244 ),
				array( 22, 128, 92 )
			);
		} else {
			self::draw_note_box(
				$pdf,
				$box_y,
				'PAYMENT INSTRUCTIONS',
				array(
					'Pay online'   => 'Member portal at ' . home_url( '/member-portal/' ),
					'Reference'    => $payment['invoice_no'],
					'Accepted via' => 'ToyyibPay (FPX and card)',
				),
				array( 252, 247, 232 ),
				array( 143, 102, 0 )
			);
			$pdf->SetFont( 'Helvetica', '', 8 );
			$pdf->SetTextColor( 95, 102, 115 );
			$pdf->SetXY( 18, $box_y + 38 );
			$pdf->MultiCell( 174, 4.5, self::txt( 'Please keep the invoice number as the payment reference. Your membership is activated automatically once payment is confirmed.' ) );
		}

		self::draw_footer( $pdf );

		return $pdf->Output( 'S' );
	}

	/**
	 * Generate a receipt PDF.
	 *
	 * @param array      $application Application row.
	 * @param array      $meta        Application meta.
	 * @param array|null $type        Membership type definition.
	 * @param array      $payment     Payment row.
	 * @return string|WP_Error
	 */
	public static function receipt_pdf( $application, $meta, $type, $payment ) {
		if ( ! class_exists( 'FPDF' ) ) {
			return new WP_Error( 'mms_pdf_missing', __( 'The PDF library is unavailable.', 'mms-membership' ) );
		}

		if ( 'paid' !== $payment['status'] ) {
			return new WP_Error( 'mms_receipt_unpaid', __( 'The receipt is available once the payment has been received.', 'mms-membership' ) );
		}

		$pdf = self::new_pdf( 'Receipt ' . $payment['invoice_no'] );
		self::draw_header( $pdf );

		$pdf->SetXY( 18, 47 );
		$pdf->SetFont( 'Helvetica', 'B', 26 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->Cell( 100, 12, 'RECEIPT', 0, 0, 'L' );

		self::draw_status( $pdf, 150, 48, 42, 'PAID' );

		$pdf->SetXY( 18, 59 );
		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 112, 118, 130 );
		$pdf->Cell( 100, 5, 'OFFICIAL PAYMENT RECEIPT', 0, 0, 'L' );

		self::draw_meta(
			$pdf,
			64,
			array(
				'Receipt No.'     => $payment['invoice_no'],
				'Payment Date'    => self::date_label( $payment['paid_at'] ),
				'Membership No.'  => ! empty( $application['membership_no'] ) ? $application['membership_no'] : '-',
				'Certificate No.' => ! empty( $application['certificate_no'] ) ? $application['certificate_no'] : '-',
				'Invoice No.'     => $payment['invoice_no'],
			)
		);

		self::draw_billed_to( $pdf, $application, $meta, 'RECEIVED FROM' );

		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->SetLineWidth( 0.3 );
		$pdf->Line( 18, 110, 192, 110 );

		$pdf->SetFillColor( 26, 22, 84 );
		$pdf->Rect( 18, 114, 174, 9, 'F' );
		$pdf->SetTextColor( 255, 255, 255 );
		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetXY( 21, 116 );
		$pdf->Cell( 88, 5, 'DESCRIPTION', 0, 0, 'L' );
		$pdf->Cell( 38, 5, 'MEMBERSHIP TERM', 0, 0, 'L' );
		$pdf->Cell( 42, 5, 'AMOUNT', 0, 0, 'R' );

		$period = self::period_label( $application, $payment, $type );

		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->SetFont( 'Helvetica', 'B', 10 );
		$pdf->SetXY( 21, 128 );
		$pdf->Cell( 88, 5, self::txt( ( $type ? $type['label'] : ucfirst( (string) $application['membership_type'] ) ) . ' membership fee' ), 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 95, 102, 115 );
		$pdf->SetXY( 21, 134 );
		$pdf->Cell( 88, 4, ucfirst( (string) $payment['kind'] ) . ' membership', 0, 0, 'L' );

		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 55, 63, 75 );
		$pdf->SetXY( 109, 128 );
		$pdf->MultiCell( 38, 4.5, self::txt( $period ? $period : 'See membership record' ), 0, 'L' );

		$pdf->SetFont( 'Helvetica', 'B', 11 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->SetXY( 150, 127 );
		$pdf->Cell( 42, 6, self::money( $payment['amount'], $payment['currency'] ), 0, 0, 'R' );

		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->Line( 18, 142, 192, 142 );

		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetTextColor( 112, 118, 130 );
		$pdf->SetXY( 108, 146 );
		$pdf->Cell( 38, 6, 'TOTAL RECEIVED', 0, 0, 'R' );
		$pdf->SetFont( 'Helvetica', 'B', 14 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->Cell( 42, 6, self::money( $payment['amount'], $payment['currency'] ), 0, 0, 'R' );

		self::draw_note_box(
			$pdf,
			160,
			'PAYMENT DETAILS',
			array(
				'Date paid'   => self::date_label( $payment['paid_at'] ),
				'Method'      => self::payment_method( $payment ),
				'Reference'   => self::payment_reference( $payment ),
				'Invoice No.' => $payment['invoice_no'],
			),
			array( 240, 249, 244 ),
			array( 22, 128, 92 )
		);

		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 95, 102, 115 );
		$pdf->SetXY( 18, 200 );
		$pdf->MultiCell(
			174,
			4.5,
			self::txt( 'This receipt confirms that the payment above has been received. Your membership is active for the term shown. Keep this receipt for your records.' )
		);

		self::draw_footer( $pdf );

		return $pdf->Output( 'S' );
	}

	/**
	 * Create a configured FPDF instance.
	 *
	 * @param string $title Document title metadata.
	 * @return FPDF
	 */
	private static function new_pdf( $title ) {
		$pdf = new \FPDF( 'P', 'mm', 'A4' );
		$pdf->SetMargins( 18, 18, 18 );
		$pdf->SetAutoPageBreak( true, 26 );
		$pdf->SetTitle( $title );
		$pdf->SetAuthor( self::ORG_NAME );
		$pdf->SetCreator( 'MACM Membership' );
		$pdf->AddPage();

		return $pdf;
	}

	/**
	 * Draw the branded document header.
	 *
	 * @param FPDF $pdf PDF instance.
	 * @return void
	 */
	private static function draw_header( $pdf ) {
		$pdf->SetFillColor( 26, 22, 84 );
		$pdf->Rect( 0, 0, 210, 4.5, 'F' );

		$logo = self::logo_path();

		if ( $logo ) {
			$pdf->Image( $logo, 18, 12, 64 );
		} else {
			$pdf->SetFont( 'Helvetica', 'B', 20 );
			$pdf->SetTextColor( 26, 22, 84 );
			$pdf->SetXY( 18, 15 );
			$pdf->Cell( 64, 10, 'MACM', 0, 0, 'L' );
		}

		$pdf->SetTextColor( 90, 96, 110 );
		$pdf->SetFont( 'Helvetica', '', 7.6 );
		$pdf->SetXY( 108, 12 );
		$pdf->Cell( 84, 4, self::txt( self::ORG_NAME ), 0, 2, 'R' );
		$pdf->Cell( 84, 4, 'No. Pendaftaran: ' . self::ORG_REG, 0, 2, 'R' );
		$pdf->Cell( 84, 4, '149, Jalan Pahang,', 0, 2, 'R' );
		$pdf->Cell( 84, 4, 'Setapak, 53000 Kuala Lumpur.', 0, 2, 'R' );
		$pdf->Cell( 84, 4, 'Tel: ' . self::ORG_PHONE, 0, 2, 'R' );

		$pdf->SetDrawColor( 221, 227, 234 );
		$pdf->SetLineWidth( 0.4 );
		$pdf->Line( 18, 38, 192, 38 );
	}

	/**
	 * Draw the document status badge.
	 *
	 * @param FPDF   $pdf   PDF instance.
	 * @param float  $x     X position.
	 * @param float  $y     Y position.
	 * @param float  $w     Width.
	 * @param string $label Badge label.
	 * @return void
	 */
	private static function draw_status( $pdf, $x, $y, $w, $label ) {
		if ( 'PAID' === $label ) {
			$pdf->SetFillColor( 226, 245, 235 );
			$pdf->SetTextColor( 22, 112, 82 );
		} elseif ( 'FAILED' === $label ) {
			$pdf->SetFillColor( 251, 234, 234 );
			$pdf->SetTextColor( 143, 35, 35 );
		} else {
			$pdf->SetFillColor( 252, 244, 217 );
			$pdf->SetTextColor( 122, 92, 0 );
		}

		$pdf->Rect( $x, $y, $w, 8, 'F' );
		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetXY( $x, $y + 1.7 );
		$pdf->Cell( $w, 4.5, $label, 0, 0, 'C' );
	}

	/**
	 * Draw the right-hand metadata table.
	 *
	 * @param FPDF  $pdf  PDF instance.
	 * @param float $y    Starting Y.
	 * @param array $rows Label => value.
	 * @return void
	 */
	private static function draw_meta( $pdf, $y, $rows ) {
		$pdf->SetFont( 'Helvetica', '', 7.3 );
		$pdf->SetTextColor( 120, 126, 138 );

		foreach ( $rows as $label => $value ) {
			$pdf->SetXY( 120, $y );
			$pdf->Cell( 28, 5, $label, 0, 0, 'L' );
			$pdf->SetFont( 'Helvetica', 'B', 8 );
			$pdf->SetTextColor( 44, 52, 66 );
			$pdf->Cell( 44, 5, self::txt( (string) $value ), 0, 0, 'R' );
			$pdf->SetFont( 'Helvetica', '', 7.3 );
			$pdf->SetTextColor( 120, 126, 138 );
			$y += 5.2;
		}
	}

	/**
	 * Draw the billed-to card.
	 *
	 * @param FPDF   $pdf         PDF instance.
	 * @param array  $application Application row.
	 * @param array  $meta        Application meta.
	 * @param string $title       Card title.
	 * @return void
	 */
	private static function draw_billed_to( $pdf, $application, $meta, $title = 'BILLED TO' ) {
		$pdf->SetFillColor( 246, 248, 251 );
		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->Rect( 18, 66, 96, 40, 'DF' );

		$pdf->SetXY( 22, 70 );
		$pdf->SetFont( 'Helvetica', 'B', 7 );
		$pdf->SetTextColor( 112, 118, 130 );
		$pdf->Cell( 88, 4, $title, 0, 0, 'L' );

		$company = ! empty( $meta['company_name'] ) ? $meta['company_name'] : ( ! empty( $application['reference'] ) ? $application['reference'] : 'Member' );
		$pdf->SetXY( 22, 75 );
		$pdf->SetFont( 'Helvetica', 'B', 10 );
		$pdf->SetTextColor( 26, 22, 84 );
		$pdf->Cell( 88, 5, self::txt( self::shorten( $company, 54 ) ), 0, 0, 'L' );

		$lines = array();
		if ( ! empty( $meta['company_reg_no'] ) ) {
			$lines[] = 'Reg. No: ' . $meta['company_reg_no'];
		}
		if ( ! empty( $meta['company_address'] ) ) {
			$lines[] = self::shorten( (string) $meta['company_address'], 200 );
		}
		$city = trim( (string) ( isset( $meta['company_city'] ) ? $meta['company_city'] : '' ) . ( ! empty( $meta['company_postcode'] ) ? ' ' . $meta['company_postcode'] : '' ) );
		if ( '' !== $city ) {
			$lines[] = $city;
		}
		if ( ! empty( $meta['company_state'] ) ) {
			$lines[] = $meta['company_state'];
		}
		if ( ! empty( $meta['email'] ) ) {
			$lines[] = $meta['email'];
		}

		$pdf->SetXY( 22, 81 );
		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 70, 76, 88 );
		$pdf->MultiCell( 88, 4.2, self::txt( implode( "\n", $lines ) ) );
	}

	/**
	 * Draw a highlighted details box.
	 *
	 * @param FPDF   $pdf      PDF instance.
	 * @param float  $y        Starting Y.
	 * @param string $title    Box title.
	 * @param array  $rows     Label => value.
	 * @param array  $fill     RGB fill.
	 * @param array  $accent   RGB accent.
	 * @return void
	 */
	private static function draw_note_box( $pdf, $y, $title, $rows, $fill, $accent ) {
		$height = 36;

		$pdf->SetFillColor( $fill[0], $fill[1], $fill[2] );
		$pdf->Rect( 18, $y, 174, $height, 'F' );
		$pdf->SetFillColor( $accent[0], $accent[1], $accent[2] );
		$pdf->Rect( 18, $y, 3.2, $height, 'F' );

		$pdf->SetXY( 26, $y + 5 );
		$pdf->SetFont( 'Helvetica', 'B', 7.5 );
		$pdf->SetTextColor( $accent[0], $accent[1], $accent[2] );
		$pdf->Cell( 70, 4, $title, 0, 0, 'L' );

		$row = 0;
		foreach ( $rows as $label => $value ) {
			$row_y = $y + 11 + $row * 6.2;
			$pdf->SetXY( 26, $row_y );
			$pdf->SetFont( 'Helvetica', '', 7 );
			$pdf->SetTextColor( 118, 124, 134 );
			$pdf->Cell( 22, 4, $label, 0, 0, 'L' );
			$pdf->SetFont( 'Helvetica', 'B', 8 );
			$pdf->SetTextColor( 44, 52, 66 );
			$pdf->Cell( 138, 4, self::txt( self::shorten( (string) $value, 82 ) ), 0, 0, 'L' );
			$row++;
		}
	}

	/**
	 * Draw the document footer.
	 *
	 * @param FPDF $pdf PDF instance.
	 * @return void
	 */
	private static function draw_footer( $pdf ) {
		$pdf->SetDrawColor( 226, 232, 240 );
		$pdf->Line( 18, 268, 192, 268 );

		$pdf->SetY( 271 );
		$pdf->SetFont( 'Helvetica', '', 7 );
		$pdf->SetTextColor( 120, 126, 138 );
		$pdf->Cell( 174, 3.8, self::txt( self::ORG_NAME . ' | No. Pendaftaran: ' . self::ORG_REG ), 0, 2, 'C' );
		$pdf->Cell( 174, 3.8, self::txt( self::ORG_ADDRESS . ' | Tel: ' . self::ORG_PHONE . ' | ' . self::ORG_WEBSITE ), 0, 2, 'C' );
		$pdf->Cell( 174, 3.8, 'This is a computer-generated document. No signature is required.', 0, 2, 'C' );
	}

	/**
	 * Human label for an invoice status.
	 *
	 * @param string $status Payment status.
	 * @return string
	 */
	private static function invoice_status_label( $status ) {
		if ( 'paid' === $status ) {
			return 'Paid';
		}
		if ( 'failed' === $status ) {
			return 'Failed';
		}
		if ( 'refund' === $status ) {
			return 'Refunded';
		}

		return 'Payment due';
	}

	/**
	 * Payment method descriptor.
	 *
	 * @param array $payment Payment row.
	 * @return string
	 */
	private static function payment_method( $payment ) {
		$provider = isset( $payment['provider'] ) ? strtolower( (string) $payment['provider'] ) : '';

		if ( 'manual' === $provider ) {
			return ! empty( $payment['channel'] ) ? $payment['channel'] : 'Manual payment';
		}
		if ( 'toyyibpay' === $provider ) {
			return 'ToyyibPay (FPX / card)';
		}

		return '' !== $provider ? ucfirst( $provider ) : '-';
	}

	/**
	 * Payment reference descriptor.
	 *
	 * @param array $payment Payment row.
	 * @return string
	 */
	private static function payment_reference( $payment ) {
		if ( ! empty( $payment['transaction_ref'] ) ) {
			return $payment['transaction_ref'];
		}
		if ( ! empty( $payment['provider_ref'] ) ) {
			return $payment['provider_ref'];
		}

		return '-';
	}

	/**
	 * Membership term label.
	 *
	 * @param array      $application Application row.
	 * @param array      $payment     Payment row.
	 * @param array|null $type        Membership type definition.
	 * @return string
	 */
	private static function period_label( $application, $payment, $type ) {
		$start = ! empty( $payment['period_start'] ) ? $payment['period_start'] : ( ! empty( $application['started_at'] ) ? $application['started_at'] : '' );
		$end   = ! empty( $payment['period_end'] ) ? $payment['period_end'] : ( ! empty( $application['expires_at'] ) ? $application['expires_at'] : '' );

		if ( $start && $end ) {
			return self::date_label( $start ) . ' - ' . self::date_label( $end );
		}

		return self::duration_label( $type );
	}

	/**
	 * Duration label from a membership type.
	 *
	 * @param array|null $type Membership type definition.
	 * @return string
	 */
	private static function duration_label( $type ) {
		$months = ( $type && ! empty( $type['duration_months'] ) ) ? (int) $type['duration_months'] : 12;

		return 1 === $months ? '1 month' : $months . ' months';
	}

	/**
	 * Format a monetary amount for PDF output.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private static function money( $amount, $currency = 'MYR' ) {
		$formatted = number_format( (float) $amount, 2, '.', ',' );

		return 'MYR' === strtoupper( (string) $currency ) ? 'RM ' . $formatted : strtoupper( (string) $currency ) . ' ' . $formatted;
	}

	/**
	 * Format a MySQL date for display.
	 *
	 * @param string $date MySQL date.
	 * @return string
	 */
	private static function date_label( $date ) {
		if ( empty( $date ) ) {
			return '-';
		}

		return mysql2date( 'j M Y', $date );
	}

	/**
	 * Locate the configured or bundled logo.
	 *
	 * @return string
	 */
	private static function logo_path() {
		$settings = get_option( 'mms_settings', array() );
		$logo_id  = isset( $settings['invoice_logo_id'] ) ? absint( $settings['invoice_logo_id'] ) : 0;

		if ( $logo_id ) {
			$path = get_attached_file( $logo_id );
			if ( $path && file_exists( $path ) ) {
				return $path;
			}
		}

		$bundled = MMS_MEMBERSHIP_DIR . 'assets/images/macm-logo.png';

		return file_exists( $bundled ) ? $bundled : '';
	}

	/**
	 * Convert text to the PDF core-font encoding.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function txt( $text ) {
		$converted = iconv( 'UTF-8', 'Windows-1252//TRANSLIT', (string) $text );

		return false === $converted ? (string) $text : $converted;
	}

	/**
	 * Shorten a string for fixed-width cells.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function shorten( $text, $length ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
			return mb_substr( $text, 0, $length - 3 ) . '...';
		}
		if ( strlen( $text ) > $length ) {
			return substr( $text, 0, $length - 3 ) . '...';
		}

		return $text;
	}

	/**
	 * Sanitize a filename segment.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function safe_name( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );

		return $value ? $value : 'document';
	}
}
