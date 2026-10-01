<?php
/**
 * Maps CSV columns onto Gravity Forms entry keys.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the list of mappable targets for a form and converts rows to entries.
 *
 * @since 1.0.0
 */
class GEI_Mapper {

	/**
	 * Prefix marking a target as entry metadata rather than a form field.
	 *
	 * @var string
	 */
	const META_PREFIX = '__';

	/**
	 * Target key for an entry note mapped from a CSV column.
	 *
	 * Listed alongside every other entry-metadata target in get_meta_targets()
	 * so it appears on the mapping screen the same way, but it is not a column
	 * on the entry array the way every other meta target is - an entry note is
	 * stored in Gravity Forms' own, separate notes table, added only after the
	 * entry itself already exists (see GEI_Importer::maybe_add_note()), never
	 * written by apply_meta(). Kept as its own constant, rather than a bare
	 * string repeated in both places, so the mapping-screen target and the
	 * importer's own read of the mapped cell can never drift apart.
	 *
	 * @since 1.5.0
	 * @var string
	 */
	const NOTE_TARGET_KEY = self::META_PREFIX . 'entry_note';

	/**
	 * Field types that hold no submitted value and cannot be imported into.
	 *
	 * @var array
	 */
	protected static $skipped_types = array(
		'html',
		'section',
		'page',
		'captcha',
		'password',
		'submit',
		'creditcard',
	);

	/**
	 * Returns the sub-inputs a field actually stores separately in an entry.
	 *
	 * A field's `inputs` property describes how it is rendered, not how it is
	 * saved. Time and Date fields render as several inputs but store a single
	 * combined string, and GF_Field::get_entry_inputs() is what distinguishes
	 * the two. Keying off `inputs` instead writes Date and Time values into a
	 * sub-input key that Gravity Forms then ignores.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @return array|null Entry inputs, or null when the field stores one value.
	 */
	protected static function get_entry_inputs( $field ) {
		if ( is_object( $field ) && method_exists( $field, 'get_entry_inputs' ) ) {
			return $field->get_entry_inputs();
		}

		return ( isset( $field->inputs ) && is_array( $field->inputs ) ) ? $field->inputs : null;
	}

	/**
	 * Coerces a GF field property to an array without PHP's scalar-cast trap.
	 *
	 * Gravity Forms' own field properties (inputs, choices) are typed loosely:
	 * a field with none configured can hold null, or an empty string, rather
	 * than an empty array. PHP's (array) cast turns a non-null scalar into a
	 * one-element array wrapping that scalar - so (array) '' becomes array('')
	 * and a "for each sub-input" loop over it then tries to treat that string
	 * as a sub-input array and fatals. is_array() is the only safe check.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value A GF field property such as ->inputs or ->choices.
	 * @return array The value unchanged if already an array, otherwise empty.
	 */
	protected static function to_array( $value ) {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Returns every target a CSV column can be mapped to for a given form.
	 *
	 * Composite fields (name, address, time, checkbox) are expanded into their
	 * sub-inputs so a CSV with separate First/Last columns maps cleanly, while
	 * the parent field is also offered for single-column sources.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form Gravity Forms form object.
	 * @return array List of targets, each with key, label, type and group.
	 */
	public static function get_targets( $form ) {
		$targets = array();

		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return $targets;
		}

		foreach ( $form['fields'] as $field ) {
			$type = isset( $field->type ) ? $field->type : '';

			if ( in_array( $type, self::$skipped_types, true ) ) {
				continue;
			}

			$field_id    = (string) $field->id;
			$field_label = isset( $field->label ) ? $field->label : sprintf( 'Field %s', $field_id );
			$inputs      = self::to_array( self::get_entry_inputs( $field ) );

			if ( ! empty( $inputs ) && 'checkbox' !== $type ) {
				// Offer the parent for single-column sources, e.g. a full name.
				$targets[] = array(
					'key'    => $field_id,
					'label'  => sprintf( '%s (whole field)', $field_label ),
					'type'   => $type,
					'group'  => $field_label,
					'scalar' => false,
				);

				foreach ( $inputs as $input ) {
					if ( ! empty( $input['isHidden'] ) ) {
						continue;
					}

					$targets[] = array(
						'key'    => (string) $input['id'],
						'label'  => sprintf( '%s &rsaquo; %s', $field_label, $input['label'] ),
						'type'   => $type,
						'group'  => $field_label,
						'scalar' => true,
					);
				}

				continue;
			}

			if ( 'checkbox' === $type ) {
				// A single column holding delimited choices is the common case.
				$targets[] = array(
					'key'    => $field_id,
					'label'  => sprintf( '%s (delimited list)', $field_label ),
					'type'   => $type,
					'group'  => $field_label,
					'scalar' => false,
				);

				foreach ( $inputs as $input ) {
					$targets[] = array(
						'key'    => (string) $input['id'],
						'label'  => sprintf( '%s &rsaquo; %s', $field_label, $input['label'] ),
						'type'   => $type,
						'group'  => $field_label,
						'scalar' => true,
					);
				}

				continue;
			}

			$targets[] = array(
				'key'    => $field_id,
				'label'  => $field_label,
				'type'   => $type,
				'group'  => $field_label,
				// Encoded values are not safe to match an entry query
				// against. A File Upload field's stored value is a URL this
				// plugin itself generates during import (see
				// GEI_File_Field::resolve_row_files()), never something the
				// CSV cell can be meaningfully compared against, so it is
				// excluded from the duplicate-match field list the same way
				// multiselect and list already are.
				'scalar' => ! in_array( $type, array( 'multiselect', 'list', 'fileupload' ), true ),
			);
		}

		foreach ( self::get_meta_targets() as $key => $label ) {
			$targets[] = array(
				'key'    => $key,
				'label'  => $label,
				'type'   => 'meta',
				'group'  => __( 'Entry metadata', 'gravity-entry-import' ),
				'scalar' => false,
			);
		}

		return $targets;
	}

