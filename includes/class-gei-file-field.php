<?php
/**
 * File Upload column source handling: resolves a CSV cell (a bare filename
 * or a remote URL) into a real, Gravity-Forms-hosted file.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns one CSV cell mapped to a File Upload field into a file genuinely
 * attached to the imported entry, the way a real form submission would be.
 *
 * This is deliberately its own class, kept out of both GEI_Mapper and
 * GEI_Importer, for one reason above all others: every other piece of
 * per-cell mapping logic in this plugin (GEI_Mapper::apply_field() and its
 * helpers) is a pure, side-effect-free string transform - it is safe for
 * GEI_Validator's read-only preview to call GEI_Mapper::build_entry()
 * against every row of a file without writing anything anywhere. Resolving a
 * File Upload cell is the opposite: it can mean an outbound HTTP request to
 * an admin-supplied URL, and it always means a filesystem write. Every
 * side-effecting method here is therefore called from exactly one place -
 * GEI_Importer, during a real import or a real "Update" - and never from
 * GEI_Mapper::build_entry()/apply_mapping(), so GEI_Validator (which builds
 * an entry purely to inspect it, via that same build_entry()) can never
 * trigger a fetch or a write no matter what a CSV contains. The read-only
 * shape checks below (is_url_value(), looks_like_bare_filename(),
 * looks_like_source()) are the only methods safe for GEI_Validator to call,
 * and are documented as such individually.
 *
 * Two source modes are supported, matched against the raw CSV cell with a
 * simple heuristic (see is_url_value()) rather than a separate per-column
 * "mode" setting an admin has to declare - detecting which mode a cell is in
 * is not a security boundary, so a light heuristic is fine; what happens
 * after the mode is known is where every real guard in this class lives:
 *
 * - Local, pre-staged file: the CSV cell is treated as a bare filename that
 *   must already exist in this job's own pre-staged directory (see
 *   GEI_Storage::get_files_dir()/ensure_files_dir()). Never a path - see
 *   resolve_local() for the sanitize_file_name()+basename()+realpath()
 *   containment chain that enforces this, mirroring the same pattern
 *   GEI_Storage::delete_job_file() already applies before deleting a path,
 *   applied here before reading one instead.
 * - Remote URL: fetched with wp_safe_remote_get()/wp_safe_remote_head()
 *   specifically - WordPress core's own SSRF-hardened HTTP functions, which
 *   reject a request to a private/loopback/link-local address (including a
 *   cloud metadata endpoint) via wp_http_validate_url() before ever opening
 *   a socket. See resolve_remote() for the scheme allowlist, the
 *   Content-Length pre-check, and the hard byte cap enforced on the actual
 *   download.
 *
 * Whichever mode produced the bytes, nothing is trusted about them until
 * validate_and_place() has run GFCommon::check_type_and_ext() (Gravity
 * Forms' own wrapper around wp_check_filetype_and_ext(), WordPress core's
 * real content-sniffing check) and matched the file's extension against the
 * field's own configured allowlist - the exact checks
 * GF_Field_FileUpload::is_invalid_file() runs for a genuine browser upload,
 * reused rather than re-implemented so this can never drift out of sync
 * with what Gravity Forms itself considers valid for this field. Only then
 * is the file copied into Gravity Forms' own per-form/per-date upload
 * structure via GFFormsModel::get_file_upload_path() - the same, real,
 * supported destination a genuine submission would use - so the resulting
 * entry is indistinguishable from an organic upload.
 *
 * @since 1.4.0
 */
class GEI_File_Field {

	/**
	 * Default cap, in bytes, on a remote file this plugin will download for a
	 * File Upload column.
	 *
	 * Deliberately generous enough for real attachments (photos, PDFs,
	 * signed documents) while still bounding how much one malicious or
	 * misconfigured CSV row can make this server download and write to disk.
	 * See max_bytes() for the gei_file_upload_max_bytes filter that overrides
	 * this per site.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_BYTES = 10485760; // 10 MB.

	/**
	 * Timeout, in seconds, for both the Content-Length pre-check request and
	 * the real download.
	 *
	 * Short enough that one slow or hanging remote host cannot stall an
	 * entire batch - the same reasoning GEI_Importer::process_batch() already
	 * applies to its own execution-time headroom, extended here to a single
	 * outbound request rather than the whole batch loop.
	 *
	 * @var int
	 */
	const REMOTE_TIMEOUT = 15;

