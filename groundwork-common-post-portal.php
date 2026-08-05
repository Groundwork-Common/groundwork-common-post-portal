<?php
/**
 * Plugin Name:       Groundwork Common Post Portal
 * Plugin URI:        https://github.com/Groundwork-Common/groundwork-common-post-portal
 * Description:       Let the people who own your content edit it from the front end, without ever handing them a wp-admin login. You choose the post types, you map the fields, they sign in with a link in their email.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Groundwork Common LLC
 * Author URI:        https://groundworkcommon.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       groundwork-common-post-portal
 * Domain Path:       /languages
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── What this plugin refuses to know ────────────────────────────────────────
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
 * ─────────────────────────────────────────────────────────────────────────── */

const GWCPP_VERSION        = '0.1.0';
const GWCPP_SCHEMA_VERSION = 1;

/*
 * Where "Support this work" points. Every reference is guarded, so setting this
 * to '' removes the link and the paragraph asking for one together — a support
 * ask with nowhere to go is worse than none.
 */
const GWCPP_SPONSOR_URL = 'https://www.groundworkcommon.com/support/';

/* The company site. Named once because the colophon links it from three
 * places — the wordmark, the company name in the opening line, and the
 * "See what we do" link — and two of those agreeing while the third drifts
 * is the kind of thing nobody notices for a year. */
const GWCPP_GWC_URL = 'https://www.groundworkcommon.com/';

define( 'GWCPP_FILE', __FILE__ );
define( 'GWCPP_DIR', plugin_dir_path( __FILE__ ) );
define( 'GWCPP_URL', plugin_dir_url( __FILE__ ) );

/* ── Guarded requires ────────────────────────────────────────────────────────
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
 * ─────────────────────────────────────────────────────────────────────────── */
if ( ! function_exists( 'gwcpp_status_labels' ) ) {
	require GWCPP_DIR . 'inc/i18n.php';
}
if ( ! function_exists( 'gwcpp_setting' ) ) {
	require GWCPP_DIR . 'inc/settings.php';
}
if ( ! function_exists( 'gwcpp_field_types' ) ) {
	require GWCPP_DIR . 'inc/field-types.php';
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

	/* Contextual help for the settings screens. Loaded after both because it
	 * describes what they do. */
	require GWCPP_DIR . 'inc/admin-help.php';
}

/* ── Activation ──────────────────────────────────────────────────────────────
 * Deliberately not flush_rewrite_rules(). On the activating request the org
 * post type has not been registered yet — `init` already fired — so a flush
 * here writes rules that do not include ours. Leave a flag, consume it on the
 * next `init` after registration.
 *
 * The role is created on `init` rather than here, on purpose. An activation
 * hook runs once, and a site that loses the role — a migration, a security
 * plugin that rebuilds roles, a restore from a backup taken before install —
 * would have no way to get it back short of deactivate/reactivate. Creating it
 * idempotently on every init costs one get_role() and cannot drift.
 *
 * Both options are explicitly non-autoloaded: each is read once, ever.
 * ─────────────────────────────────────────────────────────────────────────── */
register_activation_hook(
	__FILE__,
	static function (): void {
		update_option( 'gwcpp_needs_rewrite_flush', 1, false );
	}
);

add_action(
	'init',
	static function (): void {
		if ( get_option( 'gwcpp_needs_rewrite_flush' ) ) {
			delete_option( 'gwcpp_needs_rewrite_flush' );
			flush_rewrite_rules( false );
		}
	},
	99
);

/* Deactivation drops the rewrite rules and nothing else. It does not remove the
 * portal role, and it does not revoke anybody's access — deactivating is not
 * uninstalling, and a plugin that locks out every partner when you toggle it
 * off to test something is a plugin nobody dares toggle. */
register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules( false );
	}
);
