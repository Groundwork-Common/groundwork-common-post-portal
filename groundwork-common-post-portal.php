<?php
/**
 * Plugin Name:       Groundwork Common Post Portal
 * Plugin URI:        https://groundworkcommon.com
 * Description:       Let the people who own your content edit it from the front end, without ever handing them a wp-admin login. You choose the post types, you map the fields, they sign in with a link in their email.
 * Version:           0.3.1
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Groundwork Common LLC
 * Author URI:        https://www.groundworkcommon.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       groundwork-common-post-portal
 * Domain Path:       /languages
 * Update URI:        https://wordpress.org/plugins/groundwork-common-post-portal/
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── What this plugin refuses to know ────────────────────────────────────────
 * This grew out of a portal built for a diaper bank, where partner sites edited
 * their own listing from the front end. It worked, and it was unusable anywhere
 * else: one hardcoded post type, a field list copy-pasted across five functions
 * that each had to stay in sync by hand, a US state dropdown, a five-digit ZIP
 * regex, and a Gravity Forms ID typed into two files.
 *
 * So nothing here knows what a field means. The plugin knows how to decide who
 * may edit a post, how to render a form from a schema an admin defined, how to
 * hold a change until somebody approves it, and how to sign a person in without
 * a password. What the fields ARE is configuration.
 *
 * The one place that judgement was not worth generalising is authorization.
 * There is exactly one function that answers "may this user edit this post" —
 * gwcpp_user_can_edit_post() in inc/access.php — and every handler routes
 * through it. A registry of pluggable access strategies would be more elegant
 * and would mean the answer to that question lives in more than one place,
 * which is the one property an authorization check must never have.
 * ───────────────────────────────────────────────────────────────────────────
 */

const GWCPP_VERSION        = '0.3.1';
const GWCPP_SCHEMA_VERSION = 1;

/*
 * Where "Support this work" points. Every reference is guarded, so setting this
 * to '' removes the link and the paragraph asking for one together — a support
 * ask with nowhere to go is worse than none.
 */
const GWCPP_SPONSOR_URL = 'https://www.groundworkcommon.com/support/';

/*
 * The company site. Named once because the colophon links it from three
 * places — the wordmark, the company name in the opening line, and the
 * "See what we do" link — and two of those agreeing while the third drifts
 * is the kind of thing nobody notices for a year.
 *
 * The Author URI in the header above is the fourth, and it had in fact drifted:
 * it was the bare domain with no www and no trailing slash while these two were
 * not. Exactly the failure this comment describes, in the one place the comment
 * could not reach.
 */
const GWCPP_GWC_URL = 'https://www.groundworkcommon.com/';

define( 'GWCPP_FILE', __FILE__ );
define( 'GWCPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWCPP_URL', plugin_dir_url( __FILE__ ) );

/*
 * ── Guarded requires ────────────────────────────────────────────────────────
 * Each guard names a function the file declares. This costs one function_exists
 * per file and buys immunity to the double-load that happens when a plugin is
 * activated while an older copy is still on the include path — during the
 * activation request WordPress has already loaded the old file, and a bare
 * require then fatals on redeclaration before the admin can do anything about
 * it.
 *
 * The order is not alphabetical and is not free to change. field-types.php
 * declares the registry that schema.php validates against; access.php declares
 * the role constant users.php provisions into; everything that renders a form
 * needs both the schema and the registry already loaded.
 *
 * Nothing here is wrapped in is_admin(). It is tempting — the Fields screen and
 * the approval queue are admin-only — but is_admin() answers "is this a
 * wp-admin request", and WP-CLI, cron and the REST API are none of those. Every
 * one of these files only *registers* hooks that fire in admin contexts anyway,
 * so the saving is a few microseconds of include, and the cost is a class of bug
 * where a function exists on the screen you tested and is fatally undefined
 * under `wp eval`. That trade is not close.
 * ───────────────────────────────────────────────────────────────────────────
 */
if ( ! function_exists( 'gwcpp_status_labels' ) ) {
	require GWCPP_DIR . 'inc/i18n.php';
}
if ( ! function_exists( 'gwcpp_setting' ) ) {
	require GWCPP_DIR . 'inc/settings.php';
}
if ( ! function_exists( 'gwcpp_field_types' ) ) {
	require GWCPP_DIR . 'inc/field-types.php';
}

/*
 * The richer types, each of which registers itself onto the filter in
 * field-types.php. They must be loaded before anything CALLS that registry —
 * which is every render and every save — but the registration itself is lazy,
 * so their order among themselves does not matter.
 */
