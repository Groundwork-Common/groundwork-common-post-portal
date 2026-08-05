<?php
/**
 * The Fields screen: deciding what a portal user may edit.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwcpp_save_field', 'gwcpp_handle_save_field' );
add_action( 'admin_post_gwcpp_retire_field', 'gwcpp_handle_retire_field' );
add_action( 'admin_post_gwcpp_reorder_fields', 'gwcpp_handle_reorder_fields' );
add_action( 'admin_post_gwcpp_import_fields', 'gwcpp_handle_import_fields' );

/**
 * Which post type the screen is showing.
 *
 * Defaults to the first enabled one rather than to nothing, so the screen has
 * something on it the moment a post type is switched on.
 *
 * @return string
 */
function gwcpp_fields_post_type(): string {
	$enabled = gwcpp_post_types();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection on a GET request, validated against the enabled list.
	$requested = isset( $_GET['gwcpp_type'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_type'] ) ) : '';

	if ( '' !== $requested && in_array( $requested, $enabled, true ) ) {
		return $requested;
	}

	return $enabled ? (string) $enabled[0] : '';
}

/**
 * The Fields screen.
 */
function gwcpp_fields_screen(): void {
	gwcpp_require_admin_caps();

	$post_type = gwcpp_fields_post_type();

	echo '<div class="wrap gwcpp-admin">';
	printf( '<h1>%s</h1>', esc_html__( 'Portal Fields', 'groundwork-common-post-portal' ) );

	gwcpp_render_admin_notice();

	if ( '' === $post_type ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Switch on a post type under Portal → Settings first, then come back here to choose what people may edit on it.', 'groundwork-common-post-portal' )
		);
		echo '</div>';
		return;
	}

	gwcpp_render_type_switcher( $post_type );

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'These are the only things a portal user can change. Anything not listed here stays yours.', 'groundwork-common-post-portal' )
	);

	echo '<div class="gwcpp-fields-layout">';
	gwcpp_render_field_table( $post_type );
	gwcpp_render_field_editor( $post_type );
	echo '</div>';

	echo '</div>';
}

/**
 * The post type tabs, when more than one is enabled.
 *
 * @param string $current Current post type.
 */
function gwcpp_render_type_switcher( string $current ): void {
	$enabled = gwcpp_post_types();

	if ( count( $enabled ) < 2 ) {
		$object = get_post_type_object( $current );
		if ( $object ) {
			printf( '<h2>%s</h2>', esc_html( $object->labels->name ) );
		}
		return;
	}

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $enabled as $post_type ) {
		$object = get_post_type_object( $post_type );
		if ( ! $object ) {
			continue;
		}
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=gwcpp-fields&gwcpp_type=' . rawurlencode( $post_type ) ) ),
			$post_type === $current ? ' nav-tab-active' : '',
			esc_html( $object->labels->name )
		);
	}
	echo '</nav>';
}

/**
 * The list of mapped fields, in order, with the reorder form around it.
 *
 * @param string $post_type Post type slug.
 */
