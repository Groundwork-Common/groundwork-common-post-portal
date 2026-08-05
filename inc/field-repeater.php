<?php
/**
 * The repeater: a field that is a list of rows.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── The __i__ template, and the marker ──────────────────────────────────────
 * A repeater needs a blank row to clone when somebody presses Add. Rendering
 * that row in JavaScript means the markup exists in two places — PHP for the
 * saved rows, JS for the new ones — and the day they drift is the day a new row
 * saves nothing because its input names are subtly wrong.
 *
 * So the blank row is rendered by PHP, once, into a <template> with the literal
 * string __i__ where the row index goes. The script clones it and replaces
 * __i__ with a number. One renderer, and the names cannot disagree.
 *
 * The hidden _present marker is the same trick the choice types use, and it
 * matters more here: a repeater whose rows have all been deleted submits
 * nothing at all, which is byte-for-byte identical to a repeater that was never
 * on the form. Without the marker, "I removed all my opening hours" and "this
 * field was added after I opened the page" are the same POST, and the save path
 * has to choose which one to get wrong.
 *
 * Sub-fields come from the same registry as everything else, so a repeater of
 * text and select controls needs no code here beyond the loop.
 * ─────────────────────────────────────────────────────────────────────────── */

add_filter( 'gwcpp_field_types', 'gwcpp_register_repeater_type' );

/** The most rows one repeater will accept. */
const GWCPP_REPEATER_MAX = 50;

/**
 * Register the type.
 *
 * @param array $types Registry.
 * @return array
 */
function gwcpp_register_repeater_type( array $types ): array {
	$types['repeater'] = array(
		'label'         => __( 'Repeating rows', 'groundwork-common-post-portal' ),
		'group'         => 'rich',
		'render_portal' => 'gwcpp_render_repeater',
		'render_admin'  => 'gwcpp_render_repeater',
		'sanitize'      => 'gwcpp_sanitize_repeater',
		'validate'      => 'gwcpp_validate_repeater',
		'is_empty'      => 'gwcpp_empty_array',
		'to_display'    => 'gwcpp_display_repeater',
		'schema_form'   => 'gwcpp_schema_form_repeater',
		'needs_present' => true,
	);

	return $types;
}

/**
 * A repeater's sub-field definitions.
 *
 * Stored as the same `value|Label` lines the choice editor uses, with an
 * optional type after a second pipe, so the Fields screen needs no new UI:
 *
 *   day|Day|select
 *   opens|Opens|text
 *
 * Sub-fields are deliberately limited to the simple types. A repeater of
 * repeaters, or of file uploads, is a data model that wants its own post type,
 * and supporting it here would mean naming, uploading and diffing nested rows.
 *
 * @param array $field Field definition.
 * @return array<int, array>
 */
function gwcpp_repeater_subfields( array $field ): array {
	$raw = (string) gwcpp_field_setting( $field, 'subfields_raw', '' );
	$out = array();
	$seen = array();

	$allowed = array( 'text', 'textarea', 'number', 'url', 'email', 'phone', 'date', 'select', 'boolean' );

	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}

		$parts = array_map( 'trim', explode( '|', $line ) );
		$key   = gwcpp_sanitize_field_key( (string) ( $parts[0] ?? '' ) );

		if ( '' === $key || isset( $seen[ $key ] ) ) {
			continue;
		}

		$type = sanitize_key( (string) ( $parts[2] ?? 'text' ) );
		if ( ! in_array( $type, $allowed, true ) || null === gwcpp_field_type( $type ) ) {
			$type = 'text';
		}

		$options = array();
		if ( 'select' === $type && isset( $parts[3] ) ) {
			// For example: day|Day|select|mon=Monday;tue=Tuesday.
			foreach ( explode( ';', $parts[3] ) as $pair ) {
				$bits = array_map( 'trim', explode( '=', $pair, 2 ) );
				if ( '' === ( $bits[0] ?? '' ) ) {
					continue;
				}
				$options[] = array(
					'value' => sanitize_text_field( $bits[0] ),
					'label' => sanitize_text_field( $bits[1] ?? $bits[0] ),
				);
			}
		}

		$seen[ $key ] = true;
		$out[]        = array(
			'key'      => $key,
			'type'     => $type,
			'label'    => sanitize_text_field( (string) ( $parts[1] ?? $key ) ),
			'required' => false,
			'settings' => $options ? array( 'options' => $options ) : array(),
		);
	}

	return $out;
}

