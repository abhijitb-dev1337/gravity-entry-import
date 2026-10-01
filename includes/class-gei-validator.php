<?php
/**
 * Pre-import (dry-run) validation of a mapped CSV.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans a job's mapped CSV for likely problems without writing any entries.
 *
 * Sits between the Map and Import steps: once columns are mapped, the CSV is
 * read the same way GEI_Importer reads it - in resumable, checkpointed
 * batches - but every row is only ever run through GEI_Mapper::build_entry(),
 * never GFAPI::add_entry(). Nothing this class does can create, update or
 * delete an entry.
 *
 * State is stored on the same job record GEI_Importer uses (this is one
 * continuous Upload -> Map -> Validate -> Import flow, and a job is the unit
 * GEI_Storage already knows how to persist, lock and prune), but every key it
 * reads or writes is prefixed "validation_" so a validation pass can never be
 * mistaken for import progress, or vice versa, and so re-running validation
 * (after "back to mapping", or a page reload mid-scan) never disturbs a real
 * import that may already be under way for the same job.
 *
 * @since 1.2.0
 */
class GEI_Validator {

	/**
	 * Maximum number of sample rows retained per issue type.
	 *
	 * Mirrors GEI_Importer::MAX_LOGGED_ERRORS: both exist for the same reason
	 * (an unbounded sample list would make the job option itself grow with
	 * the size of the CSV, since it is loaded in full on every batch), kept as
	 * its own constant rather than a shared one so the two can be tuned
	 * independently - a validation pass touches every row of the file
	 * regardless of how many fields it maps, and so is likely to surface
	 * proportionally more candidate issues than the import ever logs errors.
	 *
	 * @var int
	 */
	const MAX_LOGGED_ISSUES = 100;

	/**
	 * The issue categories this validator checks for.
	 *
	 * @var array
	 */
	const ISSUE_TYPES = array( 'date', 'choice', 'required', 'file' );

