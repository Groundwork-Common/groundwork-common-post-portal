<?php
/**
 * The taxonomy field: real terms, not a copy of them in post meta.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── Why this type does not behave like the others ───────────────────────────
 * Every other field stores its value in post meta under its own key. This one
 * does not, and cannot: the whole point of using a taxonomy is that the terms
 * are shared, queryable, and already drive archives, filters and menus
 * elsewhere on the site. Writing "food, advice" into a meta row would produce a
 * second, private copy that silently disagrees with the real one.
 *
 * So the field's key is the taxonomy slug, and save.php routes it to
 * wp_set_object_terms() instead of update_post_meta(). The registry contract is
 * unchanged — sanitize still returns a value and to_display still renders it —
 * which is what lets the diff, the changeset and the validator treat it like
 * anything else without knowing where it ends up.
 *
 * Term creation is off by default. A portal user typing a slightly different
 * spelling of an existing term does not enrich the taxonomy, it forks it, and
 * the site ends up with "Food", "food " and "Foods" as three separate archives.
 * ───────────────────────────────────────────────────────────────────────────
 */

add_filter( 'gwc_pp_field_types', 'gwc_pp_register_taxonomy_type' );

/**
 * Register the type.
 *
 * @param array $types Registry.
 * @return array
 */
function gwc_pp_register_taxonomy_type( array $types ): array {
	$types['taxonomy'] = array(
		'label'         => __( 'Categories or tags', 'groundwork-common-post-portal' ),
		'group'         => 'choice',
		'render_portal' => 'gwc_pp_render_taxonomy',
		'render_admin'  => 'gwc_pp_render_taxonomy',
		'sanitize'      => 'gwc_pp_sanitize_taxonomy',
		'validate'      => 'gwc_pp_validate_taxonomy',
		'is_empty'      => 'gwc_pp_empty_array',
		'to_display'    => 'gwc_pp_display_taxonomy',
		'schema_form'   => 'gwc_pp_schema_form_taxonomy',
		'needs_present' => true,
		// Read by save.php and by current-values, so neither has to know the
		// type slug. A third-party type wanting the same treatment sets this
		// and gets it.
		'is_taxonomy'   => true,
	);

	return $types;
}

/**
 * The taxonomy a field is bound to, or ''.
 *
 * The field key IS the taxonomy, so a field pointing at a taxonomy that no
 * longer exists resolves to nothing and renders nothing, rather than throwing.
 *
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_field_taxonomy( array $field ): string {
	$taxonomy = (string) ( $field['key'] ?? '' );

	return taxonomy_exists( $taxonomy ) ? $taxonomy : '';
}

/**
 * True when a field stores terms rather than meta.
 *
 * @param string $type Type slug.
 * @return bool
 */
function gwc_pp_type_is_taxonomy( string $type ): bool {
	$def = gwc_pp_field_type( $type );

	return null !== $def && ! empty( $def['is_taxonomy'] );
}

/**
 * The terms available to choose from.
 *
 * @param array $field Field definition.
 * @return WP_Term[]
 */