	/**
	 * Determines whether a raw CSV value looks like an http(s) URL.
	 *
	 * Read-only and side-effect-free: this is a simple string-prefix check,
	 * never a network request, so it is safe for GEI_Validator to call
	 * (indirectly, via looks_like_source()) as well as GEI_Importer. This is
	 * the "reasonable, simple heuristic" this plugin's planning notes call
	 * for to choose between the two source modes - not itself a security
	 * boundary. See resolve_remote() for the real scheme check (exact
	 * "http"/"https" via wp_parse_url(), not this prefix test) that gates
	 * whether a request is actually attempted.
	 *
	 * @since 1.4.0
	 *
	 * @param string $value Raw CSV cell value.
	 * @return bool True when the value looks like an http(s) URL.
	 */
	public static function is_url_value( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		return ( 0 === stripos( $value, 'http://' ) || 0 === stripos( $value, 'https://' ) );
	}

	/**
	 * Determines whether a raw CSV value looks like a plausible bare filename.
	 *
	 * Read-only and side-effect-free, like is_url_value(): a syntax check
	 * only, never a filesystem lookup - it does not confirm the file actually
	 * exists in this job's pre-staged directory, only that the value has the
	 * right shape to be one. That deliberately mirrors what resolve_local()
	 * will go on to require (no path separators, a name sanitize_file_name()
	 * would not have to meaningfully alter), so GEI_Validator's "does this
	 * look plausible" check (via looks_like_source()) never tells an admin a
	 * value looks fine when the real import's own, stricter checks would in
	 * fact reject it.
	 *
	 * @since 1.4.0
	 *
	 * @param string $value Raw CSV cell value.
	 * @return bool True when the value looks like a bare filename.
	 */
	public static function looks_like_bare_filename( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || self::is_url_value( $value ) ) {
			return false;
		}

		// A path separator of either kind means this is not "bare" - the
		// real check happens in resolve_local(); this only has to recognise
		// the shape, not enforce the boundary.
		if ( false !== strpos( $value, '/' ) || false !== strpos( $value, '\\' ) ) {
			return false;
		}

