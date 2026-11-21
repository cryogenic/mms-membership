<?php
/**
 * Applicant resume flow: token link + re-upload of insufficient documents.
 *
 * Shortcode: [mms_membership_resume]
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Resume
 */
class MMS_Resume {

	const SHORTCODE = 'mms_membership_resume';
	const NONCE     = 'mms_resume_upload';
	const ACTION    = 'mms_resume_upload';

	/**
	 * Single instance.
	 *
	 * @var MMS_Resume|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Resume
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
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_upload' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_upload' ) );
	}

	/**
	 * Render the resume screen (upload form or a status panel).
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/js/mms-public.js', array(), MMS_MEMBERSHIP_VERSION, true );

		$token       = isset( $_GET['mms_resume'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_resume'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done        = ! empty( $_GET['mms_resume_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$application = '' !== $token ? MMS_Applications::get_by_token( $token ) : null;

		if ( ! $application ) {
			$mms_state = 'invalid';
			ob_start();
			include MMS_MEMBERSHIP_DIR . 'templates/resume/status.php';
			return ob_get_clean();
		}

		$mms_app       = $application;
		$mms_token     = $token;
		$mms_meta      = MMS_Applications::get_meta( $application['id'] );
		$mms_type      = MMS_Membership_Types::get_type( $application['membership_type'] );
		$mms_docs      = MMS_Membership_Types::get_document_types();
		$mms_documents = MMS_Applications::get_documents( $application['id'] );
		$mms_rejected  = array_values(
			array_filter(
				$mms_documents,
				function ( $document ) {
					return 'reject' === $document['review_status'];
				}
			)
		);
		$mms_doc_status = MMS_Status::document_statuses();
		$mms_errors     = $this->get_errors();
		$mms_action     = admin_url( 'admin-post.php' );
		$mms_return     = MMS_Applications::resume_url( $token );

		if ( MMS_Status::NEED_INFO !== $application['status'] ) {
			$mms_state = $done ? 'done' : 'closed';
			ob_start();
			include MMS_MEMBERSHIP_DIR . 'templates/resume/status.php';
			return ob_get_clean();
		}

		$mms_state = 'open';

		ob_start();
		include MMS_MEMBERSHIP_DIR . 'templates/resume/form.php';
		return ob_get_clean();
	}

	/**
	 * Handle replacement uploads and put the application back under review.
	 *
	 * @return void
	 */
	public function handle_upload() {
		$token       = isset( $_POST['mms_resume'] ) ? sanitize_text_field( wp_unslash( $_POST['mms_resume'] ) ) : '';
		$application = MMS_Applications::get_by_token( $token );
		$fallback    = '' !== $token ? MMS_Applications::resume_url( $token ) : home_url( '/' );

		if ( ! $application ) {
			wp_safe_redirect( $fallback );
			exit;
		}

		check_admin_referer( self::NONCE . '_' . (int) $application['id'] );

		if ( MMS_Status::NEED_INFO !== $application['status'] ) {
			wp_safe_redirect( add_query_arg( 'mms_resume_done', '1', $fallback ) );
			exit;
		}

		global $wpdb;

		$definitions = MMS_Membership_Types::get_document_types();
		$documents   = MMS_Applications::get_documents( $application['id'] );
		$table       = MMS_DB::table( MMS_DB::DOCUMENT );

		$errors     = array();
		$replaced   = array();

		foreach ( $documents as $document ) {
			if ( 'reject' !== $document['review_status'] ) {
				continue;
			}

			$key   = $document['doc_type'];
			$field = 'resume_doc_' . $key;

			if ( ! isset( $definitions[ $key ], $_FILES[ $field ] ) ) {
				continue;
			}

			$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$errors[] = sprintf(
					/* translators: %s: document name */
					__( 'The file for “%s” could not be uploaded. Please try again.', 'mms-membership' ),
					$definitions[ $key ]['label']
				);
				continue;
			}

			$check = MMS_Files::check( $file, $definitions[ $key ] );
			if ( is_wp_error( $check ) ) {
				$errors[] = sprintf(
					/* translators: 1: document name, 2: error message */
					__( '%1$s: %2$s', 'mms-membership' ),
					$definitions[ $key ]['label'],
					$check->get_error_message()
				);
				continue;
			}

			$stored = MMS_Files::store( $file, $definitions[ $key ], $key, $application['id'], $document['stored_path'] );
			if ( is_wp_error( $stored ) ) {
				$errors[] = sprintf(
					/* translators: 1: document name, 2: error message */
					__( '%1$s: %2$s', 'mms-membership' ),
					$definitions[ $key ]['label'],
					$stored->get_error_message()
				);
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'original_name' => $stored['original_name'],
					'stored_path'   => $stored['stored_path'],
					'mime_type'     => $stored['mime_type'],
					'file_size'     => $stored['file_size'],
					'review_status' => 'pending',
					'admin_note'    => '',
					'uploaded_at'   => current_time( 'mysql' ),
				),
				array( 'id' => (int) $document['id'] ),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$replaced[] = $definitions[ $key ]['label'];

			MMS_Applications::log(
				$application['id'],
				'document_replaced',
				sprintf(
					/* translators: %s: document name */
					__( 'Applicant uploaded a new file for “%s”.', 'mms-membership' ),
					$definitions[ $key ]['label']
				)
			);
		}

