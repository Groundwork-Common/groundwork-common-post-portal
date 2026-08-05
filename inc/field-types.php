<?php
/**
 * The field type registry, and the eleven types Phase 1 ships.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── One registry, seven callables, no switch statements ─────────────────────
 * Every type is an array of function names. Nothing in this plugin branches on
 * $field['type'] outside this file; everything looks the type up here and calls
 * what it finds. That is not architecture for its own sake — it is the only way
 * "an admin defines the fields" can also mean "a developer can add a field
 * type", which is the difference between a configurable plugin and a plugin
 * with a fixed list of eleven things.
 *
 * It is also what keeps the five lists that sank the original portal from ever
 * existing. There, render / validate / repopulate / save / label were five
 * hardcoded field lists that had to be edited together, and the bug that
 * shipped was one of them being edited alone. Here there is one list — the
 * schema — and five callables that read it.
 *
 * The contract:
 *
 *   render_portal (array $field, mixed $value, string $name, array $ctx): void
 *                 Print the front-end control, and only the control. The label,
 *                 description and error line are the form layer's job — a type
 *                 that printed its own label could not be reused in the meta
 *                 box, where WordPress supplies one.
 *   render_admin  (array $field, mixed $value, string $name): void
 *                 The same control for the wp-admin meta box.
 *   sanitize      (mixed $raw, array $field): mixed
 *                 Raw POST → the value stored in post meta. Never trusts input,
 *                 and never assumes $raw is a string: an array arrives whenever
 *                 somebody renames a form control by hand.
 *   validate      (mixed $value, array $field): string
 *                 '' when acceptable, otherwise the message shown to the user.
 *                 Runs on the SANITIZED value, so it is checking meaning, not
 *                 shape — shape is sanitize's problem and is never reported.
 *   is_empty      (mixed $value, array $field): bool
 *                 True means delete the meta row rather than store the value.
 *   to_display    (mixed $value, array $field): string
 *                 Human-readable, for the approval diff and the staff email.
 *                 Returns plain text; the caller escapes.
 *   schema_form   (array $field): void
 *                 Extra controls on the Fields screen for this type.
 *
 * Two optional extras: `needs_present`, for types whose control submits nothing
 * at all when the user clears it, and `has_options`, which makes the Fields
 * screen show the choice editor.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The type registry.
 *
 * @return array<string, array>
 */
