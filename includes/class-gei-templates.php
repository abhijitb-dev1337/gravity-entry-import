<?php
/**
 * Saved column-mapping templates, scoped to a form.
 *
 * @package GEI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists and retrieves named mapping templates for reuse on a later import.
 *
 * Kept as its own class rather than more methods on GEI_Storage because a
 * template's lifecycle is the opposite of a job's: GEI_Storage exists to
 * track throwaway, per-upload state that GEI_Storage::prune_stale_jobs()
 * actively wants to see go stale and disappear, while a template is
 * deliberately durable, reusable configuration an admin builds up across many
 * imports into the same form. It is therefore never touched by the daily
 * gei_cleanup cron (see gei_run_scheduled_cleanup() in the main plugin file) -
 * only an explicit "Delete" action (GEI_Admin::handle_delete_template_post())
 * or plugin uninstall (uninstall.php) ever removes one.
 *
 * Storage is one option per form - gei_templates_{form_id} - holding every
 * template saved for that form as an array keyed by template ID, rather than
 * one option per template. "Save this mapping as" being clicked repeatedly,
 * across every form on a busy multi-form site, would otherwise leave one new
 * options row behind per click with nothing to bound or index it; keying by
 * form instead means at most one row per form that has ever had a template
 * saved against it, matching GEI_Storage's own reasoning for prefixed,
 * enumerable option names (see its JOB_OPTION_PREFIX/LOCK_OPTION_PREFIX).
 *
 * @since 1.3.0
 */
class GEI_Templates {

	/**
	 * Option key prefix. The full option name is this prefix plus a form ID.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'gei_templates_';

	/**
	 * Maximum number of templates retained per form.
	 *
	 * A hard cap - rather than no limit at all - keeps a single form's
	 * options row bounded even on a site where "Save this mapping as" gets
	 * clicked under a new name every single run. An admin who hits it has to
	 * delete an old template before saving another; that small, occasional
	 * friction is preferred over letting one form's row grow without bound,
	 * matching this plugin's existing wp_options hygiene (see
	 * GEI_Importer::MAX_LOGGED_ERRORS / GEI_Validator::MAX_LOGGED_ISSUES for
	 * the same kind of deliberate cap elsewhere in this codebase).
	 *
	 * @var int
	 */
	const MAX_PER_FORM = 50;

	/**
	 * Returns every saved template for a form.
	 *
	 * @since 1.3.0
	 *
	 * @param int $form_id Form ID.
	 * @return array Map of template ID to template data. Empty when none are saved.
	 */
	public static function get_templates( $form_id ) {
		$form_id = absint( $form_id );

		if ( 0 === $form_id ) {
			return array();
		}

		$templates = get_option( self::option_name( $form_id ), array() );

		return is_array( $templates ) ? $templates : array();
	}

	/**
	 * Returns one saved template.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $form_id     Form ID the template must belong to.
	 * @param string $template_id Template identifier.
	 * @return array|null The template's data, or null when it does not exist
	 *                     for this form.
	 */
	public static function get_template( $form_id, $template_id ) {
		$template_id = self::sanitize_template_id( $template_id );
		$templates   = self::get_templates( $form_id );

		if ( '' === $template_id || ! isset( $templates[ $template_id ] ) ) {
			return null;
		}

		return $templates[ $template_id ];
	}

