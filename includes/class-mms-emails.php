<?php
/**
 * Customisable transactional email templates.
 *
 * Wording is stored in `mms_settings['emails']` and edited in MMS → Settings.
 * Only the message body is customisable; the branded shell, details table and
 * action buttons stay under plugin control.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Emails
 */
class MMS_Emails {

	const OPTION = 'mms_settings';

	/**
	 * Supported templates: key => label.
	 *
	 * @return array<string,string>
	 */
	public static function template_keys() {
		return array(
			'approved' => __( 'Application approved — payment pending', 'mms-membership' ),
			'payment'  => __( 'Payment received — receipt ready', 'mms-membership' ),
		);
	}

	/**
	 * Default subject and body for each template.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function defaults() {
		return array(
			'approved' => array(
				'subject' => __( 'Application {reference} approved — payment pending', 'mms-membership' ),
				'body'    => '<p>' . sprintf(
					/* translators: %s: applicant name */
					esc_html__( 'Dear %s,', 'mms-membership' ),
					'{name}'
				) . '</p>'
					. '<p>' . esc_html__( 'Good news — your membership application has been approved. Your membership will be activated once payment is received.', 'mms-membership' ) . '</p>'
					. '<p>' . esc_html__( 'Please set your password using the button below, then log in to the member portal to complete the payment.', 'mms-membership' ) . '</p>',
			),
			'payment'  => array(
				'subject' => __( 'Payment received — membership {membership_no}', 'mms-membership' ),
				'body'    => '<p>' . sprintf(
					/* translators: %s: applicant name */
					esc_html__( 'Dear %s,', 'mms-membership' ),
					'{name}'
				) . '</p>'
					. '<p>' . esc_html__( 'Thank you. Your payment for {company_name} has been received. Your membership is active until {expiry_date}.', 'mms-membership' ) . '</p>',
			),
		);
	}

	/**
	 * Placeholders available per template, with a short description.
	 *
	 * @param string $key Template key.
	 * @return array<string,string>
	 */
	public static function placeholders( $key ) {
		$common = array(
			'{name}'        => __( 'Person In Charge name', 'mms-membership' ),
			'{company_name}' => __( 'Company name', 'mms-membership' ),
			'{reference}'   => __( 'Application reference number', 'mms-membership' ),
			'{membership_type}' => __( 'Membership type', 'mms-membership' ),
			'{amount}'      => __( 'Amount (e.g. RM 300.00)', 'mms-membership' ),
			'{login_url}'   => __( 'Member login URL', 'mms-membership' ),
			'{portal_url}'  => __( 'Member dashboard URL', 'mms-membership' ),
			'{site_name}'   => __( 'Site name', 'mms-membership' ),
		);

		if ( 'payment' === $key ) {
			$common += array(
				'{invoice_no}'   => __( 'Invoice number', 'mms-membership' ),
				'{membership_no}' => __( 'Membership number', 'mms-membership' ),
				'{start_date}'   => __( 'Membership start date', 'mms-membership' ),
				'{expiry_date}'  => __( 'Membership expiry date', 'mms-membership' ),
			);
		}

		return $common;
	}

	/**
	 * Get the active subject and body for a template, falling back to defaults.
	 *
	 * @param string $key Template key.
	 * @return array{subject:string,body:string}|null
	 */
	public static function get( $key ) {
		$defaults = self::defaults();

		if ( ! isset( $defaults[ $key ] ) ) {
			return null;
		}

		$settings = get_option( self::OPTION, array() );
		$stored   = ( is_array( $settings ) && isset( $settings['emails'][ $key ] ) && is_array( $settings['emails'][ $key ] ) )
			? $settings['emails'][ $key ]
			: array();

		$subject = isset( $stored['subject'] ) ? trim( (string) $stored['subject'] ) : '';
		$body    = isset( $stored['body'] ) ? trim( (string) $stored['body'] ) : '';

		return array(
			'subject' => '' !== $subject ? $subject : $defaults[ $key ]['subject'],
			'body'    => '' !== $body ? $body : $defaults[ $key ]['body'],
		);
	}

	/**
	 * Render the subject with escaped, plain-text placeholder values.
	 *
	 * @param string              $key  Template key.
	 * @param array<string,mixed> $args Placeholder values.
	 * @return string
	 */
	public static function subject( $key, $args ) {
		$template = self::get( $key );

		return $template ? self::render( $template['subject'], $args, false ) : '';
	}

	/**
	 * Render the body with escaped placeholder values.
	 *
	 * @param string              $key  Template key.
	 * @param array<string,mixed> $args Placeholder values.
	 * @return string
	 */
	public static function body( $key, $args ) {
		$template = self::get( $key );

		return $template ? self::render( $template['body'], $args, true ) : '';
	}

	/**
	 * Replace `{placeholder}` tokens.
	 *
	 * @param string              $text   Template text.
	 * @param array<string,mixed> $args   Placeholder values.
	 * @param bool                $is_html Whether the output is HTML.
	 * @return string
	 */
	private static function render( $text, $args, $is_html ) {
		$values = array(
			'name'            => isset( $args['name'] ) ? (string) $args['name'] : '',
			'company_name'    => isset( $args['company_name'] ) ? (string) $args['company_name'] : '',
			'reference'       => isset( $args['reference'] ) ? (string) $args['reference'] : '',
			'membership_type' => isset( $args['membership_type'] ) ? (string) $args['membership_type'] : '',
			'amount'          => isset( $args['amount'] ) ? (string) $args['amount'] : '',
			'invoice_no'      => isset( $args['invoice_no'] ) ? (string) $args['invoice_no'] : '',
			'membership_no'   => isset( $args['membership_no'] ) ? (string) $args['membership_no'] : '',
			'start_date'      => isset( $args['start_date'] ) ? (string) $args['start_date'] : '',
			'expiry_date'     => isset( $args['expiry_date'] ) ? (string) $args['expiry_date'] : '',
			'login_url'       => MMS_Members::login_url(),
			'portal_url'      => MMS_Members::portal_url(),
			'site_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$url_keys = array( 'login_url', 'portal_url' );
		$replace  = array();

		foreach ( $values as $name => $value ) {
			if ( $is_html ) {
				$replace[ '{' . $name . '}' ] = in_array( $name, $url_keys, true ) ? esc_url( $value ) : esc_html( $value );
			} else {
				$replace[ '{' . $name . '}' ] = wp_strip_all_tags( $value );
			}
		}

		return strtr( $text, $replace );
	}
}
