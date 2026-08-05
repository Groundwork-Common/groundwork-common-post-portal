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
 * Arming is a separate option rather than a setting inside gwcpp_settings,
 * precisely so a future migration of that array can never resurrect it — a
 * merge with defaults is exactly the kind of thing that would flip a buried
 * boolean back on, and the blast radius here is somebody's entire field
 * configuration.
 *
 *     update_option( 'gwcpp_allow_destructive_uninstall', true );
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
function gwcpp_uninstall_site() {
	global $wpdb;

	/* Sign-in and handoff tokens are transients under a hashed key, so there is
	 * no way to name them individually — they have to be matched by prefix. A
	 * direct query is the only way to do that, and it runs exactly once in the
	 * life of an install.
	 *
	 * On a site with an external object cache the transients may not be in this
	 * table at all, in which case this deletes nothing and the tokens expire on
	 * their own within fifteen minutes. That is an acceptable floor: the failure
	 * mode is a token that was already going to expire expiring on schedule. */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time uninstall cleanup of prefix-matched transients; no caching layer applies and there is no API for wildcard transient deletion.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_gwcpp_tok_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_gwcpp_tok_' ) . '%'
		)
	);

	delete_option( 'gwcpp_rate_limits' );
	delete_option( 'gwcpp_needs_rewrite_flush' );

	if ( ! get_option( 'gwcpp_allow_destructive_uninstall' ) ) {
		return;
	}

	delete_option( 'gwcpp_schema' );
	delete_option( 'gwcpp_settings' );
	delete_option( 'gwcpp_allow_destructive_uninstall' );
}

/* Multisite: options and transients are per site, so cleaning only the current
 * one leaves every other site in the network holding rows nothing can read.
 * Bounded by a batch — a network with thousands of sites should not have its
 * uninstall time out halfway through, leaving the job part-done with no record
 * of where it stopped. The cap is generous enough that no realistic network
 * reaches it, and the failure mode if one does is leftover rows, not damage. */
if ( is_multisite() ) {
	$gwcpp_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	);

	foreach ( $gwcpp_sites as $gwcpp_site_id ) {
		switch_to_blog( $gwcpp_site_id );
		gwcpp_uninstall_site();
		restore_current_blog();
	}
} else {
	gwcpp_uninstall_site();
}
