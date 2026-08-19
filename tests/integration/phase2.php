<?php
/**
 * Integration checks for the approval queue and the rich field types.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/groundwork-common-post-portal/tests/integration/phase2.php
 *
 * These cover the things a stub cannot honestly prove:
 *
 *  - that wp_kses with our allow-list actually removes a <script>. The unit
 *    suite asserts the SHAPE of that list and deliberately stubs wp_kses to a
 *    passthrough, because a hand-written KSES in the test harness would pass
 *    whatever the list said.
 *  - that wp_check_filetype_and_ext refuses a PHP file renamed to .jpg. That
 *    check reads the file's bytes; there is nothing to test without a real one.
 *  - that terms are written to the taxonomy rather than to post meta.
 *
 * Fixtures are created and removed here, including on failure, so it can be run
 * repeatedly.
 *
 * @package PostPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['gwc_pp_pass'] = 0;
$GLOBALS['gwc_pp_fail'] = 0;

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it held.
 * @param string $detail Extra context, shown on failure.
 */
function vok( string $label, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['gwc_pp_pass'];
		echo "PASS  {$label}\n";
		return;
	}

	++$GLOBALS['gwc_pp_fail'];
	echo "FAIL  {$label}";
	echo '' !== $detail ? "  — {$detail}\n" : "\n";
}

/* ── Fixtures ────────────────────────────────────────────────────────────── */

register_post_type(
	'gwc_pp_p2',
	array(
		'public'   => true,
		'show_ui'  => true,
		'label'    => 'Phase 2 Test',
		'supports' => array( 'title', 'editor' ),
	)
);
register_taxonomy(
	'gwc_pp_p2_tax',
	'gwc_pp_p2',
	array(
		'public'       => false,
		'show_ui'      => true,
		'hierarchical' => true,
		'label'        => 'Phase 2 Terms',
	)
);

$original_settings = get_option( 'gwc_pp_settings' );
$original_schema   = get_option( 'gwc_pp_schema' );

$settings = is_array( $original_settings ) ? $original_settings : array();
$settings['post_types'] = array_values( array_unique( array_merge( (array) ( $settings['post_types'] ?? array() ), array( 'gwc_pp_p2' ) ) ) );
$settings['types']['gwc_pp_p2'] = array(
	'require_approval' => true,
	'allow_create'     => false,
	'allow_unpublish'  => true,
	'author_grant'     => false,
	'create_status'    => 'draft',
);
update_option( 'gwc_pp_settings', $settings );
gwc_pp_settings_cache( null, true );

foreach (
	array(
		array( 'key' => '__title', 'type' => 'text', 'label' => 'Name' ),
		array( 'key' => 'p2_phone', 'type' => 'phone', 'label' => 'Phone' ),
		array( 'key' => '__content', 'type' => 'richtext', 'label' => 'Main text' ),
		array( 'key' => 'gwc_pp_p2_tax', 'type' => 'taxonomy', 'label' => 'Categories' ),
	) as $raw
) {
	$field = gwc_pp_sanitize_field( $raw );
	if ( null === $field ) {
		echo "  ! could not build field {$raw['key']}\n";
		continue;
	}
	gwc_pp_put_field( 'gwc_pp_p2', $field );
}

$org  = wp_insert_post( array( 'post_type' => GWC_PP_ORG_TYPE, 'post_status' => 'publish', 'post_title' => 'P2 Org' ) );
$post = wp_insert_post( array( 'post_type' => 'gwc_pp_p2', 'post_status' => 'publish', 'post_title' => 'P2 Entry' ) );
gwc_pp_set_post_org( $post, $org );
update_post_meta( $post, 'p2_phone', '205 555 0100' );
update_post_meta( $post, 'staff_only', 'keep me' );

$term_a = wp_insert_term( 'Alpha', 'gwc_pp_p2_tax' );
$term_b = wp_insert_term( 'Beta', 'gwc_pp_p2_tax' );
$term_a = is_wp_error( $term_a ) ? 0 : (int) $term_a['term_id'];
$term_b = is_wp_error( $term_b ) ? 0 : (int) $term_b['term_id'];

