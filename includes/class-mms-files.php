<?php
/**
 * Shared document file helpers: MIME checks and storage.
 *
 * @package MMS_Membership
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MMS_Files
 */
class MMS_Files {

	/**
	 * Allowed MIME map for a list of accepted extensions.
	 *
	 * @param string $accepted Comma separated extension list.
	 * @return array<string,string>
	 */
	public static function mimes( $accepted ) {
		$known = array(
			'pdf'  => 'application/pdf',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);

		$mimes = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $accepted ) ) ) ) as $ext ) {
			if ( isset( $known[ $ext ] ) ) {
				$mimes[ $ext ] = $known[ $ext ];
			}
		}

		return $mimes ? $mimes : array( 'pdf' => 'application/pdf' );
	}

	/**
	 * Validate one uploaded file (size, extension and real MIME type).
	 *
	 * @param array $file $_FILES entry.
	 * @param array $definition Document definition (accepted, max_mb).
	 * @return true|WP_Error
	 */
	public static function check( $file, $definition ) {
		$max_bytes = max( 1, (int) $definition['max_mb'] ) * MB_IN_BYTES;

		if ( (int) $file['size'] > $max_bytes ) {
			return new WP_Error(
				'mms_file_too_large',
				sprintf(
					/* translators: %d: maximum size in MB */
					__( 'the file is larger than %d MB.', 'mms-membership' ),
					(int) $definition['max_mb']
				)
			);
		}

		$mimes = self::mimes( $definition['accepted'] );
		$check = wp_check_filetype( $file['name'], $mimes );

		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error(
				'mms_file_type',
				sprintf(
					/* translators: %s: accepted extensions */
					__( 'only these formats are accepted: %s.', 'mms-membership' ),
					strtoupper( str_replace( ',', ', ', $definition['accepted'] ) )
				)
			);
		}

		$real = '';
		if ( function_exists( 'finfo_open' ) && is_file( $file['tmp_name'] ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real = (string) finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );
			}
		}

		if ( '' !== $real && $real !== $check['type'] ) {
			return new WP_Error( 'mms_file_mime', __( 'the file content does not match its extension.', 'mms-membership' ) );
		}

		return true;
	}

	/**
	 * Absolute path for a stored document, guarded inside the uploads folder.
	 *
	 * @param string $stored_path Path relative to wp-content/uploads.
	 * @return string Empty string when the file is missing or outside uploads.
	 */
	public static function absolute_path( $stored_path ) {
		$uploads = wp_upload_dir();
		$base    = realpath( $uploads['basedir'] );
		$path    = realpath( $uploads['basedir'] . '/' . ltrim( (string) $stored_path, '/\\' ) );

		if ( ! $base || ! $path || 0 !== strpos( $path, $base ) || ! is_file( $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Delete a stored document (silently ignores missing files).
	 *
	 * @param string $stored_path Path relative to wp-content/uploads.
	 * @return void
	 */
	public static function delete( $stored_path ) {
		$path = self::absolute_path( $stored_path );

		if ( '' !== $path ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Move an uploaded file into uploads/mms-documents/{application_id}/ and
	 * return the document row data.
	 *
	 * @param array  $file $_FILES entry.
	 * @param array  $definition Document definition (accepted, max_mb).
	 * @param string $doc_key Document type key.
	 * @param int    $application_id Application ID.
	 * @param string $replace_path Existing stored path to remove first (optional).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function store( $file, $definition, $doc_key, $application_id, $replace_path = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( '' !== $replace_path ) {
			self::delete( $replace_path );
		}

		$mimes = self::mimes( $definition['accepted'] );

		$dir_filter = function ( $dirs ) use ( $application_id ) {
			$subdir         = '/mms-documents/' . (int) $application_id;
			$dirs['subdir'] = $subdir;
			$dirs['path']   = $dirs['basedir'] . $subdir;
			$dirs['url']    = $dirs['baseurl'] . $subdir;

			return $dirs;
		};

		add_filter( 'upload_dir', $dir_filter );

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'test_type'                => true,
				'mimes'                    => $mimes,
				'unique_filename_callback' => function ( $dir, $name, $ext ) use ( $doc_key ) {
					return wp_unique_filename( $dir, $doc_key . $ext );
				},
			)
		);

		remove_filter( 'upload_dir', $dir_filter );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return new WP_Error(
				'mms_upload_failed',
				isset( $moved['error'] ) ? $moved['error'] : __( 'The document could not be stored.', 'mms-membership' )
			);
		}

		$base = wp_upload_dir();

		return array(
			'doc_type'      => $doc_key,
			'original_name' => sanitize_file_name( $file['name'] ),
			'stored_path'   => ltrim( str_replace( wp_normalize_path( $base['basedir'] ), '', wp_normalize_path( $moved['file'] ) ), '/' ),
			'mime_type'     => $moved['type'],
			'file_size'     => (int) $file['size'],
		);
	}
}
