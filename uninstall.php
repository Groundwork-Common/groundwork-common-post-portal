<?php
/**
 * Uninstall cleanup.
 *
 * ── What this never does ─────────────────────────────────────────────────────
 * It never deletes a post, it never deletes post meta, and it never deletes a
 * user — not even when the destructive option below is armed.
 *
 * The posts are the site's records, typed in by staff and by the people the
 * portal exists to serve. The users are real people with real email addresses
 * who may hold accounts for other reasons entirely; deleting a WordPress user
 * also reassigns or destroys everything they authored, which is a blast radius
 * no plugin uninstaller has any business having. Removing the plugin removes
 * the plugin.
 *
 * That includes the portal role. A role left behind is a row in one option and
 * a label in a dropdown; a role deleted out from under fifty accounts leaves
 * fifty users who can log in and are nothing, which is worse in every way than
 * the untidiness of leaving it.
 *
 * ── What it does ─────────────────────────────────────────────────────────────
 * Always: sign-in tokens and rate-limit counters. Those are short-lived
 * security state, they are worthless once the code that mints them is gone, and
 * a stale token surviving an uninstall is the one leftover here with a security
 * shape rather than a tidiness shape.
 *
 * Only when armed: the two options holding the field schema and the settings.
 * Arming is a separate option rather than a setting inside gwc_pp_settings,
 * precisely so a future migration of that array can never resurrect it — a
 * merge with defaults is exactly the kind of thing that would flip a buried
 * boolean back on, and the blast radius here is somebody's entire field
 * configuration.
 *
 *     update_option( 'gwc_pp_allow_destructive_uninstall', true );
 *
 * Even armed, every field VALUE survives, because the values live in post meta
 * and post meta is never touched. Only the description of them goes. That
 * asymmetry is on purpose: a schema can be rebuilt from the Fields screen in an
 * afternoon; two hundred partners' submitted data cannot.
 *
 * @package PostPortal
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * The arming flag is read per site, because on a network one site's decision to
 * keep its field schema is not another's to overrule.
 */
function gwc_pp_uninstall_site() {
	global $wpdb;

	/*
	 * Sign-in tokens are transients under a hashed key, so there is no way to
	 * name them individually — they have to be matched by prefix. A direct query
	 * is the only way to do that, and it runs exactly once in the life of an
	 * install.
	 *
	 * On a site with an external object cache the transients may not be in this
	 * table at all, in which case this deletes nothing and the tokens expire on
	 * their own within fifteen minutes. That is an acceptable floor: the failure
	 * mode is a token that was already going to expire expiring on schedule.
	 *
	 * This covers only the transient ones. The durable review tokens live in
	 * user meta and are swept below; handoff tokens live in post meta, which
	 * this file never touches by design, so those expire on their own within
	 * three days.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time uninstall cleanup of prefix-matched transients; no caching layer applies and there is no API for wildcard transient deletion.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_gwc_pp_tok_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_gwc_pp_tok_' ) . '%'
		)
	);

	/*
	 * The bookkeeping options. None of these holds anything a site owner would
	 * miss, and every one of them is meaningless the moment the code that reads
	 * it is gone.
	 *
	 * gwc_pp_needs_rewrite_flush is no longer written by anything — the flush it
	 * scheduled turned out to be a no-op, see the note in the main plugin file —
	 * but installs upgraded from an earlier version still have the row, so it
	 * stays on this list.
	 */
	foreach ( array(
		'gwc_pp_rate_limits',
		'gwc_pp_review_last_run',
		'gwc_pp_review_running',
		'gwc_pp_needs_rewrite_flush',
	) as $gwc_pp_option ) {
		delete_option( $gwc_pp_option );
	}

	delete_transient( 'gwc_pp_pending_count' );

	/*
	 * Per-user rows. Two are interface state — when somebody last signed in, and
	 * whether they have collapsed the colophon — and the third is not:
	 *
	 * _gwc_pp_tokens holds the durable review tokens. Those are the longest-lived
	 * credential this plugin mints: seven days, and each one signs its holder
	 * straight in when clicked. They live in user meta rather than in a transient
	 * precisely so that nothing sweeps them by accident (see the note in
	 * inc/auth.php), which means nothing sweeps them on purpose either unless it
	 * is named here — and it was not. Every unclicked reminder link in every
	 * inbox stayed live for up to a week after the plugin was gone, with nothing
	 * left installed to expire them. That is the one leftover with a security
	 * shape rather than a tidiness shape, which is exactly the kind this file
	 * says it always removes.
	 *
	 * Deleted with the site-wide helper rather than by iterating users, which on
	 * a large site would be a query per account.
	 *
	 * None of it is covered by the destructive flag: interface state is not
	 * anybody's data, and a live credential is not something to leave behind on
	 * the strength of an option nobody set.
	 */
	// Named as literals because uninstall.php runs standalone: the plugin's own
	// files are never loaded here, so its constants do not exist. Keep in step
	// with GWC_PP_LAST_LOGIN_META, GWC_PP_COLOPHON_META and GWC_PP_TOKENS_META.
	foreach ( array( 'gwc_pp_last_login', 'gwc_pp_colophon_collapsed_at', '_gwc_pp_tokens' ) as $gwc_pp_user_meta ) {
		delete_metadata( 'user', 0, $gwc_pp_user_meta, '', true );
	}

	if ( ! get_option( 'gwc_pp_allow_destructive_uninstall' ) ) {
		return;
	}

	delete_option( 'gwc_pp_schema' );
	delete_option( 'gwc_pp_settings' );
	delete_option( 'gwc_pp_allow_destructive_uninstall' );
}

/*
 * Multisite: options and transients are per site, so cleaning only the current
 * one leaves every other site in the network holding rows nothing can read.
 * Bounded by a batch — a network with thousands of sites should not have its
 * uninstall time out halfway through, leaving the job part-done with no record
 * of where it stopped. The cap is generous enough that no realistic network
 * reaches it, and the failure mode if one does is leftover rows, not damage.
 */
if ( is_multisite() ) {
	$gwc_pp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $gwc_pp_sites as $gwc_pp_site_id ) {
		switch_to_blog( $gwc_pp_site_id );
		gwc_pp_uninstall_site();
		restore_current_blog();
	}
} else {
	gwc_pp_uninstall_site();
}
