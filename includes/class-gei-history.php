<?php
/**
 * Persistent, bounded log of completed import jobs, scoped to a form.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records a small summary of each completed import job and keeps it around
 * after GEI_Storage::prune_stale_jobs() removes the job record itself.
 *
 * A job's own record (headers, mapping, per-row error samples, the CSV file
 * it pointed at) is deliberately ephemeral - GEI_Storage::prune_stale_jobs()
 * removes it, on the same schedule as an abandoned upload, roughly a day
 * after it completes (see GEI_Storage::JOB_MAX_AGE), so that large, job-
 * scoped state never accumulates in wp_options forever. That leaves no way
 * to look back at what an import actually did once that cleanup runs, which
 * is exactly the gap this class exists to close - without reintroducing the
 * unbounded-growth problem the job-pruning schedule exists to avoid.
 *
 * The fix is to record only a SUMMARY at the moment a job completes (see
 * GEI_Importer::process_batch()): form_id, who ran it, when it started and
 * finished, and the six row-outcome counts already shown on the results
 * screen. Deliberately nothing else - no header, no mapping, no raw CSV
 * data, no per-row error detail - so a history entry can never grow with the
 * size of the file it came from the way a job record (bounded only by
 * GEI_Importer::MAX_LOGGED_ERRORS) still can while it's alive.
 *
 * Storage follows the exact pattern GEI_Templates already established, for
 * the same reasons: one option per form - gei_history_{form_id} - holding a
 * capped array of summaries, autoload off (a history list is only ever read
 * on this importer's own Upload screen, never on every page load), and
 * explicitly excluded from the daily gei_cleanup cron (see
 * gei_run_scheduled_cleanup() in the main plugin file) because, like a
 * saved mapping template, this is deliberately durable record-keeping, not
 * throwaway job state - only the per-form cap below and plugin uninstall
 * ever remove an entry.
 *
 * Unlike a template, there is no explicit per-entry "delete" action in this
 * version - a history entry holds nothing sensitive enough (no raw data, no
 * PII beyond a user ID already visible elsewhere in wp-admin) to need one,
 * and the whole point is an unedited record of what happened.
 *
 * @since 1.5.0
 */
class GEI_History {

	/**
	 * Option key prefix. The full option name is this prefix plus a form ID.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'gei_history_';

	/**
	 * Maximum number of history entries retained per form.
	 *
	 * A hard cap, oldest dropped first, the same reasoning
	 * GEI_Templates::MAX_PER_FORM already applies to its own per-form array:
	 * a form imported into routinely would otherwise grow this option
	 * forever. Unlike a template cap, nothing here ever blocks an admin from
	 * completing an import once it's reached - record() just quietly drops
	 * the oldest entry to make room, since there is no "delete one to free
	 * up space" action for an admin to be asked to take in this version.
	 *
	 * @var int
	 */
	const MAX_PER_FORM = 100;

	/**
	 * Returns every recorded history entry for a form, oldest first.
	 *
	 * @since 1.5.0
	 *
	 * @param int $form_id Form ID.
	 * @return array List of summary records. Empty when none are recorded.
	 */
	public static function get_history( $form_id ) {
		$form_id = absint( $form_id );

		if ( 0 === $form_id ) {
			return array();
		}

		$history = get_option( self::option_name( $form_id ), array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Appends one summary record for a just-completed job.
	 *
	 * Called exactly once per job, by GEI_Importer::process_batch() at the
	 * instant a job's `complete` flag is first set - see that method's own
	 * comment for why a resumed call against an already-complete job can
	 * never reach this a second time. Silently does nothing for a job with
	 * no usable form_id (a corrupt or pre-1.0 job record, mirroring how
	 * GEI_Storage::prune_stale_jobs() itself treats a job it cannot make
	 * sense of) rather than recording a history entry no form's history list
	 * would ever be able to show.
	 *
	 * @since 1.5.0
	 *
	 * @param array $job Job state, as returned by GEI_Storage::get_job().
	 * @return void
	 */
	public static function record( array $job ) {
		$form_id = isset( $job['form_id'] ) ? absint( $job['form_id'] ) : 0;

		if ( 0 === $form_id ) {
			return;
		}

		$history   = self::get_history( $form_id );
		$history[] = array(
			'form_id'   => $form_id,
			'user_id'   => isset( $job['user_id'] ) ? absint( $job['user_id'] ) : 0,
			'started'   => isset( $job['created'] ) ? (int) $job['created'] : 0,
			'completed' => isset( $job['completed_at'] ) ? (int) $job['completed_at'] : time(),
			'total'     => isset( $job['total'] ) ? (int) $job['total'] : 0,
			'imported'  => isset( $job['imported'] ) ? (int) $job['imported'] : 0,
			'updated'   => isset( $job['updated'] ) ? (int) $job['updated'] : 0,
			'skipped'   => isset( $job['skipped'] ) ? (int) $job['skipped'] : 0,
			'failed'    => isset( $job['failed'] ) ? (int) $job['failed'] : 0,
			'filtered'  => isset( $job['filtered'] ) ? (int) $job['filtered'] : 0,
		);

		// Oldest dropped first once the cap is exceeded - array_slice() with
		// a negative length keeps the trailing (most recent) MAX_PER_FORM
		// entries and discards everything before them, preserving the
		// oldest-to-newest order get_history() callers already expect.
		if ( count( $history ) > self::MAX_PER_FORM ) {
			$history = array_slice( $history, -1 * self::MAX_PER_FORM );
		}

		// autoload = false: matches GEI_Templates' own reasoning - this is
		// only ever read on this importer's own Upload screen, never on
		// every page load.
		update_option( self::option_name( $form_id ), $history, false );
	}

	/**
	 * Builds the option name holding every history entry recorded for one form.
	 *
	 * @since 1.5.0
	 *
	 * @param int $form_id Form ID.
	 * @return string Option name.
	 */
	protected static function option_name( $form_id ) {
		return self::OPTION_PREFIX . absint( $form_id );
	}
}