	/**
	 * Returns the importable entry metadata keys.
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of target key to human label.
	 */
	public static function get_meta_targets() {
		return array(
			self::META_PREFIX . 'date_created'   => __( 'Date created', 'gravity-entry-import' ),
			self::META_PREFIX . 'created_by'     => __( 'Created by (user ID, login or email)', 'gravity-entry-import' ),
			self::META_PREFIX . 'source_url'     => __( 'Source URL', 'gravity-entry-import' ),
			self::META_PREFIX . 'ip'             => __( 'IP address', 'gravity-entry-import' ),
			self::META_PREFIX . 'status'         => __( 'Status (active / spam / trash)', 'gravity-entry-import' ),
			self::META_PREFIX . 'is_read'        => __( 'Read flag (0 or 1)', 'gravity-entry-import' ),
			self::META_PREFIX . 'payment_status' => __( 'Payment status', 'gravity-entry-import' ),
			self::META_PREFIX . 'payment_amount' => __( 'Payment amount', 'gravity-entry-import' ),
			self::META_PREFIX . 'transaction_id' => __( 'Transaction ID', 'gravity-entry-import' ),
			self::NOTE_TARGET_KEY                => __( 'Entry note (added to the note timeline, not a field)', 'gravity-entry-import' ),
		);
	}

	/**
	 * Extracts the raw CSV value mapped to the entry-note target, if any.
	 *
	 * An entry note is not a column on the entry array the way every other
	 * entry-metadata target is (see apply_meta()'s own explicit no-op case for
	 * NOTE_TARGET_KEY) - it is added separately, after the entry itself has
	 * already been created or updated, via GFFormsModel::add_note() (see
	 * GEI_Importer::maybe_add_note()). This reads the one mapped cell that
	 * matters for that write, trimmed the same way apply_mapping() trims
	 * every other cell, without touching the entry array at all - callers
	 * never need to inspect $mapping/$row for this target key themselves.
	 *
	 * @since 1.5.0
	 *
	 * @param array $row     CSV row values, indexed by column.
	 * @param array $mapping Map of target key to column index.
	 * @return string Trimmed raw cell value, or an empty string when the note
	 *                target is not mapped (or the cell is blank) for this row.
	 */
	public static function get_mapped_note( array $row, array $mapping ) {
		if ( ! isset( $mapping[ self::NOTE_TARGET_KEY ] ) ) {
			return '';
		}

		$column = $mapping[ self::NOTE_TARGET_KEY ];

		if ( '' === $column || null === $column || ! isset( $row[ $column ] ) ) {
			return '';
		}

		return trim( (string) $row[ $column ] );
	}