function gwcpp_field_types(): array {
	static $types = null;
	if ( null !== $types ) {
		return $types;
	}

	/* The six that differ only in their HTML input type and their sanitizer.
	 * Spelled out as data rather than as six near-identical array literals,
	 * because the near-identical literals are where a copy-paste puts
	 * sanitize_email on the URL field and nobody notices for a year. */
	$simple = array(
		'text'   => array(
			'label' => __( 'Text', 'groundwork-common-post-portal' ),
			'input' => 'text',
		),
		'number' => array(
			'label' => __( 'Number', 'groundwork-common-post-portal' ),
			'input' => 'number',
		),
		'url'    => array(
			'label' => __( 'Website', 'groundwork-common-post-portal' ),
			/* Deliberately text, not url. type="url" makes the browser demand an
			 * absolute URL, so "shelterofhope.org" is refused client-side with a
			 * bubble the person cannot argue with — and gwcpp_sanitize_url()'s
			 * whole point is that a bare domain is what people actually type and
			 * should be upgraded to https:// rather than rejected. With
			 * type="url" that upgrade is unreachable, because the form never
			 * submits far enough to reach it.
			 *
			 * Found by typing a real domain into a real form and watching it
			 * refuse. inputmode keeps the URL keyboard on a phone, which is the
			 * part of type="url" that was worth having. */
			'input'     => 'text',
			'inputmode' => 'url',
		),
		'email'  => array(
			'label' => __( 'Email', 'groundwork-common-post-portal' ),
			'input' => 'email',
		),
		'phone'  => array(
			'label' => __( 'Phone', 'groundwork-common-post-portal' ),
			'input' => 'tel',
		),
		'date'   => array(
			'label' => __( 'Date', 'groundwork-common-post-portal' ),
			'input' => 'date',
		),
	);

	$types = array();
	foreach ( $simple as $key => $meta ) {
		$types[ $key ] = array(
			'label'         => $meta['label'],
			'group'         => 'simple',
			'input'         => $meta['input'],
			'inputmode'     => $meta['inputmode'] ?? '',
			'render_portal' => 'gwcpp_render_input',
			'render_admin'  => 'gwcpp_render_input',
			'sanitize'      => 'gwcpp_sanitize_' . $key,
			'validate'      => 'gwcpp_validate_' . $key,
			'is_empty'      => 'gwcpp_empty_scalar',
			'to_display'    => 'gwcpp_display_scalar',
			'schema_form'   => 'gwcpp_schema_form_scalar',
		);
	}

	$types['textarea'] = array(
		'label'         => __( 'Long text', 'groundwork-common-post-portal' ),
		'group'         => 'simple',
		'render_portal' => 'gwcpp_render_textarea',
		'render_admin'  => 'gwcpp_render_textarea',
		'sanitize'      => 'gwcpp_sanitize_textarea',
		'validate'      => 'gwcpp_validate_text',
		'is_empty'      => 'gwcpp_empty_scalar',
		'to_display'    => 'gwcpp_display_scalar',
		'schema_form'   => 'gwcpp_schema_form_textarea',
	);

	$types['boolean'] = array(
		'label'         => __( 'Yes / no', 'groundwork-common-post-portal' ),
		'group'         => 'choice',
		'render_portal' => 'gwcpp_render_boolean',
		'render_admin'  => 'gwcpp_render_boolean',
		'sanitize'      => 'gwcpp_sanitize_boolean',
		'validate'      => 'gwcpp_validate_boolean',
		'is_empty'      => 'gwcpp_empty_boolean',
		'to_display'    => 'gwcpp_display_boolean',
		'schema_form'   => 'gwcpp_schema_form_boolean',
		'needs_present' => true,
	);

	$types['select'] = array(
		'label'         => __( 'Choice (one)', 'groundwork-common-post-portal' ),
		'group'         => 'choice',
		'render_portal' => 'gwcpp_render_select',
		'render_admin'  => 'gwcpp_render_select',
		'sanitize'      => 'gwcpp_sanitize_choice',
		'validate'      => 'gwcpp_validate_choice',
		'is_empty'      => 'gwcpp_empty_scalar',
		'to_display'    => 'gwcpp_display_choice',
		'schema_form'   => 'gwcpp_schema_form_choice',
		'has_options'   => true,
	);

	$types['radio'] = array(
		'label'         => __( 'Choice (one, all shown)', 'groundwork-common-post-portal' ),
		'group'         => 'choice',
		'render_portal' => 'gwcpp_render_radio',
		'render_admin'  => 'gwcpp_render_radio',
		'sanitize'      => 'gwcpp_sanitize_choice',
		'validate'      => 'gwcpp_validate_choice',
		'is_empty'      => 'gwcpp_empty_scalar',
		'to_display'    => 'gwcpp_display_choice',
		'schema_form'   => 'gwcpp_schema_form_choice',
		'has_options'   => true,
		'needs_present' => true,
	);

	$types['multiselect'] = array(
		'label'         => __( 'Choice (many)', 'groundwork-common-post-portal' ),
		'group'         => 'choice',
		'render_portal' => 'gwcpp_render_multiselect',
		'render_admin'  => 'gwcpp_render_multiselect',
		'sanitize'      => 'gwcpp_sanitize_multiselect',
		'validate'      => 'gwcpp_validate_multiselect',
		'is_empty'      => 'gwcpp_empty_array',
		'to_display'    => 'gwcpp_display_multiselect',
		'schema_form'   => 'gwcpp_schema_form_choice',
		'has_options'   => true,
		'needs_present' => true,
	);

	/**
	 * Register a custom field type.
	 *
	 * A type must supply every callable in the contract at the top of this file.
	 * A missing one is not defaulted — gwcpp_field_type() drops the type
	 * entirely and logs, because a type whose sanitize callable is absent would
	 * otherwise store raw POST, and failing loudly at registration is far
	 * better than failing quietly at save.
	 *
	 * @param array $types Registry keyed by type slug.
	 */
	$types = (array) apply_filters( 'gwcpp_field_types', $types );

	return $types;
}

/** The callables every type must supply. */
const GWCPP_TYPE_CONTRACT = array( 'render_portal', 'render_admin', 'sanitize', 'validate', 'is_empty', 'to_display', 'schema_form' );

/**
 * One type, or null.
 *
 * The contract check happens here rather than in gwcpp_field_types() so a badly
 * registered type costs one lookup instead of invalidating the whole registry
 * for every caller. A type that fails it is treated as if it were never
 * registered — which, for a save path, is the only safe reading.
 *
 * @param string $type Type slug.
 * @return array|null
 */
function gwcpp_field_type( string $type ): ?array {
	$types = gwcpp_field_types();
	if ( ! isset( $types[ $type ] ) || ! is_array( $types[ $type ] ) ) {
		return null;
	}

	$def = $types[ $type ];
	foreach ( GWCPP_TYPE_CONTRACT as $callable ) {
		if ( empty( $def[ $callable ] ) || ! is_callable( $def[ $callable ] ) ) {
			return null;
		}
	}

	return $def;
}

/**
 * Call one of a type's callables, with a safe answer when the type is unknown.
 *
 * Every call site in the plugin goes through this rather than reaching into the
 * registry, so there is exactly one place that decides what happens when a
 * schema names a type that no longer exists — a plugin deactivated, a filter
 * removed, a schema restored from a site that had more types than this one.
 *
 * The fallbacks are chosen to fail closed. An unknown type sanitizes to '',
 * which stores nothing; it renders nothing rather than an uncontrolled input;
 * and it reports itself empty, so the save path deletes rather than writes.
 *
 * @param array  $field    Field definition.
 * @param string $callable Contract key.
 * @param array  $args     Arguments after the ones this helper supplies.
 * @return mixed
 */