function gwcpp_render_field_table( string $post_type ): void {
	$fields = gwcpp_type_fields( $post_type );

	echo '<div class="gwcpp-fields-list">';

	if ( ! $fields ) {
		echo '<div class="gwcpp-empty-state">';
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Nothing is mapped yet.', 'groundwork-common-post-portal' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'Add fields on the right, or import whatever this post type has already registered.', 'groundwork-common-post-portal' )
		);
		gwcpp_render_import_form( $post_type );
		echo '</div></div>';
		return;
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'gwcpp_reorder_fields' );
	echo '<input type="hidden" name="action" value="gwcpp_reorder_fields" />';
	printf( '<input type="hidden" name="gwcpp_type" value="%s" />', esc_attr( $post_type ) );

	echo '<table class="widefat striped"><thead><tr>';
	printf( '<th scope="col" class="gwcpp-col-order">%s</th>', esc_html__( 'Order', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Label', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Stored as', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Type', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Required', 'groundwork-common-post-portal' ) );
	echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'groundwork-common-post-portal' ) . '</span></th>';
	echo '</tr></thead><tbody>';

	$types = gwcpp_field_types();

	foreach ( $fields as $i => $field ) {
		$key = (string) $field['key'];

		echo '<tr>';

		/* A number input rather than up/down arrows. Arrows are one round trip
		 * per move, which for a twelve-field form somebody is rearranging means
		 * eleven page loads; typing the positions and saving once is one. */
		printf(
			'<td><label class="screen-reader-text" for="gwcpp-order-%1$s">%2$s</label><input type="number" id="gwcpp-order-%1$s" name="gwcpp_order[%1$s]" value="%3$d" min="1" class="small-text" /></td>',
			esc_attr( $key ),
			esc_html__( 'Position', 'groundwork-common-post-portal' ),
			(int) $i + 1
		);

		printf( '<td><strong>%s</strong>', esc_html( gwcpp_field_label( $field ) ) );
		if ( '' !== (string) $field['description'] ) {
			printf( '<br /><span class="description">%s</span>', esc_html( (string) $field['description'] ) );
		}
		echo '</td>';

		if ( gwcpp_is_synthetic( $key ) ) {
			printf(
				'<td><span class="gwcpp-pill">%s</span></td>',
				esc_html__( 'built in', 'groundwork-common-post-portal' )
			);
		} else {
			printf( '<td><code>%s</code></td>', esc_html( $key ) );
		}

		printf( '<td>%s</td>', esc_html( (string) ( $types[ $field['type'] ]['label'] ?? $field['type'] ) ) );
		printf( '<td>%s</td>', ! empty( $field['required'] ) ? esc_html__( 'Yes', 'groundwork-common-post-portal' ) : '—' );

		echo '<td class="gwcpp-row-actions">';
		printf(
			'<a href="%s">%s</a>',
			esc_url(
				admin_url(
					'admin.php?page=gwcpp-fields&gwcpp_type=' . rawurlencode( $post_type ) . '&edit=' . rawurlencode( $key )
				)
			),
			esc_html__( 'Edit', 'groundwork-common-post-portal' )
		);
		echo ' | ';
		printf(
			'<a href="%s" class="gwcpp-delete">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url(
						'admin-post.php?action=gwcpp_retire_field&gwcpp_type=' . rawurlencode( $post_type ) . '&key=' . rawurlencode( $key )
					),
					'gwcpp_retire_field_' . $key
				)
			),
			esc_html__( 'Remove', 'groundwork-common-post-portal' )
		);
		echo '</td></tr>';
	}

	echo '</tbody></table>';

	submit_button( __( 'Save order', 'groundwork-common-post-portal' ), 'secondary' );

	echo '</form>';

	gwcpp_render_import_form( $post_type );

	echo '</div>';
}

/**
 * The import button.
 *
 * @param string $post_type Post type slug.
 */
function gwcpp_render_import_form( string $post_type ): void {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gwcpp-import">';
	wp_nonce_field( 'gwcpp_import_fields' );
	echo '<input type="hidden" name="action" value="gwcpp_import_fields" />';
	printf( '<input type="hidden" name="gwcpp_type" value="%s" />', esc_attr( $post_type ) );
	printf(
		'<button type="submit" class="button">%s</button> <span class="description">%s</span>',
		esc_html__( 'Import fields already registered', 'groundwork-common-post-portal' ),
		esc_html__( 'Looks for meta registered with register_meta(). Nothing already mapped is touched.', 'groundwork-common-post-portal' )
	);
	echo '</form>';
}

/**
 * The add / edit panel.
 *
 * @param string $post_type Post type slug.
 */
