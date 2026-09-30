<?php
/**
 * Streaming CSV reader.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a CSV file in batches, resuming from a stored byte offset.
 *
 * Row numbers alone would force a re-scan from the top of the file on every
 * batch, which turns a large import into O(n^2) reads. Storing the byte offset
 * lets each batch seek straight to where the previous one stopped.
 *
 * @since 1.0.0
 */
class GEI_CSV_Reader {

	/**
	 * Absolute path to the CSV file.
	 *
	 * @var string
	 */
	protected $path;

	/**
	 * Field delimiter.
	 *
	 * @var string
	 */
	protected $delimiter;

	/**
	 * Field enclosure character.
	 *
	 * @var string
	 */
	protected $enclosure;

	/**
	 * Escape character passed to fgetcsv().
	 *
	 * Deliberately empty. PHP's historical default of "\" is not part of the
	 * CSV format and mangles values ending in a backslash, and from PHP 8.4
	 * omitting the argument raises a deprecation — which, on an admin-ajax
	 * response, corrupts the JSON the importer's progress loop reads.
	 *
	 * @var string
	 */
	protected $escape = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path      Absolute path to the CSV file.
	 * @param string $delimiter Field delimiter. Default ','.
	 * @param string $enclosure Field enclosure. Default '"'.
	 */
	public function __construct( $path, $delimiter = ',', $enclosure = '"' ) {
		$this->path      = $path;
		$this->delimiter = ( '' === $delimiter ) ? ',' : $delimiter;
		$this->enclosure = ( '' === $enclosure ) ? '"' : $enclosure;
	}

	/**
	 * Determines whether the file exists and is readable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the file can be read.
	 */
	public function is_readable() {
		return ( '' !== $this->path && file_exists( $this->path ) && is_readable( $this->path ) );
	}

	/**
	 * Guesses the delimiter by comparing candidate counts on the header line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path to the CSV file.
	 * @return string The most likely delimiter. Defaults to ','.
	 */
	public static function detect_delimiter( $path ) {
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return ',';
		}

		$line = fgets( $handle, 8192 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $line ) {
			return ',';
		}

		$candidates = array( ',', ';', "\t", '|' );
		$best       = ',';
		$best_count = 0;

		foreach ( $candidates as $candidate ) {
			$count = substr_count( $line, $candidate );
			if ( $count > $best_count ) {
				$best_count = $count;
				$best       = $candidate;
			}
		}

		return $best;
	}

	/**
	 * Reads the header row.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error Column labels, or an error.
	 */
	public function get_header() {
		if ( ! $this->is_readable() ) {
			return new WP_Error( 'gei_unreadable', __( 'The uploaded CSV could no longer be read. Upload it again.', 'gravity-entry-import' ) );
		}

		$handle = @fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return new WP_Error( 'gei_open', __( 'The uploaded CSV could not be opened.', 'gravity-entry-import' ) );
		}

		$row = fgetcsv( $handle, 0, $this->delimiter, $this->enclosure, $this->escape );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_array( $row ) ) {
			return new WP_Error( 'gei_empty', __( 'The CSV appears to be empty.', 'gravity-entry-import' ) );
		}

		return $this->clean_row( $row, true );
	}

	/**
	 * Returns the byte offset of the first data row.
	 *
	 * @since 1.0.0
	 *
	 * @return int Byte offset immediately after the header row.
	 */
	public function get_first_data_offset() {
		$handle = @fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return 0;
		}

		fgetcsv( $handle, 0, $this->delimiter, $this->enclosure, $this->escape );
		$offset = ftell( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return (int) $offset;
	}

	/**
	 * Reads up to $limit rows starting at a byte offset.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Byte offset to seek to before reading.
	 * @param int $limit  Maximum number of rows to return.
	 * @return array {
	 *     @type array $rows    List of row arrays.
	 *     @type array $offsets Byte offset immediately after each returned row,
	 *                          parallel to $rows, so a caller can checkpoint
	 *                          its progress per row rather than per batch.
	 *     @type int   $next    Byte offset for the following batch.
	 *     @type bool  $eof     Whether the end of the file was reached.
	 * }
	 */
	public function read_batch( $offset, $limit ) {
		$result = array(
			'rows'    => array(),
			'offsets' => array(),
			'next'    => (int) $offset,
			'eof'     => true,
		);

		if ( ! $this->is_readable() ) {
			return $result;
		}

		$handle = @fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return $result;
		}

		if ( 0 < $offset ) {
			fseek( $handle, $offset );
		}

		$read = 0;
		while ( $read < $limit ) {
			$row = fgetcsv( $handle, 0, $this->delimiter, $this->enclosure, $this->escape );

			if ( false === $row || null === $row ) {
				break;
			}

			// fgetcsv yields array( null ) for a blank line; skip without counting.
			if ( array( null ) === $row ) {
				continue;
			}

			$result['rows'][]    = $this->clean_row( $row, false );
			$result['offsets'][] = (int) ftell( $handle );
			++$read;
		}

		$result['next'] = (int) ftell( $handle );
		$result['eof']  = feof( $handle );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $result;
	}

	/**
	 * Counts the data rows in the file, excluding the header.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of data rows.
	 */
	public function count_rows() {
		if ( ! $this->is_readable() ) {
			return 0;
		}

		$handle = @fopen( $this->path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return 0;
		}

		$count = 0;
		$first = true;

		while ( false !== ( $row = fgetcsv( $handle, 0, $this->delimiter, $this->enclosure, $this->escape ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( $first ) {
				$first = false;
				continue;
			}

			if ( array( null ) === $row ) {
				continue;
			}

			++$count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $count;
	}

	/**
	 * Normalizes encoding and strips a UTF-8 BOM from the first cell.
	 *
	 * Excel writes a BOM on UTF-8 exports, which otherwise becomes part of the
	 * first column label and breaks header matching.
	 *
	 * @since 1.0.0
	 *
	 * @param array $row       Raw row from fgetcsv().
	 * @param bool  $is_header Whether this row is the header row.
	 * @return array Cleaned row.
	 */
	protected function clean_row( array $row, $is_header ) {
		foreach ( $row as $i => $value ) {
			$value = ( null === $value ) ? '' : (string) $value;

			if ( 0 === $i ) {
				$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
			}

			// mbstring is not guaranteed and WordPress does not polyfill this
			// one, so a missing extension must degrade rather than fatal.
			if ( ! seems_utf8( $value ) && function_exists( 'mb_convert_encoding' ) ) {
				$value = mb_convert_encoding( $value, 'UTF-8', 'Windows-1252' );
			}

			$row[ $i ] = $is_header ? trim( $value ) : $value;
		}

		return $row;
	}
}
