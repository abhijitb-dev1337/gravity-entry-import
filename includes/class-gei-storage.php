<?php
/**
 * Upload handling and import-job state storage.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores uploaded CSVs in a protected directory and tracks job state.
 *
 * @since 1.0.0
 */
class GEI_Storage {

	/**
	 * Option key prefix for persisted import jobs.
	 *
	 * Jobs use options rather than transients so a long-running import cannot
	 * be evicted from the object cache halfway through.
	 *
	 * @var string
	 */
	const JOB_OPTION_PREFIX = 'gei_job_';

	/**
	 * Option key prefix for per-job locks.
	 *
	 * @var string
	 */
	const LOCK_OPTION_PREFIX = 'gei_lock_';

	/**
	 * Seconds after which a held lock is treated as abandoned.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 300;

	/**
	 * Age in seconds after which an abandoned job and its CSV are prunable.
	 *
	 * @var int
	 */
	const JOB_MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Returns the absolute path to the plugin's private upload directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute directory path, without a trailing slash.
	 */
	public static function get_upload_dir() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . 'gei';
	}

	/**
	 * Creates the upload directory and blocks direct web access to it.
	 *
	 * Imported CSVs routinely contain personal data, so the directory is
	 * hardened rather than left under the publicly readable uploads tree.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the directory exists and is protected.
	 */
	public static function ensure_upload_dir() {
		$dir = self::get_upload_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::protect_dir( $dir );

		return true;
	}

	/**
	 * Writes the deny-all .htaccess and index.php stub into a directory.
	 *
	 * Factored out of ensure_upload_dir() so ensure_files_dir() - the
	 * pre-staged local-file directory added in 1.4.0 for the File Upload
	 * column source (see GEI_File_Field) - can apply the exact same
	 * hardening to its own, deeper directory without duplicating the rule
	 * text. Apache already applies a parent directory's .htaccess to its
	 * subdirectories on its own, but this is written into every directory
	 * this plugin creates anyway rather than relied upon implicitly, since
	 * that inheritance is an Apache-specific behaviour this plugin has no way
	 * to guarantee on every host it runs on.
	 *
	 * @since 1.4.0
	 *
	 * @param string $dir Absolute path to an already-created directory.
	 * @return void
	 */
	protected static function protect_dir( $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Both blocks are guarded: a bare "Require all denied" is a 500 on
			// Apache 2.2, and a bare "Deny from all" is deprecated on 2.4.
			$rules  = "<IfModule mod_authz_core.c>\n";
			$rules .= "Require all denied\n";
			$rules .= "</IfModule>\n";
			$rules .= "<IfModule !mod_authz_core.c>\n";
			$rules .= "Deny from all\n";
			$rules .= "</IfModule>\n";

			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Returns the absolute path to one job's pre-staged local-files directory.
	 *
	 * This is the "local, pre-staged files" source mode's own directory (see
	 * GEI_File_Field): a separate subdirectory per job, under this plugin's
	 * existing hardened upload area, rather than a single shared directory for
	 * every job - two jobs staging a file with the same name can never collide
	 * or overwrite one another, and pruning one job's files (see
	 * delete_job_files_dir()) can never touch another job's still-in-flight
	 * files.
	 *
	 * @since 1.4.0
	 *
	 * @param string $job_id Job identifier.
	 * @return string Absolute directory path, without a trailing slash, or an
	 *                empty string when the job ID is invalid.
	 */
	public static function get_files_dir( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );

		if ( '' === $job_id ) {
			return '';
		}

		return trailingslashit( self::get_upload_dir() ) . 'files/' . $job_id;
	}

	/**
	 * Creates one job's pre-staged local-files directory and blocks direct
	 * web access to it, the same way ensure_upload_dir() protects its parent.
	 *
	 * @since 1.4.0
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True when the directory exists and is protected.
	 */
	public static function ensure_files_dir( $job_id ) {
		$dir = self::get_files_dir( $job_id );

		if ( '' === $dir ) {
			return false;
		}

		// The parent gei/ directory has to exist (and be protected) first -
		// wp_mkdir_p() below would create it implicitly either way, but going
		// through ensure_upload_dir() guarantees its own .htaccess/index.php
		// are in place too, exactly as if the main CSV upload path had run.
		if ( ! self::ensure_upload_dir() ) {
			return false;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::protect_dir( $dir );

		return true;
	}

	/**
	 * Handles the uploaded CSV and moves it into the protected directory.
	 *
	 * @since 1.0.0
	 *
	 * @param array $file A single entry from the $_FILES superglobal.
	 * @return string|WP_Error Absolute path to the stored file, or an error.
	 */
	public static function handle_upload( $file ) {
		if ( ! self::ensure_upload_dir() ) {
			return new WP_Error( 'gei_dir', __( 'Could not create the import directory inside wp-content/uploads.', 'gravity-entry-import' ) );
		}

		// A crafted csv_file[] arrives as an array and would fatal on PHP 8
		// inside is_uploaded_file()/sanitize_file_name().
		if ( ! isset( $file['tmp_name'], $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) ) {
			return new WP_Error( 'gei_upload', __( 'No file was received. Check the server upload limits and try again.', 'gravity-entry-import' ) );
		}

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gei_upload_error', self::upload_error_message( (int) $file['error'] ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gei_upload', __( 'No file was received. Check the server upload limits and try again.', 'gravity-entry-import' ) );
		}

		$name = sanitize_file_name( wp_unslash( $file['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext && 'txt' !== $ext ) {
			return new WP_Error( 'gei_ext', __( 'Only .csv and .txt files can be imported.', 'gravity-entry-import' ) );
		}

		// CSV exports are reported inconsistently across servers (text/csv,
		// text/plain, application/vnd.ms-excel), so a strict MIME allowlist
		// rejects legitimate files. The extension is already restricted above
		// and the file is only ever read by fgetcsv, never executed or served,
		// so a binary-content check is the meaningful guard here.
		if ( ! self::looks_like_text( $file['tmp_name'] ) ) {
			return new WP_Error( 'gei_type', __( 'That file is not plain text. Re-export it as comma-separated values and try again.', 'gravity-entry-import' ) );
		}

		$job_id = self::generate_job_id();
		$target = trailingslashit( self::get_upload_dir() ) . $job_id . '.csv';

		if ( ! move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
			return new WP_Error( 'gei_move', __( 'The uploaded file could not be saved. Check filesystem permissions on wp-content/uploads.', 'gravity-entry-import' ) );
		}

		// Uploads land world-readable on some hosts; tighten to owner/group.
		@chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $target;
	}

	/**
	 * Translates a PHP upload error code into readable guidance.
	 *
	 * @since 1.0.0
	 *
	 * @param int $code One of the UPLOAD_ERR_* constants.
	 * @return string Human-readable message.
	 */
	protected static function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					/* translators: %s: server upload size limit. */
					__( 'That file is larger than this server accepts (%s). Split the CSV or raise the limit.', 'gravity-entry-import' ),
					size_format( wp_max_upload_size() )
				);

			case UPLOAD_ERR_PARTIAL:
				return __( 'The upload was interrupted. Try again.', 'gravity-entry-import' );

			case UPLOAD_ERR_NO_FILE:
				return __( 'Choose a CSV file to upload.', 'gravity-entry-import' );

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'The server could not write the upload to disk. Check the temporary directory.', 'gravity-entry-import' );

			default:
				return __( 'The file could not be uploaded.', 'gravity-entry-import' );
		}
	}

	/**
	 * Determines whether a file looks like plain text rather than a binary.
	 *
	 * Reads only the leading chunk and rejects anything containing a NUL byte,
	 * which is enough to turn away archives, images and executables renamed to
	 * .csv without rejecting legitimate exports over a MIME mismatch.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path to the file.
	 * @return bool True when the file appears to be text.
	 */
	protected static function looks_like_text( $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $handle ) {
			return false;
		}

		$chunk = fread( $handle, 4096 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $chunk || '' === $chunk ) {
			return false;
		}

		return ( false === strpos( $chunk, "\0" ) );
	}

	/**
	 * Generates an unguessable identifier for an import job.
	 *
	 * @since 1.0.0
	 *
	 * @return string A 32-character alphanumeric job ID.
	 */
	public static function generate_job_id() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Persists an import job.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @param array  $job    Job state.
	 * @return void
	 */
	public static function save_job( $job_id, array $job ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return;
		}

		// autoload = false: job blobs are large and only needed on the import screen.
		update_option( self::JOB_OPTION_PREFIX . $job_id, $job, false );
	}

	/**
	 * Reads an import job.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return array|null The job state, or null when it does not exist.
	 */
	public static function get_job( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$job = get_option( self::JOB_OPTION_PREFIX . $job_id, null );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * Deletes a job and the CSV file behind it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function delete_job( $job_id ) {
		$job = self::get_job( $job_id );

		if ( is_array( $job ) && ! empty( $job['file'] ) ) {
			self::delete_job_file( $job['file'] );
		}

		delete_option( self::JOB_OPTION_PREFIX . self::sanitize_job_id( $job_id ) );
	}

	/**
	 * Builds the path to a job's failed-rows CSV.
	 *
	 * Named as a sibling of the main upload ({job_id}.csv / {job_id}-failed.csv)
	 * inside the same hardened directory, purely from the job ID rather than
	 * anything stored on the job record. That makes it derivable even for a
	 * job whose own option row is missing or corrupt, which is exactly the
	 * case prune_stale_jobs() has to cope with for its first, already-broken
	 * branch.
	 *
	 * @since 1.2.0
	 *
	 * @param string $job_id Job identifier.
	 * @return string Absolute path, or an empty string when the job ID is invalid.
	 */
	public static function get_failed_csv_path( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );

		if ( '' === $job_id ) {
			return '';
		}

		return trailingslashit( self::get_upload_dir() ) . $job_id . '-failed.csv';
	}

	/**
	 * Deletes a CSV file, refusing paths outside the plugin upload directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute file path.
	 * @return void
	 */
	public static function delete_job_file( $path ) {
		$dir  = wp_normalize_path( self::get_upload_dir() );
		$path = wp_normalize_path( $path );

		if ( 0 !== strpos( $path, trailingslashit( $dir ) ) ) {
			return;
		}

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Deletes one job's pre-staged local-files directory and everything in it.
	 *
	 * Unlike delete_job_file(), this removes a whole directory of
	 * admin-chosen filenames rather than one file this plugin itself named -
	 * get_files_dir()/ensure_files_dir() (see GEI_File_Field) are the only
	 * places that ever write into it, but what an admin stages inside it is
	 * their own batch of files with whatever names they have. The same
	 * containment check delete_job_file() applies before deleting a single
	 * path is applied here before touching the directory at all, even though
	 * $job_id has already been through sanitize_job_id() via get_files_dir():
	 * a directory delete is destructive enough to be worth the belt and
	 * suspenders.
	 *
	 * @since 1.4.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function delete_job_files_dir( $job_id ) {
		$dir = self::get_files_dir( $job_id );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$base = wp_normalize_path( self::get_upload_dir() );
		$real = wp_normalize_path( $dir );

		if ( 0 !== strpos( $real, trailingslashit( $base ) ) ) {
			return;
		}

		// glob('*') does not return dotfiles, so the .htaccess guard file is
		// removed explicitly afterward - matching uninstall.php's own
		// reasoning for the same two-step sweep, so the final rmdir() below
		// is not left failing silently on a leftover guard file forever.
		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( file_exists( $htaccess ) ) {
			wp_delete_file( $htaccess );
		}

		// Leaves the directory in place if anything unexpected still lives in
		// it (a subdirectory an admin created by hand, say), matching
		// uninstall.php's own reasoning for using a silenced @rmdir() here
		// rather than a recursive force-delete.
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Takes an exclusive lock on a job.
	 *
	 * add_option() is used because the options table has a unique key on
	 * option_name, so the insert either wins or fails atomically. A lock older
	 * than the timeout is assumed to belong to a request that died mid-batch
	 * and is taken over.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return bool True when the lock was acquired.
	 */
	public static function acquire_lock( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );

		if ( '' === $job_id ) {
			return false;
		}

		$key = self::LOCK_OPTION_PREFIX . $job_id;

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		$held = (int) get_option( $key, 0 );

		if ( 0 < $held && ( time() - $held ) < self::LOCK_TIMEOUT ) {
			return false;
		}

		// Two requests can both observe the same stale lock at once. A plain
		// update_option() would let both believe they won, defeating the lock;
		// a conditional UPDATE only succeeds for whichever request's WHERE
		// clause still matches the value it read; the loser's affected-row
		// count comes back 0.
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				maybe_serialize( time() ),
				$key,
				maybe_serialize( $held )
			)
		);

		if ( 0 < (int) $updated ) {
			// The raw query above bypasses update_option()'s own cache write,
			// so a host with a persistent object cache would otherwise keep
			// serving the pre-takeover value to get_option() for this request.
			wp_cache_delete( $key, 'options' );

			return true;
		}

		return false;
	}

	/**
	 * Releases a job lock.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Job identifier.
	 * @return void
	 */
	public static function release_lock( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );

		if ( '' === $job_id ) {
			return;
		}

		delete_option( self::LOCK_OPTION_PREFIX . $job_id );
	}

	/**
	 * Restricts a job ID to the character set generate_job_id() produces.
	 *
	 * @since 1.0.0
	 *
	 * @param string $job_id Raw job identifier.
	 * @return string Sanitized job identifier, or an empty string when invalid.
	 */
	public static function sanitize_job_id( $job_id ) {
		$job_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $job_id );

		return ( 16 <= strlen( $job_id ) ) ? substr( $job_id, 0, 64 ) : '';
	}

	/**
	 * Removes jobs and CSVs left behind by abandoned imports.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of jobs removed.
	 */
	public static function prune_stale_jobs() {
		global $wpdb;

		$removed = 0;
		$like    = $wpdb->esc_like( self::JOB_OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		foreach ( (array) $names as $option_name ) {
			$job    = get_option( $option_name );
			$job_id = substr( $option_name, strlen( self::JOB_OPTION_PREFIX ) );

			if ( ! is_array( $job ) || empty( $job['created'] ) ) {
				delete_option( $option_name );
				delete_option( self::LOCK_OPTION_PREFIX . $job_id );
				// Derived straight from the job ID rather than read off the
				// (missing/corrupt) job record - see get_failed_csv_path() -
				// so a failed-rows CSV can still be found and removed even
				// when there is no usable job state left to say it exists.
				self::delete_job_file( self::get_failed_csv_path( $job_id ) );
				// Same reasoning, extended to the pre-staged local-files
				// directory added in 1.4.0 (see GEI_File_Field): derivable
				// from the job ID alone via get_files_dir(), so a batch of
				// staged files is not stranded on disk just because the job
				// record behind it is gone or unreadable.
				self::delete_job_files_dir( $job_id );
				++$removed;
				continue;
			}

			if ( ( time() - (int) $job['created'] ) > self::JOB_MAX_AGE ) {
				if ( ! empty( $job['file'] ) ) {
					self::delete_job_file( $job['file'] );
				}
				// A failed-rows CSV deliberately outlives job completion (see
				// GEI_Importer::record_failed_row()) so it stays downloadable
				// from the results screen; once the job itself is old enough
				// to be pruned, nothing can reach that screen to download it
				// any more, so it would otherwise become exactly the kind of
				// orphaned file this method exists to clean up.
				self::delete_job_file( self::get_failed_csv_path( $job_id ) );
				// A pre-staged local-files directory (see GEI_File_Field) is,
				// deliberately, not removed the moment the job completes -
				// see this plugin's readme for why - so it is exactly as
				// prunable by age as the job record itself, on the same
				// schedule.
				self::delete_job_files_dir( $job_id );
				delete_option( $option_name );
				// A job is never removed while still in flight, so its lock
				// (if any) is stale too; without this, removing the job record
				// left the lock option to sit until uninstall.
				delete_option( self::LOCK_OPTION_PREFIX . $job_id );
				++$removed;
			}
		}

		return $removed;
	}
}
