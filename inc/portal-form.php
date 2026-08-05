<?php
/**
 * The front-end edit and create forms.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/**
 * How a field's label should be marked up.
 *
 * Three answers, because three different controls need three different things
 * and getting this wrong is the difference between a form a screen reader can
 * complete and one it cannot:
 *
 *   label  a single control, so <label for> points straight at it.
 *   legend a group of controls, so the label has to be a <legend> inside a
 *          <fieldset>. `for` cannot name a group, and a <label> wrapped round
 *          six radios announces itself once and then goes quiet.
 *   span   the control supplies its own <label> — a lone checkbox reads as
 *          "Wheelchair accessible, checkbox", and a second label above it just
 *          says everything twice.
 *
 * @param string $type Type slug.
 * @return string
 */
function gwcpp_field_label_mode( string $type ): string {
	if ( in_array( $type, array( 'radio', 'multiselect' ), true ) ) {
		return 'legend';
	}
	if ( 'boolean' === $type ) {
		return 'span';
	}
	return 'label';
}

/**
 * Render one field: label, control, help text, error.
 *
 * @param array  $field Field definition.
 * @param mixed  $value Current value.
 * @param string $error Message for this field, or ''.
 */
function gwcpp_render_field( array $field, $value, string $error = '' ): void {
	$key   = (string) $field['key'];
	$type  = (string) $field['type'];
	$name  = GWCPP_FIELD_PARAM . '[' . $key . ']';
	$id    = gwcpp_field_id( $name );
	$mode  = gwcpp_field_label_mode( $type );
	$label = gwcpp_field_label( $field );
	$help  = (string) ( $field['description'] ?? '' );

	$describedby = array();
	if ( '' !== $help ) {
		$describedby[] = $id . '-help';
	}
	if ( '' !== $error ) {
		$describedby[] = $id . '-error';
	}

	$ctx = array(
		'describedby' => implode( ' ', $describedby ),
		'invalid'     => '' !== $error,
	);

	printf(
		'<div class="gwcpp-field gwcpp-field--%1$s%2$s">',
		esc_attr( $type ),
		'' !== $error ? ' gwcpp-field--invalid' : ''
	);

	if ( 'legend' === $mode ) {
		echo '<fieldset class="gwcpp-fieldset"><legend class="gwcpp-label">';
		echo esc_html( $label );
		gwcpp_render_required_mark( $field );
		echo '</legend>';
	} elseif ( 'label' === $mode ) {
		printf( '<label class="gwcpp-label" for="%s">', esc_attr( $id ) );
		echo esc_html( $label );
		gwcpp_render_required_mark( $field );
		echo '</label>';
	} else {
		echo '<span class="gwcpp-label">';
		echo esc_html( $label );
		gwcpp_render_required_mark( $field );
		echo '</span>';
	}

	/* The help text is printed BEFORE the control rather than after it. Placed
	 * after, it is read by a screen reader only once the control is already
	 * focused and the user is committed to answering, and it is the part of the
	 * field a sighted user skips because it sits where an error message goes.
	 * Before, it is instructions. */
	if ( '' !== $help ) {
		printf(
			'<p class="gwcpp-field__help" id="%s">%s</p>',
			esc_attr( $id . '-help' ),
			esc_html( $help )
		);
	}

	echo '<div class="gwcpp-field__control">';
	gwcpp_field_call( $field, 'render_portal', array( $field, $value, $name, $ctx ) );
	echo '</div>';

	if ( '' !== $error ) {
		printf(
			'<p class="gwcpp-field__error" id="%s" role="alert">%s</p>',
			esc_attr( $id . '-error' ),
			esc_html( $error )
		);
	}

	if ( 'legend' === $mode ) {
		echo '</fieldset>';
	}

	echo '</div>';
}

/**
 * The marker beside a required field's label.
 *
 * An asterisk alone is a convention sighted users know and a screen reader
 * reads as "star" or skips entirely, so the word is there too and hidden
 * visually.
 *
 * @param array $field Field definition.
 */
function gwcpp_render_required_mark( array $field ): void {
	if ( empty( $field['required'] ) ) {
		return;
	}

	printf(
		' <span class="gwcpp-required" aria-hidden="true">*</span><span class="screen-reader-text">%s</span>',
		esc_html__( '(required)', 'groundwork-common-post-portal' )
	);
}

/**
 * The edit form for one post.
 *
 * @param WP_Post $post   The post.
 * @param array   $values Current or resubmitted values.
 * @param array   $errors Field key => message.
 */
