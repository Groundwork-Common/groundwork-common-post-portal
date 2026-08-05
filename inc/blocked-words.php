<?php
/**
 * Blocked words: refusing submissions a site does not want published.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── What this is and is not ─────────────────────────────────────────────────
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
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * The site's blocked words.
 *
 * @return string[]
 */
function gwcpp_blocked_words(): array {
	$raw = (string) gwcpp_setting( 'blocked_words' );

	$words = array();
	foreach ( preg_split( '/[\r\n,]+/', $raw ) as $word ) {
		$word = trim( (string) $word );

		/* Anything under three characters is dropped. A two-letter entry
		 * matches inside far more than anybody intends even with word
		 * boundaries, and the one time somebody adds "hi" to a list the whole
		 * directory stops saving. */
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
	return array_values( array_unique( (array) apply_filters( 'gwcpp_blocked_words', $words ) ) );
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
function gwcpp_blocked_word_in( string $text, ?array $words = null ): string {
	$text = trim( $text );

	if ( '' === $text ) {
		return '';
	}

	$words = null === $words ? gwcpp_blocked_words() : $words;

	if ( ! $words ) {
		return '';
	}

	foreach ( $words as $word ) {
		if ( '' === $word ) {
			continue;
		}

		if ( preg_match( gwcpp_blocked_word_pattern( $word ), $text ) ) {
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
function gwcpp_blocked_word_pattern( string $word ): string {
	$last = mb_substr( $word, -1 );

	// Vowels are not doubled, and neither are w, x or y.
	$double = preg_match( '/^[bcdfglmnprstz]$/i', $last )
		? preg_quote( $last, '/' ) . '?'
		: '';

	return '/\b' . preg_quote( $word, '/' ) . '(?:' . $double . '(?:s|es|ed|ing|er|ers))?\b/iu';
}

/* Screening runs as part of validation, on the filter every other check goes
 * through, so a blocked word is reported beside its own field exactly like a
 * bad phone number rather than as a separate kind of failure. */
add_filter( 'gwcpp_validation_errors', 'gwcpp_screen_submission', 20, 3 );

/**
 * Add an error for any changed field containing a blocked word.
 *
 * @param array  $errors    Errors so far.
 * @param array  $values    Sanitized values.
 * @param string $post_type Post type slug.
 * @return array
 */
function gwcpp_screen_submission( $errors, $values, $post_type ) {
	$errors = is_array( $errors ) ? $errors : array();
	$words  = gwcpp_blocked_words();

	if ( ! $words || ! is_array( $values ) ) {
		return $errors;
	}

	$post_id = gwcpp_screening_post_id();
	$current = $post_id > 0 ? gwcpp_current_values( $post_id, (string) $post_type ) : array();

	foreach ( gwcpp_type_fields( (string) $post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $values ) || isset( $errors[ $key ] ) ) {
			continue;
		}

		// Unchanged from what is stored — see the note at the top of this file.
		if ( array_key_exists( $key, $current ) && $current[ $key ] === $values[ $key ] ) {
			continue;
		}

		$text = (string) gwcpp_field_call( $field, 'to_display', array( $values[ $key ], $field ) );
		$hit  = gwcpp_blocked_word_in( $text, $words );

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
 * post. Trusting it is safe here in a way it would not be elsewhere: the value
 * only ever widens what is screened. Naming somebody else's post would compare
 * against their values and, at worst, screen a field that did not need it —
 * never skip one that did, because a mismatch always screens.
 *
 * @return int
 */
function gwcpp_screening_post_id(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only, and the guards verified the nonce before validation ran; see above for why a wrong value cannot weaken screening.
	$post_id = isset( $_POST['gwcpp_post_id'] ) ? (int) $_POST['gwcpp_post_id'] : 0;

	return max( 0, $post_id );
}
