<?php
/**
 * Admin screens for the importer.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin page and renders the three import steps.
 *
 * @since 1.0.0
 */
class GEI_Admin {

	/**
	 * Name of the tab this importer adds to Gravity Forms' own
	 * Forms -> Import/Export screen, alongside its built-in Export Entries,
	 * Export Forms and Import Forms tabs. Matched against ?subview= and used
	 * as the gform_export_page_{$subview} action Gravity Forms fires for any
	 * tab it does not know about itself.
	 *
	 * @var string
	 */
	const SUBVIEW = 'gei_import_entries';

	/**
	 * Nonce action for the upload step.
	 *
	 * @var string
	 */
	const NONCE_UPLOAD = 'gei_upload';

	/**
	 * Nonce action for the mapping step.
	 *
	 * @var string
	 */
	const NONCE_MAP = 'gei_map';

	/**
	 * Hooks the admin screens.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gform_export_menu', array( __CLASS__, 'add_export_tab' ) );
		add_action( 'gform_export_page_' . self::SUBVIEW, array( __CLASS__, 'render_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Returns the capability required to run an import.
	 *
	 * @since 1.0.0
	 *
	 * @return string Capability name.
	 */
	public static function get_capability() {
		/**
		 * Filters the capability required to import entries.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 */
		return apply_filters( 'gei_capability', 'gravityforms_edit_entries' );
	}

	/**
	 * Determines whether the current user may run an import.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the user is permitted.
	 */
	public static function current_user_can_import() {
		// Gravity Forms does not grant its granular capabilities (such as
		// gravityforms_edit_entries) to administrators automatically - only
		// gform_full_access, which GFCommon::user_has_cap() resolves to true
		// for admins when no role-management plugin is active. Checking the
		// granular capability alone therefore hides this page from every
		// admin on a default install. GFCommon::current_user_can_any() is the
		// API Gravity Forms' own add-ons use for exactly this check.
		if ( class_exists( 'GFCommon' ) ) {
			return GFCommon::current_user_can_any( self::get_capability() );
		}

		return current_user_can( self::get_capability() ) || current_user_can( 'gform_full_access' );
	}

	/**
	 * Determines whether the current user may drive or resume a given job.
	 *
	 * current_user_can_import() alone only proves the requester holds the
	 * import capability - it says nothing about whether this is *their* job.
	 * GEI_Storage::save_job() already records the ID of whoever started the
	 * import, so comparing against that closes the gap where any other,
	 * equally-privileged user who learns a job's ID (a shared browser
	 * history, a referrer header, server logs, a screen-share) could drive or
	 * resume an import they did not start. Job IDs themselves are
	 * unguessable (wp_generate_password(32, false, false)), so this is
	 * defence in depth against a leaked ID, not a guard against brute force.
	 *
	 * gform_full_access is treated as a strictly higher trust level, the same
	 * escape hatch current_user_can_import() grants over the plain import
	 * capability, so a site admin can still step in to help with or take
	 * over a stuck import.
	 *
	 * A job saved before this check existed - or created by the CLI
	 * test-harness scripts used during this plugin's own development, which
	 * never run as a real logged-in user - has no usable owner on record.
	 * Such a job is treated as unowned (anyone who passes
	 * current_user_can_import() may still act on it) rather than as
	 * belonging to no one and therefore off limits to everyone: every job
	 * like that predates any expectation of per-user isolation, and refusing
	 * it outright would strand an import that was already in flight when
	 * this fix shipped.
	 *
	 * @since 1.1.1
	 *
	 * @param array $job Job state, as returned by GEI_Storage::get_job().
	 * @return bool True when the current user may act on this job.
	 */
	public static function current_user_owns_job( array $job ) {
		// Mirrors current_user_can_import()'s own reasoning for checking
		// gform_full_access directly: GFCommon::user_has_cap() is what
		// resolves that capability to true for admins.
		if ( class_exists( 'GFCommon' ) ? GFCommon::current_user_can_any( 'gform_full_access' ) : current_user_can( 'gform_full_access' ) ) {
			return true;
		}

		$owner_id = isset( $job['user_id'] ) ? (int) $job['user_id'] : 0;

		if ( 0 === $owner_id ) {
			return true;
		}

		return ( get_current_user_id() === $owner_id );
	}

