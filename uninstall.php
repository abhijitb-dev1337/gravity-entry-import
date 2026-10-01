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
 * Recursively removes a directory and everything inside it.
 *
 * Needed specifically for gei/files/{job_id}/ (see GEI_Storage::get_files_dir(),
 * added in 1.4.0 for the File Upload column's "local, pre-staged file" source
 * mode): unlike every other file this plugin ever writes, a pre-staged file's
 * name is whatever the admin who staged it chose, and there can be any number
 * of per-job subdirectories underneath gei/files/ - so, unlike the flat,
 * single-level sweep in gei_uninstall_site() below (which only ever has to
 * assume a fixed, flat set of {job_id}.csv / {job_id}-failed.csv filenames),
 * this has to descend into an arbitrary tree instead.
 *
 * @param string $gei_tree_dir Absolute path to the directory to remove.
 * @return void
 */
function gei_uninstall_remove_tree( $gei_tree_dir ) {
	if ( ! is_dir( $gei_tree_dir ) ) {
		return;
	}

	foreach ( (array) glob( trailingslashit( $gei_tree_dir ) . '*' ) as $gei_tree_entry ) {
		if ( is_dir( $gei_tree_entry ) ) {
			gei_uninstall_remove_tree( $gei_tree_entry );
		} elseif ( is_file( $gei_tree_entry ) ) {
			wp_delete_file( $gei_tree_entry );
		}
	}

	// glob('*') skips dotfiles, so the per-directory .htaccess guard (see
	// GEI_Storage::protect_dir()) is removed explicitly, the same reasoning
	// gei_uninstall_site() already applies to gei/.htaccess itself.
	$gei_tree_htaccess = trailingslashit( $gei_tree_dir ) . '.htaccess';
	if ( file_exists( $gei_tree_htaccess ) ) {
		wp_delete_file( $gei_tree_htaccess );
	}

	// Leaves the directory in place if anything unexpected still lives in it,
	// matching gei_uninstall_site()'s own reasoning for its final @rmdir().
	@rmdir( $gei_tree_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * Removes this site's importer options and uploaded CSVs.
 *
 * @return void
 */
function gei_uninstall_site() {
	global $wpdb;

	// gei_templates_ and gei_history_ are included alongside the job/lock
	// prefixes so a saved mapping template (see GEI_Templates) or a
	// completed-import history entry (see GEI_History) never survives an
	// uninstall as an orphaned option - both are deliberately excluded from
	// the daily gei_cleanup cron (durable, reusable records, not throwaway
	// job state - see each class's own DocBlock), so this sweep is the only
	// automatic cleanup either ever gets.
	foreach ( array( 'gei_job_', 'gei_lock_', 'gei_templates_', 'gei_history_' ) as $gei_prefix ) {
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

	// gei/files/ (see GEI_Storage::get_files_dir(), added in 1.4.0) holds an
	// arbitrary tree of per-job subdirectories, not the flat set of files the
	// sweep below assumes - removed first, with its own recursive helper,
	// so the flat sweep's is_file() checks correctly skip what is left of it
	// (a directory, not a file) and the final rmdir() further down is not
	// left failing silently forever because a job's staged files were never
	// cleared out from underneath it.
	gei_uninstall_remove_tree( trailingslashit( $gei_dir ) . 'files' );

	// glob('*') skips the dotfiles, and the leftover .htaccess would then make
	// the rmdir below fail silently, so the guard files are named explicitly.
	// This sweep is directory-wide rather than per-job-record, so it already
	// picks up every {job_id}-failed.csv left behind by GEI_Importer's
	// failed-rows export (see record_failed_row()) alongside the main
	// {job_id}.csv uploads - nothing job-record-specific needs to change here
	// for that file to be removed on uninstall.
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
