<?php
/**
 * Integration checks for the access model.
 *
 * Run under wp-env, where there is a real database and a real WP_Query:
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/groundwork-common-post-portal/tests/integration/access.php
 *
 * The unit tests cover gwcpp_user_can_edit_post() exhaustively against stubs.
 * What they cannot cover is the part that is three real queries — the list
 * built by gwcpp_editable_post_ids() — because a stubbed get_posts() returns
 * whatever the stub says and proves nothing about a meta_query. That is what
 * this file is for.
 *
 * It creates its own fixtures and deletes them at the end, including on
 * failure, so it can be run repeatedly against the same install.
 *
 * @package PostPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/* The counters live in $GLOBALS explicitly, not as file-scope variables.
 * `wp eval-file` evaluates this file inside a function, so a top-level
 * assignment here is a LOCAL — and vok()'s `global` would then increment a
 * different variable entirely, leaving the summary reporting zero of both while
 * every individual line printed correctly. A failing run would have looked
 * exactly like a passing one.
 */
$GLOBALS['gwcpp_pass'] = 0;
$GLOBALS['gwcpp_fail'] = 0;

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it held.
 * @param string $detail Extra context, shown on failure.
 */
function vok( string $label, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['gwcpp_pass'];
		echo "PASS  {$label}\n";
		return;
	}

	++$GLOBALS['gwcpp_fail'];
	echo "FAIL  {$label}";
	echo '' !== $detail ? "  — {$detail}\n" : "\n";
}

/* Under WP-CLI the output above has already been sent, so wp_set_auth_cookie()
 * would emit a "headers already sent" warning for every cookie it sets. The
 * cookie is not what is under test — the token exchange is — so the send is
 * switched off rather than the warnings tolerated, because a run whose real
 * failures are buried in six lines of expected warnings is a run nobody reads.
 */
add_filter( 'send_auth_cookies', '__return_false' );

/* ── Fixtures ────────────────────────────────────────────────────────────── */

$created_posts = array();
$created_users = array();

/* A post type registered right here, so the script does not depend on the
 * install having any particular one. It is registered late, which is fine:
 * nothing in this script goes through a rewrite rule.
 */
register_post_type(
	'gwcpp_itest',
	array(
		'public'   => false,
		'show_ui'  => true,
		'label'    => 'Integration Test Type',
		'supports' => array( 'title', 'author' ),
	)
);

$settings = get_option( 'gwcpp_settings' );
$settings = is_array( $settings ) ? $settings : array();

$original_settings = $settings;

$settings['post_types'] = array_values( array_unique( array_merge( (array) ( $settings['post_types'] ?? array() ), array( 'gwcpp_itest' ) ) ) );
$settings['types']['gwcpp_itest'] = array(
	'author_grant'     => false,
	'allow_create'     => true,
	'require_approval' => true,
	'allow_unpublish'  => true,
	'create_status'    => 'draft',
);
update_option( 'gwcpp_settings', $settings );
gwcpp_settings_cache( null, true );

// Two organisations.
$org_a = wp_insert_post(
	array(
		'post_type'   => GWCPP_ORG_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Integration Org A',
	)
);
$org_b = wp_insert_post(
	array(
		'post_type'   => GWCPP_ORG_TYPE,
		'post_status' => 'publish',
		'post_title'  => 'Integration Org B',
	)
);
$created_posts[] = $org_a;
$created_posts[] = $org_b;

// Three posts: one for each organisation, one for nobody.
$post_a = wp_insert_post(
	array(
		'post_type'   => 'gwcpp_itest',
		'post_status' => 'publish',
		'post_title'  => 'Belongs to A',
	)
);
$post_b = wp_insert_post(
	array(
		'post_type'   => 'gwcpp_itest',
		'post_status' => 'publish',
		'post_title'  => 'Belongs to B',
	)
);
$post_free = wp_insert_post(
	array(
		'post_type'   => 'gwcpp_itest',
		'post_status' => 'publish',
		'post_title'  => 'Belongs to nobody',
	)
);
$created_posts = array_merge( $created_posts, array( $post_a, $post_b, $post_free ) );

gwcpp_set_post_org( $post_a, $org_a );
gwcpp_set_post_org( $post_b, $org_b );

/* ── The role ────────────────────────────────────────────────────────────── */

gwcpp_ensure_role();
$role = get_role( GWCPP_ROLE );

