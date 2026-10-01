<?php
/**
 * Batch import engine.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Processes an import job one batch at a time.
 *
 * @since 1.0.0
 */
class GEI_Importer {

	/**
	 * Default number of rows handled per request.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Maximum number of row-level errors retained on a job.
	 *
	 * @var int
	 */
	const MAX_LOGGED_ERRORS = 100;

	/**
	 * Processes the next batch of rows for a job.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated job progress, or an error.
	 */
	public static function process_batch( $job_id ) {
		$job = GEI_Storage::get_job( $job_id );

		if ( null === $job ) {
			return new WP_Error( 'gei_no_job', __( 'That import job no longer exists. Start the import again.', 'gravity-entry-import' ) );
		}

		// Checked immediately after the existence check, before anything
		// about the job's own state (complete, mapping, lock) is inspected -
		// none of that is this requester's business if the job is not
		// theirs. Reuses the "no job" error verbatim rather than a distinct
		// message: see GEI_Admin::current_user_owns_job() for why a
		// mismatch has to look identical to a job that does not exist, so a
		// leaked job ID can't be confirmed as belonging to someone else.
		if ( ! GEI_Admin::current_user_owns_job( $job ) ) {
			return new WP_Error( 'gei_no_job', __( 'That import job no longer exists. Start the import again.', 'gravity-entry-import' ) );
		}

		if ( ! empty( $job['complete'] ) ) {
			return self::progress( $job );
		}

		if ( empty( $job['mapping'] ) || ! is_array( $job['mapping'] ) ) {
			return new WP_Error( 'gei_no_mapping', __( 'This import has no column mapping yet. Go back and map at least one column.', 'gravity-entry-import' ) );
		}

		// Without a lock, a second Start click or a reload mid-batch runs a
		// concurrent pass from the same offset and imports those rows twice.
		if ( ! GEI_Storage::acquire_lock( $job_id ) ) {
			return new WP_Error( 'gei_locked', __( 'This import is already running in another tab or request.', 'gravity-entry-import' ) );
		}

		$form = GFAPI::get_form( (int) $job['form_id'] );

		if ( empty( $form ) || is_wp_error( $form ) ) {
			GEI_Storage::release_lock( $job_id );

			return new WP_Error( 'gei_no_form', __( 'The target form could not be loaded. It may have been deleted.', 'gravity-entry-import' ) );
		}

		$reader = new GEI_CSV_Reader( $job['file'], $job['delimiter'], '"' );

		if ( ! $reader->is_readable() ) {
			GEI_Storage::release_lock( $job_id );

			return new WP_Error( 'gei_unreadable', __( 'The uploaded CSV is no longer readable. Upload it again.', 'gravity-entry-import' ) );
		}

		// A batch with per-row dedupe queries or notification emails can
		// outrun a short default execution limit. Raising it is best-effort:
		// where the host forbids it, the per-row checkpoint above still makes
		// the resume exact.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		wp_raise_memory_limit( 'admin' );

		$batch_size = isset( $job['batch_size'] ) ? (int) $job['batch_size'] : self::DEFAULT_BATCH_SIZE;
		$batch      = $reader->read_batch( (int) $job['offset'], $batch_size );

		// Fetched once per batch rather than once per row: a single query
		// covering every value already stored against the duplicate-check
		// field does the same job GFAPI::count_entries() would do per row, at
		// a fraction of the DB cost on a large import (two queries per row
		// there vs. one query per batch here). Re-fetching every batch, rather
		// than caching across the whole job, keeps it correct if entries are
		// added by other means mid-import and needs no extra state to persist
		// between AJAX requests; values inserted earlier in this same batch
		// are folded in below as they're added, so nothing is missed either
		// way.
		$existing_values = array();
		$dup_key         = isset( $job['duplicate_field'] ) ? (string) $job['duplicate_field'] : '';

		if ( ! empty( $job['skip_duplicates'] ) && '' !== $dup_key ) {
			$existing_values = self::fetch_existing_values( (int) $job['form_id'], $dup_key );
		}

		// Defaults to 'skip' - the exact pre-1.2.0 behaviour - for any job that
		// predates this option, and for a job where the mapping screen's radio
		// group was left on its default selection either way.
		$duplicate_mode = isset( $job['duplicate_mode'] ) ? (string) $job['duplicate_mode'] : 'skip';

		// Defaults to "no rule" (every row matches) for any job that predates
		// this option - see GEI_Row_Filter::row_matches() for why an absent or
		// unconfigured rule is treated as "import everything", the exact
		// pre-1.3.0 behaviour.
		$filter_rule = ( isset( $job['filter_rule'] ) && is_array( $job['filter_rule'] ) ) ? $job['filter_rule'] : array();

		// Guards a job saved before this counter existed (e.g. one already in
		// flight when this version was deployed) the same way duplicate_mode
		// is defaulted above, rather than incrementing an undefined array key.
		if ( ! isset( $job['filtered'] ) ) {
			$job['filtered'] = 0;
		}

		foreach ( $batch['rows'] as $i => $row ) {
			++$job['processed'];

			$row_number = (int) $job['processed'] + 1; // +1 accounts for the header row.

			if ( self::is_blank_row( $row ) ) {
				++$job['skipped'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			// Checked against the RAW row, before any mapping happens, and as
			// its own distinct outcome from both a blank-row skip (above) and
			// a duplicate skip/update (below) - an admin reviewing results
			// needs to be able to tell "never matched your filter" apart from
			// either of those, not have it folded into one ambiguous number.
			if ( ! GEI_Row_Filter::row_matches( $row, $filter_rule ) ) {
				++$job['filtered'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			$entry = GEI_Mapper::build_entry( $form, $row, $job['mapping'] );

			$match_entry_id = 0;
			if ( ! empty( $job['skip_duplicates'] ) ) {
				$match_entry_id = self::find_duplicate_entry_id( $entry, $dup_key, $existing_values );
			}

			if ( 0 < $match_entry_id && 'update' === $duplicate_mode ) {
				$update_result = self::update_existing_entry( $job_id, $form, $row, $job['mapping'], $match_entry_id );

				if ( is_wp_error( $update_result ) ) {
					++$job['failed'];
					self::log_error( $job, $row_number, $update_result->get_error_message() );
					self::record_failed_row( $job_id, $job, $row, $update_result->get_error_message() );
					self::checkpoint( $job_id, $job, $batch, $i );
					continue;
				}

				++$job['updated'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			if ( 0 < $match_entry_id ) {
				// duplicate_mode is 'skip' (or some other, unrecognised value -
				// treated the same as 'skip'): identical to every pre-1.2.0 job,
				// which never had a duplicate_mode key at all.
				++$job['skipped'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			// Resolves any File Upload column(s) mapped for this row into
			// real, GF-hosted files before the entry is ever inserted - see
			// GEI_File_Field::resolve_row_files() for the full local/remote
			// handling and every security check involved. Done only here,
			// immediately before add_entry(), and never inside
			// GEI_Mapper::build_entry() itself: that method is also used by
			// GEI_Validator's read-only preview, which must never fetch a
			// URL or touch the filesystem. Skipped entirely for a row
			// already decided above as a duplicate to skip, so a row that
			// will never be imported never costs a remote fetch either.
			$file_result = GEI_File_Field::resolve_row_files( $job_id, $form, $row, $job['mapping'], $entry );

			if ( is_wp_error( $file_result ) ) {
				++$job['failed'];
				self::log_error( $job, $row_number, $file_result->get_error_message() );
				self::record_failed_row( $job_id, $job, $row, $file_result->get_error_message() );
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			$entry = $file_result['entry'];

			$result = GFAPI::add_entry( $entry );

			if ( is_wp_error( $result ) ) {
				// A file already copied into Gravity Forms' own uploads
				// folder above is not referenced by any entry once
				// add_entry() itself then fails - this plugin's own cleanup
				// (prune_stale_jobs(), uninstall.php) has no reason to ever
				// look inside GF's uploads folder, so without this it would
				// become permanently orphaned rather than merely temporary.
				GEI_File_Field::cleanup_paths( $file_result['created_paths'] );

				++$job['failed'];
				self::log_error( $job, $row_number, $result->get_error_message() );
				self::record_failed_row( $job_id, $job, $row, $result->get_error_message() );
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			++$job['imported'];

			// Recorded immediately so a later row in this same batch that
			// duplicates the one just inserted is still caught, even though
			// $existing_values was only fetched once at the top of the batch.
			if ( '' !== $dup_key && isset( $entry[ $dup_key ] ) && '' !== $entry[ $dup_key ] ) {
				$existing_values[ (string) $entry[ $dup_key ] ] = (int) $result;
			}

			// Checkpointed before notifications: sending is the slowest step
			// and the likeliest place to hit the execution limit. If the
			// request dies here the entry is already recorded as imported, so
			// a resume will not add it a second time.
			self::checkpoint( $job_id, $job, $batch, $i );

			// Same ordering, and the same accepted trade-off, as
			// send_notifications() immediately below: if the request dies
			// between this checkpoint and here, the note is simply never
			// added - the row is already checkpointed as imported, so a
			// resume starts at the next row rather than risking a duplicate
			// note from reprocessing this one.
			self::maybe_add_note( (int) $result, $row, $job['mapping'], $entry );

			if ( ! empty( $job['send_notifications'] ) ) {
				self::send_notifications( $form, (int) $result );
			}
		}

		$job['offset'] = (int) $batch['next'];

		if ( empty( $batch['rows'] ) || $batch['eof'] ) {
			$job['complete']     = true;
			$job['completed_at'] = time();

			// The CSV has served its purpose and routinely holds personal
			// data, so it goes as soon as the import finishes. The job record
			// stays so the results screen still has something to report.
			if ( ! empty( $job['file'] ) ) {
				GEI_Storage::delete_job_file( $job['file'] );
				$job['file'] = '';
			}

			// Recorded exactly once, the moment this job transitions to
			// complete: process_batch() returns early, before ever reaching
			// this block, on every later call for an already-complete job
			// (see the `! empty( $job['complete'] )` check near the top of
			// this method) - so reloading or re-polling the results screen
			// can never log the same job to history twice. See GEI_History
			// for why this is a small, bounded, durable summary rather than
			// the full job record itself.
			GEI_History::record( $job );
		}

		GEI_Storage::save_job( $job_id, $job );
		GEI_Storage::release_lock( $job_id );

		return self::progress( $job );
	}

	/**
	 * Persists progress after a single row.
	 *
	 * Saving only at the end of a batch means a request that dies part-way
	 * loses the record of rows it already imported, and the retry adds them
	 * again. Advancing the stored offset row by row makes a resume exact.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job state, passed by reference.
	 * @param array  $batch  Batch returned by the reader.
	 * @param int    $index  Index of the row just handled.
	 * @return void
	 */
	protected static function checkpoint( $job_id, array &$job, array $batch, $index ) {
		if ( isset( $batch['offsets'][ $index ] ) ) {
			$job['offset'] = (int) $batch['offsets'][ $index ];
		}

		GEI_Storage::save_job( $job_id, $job );
	}

	/**
	 * Determines whether every cell in a row is empty.
	 *
	 * @since 1.0.0
	 *
	 * @param array $row CSV row.
	 * @return bool True when the row has no content.
	 */
	protected static function is_blank_row( array $row ) {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Finds the entry ID of an equivalent entry that already exists on the form.
	 *
	 * Matching is done on the mapped duplicate-check field so a re-run of the
	 * same CSV does not double up entries. Membership is checked against a
	 * pre-fetched map (see fetch_existing_values()) rather than a live query,
	 * so this is an in-memory lookup, not a database round trip.
	 *
	 * Named and shaped around returning an entry ID, rather than the plain
	 * bool this was before 1.2.0 (as is_duplicate()), because "Update" mode
	 * needs to know *which* entry to update, not just that a match exists;
	 * "Skip" mode only ever uses the truthiness of the result, so its
	 * behaviour is unchanged.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $entry           Candidate entry.
	 * @param string $dup_key         Entry array key the duplicate check matches on.
	 * @param array  $existing_values Map of already-seen values to entry ID, as
	 *                                $value => $entry_id (see fetch_existing_values()).
	 * @return int Matching entry ID, or 0 when no match exists.
	 */
	protected static function find_duplicate_entry_id( array $entry, $dup_key, array $existing_values ) {
		if ( '' === $dup_key || ! isset( $entry[ $dup_key ] ) || '' === $entry[ $dup_key ] ) {
			return 0;
		}

		$value = (string) $entry[ $dup_key ];

		return isset( $existing_values[ $value ] ) ? (int) $existing_values[ $value ] : 0;
	}

	/**
	 * Fetches every value already stored against a field, across all of a
	 * form's entries, for duplicate-check membership testing.
	 *
	 * One query for the whole batch stands in for what would otherwise be a
	 * GFAPI::count_entries() call per row (itself two queries - a SELECT plus
	 * a FOUND_ROWS() - against wp_gf_entry_meta). At 50,000+ rows that per-row
	 * cost compounds into the dominant cost of the whole import; this reduces
	 * it to a single indexed lookup per batch regardless of import size.
	 *
	 * Queries entry meta directly because GFAPI has no "get distinct values
	 * for this field" call; GFFormsModel::get_entry_meta_table_name() is
	 * Gravity Forms' own supported accessor for that table's name, so this
	 * does not depend on a hardcoded prefix.
	 *
	 * Returns $value => $entry_id (rather than the $value => true set this
	 * returned before 1.2.0) so "Update" mode has an entry ID to target
	 * without a second, per-row query - the entire point of pre-fetching this
	 * once per batch in the first place. The query is no longer DISTINCT: two
	 * entries sharing the same value are a possibility this field of a real
	 * site can't rule out, and in that case whichever row the database
	 * happens to return last wins the mapping - an arbitrary but harmless
	 * choice for "Skip" mode (still just a truthy match), and a documented,
	 * acceptable one for "Update" mode given the alternative would be a
	 * second query per row to disambiguate.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $form_id  Form ID.
	 * @param string $meta_key Entry meta key (a mapped field or sub-input ID).
	 * @return array Map of existing values to entry ID, as $value => $entry_id.
	 */
	protected static function fetch_existing_values( $form_id, $meta_key ) {
		if ( '' === (string) $meta_key || ! class_exists( 'GFFormsModel' ) ) {
			return array();
		}

		global $wpdb;

		$table = GFFormsModel::get_entry_meta_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, entry_id FROM {$table} WHERE form_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $form_id,
				(string) $meta_key
			)
		);

		$existing = array();

		foreach ( (array) $rows as $row ) {
			$existing[ (string) $row->meta_value ] = (int) $row->entry_id;
		}

		return $existing;
	}

	/**
	 * Updates an existing entry in place from one mapped CSV row, for the
	 * "Update" duplicate-handling mode.
	 *
	 * Loads the full current entry and delegates the actual merge to
	 * GEI_Mapper::build_update_entry() - see that method's DocBlock for why a
	 * partial entry array cannot be handed to GFAPI::update_entry() directly
	 * without blanking every field this row's CSV did not map.
	 *
	 * Never sends notifications, regardless of the job's send_notifications
	 * setting: GFAPI::send_notifications()'s only event, 'form_submission', is
	 * for a new submission, and firing it here would email an existing
	 * contact as though they had just re-submitted the form.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Added the $job_id parameter, and now also resolves any
	 *              mapped File Upload column into a real file before the
	 *              update is saved - see GEI_File_Field::resolve_row_files().
	 * @since 1.5.0 Now also adds a mapped entry note, the same as the
	 *              new-entry path in process_batch() - see maybe_add_note().
	 *
	 * @param string $job_id   Job identifier (needed by
	 *                         GEI_File_Field::resolve_row_files() for the
	 *                         local, pre-staged file source mode's own directory).
	 * @param array  $form     Gravity Forms form object.
	 * @param array  $row      CSV row values, indexed by column.
	 * @param array  $mapping  Map of target key to column index.
	 * @param int    $entry_id ID of the existing entry to update.
	 * @return true|WP_Error True on success, or an error.
	 */
	protected static function update_existing_entry( $job_id, $form, array $row, array $mapping, $entry_id ) {
		$current = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( empty( $current ) ) {
			return new WP_Error(
				'gei_no_match',
				__( 'The matching entry could not be loaded; it may have been deleted since the import started.', 'gravity-entry-import' )
			);
		}

		$entry = GEI_Mapper::build_update_entry( $form, $row, $mapping, $current );

		// Same reasoning, and the same ordering relative to the write it
		// guards, as process_batch()'s own add_entry() path: resolved here,
		// immediately before update_entry(), never inside GEI_Mapper itself.
		$file_result = GEI_File_Field::resolve_row_files( $job_id, $form, $row, $mapping, $entry );

		if ( is_wp_error( $file_result ) ) {
			return $file_result;
		}

		$entry = $file_result['entry'];

		$updated = GFAPI::update_entry( $entry, (int) $entry_id );

		if ( is_wp_error( $updated ) ) {
			// Same orphan-prevention as process_batch()'s own add_entry()
			// path: a file already copied into Gravity Forms' own uploads
			// folder above is not referenced anywhere once the update itself
			// then fails.
			GEI_File_Field::cleanup_paths( $file_result['created_paths'] );
		}

		if ( ! is_wp_error( $updated ) ) {
			self::maybe_add_note( (int) $entry_id, $row, $mapping, $entry );
		}

		return $updated;
	}

	/**
	 * Adds a Gravity Forms entry note from a mapped CSV cell, if any.
	 *
	 * Called only once an entry has already been created or updated
	 * successfully - process_batch() calls this right after a successful
	 * GFAPI::add_entry(), and update_existing_entry() right after a
	 * successful GFAPI::update_entry() - never before, since a note has to
	 * attach to a real, already-persisted entry ID. Uses
	 * GFFormsModel::add_note() directly: the same, real, supported API
	 * Gravity Forms' own entry-detail screen writes a hand-typed note
	 * through, so an imported note renders in the entry's note timeline
	 * identically to one a real user added, rather than this plugin writing
	 * to whatever table happens to back notes today.
	 *
	 * Attribution mirrors whatever this row's own "Created by" column (or the
	 * lack of one) already resolved for the entry itself: $entry['created_by']
	 * reflects that resolution either way (a real user ID when this row
	 * mapped and matched one via GEI_Mapper::resolve_user_id(), or the
	 * unattributed 0 default from GEI_Mapper::finalize_entry() otherwise), so
	 * reading it back here - rather than re-resolving "Created by" a second
	 * time - can never disagree with who the entry itself is credited to. A
	 * row with no recognised "Created by" value gets a system-style,
	 * unattributed note instead (user_id 0, a readable plugin name rather
	 * than a blank), the same "0 means unattributed, never silently credited
	 * to whoever ran the import" rule finalize_entry() already applies to
	 * created_by itself.
	 *
	 * Deliberately best-effort and silent on failure: GFFormsModel::add_note()
	 * has no failure mode of its own to report (no WP_Error return to check),
	 * and a note is incidental to the row's own success or failure - a row
	 * whose entry was already created or updated is never retroactively
	 * marked failed just because its optional note could not be added.
	 *
	 * @since 1.5.0
	 *
	 * @param int   $entry_id ID of the entry just created or updated.
	 * @param array $row      CSV row values, indexed by column.
	 * @param array $mapping  Map of target key to column index.
	 * @param array $entry    The entry array actually written, so its
	 *                        already-resolved 'created_by' can be reused for
	 *                        this note's own attribution.
	 * @return void
	 */
	protected static function maybe_add_note( $entry_id, array $row, array $mapping, array $entry ) {
		if ( ! class_exists( 'GFFormsModel' ) ) {
			return;
		}

		$note = GEI_Mapper::get_mapped_note( $row, $mapping );

		if ( '' === $note ) {
			return;
		}

		$user_id   = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
		$user_name = __( 'Gravity Entry Import', 'gravity-entry-import' );

		if ( 0 < $user_id ) {
			$user = get_user_by( 'id', $user_id );

			if ( $user ) {
				$user_name = $user->display_name ? $user->display_name : $user->user_login;
			}
		}

		GFFormsModel::add_note( (int) $entry_id, $user_id, $user_name, $note );
	}

	/**
	 * Fires Gravity Forms notifications for an imported entry.
	 *
	 * Off by default: importing a back catalogue with notifications enabled
	 * would email every historical submitter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form     Gravity Forms form object.
	 * @param int   $entry_id Newly created entry ID.
	 * @return void
	 */
	protected static function send_notifications( $form, $entry_id ) {
		$entry = GFAPI::get_entry( $entry_id );

		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			return;
		}

		GFAPI::send_notifications( $form, $entry, 'form_submission' );
	}

	/**
	 * Appends a row-level error to the job, capped at MAX_LOGGED_ERRORS.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $job     Job state, passed by reference.
	 * @param int    $row     Row number in the source file.
	 * @param string $message Error message.
	 * @return void
	 */
	protected static function log_error( array &$job, $row, $message ) {
		if ( ! isset( $job['errors'] ) || ! is_array( $job['errors'] ) ) {
			$job['errors'] = array();
		}

		if ( count( $job['errors'] ) >= self::MAX_LOGGED_ERRORS ) {
			return;
		}

		$job['errors'][] = array(
			'row'     => (int) $row,
			'message' => (string) $message,
		);
	}

	/**
	 * Appends one failed row to this job's failed-rows CSV, creating the file
	 * (with a header row copied from the original CSV plus a trailing
	 * "Failure Reason" column) the first time a row in this job fails.
	 *
	 * Kept as a separate, uncapped file rather than folded into log_error()'s
	 * in-job sample: log_error() caps at MAX_LOGGED_ERRORS precisely so the
	 * job option itself - loaded on every batch and rendered on the results
	 * screen - doesn't grow without bound, which is exactly why a large
	 * import's failures past the first 100 would otherwise leave no record of
	 * which rows to fix and re-run. Written into the same hardened
	 * wp-content/uploads/gei/ directory GEI_Storage already protects, rather
	 * than a new location, so it inherits the same .htaccess/index.php guard
	 * against direct web access, and - unlike the main uploaded CSV - is kept
	 * after the job completes so GEI_Admin's gated download handler can serve
	 * it from the results screen.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job state, passed by reference so failed_csv can
	 *                       be recorded once the file exists.
	 * @param array  $row    The raw CSV row values that failed, indexed by column.
	 * @param string $reason Human-readable failure reason.
	 * @return void
	 */
	protected static function record_failed_row( $job_id, array &$job, array $row, $reason ) {
		if ( ! GEI_Storage::ensure_upload_dir() ) {
			return;
		}

		$path = GEI_Storage::get_failed_csv_path( $job_id );

		if ( '' === $path ) {
			return;
		}

		$is_new    = ! file_exists( $path );
		$delimiter = isset( $job['delimiter'] ) ? (string) $job['delimiter'] : ',';

		$handle = @fopen( $path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			return;
		}

		if ( $is_new ) {
			$header = isset( $job['header'] ) ? (array) $job['header'] : array();

			// Matches GEI_CSV_Reader's own reasoning for an explicit, empty
			// escape character: PHP's historical "\" default is not part of
			// the CSV format, and from PHP 8.4 omitting the argument raises a
			// deprecation notice of its own.
			fputcsv( $handle, array_merge( $header, array( __( 'Failure Reason', 'gravity-entry-import' ) ) ), $delimiter, '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

			// Uploads land world-readable on some hosts; tighten to owner/group,
			// matching GEI_Storage::handle_upload()'s own guard on the main CSV.
			@chmod( $path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		fputcsv( $handle, array_merge( $row, array( (string) $reason ) ), $delimiter, '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$job['failed_csv'] = $path;
	}

	/**
	 * Builds the progress payload returned to the browser.
	 *
	 * @since 1.0.0
	 *
	 * @param array $job Job state.
	 * @return array Progress data.
	 */
	protected static function progress( array $job ) {
		$total   = max( 0, (int) $job['total'] );
		$done    = (int) $job['processed'];
		$percent = ( 0 < $total ) ? min( 100, (int) floor( ( $done / $total ) * 100 ) ) : 0;

		if ( ! empty( $job['complete'] ) ) {
			$percent = 100;
		}

		return array(
			'complete'   => ! empty( $job['complete'] ),
			'total'      => $total,
			'processed'  => $done,
			'imported'   => (int) $job['imported'],
			'updated'    => isset( $job['updated'] ) ? (int) $job['updated'] : 0,
			'skipped'    => (int) $job['skipped'],
			'filtered'   => isset( $job['filtered'] ) ? (int) $job['filtered'] : 0,
			'failed'     => (int) $job['failed'],
			'percent'    => $percent,
			'errors'     => isset( $job['errors'] ) ? array_slice( (array) $job['errors'], -10 ) : array(),
			'failed_csv' => ! empty( $job['failed_csv'] ),
		);
	}
}