$existing = get_user_by( 'email', 'p2@example.test' );
if ( $existing ) {
	wp_delete_user( $existing->ID );
}
$user = gwc_pp_grant_access( $org, 'p2@example.test' );
$user = is_wp_error( $user ) ? 0 : (int) $user;

/* ── Rich text: the allow-list against real KSES ─────────────────────────── */

$field_content = gwc_pp_find_field( 'gwc_pp_p2', '__content' );

$dangerous = array(
	'script'      => '<p>ok</p><script>alert(1)</script>',
	'iframe'      => '<iframe src="https://evil.test"></iframe><p>ok</p>',
	'onclick'     => '<p onclick="alert(1)">ok</p>',
	'style attr'  => '<p style="position:fixed;inset:0">ok</p>',
	'class attr'  => '<p class="site-header">ok</p>',
	'javascript:' => '<p><a href="javascript:alert(1)">ok</a></p>',
	'form'        => '<form action="https://evil.test"><input name="pw" /></form><p>ok</p>',
	'h1'          => '<h1>ok</h1>',
	'object'      => '<object data="evil.swf"></object><p>ok</p>',
);

foreach ( $dangerous as $label => $html ) {
	$clean = gwc_pp_sanitize_richtext( $html, $field_content );

	$leaked = false;
	foreach ( array( '<script', '<iframe', 'onclick', 'style=', 'class=', 'javascript:', '<form', '<input', '<h1', '<object' ) as $needle ) {
		if ( false !== stripos( $clean, $needle ) ) {
			$leaked = true;
			break;
		}
	}

	vok( "rich text strips {$label}", ! $leaked, 'got: ' . $clean );
}

vok(
	'rich text keeps ordinary writing',
	false !== strpos( gwc_pp_sanitize_richtext( '<p>We open at <strong>nine</strong>.</p>', $field_content ), '<strong>' )
);

vok(
	'rich text hardens links it keeps',
	false !== strpos( gwc_pp_sanitize_richtext( '<p><a href="https://example.org">x</a></p>', $field_content ), 'rel="nofollow noopener"' )
);

vok(
	'an emptied editor stores nothing',
	gwc_pp_empty_richtext( '<p>&nbsp;</p>' ),
	'TinyMCE sends exactly this when somebody clears the field.'
);

/* ── Uploads: the byte-level type check ──────────────────────────────────── */

$tmp = wp_tempnam( 'gwcpp-evil' );
file_put_contents( $tmp, "<?php echo 'pwned'; ?>\n" ); // phpcs:ignore

$checked = wp_check_filetype_and_ext( $tmp, 'photo.jpg', gwc_pp_allowed_upload_types() );

vok(
	'a PHP file renamed to .jpg is refused',
	empty( $checked['type'] ) || ! in_array( $checked['type'], gwc_pp_allowed_upload_types(), true ),
	'wp_check_filetype_and_ext said: ' . wp_json_encode( $checked )
);