		return ( wp_basename( $value ) === $value && false !== strpos( $value, '.' ) );
	}

	/**
	 * Determines whether a raw CSV value looks like either supported File
	 * Upload source: a plausible bare filename, or a valid http(s) URL.
	 *
	 * The only method of this class GEI_Validator calls (see
	 * GEI_Validator::evaluate_row()) - read-only and side-effect-free for the
	 * same reason is_url_value()/looks_like_bare_filename() are: Validate
	 * must never fetch a URL or touch the pre-staged files directory, so this
	 * is a syntax check only, not an existence or reachability check. A local
	 * value that looks like a plausible filename but is not actually staged
	 * yet - or a URL that looks valid but 404s - is not caught here; both are
	 * still caught by the real import's own failed-row handling (see
	 * GEI_Importer::process_batch()), which is the authoritative check.
	 *
	 * @since 1.4.0
	 *
	 * @param string $value Raw CSV cell value.
	 * @return bool True when the value looks like a usable source.
	 */
	public static function looks_like_source( $value ) {
		return ( self::is_url_value( $value ) || self::looks_like_bare_filename( $value ) );
	}

	/**
	 * Returns the configured cap, in bytes, on a remote file download.
	 *
	 * @since 1.4.0
	 *
	 * @return int Maximum size in bytes.
	 */
	public static function max_bytes() {
		/**
		 * Filters the maximum size, in bytes, of a remote file this plugin
		 * will download for a File Upload column.
		 *
		 * Has no effect on a local, pre-staged file (see resolve_local()) -
		 * a file already sitting on this server was never the
		 * download-time resource-exhaustion risk a remote URL is; a local
		 * file is instead bounded by the field's own configured maximum file
		 * size (see GF_Field_FileUpload::get_max_file_size_bytes(), applied
		 * in validate_and_place()).
		 *
		 * @since 1.4.0
		 *
		 * @param int $max_bytes Maximum size in bytes. Default 10485760 (10MB).
		 */
		$max_bytes = (int) apply_filters( 'gei_file_upload_max_bytes', self::DEFAULT_MAX_BYTES );

		return ( 0 < $max_bytes ) ? $max_bytes : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Resolves every File Upload column mapped for one CSV row into real,
	 * Gravity-Forms-hosted files, merging the result into the entry array.
	 *
	 * The only entry point GEI_Importer calls; iterates the job's mapping
	 * looking for columns backed by a 'fileupload' field (never a sub-input -
	 * File Upload is not a composite field, so a mapped key here is always a
	 * plain field ID). A blank cell is left exactly as
	 * GEI_Mapper::apply_field()'s own 'fileupload' case already left it (an
	 * empty string - "no file"), matching how every other field type treats
	 * a blank mapped cell elsewhere in this plugin.
	 *
	 * Stops at the first column that fails and returns a WP_Error for the
	 * whole row - consistent with every other row-level failure in this
	 * plugin (a duplicate-field lookup failure, a GFAPI::add_entry() error):
	 * the row is routed to the existing failed-rows CSV (see
	 * GEI_Importer::record_failed_row()) rather than partially imported with
	 * some files attached and others silently missing. Any file already
	 * copied into Gravity Forms' own uploads folder for an earlier column in
	 * this same row is rolled back via cleanup_paths() before returning, so a
	 * row that ultimately fails never leaves an orphaned file behind - the
	 * same reasoning GEI_Importer applies on its own remaining failure paths
	 * (an add_entry()/update_entry() error after this method already
	 * succeeded), just applied one column earlier.
	 *
	 * @since 1.4.0
	 *
	 * @param string $job_id  Job identifier (needed for the local, pre-staged
	 *                        file source mode's own directory).
	 * @param array  $form    Gravity Forms form object.
	 * @param array  $row     CSV row values, indexed by column.
	 * @param array  $mapping Map of target key to column index.
	 * @param array  $entry   Entry array to merge resolved file URLs into.
	 * @return array|WP_Error {
	 *     On success.
	 *
	 *     @type array $entry         Entry array with every mapped File
	 *                                Upload field's value resolved to a real
	 *                                GF-hosted file URL (JSON-encoded array
	 *                                of one URL for a multi-file field,
	 *                                otherwise a plain URL string).
	 *     @type array $created_paths Absolute paths of every file this call
	 *                                copied into Gravity Forms' own uploads
	 *                                folder, so the caller can roll them back
	 *                                if a later step (add_entry()/
	 *                                update_entry()) fails.
	 * }
	 *                        Or a WP_Error when any mapped File Upload column
	 *                        could not be resolved.
	 */
	public static function resolve_row_files( $job_id, array $form, array $row, array $mapping, array $entry ) {
		$fields  = GEI_Mapper::index_fields( $form );
		$created = array();

		foreach ( $mapping as $key => $column ) {
			if ( '' === $column || null === $column || ! isset( $row[ $column ] ) ) {
				continue;
			}

			$field = isset( $fields[ (string) $key ] ) ? $fields[ (string) $key ] : null;
			$type  = ( null !== $field && isset( $field->type ) ) ? $field->type : '';

			if ( 'fileupload' !== $type ) {
				continue;
			}

			$raw = trim( (string) $row[ $column ] );

			if ( '' === $raw ) {
				continue;
			}

			$result = self::resolve_one( $form, $field, $job_id, $raw );

			if ( is_wp_error( $result ) ) {
				self::cleanup_paths( $created );

				return $result;
			}

			$created[] = $result['path'];

			$entry[ $key ] = ! empty( $field->multipleFiles )
				? wp_json_encode( array( $result['url'] ) )
				: $result['url'];
		}

		return array(
			'entry'         => $entry,
			'created_paths' => $created,
		);
	}

	/**
	 * Resolves one CSV cell (already known to be non-blank) into a real,
	 * Gravity-Forms-hosted file.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $form      Gravity Forms form object.
	 * @param object $field     The File Upload field this cell is mapped to.
	 * @param string $job_id    Job identifier.
	 * @param string $raw_value Trimmed, non-blank raw CSV cell value.
	 * @return array|WP_Error array( 'url' => string, 'path' => string ) on
	 *                        success, or an error.
	 */
	protected static function resolve_one( array $form, $field, $job_id, $raw_value ) {
		if ( self::is_url_value( $raw_value ) ) {
			$remote = self::resolve_remote( $raw_value );

			if ( is_wp_error( $remote ) ) {
				return $remote;
			}

			return self::validate_and_place( $form, $field, $remote['path'], $remote['filename'], true );
		}

		$local = self::resolve_local( $job_id, $raw_value );

		if ( is_wp_error( $local ) ) {
			return $local;
		}

		return self::validate_and_place( $form, $field, $local, wp_basename( $local ), false );
	}

	/**
	 * Resolves the "local, pre-staged file" source mode for one CSV cell.
	 *
	 * The CSV is trusted for nothing beyond "which file": basename() strips
	 * any directory component the cell might carry (so
	 * "../../../wp-config.php" becomes just "wp-config.php"), and
	 * sanitize_file_name() then strips characters a filesystem path could
	 * still smuggle through. Neither transform is trusted on its own - the
	 * final containment check resolves the *real*, symlink-free path with
	 * realpath() and confirms it still lands inside this job's own
	 * pre-staged directory before the file is ever opened or copied, the
	 * same realpath()-then-strpos() containment pattern
	 * GEI_Storage::delete_job_file() already applies before deleting a path,
	 * applied here before reading one instead. A candidate that fails any
	 * step is rejected outright, never "resolved to the closest safe match".
	 * One alias realpath() cannot resolve away: an NTFS hard link has no
	 * separate target, so a bare filename inside the pre-staged directory
	 * that is actually a hard link to a file elsewhere (e.g. wp-config.php)
	 * passes the containment check trivially. A stat()-based link-count
	 * check (@since 1.4.1) rejects any candidate with more than one hard
	 * link, since a file staged the ordinary way never has one.
	 *
	 * @since 1.4.0
	 *
	 * @param string $job_id   Job identifier.
	 * @param string $filename Raw CSV cell value (a claimed bare filename).
	 * @return string|WP_Error Absolute path to the validated source file, or
	 *                         an error.
	 */
	protected static function resolve_local( $job_id, $filename ) {
		$dir = GEI_Storage::get_files_dir( $job_id );

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return new WP_Error(
				'gei_file_no_dir',
				__( 'No pre-staged files directory exists for this import yet. Stage the batch of files first.', 'gravity-entry-import' )
			);
		}

		$candidate = sanitize_file_name( basename( (string) $filename ) );

		if ( '' === $candidate ) {
			return new WP_Error(
				'gei_file_bad_name',
				__( 'That value does not look like a usable filename.', 'gravity-entry-import' )
			);
		}

		$real_dir  = realpath( $dir );
		$real_path = realpath( trailingslashit( $dir ) . $candidate );

		// realpath() returns false for anything that does not exist on disk,
		// which also catches every traversal attempt basename()/
		// sanitize_file_name() did not already neutralise: if a candidate
		// somehow still pointed outside $dir, it would resolve to a real
		// path elsewhere, and the containment check just below rejects it on
		// that basis instead of this one.
		if ( false === $real_dir || false === $real_path ) {
			return new WP_Error(
				'gei_file_not_found',
				__( 'That file was not found in this import\'s pre-staged files directory.', 'gravity-entry-import' )
			);
		}

		if ( 0 !== strpos( wp_normalize_path( $real_path ), trailingslashit( wp_normalize_path( $real_dir ) ) ) ) {
			return new WP_Error(
				'gei_file_outside_dir',
				__( 'That file is not inside this import\'s pre-staged files directory.', 'gravity-entry-import' )
			);
		}

		if ( ! is_file( $real_path ) || ! is_readable( $real_path ) ) {
			return new WP_Error(
				'gei_file_unreadable',
				__( 'That file could not be read.', 'gravity-entry-import' )
			);
		}

		// A hard link has no separate target for realpath() to resolve away,
		// unlike the symlink/junction case the containment check above
		// already catches: it IS the same file via a second directory entry,
		// so it passes that check trivially even when created (e.g. via
		// `mklink /H` on NTFS, which needs no elevated privilege) to also
		// alias a file well outside this job's own directory, such as
		// wp-config.php. A file staged the ordinary way (SFTP, a host's file
		// manager, a real copy) always has exactly one link; anything else is
		// rejected outright. The message deliberately does not explain why,
		// so it does not have to teach this to anyone it is shown to.
		$stat = @stat( $real_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $stat || ! isset( $stat['nlink'] ) || 1 !== (int) $stat['nlink'] ) {
			return new WP_Error(
				'gei_file_hardlinked',
				__( 'That file could not be read safely.', 'gravity-entry-import' )
			);
		}

		return $real_path;
	}

	/**
	 * Resolves the "remote URL" source mode for one CSV cell.
	 *
	 * Every request this method makes goes through wp_safe_remote_head()/
	 * wp_safe_remote_get() specifically - WordPress core's SSRF-hardened HTTP
	 * functions, which validate the URL (and every redirect it follows) with
	 * wp_http_validate_url() before opening a connection, rejecting a
	 * loopback, private-network or link-local address (which covers a cloud
	 * metadata endpoint such as 169.254.169.254) unless a site has
	 * explicitly opted back in via the 'http_request_host_is_external'
	 * filter. This plugin never bypasses or reconfigures that protection -
	 * it adds to it: wp_http_validate_url() itself deliberately skips that
	 * entire rejection block when a URL's host matches this site's own
	 * home_url() host (a WP core carve-out for legitimate internal uses
	 * elsewhere in core), which would otherwise let a CSV cell naming this
	 * site's own domain reach any internal/admin-only path on it with a
	 * genuine, authenticated-as-itself request. This plugin's own use case
	 * never needs that, so a same-host URL is refused here outright, before
	 * either request, independently of and in addition to wp_http_validate_url()'s
	 * own checks (@since 1.4.1).
	 *
	 * Two gaps in that same-host comparison, found in a later review, are
	 * closed as of 1.4.2:
	 *
	 * - wp_parse_url() preserves a trailing dot on a hostname (e.g.
	 *   "example.com." - a standard DNS root-label form that resolves
	 *   identically to the undotted name). The comparison previously did not
	 *   strip it, so a dotted variant of this site's own host
	 *   string-compared unequal to home_url()'s own (undotted) host and
	 *   sailed through this guard - and through wp_http_validate_url()'s own,
	 *   separately dot-blind same-host carve-outs further down the line -
	 *   reaching this site's own backend directly. Both sides are now
	 *   trimmed of a trailing dot before comparison.
	 * - The comparison only ever matched a URL naming this site by hostname.
	 *   A CSV cell naming this site by its already-resolved IP address
	 *   instead (a raw IP literal) never string-matched a hostname no
	 *   matter how it was normalised. hosts_share_an_ip() now also resolves
	 *   each side to an IP address and rejects on any overlap - see its own
	 *   DocBlock for the deliberately proportionate scope of that check and
	 *   the timing limitation it shares with the DNS-rebinding risk already
	 *   disclosed in this plugin's readme.
	 *
	 * Before either request: the URL's scheme is checked directly against an
	 * "http"/"https" allowlist, so a file://, ftp:// or gopher:// value is
	 * rejected outright without this method ever constructing a request for
	 * it, rather than relying solely on wp_http_validate_url()'s own scheme
	 * check further down the line.
	 *
	 * Size is enforced twice, for two different reasons: a HEAD request
	 * reads Content-Length first and aborts before a single byte of the
	 * actual file is requested if it is missing or already over the cap -
	 * cheap, and enough for an honest server. The real GET then still caps
	 * the download itself, via 'limit_response_size' (the same mechanism
	 * WordPress core's own download_url() uses, enforced by the HTTP
	 * transport while streaming to disk, not after the fact) and a final
	 * filesize() check against the bytes actually written - because a server
	 * can lie about, or omit, Content-Length on the real response even after
	 * answering the HEAD honestly.
	 *
	 * @since 1.4.0
	 *
	 * @param string $url Raw CSV cell value (already known to look like a URL).
	 * @return array|WP_Error array( 'path' => string, 'filename' => string )
	 *                        on success - $path is this method's own
	 *                        temporary file, which the caller must delete
	 *                        once done with it - or an error.
	 */
	protected static function resolve_remote( $url ) {
		$url    = trim( (string) $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new WP_Error(
				'gei_file_bad_scheme',
				__( 'Only http:// and https:// URLs can be fetched for a File Upload column.', 'gravity-entry-import' )
			);
		}

		// wp_http_validate_url() - relied on below via wp_safe_remote_head()/
		// wp_safe_remote_get() - deliberately skips its entire private/
		// loopback/link-local rejection block when a URL's host matches this
		// site's own home_url() host, a WP core carve-out for legitimate
		// internal uses elsewhere in core. This plugin hands it a fully
		// CSV-controlled URL, so without a check of its own, a CSV cell
		// naming this site's own domain would sail through that carve-out and
		// reach any internal/admin-only path on it, with this server issuing
		// a genuine authenticated-as-itself request to itself. Attaching an
		// uploaded file to an entry has no legitimate reason to ever fetch
		// from this site's own domain, so that host is refused outright here,
		// before any request - HEAD or GET - is ever attempted.
		//
		// Both hostnames are trimmed of a trailing dot before comparison
		// (1.4.2): wp_parse_url() preserves it verbatim, so
		// "example.com." - a standard DNS root-label form that resolves
		// identically to the undotted name - previously string-compared
		// unequal to the undotted home host and defeated this guard
		// entirely (and, independently, two of WordPress core's own
		// same-host carve-outs further down the line, which are each
		// equally dot-blind in their own raw string comparisons).
		$url_host  = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_HOST ), '.' ) );
		$home_host = strtolower( rtrim( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '.' ) );

		// A hostname-string comparison alone still misses a CSV cell that
		// names this site by its already-resolved IP address instead of its
		// domain name (1.4.2) - see hosts_share_an_ip()'s own DocBlock for
		// what that check does and does not guarantee.
		if ( '' === $url_host || ( '' !== $home_host && ( $url_host === $home_host || self::hosts_share_an_ip( $url_host, $home_host ) ) ) ) {
			return new WP_Error(
				'gei_file_same_host',
				__( 'A URL pointing at this site itself cannot be used as a File Upload source.', 'gravity-entry-import' )
			);
		}

		$max_bytes = self::max_bytes();

		$head = wp_safe_remote_head( $url, array( 'timeout' => self::REMOTE_TIMEOUT ) );

		if ( is_wp_error( $head ) ) {
			return new WP_Error( 'gei_file_fetch_failed', $head->get_error_message() );
		}

		$head_code = (int) wp_remote_retrieve_response_code( $head );

		if ( $head_code < 200 || $head_code >= 300 ) {
			return new WP_Error(
				'gei_file_fetch_failed',
				sprintf(
					/* translators: %d: HTTP response code. */
					__( 'The remote server returned HTTP %d.', 'gravity-entry-import' ),
					$head_code
				)
			);
		}

		$content_length = wp_remote_retrieve_header( $head, 'content-length' );

		if ( '' === (string) $content_length || ! is_numeric( $content_length ) ) {
			return new WP_Error(
				'gei_file_no_size',
				__( 'The remote server did not report a file size for that URL, so it was not fetched.', 'gravity-entry-import' )
			);
		}

		if ( (int) $content_length > $max_bytes ) {
			return new WP_Error(
				'gei_file_too_large',
				sprintf(
					/* translators: %s: maximum file size, formatted for display (e.g. "10 MB"). */
					__( 'The remote file is larger than the %s limit.', 'gravity-entry-import' ),
					size_format( $max_bytes )
				)
			);
		}

		// wp_tempnam() lives in wp-admin/includes/file.php, which every
		// real caller of this method already has loaded (the batch AJAX
		// endpoint is only ever reached through admin-ajax.php, which pulls
		// in the full wp-admin/includes/admin.php bootstrap before this
		// plugin's own hooks ever run) - guarded explicitly anyway so this
		// never hard-fatals if some future caller (a WP-CLI command, a
		// cron-driven batch) reaches this method without that bootstrap.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp_path = wp_tempnam( 'gei-file' );

		if ( ! $tmp_path ) {
			return new WP_Error( 'gei_file_tmp', __( 'A temporary file could not be created.', 'gravity-entry-import' ) );
		}

		// Capped at $max_bytes + 1, not $max_bytes: 'limit_response_size'
		// truncates silently rather than erroring, so a response that was
		// genuinely larger than the cap would otherwise be cut down to
		// exactly $max_bytes and then pass the "> $max_bytes" check below as
		// if it had always been that size. Requesting one extra byte means a
		// truncated-at-the-cap file always lands at $max_bytes + 1 (caught
		// below), while a genuinely complete file of exactly $max_bytes stays
		// at $max_bytes (correctly accepted) - the only way to tell "exactly
		// at the limit" apart from "cut off at the limit" after the fact.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::REMOTE_TIMEOUT,
				'stream'              => true,
				'filename'            => $tmp_path,
				'limit_response_size' => $max_bytes + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::delete_tmp( $tmp_path );

			return new WP_Error( 'gei_file_fetch_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			self::delete_tmp( $tmp_path );

			return new WP_Error(
				'gei_file_fetch_failed',
				sprintf(
					/* translators: %d: HTTP response code. */
					__( 'The remote server returned HTTP %d.', 'gravity-entry-import' ),
					$code
				)
			);
		}

		// Belt and suspenders: checked against the bytes actually written to
		// disk rather than trusting 'limit_response_size' alone - see this
		// method's own DocBlock for why a server's Content-Length (checked
		// above, before the download even started) cannot be the only guard.
		$actual_size = @filesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $actual_size || 0 === $actual_size || $actual_size > $max_bytes ) {
			self::delete_tmp( $tmp_path );

			return new WP_Error(
				'gei_file_too_large',
				__( 'The downloaded file was empty, or larger than the size limit.', 'gravity-entry-import' )
			);
		}

		$filename = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

		if ( '' === $filename ) {
			self::delete_tmp( $tmp_path );

			return new WP_Error(
				'gei_file_bad_name',
				__( 'Could not determine a file name from that URL.', 'gravity-entry-import' )
			);
		}

		return array(
			'path'     => $tmp_path,
			'filename' => $filename,
		);
	}

	/**
	 * Determines whether two already-normalised hostnames (or raw IP
	 * literals) resolve to at least one IP address in common.
	 *
	 * resolve_remote()'s own same-host guard compares hostname strings
	 * first; that alone misses a CSV URL that names this site not by its
	 * domain but by an IP address it already resolves to (a raw IP
	 * literal), which never string-matches a hostname no matter how it is
	 * normalised. This closes that gap by also comparing each side's
	 * resolved IP address(es).
	 *
	 * Deliberately a proportionate check, not a full resolver: a side that
	 * is already a raw IP literal (IPv4 or IPv6) is used directly, with no
	 * DNS lookup at all; a side that is a hostname is resolved with a
	 * single resolve_host_ip() call (one A record via gethostbyname() -
	 * IPv4 only, the common case this finding demonstrated, not an
	 * exhaustive multi-record or AAAA lookup). A hostname that cannot be
	 * resolved contributes no IP and therefore cannot cause a false match.
	 *
	 * Shares the same inherent timing limitation as the DNS-rebinding
	 * residual risk already disclosed in this plugin's readme (Known
	 * limitations): the IP resolved here is only a snapshot at the moment
	 * this check runs, not a guarantee of the IP a later
	 * wp_safe_remote_head()/wp_safe_remote_get() call actually connects to.
	 * This check closes the "an obvious raw IP literal matching this site's
	 * own address" gap the finding demonstrated - it is not, and is not
	 * meant to be, airtight against a determined DNS-level attack.
	 *
	 * @since 1.4.2
	 *
	 * @param string $host_a First hostname or IP literal, already trimmed and lowercased.
	 * @param string $host_b Second hostname or IP literal, already trimmed and lowercased.
	 * @return bool True when both sides resolve to at least one common IP address.
	 */
	protected static function hosts_share_an_ip( $host_a, $host_b ) {
		$ip_a = self::resolve_host_ip( $host_a );
		$ip_b = self::resolve_host_ip( $host_b );

		return ( '' !== $ip_a && '' !== $ip_b && $ip_a === $ip_b );
	}

	/**
	 * Resolves one hostname to a single IP address, or passes a raw IP
	 * literal through unchanged.
	 *
	 * Used only by hosts_share_an_ip() - see its own DocBlock for the scope
	 * and limitations of the check this feeds into.
	 *
	 * @since 1.4.2
	 *
	 * @param string $host Hostname or IP literal.
	 * @return string Resolved (or passed-through) IP address, or '' when
	 *                $host is blank or could not be resolved.
	 */
	protected static function resolve_host_ip( $host ) {
		if ( '' === $host ) {
			return '';
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}

		// gethostbyname() returns the hostname unchanged - not false - when
		// it cannot resolve it, so that case is checked for explicitly
		// rather than trusted to signal failure on its own.
		$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_string( $resolved ) || $resolved === $host || false === filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return $resolved;
	}

	/**
	 * Validates a resolved source file's real content type and extension,
	 * and copies it into Gravity Forms' own per-form upload structure - the
	 * same destination a genuine browser submission to this field would use.
	 *
	 * Mirrors GF_Field_FileUpload::is_invalid_file()'s own new-file branch
	 * rather than re-implementing an allowlist: the same
	 * GFCommon::check_type_and_ext() call Gravity Forms' own upload handler
	 * relies on (which itself wraps wp_check_filetype_and_ext() - WordPress
	 * core's real content-sniffing check, not a trust-the-extension or
	 * trust-the-client-Content-Type check), the same field-configured
	 * allowed-extensions list via $field->get_clean_allowed_extensions(),
	 * and the same denylist of inherently dangerous extensions via
	 * GFCommon::file_name_has_disallowed_extension() for a field with no
	 * allowlist configured. A file that fails any of these is rejected
	 * before this method ever calls GFFormsModel::get_file_upload_path() or
	 * writes anything into Gravity Forms' real uploads folder - the CSV
	 * never gets to determine file size limits, allowed types or the
	 * destination path; only this plugin's own logic and the field's own
	 * configuration do.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $form          Gravity Forms form object.
	 * @param object $field         The File Upload field.
	 * @param string $source_path   Absolute path to the already-fetched/staged file.
	 * @param string $filename      The file's claimed name (used for the
	 *                              extension/content-type checks and the
	 *                              name it is copied into GF's uploads folder as).
	 * @param bool   $delete_source Whether $source_path is this class's own
	 *                              temporary file to delete once done with it
	 *                              (true for a remote download; false for a
	 *                              local, admin-owned pre-staged file, which
	 *                              is only ever copied from here, never deleted).
	 * @return array|WP_Error array( 'url' => string, 'path' => string ) on
	 *                        success, or an error.
	 */
	protected static function validate_and_place( array $form, $field, $source_path, $filename, $delete_source ) {
		$reject = static function ( $code, $message ) use ( $source_path, $delete_source ) {
			if ( $delete_source ) {
				GEI_File_Field::delete_tmp( $source_path );
			}

			return new WP_Error( $code, $message );
		};

		if ( ! $field->is_check_type_and_ext_disabled() ) {
			$check = GFCommon::check_type_and_ext(
				array(
					'tmp_name' => $source_path,
					'name'     => $filename,
				),
				$filename
			);

			if ( is_wp_error( $check ) ) {
				return $reject( 'gei_file_type', $check->get_error_message() );
			}
		}

		$allowed = $field->get_clean_allowed_extensions();

		if ( ! empty( $allowed ) ) {
			if ( ! GFCommon::match_file_extension( $filename, $allowed ) ) {
				return $reject(
					'gei_file_ext',
					sprintf(
						/* translators: %s: comma-separated list of allowed file extensions. */
						__( 'That file type is not allowed for this field. Allowed: %s', 'gravity-entry-import' ),
						implode( ', ', $allowed )
					)
				);
			}
		} elseif ( GFCommon::file_name_has_disallowed_extension( $filename ) ) {
			return $reject( 'gei_file_ext', __( 'That file type is not allowed.', 'gravity-entry-import' ) );
		}

		$max_field_bytes = (int) $field->get_max_file_size_bytes();
		$actual_size     = @filesize( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $actual_size && 0 < $max_field_bytes && $actual_size > $max_field_bytes ) {
			return $reject( 'gei_file_field_too_large', $field->get_size_validation_message() );
		}

		$target = GFFormsModel::get_file_upload_path( (int) $form['id'], $filename );

		if ( false === $target ) {
			return $reject( 'gei_file_target', __( 'The upload folder for this form could not be created.', 'gravity-entry-import' ) );
		}

		// copy(), never rename()/move(): a local pre-staged file belongs to
		// the admin and a later row (or a re-run of this same import) may
		// need to read it again, and a remote download's own temp file is
		// explicitly deleted right below by this same method rather than
		// relied upon to vanish as a side effect of being moved.
		if ( ! copy( $source_path, $target['path'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return $reject( 'gei_file_copy', __( 'The file could not be copied into the form\'s upload folder.', 'gravity-entry-import' ) );
		}

		// Matches the permissions a genuine upload through this field would
		// get - GFFormsModel::set_permissions() is Gravity Forms' own,
		// filterable call for this, not a hardcoded chmod().
		GFFormsModel::set_permissions( $target['path'] );

		if ( $delete_source ) {
			self::delete_tmp( $source_path );
		}

		return array(
			'url'  => $target['url'],
			'path' => $target['path'],
		);
	}

	/**
	 * Deletes every path in a list of files this class copied into Gravity
	 * Forms' own uploads folder, best-effort.
	 *
	 * Used by GEI_Importer to roll back a row whose file(s) were
	 * successfully resolved and placed, but whose GFAPI::add_entry()/
	 * update_entry() call then failed for an unrelated reason: without this,
	 * that file would become permanently orphaned inside Gravity Forms' own
	 * uploads folder, a location neither this plugin's stale-job pruning nor
	 * its uninstall sweep has any reason to ever look inside.
	 *
	 * @since 1.4.0
	 *
	 * @param array $paths Absolute paths, as returned in resolve_row_files()'s
	 *                     'created_paths'.
	 * @return void
	 */
	public static function cleanup_paths( array $paths ) {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Deletes one temporary file, ignoring a path that is already gone.
	 *
	 * Shared by every exit point of resolve_remote() (success feeds it to
	 * validate_and_place(), which itself deletes it once done; every
	 * rejection branch inside resolve_remote() deletes it directly) so a
	 * fetch that is rejected for any reason - a bad response code, an
	 * over-cap size, an unusable filename - never leaves its own download
	 * sitting in wp_tempnam()'s temporary directory.
	 *
	 * @since 1.4.0
	 *
	 * @param string $path Absolute path to a temporary file.
	 * @return void
	 */
	public static function delete_tmp( $path ) {
		if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
