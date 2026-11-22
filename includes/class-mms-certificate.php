<?php
/**
 * Membership certificate: writes member details over the MACM A4 certificate.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Certificate
 */
class MMS_Certificate {

	const ACTION = 'mms_download_certificate';
	const NONCE  = 'mms_certificate';

	const TEMPLATE = 'assets/certificates/macm-membership-certificate.pdf';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Stream the certificate for the current member.
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( MMS_Members::login_url() );
			exit;
		}

		$user           = wp_get_current_user();
		$application_id = isset( $_GET['application_id'] ) ? absint( wp_unslash( $_GET['application_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$application    = MMS_Members::resolve_application( $user->ID, $application_id );

		if ( is_wp_error( $application ) ) {
			wp_die( esc_html( $application->get_error_message() ), '', array( 'response' => 403 ) );
		}

		if ( ! $application ) {
			wp_die( esc_html__( 'No membership application is linked to this account.', 'mms-membership' ), 404 );
		}

		check_admin_referer( self::NONCE . '_' . (int) $application['id'] );

		if ( empty( $application['membership_no'] ) || ! in_array( $application['status'], array( MMS_Status::ACTIVE, MMS_Status::EXPIRED ), true ) ) {
			wp_die( esc_html__( 'The certificate is available once your membership is active.', 'mms-membership' ), 403 );
		}

		$pdf = self::generate(
			$application,
			MMS_Applications::get_meta( $application['id'] ),
			MMS_Membership_Types::get_type( $application['membership_type'] )
		);

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), 500 );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( self::filename( $application ) ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Suggested file name.
	 *
	 * @param array $application Application row.
	 * @return string
	 */
	public static function filename( $application ) {
		return 'MACM-Membership-' . $application['membership_no'] . '.pdf';
	}

	/**
	 * Certificate number in the form TYPE/MEMBERSHIP/STATE/SERIAL, stored once.
	 *
	 * @param array      $application Application row (id, membership_type, membership_no, certificate_no).
	 * @param array|null $meta        Company / PIC row (loaded when null).
	 * @param array|null $type        Membership type (loaded when null).
	 * @return string
	 */
	public static function issue_number( $application, $meta = null, $type = null ) {
		global $wpdb;

		$application = (array) $application;

		if ( ! empty( $application['certificate_no'] ) ) {
			return (string) $application['certificate_no'];
		}

		$number = self::build_number( $application, $meta, $type );
		$id     = isset( $application['id'] ) ? (int) $application['id'] : 0;

		if ( $id ) {
			$wpdb->update(
				MMS_DB::table( MMS_DB::APPLICATION ),
				array( 'certificate_no' => $number ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return $number;
	}

	/**
	 * Build a certificate number without storing it.
	 *
	 * @param array      $application Application row.
	 * @param array|null $meta        Company / PIC row.
	 * @param array|null $type        Membership type.
	 * @return string
	 */
	public static function build_number( $application, $meta = null, $type = null ) {
		$application = (array) $application;
		$id          = isset( $application['id'] ) ? (int) $application['id'] : 0;
		$meta        = null === $meta && $id ? MMS_Applications::get_meta( $id ) : (array) $meta;
		$type        = null === $type && ! empty( $application['membership_type'] )
			? MMS_Membership_Types::get_type( $application['membership_type'] )
			: $type;

		$type_code = '';
		if ( $type && ! empty( $type['code'] ) ) {
			$type_code = strtoupper( (string) $type['code'] );
		} elseif ( ! empty( $application['membership_type'] ) ) {
			$type_code = MMS_Membership_Types::type_code( $application['membership_type'] );
		}

		$member_no = ! empty( $application['membership_no'] )
			? (string) $application['membership_no']
			: MMS_Payments::membership_number( $application );

		$state_name = isset( $meta['company_state'] ) ? (string) $meta['company_state'] : '';
		$state_code = MMS_Membership_Types::state_code( $state_name );
		if ( '' === $state_code ) {
			$state_code = '00';
		}

		return sprintf( '%s/%s/%s/%04d', $type_code, $member_no, $state_code, wp_rand( 1000, 9999 ) );
	}

	/**
	 * Absolute path to the certificate artwork.
	 *
	 * @return string
	 */
	public static function template_path() {
		return MMS_MEMBERSHIP_DIR . self::TEMPLATE;
	}

	/**
	 * Build the certificate PDF (A4 landscape).
	 *
	 * @param array      $application Application row.
	 * @param array      $meta        Company / PIC row.
	 * @param array|null $type        Membership type.
	 * @return string|WP_Error PDF bytes.
	 */
	public static function generate( $application, $meta, $type ) {
		if ( ! class_exists( '\setasign\Fpdi\Fpdi' ) ) {
			return new WP_Error( 'mms_certificate_library', __( 'The PDF library used to build certificates is missing. Run composer install in the plugin folder.', 'mms-membership' ) );
		}

		$template = self::template_path();

		if ( ! is_readable( $template ) ) {
			return new WP_Error( 'mms_certificate_template', __( 'The membership certificate template could not be found.', 'mms-membership' ) );
		}

		$company     = isset( $meta['company_name'] ) ? $meta['company_name'] : '';
		$certificate = self::issue_number( $application, $meta, $type );
		$type_name   = $type ? $type['label'] : $application['membership_type'];
		$start     = $application['started_at'] ? mysql2date( 'd/m/Y', $application['started_at'] ) : '';
		$expires   = $application['expires_at'] ? mysql2date( 'd/m/Y', $application['expires_at'] ) : '';
		$term      = $expires ? $start . ' – ' . $expires : $start;

		try {
			$pdf = new \setasign\Fpdi\Fpdi( 'L', 'mm', 'A4' );
			$pdf->SetAutoPageBreak( false );
			$pdf->SetMargins( 0, 0, 0 );
			$pdf->SetTitle( 'MACM Membership Certificate ' . $certificate );
			$pdf->SetAuthor( 'Persatuan Pengurusan Pembersihan Malaysia' );
			$pdf->SetCreator( 'MACM Membership' );
			$pdf->AddPage();

			$source = $pdf->setSourceFile( $template );
			$page   = $pdf->importPage( 1 );
			$pdf->useTemplate( $page, 0, 0, 297, 210 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'mms_certificate_template_invalid', __( 'The membership certificate template could not be read.', 'mms-membership' ) );
		}

		$encoded = self::encode( self::shorten( $company, 90 ) );
		$pdf->SetTextColor( 26, 22, 84 );
		self::write_line( $pdf, $encoded, 148.9, 89.7, 24, 'B', 200 );

		$pdf->SetTextColor( 26, 22, 84 );
		self::write_line( $pdf, self::encode( self::shorten( $type_name, 45 ) ), 149.2, 111.9, 20, 'I', 106 );

		$pdf->SetTextColor( 35, 35, 35 );
		self::write_line( $pdf, self::encode( $term ), 150.2, 156.9, 9, '', 37 );

		$pdf->SetTextColor( 70, 70, 70 );
		self::write_line( $pdf, self::encode( 'Certificate No. ' . $certificate ), 150.0, 165.0, 8.5, '', 120 );

		return $pdf->Output( 'S' );
	}

	/**
	 * Draw centered text at a baseline, shrinking to fit a width.
	 *
	 * @param \setasign\Fpdi\Fpdi $pdf      PDF instance.
	 * @param string              $text     Encoded text.
	 * @param float               $center_x Horizontal center in mm.
	 * @param float               $baseline Baseline Y in mm from the top.
	 * @param float               $size     Font size in pt.
	 * @param string              $style    FPDF font style.
	 * @param float               $max      Maximum width in mm.
	 * @return void
	 */
	private static function write_line( $pdf, $text, $center_x, $baseline, $size, $style, $max ) {
		$pdf->SetFont( 'Times', $style, $size );

		while ( $size > 7 && $pdf->GetStringWidth( $text ) > $max ) {
			$size -= 0.5;
			$pdf->SetFont( 'Times', $style, $size );
		}

		$pdf->Text( $center_x - ( $pdf->GetStringWidth( $text ) / 2 ), $baseline, $text );
	}

	/**
	 * Convert UTF-8 to the Windows-1252 encoding used by the core PDF fonts.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function encode( $text ) {
		$encoded = iconv( 'UTF-8', 'Windows-1252//TRANSLIT', (string) $text );

		return false === $encoded ? (string) $text : $encoded;
	}

	/**
	 * Shorten a string to fit the certificate.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length.
	 * @return string
	 */
	private static function shorten( $text, $max ) {
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 3 ) . '...' : $text;
	}
}