$real = wp_tempnam( 'gwcpp-real' );
// A one-pixel PNG, so there is a genuinely valid image to compare against.
file_put_contents( $real, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore

$checked_png = wp_check_filetype_and_ext( $real, 'photo.png', gwc_pp_allowed_upload_types() );
vok( 'a real PNG is accepted', 'image/png' === ( $checked_png['type'] ?? '' ) );

vok(
	'php is not on the allow-list at all',
	! in_array( 'application/x-httpd-php', gwc_pp_allowed_upload_types(), true )
		&& ! array_key_exists( 'php', gwc_pp_allowed_upload_types() )
);

@unlink( $tmp );  // phpcs:ignore
@unlink( $real ); // phpcs:ignore

/* ── Taxonomy: terms, not meta ───────────────────────────────────────────── */

$tax_field = gwc_pp_find_field( 'gwc_pp_p2', 'gwc_pp_p2_tax' );

vok( 'the taxonomy field kept its slug as its key', 'gwc_pp_p2_tax' === ( $tax_field['key'] ?? '' ) );
vok( 'the taxonomy field is recognised as one', gwc_pp_type_is_taxonomy( 'taxonomy' ) );

gwc_pp_save_fields( $post, array( 'gwc_pp_p2_tax' => array( $term_a ) ) );

vok(
	'terms are written to the taxonomy',
	in_array( $term_a, wp_get_object_terms( $post, 'gwc_pp_p2_tax', array( 'fields' => 'ids' ) ), true )
);
vok(
	'terms are NOT copied into post meta',
	'' === (string) get_post_meta( $post, 'gwc_pp_p2_tax', true ),
	'A private second copy would silently disagree with the real one.'
);

$rogue = wp_insert_term( 'Rogue', 'category' );
$rogue = is_wp_error( $rogue ) ? 0 : (int) $rogue['term_id'];
$clean = gwc_pp_sanitize_taxonomy( array( 'value' => array( $rogue, $term_b ) ), $tax_field );

vok(
	'a term from another taxonomy is refused',
	array( $term_b ) === $clean,
	'got: ' . wp_json_encode( $clean )
);

gwc_pp_save_fields( $post, array( 'gwc_pp_p2_tax' => array() ) );
vok(
	'clearing every box really clears the terms',
	array() === wp_get_object_terms( $post, 'gwc_pp_p2_tax', array( 'fields' => 'ids' ) )
);

/* ── The approval round trip ─────────────────────────────────────────────── */

gwc_pp_store_changeset(
	$post,
	$user,
	array(
		'p2_phone'  => '205 555 0199',
		'__title'   => 'P2 Entry, renamed',
		'staff_only' => 'OVERWRITTEN',
	)
);

vok( 'the post appears in the queue', in_array( $post, gwc_pp_pending_post_ids(), true ) );
vok( 'the queue count sees it', gwc_pp_pending_count() >= 1 );

vok(
	'the live post is untouched while pending',
	'205 555 0100' === get_post_meta( $post, 'p2_phone', true ) && 'P2 Entry' === get_post( $post )->post_title
);

$diff = gwc_pp_changeset_diff( $post );
$keys = array_column( $diff, 'key' );
sort( $keys );

vok(
	'the diff lists only mapped, changed fields',
	array( '__title', 'p2_phone' ) === $keys,
	'got: ' . implode( ',', $keys )
);

gwc_pp_apply_changeset( $post, 1 );

vok( 'approving writes the values', '205 555 0199' === get_post_meta( $post, 'p2_phone', true ) );
vok( 'approving updates the title', 'P2 Entry, renamed' === get_post( $post )->post_title );
vok(
	'approving cannot write an unmapped key',
	'keep me' === get_post_meta( $post, 'staff_only', true ),
	'The changeset carried it; the schema does not list it.'
);
vok( 'the queue is empty afterwards', ! in_array( $post, gwc_pp_pending_post_ids(), true ) );
vok( 'the change is in the log', count( gwc_pp_change_log( $post ) ) >= 1 );

// Reject leaves everything alone.
gwc_pp_store_changeset( $post, $user, array( 'p2_phone' => '999 999 9999' ) );
gwc_pp_reject_changeset( $post, 1, 'Wrong number.' );

vok( 'rejecting leaves the value alone', '205 555 0199' === get_post_meta( $post, 'p2_phone', true ) );
vok( 'rejecting clears the queue', ! gwc_pp_has_changeset( $post ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

if ( $user > 0 ) {
	wp_delete_user( $user );
}
wp_delete_post( $post, true );
wp_delete_post( $org, true );
foreach ( array( $term_a, $term_b ) as $t ) {
	if ( $t > 0 ) {
		wp_delete_term( $t, 'gwc_pp_p2_tax' );
	}
}
if ( $rogue > 0 ) {
	wp_delete_term( $rogue, 'category' );
}

update_option( 'gwc_pp_settings', is_array( $original_settings ) ? $original_settings : array() );
update_option( 'gwc_pp_schema', is_array( $original_schema ) ? $original_schema : array( 'version' => GWC_PP_SCHEMA_VERSION, 'types' => array() ) );

echo "\n{$GLOBALS['gwc_pp_pass']} passed, {$GLOBALS['gwc_pp_fail']} failed\n";

if ( $GLOBALS['gwc_pp_fail'] > 0 ) {
	exit( 1 );
}
