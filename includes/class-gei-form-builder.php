<?php
/**
 * Generates a new Gravity Forms form from a CSV's header row.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a brand new form, one field per CSV column, as an alternative to
 * importing into a form the admin already built by hand.
 *
 * Exists so the Upload step can offer "Create a new form from this CSV's
 * headers" without any special-casing anywhere else in this plugin: once
 * GFAPI::add_form() has created the form, every field's label is, by
 * construction, exactly the header text that produced it, so
 * GEI_Mapper::suggest_mapping()'s existing fuzzy header-matching maps every
 * column straight back onto its own generated field with no changes to the
 * mapping logic itself (see GEI_Admin::handle_upload_post() for the one new
 * code path that calls this, before the normal Map step ever renders).
 *
 * The type-guessing heuristic below is deliberately simple and conservative:
 * case-insensitive substring matching against a handful of common header
 * words, defaulting to a plain text field whenever nothing more specific
 * matches, and never guessing a field is required. It is a starting point
 * for an admin to review in the form editor, not a promise that the guessed
 * types are correct for any given CSV - see this plugin's readme (Known
 * limitations) for the same point made for the admin reading it.
 *
 * @since 1.5.0
 */
class GEI_Form_Builder {

	/**
	 * Maximum number of fields this builder will generate from a single CSV's
	 * header row.
	 *
	 * Mirrors GEI_Templates::MAX_PER_FORM / GEI_History::MAX_PER_FORM /
	 * GEI_Validator::MAX_LOGGED_ISSUES / GEI_Importer::MAX_LOGGED_ERRORS for
	 * the same reason: an unbounded CSV header (hundreds or thousands of
	 * columns, malformed or otherwise) should fail with a clear error rather
	 * than silently hand GFAPI::add_form() a form definition large enough to
	 * be impractical to load, edit or use.
	 *
	 * @since 1.5.1
	 *
	 * @var int
	 */
	const MAX_FIELDS = 150;

	/**
	 * Guesses a simple Gravity Forms field type from a CSV column header.
	 *
	 * Checked in a fixed order - email, then phone, then date, then a
	 * note/comment textarea, else plain text - so a header matching more
	 * than one rule (e.g. "Email Date Sent") resolves predictably rather
	 * than depending on iteration order. Deliberately broad, substring-only
	 * matching, exactly as scoped: "tel" matches not only "Telephone" but
	 * also, say, "Detail" or "Hotel Name" - an accepted false-positive risk
	 * of the simple heuristic this feature asks for, not a bug, and exactly
	 * why every generated field is meant to be reviewed afterward rather
	 * than trusted outright (see this plugin's readme).
	 *
	 * @since 1.5.0
	 *
	 * @param string $header_label Raw CSV column header text.
	 * @return string A Gravity Forms field type key: 'email', 'phone',
	 *                'date', 'textarea' or 'text'.
	 */
	public static function guess_field_type( $header_label ) {
		$label = strtolower( (string) $header_label );

		if ( false !== strpos( $label, 'email' ) ) {
			return 'email';
		}

		if ( false !== strpos( $label, 'phone' ) || false !== strpos( $label, 'tel' ) ) {
			return 'phone';
		}

		if ( false !== strpos( $label, 'date' ) ) {
			return 'date';
		}

		if ( false !== strpos( $label, 'note' ) || false !== strpos( $label, 'comment' ) ) {
			return 'textarea';
		}

		return 'text';
	}

	/**
	 * Creates a new form with one field per CSV column.
	 *
	 * Builds plain associative-array field definitions - the same shape
	 * GFAPI::add_form() already accepts and converts into real GF_Field
	 * objects itself via GFFormsModel::convert_field_objects() - rather than
	 * instantiating GF_Field_* classes directly, so this never has to know
	 * about or depend on any field class beyond the type keys it generates.
	 * Field IDs are left unset; GFAPI::add_form() assigns them (see
	 * GFAPI::add_missing_ids(), called internally), the same as every other
	 * caller of that API.
	 *
	 * Every generated field is created with `isRequired` explicitly false:
	 * nothing about a header's text or a guessed type is ever a reliable
	 * enough signal that a column's data is actually mandatory, and a false
	 * positive here would block a real import on a field an admin never
	 * meant to make required. The form title is de-duplicated by
	 * GFAPI::add_form() itself (via its own unique_title() call) exactly like
	 * any other new form, so the title actually assigned - returned via the
	 * reloaded form, not echoed back from the input - may differ slightly
	 * from what was requested when a form of that name already exists.
	 *
	 * Each header cell is run through sanitize_text_field() before becoming a
	 * field's label - the same treatment the form title above already gets
	 * from this method's own caller, and the same tag-stripping
	 * GEI_Mapper::suggest_mapping() already applies to a label it reads back
	 * off a field (see its own wp_strip_all_tags() call). GFAPI::add_form()
	 * performs no sanitization of its own, and while Gravity Forms' present
	 * field-rendering path happens to escape a label on output, this plugin
	 * does not rely on that holding true forever - CSV header text is
	 * untrusted input and is sanitized at the point it becomes form data,
	 * the same boundary every other user-supplied string in this plugin is
	 * already sanitized at.
	 *
	 * @since 1.5.0
	 * @since 1.5.1 Each header cell is now run through sanitize_text_field()
	 *              before becoming a field label, and a header with more
	 *              than MAX_FIELDS columns is rejected rather than building
	 *              an unbounded form.
	 *
	 * @param string $title  Requested form title (caller is expected to have
	 *                        already run this through sanitize_text_field(),
	 *                        matching every other user-supplied string in
	 *                        this plugin).
	 * @param array  $header CSV column header labels, in column order.
	 * @return int|WP_Error The new form's ID, or an error.
	 */
	public static function create_form_from_header( $title, array $header ) {
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return new WP_Error( 'gei_no_title', __( 'Give the new form a name.', 'gravity-entry-import' ) );
		}

		if ( count( $header ) > self::MAX_FIELDS ) {
			return new WP_Error(
				'gei_too_many_columns',
				sprintf(
					/* translators: %d: maximum number of fields this plugin will generate from a CSV header. */
					__( 'That CSV has too many columns to generate a form from (more than %d). Split the file, or build the form by hand instead.', 'gravity-entry-import' ),
					self::MAX_FIELDS
				)
			);
		}

		$fields = array();

		foreach ( $header as $label ) {
			$label = sanitize_text_field( (string) $label );

			// A blank header cell still needs a usable label - mirrors the
			// mapping screen's own "Column N" fallback for the exact same
			// case (see GEI_Admin::render_map_step()), so a generated field
			// is never left with an empty label an admin would otherwise
			// have to notice and fix by hand before the form is usable. Also
			// covers a header cell that sanitizes down to nothing (e.g. a
			// cell containing only markup) exactly the same way.
			if ( '' === $label ) {
				$label = sprintf(
					/* translators: %d: 1-based CSV column number. */
					__( 'Column %d', 'gravity-entry-import' ),
					count( $fields ) + 1
				);
			}

			$fields[] = array(
				'type'       => self::guess_field_type( $label ),
				'label'      => $label,
				'isRequired' => false,
			);
		}

		if ( empty( $fields ) ) {
			return new WP_Error( 'gei_no_header', __( 'The CSV has no columns to build fields from.', 'gravity-entry-import' ) );
		}

		$form_id = GFAPI::add_form(
			array(
				'title'  => $title,
				'fields' => $fields,
			)
		);

		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		return (int) $form_id;
	}
}