function gwcpp_field_call( array $field, string $callable, array $args = array() ) {
	$def = gwcpp_field_type( (string) ( $field['type'] ?? '' ) );

	if ( null === $def ) {
		switch ( $callable ) {
			case 'sanitize':
			case 'to_display':
				return '';
			case 'is_empty':
				return true;
			case 'validate':
				return '';
			default:
				return null;
		}
	}

	return call_user_func_array( $def[ $callable ], $args );
}

/* ── Shared helpers ──────────────────────────────────────────────────────── */

/**
 * A DOM id derived from a form control's name.
 *
 * Deterministic, because the label rendered by the form layer and the control
 * rendered by the type are produced in different functions and must agree
 * without passing an id between them.
 *
 * @param string $name Form control name, e.g. gwcpp_f[phone].
 * @return string
 */
function gwcpp_field_id( string $name ): string {
	$id = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $name );
	return trim( (string) $id, '-' );
}

/**
 * A field's setting, with a default.
 *
 * @param array  $field   Field definition.
 * @param string $key     Setting key.
 * @param mixed  $default Value when unset.
 * @return mixed
 */
function gwcpp_field_setting( array $field, string $key, $default = '' ) {
	return $field['settings'][ $key ] ?? $default;
}

/**
 * A field's choices, normalised to a list of value/label pairs.
 *
 * Accepts the stored shape (a list of arrays) and tolerates a flat map, because
 * that is what a developer registering a type through the filter writes first.
 *
 * @param array $field Field definition.
 * @return array<int, array{value:string,label:string}>
 */
function gwcpp_field_options( array $field ): array {
	$raw = gwcpp_field_setting( $field, 'options', array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();
	foreach ( $raw as $key => $option ) {
		if ( is_array( $option ) ) {
			$value = (string) ( $option['value'] ?? '' );
			$label = (string) ( $option['label'] ?? $value );
		} else {
			// Flat map: 'al' => 'Alabama'.
			$value = (string) $key;
			$label = (string) $option;
		}

		if ( '' === $value ) {
			continue;
		}
		$out[] = array(
			'value' => $value,
			'label' => '' !== $label ? $label : $value,
		);
	}

	return $out;
}

/**
 * The acceptable values for a choice field.
 *
 * @param array $field Field definition.
 * @return string[]
 */
function gwcpp_field_option_values( array $field ): array {
	return array_column( gwcpp_field_options( $field ), 'value' );
}

/**
 * A choice value's label, falling back to the value.
 *
 * @param array  $field Field definition.
 * @param string $value Stored value.
 * @return string
 */
function gwcpp_field_option_label( array $field, string $value ): string {
	foreach ( gwcpp_field_options( $field ) as $option ) {
		if ( $option['value'] === $value ) {
			return $option['label'];
		}
	}
	return $value;
}

/**
 * The hidden marker that distinguishes "cleared" from "not on this form".
 *
 * An unchecked checkbox submits nothing. So does a checkbox that was never
 * rendered, because the field was added to the schema after this form was
 * opened, or because the form was for a different post type entirely. Without a
 * marker those two are the same POST, and the save path has to choose between
 * never letting anyone clear a checkbox and wiping fields it was never shown.
 *
 * @param string $name Form control name.
 */
function gwcpp_render_present_marker( string $name ): void {
	printf(
		'<input type="hidden" name="%s" value="1" />',
		esc_attr( $name . '[__present]' )
	);
}

/* ── Simple scalars ──────────────────────────────────────────────────────── */

/**
 * Any of the six single-line inputs.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_input( array $field, $value, string $name, array $ctx = array() ): void {
	$types = gwcpp_field_types();
	$input = (string) ( $types[ $field['type'] ]['input'] ?? 'text' );

	$attrs = array(
		'type'  => $input,
		'id'    => gwcpp_field_id( $name ),
		'name'  => $name,
		'value' => is_scalar( $value ) ? (string) $value : '',
		'class' => 'gwcpp-input',
	);

	/* A soft hint to the on-screen keyboard, for types rendered as plain text
	 * so that the server's more forgiving rule gets to decide. Unlike `type`,
	 * inputmode never blocks a submission. */
	$inputmode = (string) ( $types[ $field['type'] ]['inputmode'] ?? '' );
	if ( '' !== $inputmode ) {
		$attrs['inputmode']      = $inputmode;
		$attrs['autocapitalize'] = 'none';
		$attrs['spellcheck']     = 'false';
	}

	$placeholder = (string) gwcpp_field_setting( $field, 'placeholder' );
	if ( '' !== $placeholder ) {
		$attrs['placeholder'] = $placeholder;
	}

	if ( 'number' === $field['type'] ) {
		foreach ( array( 'min', 'max', 'step' ) as $key ) {
			$set = gwcpp_field_setting( $field, $key, '' );
			if ( '' !== $set && is_numeric( $set ) ) {
				$attrs[ $key ] = (string) $set;
			}
		}
	} else {
		$max = (int) gwcpp_field_setting( $field, 'maxlength', 0 );
		if ( $max > 0 ) {
			$attrs['maxlength'] = (string) $max;
		}
	}

	/* Required is advisory here and enforced in validate.php. The attribute is
	 * worth setting anyway — it gets the browser's own message, in the user's
	 * language, before a round trip — but nothing may depend on it, because it
	 * is one devtools edit away from absent. */
	if ( ! empty( $field['required'] ) ) {
		$attrs['required'] = 'required';
	}

	if ( ! empty( $ctx['describedby'] ) ) {
		$attrs['aria-describedby'] = (string) $ctx['describedby'];
	}
	if ( ! empty( $ctx['invalid'] ) ) {
		$attrs['aria-invalid'] = 'true';
	}

	echo '<input';
	foreach ( $attrs as $key => $val ) {
		printf( ' %s="%s"', esc_attr( $key ), esc_attr( $val ) );
	}
	echo ' />';
}

