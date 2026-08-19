<?php
/**
 * Reading a submission in, and writing it out.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/** The form field that wraps every mapped value. */
const GWC_PP_FIELD_PARAM = 'gwc_pp_f';

/*
 * ── Slashes ─────────────────────────────────────────────────────────────────
 * WordPress hands you $_POST with slashes added, and then adds them back on the
 * way out: update_post_meta() and wp_insert_post() both call wp_unslash() on
 * what you give them. So a value makes a round trip through two unslashes and
 * has to be slashed twice, or it loses a backslash each save.
 *
 * The rule this file follows, stated once so no call site has to think:
 *
 *   1. wp_unslash() the raw POST, once, in gwc_pp_collect_submission().
 *   2. Everything in between — sanitize, validate, diff, store in a changeset —
 *      works on clean, unslashed values. That is what makes them comparable and
 *      what makes a test able to write a plain string.
 *   3. wp_slash() at the moment of writing, in gwc_pp_save_fields(), because the
 *      WordPress function about to receive it will unslash it again.
 *
 * The alternative the original portal used was to skip step 1 and rely on
 * update_post_meta's unslash to undo the one WordPress added — which works, and
 * means every sanitizer in between is running against slashed input, and means
 * a value that never reaches update_post_meta (a validation message, a diff, a
 * changeset) carries slashes nobody expected.
 * ───────────────────────────────────────────────────────────────────────────
 */

/**
 * Turn a raw POST into sanitized values.
 *
 * Only fields the form actually submitted come back. That distinction is what
 * makes it safe to add a field to the schema while somebody has a form open:
 * their submission simply does not mention the new field, and the save path
 * leaves it alone rather than writing an empty value over it.
 *
 * @param string $post_type Post type slug.
 * @param array  $raw       Raw $_POST[ GWC_PP_FIELD_PARAM ], still slashed.
 * @return array<string, mixed> Sanitized values, keyed by field key.
 */
function gwc_pp_collect_submission( string $post_type, array $raw ): array {
	$raw    = (array) wp_unslash( $raw );
	$values = array();

	foreach ( gwc_pp_type_fields( $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $raw ) ) {
			continue;
		}

		$values[ $key ] = gwc_pp_field_call( $field, 'sanitize', array( $raw[ $key ], $field ) );
	}

	return $values;
}

/**
 * Run any uploads a submission carried, and fold the results into its values.
 *
 * ── Why uploads are a separate pass ──────────────────────────────────────────
 * Every other field's value arrives in $_POST and is handled by the type's
 * sanitize callable. A file does not: it arrives in $_FILES, and turning it
 * into a value means writing to disk and creating an attachment — a side effect
 * a sanitizer must never have, because sanitizers get called from diffs, from
 * previews, and from tests.
 *
 * So types that take files declare an optional `upload` callable, run exactly
 * once per submission from here. `upload` is not part of the required contract
 * in GWC_PP_TYPE_CONTRACT; a type without it is simply skipped.
 *
 * Called once, and once only. An earlier draft of the save handler called the
 * collector in three branches, which would have uploaded the same file three
 * times and left two orphans behind on every save.
 *
 * ── And why reconciling belongs here too ─────────────────────────────────────
 * A type may also declare an optional `reconcile` callable, run from the same
 * loop. It exists for the same reason `upload` does: a sanitizer is handed a
 * value and a field definition and nothing else, so a check that depends on
 * which post is being edited cannot live there. gwc_pp_reconcile_media() is the
 * one implementation — see the note on it for what a hidden "keep the current
 * file" input can otherwise be talked into naming.
 *
 * @param string $post_type Post type slug.
 * @param array  $values    Sanitized values so far.
 * @param int    $user_id   Who is uploading.
 * @param int    $post_id   Post being edited, or 0 when creating.
 * @return array{values:array, uploaded:int[], errors:array<string,string>}
 */