	/**
	 * Scans the next batch of rows for a job, without importing anything.
	 *
	 * Shaped deliberately like GEI_Importer::process_batch(): the same job
	 * loading, the same existence/ownership checks in the same order, the
	 * same exclusive lock for the duration of the batch, and the same
	 * per-row checkpointing so a request that dies part-way through a scan
	 * resumes exactly rather than re-scanning rows it already covered. It
	 * shares GEI_Storage's job-lock mechanism with the real import - the two
	 * cannot validate and import the same job at the same instant - but
	 * validation never touches the import's own `offset`/`processed`/
	 * `imported`/`skipped`/`failed` counters, and the import never touches
	 * this method's `validation_*` counters, so neither can corrupt the
	 * other's progress.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @return array|WP_Error Updated validation progress, or an error.
	 */
	public static function validate_batch( $job_id ) {
		$job = GEI_Storage::get_job( $job_id );

		if ( null === $job ) {
			return new WP_Error( 'gei_no_job', __( 'That import job no longer exists. Start the import again.', 'gravity-entry-import' ) );
		}

		// Same order, and the same reasoning, as GEI_Importer::process_batch():
		// checked immediately after the existence check and before anything
		// else about the job is inspected. See GEI_Admin::current_user_owns_job()
		// for why the error has to look identical to a missing job.
		if ( ! GEI_Admin::current_user_owns_job( $job ) ) {
			return new WP_Error( 'gei_no_job', __( 'That import job no longer exists. Start the import again.', 'gravity-entry-import' ) );
		}

		if ( ! empty( $job['validation_complete'] ) ) {
			return self::progress( $job );
		}

		if ( empty( $job['mapping'] ) || ! is_array( $job['mapping'] ) ) {
			return new WP_Error( 'gei_no_mapping', __( 'This import has no column mapping yet. Go back and map at least one column.', 'gravity-entry-import' ) );
		}

		// Shares the real import's lock rather than a validation-specific one:
		// the two must never run against the same job at the same time (both
		// read the CSV by seeking to a stored offset; interleaved reads from
		// two processes would each see a consistent file, but there is no
		// reason to allow it), and GEI_Storage only ever needs to arbitrate
		// one lock per job either way.
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

		// Same best-effort headroom GEI_Importer::process_batch() gives itself,
		// for the same reason: a batch with dozens of per-row field lookups can
		// outrun a short default execution limit, and the per-row checkpoint
		// below makes a resume exact either way.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		wp_raise_memory_limit( 'admin' );

		$batch_size = isset( $job['batch_size'] ) ? (int) $job['batch_size'] : GEI_Importer::DEFAULT_BATCH_SIZE;
		$offset     = isset( $job['validation_offset'] ) ? (int) $job['validation_offset'] : (int) $job['offset'];
		$batch      = $reader->read_batch( $offset, $batch_size );

		// Indexed once per batch, not once per row - the same pre-fetch-over-
		// per-row-work this codebase already favours in
		// GEI_Importer::fetch_existing_values().
		$fields = GEI_Mapper::index_fields( $form );

		$processed = isset( $job['validation_processed'] ) ? (int) $job['validation_processed'] : 0;
		$flagged   = isset( $job['validation_issue_rows'] ) ? (int) $job['validation_issue_rows'] : 0;
		$filtered  = isset( $job['validation_filtered'] ) ? (int) $job['validation_filtered'] : 0;
		$issues    = ( isset( $job['validation_issues'] ) && is_array( $job['validation_issues'] ) )
			? $job['validation_issues']
			: self::empty_issue_set();

		// Defaults to "no rule" for the same reason, and with the same
		// fallback, as GEI_Importer::process_batch()'s own $filter_rule.
		$filter_rule = ( isset( $job['filter_rule'] ) && is_array( $job['filter_rule'] ) ) ? $job['filter_rule'] : array();

		foreach ( $batch['rows'] as $i => $row ) {
			++$processed;
			$row_number = $processed + 1; // +1 accounts for the header row.

			$is_blank = self::is_blank_row( $row );

			if ( ! $is_blank && GEI_Row_Filter::row_matches( $row, $filter_rule ) ) {
				$entry = GEI_Mapper::build_entry( $form, $row, $job['mapping'] );

				if ( self::evaluate_row( $fields, $row, $job['mapping'], $entry, $row_number, $issues ) ) {
					++$flagged;
				}
			} elseif ( ! $is_blank ) {
				// A row the real import would filter out is never run through
				// evaluate_row() at all - it will never actually be imported,
				// so flagging it with, say, a required-field issue would be
				// reporting a problem on a row nobody asked to import. Counted
				// as its own distinct outcome rather than folded into "clean"
				// so the summary can say how many rows the filter excluded,
				// not just "everything else looked fine".
				++$filtered;
			}

			self::checkpoint( $job_id, $job, $batch, $i, $processed, $issues, $flagged, $filtered );
		}

		if ( empty( $batch['rows'] ) || $batch['eof'] ) {
			$job['validation_complete'] = true;
		}

		GEI_Storage::save_job( $job_id, $job );
		GEI_Storage::release_lock( $job_id );

		return self::progress( $job );
	}

	/**
	 * Persists validation progress after a single row.
	 *
	 * Mirrors GEI_Importer::checkpoint() exactly, including saving on every
	 * row rather than only at the end of the batch, for the same reason: a
	 * request that dies part-way through a scan would otherwise lose track of
	 * rows it already covered and re-scan (and re-count) them on resume.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id    Job identifier.
	 * @param array  $job       Job state, passed by reference.
	 * @param array  $batch     Batch returned by the reader.
	 * @param int    $index     Index of the row just handled.
	 * @param int    $processed Rows scanned so far, including this one.
	 * @param array  $issues    Accumulated issue set (see empty_issue_set()).
	 * @param int    $flagged   Count of distinct rows that had at least one issue.
	 * @param int    $filtered  Count of rows excluded by the job's conditional
	 *                          row-filter rule (see GEI_Row_Filter) and
	 *                          therefore never run through evaluate_row() at all.
	 * @return void
	 */
	protected static function checkpoint( $job_id, array &$job, array $batch, $index, $processed, array $issues, $flagged, $filtered = 0 ) {
		$job['validation_processed']  = (int) $processed;
		$job['validation_issues']     = $issues;
		$job['validation_issue_rows'] = (int) $flagged;
		$job['validation_filtered']   = (int) $filtered;

		if ( isset( $batch['offsets'][ $index ] ) ) {
			$job['validation_offset'] = (int) $batch['offsets'][ $index ];
		}

		GEI_Storage::save_job( $job_id, $job );
	}