function gwcpp_render_field_editor( string $post_type ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; picks which field to prefill the form with.
	$edit_key = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';

	$field = '' !== $edit_key ? gwcpp_find_field( $post_type, $edit_key ) : null;
	if ( null === $field ) {
		$field    = gwcpp_field_defaults();
		$edit_key = '';
	}

	$is_synthetic = '' !== $edit_key && gwcpp_is_synthetic( $edit_key );

	echo '<div class="gwcpp-field-editor postbox"><div class="inside">';

	printf(
		'<h2>%s</h2>',
		esc_html( '' !== $edit_key ? __( 'Edit field', 'groundwork-common-post-portal' ) : __( 'Add a field', 'groundwork-common-post-portal' ) )
	);

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'gwcpp_save_field' );
	echo '<input type="hidden" name="action" value="gwcpp_save_field" />';
	printf( '<input type="hidden" name="gwcpp_type" value="%s" />', esc_attr( $post_type ) );

	// What to store it as.
	echo '<p class="gwcpp-schema-setting">';
	printf( '<label for="gwcpp-field-key">%s</label>', esc_html__( 'Stored as', 'groundwork-common-post-portal' ) );

	if ( $is_synthetic ) {
		printf( '<input type="hidden" name="gwcpp_field[key]" value="%s" />', esc_attr( $edit_key ) );
		printf(
			'<input type="text" id="gwcpp-field-key" value="%s" class="regular-text" disabled />',
			esc_attr( gwcpp_synthetic_fields()[ $edit_key ]['label'] )
		);
		printf(
			'<span class="description">%s</span>',
			esc_html__( 'This is one of the post\'s own fields, so where it is stored cannot change.', 'groundwork-common-post-portal' )
		);
	} else {
		printf(
			'<input type="text" id="gwcpp-field-key" name="gwcpp_field[key]" value="%s" class="regular-text" %s required />',
			esc_attr( (string) $field['key'] ),
			'' !== $edit_key ? 'readonly' : ''
		);
		printf(
			'<span class="description">%s</span>',
			'' !== $edit_key
				? esc_html__( 'Cannot be changed once data exists under it. Remove the field and add a new one to move to a different key.', 'groundwork-common-post-portal' )
				: esc_html__( 'The post meta key, e.g. contact_phone. Lowercase letters, numbers and underscores.', 'groundwork-common-post-portal' )
		);
	}
	echo '</p>';

	// Built-in fields not yet mapped.
	if ( '' === $edit_key ) {
		gwcpp_render_synthetic_shortcuts( $post_type );
	}

	// Label.
	printf(
		'<p class="gwcpp-schema-setting"><label for="gwcpp-field-label">%s</label><input type="text" id="gwcpp-field-label" name="gwcpp_field[label]" value="%s" class="regular-text" /></p>',
		esc_html__( 'Label', 'groundwork-common-post-portal' ),
		esc_attr( (string) $field['label'] )
	);

	// Help text.
	printf(
		'<p class="gwcpp-schema-setting"><label for="gwcpp-field-desc">%s</label><input type="text" id="gwcpp-field-desc" name="gwcpp_field[description]" value="%s" class="regular-text" /><span class="description">%s</span></p>',
		esc_html__( 'Help text', 'groundwork-common-post-portal' ),
		esc_attr( (string) $field['description'] ),
		esc_html__( 'Shown above the control. Say what good input looks like, not what the field is called.', 'groundwork-common-post-portal' )
	);

	// Type.
	echo '<p class="gwcpp-schema-setting">';
	printf( '<label for="gwcpp-field-type">%s</label>', esc_html__( 'Type', 'groundwork-common-post-portal' ) );

	if ( $is_synthetic ) {
		printf( '<input type="hidden" name="gwcpp_field[type]" value="%s" />', esc_attr( (string) $field['type'] ) );
		printf( '<code>%s</code>', esc_html( (string) $field['type'] ) );
	} else {
		echo '<select id="gwcpp-field-type" name="gwcpp_field[type]">';
		foreach ( gwcpp_grouped_field_types() as $group => $types ) {
			printf( '<optgroup label="%s">', esc_attr( $group ) );
			foreach ( $types as $slug => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $slug ),
					selected( $slug, (string) $field['type'], false ),
					esc_html( $label )
				);
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}
	echo '</p>';

	// Required.
	printf(
		'<p class="gwcpp-schema-setting"><label><input type="checkbox" name="gwcpp_field[required]" value="1"%s /> %s</label></p>',
		checked( ! empty( $field['required'] ), true, false ),
		esc_html__( 'Must be filled in', 'groundwork-common-post-portal' )
	);

	// Per-type settings.
	echo '<div class="gwcpp-type-settings">';
	gwcpp_field_call( $field, 'schema_form', array( $field ) );
	echo '</div>';

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'The settings above belong to the type currently selected. Change the type and save to see its own.', 'groundwork-common-post-portal' )
	);

	submit_button( '' !== $edit_key ? __( 'Save field', 'groundwork-common-post-portal' ) : __( 'Add field', 'groundwork-common-post-portal' ) );

	if ( '' !== $edit_key ) {
		printf(
			'<a class="button-link" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=gwcpp-fields&gwcpp_type=' . rawurlencode( $post_type ) ) ),
			esc_html__( 'Cancel', 'groundwork-common-post-portal' )
		);
	}

	echo '</form></div></div>';
}