vok( 'the portal role exists', $role instanceof WP_Role );
vok( 'the role can read', $role && $role->has_cap( 'read' ) );
vok(
	'the role holds no real editing capability',
	$role && ! $role->has_cap( 'edit_posts' ) && ! $role->has_cap( 'edit_others_posts' ),
	'Granting edit_posts to reach one listing would grant it for every post on the site.'
);

/* ── Provisioning ────────────────────────────────────────────────────────── */

$email_a = 'gwcpp-itest-a@example.test';
$email_b = 'gwcpp-itest-b@example.test';

// Clean up anything a previous run left behind.
foreach ( array( $email_a, $email_b ) as $email ) {
	$stale = get_user_by( 'email', $email );
	if ( $stale ) {
		wp_delete_user( $stale->ID );
	}
}

$user_a = gwcpp_grant_access( $org_a, $email_a );
vok( 'inviting an address provisions a user', is_int( $user_a ) && $user_a > 0, is_wp_error( $user_a ) ? $user_a->get_error_message() : '' );

if ( is_int( $user_a ) ) {
	$created_users[] = $user_a;
	vok( 'the provisioned user holds the portal role', gwcpp_user_is_portal_user( $user_a ) );
	vok( 'the provisioned user is in the organisation', in_array( $org_a, gwcpp_user_orgs( $user_a ), true ) );
}

$user_b = gwcpp_grant_access( $org_b, $email_b );
if ( is_int( $user_b ) ) {
	$created_users[] = $user_b;
}

// The refusal that matters: an existing non-portal account.
$admin_email = get_option( 'admin_email' );
$refused     = gwcpp_grant_access( $org_a, $admin_email );
vok(
	'inviting an existing non-portal account is refused',
	is_wp_error( $refused ),
	'Silently adding the portal role to an administrator would lock them out of their own site.'
);

/* ── The access decision ─────────────────────────────────────────────────── */

vok( 'a member reaches their organisation\'s post', gwcpp_user_can_edit_post( $user_a, $post_a ) );
vok( 'a member does not reach another organisation\'s post', ! gwcpp_user_can_edit_post( $user_a, $post_b ) );
vok( 'a member does not reach an unassigned post', ! gwcpp_user_can_edit_post( $user_a, $post_free ) );
vok( 'the other member reaches only their own', gwcpp_user_can_edit_post( $user_b, $post_b ) && ! gwcpp_user_can_edit_post( $user_b, $post_a ) );

/* ── The list, which is the part unit tests cannot reach ─────────────────── */

$list_a = gwcpp_editable_post_ids( $user_a );

vok(
	'the list contains the organisation\'s post',
	in_array( $post_a, $list_a, true ),
	'got: ' . implode( ',', $list_a )
);
vok( 'the list excludes the other organisation\'s post', ! in_array( $post_b, $list_a, true ) );
vok( 'the list excludes the unassigned post', ! in_array( $post_free, $list_a, true ) );

// A direct grant should show up in the same list.
gwcpp_add_post_editor( $user_a, $post_free );
gwcpp_flush_access_cache();
$list_a = gwcpp_editable_post_ids( $user_a );

vok(
	'a direct grant appears in the list',
	in_array( $post_free, $list_a, true ),
	'got: ' . implode( ',', $list_a )
);

gwcpp_remove_post_editor( $user_a, $post_free );
gwcpp_flush_access_cache();
vok( 'withdrawing a direct grant removes it from the list', ! in_array( $post_free, gwcpp_editable_post_ids( $user_a ), true ) );

// Switching the post type off must empty the list entirely.
$off = $settings;
$off['post_types'] = array();
update_option( 'gwcpp_settings', $off );
gwcpp_settings_cache( null, true );
gwcpp_flush_access_cache();

vok(
	'switching the post type off empties the list',
	array() === gwcpp_editable_post_ids( $user_a ),
	'got: ' . implode( ',', gwcpp_editable_post_ids( $user_a ) )
);

update_option( 'gwcpp_settings', $settings );
gwcpp_settings_cache( null, true );
gwcpp_flush_access_cache();

/* ── The save allow-list ─────────────────────────────────────────────────── */

gwcpp_put_field(
	'gwcpp_itest',
	array(
		'key'   => 'itest_phone',
		'type'  => 'text',
		'label' => 'Phone',
	)
);

update_post_meta( $post_a, 'staff_only_note', 'do not touch' );

/* A submission naming a key that is not in the schema. gwcpp_save_fields()
 * iterates the schema, not the submission, so the extra key is never looked
 * at — this proves that structurally rather than by asserting on a branch.
 */
gwcpp_save_fields(
	$post_a,
	array(
		'itest_phone'     => '205 555 0199',
		'staff_only_note' => 'OVERWRITTEN',
		'__title'         => 'Belongs to A, renamed',
	)
);