	/**
	 * Adds an "Import Entries" tab to Gravity Forms' Import/Export screen.
	 *
	 * Hooked to the gform_export_menu filter, the same extension point
	 * Gravity Forms exposes for third-party tabs on that screen. Each tab
	 * Gravity Forms itself registers there follows the same pattern: a
	 * permission check gating whether the entry is added at all, keyed by a
	 * numeric string so ksort() controls display order.
	 *
	 * @since 1.1.0
	 *
	 * @param array $tabs Existing tabs, keyed by a numeric-string sort order.
	 * @return array Tabs with this importer's entry added, when permitted.
	 */
	public static function add_export_tab( $tabs ) {
		if ( ! self::current_user_can_import() ) {
			return $tabs;
		}

		// A plain down-arrow-into-tray glyph, drawn in the same stroke style
		// as Gravity Forms' own Import Forms tab icon so it doesn't stand out
		// as foreign next to it.
		$icon = '<svg width="24" height="24" role="presentation" focusable="false" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><title>' . esc_attr__( 'import entries', 'gravity-entry-import' ) . '</title><g fill="none" class="nc-icon-wrapper"><path stroke="#111111" stroke-width="1.5" d="M4 15.25h2M8 19.25h8M4 19.25h2"/><path d="M12 4v10m0 0l-3.5-3.5M12 14l3.5-3.5" stroke="#111111" stroke-width="1.5"/></g></svg>';

		// '40' sorts after the built-in tabs (10/20/30) without needing to
		// know whether every one of them is present for the current user.
		$tabs['40'] = array(
			'name'  => self::SUBVIEW,
			'label' => __( 'Import Entries', 'gravity-entry-import' ),
			'icon'  => $icon,
		);

		return $tabs;
	}

