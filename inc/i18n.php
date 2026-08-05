<?php
/**
 * Translated lookup tables.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── Why these are functions and not constants ───────────────────────────────
 * Every table in this file is a function with a static memo rather than a
 * `const` array, and the reason is load order rather than taste.
 *
 * A `const` is evaluated when the file is included, which for a plugin is
 * `plugins_loaded` at the latest — before `init`, and therefore before the
 * translation for the current request has been loaded. The strings would be
 * frozen in English for the life of the request, and the bug would be invisible
 * on an English site and total on every other one.
 *
 * A function defers the __() call to the first read, which is always inside a
 * render path and always after `init`. The static memo means the tables are
 * still built once per request.
 *
 * Tables with no strings in them stay `const` — GWCPP_PORTAL_VIEWS below — for
 * exactly the same reason inverted: there is nothing to translate, so there is
 * nothing to defer.
 * ─────────────────────────────────────────────────────────────────────────── */

/** The views the portal can be in. Order is the order of the nav. */
const GWCPP_PORTAL_VIEWS = array( 'list', 'edit', 'new', 'account' );

/**
 * Post statuses as a portal user should see them named.
 *
 * Deliberately not WordPress's own labels. "Pending Review" is accurate and
 * reads as a rejection; "Waiting for review" is the same fact stated as a queue
 * position. "Draft" is jargon for anyone who has not used WordPress — what the
 * person actually needs to know is that the public cannot see it.
 *
 * @return array<string, string>
 */
function gwcpp_status_labels(): array {
	static $labels = null;
	if ( null !== $labels ) {
		return $labels;
	}

	$labels = array(
		'publish' => __( 'Published', 'groundwork-common-post-portal' ),
		'draft'   => __( 'Not published', 'groundwork-common-post-portal' ),
		'pending' => __( 'Waiting for review', 'groundwork-common-post-portal' ),
		'private' => __( 'Private', 'groundwork-common-post-portal' ),
		'future'  => __( 'Scheduled', 'groundwork-common-post-portal' ),
	);

	return $labels;
}

/**
 * One status, named for a portal user.
 *
 * Falls back to the raw slug rather than to an empty string: an unrecognised
 * status is somebody else's custom one, and showing its slug is ugly but
 * truthful, where showing nothing looks like the post has no state at all.
 *
 * @param string $status Post status slug.
 * @return string
 */
function gwcpp_status_label( string $status ): string {
	$labels = gwcpp_status_labels();
	return $labels[ $status ] ?? $status;
}

/**
 * The groups the Fields screen sorts field types into.
 *
 * @return array<string, string>
 */
function gwcpp_field_groups(): array {
	static $groups = null;
	if ( null !== $groups ) {
		return $groups;
	}

	$groups = array(
		'simple'  => __( 'Simple', 'groundwork-common-post-portal' ),
		'choice'  => __( 'Choice', 'groundwork-common-post-portal' ),
		'rich'    => __( 'Rich', 'groundwork-common-post-portal' ),
		'builtin' => __( 'Built in', 'groundwork-common-post-portal' ),
	);

	return $groups;
}