vok( 'a mapped field is written', '205 555 0199' === get_post_meta( $post_a, 'itest_phone', true ) );
vok(
	'an unmapped key in the submission is ignored',
	'do not touch' === get_post_meta( $post_a, 'staff_only_note', true ),
	'got: ' . get_post_meta( $post_a, 'staff_only_note', true )
);
vok(
	'a synthetic field not in the schema is also ignored',
	'Belongs to A' === get_post( $post_a )->post_title,
	'__title was not mapped for this post type, so it must not be writable'
);

/* Now map the title and confirm it does become writable, so the check above is
 * proving the allow-list rather than proving titles never save.
 */
gwcpp_put_field(
	'gwcpp_itest',
	array(
		'key'   => '__title',
		'type'  => 'text',
		'label' => 'Name',
	)
);
gwcpp_save_fields( $post_a, array( '__title' => 'Belongs to A, renamed' ) );

vok( 'a mapped title is written', 'Belongs to A, renamed' === get_post( $post_a )->post_title );

gwcpp_save_fields( $post_a, array( '__title' => '   ' ) );
vok(
	'an empty title is refused even on the save path',
	'Belongs to A, renamed' === get_post( $post_a )->post_title,
	'A post shown as "(no title)" everywhere, done by somebody who cannot see any of those places.'
);

/* ── Unpublish, and the fact that nothing can delete ─────────────────────── */

vok( 'unpublishing works', gwcpp_unpublish_post( $post_a, $user_a ) && 'draft' === get_post_status( $post_a ) );
vok( 'an unpublished post is still editable by its owner', gwcpp_user_can_edit_post( $user_a, $post_a ) );
vok( 'republishing works', gwcpp_republish_post( $post_a ) && 'publish' === get_post_status( $post_a ) );

wp_trash_post( $post_a );
vok(
	'a trashed post is not editable',
	! gwcpp_user_can_edit_post( $user_a, $post_a ),
	'Trashing is staff\'s decision and must not be reachable or reversible from the portal.'
);
wp_untrash_post( $post_a );
wp_update_post(
	array(
		'ID'          => $post_a,
		'post_status' => 'publish',
	)
);

/* ── Revocation ends sessions ────────────────────────────────────────────── */

gwcpp_revoke_access( $user_a, $org_a );
gwcpp_flush_access_cache();

vok( 'revoking removes organisation membership', ! in_array( $org_a, gwcpp_user_orgs( $user_a ), true ) );
vok( 'revoking withdraws access', ! gwcpp_user_can_edit_post( $user_a, $post_a ) );
vok( 'revoking empties the list', array() === gwcpp_editable_post_ids( $user_a ) );

/* ── Tokens ──────────────────────────────────────────────────────────────── */

gwcpp_add_user_to_org( $user_a, $org_a );
$token = gwcpp_mint_token( $user_a );

vok( 'a token is 64 hex characters', (bool) preg_match( '/^[a-f0-9]{64}$/', $token ) );
vok(
	'the raw token is not what is stored',
	false === get_transient( 'gwcpp_tok_' . $token ) && false !== get_transient( 'gwcpp_tok_' . hash( 'sha256', $token ) ),
	'A leaked backup should yield hashes, not working sign-in links.'
);

/* Consuming signs the user in, so this is the last thing the script does that
 * touches the current user.
 */
$consumed = gwcpp_consume_token( $token );
vok( 'a token signs in the user it names', $consumed === $user_a );
vok( 'a token works only once', 0 === gwcpp_consume_token( $token ) );
vok( 'a token minted for one purpose is not accepted for another', 0 === gwcpp_consume_token( gwcpp_mint_token( $user_a, 'handoff' ), 'signin' ) );
vok( 'nonsense is not a token', 0 === gwcpp_consume_token( 'not-a-token' ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

wp_set_current_user( 0 );

foreach ( $created_users as $id ) {
	wp_delete_user( $id );
}
foreach ( $created_posts as $id ) {
	wp_delete_post( $id, true );
}

update_option( 'gwcpp_settings', $original_settings );

$schema = gwcpp_get_schema();
unset( $schema['types']['gwcpp_itest'] );
gwcpp_save_schema( $schema );

echo "\n";
echo "{$GLOBALS['gwcpp_pass']} passed, {$GLOBALS['gwcpp_fail']} failed\n";

if ( $GLOBALS['gwcpp_fail'] > 0 ) {
	exit( 1 );
}