/**
 * Quick links for the post's own fields that are not mapped yet.
 *
 * The title in particular: a portal where nobody can change the name of the
 * thing they are editing is a portal somebody has to be told about, and it is
 * not obvious that "Title" is something you add rather than something that is
 * simply there.
 *
 * @param string $post_type Post type slug.
 */
function gwcpp_render_synthetic_shortcuts( string $post_type ): void {
	$available = array();

	foreach ( gwcpp_synthetic_fields() as $key => $spec ) {
		if ( null === gwcpp_find_field( $post_type, $key ) ) {
			$available[ $key ] = $spec['label'];
		}
	}

	if ( ! $available ) {
		return;
	}

	echo '<p class="gwcpp-synthetic-shortcuts description">';
	esc_html_e( 'Or add one of the post\'s own fields:', 'groundwork-common-post-portal' );
	echo ' ';

	$links = array();
	foreach ( $available as $key => $label ) {
		$links[] = sprintf(
			'<button type="submit" class="button-link" name="gwcpp_field[key]" value="%s">%s</button>',
			esc_attr( $key ),
			esc_html( $label )
		);
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped as it is built above.
	echo implode( ', ', $links );
	echo '</p>';
}

/**
 * Field types, grouped for the dropdown.
 *
 * @return array<string, array<string, string>>
 */
function gwcpp_grouped_field_types(): array {
	$groups = gwcpp_field_groups();
	$out    = array();

	foreach ( gwcpp_field_types() as $slug => $def ) {
		if ( null === gwcpp_field_type( $slug ) ) {
			continue;
		}
		$group           = (string) ( $def['group'] ?? 'simple' );
		$label           = (string) ( $groups[ $group ] ?? $group );
		$out[ $label ][ $slug ] = (string) ( $def['label'] ?? $slug );
	}

	return $out;
}

/* ── Handlers ────────────────────────────────────────────────────────────── */

/**
 * The post type a handler was given, validated.
 *
 * @return string
 */
function gwcpp_handler_post_type(): string {
	// phpcs:ignore WordPress.Security.NonceVerification -- Every caller verifies its own nonce before calling this.
	$raw = isset( $_REQUEST['gwcpp_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['gwcpp_type'] ) ) : '';

	if ( ! gwcpp_type_enabled( $raw ) ) {
		wp_die(
			esc_html__( 'That post type is not switched on for the portal.', 'groundwork-common-post-portal' ),
			'',
			array( 'response' => 400 )
		);
	}

	return $raw;
}

/**
 * Back to the Fields screen with a message code.
 *
 * @param string $post_type Post type slug.
 * @param string $code      Message code.
 */
function gwcpp_fields_redirect( string $post_type, string $code ): void {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => 'gwcpp-fields',
				'gwcpp_type'   => $post_type,
				'gwcpp_notice' => $code,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Add or update a field.
 */
function gwcpp_handle_save_field(): void {
	gwcpp_require_admin_caps();
	check_admin_referer( 'gwcpp_save_field' );

	$post_type = gwcpp_handler_post_type();

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Sanitized in full by gwcpp_sanitize_field().
	$raw = isset( $_POST['gwcpp_field'] ) && is_array( $_POST['gwcpp_field'] )
		? (array) wp_unslash( $_POST['gwcpp_field'] )
		: array();

	/* A synthetic shortcut button submits only the key. Fill in what that key
	 * already knows about itself so the field is complete without the person
	 * having to pick a type for something whose type is fixed. */
	if ( isset( $raw['key'] ) && gwcpp_is_synthetic( (string) $raw['key'] ) && empty( $raw['type'] ) ) {
		$spec         = gwcpp_synthetic_fields()[ (string) $raw['key'] ];
		$raw['type']  = $spec['type'];
		$raw['label'] = $spec['label'];
	}

	$field = gwcpp_sanitize_field( $raw );

	if ( null === $field ) {
		gwcpp_fields_redirect( $post_type, 'field_bad' );
	}

	gwcpp_put_field( $post_type, $field );
	gwcpp_fields_redirect( $post_type, 'field_saved' );
}

/**
 * Take a field off the form.
 */
function gwcpp_handle_retire_field(): void {
	gwcpp_require_admin_caps();

	// phpcs:ignore WordPress.Security.NonceVerification -- Verified immediately below against this same value.
	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

	check_admin_referer( 'gwcpp_retire_field_' . $key );

	$post_type = gwcpp_handler_post_type();

	gwcpp_retire_field( $post_type, $key );
	gwcpp_fields_redirect( $post_type, 'field_retired' );
}

/**
 * Save the order.
 */
function gwcpp_handle_reorder_fields(): void {
	gwcpp_require_admin_caps();
	check_admin_referer( 'gwcpp_reorder_fields' );

	$post_type = gwcpp_handler_post_type();

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Keys and values are both cast below.
	$raw = isset( $_POST['gwcpp_order'] ) && is_array( $_POST['gwcpp_order'] )
		? (array) wp_unslash( $_POST['gwcpp_order'] )
		: array();

	$positions = array();
	foreach ( $raw as $key => $position ) {
		$positions[ sanitize_text_field( (string) $key ) ] = (int) $position;
	}

	/* asort keeps the key association while sorting by position, and two fields
	 * given the same number keep the order they were already in rather than
	 * swapping unpredictably. gwcpp_set_field_order() then drops anything
	 * unknown and appends anything missing. */
	asort( $positions );

	gwcpp_set_field_order( $post_type, array_keys( $positions ) );
	gwcpp_fields_redirect( $post_type, 'reordered' );
}

/**
 * Import whatever the post type has already registered.
 *
 * Reads register_meta() registrations, which is where a well-behaved plugin or
 * theme declares its meta and its types. Anything already mapped is skipped, so
 * running this twice is safe and running it after hand-editing a field does not
 * undo the edit.
 */
function gwcpp_handle_import_fields(): void {
	gwcpp_require_admin_caps();
	check_admin_referer( 'gwcpp_import_fields' );

	$post_type = gwcpp_handler_post_type();
	$found     = 0;

	foreach ( gwcpp_registered_meta( $post_type ) as $key => $spec ) {
		if ( null !== gwcpp_find_field( $post_type, $key ) ) {
			continue;
		}

		$field = gwcpp_sanitize_field(
			array(
				'key'   => $key,
				'type'  => $spec['type'],
				'label' => $spec['label'],
			)
		);

		if ( null === $field ) {
			continue;
		}

		gwcpp_put_field( $post_type, $field );
		++$found;
	}

	gwcpp_fields_redirect( $post_type, $found > 0 ? 'imported' : 'nothing' );
}

/**
 * Meta registered for a post type, mapped onto our field types.
 *
 * Protected keys — anything starting with an underscore — are skipped. They are
 * marked protected precisely because they are not for end users, and importing
 * a site's internal bookkeeping into a partner-facing form is the wrong default
 * even though it is the more impressive demo.
 *
 * @param string $post_type Post type slug.
 * @return array<string, array{type:string,label:string}>
 */
function gwcpp_registered_meta( string $post_type ): array {
	if ( ! function_exists( 'get_registered_meta_keys' ) ) {
		return array();
	}

	$map = array(
		'string'  => 'text',
		'boolean' => 'boolean',
		'integer' => 'number',
		'number'  => 'number',
	);

	$out = array();

	foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
		$key = (string) $key;

		if ( '' === $key || '_' === $key[0] ) {
			continue;
		}

		$type = (string) ( $args['type'] ?? 'string' );

		// array and object have no single sensible control, and guessing one
		// produces a field that silently mangles the value on first save.
		if ( ! isset( $map[ $type ] ) ) {
			continue;
		}

		$label = (string) ( $args['description'] ?? '' );
		if ( '' === $label ) {
			$label = ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
		}

		$out[ $key ] = array(
			'type'  => $map[ $type ],
			'label' => $label,
		);
	}

	return $out;
}
