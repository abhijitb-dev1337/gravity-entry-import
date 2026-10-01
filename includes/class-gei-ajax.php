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
		add_action( 'wp_ajax_gei_process_validation_batch', array( __CLASS__, 'process_validation_batch' ) );
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

	/**
	 * Processes one dry-run validation batch and returns progress as JSON.
	 *
	 * Mirrors process_batch() exactly - nonce, then capability, then (inside
	 * GEI_Validator::validate_batch() itself) job ownership, in that order -
	 * for the same job_id-from-the-browser reasons. Kept as a distinct action
	 * rather than a mode flag on gei_process_batch so the two can never be
	 * confused client-side, and so GEI_Validator::validate_batch() can never
	 * be reached from a request that thinks it is driving a real import.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function process_validation_batch() {
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

		$result = GEI_Validator::validate_batch( $job_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}
}
