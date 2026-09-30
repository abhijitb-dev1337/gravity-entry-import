<?php
/**
 * AJAX endpoint driving the batched import.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the admin-ajax request for each batch.
 *
 * @since 1.0.0
 */
class GEI_Ajax {

	/**
	 * Registers the AJAX handler.
	 *
	 * Only the logged-in variant is registered; there is no reason for this
	 * endpoint to be reachable by unauthenticated visitors.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_gei_process_batch', array( __CLASS__, 'process_batch' ) );
	}

	/**
	 * Processes one batch and returns progress as JSON.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function process_batch() {
		check_ajax_referer( 'gei_ajax', 'nonce' );

		if ( ! GEI_Admin::current_user_can_import() ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to import entries.', 'gravity-entry-import' ) ),
				403
			);
		}

		$job_id = isset( $_POST['job'] ) ? GEI_Storage::sanitize_job_id( wp_unslash( $_POST['job'] ) ) : '';

		if ( '' === $job_id ) {
			wp_send_json_error(
				array( 'message' => __( 'No import job was supplied.', 'gravity-entry-import' ) ),
				400
			);
		}

		$result = GEI_Importer::process_batch( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}
}