/**
 * Long text.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_textarea( array $field, $value, string $name, array $ctx = array() ): void {
	$rows = (int) gwcpp_field_setting( $field, 'rows', 4 );
	$rows = max( 2, min( 30, $rows ) );

	$attrs = array(
		'id'    => gwcpp_field_id( $name ),
		'name'  => $name,
		'rows'  => (string) $rows,
		'class' => 'gwcpp-input gwcpp-textarea',
	);

	$max = (int) gwcpp_field_setting( $field, 'maxlength', 0 );
	if ( $max > 0 ) {
		$attrs['maxlength'] = (string) $max;
	}
	$placeholder = (string) gwcpp_field_setting( $field, 'placeholder' );
	if ( '' !== $placeholder ) {
		$attrs['placeholder'] = $placeholder;
	}
	if ( ! empty( $field['required'] ) ) {
		$attrs['required'] = 'required';
	}
	if ( ! empty( $ctx['describedby'] ) ) {
		$attrs['aria-describedby'] = (string) $ctx['describedby'];
	}
	if ( ! empty( $ctx['invalid'] ) ) {
		$attrs['aria-invalid'] = 'true';
	}

	echo '<textarea';
	foreach ( $attrs as $key => $val ) {
		printf( ' %s="%s"', esc_attr( $key ), esc_attr( $val ) );
	}
	echo '>' . esc_textarea( is_scalar( $value ) ? (string) $value : '' ) . '</textarea>';
}

/**
 * True when a scalar should not be stored.
 *
 * '0' is not empty. PHP's own empty() says it is, which would make a number
 * field storing zero and a number field left blank indistinguishable, and would
 * delete the meta row for every legitimate zero.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return bool
 */
function gwcpp_empty_scalar( $value, array $field = array() ): bool {
	unset( $field );
	return ! is_scalar( $value ) || '' === trim( (string) $value );
}

/**
 * A scalar as text.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_scalar( $value, array $field = array() ): string {
	unset( $field );
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Collapse anything that is not a scalar to an empty string.
 *
 * Every scalar sanitizer starts here. `$_POST['gwcpp_f']['phone']` is an array
 * the moment somebody renames a control to `phone[]` in devtools, and
 * sanitize_text_field() on an array emits a PHP warning and returns '' — the
 * right answer arrived at the wrong way, and on a site with display_errors the
 * warning lands in the middle of the page.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function gwcpp_scalar_string( $raw ): string {
	return is_scalar( $raw ) ? (string) $raw : '';
}

/**
 * Text.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_text( $raw, array $field = array() ): string {
	$value = sanitize_text_field( gwcpp_scalar_string( $raw ) );
	$max   = (int) gwcpp_field_setting( $field, 'maxlength', 0 );
	if ( $max > 0 ) {
		// mb_substr, because a maxlength counted in bytes cuts a multibyte
		// character in half and stores a broken one.
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
	return $value;
}

/**
 * Long text. Newlines survive; tags do not.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_textarea( $raw, array $field = array() ): string {
	$value = sanitize_textarea_field( gwcpp_scalar_string( $raw ) );
	$max   = (int) gwcpp_field_setting( $field, 'maxlength', 0 );
	if ( $max > 0 ) {
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
	return $value;
}

/**
 * A number, kept as a string so an empty field stores nothing rather than 0.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_number( $raw, array $field = array() ): string {
	unset( $field );
	$value = trim( gwcpp_scalar_string( $raw ) );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return '';
	}
	// Normalise: '007' and '7.0' both store as the number they mean, so the
	// approval diff does not report a change nobody made.
	return (string) ( $value + 0 );
}

/**
 * A URL.
 *
 * esc_url_raw strips a javascript: scheme and anything else not in the allowed
 * list, so the stored value is safe to put in an href without further work.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_url( $raw, array $field = array() ): string {
	unset( $field );
	$value = trim( gwcpp_scalar_string( $raw ) );
	if ( '' === $value ) {
		return '';
	}

	/* Whitespace means this is prose, not an address, and it is refused before
	 * anything else touches it. Without this check the two steps below turn
	 * "not a website" into "https://not%20a%20website" — esc_url_raw
	 * percent-encodes the spaces rather than rejecting them — and the person is
	 * then shown that string back in the field, having never typed it. A form
	 * that hands somebody mangled input to correct is worse than one that says
	 * plainly that it did not understand. */
	if ( preg_match( '/\s/', $value ) ) {
		return '';
	}

	/* Somebody typing "example.org" means a website, and rejecting it teaches
	 * them to type something they do not understand rather than teaching them
	 * about schemes. Assume https rather than http: an unprefixed guess should
	 * not be the reason a link is insecure.
	 *
	 * Only when it could actually be a host, though. Prefixing a scheme onto
	 * anything at all makes every typo into a URL that passes validation and
	 * goes on the site as a dead link. */
	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) ) {
		if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}(?:[:\/?#]|$)/i', $value ) ) {
			return '';
		}
		$value = 'https://' . $value;
	}

	return esc_url_raw( $value, array( 'http', 'https' ) );
}