function gwc_pp_taxonomy_terms( array $field ): array {
	$taxonomy = gwc_pp_field_taxonomy( $field );
	if ( '' === $taxonomy ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 300,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	return is_array( $terms ) ? $terms : array();
}

/**
 * Checkboxes for each term.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Stored term IDs.
 * @param string $name  Form control name.
 * @param array  $ctx   Render context.
 */
function gwc_pp_render_taxonomy( array $field, $value, string $name, array $ctx = array() ): void {
	gwc_pp_render_present_marker( $name );

	$terms = gwc_pp_taxonomy_terms( $field );

	if ( ! $terms ) {
		printf(
			'<p class="gwcpp-field__hint">%s</p>',
			esc_html__( 'There is nothing to choose from here yet.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$selected = is_array( $value ) ? array_map( 'intval', $value ) : array();
	$base     = gwc_pp_field_id( $name );

	printf(
		'<div class="gwcpp-choices" role="group"%s>',
		! empty( $ctx['describedby'] ) ? ' aria-describedby="' . esc_attr( (string) $ctx['describedby'] ) . '"' : ''
	);

	foreach ( $terms as $i => $term ) {
		printf(
			'<label class="gwcpp-choice"><input type="checkbox" id="%1$s-%2$d" name="%3$s" value="%4$d"%5$s /> <span>%6$s</span></label>',
			esc_attr( $base ),
			(int) $i,
			esc_attr( $name . '[value][]' ),
			(int) $term->term_id,
			checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
			esc_html( $term->name )
		);
	}

	echo '</div>';

	if ( gwc_pp_field_setting( $field, 'allow_new' ) ) {
		printf(
			'<label class="gwcpp-label gwcpp-label--sub" for="%1$s-new">%2$s</label><input type="text" id="%1$s-new" name="%3$s" class="gwcpp-input" /><p class="gwcpp-field__hint">%4$s</p>',
			esc_attr( $base ),
			esc_html__( 'Something not on the list', 'groundwork-common-post-portal' ),
			esc_attr( $name . '[new]' ),
			esc_html__( 'Separate several with commas. Check the list first — a near-duplicate makes a second category rather than joining the existing one.', 'groundwork-common-post-portal' )
		);
	}
}

/**
 * Term IDs, checked against the taxonomy.
 *
 * @param mixed $raw   Raw value.
 * @param array $field Field definition.
 * @return int[]
 */
function gwc_pp_sanitize_taxonomy( $raw, array $field = array() ): array {
	$taxonomy = gwc_pp_field_taxonomy( $field );
	if ( '' === $taxonomy || ! is_array( $raw ) ) {
		return array();
	}

	$chosen = isset( $raw['value'] ) && is_array( $raw['value'] ) ? $raw['value'] : array();
	$out    = array();

	foreach ( $chosen as $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			continue;
		}

		/*
		 * Confirmed to belong to THIS taxonomy. A term ID is a global integer,
		 * so a crafted submission could otherwise attach a term from any
		 * taxonomy on the site — including a private one used for access
		 * control by some other plugin.
		 */
		$term = get_term( $term_id, $taxonomy );
		if ( $term instanceof WP_Term && ! in_array( $term->term_id, $out, true ) ) {
			$out[] = (int) $term->term_id;
		}
	}

	if ( ! empty( $raw['new'] ) && gwc_pp_field_setting( $field, 'allow_new' ) ) {
		foreach ( gwc_pp_create_terms( (string) $raw['new'], $taxonomy ) as $term_id ) {
			if ( ! in_array( $term_id, $out, true ) ) {
				$out[] = $term_id;
			}
		}
	}

	sort( $out );

	return $out;
}

/**
 * Turn typed names into term IDs, reusing anything that already exists.
 *
 * Matching an existing term by name rather than creating blindly is most of the
 * value here: "Food" typed into the new-term box when Food already exists
 * should select it, not make a second one.
 *
 * @param string $raw      Comma-separated names.
 * @param string $taxonomy Taxonomy slug.
 * @return int[]
 */
function gwc_pp_create_terms( string $raw, string $taxonomy ): array {
	$out = array();

	foreach ( explode( ',', $raw ) as $name ) {
		$name = sanitize_text_field( trim( $name ) );
		if ( '' === $name ) {
			continue;
		}

		// Bounded, so a pasted paragraph cannot create fifty categories.
		if ( count( $out ) >= 10 ) {
			break;
		}

		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing instanceof WP_Term ) {
			$out[] = (int) $existing->term_id;
			continue;
		}

		$created = wp_insert_term( $name, $taxonomy );
		if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
			$out[] = (int) $created['term_id'];
		}
	}

	return $out;
}

/**
 * Check the count, and that the taxonomy still exists.
 *
 * @param mixed $value Sanitized value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_validate_taxonomy( $value, array $field = array() ): string {
	if ( '' === gwc_pp_field_taxonomy( $field ) ) {
		return __( 'This field is not set up properly. Please tell us and we will fix it.', 'groundwork-common-post-portal' );
	}

	$max = (int) gwc_pp_field_setting( $field, 'max_choices', 0 );
	if ( $max > 0 && is_array( $value ) && count( $value ) > $max ) {
		return sprintf(
			/* translators: %d: the largest number of choices allowed. */
			_n( 'Please choose no more than %d.', 'Please choose no more than %d.', $max, 'groundwork-common-post-portal' ),
			$max
		);
	}

	return '';
}

/**
 * Term names, for the diff and the emails.
 *
 * @param mixed $value Value.
 * @param array $field Field definition.
 * @return string
 */
function gwc_pp_display_taxonomy( $value, array $field = array() ): string {
	$taxonomy = gwc_pp_field_taxonomy( $field );
	if ( '' === $taxonomy || ! is_array( $value ) || ! $value ) {
		return '';
	}

	$names = array();
	foreach ( $value as $term_id ) {
		$term = get_term( (int) $term_id, $taxonomy );
		if ( $term instanceof WP_Term ) {
			$names[] = $term->name;
		}
	}

	sort( $names );

	return implode( ', ', $names );
}

/**
 * The Fields screen controls.
 *
 * @param array $field Field definition.
 */
function gwc_pp_schema_form_taxonomy( array $field ): void {
	$taxonomy = (string) ( $field['key'] ?? '' );

	if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
		printf(
			'<p class="description" style="color:#b32d2e;">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a taxonomy slug. */
					__( 'No taxonomy called "%s" is registered, so this field will not appear on the form.', 'groundwork-common-post-portal' ),
					$taxonomy
				)
			)
		);
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'For this type, "Stored as" must be the taxonomy slug — for example category, post_tag, or one your site registers. Terms are read live, so the list is always current.', 'groundwork-common-post-portal' )
	);

	printf(
		'<p class="gwcpp-schema-setting"><label><input type="checkbox" name="%s" value="1"%s /> %s</label></p>',
		esc_attr( 'gwc_pp_field[settings][allow_new]' ),
		checked( (bool) gwc_pp_field_setting( $field, 'allow_new' ), true, false ),
		esc_html__( 'Let portal users add new ones', 'groundwork-common-post-portal' )
	);

	gwc_pp_schema_setting_input(
		'max_choices',
		__( 'Most that can be chosen', 'groundwork-common-post-portal' ),
		gwc_pp_field_setting( $field, 'max_choices' ),
		'number',
		__( 'Leave blank for no limit.', 'groundwork-common-post-portal' )
	);
}