	/**
	 * Saves a new template for a form.
	 *
	 * Only ever adds - there is no "update in place" here, matching the plan
	 * this feature was built against ("removed only via an explicit delete
	 * action, or uninstall"); re-saving under the same name a second time
	 * creates a second, independent template rather than silently overwriting
	 * the first, so a name collision can never quietly discard a
	 * previously-saved mapping.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $form_id Form ID this template is scoped to.
	 * @param string $name    Admin-supplied template name. Caller is expected
	 *                        to have already run this through
	 *                        sanitize_text_field(), matching every other
	 *                        user-supplied string in this plugin.
	 * @param array  $data    Template payload: mapping, duplicate_mode,
	 *                        duplicate_field, skip_duplicates, send_notifications.
	 *                        Deliberately does not include the conditional
	 *                        row-filter rule (GEI_Row_Filter) - that rule is
	 *                        scoped to one CSV's shape (a specific column
	 *                        index and its header), not to the form, so
	 *                        reapplying it against a different CSV later would
	 *                        silently filter on the wrong column.
	 * @return string|WP_Error The new template's ID, or an error.
	 */
	public static function save_template( $form_id, $name, array $data ) {
		$form_id = absint( $form_id );
		$name    = trim( (string) $name );

		if ( 0 === $form_id ) {
			return new WP_Error( 'gei_no_form', __( 'No form was specified for this template.', 'gravity-entry-import' ) );
		}

		if ( '' === $name ) {
			return new WP_Error( 'gei_no_name', __( 'Give the template a name before saving it.', 'gravity-entry-import' ) );
		}

		$templates = self::get_templates( $form_id );

		if ( count( $templates ) >= self::MAX_PER_FORM ) {
			return new WP_Error(
				'gei_too_many_templates',
				__( 'This form already has the maximum number of saved mapping templates. Delete one before saving another.', 'gravity-entry-import' )
			);
		}

		$template_id = self::generate_template_id();

		$templates[ $template_id ] = array(
			'name'               => $name,
			'mapping'            => ( isset( $data['mapping'] ) && is_array( $data['mapping'] ) ) ? $data['mapping'] : array(),
			'duplicate_mode'     => isset( $data['duplicate_mode'] ) ? (string) $data['duplicate_mode'] : 'skip',
			'duplicate_field'    => isset( $data['duplicate_field'] ) ? (string) $data['duplicate_field'] : '',
			'skip_duplicates'    => ! empty( $data['skip_duplicates'] ),
			'send_notifications' => ! empty( $data['send_notifications'] ),
			'created'            => time(),
		);

		// autoload = false: a template is only ever read on the mapping
		// screen for one specific form, never on every page load.
		update_option( self::option_name( $form_id ), $templates, false );

		return $template_id;
	}

	/**
	 * Deletes one template from a form.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $form_id     Form ID the template must belong to.
	 * @param string $template_id Template identifier.
	 * @return bool True when a template was found for this form and removed.
	 */
	public static function delete_template( $form_id, $template_id ) {
		$form_id     = absint( $form_id );
		$template_id = self::sanitize_template_id( $template_id );
		$templates   = self::get_templates( $form_id );

		if ( '' === $template_id || ! isset( $templates[ $template_id ] ) ) {
			return false;
		}

		unset( $templates[ $template_id ] );

		if ( empty( $templates ) ) {
			// Nothing left worth keeping an empty options row around for.
			delete_option( self::option_name( $form_id ) );
		} else {
			update_option( self::option_name( $form_id ), $templates, false );
		}

		return true;
	}

	/**
	 * Builds the option name holding every template saved for one form.
	 *
	 * @since 1.3.0
	 *
	 * @param int $form_id Form ID.
	 * @return string Option name.
	 */
	protected static function option_name( $form_id ) {
		return self::OPTION_PREFIX . absint( $form_id );
	}

	/**
	 * Generates an identifier for a new template.
	 *
	 * Unlike GEI_Storage::generate_job_id(), a template ID is not the only
	 * thing standing between a request and someone else's data: every entry
	 * point that accepts one (loading it on the mapping screen, deleting it)
	 * also resolves it against the job's own trusted form_id first - see
	 * GEI_Admin::render_map_step() and ::handle_delete_template_post() - so it
	 * only ever needs to be unique as an array key within one form's template
	 * list, not unguessable on its own.
	 *
	 * @since 1.3.0
	 *
	 * @return string A 12-character alphanumeric identifier.
	 */
	protected static function generate_template_id() {
		return wp_generate_password( 12, false, false );
	}

	/**
	 * Restricts a template ID to the character set generate_template_id() produces.
	 *
	 * @since 1.3.0
	 *
	 * @param string $template_id Raw template identifier.
	 * @return string Sanitized identifier, or an empty string when invalid.
	 */
	public static function sanitize_template_id( $template_id ) {
		$template_id = preg_replace( '/[^A-Za-z0-9]/', '', (string) $template_id );

		return ( '' !== $template_id ) ? substr( $template_id, 0, 32 ) : '';
	}
}