function gwcpp_render_edit_form( WP_Post $post, array $values, array $errors = array() ): void {
	$fields = gwcpp_type_fields( $post->post_type );

	if ( ! $fields ) {
		printf(
			'<p class="gwcpp-empty">%s</p>',
			esc_html__( 'Nothing has been set up for you to edit here yet.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$summary = gwcpp_error_summary( $errors );

	/* enctype unconditionally, rather than only when a media field is mapped.
	 * A form missing it silently sends filenames instead of files — no error,
	 * no warning, just an upload that never happens — and the condition that
	 * would get it wrong is "somebody added a media field after this template
	 * was written". */
	echo '<form class="gwcpp-form" method="post" enctype="multipart/form-data" action="' . esc_url( gwcpp_portal_url() ) . '">';

	if ( '' !== $summary ) {
		printf(
			'<div class="gwcpp-notice gwcpp-notice--error" role="alert" tabindex="-1" id="gwcpp-errors"><p>%s</p></div>',
			esc_html( $summary )
		);
	}

	wp_nonce_field( 'gwcpp_save_' . $post->ID, 'gwcpp_save_nonce' );
	printf( '<input type="hidden" name="gwcpp_post_id" value="%d" />', (int) $post->ID );

	foreach ( $fields as $field ) {
		$key = (string) $field['key'];
		gwcpp_render_field(
			$field,
			$values[ $key ] ?? null,
			isset( $errors[ $key ] ) ? (string) $errors[ $key ] : ''
		);
	}

	printf(
		'<div class="gwcpp-actions"><button type="submit" name="gwcpp_save" value="1" class="gwcpp-button gwcpp-button--primary">%s</button> <a class="gwcpp-button gwcpp-button--quiet" href="%s">%s</a></div>',
		esc_html( gwcpp_save_button_label( $post->post_type ) ),
		esc_url( gwcpp_portal_url() ),
		esc_html__( 'Cancel', 'groundwork-common-post-portal' )
	);

	echo '</form>';

	gwcpp_render_unpublish_form( $post );
}

/**
 * What the save button says.
 *
 * Different words when approval is on, because "Save" promises something that
 * will not happen — the change is not saved to the live post, it is submitted.
 * Somebody who reads "Save", sees the public page unchanged, and saves again is
 * behaving reasonably in response to a button that lied.
 *
 * @param string $post_type Post type slug.
 * @return string
 */
function gwcpp_save_button_label( string $post_type ): string {
	return gwcpp_type_setting( $post_type, 'require_approval' )
		? __( 'Submit changes for review', 'groundwork-common-post-portal' )
		: __( 'Save changes', 'groundwork-common-post-portal' );
}

/**
 * The unpublish control, when the post type allows it and the post is live.
 *
 * Its own form rather than a second button in the main one, so that submitting
 * the edit form can never be routed to it — two submit buttons in one form are
 * one stray Enter keypress away from the wrong handler.
 *
 * @param WP_Post $post The post.
 */
function gwcpp_render_unpublish_form( WP_Post $post ): void {
	if ( ! gwcpp_type_setting( $post->post_type, 'allow_unpublish' ) ) {
		return;
	}

	if ( 'publish' === $post->post_status ) {
		echo '<form class="gwcpp-form gwcpp-form--danger" method="post" action="' . esc_url( gwcpp_portal_url() ) . '">';
		wp_nonce_field( 'gwcpp_unpublish_' . $post->ID, 'gwcpp_unpublish_nonce' );
		printf( '<input type="hidden" name="gwcpp_post_id" value="%d" />', (int) $post->ID );
		printf(
			'<h2 class="gwcpp-danger__title">%s</h2><p>%s</p><button type="submit" name="gwcpp_unpublish" value="1" class="gwcpp-button gwcpp-button--danger">%s</button>',
			esc_html__( 'Take this off the site', 'groundwork-common-post-portal' ),
			esc_html__( 'This hides it from the public. Nothing is deleted, and it can be put back.', 'groundwork-common-post-portal' ),
			esc_html__( 'Take it off the site', 'groundwork-common-post-portal' )
		);
		echo '</form>';
		return;
	}

	if ( 'draft' === $post->post_status && get_post_meta( $post->ID, '_gwcpp_unpublished_by', true ) ) {
		echo '<form class="gwcpp-form" method="post" action="' . esc_url( gwcpp_portal_url() ) . '">';
		wp_nonce_field( 'gwcpp_republish_' . $post->ID, 'gwcpp_republish_nonce' );
		printf( '<input type="hidden" name="gwcpp_post_id" value="%d" />', (int) $post->ID );
		printf(
			'<p>%s</p><button type="submit" name="gwcpp_republish" value="1" class="gwcpp-button">%s</button>',
			esc_html__( 'This is currently hidden from the public.', 'groundwork-common-post-portal' ),
			esc_html__( 'Put it back on the site', 'groundwork-common-post-portal' )
		);
		echo '</form>';
	}
}

/**
 * The create form for a post type.
 *
 * @param string $post_type Post type slug.
 * @param array  $values    Resubmitted values.
 * @param array  $errors    Field key => message.
 */
function gwcpp_render_create_form( string $post_type, array $values = array(), array $errors = array() ): void {
	$fields = gwcpp_type_fields( $post_type );
	$object = get_post_type_object( $post_type );

	if ( ! $fields || ! $object ) {
		printf(
			'<p class="gwcpp-empty">%s</p>',
			esc_html__( 'Nothing has been set up for you to add here yet.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$summary = gwcpp_error_summary( $errors );

	echo '<form class="gwcpp-form" method="post" enctype="multipart/form-data" action="' . esc_url( gwcpp_portal_url() ) . '">';

	if ( '' !== $summary ) {
		printf(
			'<div class="gwcpp-notice gwcpp-notice--error" role="alert" tabindex="-1" id="gwcpp-errors"><p>%s</p></div>',
			esc_html( $summary )
		);
	}

	wp_nonce_field( 'gwcpp_create_' . $post_type, 'gwcpp_create_nonce' );
	printf( '<input type="hidden" name="gwcpp_post_type" value="%s" />', esc_attr( $post_type ) );

	foreach ( $fields as $field ) {
		$key = (string) $field['key'];
		gwcpp_render_field(
			$field,
			$values[ $key ] ?? null,
			isset( $errors[ $key ] ) ? (string) $errors[ $key ] : ''
		);
	}

	printf(
		'<div class="gwcpp-actions"><button type="submit" name="gwcpp_create" value="1" class="gwcpp-button gwcpp-button--primary">%s</button> <a class="gwcpp-button gwcpp-button--quiet" href="%s">%s</a></div>',
		esc_html__( 'Add it', 'groundwork-common-post-portal' ),
		esc_url( gwcpp_portal_url() ),
		esc_html__( 'Cancel', 'groundwork-common-post-portal' )
	);

	echo '</form>';
}
