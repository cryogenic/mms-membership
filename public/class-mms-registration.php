<?php
/**
 * Public membership registration form (multi-step).
 *
 * Shortcode: [mms_membership_registration]
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Registration
 */
class MMS_Registration {

	const SHORTCODE = 'mms_membership_registration';
	const NONCE     = 'mms_submit_application';
	const ACTION    = 'mms_submit_application';

	/**
	 * Single instance.
	 *
	 * @var MMS_Registration|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return MMS_Registration
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
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_submit' ) );
	}

	/**
	 * Render the registration form (or the success panel).
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/css/mms-public.css', array(), MMS_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'mms-public', MMS_MEMBERSHIP_URL . 'assets/js/mms-public.js', array(), MMS_MEMBERSHIP_VERSION, true );

		$reference = isset( $_GET['mms_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['mms_ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $reference ) {
			ob_start();
			include MMS_MEMBERSHIP_DIR . 'templates/registration/success.php';
			return ob_get_clean();
		}

		$types   = MMS_Membership_Types::get_types();
		$docs    = MMS_Membership_Types::get_document_types();
		$context = $this->get_error_context();

		if ( empty( $types ) ) {
			return '<p class="mms-notice">' . esc_html__( 'Registration is not open yet. Please check back later.', 'mms-membership' ) . '</p>';
		}

		$mms_types         = $types;
		$mms_docs          = $docs;
		$mms_states        = MMS_Membership_Types::get_states();
		$mms_old           = $context['old'];
		$mms_errors        = $context['errors'];
		$mms_action        = admin_url( 'admin-post.php' );
		$mms_return        = $this->current_url();
		$mms_doc_types     = $this->map_documents_to_types( $types, $docs );

		ob_start();
		include MMS_MEMBERSHIP_DIR . 'templates/registration/form.php';
		return ob_get_clean();
	}

	/**
	 * Build a map of document key => which membership types need it (required / optional).
	 *
	 * @param array $types Membership types.
	 * @param array $docs Document catalogue.
	 * @return array<string,array<string,array<int,string>>>
	 */
	private function map_documents_to_types( $types, $docs ) {
		$map = array();

		foreach ( $types as $slug => $type ) {
			foreach ( (array) $type['required_docs'] as $key ) {
				if ( isset( $docs[ $key ] ) ) {
					$map[ $key ]['required'][] = $slug;
				}
			}
			foreach ( (array) $type['optional_docs'] as $key ) {
				if ( isset( $docs[ $key ] ) ) {
					$map[ $key ]['optional'][] = $slug;
				}
			}
		}

		return $map;
	}

