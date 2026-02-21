<?php
/**
 * Plugin Name: PlugShip Receiver
 * Plugin URI: https://github.com/plugship-receiver
 * Description: Companion plugin for the plugship CLI. Adds a REST endpoint to receive and install plugin ZIP files.
 * Version: 1.0.1
 * Author: PlugShip
 * License: MIT
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLUGSHIP_VERSION', '1.0.1' );
define( 'PLUGSHIP_MAX_UPLOAD_SIZE', 50 * 1024 * 1024 ); // 50 MB

add_action( 'rest_api_init', function () {
	register_rest_route( 'plugship/v1', '/status', array(
		'methods'             => 'GET',
		'callback'            => 'plugship_status',
		'permission_callback' => 'plugship_check_permissions',
	) );

	register_rest_route( 'plugship/v1', '/deploy', array(
		'methods'             => 'POST',
		'callback'            => 'plugship_deploy',
		'permission_callback' => 'plugship_check_permissions',
	) );
} );

function plugship_check_permissions() {
	return current_user_can( 'install_plugins' );
}

function plugship_status() {
	return new WP_REST_Response( array(
		'status'  => 'ok',
		'version' => PLUGSHIP_VERSION,
	), 200 );
}

function plugship_deploy( WP_REST_Request $request ) {
	// Suppress ALL PHP error output for the entire function
	// to prevent HTML errors from corrupting JSON response
	$original_display_errors = ini_get( 'display_errors' );
	$original_error_reporting = error_reporting();
	ini_set( 'display_errors', 0 );

	// Capture any errors that happen
	$captured_errors = array();
	set_error_handler( function ( $errno, $errstr, $errfile, $errline ) use ( &$captured_errors ) {
		$captured_errors[] = $errstr;
		return true; // Prevent default error handler
	} );

	$response = plugship_do_deploy( $request, $captured_errors );

	// Restore error handling
	restore_error_handler();
	ini_set( 'display_errors', $original_display_errors );
	error_reporting( $original_error_reporting );

	// Clean any stray output that leaked through
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}

	return $response;
}

function plugship_do_deploy( WP_REST_Request $request, &$captured_errors ) {
	$files = $request->get_file_params();

	if ( empty( $files['plugin'] ) ) {
		return new WP_Error(
			'missing_file',
			'No plugin ZIP file provided.',
			array( 'status' => 400 )
		);
	}

	$file = $files['plugin'];

	if ( $file['error'] !== UPLOAD_ERR_OK ) {
		return new WP_Error(
			'upload_error',
			'File upload failed with error code: ' . $file['error'],
			array( 'status' => 400 )
		);
	}

	// Validate file size.
	$file_size = filesize( $file['tmp_name'] );
	if ( $file_size === false || $file_size > PLUGSHIP_MAX_UPLOAD_SIZE ) {
		return new WP_Error(
			'file_too_large',
			sprintf( 'File exceeds maximum upload size of %d MB.', PLUGSHIP_MAX_UPLOAD_SIZE / 1024 / 1024 ),
			array( 'status' => 400 )
		);
	}

	// Validate MIME type.
	$mime = mime_content_type( $file['tmp_name'] );
	if ( ! in_array( $mime, array( 'application/zip', 'application/x-zip-compressed' ), true ) ) {
		return new WP_Error(
			'invalid_file_type',
			'Uploaded file is not a valid ZIP archive.',
			array( 'status' => 400 )
		);
	}

	// Validate ZIP integrity and inspect contents.
	$zip = new ZipArchive();
	$zip_result = $zip->open( $file['tmp_name'], ZipArchive::RDONLY );
	if ( $zip_result !== true ) {
		return new WP_Error(
			'invalid_zip',
			'Uploaded file is not a valid ZIP archive.',
			array( 'status' => 400 )
		);
	}

	// Check for path traversal and symlinks in ZIP entries.
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$entry = $zip->getNameIndex( $i );

		if ( strpos( $entry, '..' ) !== false || strpos( $entry, '~' ) === 0 ) {
			$zip->close();
			return new WP_Error(
				'malicious_zip',
				'ZIP archive contains invalid path entries.',
				array( 'status' => 400 )
			);
		}

		// Reject absolute paths.
		if ( preg_match( '#^[/\\\\]#', $entry ) ) {
			$zip->close();
			return new WP_Error(
				'malicious_zip',
				'ZIP archive contains absolute path entries.',
				array( 'status' => 400 )
			);
		}
	}
	$zip->close();

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	// Use a safe generated name instead of user-supplied filename.
	$tmp_path = wp_tempnam( 'plugship_' . wp_generate_password( 8, false ) . '.zip' );
	if ( ! move_uploaded_file( $file['tmp_name'], $tmp_path ) ) {
		return new WP_Error(
			'move_failed',
			'Failed to move uploaded file.',
			array( 'status' => 500 )
		);
	}

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );

	ob_start();
	$result = $upgrader->install( $tmp_path, array(
		'overwrite_package' => true,
	) );
	ob_end_clean();

	@unlink( $tmp_path );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'install_failed',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	if ( $result === false ) {
		$errors = $skin->get_errors();
		$msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Plugin installation failed.';
		return new WP_Error(
			'install_failed',
			$msg,
			array( 'status' => 500 )
		);
	}

	// Get installed plugin info.
	$plugin_file = $upgrader->plugin_info();
	$plugin_data = array();
	$activated   = false;
	$activation_error = null;

	if ( $plugin_file ) {
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );

		// Check if plugin is already active (auto-activated during install)
		$activated = is_plugin_active( $plugin_file );

		// If not active and activation requested, try to activate
		if ( ! $activated ) {
			$activate = $request->get_param( 'activate' );
			if ( $activate !== 'false' && $activate !== false ) {
				// Clear any errors captured so far (from install phase)
				$errors_before = count( $captured_errors );

				ob_start();
				$activate_result = activate_plugin( $plugin_file );
				ob_end_clean();

				if ( is_wp_error( $activate_result ) ) {
					$activation_error = $activate_result->get_error_message();
				} else {
					$activated = is_plugin_active( $plugin_file );
					if ( ! $activated ) {
						// Activation returned no error but plugin isn't active
						$new_errors = array_slice( $captured_errors, $errors_before );
						$activation_error = ! empty( $new_errors )
							? implode( '; ', $new_errors )
							: 'Plugin activation failed silently.';
					}
				}
			}
		}
	}

	// Collect any PHP warnings/errors that occurred
	$warnings = ! empty( $captured_errors ) ? implode( '; ', array_unique( $captured_errors ) ) : null;

	return new WP_REST_Response( array(
		'success'          => true,
		'plugin'           => $plugin_file,
		'name'             => $plugin_data['Name'] ?? '',
		'version'          => $plugin_data['Version'] ?? '',
		'activated'        => $activated,
		'activation_error' => $activation_error,
		'warnings'         => $warnings,
	), 200 );
}