	/**
	 * Determines whether every cell in a row is empty.
	 *
	 * Duplicated from GEI_Importer::is_blank_row() rather than called across
	 * classes: it is a five-line, stateless check, and this class otherwise
	 * has no dependency on GEI_Importer at all beyond its DEFAULT_BATCH_SIZE
	 * constant, which is worth keeping that way.
	 *
	 * @since 1.2.0
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
	 * Builds an empty issue set with every known issue type pre-populated.
	 *
	 * Pre-populating every type (rather than only adding a type's entry the
	 * first time it is seen) keeps progress()'s issue_counts payload shaped
	 * identically from the first batch onward, so the browser never has to
	 * treat a type with zero occurrences so far as a special case.
	 *
	 * @since 1.2.0
	 *
	 * @return array Map of issue type to array( 'count' => 0, 'samples' => array() ).
	 */
	protected static function empty_issue_set() {
		$set = array();

		foreach ( self::ISSUE_TYPES as $type ) {
			$set[ $type ] = array(
				'count'   => 0,
				'samples' => array(),
			);
		}

		return $set;
	}

	/**
	 * Checks one row's mapped cells for date, choice and required-field issues.
	 *
	 * Only ever reads $row and the entry build_entry() already produced for
	 * it (passed in rather than rebuilt here, since the caller needs it for
	 * nothing else and building it is not free); nothing here calls
	 * GFAPI::add_entry() or any other method that writes to the database.
	 *
	 * Checks are only made against cells that are actually mapped for this
	 * row - a required field that was never mapped at all produces no issue
	 * here, matching the literal wording of the check this exists to cover
	 * ("is the GF field object's isRequired true and the *mapped cell*
	 * blank?"). A field left entirely unmapped is a mapping-screen decision,
	 * not a per-row data problem.
	 *
	 * @since 1.2.0
	 *
	 * @param array $fields     Map of field ID to field object, from GEI_Mapper::index_fields().
	 * @param array $row        CSV row values, indexed by column.
	 * @param array $mapping    Map of target key to column index.
	 * @param array $entry      The entry GEI_Mapper::build_entry() produced for this row.
	 * @param int   $row_number 1-based row number in the source file, for display.
	 * @param array $issues     Accumulated issue set, passed by reference.
	 * @return bool True when this row had at least one issue.
	 */
	protected static function evaluate_row( array $fields, array $row, array $mapping, array $entry, $row_number, array &$issues ) {
		$row_had_issue = false;

		foreach ( $mapping as $key => $column ) {
			if ( '' === $column || null === $column || ! isset( $row[ $column ] ) ) {
				continue;
			}

			// Entry metadata targets (the "__" prefix) are never backed by a
			// GF field object, so none of the field-level checks below apply.
			if ( 0 === strpos( (string) $key, GEI_Mapper::META_PREFIX ) ) {
				continue;
			}

			$key_parts = explode( '.', (string) $key, 2 );
			$field_id  = $key_parts[0];
			$field     = isset( $fields[ $field_id ] ) ? $fields[ $field_id ] : null;

			if ( null === $field ) {
				continue;
			}

			$raw       = trim( (string) $row[ $column ] );
			$label     = isset( $field->label ) ? $field->label : sprintf( 'Field %s', $field_id );
			$type      = isset( $field->type ) ? $field->type : '';
			$is_parent = ( false === strpos( (string) $key, '.' ) );

			// --- Required: GFAPI::add_entry() never runs Gravity Forms' own
			// front-end required-field validation, so this is the only check
			// that ever catches a required field left blank by the source data.
			if ( '' === $raw && ! empty( $field->isRequired ) ) {
				self::record_issue(
					$issues,
					'required',
					$row_number,
					$label,
					$raw,
					__( 'Required field is blank.', 'gravity-entry-import' )
				);
				$row_had_issue = true;
			}

			if ( '' === $raw ) {
				continue; // Nothing further to check on a blank cell.
			}

			// --- Dates: reuses GEI_Mapper::build_entry()'s own date-parsing
			// result rather than re-deriving date-format rules. normalize_date()
			// only ever returns something other than Y-m-d when both its
			// field-format-aware parse and its strtotime() fallback failed and
			// it returned the raw value unparsed, so checking the shape of what
			// build_entry() already produced is a direct, reliable proxy for
			// "did not parse" without duplicating that logic here.
			if ( 'date' === $type && $is_parent ) {
				$formatted = isset( $entry[ $key ] ) ? (string) $entry[ $key ] : '';

				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $formatted ) ) {
					self::record_issue(
						$issues,
						'date',
						$row_number,
						$label,
						$raw,
						__( 'Does not look like a valid date for this field.', 'gravity-entry-import' )
					);
					$row_had_issue = true;
				}

				continue;
			}

