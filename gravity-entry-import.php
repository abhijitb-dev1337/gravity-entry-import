<?php
/**
 * Plugin Name:       Gravity Entry Import
 * Description:       Imports entries into Gravity Forms from a CSV file, with column-to-field mapping and batched, resumable processing.
 * Version:           1.5.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gravity-entry-import
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

define( 'GEI_VERSION', '1.5.1' );
define( 'GEI_FILE', __FILE__ );
define( 'GEI_PATH', plugin_dir_path( __FILE__ ) );
define( 'GEI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimum Gravity Forms version required for the GFAPI calls this plugin makes.
 */
define( 'GEI_MIN_GF_VERSION', '2.4' );

/**
 * Determines whether a compatible Gravity Forms install is active.
 *
 * @since 1.0.0
 *
 * @return bool True when Gravity Forms is active and new enough.
 */
function gei_is_gf_available() {
	if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFCommon' ) ) {
		return false;
	}

	return version_compare( GFCommon::$version, GEI_MIN_GF_VERSION, '>=' );
}

/**
 * Prints an admin notice when Gravity Forms is missing or too old.
 *
 * @since 1.0.0
 *
 * @return void
 */
function gei_missing_gf_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported Gravity Forms version. */
				__( 'Gravity Entry Import requires Gravity Forms %s or newer. The importer is inactive until Gravity Forms is installed and activated.', 'gravity-entry-import' ),
				GEI_MIN_GF_VERSION
			)
		)
	);
}

/**
 * Boots the plugin once all other plugins have loaded.
 *
 * Gravity Forms registers GFAPI on `plugins_loaded`, so the availability check
 * has to run after that point rather than at file-include time.
 *
 * @since 1.0.0
 *
 * @return void
 */
function gei_bootstrap() {
	if ( ! gei_is_gf_available() ) {
		add_action( 'admin_notices', 'gei_missing_gf_notice' );
		return;
	}

	require_once GEI_PATH . 'includes/class-gei-storage.php';
	require_once GEI_PATH . 'includes/class-gei-templates.php';
	require_once GEI_PATH . 'includes/class-gei-history.php';
	require_once GEI_PATH . 'includes/class-gei-csv-reader.php';
	require_once GEI_PATH . 'includes/class-gei-row-filter.php';
	require_once GEI_PATH . 'includes/class-gei-mapper.php';
	require_once GEI_PATH . 'includes/class-gei-file-field.php';
	require_once GEI_PATH . 'includes/class-gei-form-builder.php';
	require_once GEI_PATH . 'includes/class-gei-importer.php';
	require_once GEI_PATH . 'includes/class-gei-validator.php';
	require_once GEI_PATH . 'includes/class-gei-admin.php';
	require_once GEI_PATH . 'includes/class-gei-ajax.php';

	GEI_Admin::init();
	GEI_Ajax::init();
}
add_action( 'plugins_loaded', 'gei_bootstrap', 20 );

/**
 * Creates the protected upload directory and schedules cleanup on activation.
 *
 * @since 1.0.0
 *
 * @return void
 */
function gei_activate() {
	require_once GEI_PATH . 'includes/class-gei-storage.php';
	GEI_Storage::ensure_upload_dir();

	if ( ! wp_next_scheduled( 'gei_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'gei_cleanup' );
	}
}
register_activation_hook( __FILE__, 'gei_activate' );

/**
 * Runs the daily cleanup of abandoned import jobs and their CSVs.
 *
 * Registered independently of gei_bootstrap()/Gravity Forms' availability:
 * GEI_Storage has no Gravity Forms dependency of its own, and a job's CSV -
 * which routinely holds personal data - would otherwise sit on disk
 * indefinitely if Gravity Forms happens to be deactivated when it goes stale.
 * Without this, an abandoned upload (started, then never finished) would only
 * ever get cleaned up by the next unrelated upload or a plugin deactivation,
 * neither of which is guaranteed to happen on a quiet site.
 *
 * @since 1.1.0
 *
 * @return void
 */
function gei_run_scheduled_cleanup() {
	require_once GEI_PATH . 'includes/class-gei-storage.php';
	GEI_Storage::prune_stale_jobs();
}
add_action( 'gei_cleanup', 'gei_run_scheduled_cleanup' );

/**
 * Prunes abandoned import jobs and unschedules cleanup on deactivation.
 *
 * In-flight jobs newer than the prune window survive, so an import interrupted
 * by a deactivation can still be resumed. Full cleanup happens on uninstall.
 *
 * @since 1.0.0
 *
 * @return void
 */
function gei_deactivate() {
	require_once GEI_PATH . 'includes/class-gei-storage.php';
	GEI_Storage::prune_stale_jobs();
	wp_clear_scheduled_hook( 'gei_cleanup' );
}
register_deactivation_hook( __FILE__, 'gei_deactivate' );
