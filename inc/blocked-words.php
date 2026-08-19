<?php
/**
 * Blocked words: refusing submissions a site does not want published.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── What this is and is not ─────────────────────────────────────────────────
 * It is a configurable list of words that stop a submission and tell the person
 * why, so a directory of family services does not publish something nobody
 * meant to send. It exists because the approval queue is not always on, and
 * because catching it at the form is kinder than catching it at review.
 *
 * It is not a content moderation system, and nothing here should be mistaken
 * for one. A determined person defeats a word list trivially; what a word list
 * actually catches is a mistake, a copy-paste, and the occasional test message
 * somebody forgot to remove.
 *
 * The list itself is empty by default and belongs to the site. Shipping one
 * would mean this plugin deciding, on behalf of every install, which words are
 * unacceptable in a language it does not know the site is written in.
 *
 * ── The rule that makes it usable ───────────────────────────────────────────
 * A field whose value has not changed is never screened.
 *
 * That sounds like a loophole and is the opposite. Without it, a site that adds
 * a word to the list can find an existing entry — one staff typed themselves,
 * years ago, containing that word legitimately — becomes unsavable: its owner
 * opens the form, changes their phone number, and is told they cannot save
 * because of a word in a field they never touched and may not be able to see a
 * problem with. The original portal hit exactly this, with an organisation
 * whose real name matched a blocked word.
 *
 * Screening what changed is the only version of this that a person can act on.
 * ───────────────────────────────────────────────────────────────────────────
 */

/**
 * The site's blocked words.
 *
 * @return string[]
 */
function gwc_pp_blocked_words(): array {
	$raw = (string) gwc_pp_setting( 'blocked_words' );

	$words = array();
	foreach ( preg_split( '/[\r\n,]+/', $raw ) as $word ) {
		$word = trim( (string) $word );

		/*
		 * Anything under three characters is dropped. A two-letter entry
		 * matches inside far more than anybody intends even with word
		 * boundaries, and the one time somebody adds "hi" to a list the whole
		 * directory stops saving.
		 */
		if ( mb_strlen( $word ) < 3 ) {
			continue;
		}

		$words[] = mb_strtolower( $word );
	}

	/**
	 * Words that stop a submission.
	 *
	 * @param string[] $words Lowercased words.
	 */
	return array_values( array_unique( (array) apply_filters( 'gwc_pp_blocked_words', $words ) ) );
}

/**
 * Whether a piece of text contains a blocked word.
 *
 * Matched on word boundaries with a short suffix group, so "scam" catches
 * "scams" and "scammer" without catching "scampi" — which unanchored
 * str_contains does, and which is the failure people notice. The suffix group
 * is deliberately small: it is there for plurals and the obvious inflections,
 * not to do stemming badly.
 *
 * @param string $text  Text to check.
 * @param array  $words Blocked words. Defaults to the site's list.
 * @return string The word that matched, or ''.
 */
function gwc_pp_blocked_word_in( string $text, ?array $words = null ): string {
	$text = trim( $text );

	if ( '' === $text ) {
		return '';
	}

	$words = null === $words ? gwc_pp_blocked_words() : $words;

	if ( ! $words ) {
		return '';
	}

	foreach ( $words as $word ) {
		if ( '' === $word ) {
			continue;
		}

		if ( preg_match( gwc_pp_blocked_word_pattern( $word ), $text ) ) {
			return $word;
		}
	}

	return '';
}

/**
 * The pattern one blocked word is matched by.
 *
 * English doubles a final consonant before -ed, -er and -ing: scam becomes
 * scamming and scammer, not scaming and scamer. A suffix group without that
 * allowance misses the two forms a word list is most often added to catch,
 * which is not obvious until somebody tests it against a real word.
 *
 * The doubled letter is only allowed when a suffix follows, so "scampi" and
 * "scamp" are still refused — the boundary after the optional group is what
 * does that work.
 *
 * @param string $word Blocked word, lowercased.
 * @return string A PCRE pattern.
 */