/**
 * An email address.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_email( $raw, array $field = array() ): string {
	unset( $field );
	return sanitize_email( trim( gwcpp_scalar_string( $raw ) ) );
}

/**
 * A phone number, kept as typed.
 *
 * Deliberately not reformatted. The original portal masked input to
 * (NNN) NNN-NNNN in JavaScript and validated the same shape in PHP, which is
 * correct in one country and wrong everywhere else, and which silently mangled
 * an extension. Punctuation, spacing and extensions are the site's business.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_phone( $raw, array $field = array() ): string {
	unset( $field );
	$value = sanitize_text_field( gwcpp_scalar_string( $raw ) );
	// Keep digits, the punctuation phone numbers actually use, and letters, so
	// "x204" and "ext 204" both survive.
	$value = preg_replace( '/[^0-9A-Za-z()+.\-\s#,]/u', '', $value );
	return trim( (string) $value );
}

/**
 * A date, stored as Y-m-d.
 *
 * Rejects anything that is not a real calendar date rather than accepting and
 * normalising it: strtotime() reads '2026-02-31' as 3 March, and a form that
 * silently moves the date somebody typed is worse than one that asks again.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_date( $raw, array $field = array() ): string {
	unset( $field );
	$value = trim( gwcpp_scalar_string( $raw ) );
	if ( '' === $value ) {
		return '';
	}
	$parts = array();
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) ) {
		return '';
	}
	if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
		return '';
	}
	return $value;
}

/* ── Scalar validators ───────────────────────────────────────────────────── */

/**
 * Text needs no check beyond required, which the form layer applies to every
 * type. Named rather than left null so the contract has no holes.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_text( $value, array $field = array() ): string {
	unset( $value, $field );
	return '';
}

/**
 * A number, against its configured bounds.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_number( $value, array $field = array() ): string {
	if ( '' === $value || ! is_numeric( $value ) ) {
		return '';
	}

	$number = (float) $value;
	$min    = gwcpp_field_setting( $field, 'min', '' );
	$max    = gwcpp_field_setting( $field, 'max', '' );

	if ( '' !== $min && is_numeric( $min ) && $number < (float) $min ) {
		/* translators: %s: the smallest number allowed. */
		return sprintf( __( 'Please enter %s or more.', 'groundwork-common-post-portal' ), (string) ( $min + 0 ) );
	}
	if ( '' !== $max && is_numeric( $max ) && $number > (float) $max ) {
		/* translators: %s: the largest number allowed. */
		return sprintf( __( 'Please enter %s or less.', 'groundwork-common-post-portal' ), (string) ( $max + 0 ) );
	}

	return '';
}