			// --- File Upload: flags a column value that looks like neither
			// a plausible bare filename nor a valid http(s) URL, via the
			// same read-only shape check (GEI_File_Field::looks_like_source())
			// the real import's own source-mode detection is built on. This
			// is a syntax check only - it never fetches the URL or looks
			// inside the pre-staged files directory, so it cannot catch
			// every way a real import might still fail a row (a 404, a file
			// never actually staged); see GEI_File_Field's own class
			// DocBlock for why Validate is not, and must not be, the
			// authoritative check for this.
			if ( 'fileupload' === $type && $is_parent ) {
				if ( ! GEI_File_Field::looks_like_source( $raw ) ) {
					self::record_issue(
						$issues,
						'file',
						$row_number,
						$label,
						$raw,
						__( 'Does not look like a bare filename or a valid http(s) URL for this File Upload field.', 'gravity-entry-import' )
					);
					$row_had_issue = true;
				}

				continue;
			}

			// --- Choices: radio/select match against any of the field's
			// choices, the same test a real import relies on via
			// match_choice_value() - except this one does not fall back to
			// accepting the raw value, so an unmatched one is reported instead
			// of silently imported as free text.
			if ( in_array( $type, array( 'radio', 'select' ), true ) && $is_parent ) {
				if ( ! empty( $field->choices ) && ! GEI_Mapper::choice_matches( $field, $raw ) ) {
					self::record_issue(
						$issues,
						'choice',
						$row_number,
						$label,
						$raw,
						__( 'Does not match any configured choice for this field.', 'gravity-entry-import' )
					);
					$row_had_issue = true;
				}

				continue;
			}

			// --- Choices: one checkbox sub-input. Checked against that one
			// sub-input's own choice specifically (matching
			// resolve_checkbox_input()'s real matching rule, including its
			// boolean-truthy "yes"/"1"/"x" flags), not "any choice on the
			// field" - a value valid for a different choice on the same field
			// is exactly the case a real import silently leaves unchecked.
			if ( 'checkbox' === $type && ! $is_parent ) {
				if ( ! empty( $field->choices ) && ! GEI_Mapper::checkbox_input_matches( $field, $raw, $key ) ) {
					self::record_issue(
						$issues,
						'choice',
						$row_number,
						$label,
						$raw,
						__( 'Does not match this checkbox choice and is not a recognised checked/unchecked flag.', 'gravity-entry-import' )
					);
					$row_had_issue = true;
				}

				continue;
			}