function gwc_pp_blocked_word_pattern( string $word ): string {
	$last = mb_substr( $word, -1 );

	// Vowels are not doubled, and neither are w, x or y.
	$double = preg_match( '/^[bcdfglmnprstz]$/i', $last )
		? preg_quote( $last, '/' ) . '?'
		: '';

	return '/\b' . preg_quote( $word, '/' ) . '(?:' . $double . '(?:s|es|ed|ing|er|ers))?\b/iu';
}

/*
 * Screening runs as part of validation, on the filter every other check goes
 * through, so a blocked word is reported beside its own field exactly like a
 * bad phone number rather than as a separate kind of failure.
 */
add_filter( 'gwc_pp_validation_errors', 'gwc_pp_screen_submission', 20, 3 );

/**
 * Add an error for any changed field containing a blocked word.
 *
 * @param array  $errors    Errors so far.
 * @param array  $values    Sanitized values.
 * @param string $post_type Post type slug.
 * @return array
 */
function gwc_pp_screen_submission( $errors, $values, $post_type ) {
	$errors = is_array( $errors ) ? $errors : array();
	$words  = gwc_pp_blocked_words();

	if ( ! $words || ! is_array( $values ) ) {
		return $errors;
	}

	$post_id = gwc_pp_screening_post_id();
	$current = $post_id > 0 ? gwc_pp_current_values( $post_id, (string) $post_type ) : array();

	foreach ( gwc_pp_type_fields( (string) $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $values ) || isset( $errors[ $key ] ) ) {
			continue;
		}

		// Unchanged from what is stored — see the note at the top of this file.
		if ( array_key_exists( $key, $current ) && $current[ $key ] === $values[ $key ] ) {
			continue;
		}

		$text = (string) gwc_pp_field_call( $field, 'to_display', array( $values[ $key ], $field ) );
		$hit  = gwc_pp_blocked_word_in( $text, $words );

		if ( '' === $hit ) {
			continue;
		}

		$errors[ $key ] = sprintf(
			/* translators: %s: the word that was found. */
			__( 'We cannot accept "%s" here. If that is genuinely part of your details, please get in touch and we will sort it out.', 'groundwork-common-post-portal' ),
			$hit
		);
	}

	return $errors;
}

/**
 * The post a submission is being screened against, or 0 for a new one.
 *
 * Read from the request rather than passed down, because the validation filter
 * is called from both the edit and create handlers and only one of them has a
 * post.
 *
 * ── Why it is checked and not merely read ────────────────────────────────────
 * This used to be read straight out of $_POST, on the reasoning that a wrong
 * value "only ever widens what is screened" — compare against somebody else's
 * values, screen a field that did not need it, never skip one that did.
 *
 * That is very nearly right, and the gap is the skip at the top of the loop: a
 * field is left unscreened when the submitted value EQUALS the stored one. Name
 * a post whose stored value happens to be the phrase you want to submit, and
 * screening for that field is skipped rather than widened. It needs a blocked
 * phrase to already exist somewhere on the site, so it is a narrow hole in a
 * feature documented as catching mistakes rather than determined people — but
 * the reasoning was wrong, and reasoning that is wrong outlives the code it was
 * written about.
 *
 * So the ID is now put through the same choke point everything else is. A post
 * the signed-in user cannot edit reads as 0, which screens every field — the
 * safe direction, and the same answer a new submission gets.
 *
 * @return int
 */
function gwc_pp_screening_post_id(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only, and the handler guards verified a nonce bound to this same ID before validation ran.
	$post_id = isset( $_POST['gwc_pp_post_id'] ) ? (int) $_POST['gwc_pp_post_id'] : 0;

	if ( $post_id <= 0 ) {
		return 0;
	}

	return gwc_pp_user_can_edit_post( get_current_user_id(), $post_id ) ? $post_id : 0;
}