function gwc_pp_apply_uploads( string $post_type, array $values, int $user_id, int $post_id = 0 ): array {
	$uploaded = array();
	$errors   = array();

	foreach ( gwc_pp_type_fields( $post_type ) as $field ) {
		$def = gwc_pp_field_type( (string) $field['type'] );
		$key = (string) $field['key'];

		if ( null === $def ) {
			continue;
		}

		$fresh = null;

		if ( ! empty( $def['upload'] ) && is_callable( $def['upload'] ) ) {
			$result = call_user_func( $def['upload'], $field, $user_id );

			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
				continue;
			}

			// null means "no file was sent for this field", which is not an
			// error — it is what every save that does not touch the photo looks
			// like. The reconciler below still runs, because that save is
			// exactly the one carrying a `keep` value forward.
			if ( null !== $result ) {
				$fresh          = (int) $result;
				$values[ $key ] = $fresh;
				$uploaded[]     = $fresh;
			}
		}

		if ( array_key_exists( $key, $values ) && ! empty( $def['reconcile'] ) && is_callable( $def['reconcile'] ) ) {
			$values[ $key ] = call_user_func( $def['reconcile'], $values[ $key ], $field, $post_id, $fresh );
		}
	}

	return array(
		'values'   => $values,
		'uploaded' => $uploaded,
		'errors'   => $errors,
	);
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
 * @param array  $raw       Raw $_POST[ GWC_PP_FIELD_PARAM ], still slashed.
 * @param array  $values    The sanitized values from gwc_pp_collect_submission().
 * @return array<string, string> Field key => what the person actually typed.
 */
function gwc_pp_dropped_fields( string $post_type, array $raw, array $values ): array {
	$raw     = (array) wp_unslash( $raw );
	$dropped = array();

	foreach ( gwc_pp_type_fields( $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $raw ) || ! array_key_exists( $key, $values ) ) {
			continue;
		}

		// Still holds a value, so nothing was lost.
		if ( ! gwc_pp_field_call( $field, 'is_empty', array( $values[ $key ], $field ) ) ) {
			continue;
		}

		$typed = gwc_pp_raw_string( $raw[ $key ] );
		if ( '' !== $typed ) {
			$dropped[ $key ] = $typed;
		}
	}

	return $dropped;
}

/**
 * What the person typed, as one string, whatever shape the control submitted.
 *
 * The wrapper shapes are the ones gwc_pp_render_present_marker() produces: an
 * array with a __present key, and a value that is itself a scalar or a list.
 * A checkbox group that submits only its marker really is empty, and must not
 * be reported as something that got lost.
 *
 * @param mixed $raw Raw submitted value.
 * @return string
 */
