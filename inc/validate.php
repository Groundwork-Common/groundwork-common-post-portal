<?php
/**
 * Validation: every problem with a submission, reported at once.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── Every error, every time ─────────────────────────────────────────────────
 * gwcpp_validate_submission() returns a map of field key to message, and it
 * always contains every problem in the submission. There is no early return in
 * it, and there must never be one.
 *
 * This is worth stating because the portal this was generalised from got it
 * wrong in a way that survived its own tests. Its validator had been written
 * to return a single {field, message} pair, was later rewritten to accumulate
 * a list, and one branch of the old contract was left behind — a bare `return`
 * of an associative array from the middle of the function. When that branch
 * fired, every other error in the submission was discarded and the error
 * summary counted the two keys of the leftover array as "2 things need fixing",
 * then printed them run together as one sentence. The test for that branch
 * filtered the result for a substring and passed either way.
 *
 * Hence the shape here: one return, at the bottom, of a map whose keys are
 * field keys. A test can assert on the exact key set, and a leftover early
 * return would fail it rather than hide in it.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * Check a submission.
 *
 * Runs on SANITIZED values, which means it is checking meaning rather than
 * shape. A value that arrived malformed has already become '' by the time it
 * gets here, so "not a valid email" is reported by the required check or by the
 * type's own validator, never by a regex duplicated from the sanitizer.
 *
 * @param string $post_type Post type slug.
 * @param array  $values    Sanitized values, keyed by field key. Only keys the
 *                          form actually submitted are present.
 * @param array  $dropped   Field key => what the person typed, for values that
 *                          sanitized away to nothing. From
 *                          gwcpp_dropped_fields().
 * @return array<string, string> Field key => message. Empty when acceptable.
 */
function gwcpp_validate_submission( string $post_type, array $values, array $dropped = array() ): array {
	$errors = array();

	foreach ( gwcpp_type_fields( $post_type ) as $field ) {
		$key = (string) $field['key'];

		/* A field the form did not submit is not validated. It is not that the
		 * value was cleared — it is that this form never showed the field, and
		 * a required field added to the schema this morning must not make every
		 * form opened yesterday unsubmittable. The save path applies the same
		 * rule from the other side and leaves such a field untouched. */
		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}

		$value = $values[ $key ];
		$empty = (bool) gwcpp_field_call( $field, 'is_empty', array( $value, $field ) );

		/* Empty because it sanitized away, not because it was left blank. This
		 * is checked before the required test on purpose: "please fill in
		 * Website" is a confusing thing to tell somebody who just filled in
		 * Website, and on an optional field there would otherwise be no message
		 * at all and their input would simply have vanished. */
		if ( $empty && isset( $dropped[ $key ] ) ) {
			$errors[ $key ] = gwcpp_dropped_message( $field, (string) $dropped[ $key ] );
			continue;
		}

		if ( ! empty( $field['required'] ) && $empty ) {
			$errors[ $key ] = gwcpp_required_message( $field );
			continue;
		}

		// An empty optional field has nothing left to be wrong about, and
		// running a type validator on '' is how "please enter a valid phone
		// number" ends up on a blank optional field.
		if ( $empty ) {
			continue;
		}

		$message = (string) gwcpp_field_call( $field, 'validate', array( $value, $field ) );
		if ( '' !== $message ) {
			$errors[ $key ] = $message;
		}
	}

	/**
	 * Problems with a submission.
	 *
	 * @param array<string,string> $errors    Field key => message.
	 * @param array                $values    Sanitized values.
	 * @param string               $post_type Post type slug.
	 */
	$errors = (array) apply_filters( 'gwcpp_validation_errors', $errors, $values, $post_type );

	return $errors;
}

/**
 * What to say about a value that sanitized away to nothing.
 *
 * Asks the field's own validator, using what the person actually typed. Every
 * type that can drop a value already owns a good message for why — "that does
 * not look like a web address", "please choose a real date" — and asking for it
 * here is the only way to avoid keeping a second copy of those strings in this
 * file, in step with the first, forever.
 *
 * The fallback covers a type whose validator happens to accept the raw string
 * even though its sanitizer refused it. That combination is a bug in the type,
 * not in the submission, so the message says the least misleading thing it can
 * rather than accusing the person of something specific.
 *
 * @param array  $field Field definition.
 * @param string $typed What the person entered.
 * @return string
 */
function gwcpp_dropped_message( array $field, string $typed ): string {
	$message = (string) gwcpp_field_call( $field, 'validate', array( $typed, $field ) );

	if ( '' !== $message ) {
		return $message;
	}

	return sprintf(
		/* translators: %s: a field label, e.g. "Opening date". */
		__( 'That is not something we can store in %s. Please check it and try again.', 'groundwork-common-post-portal' ),
		gwcpp_field_label( $field )
	);
}

/**
 * What to say about a required field that was left blank.
 *
 * Names the field rather than saying "this field is required", because the
 * summary at the top of the form is often the only part a person reads and
 * "This field is required" three times over tells them nothing about which
 * three.
 *
 * @param array $field Field definition.
 * @return string
 */
function gwcpp_required_message( array $field ): string {
	$label = gwcpp_field_label( $field );
	$type  = (string) ( $field['type'] ?? 'text' );

	if ( in_array( $type, array( 'select', 'radio', 'multiselect', 'boolean' ), true ) ) {
		return sprintf(
			/* translators: %s: a field label, e.g. "Services offered". */
			__( 'Please choose %s.', 'groundwork-common-post-portal' ),
			$label
		);
	}

	return sprintf(
		/* translators: %s: a field label, e.g. "Phone number". */
		__( 'Please fill in %s.', 'groundwork-common-post-portal' ),
		$label
	);
}

/**
 * The sentence shown above a form that could not be saved.
 *
 * Counts and then stops. Repeating every message here as well as beside its own
 * field means reading the same text twice, and the version at the top is the
 * one without the field in front of it — which is exactly the version that is
 * harder to act on.
 *
 * @param array<string, string> $errors Field key => message.
 * @return string
 */
function gwcpp_error_summary( array $errors ): string {
	$count = count( $errors );

	if ( $count < 1 ) {
		return '';
	}

	/* The placeholder is in the singular too, even though English would rather
	 * say "One thing". Plenty of languages need the number in every form, and a
	 * singular without it gives translators nowhere to put it.
	 *
	 * This note sits above the sprintf rather than beside the _n, because a
	 * translators comment has to be the LAST comment before the gettext call —
	 * anything between them and the extractor drops it. */
	return sprintf(
		/* translators: %d: how many fields have a problem. */
		_n(
			'%d thing needs fixing before this can be saved. It is marked below.',
			'%d things need fixing before this can be saved. They are marked below.',
			$count,
			'groundwork-common-post-portal'
		),
		$count
	);
}
