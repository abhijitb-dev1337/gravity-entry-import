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

		foreach ( $batch['rows'] as $i => $row ) {
			++$job['processed'];

			$row_number = (int) $job['processed'] + 1; // +1 accounts for the header row.

			if ( self::is_blank_row( $row ) ) {
				++$job['skipped'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			$entry = GEI_Mapper::build_entry( $form, $row, $job['mapping'] );

			if ( ! empty( $job['skip_duplicates'] ) && self::is_duplicate( $entry, $dup_key, $existing_values ) ) {
				++$job['skipped'];
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			$result = GFAPI::add_entry( $entry );

			if ( is_wp_error( $result ) ) {
				++$job['failed'];
				self::log_error( $job, $row_number, $result->get_error_message() );
				self::checkpoint( $job_id, $job, $batch, $i );
				continue;
			}

			++$job['imported'];

			// Recorded immediately so a later row in this same batch that
			// duplicates the one just inserted is still caught, even though
			// $existing_values was only fetched once at the top of the batch.
			if ( '' !== $dup_key && isset( $entry[ $dup_key ] ) && '' !== $entry[ $dup_key ] ) {
				$existing_values[ (string) $entry[ $dup_key ] ] = true;
			}

			// Checkpointed before notifications: sending is the slowest step
			// and the likeliest place to hit the execution limit. If the
			// request dies here the entry is already recorded as imported, so
			// a resume will not add it a second time.
			self::checkpoint( $job_id, $job, $batch, $i );

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
	 * Checks whether an equivalent entry already exists on the form.
	 *
	 * Matching is done on the mapped duplicate-check field so a re-run of the
	 * same CSV does not double up entries. Membership is checked against a
	 * pre-fetched set (see fetch_existing_values()) rather than a live query,
	 * so this is an in-memory lookup, not a database round trip.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry           Candidate entry.
	 * @param string $dup_key         Entry array key the duplicate check matches on.
	 * @param array  $existing_values Set of already-seen values, as $value => true.
	 * @return bool True when a matching value exists.
	 */
	protected static function is_duplicate( array $entry, $dup_key, array $existing_values ) {
		if ( '' === $dup_key || ! isset( $entry[ $dup_key ] ) || '' === $entry[ $dup_key ] ) {
			return false;
		}

		return isset( $existing_values[ (string) $entry[ $dup_key ] ] );
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
	 * @since 1.1.0
	 *
	 * @param int    $form_id Form ID.
	 * @param string $meta_key Entry meta key (a mapped field or sub-input ID).
	 * @return array Set of existing values, as $value => true.
	 */
	protected static function fetch_existing_values( $form_id, $meta_key ) {
		if ( '' === (string) $meta_key || ! class_exists( 'GFFormsModel' ) ) {
			return array();
		}

		global $wpdb;

		$table = GFFormsModel::get_entry_meta_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$table} WHERE form_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $form_id,
				(string) $meta_key
			)
		);

		return array_fill_keys( array_map( 'strval', (array) $values ), true );
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
			'complete'  => ! empty( $job['complete'] ),
			'total'     => $total,
			'processed' => $done,
			'imported'  => (int) $job['imported'],
			'skipped'   => (int) $job['skipped'],
			'failed'    => (int) $job['failed'],
			'percent'   => $percent,
			'errors'    => isset( $job['errors'] ) ? array_slice( (array) $job['errors'], -10 ) : array(),
		);
	}
}