if ( ! function_exists( 'gwcpp_register_taxonomy_type' ) ) {
	require GWCPP_DIR . 'inc/field-taxonomy.php';
}
if ( ! function_exists( 'gwcpp_register_richtext_type' ) ) {
	require GWCPP_DIR . 'inc/field-richtext.php';
}
if ( ! function_exists( 'gwcpp_register_repeater_type' ) ) {
	require GWCPP_DIR . 'inc/field-repeater.php';
}
if ( ! function_exists( 'gwcpp_register_media_type' ) ) {
	require GWCPP_DIR . 'inc/field-media.php';
}
if ( ! function_exists( 'gwcpp_get_schema' ) ) {
	require GWCPP_DIR . 'inc/schema.php';
}
if ( ! function_exists( 'gwcpp_register_org_type' ) ) {
	require GWCPP_DIR . 'inc/org-cpt.php';
}
if ( ! function_exists( 'gwcpp_user_can_edit_post' ) ) {
	require GWCPP_DIR . 'inc/access.php';
}
if ( ! function_exists( 'gwcpp_grant_access' ) ) {
	require GWCPP_DIR . 'inc/users.php';
}
if ( ! function_exists( 'gwcpp_send_email' ) ) {
	require GWCPP_DIR . 'inc/emails.php';
}
if ( ! function_exists( 'gwcpp_portal_page_id' ) ) {
	require GWCPP_DIR . 'inc/auth.php';
}
if ( ! function_exists( 'gwcpp_validate_submission' ) ) {
	require GWCPP_DIR . 'inc/validate.php';
}
if ( ! function_exists( 'gwcpp_save_fields' ) ) {
	require GWCPP_DIR . 'inc/save.php';
}
if ( ! function_exists( 'gwcpp_get_changeset' ) ) {
	require GWCPP_DIR . 'inc/changeset.php';
}
if ( ! function_exists( 'gwcpp_review_state' ) ) {
	require GWCPP_DIR . 'inc/review.php';
}
if ( ! function_exists( 'gwcpp_get_handoff' ) ) {
	require GWCPP_DIR . 'inc/handoff.php';
}
if ( ! function_exists( 'gwcpp_blocked_words' ) ) {
	require GWCPP_DIR . 'inc/blocked-words.php';
}
if ( ! function_exists( 'gwcpp_render_edit_form' ) ) {
	require GWCPP_DIR . 'inc/portal-form.php';
}
if ( ! function_exists( 'gwcpp_render_post_list' ) ) {
	require GWCPP_DIR . 'inc/portal-list.php';
}
if ( ! function_exists( 'gwcpp_render_portal' ) ) {
	require GWCPP_DIR . 'inc/portal.php';
}
if ( ! function_exists( 'gwcpp_register_front_assets' ) ) {
	require GWCPP_DIR . 'inc/enqueue.php';
}
if ( ! function_exists( 'gwcpp_register_block' ) ) {
	require GWCPP_DIR . 'inc/block.php';
}
if ( ! function_exists( 'gwcpp_render_access_meta_box' ) ) {
	require GWCPP_DIR . 'inc/meta-box.php';
}
if ( ! function_exists( 'gwcpp_fields_screen' ) ) {
	require GWCPP_DIR . 'inc/admin-fields.php';

	// The tab shell, and the settings that are not reachable without it.
	require GWCPP_DIR . 'inc/admin-screen.php';

	// The approval queue, which hangs off that shell's menu.
	require GWCPP_DIR . 'inc/admin-queue.php';

	// Review state where staff look for it: the post list and the post itself.
	require GWCPP_DIR . 'inc/admin-review.php';

	/*
	 * Contextual help for the settings screens. Loaded after both because it
	 * describes what they do.
	 */
	require GWCPP_DIR . 'inc/admin-help.php';
}

/*
 * ── Activation and deactivation ─────────────────────────────────────────────
 * There is deliberately no flush_rewrite_rules() in either, and no deferred
 * flush either. There used to be both, on the reasoning that the org post type
 * is registered after the activating request's `init` has already fired, so a
 * flush during activation would write rules that did not include ours.
 *
 * That reasoning is sound and the conclusion was still wrong, because this
 * plugin adds no rewrite rules at all: the org post type is registered with
 * 'rewrite' => false and 'query_var' => false (see inc/org-cpt.php), and there
 * is no add_rewrite_rule or add_rewrite_endpoint anywhere in it. The portal is
 * an ordinary page. So the flag cost a get_option() on every single `init` and
 * the flush cost a full rule rebuild on activate and deactivate, both to
 * regenerate exactly what was already there.
 *
 * If a rewrite rule is ever added, the deferred-flag pattern is the right way
 * to flush it and this comment is the argument for bringing it back.
 *
 * The role is created on `init` rather than in the activation hook, on purpose.
 * An activation hook runs once, and a site that loses the role — a migration, a
 * security plugin that rebuilds roles, a restore from a backup taken before
 * install — would have no way to get it back short of deactivate/reactivate.
 * Creating it idempotently on every init costs one get_role() and cannot drift.
 *
 * Deactivation does not remove the portal role and does not revoke anybody's
 * access. Deactivating is not uninstalling, and a plugin that locks out every
 * partner when you toggle it off to test something is a plugin nobody dares
 * toggle.
 * ───────────────────────────────────────────────────────────────────────────
 */
register_deactivation_hook( __FILE__, 'gwcpp_deactivate' );

/**
 * Unschedule this plugin's cron events.
 *
 * ── Two things this gets right that the obvious version does not ─────────────
 * wp_clear_scheduled_hook(), not wp_next_scheduled() plus wp_unschedule_event().
 * The latter clears the soonest occurrence only, and gwcpp_review_catch_up() can
 * add a single event alongside the recurring one — so a leftover survived
 * deactivation and became exactly the permanent entry in every cron listing this
 * is here to prevent.
 *
 * And it honours $network_wide. Cron events are per site, so deactivating across
 * a network from the network admin has to visit each site; doing the current one
 * only left every other site in the network running events for a plugin that is
 * no longer active there. Bounded the same way uninstall.php is, and for the
 * same reason — the failure mode at the cap is leftover cron rows, not damage.
 *
 * gwcpp_schedule_upload_reaper() and its siblings put these back on the next
 * init if the plugin is reactivated.
 *
 * @param bool $network_wide Whether this is a network deactivation.
 */
function gwcpp_deactivate( $network_wide = false ): void {
	if ( $network_wide && is_multisite() ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 1000,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			gwcpp_clear_scheduled_events();
			restore_current_blog();
		}

		return;
	}

	gwcpp_clear_scheduled_events();
}

/**
 * Clear this plugin's cron events on the current site.
 */
function gwcpp_clear_scheduled_events(): void {
	foreach ( array( 'gwcpp_reap_orphan_uploads', 'gwcpp_daily_review', 'gwcpp_weekly_review_digest' ) as $event ) {
		wp_clear_scheduled_hook( $event );
	}
}
