<?php
/**
 * Reading a submission in, and writing it out.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/** The form field that wraps every mapped value. */
const GWCPP_FIELD_PARAM = 'gwcpp_f';

/* ── Slashes ─────────────────────────────────────────────────────────────────
 * WordPress hands you $_POST with slashes added, and then adds them back on the
 * way out: update_post_meta() and wp_insert_post() both call wp_unslash() on
 * what you give them. So a value makes a round trip through two unslashes and
 * has to be slashed twice, or it loses a backslash each save.
 *
 * The rule this file follows, stated once so no call site has to think:
 *
 *   1. wp_unslash() the raw POST, once, in gwcpp_collect_submission().
 *   2. Everything in between — sanitize, validate, diff, store in a changeset —
 *      works on clean, unslashed values. That is what makes them comparable and
 *      what makes a test able to write a plain string.
 *   3. wp_slash() at the moment of writing, in gwcpp_save_fields(), because the
 *      WordPress function about to receive it will unslash it again.
 *
 * The alternative the original portal used was to skip step 1 and rely on
 * update_post_meta's unslash to undo the one WordPress added — which works, and
 * means every sanitizer in between is running against slashed input, and means
 * a value that never reaches update_post_meta (a validation message, a diff, a
 * changeset) carries slashes nobody expected.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Turn a raw POST into sanitized values.
 *
 * Only fields the form actually submitted come back. That distinction is what
 * makes it safe to add a field to the schema while somebody has a form open:
 * their submission simply does not mention the new field, and the save path
 * leaves it alone rather than writing an empty value over it.
 *
 * @param string $post_type Post type slug.
 * @param array  $raw       Raw $_POST[ GWCPP_FIELD_PARAM ], still slashed.
 * @return array<string, mixed> Sanitized values, keyed by field key.
 */
function gwcpp_collect_submission( string $post_type, array $raw ): array {
	$raw    = (array) wp_unslash( $raw );
	$values = array();

	foreach ( gwcpp_type_fields( $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $raw ) ) {
			continue;
		}

		$values[ $key ] = gwcpp_field_call( $field, 'sanitize', array( $raw[ $key ], $field ) );
	}

	return $values;
}

/**
 * Fields the user filled in that sanitized away to nothing.
 *
 * ── The gap this closes ──────────────────────────────────────────────────────
 * url, email and date all sanitize an unusable value to ''. That is correct —
 * storing "28/02/2026" in a field the rest of the site reads as Y-m-d would be
 * worse — but it makes an invalid submission indistinguishable from an empty
 * one by the time the validator sees it. So on an optional field, typing
 * something wrong made it silently disappear with no error and no explanation,
 * and on a required one the message was "please fill this in" for a field the
 * person had just filled in.
 *
 * Comparing against the raw input is the only way to tell those apart. The raw
 * string is returned alongside the key so the validator can run the type's own
 * check on it and reuse the message that check already has, rather than this
 * file keeping a second set of "that is not a web address" strings in step.
 *
 * @param string $post_type Post type slug.
 * @param array  $raw       Raw $_POST[ GWCPP_FIELD_PARAM ], still slashed.
 * @param array  $values    The sanitized values from gwcpp_collect_submission().
 * @return array<string, string> Field key => what the person actually typed.
 */
function gwcpp_dropped_fields( string $post_type, array $raw, array $values ): array {
	$raw     = (array) wp_unslash( $raw );
	$dropped = array();

	foreach ( gwcpp_type_fields( $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $raw ) || ! array_key_exists( $key, $values ) ) {
			continue;
		}

		// Still holds a value, so nothing was lost.
		if ( ! gwcpp_field_call( $field, 'is_empty', array( $values[ $key ], $field ) ) ) {
			continue;
		}

		$typed = gwcpp_raw_string( $raw[ $key ] );
		if ( '' !== $typed ) {
			$dropped[ $key ] = $typed;
		}
	}

	return $dropped;
}

/**
 * What the person typed, as one string, whatever shape the control submitted.
 *
 * The wrapper shapes are the ones gwcpp_render_present_marker() produces: an
 * array with a __present key, and a value that is itself a scalar or a list.
 * A checkbox group that submits only its marker really is empty, and must not
 * be reported as something that got lost.
 *
 * @param mixed $raw Raw submitted value.
 * @return string
 */
function gwcpp_raw_string( $raw ): string {
	if ( is_array( $raw ) ) {
		$raw = $raw['value'] ?? '';
	}
	if ( is_array( $raw ) ) {
		$raw = implode( ', ', array_filter( $raw, 'is_scalar' ) );
	}

	return is_scalar( $raw ) ? trim( (string) $raw ) : '';
}

/**
 * The values currently stored on a post, for prefilling a form and for diffing.
 *
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type slug.
 * @return array<string, mixed>
 */