	/**
	 * Suggests a column index for each target by fuzzy-matching header labels.
	 *
	 * @since 1.0.0
	 *
	 * @param array $targets Targets from get_targets().
	 * @param array $header  CSV header labels.
	 * @return array Map of target key to column index.
	 */
	public static function suggest_mapping( array $targets, array $header ) {
		$mapping    = array();
		$normalized = array();

		foreach ( $header as $index => $label ) {
			$normalized[ $index ] = self::normalize_label( $label );
		}

		$used = array();

		foreach ( $targets as $target ) {
			$needle = self::normalize_label( wp_strip_all_tags( str_replace( '&rsaquo;', ' ', $target['label'] ) ) );

			foreach ( $normalized as $index => $candidate ) {
				if ( '' === $candidate || isset( $used[ $index ] ) ) {
					continue;
				}

				if ( $candidate === $needle ) {
					$mapping[ $target['key'] ] = $index;
					$used[ $index ]            = true;
					break;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Lowercases a label and strips everything but alphanumerics.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label Raw label.
	 * @return string Normalized label.
	 */
	protected static function normalize_label( $label ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $label ) );
	}

	/**
	 * Converts one CSV row into a Gravity Forms entry array.
	 *
	 * @since 1.0.0
	 *
	 * @param array $form    Gravity Forms form object.
	 * @param array $row     CSV row values, indexed by column.
	 * @param array $mapping Map of target key to column index.
	 * @return array Entry array suitable for GFAPI::add_entry().
	 */
	public static function build_entry( $form, array $row, array $mapping ) {
		$entry = array( 'form_id' => (int) $form['id'] );

		self::apply_mapping( $entry, $form, $row, $mapping );

		return self::finalize_entry( $entry, $form );
	}

	/**
	 * Builds the full entry array for updating an existing entry from one
	 * mapped CSV row, for the "Update" duplicate-handling mode.
	 *
	 * GFAPI::update_entry() does not merge a partial array onto the stored
	 * entry - confirmed by reading its field-update loop in Gravity Forms' own
	 * includes/api.php (GFAPI::queue_batch_field_operation()), where any field
	 * ID absent from the array it is given defaults to an empty string and is
	 * written over whatever the entry already held. Passing only this row's
	 * mapped values would therefore blank out every field the CSV did not map
	 * for this row.
	 *
	 * To avoid that, $current_entry (the full existing entry, as returned by
	 * GFAPI::get_entry()) is used as the starting point, and only the cells
	 * this row's mapping actually supplies are overlaid on top of it via the
	 * same apply_mapping() logic build_entry() uses for a new row. Everything
	 * else - every other field, and every entry-level property such as status,
	 * IP or payment fields - is left exactly as GFAPI::get_entry() returned it.
	 *
	 * Deliberately skips finalize_entry(): its new-entry defaults
	 * (date_created, status, is_read, ip, source_url, unattributed
	 * created_by) exist so a brand new row always inserts something sane.
	 * Reapplying them here would overwrite a real historical date, status or
	 * IP on every update with a placeholder, purely because that column
	 * happened not to be mapped for this run - the opposite of "don't touch
	 * what the CSV didn't map".
	 *
	 * One known simplification: a checkbox field mapped as a single
	 * delimited-list column (its whole-field target, not a per-choice
	 * sub-input) only turns ON the boxes present in this row's list - it does
	 * not clear boxes the existing entry had checked that are absent from
	 * this row's value. That mirrors apply_checkbox_list()'s own behaviour for
	 * a new entry (which starts empty, so there is nothing to clear), and was
	 * judged the safer default for an update rather than risk silently
	 * unchecking something this column was never meant to speak to.
	 *
	 * @since 1.2.0
	 *
	 * @param array $form          Gravity Forms form object.
	 * @param array $row           CSV row values, indexed by column.
	 * @param array $mapping       Map of target key to column index.
	 * @param array $current_entry The existing entry, as returned by GFAPI::get_entry().
	 * @return array Full entry array suitable for GFAPI::update_entry().
	 */
	public static function build_update_entry( $form, array $row, array $mapping, array $current_entry ) {
		$entry = $current_entry;

		self::apply_mapping( $entry, $form, $row, $mapping );

		/** This filter is documented in includes/class-gei-mapper.php */
		return apply_filters( 'gei_entry', $entry, $form );
	}

	/**
	 * Writes every mapped cell of one CSV row into an entry array.
	 *
	 * Factored out of build_entry() so build_update_entry() can run the exact
	 * same per-cell mapping logic against a different starting point - the
	 * full current entry for a row being updated, rather than a blank one -
	 * without duplicating it. This only ever writes the cells the mapping
	 * actually supplies; it does not fill in finalize_entry()'s new-entry
	 * defaults or run the gei_entry filter, both of which are the caller's
	 * responsibility (build_entry() does both; build_update_entry()
	 * deliberately skips the defaults - see its own DocBlock for why).
	 *
	 * @since 1.2.0
	 *
	 * @param array $entry   Entry array to write into, passed by reference.
	 * @param array $form    Gravity Forms form object.
	 * @param array $row     CSV row values, indexed by column.
	 * @param array $mapping Map of target key to column index.
	 * @return void
	 */
	protected static function apply_mapping( array &$entry, $form, array $row, array $mapping ) {
		$fields = self::index_fields( $form );

		foreach ( $mapping as $key => $column ) {
			if ( '' === $column || null === $column || ! isset( $row[ $column ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $column ] );

			if ( 0 === strpos( $key, self::META_PREFIX ) ) {
				self::apply_meta( $entry, substr( $key, strlen( self::META_PREFIX ) ), $value );
				continue;
			}

			$field_id = self::root_field_id( $key );
			$field    = isset( $fields[ $field_id ] ) ? $fields[ $field_id ] : null;

			if ( null === $field ) {
				continue;
			}

			self::apply_field( $entry, $field, $key, $value );
		}
	}

	/**
	 * Indexes a form's fields by their integer ID.
	 *
	 * Public since 1.2.0 so GEI_Validator can resolve a mapped target key back
	 * to its field object once per batch, the same pre-fetch-over-per-row-work
	 * this codebase already favours elsewhere (see fetch_existing_values() in
	 * GEI_Importer).
	 *
	 * @since 1.0.0
	 *
	 * @param array $form Gravity Forms form object.
	 * @return array Map of field ID to field object.
	 */
	public static function index_fields( $form ) {
		$indexed = array();

		if ( empty( $form['fields'] ) ) {
			return $indexed;
		}

		foreach ( $form['fields'] as $field ) {
			$indexed[ (string) $field->id ] = $field;
		}

		return $indexed;
	}

	/**
	 * Extracts the parent field ID from an input key such as "3.6".
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Target key.
	 * @return string Parent field ID.
	 */
	protected static function root_field_id( $key ) {
		$parts = explode( '.', (string) $key );

		return $parts[0];
	}

	/**
	 * Writes a value into the entry for a single field or sub-input.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry Entry array, passed by reference.
	 * @param object $field Gravity Forms field object.
	 * @param string $key   Target key (field ID or input ID).
	 * @param string $value Raw CSV value.
	 * @return void
	 */
	protected static function apply_field( array &$entry, $field, $key, $value ) {
		$type      = isset( $field->type ) ? $field->type : '';
		$is_parent = ( false === strpos( $key, '.' ) );

		if ( 'checkbox' === $type ) {
			if ( $is_parent ) {
				self::apply_checkbox_list( $entry, $field, $value );
			} else {
				$entry[ $key ] = self::resolve_checkbox_input( $field, $value, $key );
			}
			return;
		}

		// Only expand into sub-inputs when the field genuinely stores them.
		// Date and Time render as several inputs but save one combined string.
		$entry_inputs = self::get_entry_inputs( $field );

		if ( $is_parent && ! empty( $entry_inputs ) ) {
			self::apply_composite( $entry, $field, $value );
			return;
		}

		switch ( $type ) {
			case 'multiselect':
				$entry[ $key ] = self::encode_multiselect( $field, $value );
				break;

			case 'list':
				$entry[ $key ] = self::encode_list( $value );
				break;

			case 'date':
				$entry[ $key ] = self::normalize_date( $value, $field );
				break;

			case 'time':
				$entry[ $key ] = self::normalize_time( $value, $field );
				break;

			case 'number':
				$entry[ $key ] = self::normalize_number( $value );
				break;

			case 'radio':
			case 'select':
				$entry[ $key ] = self::match_choice_value( $field, $value );
				break;

			case 'fileupload':
				// Never resolved to a real file here: this method is also
				// used by GEI_Validator's read-only preview (see
				// GEI_Validator::evaluate_row(), which calls
				// GEI_Mapper::build_entry() purely to inspect it), and
				// resolving a File Upload cell can mean an outbound HTTP
				// request or a filesystem write - a side effect Validate
				// must never perform. The raw cell is stored as a
				// placeholder; GEI_Importer::process_batch() (and, for
				// "Update" mode, GEI_Importer::update_existing_entry())
				// overwrite it with the real, GF-hosted file URL - or fail
				// the row - immediately before the entry is inserted or
				// updated. See GEI_File_Field::resolve_row_files().
				$entry[ $key ] = $value;
				break;

			default:
				$entry[ $key ] = $value;
				break;
		}
	}

	/**
	 * Splits a single column across a composite field's sub-inputs.
	 *
	 * Used when the CSV carries one column for a whole name, address or time.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry Entry array, passed by reference.
	 * @param object $field Gravity Forms field object.
	 * @param string $value Raw CSV value.
	 * @return void
	 */
	protected static function apply_composite( array &$entry, $field, $value ) {
		$type = isset( $field->type ) ? $field->type : '';

		if ( '' === $value ) {
			return;
		}

		if ( 'name' === $type ) {
			$parts                      = preg_split( '/\s+/', $value, 2 );
			$entry[ $field->id . '.3' ] = isset( $parts[0] ) ? $parts[0] : '';
			$entry[ $field->id . '.6' ] = isset( $parts[1] ) ? $parts[1] : '';
			return;
		}

		if ( 'address' === $type ) {
			// Without a reliable single-column address parser, put the whole
			// string in street line 1 rather than guessing city/state/zip wrong.
			$entry[ $field->id . '.1' ] = $value;
			return;
		}

		// Fallback for any other composite: first visible input wins.
		foreach ( self::to_array( $field->inputs ) as $input ) {
			if ( empty( $input['isHidden'] ) ) {
				$entry[ (string) $input['id'] ] = $value;
				return;
			}
		}
	}

	/**
	 * Formats a time value the way Gravity Forms stores it.
	 *
	 * Time fields save one combined string rather than separate hour/minute
	 * inputs: "03:45 pm" for a 12-hour field, "15:45" for a 24-hour one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw time string.
	 * @param object $field Gravity Forms field object.
	 * @return string Formatted time, or the raw value when unparseable.
	 */
	protected static function normalize_time( $value, $field ) {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			// A bare "14:05" parses; anything else is left for a human to fix.
			return $value;
		}

		$format = isset( $field->timeFormat ) ? (string) $field->timeFormat : '12';

		if ( '24' === $format ) {
			return gmdate( 'H:i', $timestamp );
		}

		return gmdate( 'h:i a', $timestamp );
	}

	/**
	 * Distributes a delimited list of choices across a checkbox's inputs.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry Entry array, passed by reference.
	 * @param object $field Gravity Forms field object.
	 * @param string $value Delimited list of selected choices.
	 * @return void
	 */
	protected static function apply_checkbox_list( array &$entry, $field, $value ) {
		if ( '' === $value ) {
			return;
		}

		if ( empty( $field->choices ) ) {
			return;
		}

		$selected = self::split_choices( $field, $value );

		if ( empty( $selected ) ) {
			return;
		}

		$lookup = array();
		foreach ( $selected as $token ) {
			$lookup[ strtolower( $token ) ] = true;
		}

		foreach ( self::to_array( $field->choices ) as $i => $choice ) {
			$text       = isset( $choice['text'] ) ? strtolower( $choice['text'] ) : '';
			$choice_val = isset( $choice['value'] ) ? strtolower( $choice['value'] ) : '';

			if ( ! isset( $lookup[ $text ] ) && ! isset( $lookup[ $choice_val ] ) ) {
				continue;
			}

			// Gravity Forms skips the .10 / .20 input indexes, so derive the
			// input ID from the field's own inputs array rather than counting.
			$input_id = self::checkbox_input_id( $field, $i );

			if ( '' !== $input_id ) {
				$entry[ $input_id ] = isset( $choice['value'] ) ? $choice['value'] : $choice['text'];
			}
		}
	}

	/**
	 * Splits a delimited cell into choice tokens.
	 *
	 * A choice label may itself contain a comma ("Yes, please"), which naive
	 * splitting would break apart so it never matches. The whole cell is
	 * therefore tested against the field's choices first, and only split when
	 * it is not itself a single valid choice.
	 *
	 * Public since 1.2.0 so GEI_Validator can tokenize a multiselect cell the
	 * same way encode_multiselect() does, rather than re-deriving its own
	 * splitting rule that could quietly drift out of sync with the real import.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @param string $value Raw CSV value.
	 * @return array List of choice tokens.
	 */
	public static function split_choices( $field, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return array();
		}

		$needle = strtolower( $value );

		foreach ( self::to_array( $field->choices ) as $choice ) {
			$text       = isset( $choice['text'] ) ? strtolower( $choice['text'] ) : '';
			$choice_val = isset( $choice['value'] ) ? strtolower( $choice['value'] ) : '';

			if ( $needle === $text || $needle === $choice_val ) {
				return array( $value );
			}
		}

		$parts = preg_split( '/\s*[,;|]\s*/', $value );

		return array_values( array_filter( array_map( 'trim', self::to_array( $parts ) ), 'strlen' ) );
	}

	/**
	 * Resolves the input ID for the nth choice of a checkbox field.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @param int    $index Zero-based choice index.
	 * @return string Input ID, or an empty string when unavailable.
	 */
	protected static function checkbox_input_id( $field, $index ) {
		if ( empty( $field->inputs ) || ! isset( $field->inputs[ $index ]['id'] ) ) {
			return '';
		}

		return (string) $field->inputs[ $index ]['id'];
	}

	/**
	 * Matches a raw value against a field's configured choices.
	 *
	 * Falls back to the raw value so imports are not silently emptied when the
	 * source data uses labels the form does not define.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @param string $value Raw CSV value.
	 * @return string The matched choice value, or the raw value.
	 */
	protected static function match_choice_value( $field, $value ) {
		if ( '' === $value || empty( $field->choices ) ) {
			return $value;
		}

		$needle = strtolower( $value );

		foreach ( self::to_array( $field->choices ) as $choice ) {
			$text       = isset( $choice['text'] ) ? strtolower( $choice['text'] ) : '';
			$choice_val = isset( $choice['value'] ) ? strtolower( $choice['value'] ) : '';

			if ( $needle === $text || $needle === $choice_val ) {
				return isset( $choice['value'] ) ? $choice['value'] : $choice['text'];
			}
		}

		return $value;
	}

	/**
	 * Determines whether a raw CSV value matches one of a field's configured
	 * choices, without match_choice_value()'s real-import fallback.
	 *
	 * match_choice_value() (and, in their own ways, resolve_checkbox_input()
	 * and the per-token matching inside encode_multiselect()) all deliberately
	 * fall back to keeping an unmatched value rather than rejecting it, so a
	 * real import is never silently emptied just because the source data used
	 * a label the form does not define. That fallback is exactly what a
	 * pre-import validation pass needs to flag instead of hide, so this is a
	 * separate, read-only predicate: it changes nothing about what a real
	 * import stores, and match_choice_value() itself is left untouched.
	 *
	 * @since 1.2.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @param string $value Raw CSV value to test.
	 * @return bool True when the value matches one of the field's choices by
	 *              label or stored value, case-insensitively.
	 */
	public static function choice_matches( $field, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || empty( $field->choices ) ) {
			return false;
		}

		$needle = strtolower( $value );

		foreach ( self::to_array( $field->choices ) as $choice ) {
			$text       = isset( $choice['text'] ) ? strtolower( $choice['text'] ) : '';
			$choice_val = isset( $choice['value'] ) ? strtolower( $choice['value'] ) : '';

			if ( $needle === $text || $needle === $choice_val ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether a raw CSV value would be recognised for one specific
	 * checkbox sub-input, mirroring resolve_checkbox_input()'s own matching
	 * rule (the sub-input's own choice, or a boolean-truthy flag) without
	 * mutating anything or applying its "anything else means unchecked"
	 * fallback silently.
	 *
	 * A real import never treats an unrecognised value here as an error -
	 * resolve_checkbox_input() deliberately leaves the box unchecked instead -
	 * so this exists purely so the validator can surface "this will silently
	 * be left unchecked" as a possible issue, without resolve_checkbox_input()
	 * itself changing at all. Checked against the sub-input's own single
	 * choice specifically (not "any of the field's choices" the way
	 * choice_matches() works for radio/select/multiselect), because that is
	 * what actually determines this one box's state on a real import.
	 *
	 * @since 1.2.0
	 *
	 * @param object $field    Gravity Forms field object.
	 * @param string $value    Raw CSV value.
	 * @param string $input_id Input ID being checked, e.g. "4.2".
	 * @return bool True when the value would check this specific box.
	 */
	public static function checkbox_input_matches( $field, $value, $input_id ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		$index = self::choice_index_for_input( $field, $input_id );

		if ( null === $index || ! isset( $field->choices[ $index ] ) ) {
			return false;
		}

		$choice   = $field->choices[ $index ];
		$needle   = strtolower( $value );
		$is_match = ( isset( $choice['text'] ) && strtolower( $choice['text'] ) === $needle )
			|| ( isset( $choice['value'] ) && strtolower( $choice['value'] ) === $needle );

		if ( $is_match ) {
			return true;
		}

		return in_array( $needle, array( '1', 'yes', 'true', 'x', 'checked', 'y' ), true );
	}

	/**
	 * Resolves the stored value for a single checkbox sub-input.
	 *
	 * Gravity Forms treats any non-empty sub-input as checked, so an unmatched
	 * value must become an empty string. Returning the raw cell here would turn
	 * a column of "no" into a column of ticked boxes.
	 *
	 * Accepts either the choice's own label/value, or a boolean flag meaning
	 * "this box is ticked". Anything else leaves the box unchecked.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field    Gravity Forms field object.
	 * @param string $value    Raw CSV value.
	 * @param string $input_id Input ID being written.
	 * @return string The choice value when checked, otherwise an empty string.
	 */
	protected static function resolve_checkbox_input( $field, $value, $input_id ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$index = self::choice_index_for_input( $field, $input_id );

		if ( null === $index || ! isset( $field->choices[ $index ] ) ) {
			return '';
		}

		$choice   = $field->choices[ $index ];
		$stored   = isset( $choice['value'] ) ? $choice['value'] : $choice['text'];
		$needle   = strtolower( $value );
		$is_match = ( isset( $choice['text'] ) && strtolower( $choice['text'] ) === $needle )
			|| ( isset( $choice['value'] ) && strtolower( $choice['value'] ) === $needle );

		if ( $is_match ) {
			return $stored;
		}

		if ( in_array( $needle, array( '1', 'yes', 'true', 'x', 'checked', 'y' ), true ) ) {
			return $stored;
		}

		return '';
	}

	/**
	 * Finds the choice index backing a given checkbox input ID.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field    Gravity Forms field object.
	 * @param string $input_id Input ID.
	 * @return int|null Zero-based choice index, or null when not found.
	 */
	protected static function choice_index_for_input( $field, $input_id ) {
		if ( empty( $field->inputs ) ) {
			return null;
		}

		foreach ( self::to_array( $field->inputs ) as $i => $input ) {
			if ( (string) $input['id'] === (string) $input_id ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * Encodes a delimited list for a multiselect field.
	 *
	 * @since 1.0.0
	 *
	 * @param object $field Gravity Forms field object.
	 * @param string $value Delimited list of selected choices.
	 * @return string JSON-encoded array, as Gravity Forms stores multiselects.
	 */
	protected static function encode_multiselect( $field, $value ) {
		if ( '' === $value ) {
			return '';
		}

		$parts  = self::split_choices( $field, $value );
		$mapped = array();

		foreach ( $parts as $part ) {
			$mapped[] = self::match_choice_value( $field, $part );
		}

		return wp_json_encode( $mapped );
	}

	/**
	 * Encodes a delimited list for a list field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Delimited list of rows.
	 * @return string Serialized array, as Gravity Forms stores list fields.
	 */
	protected static function encode_list( $value ) {
		if ( '' === $value ) {
			return '';
		}

		$parts = preg_split( '/\s*[;|]\s*/', $value );
		$parts = array_values( array_filter( array_map( 'trim', self::to_array( $parts ) ), 'strlen' ) );

		return maybe_serialize( $parts );
	}

	/**
	 * Converts a date string into the Y-m-d format Gravity Forms stores.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw date string.
	 * @param object $field Gravity Forms field object.
	 * @return string Formatted date, or the raw value when unparseable.
	 */
	protected static function normalize_date( $value, $field ) {
		if ( '' === $value ) {
			return '';
		}

		$format = isset( $field->dateFormat ) ? $field->dateFormat : 'mdy';
		$parsed = self::parse_date_with_format( $value, $format );

		if ( false !== $parsed ) {
			return gmdate( 'Y-m-d', $parsed );
		}

		$timestamp = strtotime( $value );

		return ( false === $timestamp ) ? $value : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Parses a date using the field's configured day/month order.
	 *
	 * strtotime() reads 03/04/2025 as March 4th; a form set to dmy means April
	 * 3rd. Honouring the field format avoids silently shifting every date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value  Raw date string.
	 * @param string $format Gravity Forms date format key, e.g. 'dmy'.
	 * @return int|false Timestamp, or false when the value does not match.
	 */
	protected static function parse_date_with_format( $value, $format ) {
		if ( ! preg_match( '/^(\d{1,4})\D(\d{1,2})\D(\d{1,4})$/', trim( $value ), $m ) ) {
			return false;
		}

		$order = substr( (string) $format, 0, 3 );

		switch ( $order ) {
			case 'dmy':
				$day   = (int) $m[1];
				$month = (int) $m[2];
				$year  = (int) $m[3];
				break;

			case 'ymd':
				$year  = (int) $m[1];
				$month = (int) $m[2];
				$day   = (int) $m[3];
				break;

			case 'mdy':
			default:
				// A 4-digit leading group is an ISO date regardless of setting.
				if ( 4 === strlen( $m[1] ) ) {
					$year  = (int) $m[1];
					$month = (int) $m[2];
					$day   = (int) $m[3];
				} else {
					$month = (int) $m[1];
					$day   = (int) $m[2];
					$year  = (int) $m[3];
				}
				break;
		}

		if ( $year < 100 ) {
			$year += ( $year < 70 ) ? 2000 : 1900;
		}

		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}

		return gmmktime( 0, 0, 0, $month, $day, $year );
	}

	/**
	 * Strips thousands separators and currency symbols from a number.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw numeric string.
	 * @return string Cleaned numeric string.
	 */
	protected static function normalize_number( $value ) {
		if ( '' === $value ) {
			return '';
		}

		$cleaned = preg_replace( '/[^0-9.\-]/', '', str_replace( ',', '', $value ) );

		return ( '' === $cleaned ) ? '' : $cleaned;
	}

	/**
	 * Writes an entry metadata value, normalizing it for the target key.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry Entry array, passed by reference.
	 * @param string $key   Metadata key without the prefix.
	 * @param string $value Raw CSV value.
	 * @return void
	 */
	protected static function apply_meta( array &$entry, $key, $value ) {
		if ( '' === $value ) {
			return;
		}

		switch ( $key ) {
			case 'date_created':
				$timestamp = strtotime( $value );
				if ( false !== $timestamp ) {
					// Gravity Forms stores entry dates in UTC. get_gmt_from_date()
					// applies the offset that was in force on that date, which a
					// fixed gmt_offset gets wrong for half the year on DST sites.
					$entry['date_created'] = get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $timestamp ) );
				}
				break;

			case 'created_by':
				$user_id = self::resolve_user_id( $value );
				if ( 0 < $user_id ) {
					$entry['created_by'] = $user_id;
				}
				break;

			case 'source_url':
				$entry['source_url'] = esc_url_raw( $value );
				break;

			case 'ip':
				$entry['ip'] = filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
				break;

			case 'status':
				$allowed          = array( 'active', 'spam', 'trash' );
				$status           = strtolower( $value );
				$entry['status']  = in_array( $status, $allowed, true ) ? $status : 'active';
				break;

			case 'is_read':
				$entry['is_read'] = in_array( strtolower( $value ), array( '1', 'yes', 'true' ), true ) ? 1 : 0;
				break;

			case 'payment_amount':
				$entry['payment_amount'] = self::normalize_number( $value );
				break;

			case 'payment_status':
			case 'transaction_id':
				$entry[ $key ] = $value;
				break;

			case 'entry_note':
				// Deliberately a no-op: an entry note is not a column on the
				// entry array at all, so there is nothing to write here. The
				// raw cell is read separately, by key, via get_mapped_note(),
				// and turned into a real Gravity Forms note only once the
				// entry this note belongs to actually exists (see
				// GEI_Importer::maybe_add_note()). Listed explicitly, rather
				// than left to fall through an unmatched switch silently, so
				// a reader of this switch is not left wondering why
				// NOTE_TARGET_KEY's suffix never appears here.
				break;
		}
	}

	/**
	 * Resolves a user ID from an ID, login or email address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw value.
	 * @return int User ID, or 0 when no user matches.
	 */
	protected static function resolve_user_id( $value ) {
		if ( is_numeric( $value ) ) {
			$user = get_user_by( 'id', (int) $value );

			return $user ? (int) $user->ID : 0;
		}

		$user = is_email( $value ) ? get_user_by( 'email', $value ) : get_user_by( 'login', $value );

		return $user ? (int) $user->ID : 0;
	}

	/**
	 * Applies defaults Gravity Forms expects on every stored entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Entry array.
	 * @param array $form  Gravity Forms form object.
	 * @return array Entry array with defaults filled in.
	 */
	protected static function finalize_entry( array $entry, $form ) {
		if ( ! isset( $entry['date_created'] ) ) {
			$entry['date_created'] = gmdate( 'Y-m-d H:i:s' );
		}

		if ( ! isset( $entry['status'] ) ) {
			$entry['status'] = 'active';
		}

		if ( ! isset( $entry['is_read'] ) ) {
			$entry['is_read'] = 0;
		}

		if ( ! isset( $entry['ip'] ) ) {
			$entry['ip'] = '';
		}

		if ( ! isset( $entry['source_url'] ) ) {
			$entry['source_url'] = '';
		}

		// GFAPI::add_entry() defaults created_by to the logged-in user. Left
		// unset, a historical import would credit every entry to whoever ran
		// it, so an unmapped author is stored as unattributed instead.
		if ( ! isset( $entry['created_by'] ) ) {
			$entry['created_by'] = 0;
		}

		/**
		 * Filters an entry built from a CSV row before it is inserted.
		 *
		 * @since 1.0.0
		 *
		 * @param array $entry Entry array bound for GFAPI::add_entry().
		 * @param array $form  Gravity Forms form object.
		 */
		return apply_filters( 'gei_entry', $entry, $form );
	}
}