/**
 * The rows.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored rows.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_repeater( array $field, $value, string $name, array $ctx = array() ): void {
	gwcpp_render_present_marker( $name );

	$subfields = gwcpp_repeater_subfields( $field );

	if ( ! $subfields ) {
		printf(
			'<p class="gwcpp-field__hint">%s</p>',
			esc_html__( 'This field has no columns set up yet.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$rows = is_array( $value ) ? array_values( $value ) : array();
	$id   = gwcpp_field_id( $name );

	printf(
		'<div class="gwcpp-repeater" data-gwcpp-repeater data-max="%d"%s>',
		(int) gwcpp_repeater_max( $field ),
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : ''
	);

	echo '<div class="gwcpp-repeater__rows" data-gwcpp-rows>';
	foreach ( $rows as $i => $row ) {
		gwcpp_render_repeater_row( $subfields, is_array( $row ) ? $row : array(), $name, (string) $i );
	}
	echo '</div>';

	/* The blank row, rendered by the same function as the saved ones. Inside a
	 * <template> so the browser does not treat its controls as part of the form
	 * — an ordinary hidden div would submit __i__ as a real row on every save. */
	echo '<template data-gwcpp-row-template>';
	gwcpp_render_repeater_row( $subfields, array(), $name, '__i__' );
	echo '</template>';

	printf(
		'<p class="gwcpp-repeater__actions"><button type="button" class="gwcpp-button gwcpp-button--quiet" data-gwcpp-add>%s</button></p>',
		esc_html__( '+ Add another', 'groundwork-common-post-portal' )
	);

	/* Where the script says what just happened. Adding a row moves focus into
	 * it, so that announces itself; removing one does not, and without this a
	 * screen-reader user presses Remove and hears nothing at all.
	 *
	 * Rendered by PHP rather than created in JavaScript for two reasons: the
	 * strings are translatable here and would otherwise need
	 * wp_set_script_translations for one sentence, and a live region has to be
	 * in the document before the text goes into it or nothing is announced. */
	printf(
		'<p class="screen-reader-text" role="status" aria-live="polite" data-gwcpp-status data-gwcpp-removed="%s" data-gwcpp-added="%s"></p>',
		esc_attr__( 'Row removed.', 'groundwork-common-post-portal' ),
		esc_attr__( 'Row added.', 'groundwork-common-post-portal' )
	);

	printf(
		'<noscript><p class="gwcpp-field__hint">%s</p></noscript>',
		esc_html__( 'Adding rows needs JavaScript. You can still edit and clear the rows already here.', 'groundwork-common-post-portal' )
	);

	echo '</div>';

	unset( $id );
}

/**
 * One row.
 *
 * @param array  $subfields Sub-field definitions.
 * @param array  $row       Values for this row.
 * @param string $name      The repeater's control name.
 * @param string $index     Row index, or the literal __i__ for the template.
 */
function gwcpp_render_repeater_row( array $subfields, array $row, string $name, string $index ): void {
	echo '<div class="gwcpp-repeater__row" data-gwcpp-row>';

	foreach ( $subfields as $sub ) {
		$sub_key  = (string) $sub['key'];
		$sub_name = $name . '[rows][' . $index . '][' . $sub_key . ']';

		echo '<div class="gwcpp-repeater__cell">';
		printf(
			'<label class="gwcpp-label gwcpp-label--sub" for="%s">%s</label>',
			esc_attr( gwcpp_field_id( $sub_name ) ),
			esc_html( (string) $sub['label'] )
		);
		gwcpp_field_call( $sub, 'render_portal', array( $sub, $row[ $sub_key ] ?? null, $sub_name, array() ) );
		echo '</div>';
	}

	printf(
		'<button type="button" class="gwcpp-repeater__remove" data-gwcpp-remove aria-label="%s">&times;</button>',
		esc_attr__( 'Remove this row', 'groundwork-common-post-portal' )
	);

	echo '</div>';
}

/**
 * The row limit for one repeater.
 *
 * @param array $field Field definition.
 * @return int
 */
function gwcpp_repeater_max( array $field ): int {
	$configured = (int) gwcpp_field_setting( $field, 'max_rows', 0 );

	return $configured > 0 ? min( $configured, GWCPP_REPEATER_MAX ) : GWCPP_REPEATER_MAX;
}