function gwcpp_current_values( int $post_id, string $post_type ): array {
	$post   = get_post( $post_id );
	$values = array();

	foreach ( gwcpp_type_fields( $post_type ) as $field ) {
		$key  = (string) $field['key'];
		$type = (string) $field['type'];

		if ( gwcpp_is_synthetic( $key ) ) {
			$column         = (string) $field['column'];
			$values[ $key ] = $post instanceof WP_Post ? (string) $post->$column : '';
			continue;
		}

		/* Multi-value types read every row; everything else reads one. Driven
		 * off the sanitizer's return shape rather than off a list of type
		 * slugs, so a type registered through the filter gets the right
		 * treatment without this function knowing it exists. */
		if ( gwcpp_type_is_multi( $type ) ) {
			$stored         = get_post_meta( $post_id, $key, true );
			$values[ $key ] = is_array( $stored ) ? $stored : array();
			continue;
		}

		$values[ $key ] = get_post_meta( $post_id, $key, true );
	}

	return $values;
}

/**
 * True for a type whose value is a list.
 *
 * Asked of the type's own is_empty callable rather than hardcoded, because
 * gwcpp_empty_array() is what a list type uses and gwcpp_empty_scalar() is what
 * a scalar type uses. It is an indirect test and it is the only one that stays
 * correct when somebody registers a list type through the filter.
 *
 * @param string $type Type slug.
 * @return bool
 */
function gwcpp_type_is_multi( string $type ): bool {
	$def = gwcpp_field_type( $type );

	return null !== $def && 'gwcpp_empty_array' === ( $def['is_empty'] ?? '' );
}

/**
 * Write values to a post.
 *
 * ── The allow-list is structural, not a check ────────────────────────────────
 * This function cannot write a meta key that is not in the schema for this post
 * type, because it iterates the schema and reads from $values rather than
 * iterating $values. A submitted key naming something staff-only — a review
 * date, an internal note, a latitude — is not rejected here; it is never looked
 * at. There is no branch to forget and no list to keep in sync.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Sanitized, unslashed values keyed by field key.
 * @return bool True when something changed.
 */
function gwcpp_save_fields( int $post_id, array $values ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$post_update = array();
	$changed     = false;

	foreach ( gwcpp_type_fields( $post->post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}

		$value = $values[ $key ];

		if ( gwcpp_is_synthetic( $key ) ) {
			$column = (string) $field['column'];
			$new    = is_scalar( $value ) ? (string) $value : '';

			/* The title is the one field with a floor. wp_update_post() accepts
			 * an empty one, and the result is a post shown as "(no title)"
			 * everywhere on the site, done by somebody who cannot see any of
			 * those places. Validation already refuses it; this is the second
			 * line, for the paths that do not run validation — an approval
			 * replaying an old changeset, a WP-CLI call. */
			if ( 'post_title' === $column && '' === trim( $new ) ) {
				continue;
			}

			if ( (string) $post->$column !== $new ) {
				$post_update[ $column ] = $new;
				$changed                = true;
			}
			continue;
		}

		if ( gwcpp_field_call( $field, 'is_empty', array( $value, $field ) ) ) {
			if ( '' !== (string) get_post_meta( $post_id, $key, true ) || is_array( get_post_meta( $post_id, $key, true ) ) ) {
				delete_post_meta( $post_id, $key );
				$changed = true;
			}
			continue;
		}

		$existing = get_post_meta( $post_id, $key, true );
		if ( $existing !== $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
			$changed = true;
		}
	}

	if ( $post_update ) {
		$post_update['ID'] = $post_id;

		/* wp_slash because wp_insert_post() unslashes what it is given, and
		 * wp_update_post() is wp_insert_post. Without it every apostrophe in a
		 * title loses its escaping one save at a time. */
		wp_update_post( wp_slash( $post_update ) );
	}

	if ( $changed ) {
		/**
		 * Fires after portal values are written to a post.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $values  The values written.
		 */
		do_action( 'gwcpp_fields_saved', $post_id, $values );
	}

	return $changed;
}

/**
 * Create a post from a portal submission.
 *
 * @param string $post_type Post type slug.
 * @param array  $values    Sanitized values.
 * @param int    $user_id   Who is creating it.
 * @param int    $org_id    Organisation to assign it to, or 0.
 * @return int|WP_Error New post ID.
 */