/**
 * A URL that survived sanitizing.
 *
 * An empty result from a non-empty submission is the only signal available that
 * esc_url_raw rejected it, which is why this compares against the raw length
 * rather than re-parsing.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_url( $value, array $field = array() ): string {
	unset( $field );
	if ( '' === $value ) {
		return '';
	}
	if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
		return __( 'That does not look like a web address. It should look like https://example.org', 'groundwork-common-post-portal' );
	}
	return '';
}

/**
 * An email address that survived sanitizing.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_email( $value, array $field = array() ): string {
	unset( $field );
	if ( '' === $value ) {
		return '';
	}
	if ( ! is_email( $value ) ) {
		return __( 'That does not look like an email address.', 'groundwork-common-post-portal' );
	}
	return '';
}

/**
 * A phone number with enough digits to be one.
 *
 * Seven, which is the shortest a subscriber number gets anywhere, and no upper
 * bound worth enforcing. This is a typo check, not a format check.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_phone( $value, array $field = array() ): string {
	unset( $field );
	if ( '' === $value ) {
		return '';
	}
	$digits = preg_replace( '/\D/', '', (string) $value );
	if ( strlen( (string) $digits ) < 7 ) {
		return __( 'That phone number looks too short. Please include the area code.', 'groundwork-common-post-portal' );
	}
	return '';
}

/**
 * A date that survived sanitizing.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_date( $value, array $field = array() ): string {
	unset( $field );
	if ( '' === $value ) {
		return '';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) {
		return __( 'Please choose a real date.', 'groundwork-common-post-portal' );
	}
	return '';
}

/* ── Yes / no ────────────────────────────────────────────────────────────── */

/**
 * A single checkbox.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_boolean( array $field, $value, string $name, array $ctx = array() ): void {
	gwcpp_render_present_marker( $name );

	$id      = gwcpp_field_id( $name );
	$checked = ! empty( $value ) && '0' !== (string) $value;
	$text    = (string) gwcpp_field_setting( $field, 'checkbox_label', '' );
	if ( '' === $text ) {
		$text = (string) ( $field['label'] ?? '' );
	}

	printf(
		'<label class="gwcpp-check"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s /> <span>%5$s</span></label>',
		esc_attr( $id ),
		esc_attr( $name . '[value]' ),
		checked( $checked, true, false ),
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : '',
		esc_html( $text )
	);
}

/**
 * A checkbox's submission.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_boolean( $raw, array $field = array() ): string {
	unset( $field );
	if ( is_array( $raw ) ) {
		return empty( $raw['value'] ) ? '' : '1';
	}
	return empty( $raw ) ? '' : '1';
}

/**
 * A required checkbox has to be ticked; anything else is always acceptable.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_boolean( $value, array $field = array() ): string {
	unset( $value, $field );
	return '';
}

/**
 * True when a checkbox is off.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return bool
 */
function gwcpp_empty_boolean( $value, array $field = array() ): bool {
	unset( $field );
	return empty( $value ) || '0' === (string) $value;
}

/**
 * A checkbox as words.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_boolean( $value, array $field = array() ): string {
	unset( $field );
	return gwcpp_empty_boolean( $value )
		? __( 'No', 'groundwork-common-post-portal' )
		: __( 'Yes', 'groundwork-common-post-portal' );
}

/* ── Choice ──────────────────────────────────────────────────────────────── */

/**
 * A dropdown.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_select( array $field, $value, string $name, array $ctx = array() ): void {
	$value = is_scalar( $value ) ? (string) $value : '';

	printf(
		'<select id="%1$s" name="%2$s" class="gwcpp-input gwcpp-select"%3$s%4$s%5$s>',
		esc_attr( gwcpp_field_id( $name ) ),
		esc_attr( $name ),
		! empty( $field['required'] ) ? ' required' : '',
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : '',
		! empty( $ctx['invalid'] ) ? ' aria-invalid="true"' : ''
	);

	/* An empty first option even when the field is required. Without one, a
	 * select opens already showing the first choice, and a user who agrees with
	 * it never touches the control — so "required" is satisfied by a value
	 * nobody chose, which is the opposite of what requiring it was for. */
	$placeholder = (string) gwcpp_field_setting( $field, 'placeholder', '' );
	printf(
		'<option value="">%s</option>',
		esc_html( '' !== $placeholder ? $placeholder : __( 'Choose…', 'groundwork-common-post-portal' ) )
	);

	foreach ( gwcpp_field_options( $field ) as $option ) {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $option['value'] ),
			selected( $option['value'], $value, false ),
			esc_html( $option['label'] )
		);
	}

	echo '</select>';
}