		if ( ! empty( $errors ) ) {
			$this->fail( $fallback, $errors );
		}

		if ( empty( $replaced ) ) {
			$this->fail( $fallback, array( __( 'Please choose at least one document to upload.', 'mms-membership' ) ) );
		}

		$now = current_time( 'mysql' );

		$wpdb->update(
			MMS_DB::table( MMS_DB::APPLICATION ),
			array(
				'status'      => MMS_Status::UNDER_REVIEW,
				'reviewed_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $application['id'] ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		MMS_Applications::log(
			$application['id'],
			'documents_resubmitted',
			sprintf(
				/* translators: %s: list of document names */
				__( 'Replacement documents received: %s. Status returned to under review.', 'mms-membership' ),
				implode( ', ', $replaced )
			)
		);

		$this->notify_admin( $application, $replaced );

		wp_safe_redirect( add_query_arg( 'mms_resume_done', '1', MMS_Applications::resume_url( $token ) ) );
		exit;
	}

	/**
	 * Email the reviewer that new documents arrived.
	 *
	 * @param array $application Application row.
	 * @param array $replaced Document labels that were replaced.
	 * @return void
	 */
	private function notify_admin( $application, $replaced ) {
		$admin_email = get_option( 'admin_email' );

		if ( ! $admin_email || ! is_email( $admin_email ) ) {
			return;
		}

		$meta      = MMS_Applications::get_meta( $application['id'] );
		$reference = '' !== $application['reference'] ? $application['reference'] : '#' . (int) $application['id'];
		$company   = isset( $meta['company_name'] ) ? $meta['company_name'] : '';

		$body = array(
			sprintf(
				/* translators: %s: company name */
				__( 'Company: %s', 'mms-membership' ),
				$company
			),
			sprintf(
				/* translators: %s: reference number */
				__( 'Reference: %s', 'mms-membership' ),
				$reference
			),
			'',
			__( 'New documents were uploaded by the applicant:', 'mms-membership' ),
			'- ' . implode( "\n- ", $replaced ),
			'',
			__( 'The application status is now “Under review”.', 'mms-membership' ),
			'',
			add_query_arg(
				array(
					'page'        => 'mms-applications',
					'application' => (int) $application['id'],
				),
				admin_url( 'admin.php' )
			),
		);

		$sent = wp_mail(
			$admin_email,
			sprintf(
				/* translators: %s: reference number */
				__( 'Documents resubmitted — %s', 'mms-membership' ),
				$reference
			),
			implode( "\n", $body )
		);

		MMS_Applications::log(
			$application['id'],
			$sent ? 'email_sent' : 'email_failed',
			$sent
				? __( 'Reviewer notified that replacement documents were uploaded.', 'mms-membership' )
				: __( 'Reviewer notification could not be sent — check the site mail configuration.', 'mms-membership' )
		);
	}

	/**
	 * Read stored upload errors for the current attempt.
	 *
	 * @return array<int,string>
	 */
	private function get_errors() {
		$key = isset( $_GET['mms_error'] ) ? sanitize_key( wp_unslash( $_GET['mms_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $key ) {
			return array();
		}

		$stored = get_transient( 'mms_resume_err_' . $key );
		delete_transient( 'mms_resume_err_' . $key );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Redirect back to the resume page with error messages.
	 *
	 * @param string $fallback Resume page URL.
	 * @param array  $errors Error messages.
	 * @return void
	 */
	private function fail( $fallback, $errors ) {
		$key = wp_generate_password( 12, false, false );

		set_transient( 'mms_resume_err_' . $key, array_values( (array) $errors ), HOUR_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'mms_error', $key, $fallback ) );
		exit;
	}
}