function gwcpp_create_post( string $post_type, array $values, int $user_id, int $org_id = 0 ) {
	if ( ! gwcpp_type_enabled( $post_type ) || ! gwcpp_type_setting( $post_type, 'allow_create' ) ) {
		return new WP_Error( 'gwcpp_no_create', __( 'You cannot add new entries of this kind.', 'groundwork-common-post-portal' ) );
	}

	$status = (string) gwcpp_type_setting( $post_type, 'create_status' );
	if ( ! in_array( $status, array( 'draft', 'pending' ), true ) ) {
		$status = 'draft';
	}

	$title = '';
	if ( isset( $values['__title'] ) && is_scalar( $values['__title'] ) ) {
		$title = (string) $values['__title'];
	}

	$post_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_title'  => $title,
				'post_author' => $user_id,
			)
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$post_id = (int) $post_id;

	/* Both grants, deliberately. The organisation is how their colleagues will
	 * reach it; the direct grant is how the creator reaches it if they are
	 * later removed from the organisation, and how they reach it at all when
	 * they belong to no organisation. Relying on post_author instead would tie
	 * access to a setting that is off by default. */
	if ( $org_id > 0 ) {
		gwcpp_set_post_org( $post_id, $org_id );
	}
	gwcpp_add_post_editor( $user_id, $post_id );

	// __title is written above; the rest go through the ordinary path.
	gwcpp_save_fields( $post_id, $values );

	return $post_id;
}

/**
 * Move a post out of public view, reversibly.
 *
 * The strongest thing a portal user can do. Not a trash, not a delete: a status
 * change that staff can undo in one click and that leaves every field, every
 * revision and every relationship exactly where it was.
 *
 * @param int $post_id Post ID.
 * @param int $user_id Who did it.
 * @return bool
 */
function gwcpp_unpublish_post( int $post_id, int $user_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	if ( ! gwcpp_type_setting( $post->post_type, 'allow_unpublish' ) ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'draft',
		)
	);

	/* Recorded because six months later somebody will ask why a listing
	 * vanished, and "a portal user unpublished it on this date" is the whole
	 * answer. The alternative is reading the revision history, which does not
	 * record status changes. */
	update_post_meta( $post_id, '_gwcpp_unpublished_by', $user_id );
	update_post_meta( $post_id, '_gwcpp_unpublished_at', time() );

	return true;
}

/**
 * Put an unpublished post back.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_republish_post( int $post_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'draft' !== $post->post_status ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		)
	);

	delete_post_meta( $post_id, '_gwcpp_unpublished_by' );
	delete_post_meta( $post_id, '_gwcpp_unpublished_at' );

	return true;
}

/* ── Repopulating a rejected form ────────────────────────────────────────────
 * A submission that fails validation is stored briefly so the form can be
 * redrawn with what the person typed still in it, rather than blank.
 *
 * Only the mapped field keys are kept, and each is length-capped. The original
 * portal stored the entire $_POST — nonces, submit button values, anything else
 * on the page — in a transient keyed by user, which is both a larger object
 * than anyone intended and a place where a crafted extra POST field lands in
 * storage and comes back out at render time.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The longest a single repopulated value may be. */
const GWCPP_PENDING_MAX = 4000;

/**
 * Stash a rejected submission.
 *
 * @param int   $user_id User ID.
 * @param int   $post_id Post ID, or 0 for a create form.
 * @param array $values  Sanitized values.
 * @param array $errors  Field key => message.
 * @param array $dropped Field key => what the person typed, for values that
 *                       sanitized away.
 */
function gwcpp_stash_submission( int $user_id, int $post_id, array $values, array $errors, array $dropped = array() ): void {
	$kept = array();

	/* What they typed wins over what it sanitized to, but only for the fields
	 * that sanitized to nothing. Redrawing the form with a blank box beside
	 * "that does not look like a web address" asks somebody to correct
	 * something they can no longer see. These values are escaped at output like
	 * every other, and they are never written to a post — the stash exists only
	 * to redraw a form that was refused. */
	foreach ( $dropped as $key => $typed ) {
		$values[ $key ] = sanitize_text_field( (string) $typed );
	}

	foreach ( $values as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$kept[ $key ] = substr( (string) $value, 0, GWCPP_PENDING_MAX );
			continue;
		}
		if ( is_array( $value ) ) {
			// Bounded in both directions: a crafted submission should not be
			// able to store a thousand-element array under a real field key.
			$kept[ $key ] = array_slice( array_filter( $value, 'is_scalar' ), 0, 100 );
		}
	}

	set_transient(
		'gwcpp_stash_' . $user_id . '_' . $post_id,
		array(
			'values' => $kept,
			'errors' => $errors,
		),
		15 * MINUTE_IN_SECONDS
	);
}

/**
 * Read and clear a stashed submission.
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID, or 0.
 * @return array{values:array,errors:array}|null
 */
function gwcpp_take_stash( int $user_id, int $post_id ): ?array {
	$key    = 'gwcpp_stash_' . $user_id . '_' . $post_id;
	$stored = get_transient( $key );
	delete_transient( $key );

	if ( ! is_array( $stored ) || ! isset( $stored['values'] ) ) {
		return null;
	}

	return array(
		'values' => (array) $stored['values'],
		'errors' => isset( $stored['errors'] ) ? (array) $stored['errors'] : array(),
	);
}