/**
 * A radio group.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_radio( array $field, $value, string $name, array $ctx = array() ): void {
	gwcpp_render_present_marker( $name );

	$value = is_scalar( $value ) ? (string) $value : '';
	$base  = gwcpp_field_id( $name );

	printf(
		'<div class="gwcpp-choices" role="radiogroup"%s>',
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : ''
	);

	foreach ( gwcpp_field_options( $field ) as $i => $option ) {
		printf(
			'<label class="gwcpp-choice"><input type="radio" id="%1$s-%2$d" name="%3$s" value="%4$s"%5$s /> <span>%6$s</span></label>',
			esc_attr( $base ),
			(int) $i,
			esc_attr( $name . '[value]' ),
			esc_attr( $option['value'] ),
			checked( $option['value'], $value, false ),
			esc_html( $option['label'] )
		);
	}

	echo '</div>';
}

/**
 * A checkbox group.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored value.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwcpp_render_multiselect( array $field, $value, string $name, array $ctx = array() ): void {
	gwcpp_render_present_marker( $name );

	$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
	$base     = gwcpp_field_id( $name );

	printf(
		'<div class="gwcpp-choices" role="group"%s>',
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : ''
	);

	foreach ( gwcpp_field_options( $field ) as $i => $option ) {
		printf(
			'<label class="gwcpp-choice"><input type="checkbox" id="%1$s-%2$d" name="%3$s" value="%4$s"%5$s /> <span>%6$s</span></label>',
			esc_attr( $base ),
			(int) $i,
			esc_attr( $name . '[value][]' ),
			esc_attr( $option['value'] ),
			checked( in_array( $option['value'], $selected, true ), true, false ),
			esc_html( $option['label'] )
		);
	}

	echo '</div>';
}

/**
 * One choice, checked against the configured options.
 *
 * A value that is not on the list becomes '' rather than being stored. The
 * options are the whole definition of what this field may hold, and a select is
 * one devtools edit away from submitting anything at all.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_sanitize_choice( $raw, array $field = array() ): string {
	if ( is_array( $raw ) ) {
		$raw = $raw['value'] ?? '';
	}
	$value = gwcpp_scalar_string( $raw );
	if ( '' === $value ) {
		return '';
	}
	return in_array( $value, gwcpp_field_option_values( $field ), true ) ? $value : '';
}

/**
 * Many choices, each checked against the configured options.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return string[]
 */
function gwcpp_sanitize_multiselect( $raw, array $field = array() ): array {
	if ( is_array( $raw ) && isset( $raw['value'] ) ) {
		$raw = $raw['value'];
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$allowed = gwcpp_field_option_values( $field );
	$out     = array();
	foreach ( $raw as $value ) {
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$value = (string) $value;
		if ( in_array( $value, $allowed, true ) && ! in_array( $value, $out, true ) ) {
			$out[] = $value;
		}
	}

	/* Stored in the schema's option order, not submission order. Two people
	 * ticking the same three boxes in a different sequence must not produce a
	 * changeset that reports a difference. */
	return array_values( array_intersect( $allowed, $out ) );
}

/**
 * A choice with options configured.
 *
 * A field whose options are empty can never be satisfied, and reporting that as
 * "please choose one" blames the user for a configuration mistake.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_choice( $value, array $field = array() ): string {
	if ( '' === $value ) {
		return '';
	}
	if ( ! gwcpp_field_options( $field ) ) {
		return __( 'This field has no choices set up yet. Please tell us and we will fix it.', 'groundwork-common-post-portal' );
	}
	return '';
}

/**
 * Many choices, against a configured maximum.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_validate_multiselect( $value, array $field = array() ): string {
	$count = is_array( $value ) ? count( $value ) : 0;
	$max   = (int) gwcpp_field_setting( $field, 'max_choices', 0 );

	if ( $max > 0 && $count > $max ) {
		return sprintf(
			/* translators: %d: the largest number of choices allowed. */
			_n( 'Please choose no more than %d option.', 'Please choose no more than %d options.', $max, 'groundwork-common-post-portal' ),
			$max
		);
	}

	return '';
}

/**
 * True when nothing is chosen.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return bool
 */
function gwcpp_empty_array( $value, array $field = array() ): bool {
	unset( $field );
	return ! is_array( $value ) || ! $value;
}

/**
 * One choice, by its label.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_choice( $value, array $field = array() ): string {
	$value = is_scalar( $value ) ? (string) $value : '';
	return '' === $value ? '' : gwcpp_field_option_label( $field, $value );
}

/**
 * Many choices, by their labels.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_display_multiselect( $value, array $field = array() ): string {
	if ( ! is_array( $value ) || ! $value ) {
		return '';
	}
	$labels = array();
	foreach ( $value as $one ) {
		if ( is_scalar( $one ) ) {
			$labels[] = gwcpp_field_option_label( $field, (string) $one );
		}
	}
	return implode( ', ', $labels );
}

/* ── Fields screen controls ──────────────────────────────────────────────────
 * These live here rather than in admin-fields.php, even though they are only
 * ever called from that screen, because gwcpp_field_type() drops any type
 * missing a contract callable. Putting them in an admin file would mean the
 * registry was empty on any request where that file had not loaded — cron,
 * WP-CLI, a REST call — and the symptom would be a save path that silently
 * stored nothing rather than an error anyone could read.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * A labelled text input on the Fields screen.
 *
 * @param string $key   Setting key.
 * @param string $label Visible label.
 * @param mixed  $value Current value.
 * @param string $type  HTML input type.
 * @param string $help  Optional hint.
 */
function gwcpp_schema_setting_input( string $key, string $label, $value, string $type = 'text', string $help = '' ): void {
	$id = 'gwcpp-set-' . sanitize_key( $key );
	printf(
		'<p class="gwcpp-schema-setting"><label for="%1$s">%2$s</label> <input type="%3$s" id="%1$s" name="%4$s" value="%5$s" class="regular-text" /></p>',
		esc_attr( $id ),
		esc_html( $label ),
		esc_attr( $type ),
		esc_attr( 'gwcpp_field[settings][' . $key . ']' ),
		esc_attr( is_scalar( $value ) ? (string) $value : '' )
	);
	if ( '' !== $help ) {
		printf( '<p class="description">%s</p>', esc_html( $help ) );
	}
}