	/**
	 * URL of the current page (used to return after the POST).
	 *
	 * @return string
	 */
	private function current_url() {
		$permalink = get_permalink();

		return $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * Read stored validation errors / old input for the current attempt.
	 *
	 * @return array{errors:array<int,string>,old:array<string,string>}
	 */
	private function get_error_context() {
		$empty = array(
			'errors' => array(),
			'old'    => array(),
		);

		$key = isset( $_GET['mms_error'] ) ? sanitize_key( wp_unslash( $_GET['mms_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key ) {
			return $empty;
		}

		$stored = get_transient( 'mms_form_' . $key );
		delete_transient( 'mms_form_' . $key );

		if ( ! is_array( $stored ) ) {
			return $empty;
		}

		return array(
			'errors' => isset( $stored['errors'] ) ? (array) $stored['errors'] : array(),
			'old'    => isset( $stored['old'] ) ? (array) $stored['old'] : array(),
		);
	}

	/**
	 * Handle the public submission.
	 *
	 * @return void
	 */
	public function handle_submit() {
		$return = isset( $_POST['mms_return'] ) ? esc_url_raw( wp_unslash( $_POST['mms_return'] ) ) : home_url( '/' );
		$return = wp_validate_redirect( $return, home_url( '/' ) );

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE ) ) {
			$this->fail( $return, array( __( 'Your session expired. Please review the form and submit again.', 'mms-membership' ) ) );
		}

		// Honeypot: real applicants never fill this field.
		if ( ! empty( $_POST['mms_hp'] ) ) {
			$this->fail( $return, array( __( 'Submission could not be processed.', 'mms-membership' ) ) );
		}

		$types = MMS_Membership_Types::get_types();
		$docs  = MMS_Membership_Types::get_document_types();

		$type_slug = isset( $_POST['membership_type'] ) ? sanitize_key( wp_unslash( $_POST['membership_type'] ) ) : '';

		$data = array(
			'company_name'    => $this->post_text( 'company_name' ),
			'company_reg_no'  => $this->post_text( 'company_reg_no' ),
			'company_state'   => $this->post_text( 'company_state' ),
			'company_address' => $this->post_textarea( 'company_address' ),
			'company_phone'   => $this->post_text( 'company_phone' ),
			'company_email'   => sanitize_email( $this->post_text( 'company_email' ) ),
			'company_website' => $this->post_text( 'company_website' ),
			'pic_name'        => $this->post_text( 'pic_name' ),
			'pic_ic'          => $this->normalise_ic( $this->post_text( 'pic_ic' ) ),
			'pic_position'    => $this->post_text( 'pic_position' ),
			'pic_email'       => sanitize_email( $this->post_text( 'pic_email' ) ),
			'pic_phone'       => $this->post_text( 'pic_phone' ),
		);

		$old = array_merge( array( 'membership_type' => $type_slug ), $data );

		$errors = $this->validate( $type_slug, $data, $types, $docs );

		if ( ! empty( $errors ) ) {
			$this->fail( $return, $errors, $old );
		}

		$application_id = MMS_Applications::create( $type_slug );
		if ( ! $application_id ) {
			$this->fail( $return, array( __( 'The application could not be saved. Please try again.', 'mms-membership' ) ), $old );
		}

		$type    = $types[ $type_slug ];
		$uploads = $this->handle_uploads( $application_id, $type, $docs, $data );

		if ( is_wp_error( $uploads ) ) {
			$this->fail( $return, $uploads->get_error_messages(), $old );
		}

		MMS_Applications::save_meta( $application_id, $data );

		foreach ( $uploads as $document ) {
			MMS_Applications::add_document( $application_id, $document );
		}

		$reference = MMS_Applications::mark_submitted( $application_id );

		MMS_Applications::log(
			$application_id,
			'submitted',
			sprintf(
				/* translators: 1: membership type label, 2: number of documents */
				__( 'Application submitted online (%1$s, %2$d document(s)).', 'mms-membership' ),
				$type['label'],
				count( $uploads )
			)
		);

		$this->send_notifications( $application_id, $reference, $type, $data );

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_ref' => rawurlencode( $reference ),
				),
				$return
			)
		);
		exit;
	}

	/**
	 * Validate the submitted values.
	 *
	 * @param string $type_slug Selected membership type.
	 * @param array  $data Sanitised field values.
	 * @param array  $types Membership types.
	 * @param array  $docs Document catalogue.
	 * @return array<int,string>
	 */
	private function validate( $type_slug, $data, $types, $docs ) {
		$errors = array();

		if ( '' === $type_slug || ! isset( $types[ $type_slug ] ) ) {
			$errors[] = __( 'Please choose a membership type.', 'mms-membership' );
		}

		$required = array(
			'company_name'    => __( 'Company name', 'mms-membership' ),
			'company_reg_no'  => __( 'Company registration number', 'mms-membership' ),
			'company_state'   => __( 'State', 'mms-membership' ),
			'company_address' => __( 'Company address', 'mms-membership' ),
			'company_phone'   => __( 'Company phone', 'mms-membership' ),
			'company_email'   => __( 'Company email', 'mms-membership' ),
			'pic_name'        => __( 'Person In Charge name', 'mms-membership' ),
			'pic_ic'          => __( 'Person In Charge IC / passport number', 'mms-membership' ),
			'pic_position'    => __( 'Person In Charge position', 'mms-membership' ),
			'pic_email'       => __( 'Person In Charge email', 'mms-membership' ),
			'pic_phone'       => __( 'Person In Charge phone', 'mms-membership' ),
		);

		foreach ( $required as $field => $label ) {
			if ( '' === trim( (string) $data[ $field ] ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is required.', 'mms-membership' ), $label );
			}
		}

		if ( '' !== $data['company_email'] && ! is_email( $data['company_email'] ) ) {
			$errors[] = __( 'The company email address is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['pic_email'] && ! is_email( $data['pic_email'] ) ) {
			$errors[] = __( 'The Person In Charge email address is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['pic_ic'] && ! preg_match( '/^\d{12}$/', $data['pic_ic'] ) ) {
			$errors[] = __( 'The IC number must contain 12 digits.', 'mms-membership' );
		}

		if ( '' !== $data['company_phone'] && ! $this->is_phone( $data['company_phone'] ) ) {
			$errors[] = __( 'The company phone number is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['pic_phone'] && ! $this->is_phone( $data['pic_phone'] ) ) {
			$errors[] = __( 'The Person In Charge phone number is not valid.', 'mms-membership' );
		}

		if ( '' !== $data['company_website'] && ! filter_var( $data['company_website'], FILTER_VALIDATE_URL ) ) {
			$errors[] = __( 'The company website must be a full URL (including https://).', 'mms-membership' );
		}

		if ( '' !== $data['company_state'] && ! in_array( $data['company_state'], wp_list_pluck( MMS_Membership_Types::get_states(), 'name' ), true ) ) {
			$errors[] = __( 'Choose a valid state.', 'mms-membership' );
		}

		if ( '' !== $data['company_reg_no'] && MMS_Applications::meta_exists( 'company_reg_no', $data['company_reg_no'] ) ) {
			$errors[] = __( 'An application with this company registration number already exists.', 'mms-membership' );
		}

		if ( '' !== $data['pic_email'] && MMS_Applications::meta_exists( 'pic_email', $data['pic_email'] ) ) {
			$errors[] = __( 'An application with this Person In Charge email already exists.', 'mms-membership' );
		}

		if ( empty( $_POST['mms_terms'] ) ) {
			$errors[] = __( 'Please confirm that the information is accurate and that you accept the terms.', 'mms-membership' );
		}

		if ( isset( $types[ $type_slug ] ) ) {
			$type = $types[ $type_slug ];

			foreach ( (array) $type['required_docs'] as $key ) {
				$label = isset( $docs[ $key ] ) ? $docs[ $key ]['label'] : $key;
				if ( ! isset( $_FILES[ 'mms_doc_' . $key ] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES[ 'mms_doc_' . $key ]['error'] ) {
					/* translators: %s: document name */
					$errors[] = sprintf( __( 'Please upload: %s', 'mms-membership' ), $label );
				}
			}

			$allowed = array_merge( (array) $type['required_docs'], (array) $type['optional_docs'] );
			foreach ( $allowed as $key ) {
				if ( ! isset( $docs[ $key ], $_FILES[ 'mms_doc_' . $key ] ) ) {
					continue;
				}
				$file = $_FILES[ 'mms_doc_' . $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
					continue;
				}
				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					$errors[] = sprintf(
						/* translators: %s: document name */
						__( 'The file for “%s” could not be uploaded. Please try again.', 'mms-membership' ),
						$docs[ $key ]['label']
					);
					continue;
				}
				$check = MMS_Files::check( $file, $docs[ $key ] );
				if ( is_wp_error( $check ) ) {
					$errors[] = sprintf(
						/* translators: 1: document name, 2: error message */
						__( '%1$s: %2$s', 'mms-membership' ),
						$docs[ $key ]['label'],
						$check->get_error_message()
					);
				}
			}
		}

		/**
		 * Filter validation errors before the application is saved.
		 *
		 * @param array $errors Error messages.
		 * @param array $data   Submitted data.
		 */
		return apply_filters( 'mms_registration_errors', $errors, $data );
	}

	/**
	 * Move the uploaded documents into uploads/mms-documents/{application_id}/.
	 *
	 * @param int   $application_id Application ID.
	 * @param array $type Membership type definition.
	 * @param array $docs Document catalogue.
	 * @param array $data Applicant data.
	 * @return array<int,array<string,mixed>>|WP_Error Stored documents.
	 */
	private function handle_uploads( $application_id, $type, $docs, $data ) {
		$keys   = array_merge( (array) $type['required_docs'], (array) $type['optional_docs'] );
		$stored = array();

		foreach ( $keys as $key ) {
			$field = 'mms_doc_' . $key;
			if ( ! isset( $docs[ $key ], $_FILES[ $field ] ) ) {
				continue;
			}

			$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}

			$check = MMS_Files::check( $file, $docs[ $key ] );
			if ( is_wp_error( $check ) ) {
				return $check;
			}

			$document = MMS_Files::store( $file, $docs[ $key ], $key, $application_id );
			if ( is_wp_error( $document ) ) {
				return $document;
			}

			$stored[] = $document;
		}

		return $stored;
	}

	/**
	 * Send the acknowledgement email to the applicant and a notice to the site admin.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $reference Reference number.
	 * @param array  $type Membership type definition.
	 * @param array  $data Applicant data.
	 * @return void
	 */
	private function send_notifications( $application_id, $reference, $type, $data ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$body = array(
			sprintf(
				/* translators: %s: applicant name */
				__( 'Dear %s,', 'mms-membership' ),
				$data['pic_name']
			),
			'',
			sprintf(
				/* translators: %s: site name */
				__( 'Thank you for applying for membership with %s.', 'mms-membership' ),
				$site_name
			),
			'',
			sprintf(
				/* translators: %s: reference number */
				__( 'Reference number: %s', 'mms-membership' ),
				$reference
			),
			sprintf(
				/* translators: %s: membership type */
				__( 'Membership type: %s', 'mms-membership' ),
				$type['label']
			),
			'',
			__( 'Your application and documents are now pending review. We will contact you once the review is complete.', 'mms-membership' ),
			'',
			__( 'Please keep this reference number for future enquiries.', 'mms-membership' ),
			'',
			$site_name,
		);

		$sent = wp_mail(
			$data['pic_email'],
			sprintf(
				/* translators: %s: reference number */
				__( 'Application received — %s', 'mms-membership' ),
				$reference
			),
			implode( "\n", $body )
		);

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email && $admin_email !== $data['pic_email'] ) {
			wp_mail(
				$admin_email,
				sprintf(
					/* translators: 1: reference number, 2: company name */
					__( 'New membership application %1$s — %2$s', 'mms-membership' ),
					$reference,
					$data['company_name']
				),
				sprintf(
					/* translators: 1: company name, 2: membership type, 3: reference, 4: admin URL */
					__( "Company: %1\$s\nType: %2\$s\nReference: %3\$s\n\nReview: %4\$s", 'mms-membership' ),
					$data['company_name'],
					$type['label'],
					$reference,
					admin_url( 'admin.php?page=mms-membership' )
				)
			);
		}

		MMS_Applications::log(
			$application_id,
			$sent ? 'email_sent' : 'email_failed',
			$sent
				? __( 'Acknowledgement email sent to the applicant.', 'mms-membership' )
				: __( 'Acknowledgement email could not be sent — check the site mail configuration.', 'mms-membership' )
		);
	}

	/**
	 * Redirect back to the form with errors (and keep the typed values).
	 *
	 * @param string $return Return URL.
	 * @param array  $errors Error messages.
	 * @param array  $old Previously submitted values.
	 * @return void
	 */
	private function fail( $return, $errors, $old = array() ) {
		$key = wp_generate_password( 12, false, false );

		set_transient(
			'mms_form_' . $key,
			array(
				'errors' => array_values( (array) $errors ),
				'old'    => $old,
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'mms_error' => $key,
				),
				$return
			)
		);
		exit;
	}

	/**
	 * Sanitised single-line POST value.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Sanitised multi-line POST value.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private function post_textarea( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Keep digits only, so spaces and dashes in the IC number are accepted.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalise_ic( $value ) {
		return preg_replace( '/[^0-9]/', '', $value );
	}

	/**
	 * Basic Malaysian phone number check (9–12 digits, optional +).
	 *
	 * @param string $value Phone number.
	 * @return bool
	 */
	private function is_phone( $value ) {
		$clean = preg_replace( '/[^0-9+]/', '', $value );

		return (bool) preg_match( '/^\+?[0-9]{9,12}$/', $clean );
	}
}
