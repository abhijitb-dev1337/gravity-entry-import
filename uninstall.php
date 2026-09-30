<?php
/**
 * Removes all importer data when the plugin is deleted.
 *
 * Imported entries are left alone — they belong to Gravity Forms now. Only the
 * importer's own job state and uploaded CSVs are removed.
 *
 * @package GEI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes this site's importer options and uploaded CSVs.
 *
 * @return void
 */
function gei_uninstall_site() {
	global $wpdb;

	foreach ( array( 'gei_job_', 'gei_lock_' ) as $gei_prefix ) {
		$gei_like = $wpdb->esc_like( $gei_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$gei_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $gei_like ) );

		foreach ( (array) $gei_options as $gei_option ) {
			delete_option( $gei_option );
		}
	}

	$gei_uploads = wp_upload_dir();
	$gei_dir     = trailingslashit( $gei_uploads['basedir'] ) . 'gei';

	if ( ! is_dir( $gei_dir ) ) {
		return;
	}

	// glob('*') skips the dotfiles, and the leftover .htaccess would then make
	// the rmdir below fail silently, so the guard files are named explicitly.
	$gei_files = array_merge(
		(array) glob( trailingslashit( $gei_dir ) . '*' ),
		array(
			trailingslashit( $gei_dir ) . '.htaccess',
		)
	);

	foreach ( $gei_files as $gei_file ) {
		if ( is_file( $gei_file ) ) {
			wp_delete_file( $gei_file );
		}
	}

	// Leaves the directory in place if anything unexpected still lives in it.
	@rmdir( $gei_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( is_multisite() ) {
	$gei_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $gei_sites as $gei_site_id ) {
		switch_to_blog( (int) $gei_site_id );
		gei_uninstall_site();
		restore_current_blog();
	}
} else {
	gei_uninstall_site();
}