/**
 * Rows, each sanitized by its sub-field's own type.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return array
 */
function gwcpp_sanitize_repeater( $raw, array $field = array() ): array {
	$subfields = gwcpp_repeater_subfields( $field );
	if ( ! $subfields || ! is_array( $raw ) ) {
		return array();
	}

	$rows = isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array();
	$out  = array();

	foreach ( $rows as $index => $row ) {
		/* The template's own row, if it ever reaches us. It should not — a
		 * <template> element's contents are inert and are not submitted — but a
		 * browser that does not support <template> would render it as ordinary
		 * markup, and then every save would store a row of blanks. */
		if ( '__i__' === (string) $index || ! is_array( $row ) ) {
			continue;
		}

		if ( count( $out ) >= gwcpp_repeater_max( $field ) ) {
			break;
		}

		$clean = array();
		$any   = false;

		foreach ( $subfields as $sub ) {
			$sub_key           = (string) $sub['key'];
			$clean[ $sub_key ] = gwcpp_field_call( $sub, 'sanitize', array( $row[ $sub_key ] ?? null, $sub ) );

			if ( ! gwcpp_field_call( $sub, 'is_empty', array( $clean[ $sub_key ], $sub ) ) ) {
				$any = true;
			}
		}

		/* A row where every cell is blank is dropped rather than stored. People
		 * press Add, change their mind, and leave the empty row sitting there —
		 * storing it would put a blank line in whatever the theme renders. */
		if ( $any ) {
			$out[] = $clean;
		}
	}

	return array_values( $out );
}

/**
 * Check the row count.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_repeater( $value, array $field = array() ): string {
	if ( ! gwcpp_repeater_subfields( $field ) ) {
		return __( 'This field is not set up properly. Please tell us and we will fix it.', 'groundwork-common-post-portal' );
	}

	$max = gwcpp_repeater_max( $field );
	if ( is_array( $value ) && count( $value ) > $max ) {
		return sprintf(
			/* translators: %d: the largest number of rows allowed. */
			_n( 'Please add no more than %d row.', 'Please add no more than %d rows.', $max, 'groundwork-common-post-portal' ),
			$max
		);
	}

	return '';
}

/**
 * The rows as one readable line, for the diff.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_repeater( $value, array $field = array() ): string {
	if ( ! is_array( $value ) || ! $value ) {
		return '';
	}

	$subfields = gwcpp_repeater_subfields( $field );
	$lines     = array();

	foreach ( $value as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$cells = array();
		foreach ( $subfields as $sub ) {
			$text = (string) gwcpp_field_call( $sub, 'to_display', array( $row[ (string) $sub['key'] ] ?? '', $sub ) );
			if ( '' !== $text ) {
				$cells[] = $text;
			}
		}

		if ( $cells ) {
			$lines[] = implode( ' ', $cells );
		}
	}

	return implode( '; ', $lines );
}

/**
 * The Fields screen controls.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_repeater( array $field ): void {
	printf(
		'<p class="gwcpp-schema-setting"><label for="gwcpp-set-subfields">%s</label><textarea id="gwcpp-set-subfields" name="%s" rows="6" class="large-text code">%s</textarea></p>',
		esc_html__( 'Columns', 'groundwork-common-post-portal' ),
		esc_attr( 'gwcpp_field[settings][subfields_raw]' ),
		esc_textarea( (string) gwcpp_field_setting( $field, 'subfields_raw', '' ) )
	);

	printf(
		'<p class="description">%s<br /><code>%s</code><br /><code>%s</code></p>',
		esc_html__( 'One column per line: key|Label|type. Type may be text, textarea, number, url, email, phone, date, select or boolean, and defaults to text.', 'groundwork-common-post-portal' ),
		esc_html( 'opens|Opens at|text' ),
		esc_html( 'day|Day|select|mon=Monday;tue=Tuesday' )
	);

	gwcpp_schema_setting_input(
		'max_rows',
		__( 'Most rows allowed', 'groundwork-common-post-portal' ),
		gwcpp_field_setting( $field, 'max_rows' ),
		'number',
		sprintf(
			/* translators: %d: a number of rows. */
			__( 'Leave blank for the default. Never more than %d.', 'groundwork-common-post-portal' ),
			GWCPP_REPEATER_MAX
		)
	);
}