	/**
	 * Builds a URL back into this importer's tab.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args Extra query args, e.g. array( 'step' => 'map' ).
	 * @return string Absolute admin URL.
	 */
	protected static function page_url( array $args = array() ) {
		$args = array_merge(
			array(
				'page'    => 'gf_export',
				'subview' => self::SUBVIEW,
			),
			$args
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Determines whether the current request is this importer's tab.
	 *
	 * Unlike a page registered with add_submenu_page(), a Gravity Forms
	 * export tab has no hook suffix of its own to key off - every tab on the
	 * Import/Export screen shares the same "gf_export" page. The subview
	 * query var is what actually distinguishes them.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the current request is this importer's tab.
	 */
	protected static function is_own_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		if ( ! isset( $_GET['page'] ) || 'gf_export' !== $_GET['page'] ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$subview = isset( $_GET['subview'] ) ? sanitize_key( wp_unslash( $_GET['subview'] ) ) : '';

		return ( self::SUBVIEW === $subview );
	}

	/**
	 * Enqueues the importer's admin assets on its own screen only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( ! self::is_own_screen() ) {
			return;
		}

		wp_enqueue_style(
			'gei-admin',
			GEI_URL . 'assets/admin.css',
			array(),
			GEI_VERSION
		);

		wp_enqueue_script(
			'gei-admin',
			GEI_URL . 'assets/admin.js',
			array(),
			GEI_VERSION,
			true
		);

		wp_localize_script(
			'gei-admin',
			'gei',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'gei_ajax' ),
				'i18n'    => array(
					'importing' => __( 'Importing…', 'gravity-entry-import' ),
					'done'      => __( 'Import complete.', 'gravity-entry-import' ),
					'failed'    => __( 'The import stopped because of an error.', 'gravity-entry-import' ),
					'confirm'   => __( 'Start importing entries into this form?', 'gravity-entry-import' ),
				),
			)
		);
	}

	/**
	 * Routes the POST handlers for the upload and mapping steps.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! isset( $_POST['gei_action'] ) ) {
			return;
		}

		if ( ! self::current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import entries.', 'gravity-entry-import' ), 403 );
		}

		$action = sanitize_key( wp_unslash( $_POST['gei_action'] ) );

		if ( 'upload' === $action ) {
			self::handle_upload_post();
			return;
		}

		if ( 'map' === $action ) {
			self::handle_map_post();
		}
	}

	/**
	 * Validates and stores the uploaded CSV, then redirects to mapping.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function handle_upload_post() {
		check_admin_referer( self::NONCE_UPLOAD );

		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

		if ( 0 === $form_id ) {
			self::redirect_with_error( __( 'Choose the form to import into.', 'gravity-entry-import' ) );
		}

		$form = GFAPI::get_form( $form_id );

		if ( empty( $form ) || is_wp_error( $form ) ) {
			self::redirect_with_error( __( 'That form could not be found.', 'gravity-entry-import' ) );
		}

		if ( empty( $_FILES['csv_file'] ) ) {
			self::redirect_with_error( __( 'Choose a CSV file to upload.', 'gravity-entry-import' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Handled and validated inside handle_upload().
		$stored = GEI_Storage::handle_upload( $_FILES['csv_file'] );

		if ( is_wp_error( $stored ) ) {
			self::redirect_with_error( $stored->get_error_message() );
		}

		$delimiter = GEI_CSV_Reader::detect_delimiter( $stored );
		$reader    = new GEI_CSV_Reader( $stored, $delimiter, '"' );
		$header    = $reader->get_header();

		if ( is_wp_error( $header ) ) {
			GEI_Storage::delete_job_file( $stored );
			self::redirect_with_error( $header->get_error_message() );
		}

		// count_rows() does a full sequential scan of the file to populate the
		// progress bar's total. It runs once, synchronously, inside this POST
		// request rather than a batched call, so a very large CSV needs the
		// same best-effort headroom the batch loop gets, or a slow disk / low
		// execution-time limit could time this single request out.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$job_id = basename( $stored, '.csv' );

		GEI_Storage::save_job(
			$job_id,
			array(
				'file'      => $stored,
				'form_id'   => $form_id,
				'delimiter' => $delimiter,
				'header'    => $header,
				'total'     => $reader->count_rows(),
				'offset'    => $reader->get_first_data_offset(),
				'mapping'   => array(),
				'processed' => 0,
				'imported'  => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'errors'    => array(),
				'complete'  => false,
				'created'   => time(),
				'user_id'   => get_current_user_id(),
			)
		);

		GEI_Storage::prune_stale_jobs();

		wp_safe_redirect(
			self::page_url(
				array(
					'step' => 'map',
					'job'  => rawurlencode( $job_id ),
				)
			)
		);
		exit;
	}

	/**
	 * Saves the column mapping and redirects to the run step.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function handle_map_post() {
		check_admin_referer( self::NONCE_MAP );

		$job_id = isset( $_POST['job'] ) ? GEI_Storage::sanitize_job_id( wp_unslash( $_POST['job'] ) ) : '';
		$job    = GEI_Storage::get_job( $job_id );

		if ( null === $job ) {
			self::redirect_with_error( __( 'That import job has expired. Upload the file again.', 'gravity-entry-import' ) );
		}

		// Reuses the "expired" message verbatim rather than a distinct one:
		// see current_user_owns_job() for why a mismatch has to look
		// identical to a job that does not exist.
		if ( ! self::current_user_owns_job( $job ) ) {
			self::redirect_with_error( __( 'That import job has expired. Upload the file again.', 'gravity-entry-import' ) );
		}

		$raw_mapping = isset( $_POST['mapping'] ) ? (array) wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in the loop below.
		$mapping     = array();

		foreach ( $raw_mapping as $target => $column ) {
			$column = sanitize_text_field( $column );

			if ( '' === $column ) {
				continue;
			}

			// Target keys are field IDs ("3"), input IDs ("3.6") or meta ("__ip").
			$target = preg_replace( '/[^A-Za-z0-9._]/', '', (string) $target );

			if ( '' === $target ) {
				continue;
			}

			$mapping[ $target ] = (int) $column;
		}

		if ( empty( $mapping ) ) {
			self::redirect_with_error( __( 'Map at least one column before starting the import.', 'gravity-entry-import' ), $job_id, 'map' );
		}

		$duplicate_field = isset( $_POST['duplicate_field'] ) ? preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['duplicate_field'] ) ) : '';
		$skip_duplicates = ! empty( $_POST['skip_duplicates'] );

		// Deduplicating on a column that was never mapped silently does
		// nothing, which looks identical to "no duplicates found". Refuse the
		// combination rather than let it pass quietly.
		if ( $skip_duplicates && ( '' === $duplicate_field || ! isset( $mapping[ $duplicate_field ] ) ) ) {
			self::redirect_with_error(
				__( 'To skip duplicates, choose a field that is also mapped to a CSV column.', 'gravity-entry-import' ),
				$job_id,
				'map'
			);
		}

		$job['mapping']            = $mapping;
		$job['skip_duplicates']    = $skip_duplicates;
		$job['send_notifications'] = ! empty( $_POST['send_notifications'] );
		$job['duplicate_field']    = $duplicate_field;
		$job['batch_size']         = isset( $_POST['batch_size'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['batch_size'] ) ) ) ) : GEI_Importer::DEFAULT_BATCH_SIZE;

		GEI_Storage::save_job( $job_id, $job );

		wp_safe_redirect(
			self::page_url(
				array(
					'step' => 'run',
					'job'  => rawurlencode( $job_id ),
				)
			)
		);
		exit;
	}

	/**
	 * Redirects back to the importer with an error message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Error text.
	 * @param string $job_id  Optional job ID to return to.
	 * @param string $step    Optional step to return to.
	 * @return void
	 */
	protected static function redirect_with_error( $message, $job_id = '', $step = '' ) {
		$args = array(
			'gei_err' => rawurlencode( $message ),
		);

		if ( '' !== $step ) {
			$args['step'] = $step;
		}

		if ( '' !== $job_id ) {
			$args['job'] = rawurlencode( $job_id );
		}

		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	/**
	 * Renders the importer inside Gravity Forms' Import/Export screen.
	 *
	 * Hooked to gform_export_page_{self::SUBVIEW}, the action Gravity Forms
	 * fires for any tab it does not render itself. GFExport::page_header()
	 * and page_footer() draw the same left-hand tab rail and top chrome as
	 * Export Entries, Export Forms and Import Forms; the markup in between
	 * matches the .gform-settings-panel shell those tabs use, so this tab
	 * looks native rather than bolted on.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function render_tab() {
		if ( ! self::current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import entries.', 'gravity-entry-import' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step routing.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step routing.
		$job_id = isset( $_GET['job'] ) ? GEI_Storage::sanitize_job_id( wp_unslash( $_GET['job'] ) ) : '';

		if ( class_exists( 'GFExport' ) ) {
			GFExport::page_header();
		}

		echo '<div class="gform-settings__content">';
		echo '<div class="gform-settings-panel gform-settings-panel--full gei-tab">';
		printf(
			'<header class="gform-settings-panel__header"><legend class="gform-settings-panel__title">%s</legend></header>',
			esc_html__( 'Import Entries', 'gravity-entry-import' )
		);
		echo '<div class="gform-settings-panel__content">';

		self::render_notice();
		self::render_steps( $step );

		switch ( $step ) {
			case 'map':
				self::render_map_step( $job_id );
				break;

			case 'run':
				self::render_run_step( $job_id );
				break;

			default:
				self::render_upload_step();
				break;
		}

		echo '</div></div></div>';

		if ( class_exists( 'GFExport' ) ) {
			GFExport::page_footer();
		}
	}

	/**
	 * Prints any error passed back through the query string.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only message.
		if ( empty( $_GET['gei_err'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only message.
		$message = sanitize_text_field( wp_unslash( $_GET['gei_err'] ) );

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Renders the step indicator.
	 *
	 * @since 1.0.0
	 *
	 * @param string $current Current step key.
	 * @return void
	 */
	protected static function render_steps( $current ) {
		$steps = array(
			'upload' => __( '1. Upload CSV', 'gravity-entry-import' ),
			'map'    => __( '2. Map columns', 'gravity-entry-import' ),
			'run'    => __( '3. Import', 'gravity-entry-import' ),
		);

		echo '<ol class="gei-steps">';
		foreach ( $steps as $key => $label ) {
			printf(
				'<li class="%s">%s</li>',
				esc_attr( $key === $current ? 'is-active' : '' ),
				esc_html( $label )
			);
		}
		echo '</ol>';
	}

	/**
	 * Renders the upload form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function render_upload_step() {
		$forms = GFAPI::get_forms( true );

		if ( empty( $forms ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'There are no active Gravity Forms to import into yet.', 'gravity-entry-import' )
			);
			return;
		}

		echo '<form method="post" enctype="multipart/form-data" class="gei-card">';
		wp_nonce_field( self::NONCE_UPLOAD );
		echo '<input type="hidden" name="gei_action" value="upload" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gei-form">' . esc_html__( 'Target form', 'gravity-entry-import' ) . '</label></th><td>';
		echo '<select name="form_id" id="gei-form" required>';
		printf( '<option value="">%s</option>', esc_html__( '— Select a form —', 'gravity-entry-import' ) );
		foreach ( $forms as $form ) {
			printf(
				'<option value="%d">%s</option>',
				absint( $form['id'] ),
				esc_html( $form['title'] )
			);
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gei-file">' . esc_html__( 'CSV file', 'gravity-entry-import' ) . '</label></th><td>';
		echo '<input type="file" name="csv_file" id="gei-file" accept=".csv,text/csv,text/plain" required />';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: server upload size limit. */
					__( 'The first row must contain column headers. Maximum upload size on this server: %s.', 'gravity-entry-import' ),
					size_format( wp_max_upload_size() )
				)
			)
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Upload and continue', 'gravity-entry-import' ) );
		echo '</form>';
	}