function gwc_pp_raw_string( $raw ): string {
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
function gwc_pp_current_values( int $post_id, string $post_type ): array {
	$post   = get_post( $post_id );
	$values = array();

	foreach ( gwc_pp_type_fields( $post_type ) as $field ) {
		$key  = (string) $field['key'];
		$type = (string) $field['type'];

		if ( gwc_pp_is_synthetic( $key ) ) {
			$column         = (string) $field['column'];
			$values[ $key ] = $post instanceof WP_Post ? (string) $post->$column : '';
			continue;
		}

		/*
		 * Terms, not meta. Read from the taxonomy every time rather than from a
		 * cached copy, so a term renamed or deleted elsewhere on the site is
		 * reflected here without this plugin having to hear about it.
		 */
		if ( gwc_pp_type_is_taxonomy( $type ) ) {
			$terms          = wp_get_object_terms( $post_id, $key, array( 'fields' => 'ids' ) );
			$values[ $key ] = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
			sort( $values[ $key ] );
			continue;
		}

		/*
		 * Multi-value types read every row; everything else reads one. Driven
		 * off the sanitizer's return shape rather than off a list of type
		 * slugs, so a type registered through the filter gets the right
		 * treatment without this function knowing it exists.
		 */
		if ( gwc_pp_type_is_multi( $type ) ) {
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
 * gwc_pp_empty_array() is what a list type uses and gwc_pp_empty_scalar() is what
 * a scalar type uses. It is an indirect test and it is the only one that stays
 * correct when somebody registers a list type through the filter.
 *
 * @param string $type Type slug.
 * @return bool
 */
function gwc_pp_type_is_multi( string $type ): bool {
	$def = gwc_pp_field_type( $type );

	return null !== $def && 'gwc_pp_empty_array' === ( $def['is_empty'] ?? '' );
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
function gwc_pp_save_fields( int $post_id, array $values ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$post_update = array();
	$changed     = false;

	foreach ( gwc_pp_type_fields( $post->post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}

		$value = $values[ $key ];

		if ( gwc_pp_is_synthetic( $key ) ) {
			$column = (string) $field['column'];
			$new    = is_scalar( $value ) ? (string) $value : '';

			/*
			 * The title is the one field with a floor. wp_update_post() accepts
			 * an empty one, and the result is a post shown as "(no title)"
			 * everywhere on the site, done by somebody who cannot see any of
			 * those places. Validation already refuses it; this is the second
			 * line, for the paths that do not run validation — an approval
			 * replaying an old changeset, a WP-CLI call.
			 */
			if ( 'post_title' === $column && '' === trim( $new ) ) {
				continue;
			}

			if ( (string) $post->$column !== $new ) {
				$post_update[ $column ] = $new;
				$changed                = true;
			}
			continue;
		}

		if ( gwc_pp_type_is_taxonomy( (string) $field['type'] ) ) {
			$term_ids = is_array( $value ) ? array_map( 'intval', $value ) : array();
			$existing = wp_get_object_terms( $post_id, $key, array( 'fields' => 'ids' ) );
			$existing = is_array( $existing ) ? array_map( 'intval', $existing ) : array();

			sort( $term_ids );
			sort( $existing );

			if ( $term_ids !== $existing ) {
				/*
				 * append => false, so clearing every box really clears them.
				 * The *_present marker is what makes that safe: a form that
				 * never showed this field does not submit the key at all and is
				 * skipped above, so an empty array here always means somebody
				 * actually unticked everything.
				 */
				wp_set_object_terms( $post_id, $term_ids, $key, false );
				$changed = true;
			}
			continue;
		}

		if ( gwc_pp_field_call( $field, 'is_empty', array( $value, $field ) ) ) {
			$stored = get_post_meta( $post_id, $key, true );

			/*
			 * The array test comes FIRST, and the order is the whole point. A
			 * multi-value field — multiselect, checkbox, repeater, a taxonomy
			 * type stored as meta — holds an array, and `(string) $array` is a
			 * PHP warning rather than a comparison. Written the other way round
			 * the cast ran before anything could stop it, so every save that
			 * cleared one of those fields emitted "Array to string conversion"
			 * into the log, or into the middle of the page on a host with
			 * display_errors on. The is_array() call was already here; it was
			 * simply on the wrong side of the ||, where short-circuiting can
			 * never reach it.
			 */
			if ( is_array( $stored ) || '' !== (string) $stored ) {
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

		/*
		 * wp_slash because wp_insert_post() unslashes what it is given, and
		 * wp_update_post() is wp_insert_post. Without it every apostrophe in a
		 * title loses its escaping one save at a time.
		 */
		wp_update_post( wp_slash( $post_update ) );
	}

	if ( $changed ) {
		/**
		 * Fires after portal values are written to a post.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $values  The values written.
		 */
		do_action( 'gwc_pp_fields_saved', $post_id, $values );
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
function gwc_pp_create_post( string $post_type, array $values, int $user_id, int $org_id = 0 ) {
	if ( ! gwc_pp_type_enabled( $post_type ) || ! gwc_pp_type_setting( $post_type, 'allow_create' ) ) {
		return new WP_Error( 'gwc_pp_no_create', __( 'You cannot add new entries of this kind.', 'groundwork-common-post-portal' ) );
	}

	$status = (string) gwc_pp_type_setting( $post_type, 'create_status' );
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

	/*
	 * Both grants, deliberately. The organisation is how their colleagues will
	 * reach it; the direct grant is how the creator reaches it if they are
	 * later removed from the organisation, and how they reach it at all when
	 * they belong to no organisation. Relying on post_author instead would tie
	 * access to a setting that is off by default.
	 */
	if ( $org_id > 0 ) {
		gwc_pp_set_post_org( $post_id, $org_id );
	}
	gwc_pp_add_post_editor( $user_id, $post_id );

	// __title is written above; the rest go through the ordinary path.
	gwc_pp_save_fields( $post_id, $values );

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
function gwc_pp_unpublish_post( int $post_id, int $user_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	if ( ! gwc_pp_type_setting( $post->post_type, 'allow_unpublish' ) ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'draft',
		)
	);

	/*
	 * Recorded because six months later somebody will ask why a listing
	 * vanished, and "a portal user unpublished it on this date" is the whole
	 * answer. The alternative is reading the revision history, which does not
	 * record status changes.
	 */
	update_post_meta( $post_id, '_gwc_pp_unpublished_by', $user_id );
	update_post_meta( $post_id, '_gwc_pp_unpublished_at', time() );

	return true;
}

/**
 * Put an unpublished post back.
 *
 * Re-checks the same two things the renderer checks before it shows the button,
 * because a handler that trusts the renderer is a handler that can be replayed.
 * A nonce minted while the control was on screen stays valid for up to a day, so
 * "staff turned allow_unpublish off an hour ago" and "staff cleared the marker
 * by republishing it themselves" are both states an old form can be submitted
 * into. Neither should put the post back.
 *
 * The marker check is the load-bearing one: without it this republishes any
 * draft the user can reach, including one staff had deliberately left unpublished
 * and one that was never published in the first place.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwc_pp_republish_post( int $post_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || 'draft' !== $post->post_status ) {
		return false;
	}

	if ( ! gwc_pp_type_setting( $post->post_type, 'allow_unpublish' ) ) {
		return false;
	}

	// Only a post a portal user took down may be put back by one.
	if ( ! get_post_meta( $post_id, '_gwc_pp_unpublished_by', true ) ) {
		return false;
	}

	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => 'publish',
		)
	);

	delete_post_meta( $post_id, '_gwc_pp_unpublished_by' );
	delete_post_meta( $post_id, '_gwc_pp_unpublished_at' );

	return true;
}

/*
 * ── Repopulating a rejected form ────────────────────────────────────────────
 * A submission that fails validation is stored briefly so the form can be
 * redrawn with what the person typed still in it, rather than blank.
 *
 * Only the mapped field keys are kept, and each is length-capped. The original
 * portal stored the entire $_POST — nonces, submit button values, anything else
 * on the page — in a transient keyed by user, which is both a larger object
 * than anyone intended and a place where a crafted extra POST field lands in
 * storage and comes back out at render time.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** The longest a single repopulated value may be. */
const GWC_PP_PENDING_MAX = 4000;

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
function gwc_pp_stash_submission( int $user_id, int $post_id, array $values, array $errors, array $dropped = array() ): void {
	$kept = array();

	/*
	 * What they typed wins over what it sanitized to, but only for the fields
	 * that sanitized to nothing. Redrawing the form with a blank box beside
	 * "that does not look like a web address" asks somebody to correct
	 * something they can no longer see. These values are escaped at output like
	 * every other, and they are never written to a post — the stash exists only
	 * to redraw a form that was refused.
	 */
	foreach ( $dropped as $key => $typed ) {
		$values[ $key ] = sanitize_text_field( (string) $typed );
	}

	foreach ( $values as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$kept[ $key ] = substr( (string) $value, 0, GWC_PP_PENDING_MAX );
			continue;
		}
		if ( is_array( $value ) ) {
			// Bounded in both directions: a crafted submission should not be
			// able to store a thousand-element array under a real field key.
			$kept[ $key ] = array_slice( array_filter( $value, 'is_scalar' ), 0, 100 );
		}
	}

	set_transient(
		'gwc_pp_stash_' . $user_id . '_' . $post_id,
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
function gwc_pp_take_stash( int $user_id, int $post_id ): ?array {
	$key    = 'gwc_pp_stash_' . $user_id . '_' . $post_id;
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
