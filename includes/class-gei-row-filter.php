<?php
/**
 * Conditional row-import rule evaluation.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a single "only import rows where…" rule against a raw CSV cell.
 *
 * Deliberately a small, fixed set of comparison operators rather than
 * free-form code or a regular expression: an inline code-execution surface on
 * an admin screen was explicitly ruled out on security grounds while this
 * feature was planned, and a regex engine is its own, harder-to-audit class of
 * footgun (catastrophic backtracking, accidental partial matches) for a
 * feature whose entire job is "should this row be skipped". Six operators
 * cover the overwhelming majority of real "only import active/approved/US
 * rows" use cases without either risk.
 *
 * A rule is intentionally evaluated against the RAW CSV cell value, before
 * GEI_Mapper does anything to it: the point of this filter is "should this row
 * be imported at all", which has to be decided before any field-specific
 * normalization (date parsing, choice matching, checkbox splitting) could
 * change what the cell looks like, and has to run identically whether the
 * column ends up mapped to a form field or not.
 *
 * Stateless and side-effect free by design, so both GEI_Importer::process_batch()
 * and GEI_Validator::validate_batch() can call it against the same row and are
 * guaranteed to reach the same yes/no answer - a row the importer would skip
 * can never be the one the validator flags a possible issue on, and vice versa.
 *
 * @since 1.3.0
 */
class GEI_Row_Filter {

	/**
	 * The fixed set of comparison operators this filter supports.
	 *
	 * Used both to validate a submitted rule (GEI_Admin::handle_map_post())
	 * and to populate the operator dropdown on the mapping screen
	 * (GEI_Admin::filter_operator_labels()), so the two can never drift apart
	 * into an operator the form offers but evaluate() does not understand, or
	 * vice versa.
	 *
	 * @var array
	 */
	const OPERATORS = array(
		'equals',
		'not_equals',
		'contains',
		'not_contains',
		'is_empty',
		'is_not_empty',
	);

	/**
	 * Determines whether one CSV row satisfies a job's configured filter rule.
	 *
	 * A rule with no column configured means the filter itself is off, and
	 * every row matches - the exact "every row is imported" behaviour this
	 * plugin had before conditional rules existed, so a job that never sets a
	 * rule is unaffected. When a column is configured but this particular row
	 * has no cell at that index (a malformed, short row), the cell is treated
	 * as an empty string rather than skipped outright, so "Is empty" still
	 * behaves as an admin would expect instead of the row silently escaping
	 * the filter altogether.
	 *
	 * @since 1.3.0
	 *
	 * @param array $row  CSV row values, indexed by column.
	 * @param array $rule Filter rule: array( 'column' => int, 'operator' => string, 'value' => string ).
	 *                    An empty array (or one with no 'column') means "no filter".
	 * @return bool True when the row matches the rule (or no rule is set) and should be imported.
	 */
	public static function row_matches( array $row, array $rule ) {
		if ( ! isset( $rule['column'] ) || '' === $rule['column'] || null === $rule['column'] ) {
			return true;
		}

		$column = (int) $rule['column'];
		$value  = isset( $row[ $column ] ) ? $row[ $column ] : '';
		$op     = isset( $rule['operator'] ) ? (string) $rule['operator'] : '';
		$needle = isset( $rule['value'] ) ? $rule['value'] : '';

		return self::evaluate( $value, $op, $needle );
	}

	/**
	 * Compares one raw value against one operator/comparison-value pair.
	 *
	 * A pure function - no CSV, no job, no Gravity Forms dependency - so it
	 * can be exercised directly against fixed inputs in a unit test without
	 * any of this plugin's WordPress or GFAPI stubbing.
	 *
	 * Both sides are trimmed and compared case-insensitively, matching how
	 * the rest of this plugin already treats a CSV cell against configured
	 * text (see GEI_Mapper::match_choice_value() and ::choice_matches()): an
	 * admin typing "active" as the comparison value should match a CSV
	 * exporting "Active" just as readily as an exact case match would.
	 *
	 * @since 1.3.0
	 *
	 * @param string $cell_value    Raw CSV cell value.
	 * @param string $operator      One of self::OPERATORS.
	 * @param string $compare_value Comparison value. Ignored by is_empty/is_not_empty.
	 * @return bool True when the value satisfies the operator.
	 */
	public static function evaluate( $cell_value, $operator, $compare_value ) {
		$cell_value    = trim( (string) $cell_value );
		$compare_value = trim( (string) $compare_value );

		switch ( $operator ) {
			case 'equals':
				return ( strtolower( $cell_value ) === strtolower( $compare_value ) );

			case 'not_equals':
				return ( strtolower( $cell_value ) !== strtolower( $compare_value ) );

			case 'contains':
				// A blank comparison value can never be "contained" in a
				// meaningful sense; without this guard every row would match,
				// silently turning a half-filled-in rule into a no-op filter.
				return ( '' !== $compare_value && false !== stripos( $cell_value, $compare_value ) );

			case 'not_contains':
				return ( '' === $compare_value || false === stripos( $cell_value, $compare_value ) );

			case 'is_empty':
				return ( '' === $cell_value );

			case 'is_not_empty':
				return ( '' !== $cell_value );

			default:
				// An unrecognised operator - a job saved before an operator
				// was renamed or removed, or a tampered/stripped POST body -
				// fails open rather than silently discarding every row in
				// the file.
				return true;
		}
	}
}
