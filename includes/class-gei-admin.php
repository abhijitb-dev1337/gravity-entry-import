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
	 * Nonce action for downloading a job's failed-rows CSV.
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	const NONCE_DOWNLOAD_FAILED = 'gei_download_failed';

	/**
	 * Nonce action for deleting a saved mapping template.
	 *
	 * @since 1.3.0
	 *
	 * @var string
	 */
	const NONCE_DELETE_TEMPLATE = 'gei_delete_template';

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
		// admin-post.php rather than folding this into handle_post()'s
		// gei_action dispatch: this request needs to stream a file and exit,
		// not render or redirect within this tab's own screen, so it is kept
		// as its own distinctly-named action instead of overloading gei_action
		// with a response shape none of the others share.
		add_action( 'admin_post_gei_download_failed', array( __CLASS__, 'handle_download_failed' ) );
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
	 * Determines whether the current user may create a new form from a CSV's
	 * headers.
	 *
	 * Deliberately a separate, higher-trust check from current_user_can_import():
	 * GFAPI::add_form() performs no capability check of its own - it trusts
	 * its caller entirely - so this plugin has to draw the same line Gravity
	 * Forms itself already draws between editing entries and creating forms.
	 * This mirrors, capability for capability, the exact precedent Gravity
	 * Forms' own Import Forms tab sets on this same Forms -> Import/Export
	 * screen: GFExport::get_tabs() only ever adds that tab for a user who
	 * holds both gravityforms_edit_forms and gravityforms_create_form, not
	 * gravityforms_edit_entries/gravityforms_export_entries alone. Each
	 * capability is checked through its own GFCommon::current_user_can_any()
	 * call, rather than one call passed both capabilities, because that
	 * method ORs together every capability in an array it is given - the
	 * opposite of the AND this needs - and two single-capability calls is the
	 * same pattern GFExport::get_tabs() itself uses (as nested ifs) to express
	 * that AND.
	 *
	 * gform_full_access still universally overrides, the same escape hatch
	 * current_user_can_import() already grants over the plain import
	 * capability - GFCommon::current_user_can_any() folds that in
	 * automatically for either capability checked below.
	 *
	 * @since 1.5.1
	 *
	 * @return bool True when the current user may create a new form from a CSV's headers.
	 */
	public static function current_user_can_create_form() {
		if ( class_exists( 'GFCommon' ) ) {
			return GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) && GFCommon::current_user_can_any( 'gravityforms_create_form' );
		}

		return ( current_user_can( 'gravityforms_edit_forms' ) && current_user_can( 'gravityforms_create_form' ) ) || current_user_can( 'gform_full_access' );
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
					'importing'  => __( 'Importing…', 'gravity-entry-import' ),
					'done'       => __( 'Import complete.', 'gravity-entry-import' ),
					'failed'     => __( 'The import stopped because of an error.', 'gravity-entry-import' ),
					'confirm'    => __( 'Start importing entries into this form?', 'gravity-entry-import' ),
					'validating' => __( 'Scanning…', 'gravity-entry-import' ),
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
			return;
		}

		if ( 'delete_template' === $action ) {
			self::handle_delete_template_post();
		}
	}

	/**
	 * Validates and stores the uploaded CSV, then redirects to mapping.
	 *
	 * "Create a new form from this CSV's headers" is a mutually exclusive
	 * alternative to picking an existing one from the dropdown, not an
	 * additional option alongside it - when checked, a selected form_id (if
	 * any) is simply ignored rather than requiring the admin to also blank
	 * out the dropdown first. The new form is only ever created once the CSV
	 * itself has already been validated as readable and non-empty: a
	 * permanent new form is never left behind on the strength of an upload
	 * that turns out to be unusable anyway. See GEI_Form_Builder for the
	 * field-generation logic itself.
	 *
	 * Creating a new form is gated separately from current_user_can_import()
	 * via current_user_can_create_form(): the import capability alone is not
	 * enough to create a permanent new form, the same higher trust boundary
	 * Gravity Forms' own Import Forms tab already draws on this same screen
	 * (see current_user_can_create_form()'s own docblock). A request with
	 * create_new_form checked but without that capability is rejected
	 * outright here - it never silently falls through to "no form selected",
	 * and a form is never created on its behalf.
	 *
	 * @since 1.0.0
	 * @since 1.5.0 Added the "create a new form from this CSV's headers" path.
	 * @since 1.5.1 Gated the "create a new form" path behind
	 *              current_user_can_create_form(), separately from the plain
	 *              import capability this whole method is otherwise already
	 *              gated behind (see handle_post()).
	 *
	 * @return void
	 */
	protected static function handle_upload_post() {
		check_admin_referer( self::NONCE_UPLOAD );

		$create_new_form = ! empty( $_POST['create_new_form'] );
		$form_id         = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$new_form_title  = isset( $_POST['new_form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_form_title'] ) ) : '';

		if ( $create_new_form && ! self::current_user_can_create_form() ) {
			self::redirect_with_error( __( 'You do not have permission to create a new form. Choose an existing form instead, or ask someone who can create Gravity Forms forms to do this for you.', 'gravity-entry-import' ) );
		}

		if ( $create_new_form ) {
			if ( '' === $new_form_title ) {
				self::redirect_with_error( __( 'Give the new form a name.', 'gravity-entry-import' ) );
			}
		} elseif ( 0 === $form_id ) {
			self::redirect_with_error( __( 'Choose the form to import into, or choose to create a new one from the CSV headers.', 'gravity-entry-import' ) );
		}

		$form = null;

		if ( ! $create_new_form ) {
			$form = GFAPI::get_form( $form_id );

			if ( empty( $form ) || is_wp_error( $form ) ) {
				self::redirect_with_error( __( 'That form could not be found.', 'gravity-entry-import' ) );
			}
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

		if ( $create_new_form ) {
			$new_form_id = GEI_Form_Builder::create_form_from_header( $new_form_title, $header );

			if ( is_wp_error( $new_form_id ) ) {
				GEI_Storage::delete_job_file( $stored );
				self::redirect_with_error( $new_form_id->get_error_message() );
			}

			$form_id = $new_form_id;
			$form    = GFAPI::get_form( $form_id );

			if ( empty( $form ) || is_wp_error( $form ) ) {
				GEI_Storage::delete_job_file( $stored );
				self::redirect_with_error( __( 'The new form was created but could not be reloaded.', 'gravity-entry-import' ) );
			}
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
				'file'                  => $stored,
				'form_id'               => $form_id,
				'delimiter'             => $delimiter,
				'header'                => $header,
				'total'                 => $reader->count_rows(),
				'offset'                => $reader->get_first_data_offset(),
				'mapping'               => array(),
				'processed'             => 0,
				'imported'              => 0,
				'updated'               => 0,
				'skipped'               => 0,
				'failed'                => 0,
				'errors'                => array(),
				// Absolute path to this job's failed-rows CSV once
				// GEI_Importer::record_failed_row() creates one; empty until
				// (and unless) a row actually fails.
				'failed_csv'            => '',
				// 'skip' (the pre-1.2.0 behaviour) or 'update'; see
				// GEI_Admin::handle_map_post() and GEI_Importer::process_batch().
				'duplicate_mode'        => 'skip',
				'complete'              => false,
				// Namespaced separately from the counters above so a dry-run
				// validation pass can never be mistaken for, or corrupt, real
				// import progress. See GEI_Validator.
				'validation_offset'     => $reader->get_first_data_offset(),
				'validation_processed'  => 0,
				'validation_issue_rows' => 0,
				'validation_issues'     => array(),
				'validation_complete'   => false,
				'created'               => time(),
				'user_id'               => get_current_user_id(),
				// Whether this job's target form was just generated from the
				// CSV's own headers (see GEI_Form_Builder), rather than an
				// existing form the admin picked - read by render_map_step()
				// to show which form was created, so it's never a surprise
				// that a new, permanent form now exists.
				'created_form'          => $create_new_form,
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

		// Cast before preg_replace(): a crafted duplicate_field[] array survives
		// preg_replace() unchanged (it maps over array subjects rather than
		// erroring), and the isset( $mapping[ $duplicate_field ] ) check below
		// then throws an uncaught TypeError on an array offset. Every other
		// field in this method already goes through a scalar-safe sanitizer;
		// this one was the exception.
		$duplicate_field = isset( $_POST['duplicate_field'] ) ? preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $_POST['duplicate_field'] ) ) : '';
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

		// Anything other than the literal string "update" is treated as "skip" -
		// the exact pre-1.2.0 behaviour - so a missing field (an older cached
		// form, a stripped POST body) degrades to the safe, established default
		// rather than to an update no one asked for.
		$duplicate_mode = ( isset( $_POST['duplicate_mode'] ) && 'update' === sanitize_key( wp_unslash( $_POST['duplicate_mode'] ) ) ) ? 'update' : 'skip';

		$job['mapping']            = $mapping;
		$job['skip_duplicates']    = $skip_duplicates;
		$job['duplicate_mode']     = $duplicate_mode;
		$job['send_notifications'] = ! empty( $_POST['send_notifications'] );
		$job['duplicate_field']    = $duplicate_field;
		$job['batch_size']         = isset( $_POST['batch_size'] ) ? max( 1, min( 200, absint( wp_unslash( $_POST['batch_size'] ) ) ) ) : GEI_Importer::DEFAULT_BATCH_SIZE;
		$job['filter_rule']        = self::parse_filter_rule( $job );

		// Reset on every mapping save, not only the first: this is what makes
		// "back to mapping" followed by a changed mapping (or duplicate mode)
		// safe to re-validate rather than show stale results from a previous
		// mapping. Namespaced validation_* keys mean this can never touch the
		// real import's own offset/processed/imported/skipped/failed/filtered
		// counters.
		$job['validation_offset']     = (int) $job['offset'];
		$job['validation_processed']  = 0;
		$job['validation_issue_rows'] = 0;
		$job['validation_issues']     = array();
		$job['validation_filtered']   = 0;
		$job['validation_complete']   = false;

		GEI_Storage::save_job( $job_id, $job );

		// Saving a template is a side effect of this same submission rather
		// than a parallel form/nonce of its own - see the "Save this mapping
		// as" field in render_map_step(). A blank name means the admin did not
		// ask to save anything, so this is skipped silently rather than
		// treated as an error on what is otherwise a normal mapping save.
		$template_name  = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$template_error = '';

		if ( '' !== $template_name ) {
			$saved = GEI_Templates::save_template(
				(int) $job['form_id'],
				$template_name,
				array(
					'mapping'            => $mapping,
					'duplicate_mode'     => $duplicate_mode,
					'duplicate_field'    => $duplicate_field,
					'skip_duplicates'    => $skip_duplicates,
					'send_notifications' => $job['send_notifications'],
				)
			);

			if ( is_wp_error( $saved ) ) {
				$template_error = $saved->get_error_message();
			}
		}

		$redirect_args = array(
			'step' => 'validate',
			'job'  => rawurlencode( $job_id ),
		);

		// A failed template save (e.g. this form already has the maximum
		// number of templates) is surfaced as a notice, but deliberately does
		// not block or redirect away from the mapping the admin just
		// configured - the import itself did not fail, only the optional
		// "also save this as a template" side effect did.
		if ( '' !== $template_error ) {
			$redirect_args['gei_err'] = rawurlencode( $template_error );
		}

		wp_safe_redirect( self::page_url( $redirect_args ) );
		exit;
	}

	/**
	 * Parses and validates the "only import rows where…" filter rule fields
	 * from the mapping form POST.
	 *
	 * Returns an empty array - GEI_Row_Filter::row_matches()'s own "no rule"
	 * shape - whenever no column was chosen, or whenever the operator is not
	 * one of GEI_Row_Filter::OPERATORS (a tampered POST body, or a value left
	 * over from a version that supported a different operator set), or
	 * whenever the chosen column index does not actually exist in this job's
	 * own header. A rule that fails validation is silently dropped back to "no
	 * filter" rather than erroring out the whole mapping save over what is an
	 * optional, easy-to-misconfigure control.
	 *
	 * @since 1.3.0
	 *
	 * @param array $job Job state (read for its header column count only).
	 * @return array Filter rule ready to store on the job, or an empty array.
	 */
	protected static function parse_filter_rule( array $job ) {
		$column   = isset( $_POST['filter_column'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_column'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller, handle_map_post().
		$operator = isset( $_POST['filter_operator'] ) ? sanitize_key( wp_unslash( $_POST['filter_operator'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller, handle_map_post().
		$value    = isset( $_POST['filter_value'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer() in the caller, handle_map_post().

		if ( '' === $column || ! in_array( $operator, GEI_Row_Filter::OPERATORS, true ) ) {
			return array();
		}

		$header_count = isset( $job['header'] ) ? count( (array) $job['header'] ) : 0;
		$column       = absint( $column );

		if ( $column >= $header_count ) {
			return array();
		}

		return array(
			'column'   => $column,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	/**
	 * Deletes one saved mapping template and returns to the mapping screen.
	 *
	 * Same nonce -> capability -> job-ownership check order as every other
	 * job_id-accepting entry point in this plugin: capability is already
	 * enforced by handle_post() before this method is ever reached (see its
	 * own dispatch), the nonce is checked first here to mirror
	 * handle_map_post()'s own structure exactly, and job ownership is checked
	 * immediately after, before anything about the template itself is
	 * touched. The template is resolved and removed via the job's own trusted
	 * form_id, never a form_id taken directly from the request - the same
	 * defence in depth GEI_Storage::delete_job_file() applies before deleting
	 * a path - so this can never be used to delete another form's template
	 * even if a template_id belonging to one were submitted.
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	protected static function handle_delete_template_post() {
		check_admin_referer( self::NONCE_DELETE_TEMPLATE );

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

		$template_id = isset( $_POST['template_id'] ) ? GEI_Templates::sanitize_template_id( wp_unslash( $_POST['template_id'] ) ) : '';

		GEI_Templates::delete_template( (int) $job['form_id'], $template_id );

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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step routing.
		$history_form_id = isset( $_GET['form'] ) ? absint( wp_unslash( $_GET['form'] ) ) : 0;

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

			case 'validate':
				self::render_validate_step( $job_id );
				break;

			case 'run':
				self::render_run_step( $job_id );
				break;

			case 'history':
				self::render_history_step( $history_form_id );
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
			'upload'   => __( '1. Upload CSV', 'gravity-entry-import' ),
			'map'      => __( '2. Map columns', 'gravity-entry-import' ),
			'validate' => __( '3. Validate', 'gravity-entry-import' ),
			'run'      => __( '4. Import', 'gravity-entry-import' ),
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
	 * No longer bails out entirely when there are no active forms yet (as
	 * every version before 1.5.0 did): "Create a new form from this CSV's
	 * headers" needs no existing form to pick, so a site with none yet can
	 * still use it - only the "Target form" dropdown itself has nothing to
	 * offer in that case, shown as a description instead of blocking the
	 * whole screen the way the old warning notice did.
	 *
	 * "Or create a new form" is shown only to a user who actually holds
	 * current_user_can_create_form() - matching how Gravity Forms itself only
	 * ever shows its own Import Forms tab, on this same screen, to a user who
	 * holds the equivalent capabilities (see that method's own docblock). A
	 * user without it sees only the plain "select an existing form" dropdown,
	 * same as every version before 1.5.0 added this feature; the Target form
	 * description text adjusts accordingly so it never references an option
	 * that isn't on the screen.
	 *
	 * @since 1.0.0
	 * @since 1.5.0 Added the "create a new form from this CSV's headers"
	 *              option, and the "View past imports" links below the form.
	 * @since 1.5.1 The "create a new form" option is now shown only to a user
	 *              who holds current_user_can_create_form().
	 *
	 * @return void
	 */
	protected static function render_upload_step() {
		$forms           = GFAPI::get_forms( true );
		$can_create_form = self::current_user_can_create_form();

		echo '<form method="post" enctype="multipart/form-data" class="gei-card">';
		wp_nonce_field( self::NONCE_UPLOAD );
		echo '<input type="hidden" name="gei_action" value="upload" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gei-form">' . esc_html__( 'Target form', 'gravity-entry-import' ) . '</label></th><td>';
		echo '<select name="form_id" id="gei-form">';
		printf( '<option value="">%s</option>', esc_html__( '— Select a form —', 'gravity-entry-import' ) );
		foreach ( $forms as $form ) {
			printf(
				'<option value="%d">%s</option>',
				absint( $form['id'] ),
				esc_html( $form['title'] )
			);
		}
		echo '</select>';

		if ( empty( $forms ) ) {
			$form_description = $can_create_form
				? __( 'There are no active Gravity Forms yet — create a new one from the CSV\'s headers below instead.', 'gravity-entry-import' )
				: __( 'There are no active Gravity Forms yet. Ask someone who can create Gravity Forms forms to build one, or to create one for you from this CSV\'s headers.', 'gravity-entry-import' );
		} else {
			$form_description = $can_create_form
				? __( 'Leave this on "— Select a form —" if you are creating a new form from the CSV below instead.', 'gravity-entry-import' )
				: __( 'Choose the form to import this CSV into.', 'gravity-entry-import' );
		}

		printf( '<p class="description">%s</p>', esc_html( $form_description ) );
		echo '</td></tr>';

		if ( $can_create_form ) {
			echo '<tr><th scope="row">' . esc_html__( 'Or create a new form', 'gravity-entry-import' ) . '</th><td>';
			echo '<label><input type="checkbox" name="create_new_form" value="1" id="gei-create-new-form" /> ' . esc_html__( 'Create a new form from this CSV\'s headers', 'gravity-entry-import' ) . '</label>';
			echo '<p>';
			echo '<label for="gei-new-form-title" class="screen-reader-text">' . esc_html__( 'New form name', 'gravity-entry-import' ) . '</label>';
			printf(
				'<input type="text" name="new_form_title" id="gei-new-form-title" class="regular-text" maxlength="191" placeholder="%s" />',
				esc_attr__( 'New form name', 'gravity-entry-import' )
			);
			echo '</p>';
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Generates one field per CSV column, guessing a simple field type from each column header (for example, a header containing "email" becomes an Email field; otherwise, plain text). Every generated field is created not required. Review and adjust the result in the form editor before relying on it - the guess is a starting point, not a guarantee. Ignored unless the checkbox above is ticked.', 'gravity-entry-import' )
			);
			echo '</td></tr>';
		}

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

		self::render_history_links( $forms );
	}

	/**
	 * Lists a "View past imports" link for every form that has at least one
	 * recorded history entry (see GEI_History), below the upload form.
	 *
	 * Reading history is as cheap as reading GEI_Templates' own per-form
	 * option - one get_option() call per form already listed in the "Target
	 * form" dropdown above, not a new query - so this always reflects the
	 * exact set of forms with at least one completed import on record. Shown
	 * per-form, rather than only for whichever form the dropdown currently
	 * has selected, since this plain <select> has no JS-driven way to track
	 * a "currently selected" form without adding script this screen does not
	 * otherwise need.
	 *
	 * @since 1.5.0
	 *
	 * @param array $forms Every active form, as returned by GFAPI::get_forms( true ).
	 * @return void
	 */
	protected static function render_history_links( array $forms ) {
		$with_history = array();

		foreach ( $forms as $form ) {
			$count = count( GEI_History::get_history( (int) $form['id'] ) );

			if ( 0 < $count ) {
				$with_history[] = array(
					'id'    => (int) $form['id'],
					'title' => $form['title'],
					'count' => $count,
				);
			}
		}

		if ( empty( $with_history ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Past imports', 'gravity-entry-import' ) . '</h2>';
		echo '<ul class="gei-history-links">';

		foreach ( $with_history as $entry ) {
			echo '<li>';
			printf(
				'<a href="%s">%s</a>',
				esc_url(
					self::page_url(
						array(
							'step' => 'history',
							'form' => $entry['id'],
						)
					)
				),
				esc_html(
					sprintf(
						/* translators: 1: form title, 2: number of recorded past imports. */
						__( 'View past imports: %1$s (%2$d)', 'gravity-entry-import' ),
						$entry['title'],
						$entry['count']
					)
				)
			);
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Renders the column-mapping form.
	 *
	 * @since 1.0.0
	 * @since 1.5.0 Shows a notice, with a link to the form editor, when this
	 *              job's target form was just generated from the CSV's own
	 *              headers (see GEI_Form_Builder) rather than picked from
	 *              the existing-forms dropdown.
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

		// Shown every time this screen renders for this job, not just once:
		// it's cheap to repeat and means "back to mapping" or a page reload
		// never loses the one-time notice an admin might have missed the
		// first time - so it's never a surprise that a new, permanent form
		// now exists just because they didn't read it carefully the first
		// time around.
		if ( ! empty( $job['created_form'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%s <a href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %s: title of the newly created form. */
						__( 'A new form, "%s", was created from this CSV\'s headers.', 'gravity-entry-import' ),
						$form['title']
					)
				),
				esc_url(
					add_query_arg(
						array(
							'page' => 'gf_edit_forms',
							'id'   => absint( $form['id'] ),
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Open it in the form editor to review the guessed field types', 'gravity-entry-import' )
			);
		}

		$templates = GEI_Templates::get_templates( (int) $job['form_id'] );

		// Loading a template never writes anything - it only changes which
		// mapping/options this render pre-fills - so, like this screen's own
		// ?step=/&job= routing, reading it needs no nonce. See
		// render_template_bar() for the GET form that sets this.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only template selection.
		$template_id     = isset( $_GET['template'] ) ? GEI_Templates::sanitize_template_id( wp_unslash( $_GET['template'] ) ) : '';
		$loaded_template = ( '' !== $template_id ) ? GEI_Templates::get_template( (int) $job['form_id'], $template_id ) : null;

		$targets   = GEI_Mapper::get_targets( $form );
		$header    = (array) $job['header'];
		$suggested = GEI_Mapper::suggest_mapping( $targets, $header );
		$dupe_opts = array();

		// A loaded template's mapping and options entirely replace the
		// auto-suggested ones: once an admin has explicitly picked a saved
		// mapping, a fuzzy guess from the header text is a worse answer than
		// the one they asked for. Every one of these falls back to this
		// screen's pre-1.3.0 default exactly when no template is loaded, so a
		// job that never touches this feature renders byte-identically to
		// before it existed.
		$active_mapping            = ( null !== $loaded_template ) ? (array) $loaded_template['mapping'] : $suggested;
		$active_skip_duplicates    = ( null !== $loaded_template ) && ! empty( $loaded_template['skip_duplicates'] );
		$active_duplicate_field    = ( null !== $loaded_template ) ? (string) $loaded_template['duplicate_field'] : '';
		$active_duplicate_mode     = ( null !== $loaded_template ) ? (string) $loaded_template['duplicate_mode'] : 'skip';
		$active_send_notifications = ( null !== $loaded_template ) && ! empty( $loaded_template['send_notifications'] );

		// The pre-staged local-files directory (see GEI_File_Field, and
		// GEI_Storage::get_files_dir()/ensure_files_dir()) is created here,
		// on the mapping screen, rather than waiting for an admin to map a
		// column to a File Upload field first: the whole point of showing
		// its path below is so an admin can stage files into it *before*
		// finishing the mapping, and that path has to already exist to be
		// worth showing. Only created when this form actually has a File
		// Upload field to map at all, so a form with none never gets an
		// empty, unused directory.
		$files_dir = '';
		foreach ( $targets as $target ) {
			if ( 'fileupload' === $target['type'] ) {
				GEI_Storage::ensure_files_dir( $job_id );
				$files_dir = GEI_Storage::get_files_dir( $job_id );
				break;
			}
		}

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

		self::render_template_bar( $job_id, $templates, $template_id, $loaded_template );

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
			$selected = isset( $active_mapping[ $key ] ) ? (int) $active_mapping[ $key ] : '';

			// Only single-value targets can be matched against by an entry
			// query; composite parents and encoded values never would.
			if ( ! empty( $target['scalar'] ) ) {
				$dupe_opts[ $key ] = wp_strip_all_tags( str_replace( '&rsaquo;', '>', $target['label'] ) );
			}

			// sanitize_html_class() strips the dot, so input "3.6" and field
			// "36" would collide on one DOM id; map the dot to a dash instead.
			$dom_id = sanitize_html_class( str_replace( '.', '-', $key ) );

			echo '<tr>';
			echo '<td>';
			printf( '<label for="gei-map-%1$s">%2$s</label>', esc_attr( $dom_id ), wp_kses( $target['label'], array() ) );

			// Mode-indicator help text: File Upload is the one field type
			// where the column's meaning depends on the shape of the value
			// inside it (see GEI_File_Field::is_url_value()), so this spells
			// both supported forms out next to the field that needs it,
			// rather than requiring a separate per-column "mode" setting.
			if ( 'fileupload' === $target['type'] ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: absolute path to this job's pre-staged files directory. */
							__( 'Column value is either a bare filename already staged in %s, or a full http:// or https:// URL to fetch.', 'gravity-entry-import' ),
							$files_dir
						)
					)
				);
			}

			echo '</td>';

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
		echo '<label><input type="checkbox" name="skip_duplicates" value="1"' . checked( $active_skip_duplicates, true, false ) . ' /> ' . esc_html__( 'Rows that already exist, matching on:', 'gravity-entry-import' ) . '</label> ';
		echo '<select name="duplicate_field">';
		printf( '<option value="">%s</option>', esc_html__( '— Select a field —', 'gravity-entry-import' ) );
		foreach ( $dupe_opts as $key => $label ) {
			printf( '<option value="%1$s"%3$s>%2$s</option>', esc_attr( $key ), esc_html( $label ), selected( $active_duplicate_field, $key, false ) );
		}
		echo '</select>';
		printf( '<p class="description">%s</p>', esc_html__( 'Adds one lookup query per batch (not per row), so this stays cheap even on a large import.', 'gravity-entry-import' ) );

		echo '<fieldset class="gei-duplicate-mode">';
		printf( '<legend class="screen-reader-text">%s</legend>', esc_html__( 'What to do with a matching row', 'gravity-entry-import' ) );
		echo '<label><input type="radio" name="duplicate_mode" value="skip"' . checked( $active_duplicate_mode, 'skip', false ) . ' /> ' . esc_html__( 'Skip it', 'gravity-entry-import' ) . '</label> ';
		echo '<label><input type="radio" name="duplicate_mode" value="update"' . checked( $active_duplicate_mode, 'update', false ) . ' /> ' . esc_html__( 'Update the matching entry instead', 'gravity-entry-import' ) . '</label>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Update replaces this row\'s mapped fields on the matching entry. Fields this import does not map are left exactly as they are - nothing is blanked.', 'gravity-entry-import' )
		);
		echo '</fieldset>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Notifications', 'gravity-entry-import' ) . '</th><td>';
		echo '<label><input type="checkbox" name="send_notifications" value="1"' . checked( $active_send_notifications, true, false ) . ' /> ' . esc_html__( 'Send form notifications for each imported entry', 'gravity-entry-import' ) . '</label>';
		printf( '<p class="description">%s</p>', esc_html__( 'Leave this off unless you mean to email everyone in the file.', 'gravity-entry-import' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gei-batch">' . esc_html__( 'Rows per batch', 'gravity-entry-import' ) . '</label></th><td>';
		printf(
			'<input type="number" name="batch_size" id="gei-batch" value="%d" min="1" max="200" class="small-text" />',
			absint( GEI_Importer::DEFAULT_BATCH_SIZE )
		);
		printf( '<p class="description">%s</p>', esc_html__( 'Lower this if the server times out during import.', 'gravity-entry-import' ) );
		echo '</td></tr>';

		self::render_filter_rule_row( $header );

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Save this mapping', 'gravity-entry-import' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="gei-template-name">' . esc_html__( 'Save this mapping as', 'gravity-entry-import' ) . '</label></th><td>';
		echo '<input type="text" name="template_name" id="gei-template-name" class="regular-text" maxlength="191" />';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Optional. Give it a name to reuse this mapping and these options (not the row filter above) on a future import into this same form. Leave it blank to skip saving. Either way, this run continues normally when you click "Continue to import".', 'gravity-entry-import' )
		);
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Continue to import', 'gravity-entry-import' ) );
		echo '</form>';

		self::print_sample_data( $job );
	}

	/**
	 * Renders the "Load a saved mapping" control and, when a template is
	 * currently loaded, its "Delete" action.
	 *
	 * Both sit above the mapping table and, deliberately, outside the mapping
	 * form itself - an HTML form can never be nested inside another, and the
	 * load control here is its own GET form while the mapping table below is
	 * a POST form, so they have to be siblings rather than nested regardless.
	 *
	 * The load control is a plain GET re-render of this same screen with
	 * &template={id} added, matching this plugin's established
	 * server-rendered-not-SPA style (the same ?page=/&subview=/&step=/&job=
	 * routing is_own_screen() and render_tab() already use) - reading a
	 * template never writes anything, so this needs no nonce any more than
	 * that existing routing does. Deleting a template does write, so that one
	 * action is its own small POST form carrying NONCE_DELETE_TEMPLATE,
	 * checked in handle_delete_template_post().
	 *
	 * @since 1.3.0
	 *
	 * @param string     $job_id          Job identifier.
	 * @param array      $templates       Every saved template for this job's
	 *                                    form, from GEI_Templates::get_templates().
	 * @param string     $template_id     The currently loaded template's ID,
	 *                                    or an empty string when none is loaded.
	 * @param array|null $loaded_template The currently loaded template's data, or null.
	 * @return void
	 */
	protected static function render_template_bar( $job_id, array $templates, $template_id, $loaded_template ) {
		if ( empty( $templates ) && null === $loaded_template ) {
			return;
		}

		echo '<div class="gei-template-bar">';

		if ( ! empty( $templates ) ) {
			echo '<form method="get" class="gei-template-load">';
			echo '<input type="hidden" name="page" value="gf_export" />';
			printf( '<input type="hidden" name="subview" value="%s" />', esc_attr( self::SUBVIEW ) );
			echo '<input type="hidden" name="step" value="map" />';
			printf( '<input type="hidden" name="job" value="%s" />', esc_attr( $job_id ) );
			echo '<label for="gei-template-select">' . esc_html__( 'Load a saved mapping', 'gravity-entry-import' ) . '</label> ';
			echo '<select name="template" id="gei-template-select">';
			printf( '<option value="">%s</option>', esc_html__( '— Choose a saved mapping —', 'gravity-entry-import' ) );
			foreach ( $templates as $t_id => $t_data ) {
				printf(
					'<option value="%1$s"%3$s>%2$s</option>',
					esc_attr( $t_id ),
					esc_html( isset( $t_data['name'] ) ? $t_data['name'] : $t_id ),
					selected( $template_id, $t_id, false )
				);
			}
			echo '</select> ';
			submit_button( __( 'Load', 'gravity-entry-import' ), 'secondary', '', false );
			echo '</form>';
		}

		if ( null !== $loaded_template ) {
			echo '<form method="post" class="gei-template-delete">';
			printf(
				'<span class="gei-template-loaded">%s</span> ',
				esc_html(
					sprintf(
						/* translators: %s: name of the currently loaded mapping template. */
						__( 'Loaded mapping: "%s"', 'gravity-entry-import' ),
						isset( $loaded_template['name'] ) ? $loaded_template['name'] : ''
					)
				)
			);
			wp_nonce_field( self::NONCE_DELETE_TEMPLATE );
			echo '<input type="hidden" name="gei_action" value="delete_template" />';
			printf( '<input type="hidden" name="job" value="%s" />', esc_attr( $job_id ) );
			printf( '<input type="hidden" name="template_id" value="%s" />', esc_attr( $template_id ) );
			printf(
				'<button type="submit" class="button-link-delete">%s</button>',
				esc_html__( 'Delete this saved mapping', 'gravity-entry-import' )
			);
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Returns the human-readable label for each GEI_Row_Filter operator, in
	 * the order they should appear in the filter rule's operator dropdown.
	 *
	 * Kept as its own map (rather than deriving labels from
	 * GEI_Row_Filter::OPERATORS mechanically) because the operator keys are
	 * machine-stable identifiers stored on the job, while the labels are
	 * translatable strings - the same separation GEI_Mapper::get_meta_targets()
	 * already draws between its own stored meta keys and their display labels.
	 * The key set here must stay in sync with GEI_Row_Filter::OPERATORS by
	 * hand; there is no fatal on drift (an operator missing a label here would
	 * just render without one; the whole set is small enough to review together).
	 *
	 * @since 1.3.0
	 *
	 * @return array Map of operator key to translated label.
	 */
	protected static function filter_operator_labels() {
		return array(
			'equals'       => __( 'Equals', 'gravity-entry-import' ),
			'not_equals'   => __( 'Does not equal', 'gravity-entry-import' ),
			'contains'     => __( 'Contains', 'gravity-entry-import' ),
			'not_contains' => __( 'Does not contain', 'gravity-entry-import' ),
			'is_empty'     => __( 'Is empty', 'gravity-entry-import' ),
			'is_not_empty' => __( 'Is not empty', 'gravity-entry-import' ),
		);
	}

	/**
	 * Renders the "Only import rows where…" conditional filter row in the
	 * Options table.
	 *
	 * Always starts unselected/blank on every render, the same as every other
	 * option in this table (see render_map_step()): this screen does not
	 * currently persist any option's UI state across a "back to mapping" trip,
	 * and a filter rule is deliberately no exception to that, rather than the
	 * one option that behaves differently. The rule this renders is parsed and
	 * validated on submit by GEI_Admin::parse_filter_rule().
	 *
	 * @since 1.3.0
	 *
	 * @param array $header CSV header labels, the same list the mapping table above uses.
	 * @return void
	 */
	protected static function render_filter_rule_row( array $header ) {
		echo '<tr><th scope="row">' . esc_html__( 'Only import matching rows', 'gravity-entry-import' ) . '</th><td>';
		echo '<div class="gei-filter-rule">';

		echo '<label class="screen-reader-text" for="gei-filter-column">' . esc_html__( 'CSV column to filter on', 'gravity-entry-import' ) . '</label>';
		echo '<select name="filter_column" id="gei-filter-column">';
		printf( '<option value="">%s</option>', esc_html__( '— No filter: import every row —', 'gravity-entry-import' ) );
		foreach ( $header as $index => $label ) {
			printf(
				'<option value="%1$d">%2$s</option>',
				absint( $index ),
				esc_html(
					'' === trim( (string) $label )
						/* translators: %d: 1-based CSV column number. */
						? sprintf( __( 'Column %d', 'gravity-entry-import' ), $index + 1 )
						: $label
				)
			);
		}
		echo '</select> ';

		echo '<label class="screen-reader-text" for="gei-filter-operator">' . esc_html__( 'Condition', 'gravity-entry-import' ) . '</label>';
		echo '<select name="filter_operator" id="gei-filter-operator">';
		foreach ( self::filter_operator_labels() as $op => $op_label ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $op ), esc_html( $op_label ) );
		}
		echo '</select> ';

		echo '<label class="screen-reader-text" for="gei-filter-value">' . esc_html__( 'Comparison value', 'gravity-entry-import' ) . '</label>';
		echo '<input type="text" name="filter_value" id="gei-filter-value" class="regular-text" />';

		echo '</div>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Only rows where this CSV column meets the condition are imported. Every other row is counted separately as "filtered" - not as a validation issue and not as a skipped duplicate. Leave the column unselected to import every row (the default). The comparison value is ignored for "Is empty" / "Is not empty".', 'gravity-entry-import' )
		);
		echo '</td></tr>';
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

		// Present in the markup on every load rather than only once a failure
		// has actually happened, so admin.js only has to toggle visibility
		// from each batch's AJAX response instead of building the link itself
		// - keeping the gated, nonce'd URL construction in PHP, alongside
		// GEI_Admin::handle_download_failed()'s own checks, rather than in JS.
		// Hidden by default in admin.css; a failed-rows file may not exist yet
		// (or ever) for this job, and the handler itself 404s gracefully if
		// none does.
		printf(
			'<p class="gei-failed-download"><a href="%s" class="button">%s</a></p>',
			esc_url( self::failed_csv_download_url( $job_id ) ),
			esc_html__( 'Download failed rows', 'gravity-entry-import' )
		);

		echo '</div>';

		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( self::page_url() ),
			esc_html__( 'Start a different import', 'gravity-entry-import' )
		);

		echo '</div>';
	}

	/**
	 * Renders the dry-run validation screen between mapping and import.
	 *
	 * Never writes anything: while a scan is in progress this reuses the same
	 * progress-bar markup and admin.js polling pattern the run step uses for
	 * the real import (see assets/admin.js's bindValidator()), driving
	 * GEI_Validator::validate_batch() through the wp_ajax_gei_process_validation_batch
	 * endpoint instead of the import's own. Once validation_complete is set on
	 * the job, this renders the summary directly from the stored job state
	 * instead - a plain page load needs no AJAX round trip to show a result
	 * that already exists.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	protected static function render_validate_step( $job_id ) {
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

		echo '<div class="gei-card">';

		if ( ! empty( $job['validation_complete'] ) ) {
			self::render_validation_summary( $job_id, $job );
		} else {
			$form  = GFAPI::get_form( (int) $job['form_id'] );
			$title = ( ! empty( $form ) && ! is_wp_error( $form ) ) ? $form['title'] : '';

			printf(
				'<p class="gei-summary">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of rows, 2: form title. */
						__( 'Checking %1$d rows for possible issues before importing into "%2$s"… nothing is imported during this scan.', 'gravity-entry-import' ),
						(int) $job['total'],
						$title
					)
				)
			);

			printf(
				'<div id="gei-validator" data-job="%s" data-total="%d">',
				esc_attr( $job_id ),
				absint( $job['total'] )
			);
			echo '<div class="gei-progress"><div class="gei-progress-bar" style="width:0%"></div></div>';
			echo '<p class="gei-status" role="status" aria-live="polite"></p>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Renders the completed validation summary: counts, a sample issue table,
	 * and the "back to mapping" / "proceed to import" actions.
	 *
	 * Shown even when no issues were found ("still show the summary briefly
	 * for transparency"), with "Proceed to import" as the primary button
	 * either way so a clean scan doesn't leave the admin looking for what to
	 * click next.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job state.
	 * @return void
	 */
	protected static function render_validation_summary( $job_id, array $job ) {
		$issues        = ( isset( $job['validation_issues'] ) && is_array( $job['validation_issues'] ) ) ? $job['validation_issues'] : array();
		$total         = (int) $job['total'];
		$flagged_rows  = isset( $job['validation_issue_rows'] ) ? (int) $job['validation_issue_rows'] : 0;
		$filtered_rows = isset( $job['validation_filtered'] ) ? (int) $job['validation_filtered'] : 0;
		$clean_rows    = max( 0, $total - $flagged_rows - $filtered_rows );

		// A job with no conditional row-filter rule always scans with
		// $filtered_rows at 0, so this keeps the exact pre-1.3.0 three-number
		// message in that case rather than always growing a fourth clause -
		// see GEI_Row_Filter::row_matches() for why "no rule" and "rule
		// matched nothing" are indistinguishable by design, and why that
		// makes 0 the correct, unambiguous "no filter" case to special-case.
		if ( 0 < $filtered_rows ) {
			printf(
				'<p class="gei-summary">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: rows scanned, 2: rows with no issues, 3: rows with possible issues, 4: rows excluded by the "only import matching rows" filter. */
						__( '%1$d rows scanned, %2$d clean, %3$d with possible issues, %4$d excluded by your row filter (not imported, and not checked for issues).', 'gravity-entry-import' ),
						$total,
						$clean_rows,
						$flagged_rows,
						$filtered_rows
					)
				)
			);
		} else {
			printf(
				'<p class="gei-summary">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: rows scanned, 2: rows with no issues, 3: rows with possible issues. */
						__( '%1$d rows scanned, %2$d clean, %3$d with possible issues.', 'gravity-entry-import' ),
						$total,
						$clean_rows,
						$flagged_rows
					)
				)
			);
		}

		$samples = array();
		foreach ( $issues as $data ) {
			foreach ( ( isset( $data['samples'] ) ? (array) $data['samples'] : array() ) as $sample ) {
				$samples[] = $sample;
			}
		}

		if ( ! empty( $samples ) ) {
			usort(
				$samples,
				static function ( $a, $b ) {
					return $a['row'] <=> $b['row'];
				}
			);

			echo '<table class="widefat striped gei-validation-issues">';
			echo '<thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'Row', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Field', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Value', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Possible problem', 'gravity-entry-import' ) );
			echo '</tr></thead><tbody>';

			foreach ( $samples as $sample ) {
				echo '<tr>';
				printf( '<td>%d</td>', absint( $sample['row'] ) );
				printf( '<td>%s</td>', esc_html( $sample['field'] ) );
				printf( '<td>%s</td>', esc_html( $sample['value'] ) );
				printf( '<td>%s</td>', esc_html( $sample['problem'] ) );
				echo '</tr>';
			}

			echo '</tbody></table>';

			$sampled_count = count( $samples );
			$total_issues  = 0;
			foreach ( $issues as $data ) {
				$total_issues += isset( $data['count'] ) ? (int) $data['count'] : 0;
			}

			if ( $total_issues > $sampled_count ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %d: number of additional issues not shown in the sample table. */
							__( '…and %d more not shown here.', 'gravity-entry-import' ),
							$total_issues - $sampled_count
						)
					)
				);
			}
		} else {
			printf( '<p>%s</p>', esc_html__( 'No possible issues were found.', 'gravity-entry-import' ) );
		}

		echo '<p class="gei-validation-actions">';
		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( self::page_url( array( 'step' => 'map', 'job' => rawurlencode( $job_id ) ) ) ),
			esc_html__( 'Back to mapping', 'gravity-entry-import' )
		);
		printf(
			'<a class="button button-primary" href="%s">%s</a>',
			esc_url( self::page_url( array( 'step' => 'run', 'job' => rawurlencode( $job_id ) ) ) ),
			esc_html__( 'Proceed to import', 'gravity-entry-import' )
		);
		echo '</p>';
	}

	/**
	 * Renders the "past imports" history screen for one form (see GEI_History).
	 *
	 * Reachable only from a "View past imports" link render_history_links()
	 * prints on the Upload step, for a form that already has at least one
	 * recorded entry - not restricted to whoever ran any particular import,
	 * the same trust level GEI_Templates' own saved mappings already use
	 * (visible to anyone who can pass current_user_can_import(), which
	 * render_tab() already enforces before this is ever reached, not scoped
	 * per-user the way driving or resuming a live job itself is - see
	 * current_user_owns_job() for why that one case is different). A history
	 * entry holds only the small summary GEI_History::record() wrote - no
	 * raw CSV data, no mapping - so there is nothing here more sensitive than
	 * what the results screen already showed when each import finished.
	 *
	 * @since 1.5.0
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	protected static function render_history_step( $form_id ) {
		$form_id = absint( $form_id );
		$form    = ( 0 < $form_id ) ? GFAPI::get_form( $form_id ) : null;
		$title   = ( ! empty( $form ) && ! is_wp_error( $form ) ) ? $form['title'] : '';
		$history = GEI_History::get_history( $form_id );

		echo '<div class="gei-card">';

		printf(
			'<p class="gei-summary">%s</p>',
			esc_html(
				'' !== $title
					? sprintf(
						/* translators: %s: form title. */
						__( 'Past imports into "%s".', 'gravity-entry-import' ),
						$title
					)
					: __( 'Past imports.', 'gravity-entry-import' )
			)
		);

		if ( empty( $history ) ) {
			printf( '<p>%s</p>', esc_html__( 'No completed imports are recorded for this form yet.', 'gravity-entry-import' ) );
		} else {
			// record() appends, so the stored array is oldest-to-newest; this
			// is the one place that order needs reversing so the most recent
			// import is what an admin sees first.
			$rows = array_reverse( $history );

			echo '<table class="widefat striped gei-history">';
			echo '<thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'Completed', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Run by', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Total', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Imported', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Updated', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Skipped', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Filtered', 'gravity-entry-import' ) );
			printf( '<th>%s</th>', esc_html__( 'Failed', 'gravity-entry-import' ) );
			echo '</tr></thead><tbody>';

			foreach ( $rows as $entry ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( self::format_history_date( $entry ) ) );
				printf( '<td>%s</td>', esc_html( self::format_history_user( $entry ) ) );
				printf( '<td>%d</td>', isset( $entry['total'] ) ? absint( $entry['total'] ) : 0 );
				printf( '<td>%d</td>', isset( $entry['imported'] ) ? absint( $entry['imported'] ) : 0 );
				printf( '<td>%d</td>', isset( $entry['updated'] ) ? absint( $entry['updated'] ) : 0 );
				printf( '<td>%d</td>', isset( $entry['skipped'] ) ? absint( $entry['skipped'] ) : 0 );
				printf( '<td>%d</td>', isset( $entry['filtered'] ) ? absint( $entry['filtered'] ) : 0 );
				printf( '<td>%d</td>', isset( $entry['failed'] ) ? absint( $entry['failed'] ) : 0 );
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( self::page_url() ),
			esc_html__( 'Back to Upload', 'gravity-entry-import' )
		);

		echo '</div>';
	}

	/**
	 * Formats one history entry's completion timestamp for display.
	 *
	 * Uses wp_date() rather than date_i18n() with a raw timestamp: both
	 * 'started'/'completed' were stored via PHP's time() (a plain UTC Unix
	 * timestamp, the same as every other timestamp this plugin already
	 * stores - see GEI_Storage's job 'created' field), and wp_date() is core's
	 * own correct, DST-aware way to render that in the site's configured
	 * timezone, the same correctness this codebase already cares about
	 * elsewhere (see GEI_Mapper::apply_meta()'s own date_created handling).
	 *
	 * @since 1.5.0
	 *
	 * @param array $entry One history summary record.
	 * @return string Formatted date/time, or a fallback when unset.
	 */
	protected static function format_history_date( array $entry ) {
		$timestamp = isset( $entry['completed'] ) ? (int) $entry['completed'] : 0;

		if ( 0 === $timestamp ) {
			return __( 'Unknown', 'gravity-entry-import' );
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Formats one history entry's recorded user for display.
	 *
	 * Mirrors GEI_Mapper::finalize_entry()'s own reasoning for an
	 * unattributed created_by of 0: a history entry with no user on record
	 * (a job run some other way, or from before per-job attribution existed)
	 * shows as unattributed rather than a misleading "user 0", and a user ID
	 * that no longer resolves to an account (since deleted) shows as unknown
	 * rather than silently disappearing from the column.
	 *
	 * @since 1.5.0
	 *
	 * @param array $entry One history summary record.
	 * @return string Display name, or a fallback.
	 */
	protected static function format_history_user( array $entry ) {
		$user_id = isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0;

		if ( 0 === $user_id ) {
			return __( 'Unattributed', 'gravity-entry-import' );
		}

		$user = get_user_by( 'id', $user_id );

		return $user ? $user->display_name : __( 'Unknown user', 'gravity-entry-import' );
	}

	/**
	 * Builds the gated, nonce'd URL for downloading a job's failed-rows CSV.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @return string Absolute admin-post.php URL.
	 */
	protected static function failed_csv_download_url( $job_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'gei_download_failed',
					'job'    => rawurlencode( $job_id ),
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_DOWNLOAD_FAILED
		);
	}

	/**
	 * Streams a job's failed-rows CSV to the browser.
	 *
	 * Nonce, then capability, then job-ownership, in that order - the same
	 * sequence and the same reasoning as every other job_id-accepting entry
	 * point in this plugin (see handle_map_post() and
	 * GEI_Importer::process_batch()). Never serves a path taken directly off
	 * the request: the only path this ever streams is whatever is recorded on
	 * the job's own `failed_csv` value, which only GEI_Importer's own
	 * record_failed_row() ever sets, and even that is re-checked against the
	 * plugin's own upload directory before being read - the same defence in
	 * depth GEI_Storage::delete_job_file() applies before deleting a path.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function handle_download_failed() {
		check_admin_referer( self::NONCE_DOWNLOAD_FAILED );

		if ( ! self::current_user_can_import() ) {
			wp_die( esc_html__( 'You do not have permission to import entries.', 'gravity-entry-import' ), 403 );
		}

		$job_id = isset( $_GET['job'] ) ? GEI_Storage::sanitize_job_id( wp_unslash( $_GET['job'] ) ) : '';
		$job    = GEI_Storage::get_job( $job_id );

		// Same fallback as everywhere else a job_id can be supplied: see
		// current_user_owns_job() for why a mismatch and a missing job have to
		// look identical.
		if ( null === $job || ! self::current_user_owns_job( $job ) ) {
			wp_die( esc_html__( 'That import job has expired or was removed.', 'gravity-entry-import' ), 404 );
		}

		$path = isset( $job['failed_csv'] ) ? (string) $job['failed_csv'] : '';

		if ( '' === $path || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'No failed-rows file exists for this import.', 'gravity-entry-import' ), 404 );
		}

		$dir = wp_normalize_path( GEI_Storage::get_upload_dir() );

		if ( 0 !== strpos( wp_normalize_path( $path ), trailingslashit( $dir ) ) ) {
			wp_die( esc_html__( 'That file could not be found.', 'gravity-entry-import' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $job_id . '-failed.csv' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
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