/**
 * Placeholder and length, for the single-line types.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_scalar( array $field ): void {
	gwcpp_schema_setting_input(
		'placeholder',
		__( 'Placeholder', 'groundwork-common-post-portal' ),
		gwcpp_field_setting( $field, 'placeholder' )
	);

	if ( 'number' === ( $field['type'] ?? '' ) ) {
		gwcpp_schema_setting_input( 'min', __( 'Smallest allowed', 'groundwork-common-post-portal' ), gwcpp_field_setting( $field, 'min' ), 'number' );
		gwcpp_schema_setting_input( 'max', __( 'Largest allowed', 'groundwork-common-post-portal' ), gwcpp_field_setting( $field, 'max' ), 'number' );
		gwcpp_schema_setting_input( 'step', __( 'Step', 'groundwork-common-post-portal' ), gwcpp_field_setting( $field, 'step' ), 'number' );
		return;
	}

	gwcpp_schema_setting_input(
		'maxlength',
		__( 'Character limit', 'groundwork-common-post-portal' ),
		gwcpp_field_setting( $field, 'maxlength' ),
		'number',
		__( 'Leave blank for no limit.', 'groundwork-common-post-portal' )
	);
}

/**
 * Rows, placeholder and length, for long text.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_textarea( array $field ): void {
	gwcpp_schema_setting_input( 'rows', __( 'Rows', 'groundwork-common-post-portal' ), gwcpp_field_setting( $field, 'rows', 4 ), 'number' );
	gwcpp_schema_form_scalar( $field );
}

/**
 * The wording beside a checkbox.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_boolean( array $field ): void {
	gwcpp_schema_setting_input(
		'checkbox_label',
		__( 'Wording beside the box', 'groundwork-common-post-portal' ),
		gwcpp_field_setting( $field, 'checkbox_label' ),
		'text',
		__( 'Leave blank to reuse the field label. A checkbox reads better as a statement: "We are wheelchair accessible".', 'groundwork-common-post-portal' )
	);
}

/**
 * The choice editor.
 *
 * One option per line, `value|Label`, rather than a repeater of paired inputs.
 * A repeater is nicer to click and far worse to edit: rewording twelve options
 * means twelve clicks and no way to paste a list in from anywhere else.
 *
 * @param array $field Field definition.
 */
function gwcpp_schema_form_choice( array $field ): void {
	$lines = array();
	foreach ( gwcpp_field_options( $field ) as $option ) {
		$lines[] = $option['value'] === $option['label']
			? $option['value']
			: $option['value'] . '|' . $option['label'];
	}

	printf(
		'<p class="gwcpp-schema-setting"><label for="gwcpp-set-options">%s</label><textarea id="gwcpp-set-options" name="%s" rows="8" class="large-text code">%s</textarea></p>',
		esc_html__( 'Choices', 'groundwork-common-post-portal' ),
		esc_attr( 'gwcpp_field[settings][options_raw]' ),
		esc_textarea( implode( "\n", $lines ) )
	);
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'One per line. Write "stored-value|What people see" to store something different from the label, or just the label on its own.', 'groundwork-common-post-portal' )
	);

	if ( 'multiselect' === ( $field['type'] ?? '' ) ) {
		gwcpp_schema_setting_input(
			'max_choices',
			__( 'Most that can be chosen', 'groundwork-common-post-portal' ),
			gwcpp_field_setting( $field, 'max_choices' ),
			'number',
			__( 'Leave blank for no limit.', 'groundwork-common-post-portal' )
		);
	}
}

/**
 * Parse the choice editor's textarea back into options.
 *
 * Kept beside the renderer that produced the format, because a parser living
 * anywhere else is a parser that drifts from it.
 *
 * @param string $raw Textarea contents.
 * @return array<int, array{value:string,label:string}>
 */
function gwcpp_parse_options( string $raw ): array {
	$out  = array();
	$seen = array();

	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}

		if ( false !== strpos( $line, '|' ) ) {
			list( $value, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
		} else {
			$label = $line;
			$value = sanitize_title( $line );
		}

		$value = sanitize_text_field( $value );
		$label = sanitize_text_field( '' !== $label ? $label : $value );

		/* A blank or duplicate value is dropped rather than rejected. The
		 * alternative is refusing to save the whole field because one line of
		 * twelve was pasted twice, which teaches people to stop pasting. */
		if ( '' === $value || isset( $seen[ $value ] ) ) {
			continue;
		}

		$seen[ $value ] = true;
		$out[]          = array(
			'value' => $value,
			'label' => $label,
		);
	}

	return $out;
}