	/**
	 * Renders the column-mapping form.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	protected static function render_map_step( $job_id ) {
		$job = GEI_Storage::get_job( $job_id );

		if ( null === $job ) {
			self::render_expired_notice();
			return;
		}

		// Same fallback as a missing job: see current_user_owns_job() for why
		// this can't tell an unauthorized user their job ID was valid.
		if ( ! self::current_user_owns_job( $job ) ) {
			self::render_expired_notice();
			return;
		}

		$form = GFAPI::get_form( (int) $job['form_id'] );

		if ( empty( $form ) || is_wp_error( $form ) ) {
			self::render_expired_notice();
			return;
		}

		$targets    = GEI_Mapper::get_targets( $form );
		$header     = (array) $job['header'];
		$suggested  = GEI_Mapper::suggest_mapping( $targets, $header );
		$dupe_opts  = array();

		printf(
			'<p class="gei-summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of rows, 2: form title. */
					__( '%1$d rows found. Importing into "%2$s".', 'gravity-entry-import' ),
					(int) $job['total'],
					$form['title']
				)
			)
		);

		echo '<form method="post" class="gei-card">';
		wp_nonce_field( self::NONCE_MAP );
		echo '<input type="hidden" name="gei_action" value="map" />';
		printf( '<input type="hidden" name="job" value="%s" />', esc_attr( $job_id ) );

		echo '<table class="widefat striped gei-map">';
		echo '<thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Form field', 'gravity-entry-import' ) );
		printf( '<th>%s</th>', esc_html__( 'CSV column', 'gravity-entry-import' ) );
		printf( '<th>%s</th>', esc_html__( 'Sample value', 'gravity-entry-import' ) );
		echo '</tr></thead><tbody>';

		foreach ( $targets as $target ) {
			$key      = $target['key'];
			$selected = isset( $suggested[ $key ] ) ? (int) $suggested[ $key ] : '';

			// Only single-value targets can be matched against by an entry
			// query; composite parents and encoded values never would.
			if ( ! empty( $target['scalar'] ) ) {
				$dupe_opts[ $key ] = wp_strip_all_tags( str_replace( '&rsaquo;', '>', $target['label'] ) );
			}

			// sanitize_html_class() strips the dot, so input "3.6" and field
			// "36" would collide on one DOM id; map the dot to a dash instead.
			$dom_id = sanitize_html_class( str_replace( '.', '-', $key ) );

			echo '<tr>';
			printf( '<td><label for="gei-map-%1$s">%2$s</label></td>', esc_attr( $dom_id ), wp_kses( $target['label'], array() ) );

			echo '<td>';
			printf(
				'<select name="mapping[%1$s]" id="gei-map-%2$s" class="gei-map-select" data-target="%1$s">',
				esc_attr( $key ),
				esc_attr( $dom_id )
			);
			printf( '<option value="">%s</option>', esc_html__( '— Not imported —', 'gravity-entry-import' ) );

			foreach ( $header as $index => $label ) {
				printf(
					'<option value="%1$d"%3$s>%2$s</option>',
					absint( $index ),
					esc_html(
						'' === trim( (string) $label )
							/* translators: %d: 1-based CSV column number. */
							? sprintf( __( 'Column %d', 'gravity-entry-import' ), $index + 1 )
							: $label
					),
					selected( $selected, $index, false )
				);
			}

			echo '</select>';
			echo '</td>';

			printf( '<td class="gei-sample" data-target="%s"></td>', esc_attr( $key ) );
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Options', 'gravity-entry-import' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Duplicates', 'gravity-entry-import' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_duplicates" value="1" /> ' . esc_html__( 'Skip rows that already exist, matching on:', 'gravity-entry-import' ) . '</label> ';
		echo '<select name="duplicate_field">';
		printf( '<option value="">%s</option>', esc_html__( '— Select a field —', 'gravity-entry-import' ) );
		foreach ( $dupe_opts as $key => $label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</select>';
		printf( '<p class="description">%s</p>', esc_html__( 'Adds one lookup query per row, so it slows large imports.', 'gravity-entry-import' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notifications', 'gravity-entry-import' ) . '</th><td>';
		echo '<label><input type="checkbox" name="send_notifications" value="1" /> ' . esc_html__( 'Send form notifications for each imported entry', 'gravity-entry-import' ) . '</label>';
		printf( '<p class="description">%s</p>', esc_html__( 'Leave this off unless you mean to email everyone in the file.', 'gravity-entry-import' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gei-batch">' . esc_html__( 'Rows per batch', 'gravity-entry-import' ) . '</label></th><td>';
		printf(
			'<input type="number" name="batch_size" id="gei-batch" value="%d" min="1" max="200" class="small-text" />',
			absint( GEI_Importer::DEFAULT_BATCH_SIZE )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'Lower this if the server times out during import.', 'gravity-entry-import' ) );
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Continue to import', 'gravity-entry-import' ) );
		echo '</form>';

		self::print_sample_data( $job );
	}

	/**
	 * Outputs the first data row so the mapping screen can show samples.
	 *
	 * @since 1.0.0
	 *
	 * @param array $job Job state.
	 * @return void
	 */
	protected static function print_sample_data( array $job ) {
		$reader = new GEI_CSV_Reader( $job['file'], $job['delimiter'], '"' );
		$batch  = $reader->read_batch( (int) $job['offset'], 1 );
		$sample = isset( $batch['rows'][0] ) ? $batch['rows'][0] : array();

		$trimmed = array();
		foreach ( $sample as $index => $value ) {
			$value             = (string) $value;
			$trimmed[ $index ] = ( 60 < strlen( $value ) ) ? substr( $value, 0, 57 ) . '…' : $value;
		}

		wp_add_inline_script(
			'gei-admin',
			'window.geiSample = ' . wp_json_encode( $trimmed ) . ';',
			'before'
		);
	}

	/**
	 * Renders the progress screen that drives the AJAX import.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	protected static function render_run_step( $job_id ) {
		$job = GEI_Storage::get_job( $job_id );

		if ( null === $job ) {
			self::render_expired_notice();
			return;
		}

		// Same fallback as a missing job: see current_user_owns_job() for why
		// this can't tell an unauthorized user their job ID was valid.
		if ( ! self::current_user_owns_job( $job ) ) {
			self::render_expired_notice();
			return;
		}

		$form  = GFAPI::get_form( (int) $job['form_id'] );
		$title = ( ! empty( $form ) && ! is_wp_error( $form ) ) ? $form['title'] : '';

		echo '<div class="gei-card">';

		printf(
			'<p class="gei-summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of rows, 2: form title. */
					__( 'Ready to import %1$d rows into "%2$s".', 'gravity-entry-import' ),
					(int) $job['total'],
					$title
				)
			)
		);

		printf(
			'<div id="gei-runner" data-job="%s" data-total="%d">',
			esc_attr( $job_id ),
			absint( $job['total'] )
		);

		echo '<div class="gei-progress"><div class="gei-progress-bar" style="width:0%"></div></div>';
		echo '<p class="gei-status" role="status" aria-live="polite"></p>';

		printf(
			'<p><button type="button" class="button button-primary" id="gei-start">%s</button></p>',
			esc_html__( 'Start import', 'gravity-entry-import' )
		);

		echo '<ul class="gei-errors"></ul>';
		echo '</div>';

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::page_url() ),
			esc_html__( 'Start a different import', 'gravity-entry-import' )
		);

		echo '</div>';
	}

	/**
	 * Renders the "job expired" fallback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected static function render_expired_notice() {
		printf(
			'<div class="notice notice-error"><p>%s</p></div><p><a class="button" href="%s">%s</a></p>',
			esc_html__( 'That import job has expired or was removed.', 'gravity-entry-import' ),
			esc_url( self::page_url() ),
			esc_html__( 'Start over', 'gravity-entry-import' )
		);
	}
}