			// --- Choices: multiselect. Tokenized the same way
			// encode_multiselect() splits a delimited cell (via the now-public
			// GEI_Mapper::split_choices()), so a cell holding several choices
			// is checked token by token rather than as one unmatchable string.
			if ( 'multiselect' === $type && $is_parent && ! empty( $field->choices ) ) {
				$unmatched = array();

				foreach ( GEI_Mapper::split_choices( $field, $raw ) as $token ) {
					if ( ! GEI_Mapper::choice_matches( $field, $token ) ) {
						$unmatched[] = $token;
					}
				}

				if ( ! empty( $unmatched ) ) {
					self::record_issue(
						$issues,
						'choice',
						$row_number,
						$label,
						$raw,
						sprintf(
							/* translators: %s: comma-separated list of values that matched no configured choice. */
							__( 'Does not match any configured choice: %s', 'gravity-entry-import' ),
							implode( ', ', $unmatched )
						)
					);
					$row_had_issue = true;
				}
			}
		}

		return $row_had_issue;
	}

	/**
	 * Records one issue occurrence, capped at MAX_LOGGED_ISSUES samples per type.
	 *
	 * The count itself is never capped - only the retained sample rows are -
	 * so progress()'s summary ("N rows scanned, N clean, N with possible
	 * issues") stays accurate even once a type's sample list is full.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $issues      Accumulated issue set, passed by reference.
	 * @param string $type        One of self::ISSUE_TYPES.
	 * @param int    $row_number  1-based row number in the source file.
	 * @param string $field_label Human-readable field label.
	 * @param string $value       The raw CSV value that triggered the issue.
	 * @param string $problem     Human-readable description of the problem.
	 * @return void
	 */
	protected static function record_issue( array &$issues, $type, $row_number, $field_label, $value, $problem ) {
		if ( ! isset( $issues[ $type ] ) ) {
			$issues[ $type ] = array(
				'count'   => 0,
				'samples' => array(),
			);
		}

		++$issues[ $type ]['count'];

		if ( count( $issues[ $type ]['samples'] ) < self::MAX_LOGGED_ISSUES ) {
			$value = ( 80 < strlen( $value ) ) ? substr( $value, 0, 77 ) . '…' : $value;

			$issues[ $type ]['samples'][] = array(
				'row'     => (int) $row_number,
				'field'   => (string) $field_label,
				'value'   => (string) $value,
				'problem' => (string) $problem,
			);
		}
	}

	/**
	 * Builds the progress payload returned to the browser.
	 *
	 * @since 1.2.0
	 *
	 * @param array $job Job state.
	 * @return array Progress data.
	 */
	protected static function progress( array $job ) {
		$total   = max( 0, (int) $job['total'] );
		$done    = isset( $job['validation_processed'] ) ? (int) $job['validation_processed'] : 0;
		$percent = ( 0 < $total ) ? min( 100, (int) floor( ( $done / $total ) * 100 ) ) : 0;

		if ( ! empty( $job['validation_complete'] ) ) {
			$percent = 100;
		}

		$issues = ( isset( $job['validation_issues'] ) && is_array( $job['validation_issues'] ) )
			? $job['validation_issues']
			: self::empty_issue_set();

		$counts = array();
		foreach ( $issues as $type => $data ) {
			$counts[ $type ] = isset( $data['count'] ) ? (int) $data['count'] : 0;
		}

		return array(
			'complete'     => ! empty( $job['validation_complete'] ),
			'total'        => $total,
			'processed'    => $done,
			'percent'      => $percent,
			'issue_rows'   => isset( $job['validation_issue_rows'] ) ? (int) $job['validation_issue_rows'] : 0,
			'issue_counts' => $counts,
			'filtered'     => isset( $job['validation_filtered'] ) ? (int) $job['validation_filtered'] : 0,
		);
	}
}
