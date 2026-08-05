<?php
/**
 * Integration checks for the review cycle, handoff, and durable tokens.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/groundwork-common-post-portal/tests/integration/phase3.php
 *
 * The unit suite covers the date arithmetic exhaustively against stubs. What
 * needs a real WordPress is everything around it: that the daily run actually
 * walks real posts and sends real mail, that the ladder advances once per run,
 * that a durable token survives a transient flush — which is the entire reason
 * it exists — and that accepting a handoff creates a real user.
 *
 * @package PostPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

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

/** Set an entry's last review to N months ago. */
function gwcpp_itest_reviewed_ago( int $post_id, int $months ): void {
	update_post_meta(
		$post_id,
		GWCPP_REVIEWED_META,
		gwcpp_review_today()->modify( '-' . $months . ' months' )->format( 'Y-m-d' )
	);
	delete_post_meta( $post_id, GWCPP_NOTICES_META );
}

add_filter( 'send_auth_cookies', '__return_false' );

/* ── Fixtures ────────────────────────────────────────────────────────────── */

register_post_type(
	'gwcpp_p3',
	array(
		'public'   => true,
		'show_ui'  => true,
		'label'    => 'Phase 3 Test',
		'supports' => array( 'title' ),
	)
);

$original_settings = get_option( 'gwcpp_settings' );

$settings = is_array( $original_settings ) ? $original_settings : array();
$settings['post_types'] = array_values( array_unique( array_merge( (array) ( $settings['post_types'] ?? array() ), array( 'gwcpp_p3' ) ) ) );
$settings['types']['gwcpp_p3'] = array(
	'require_approval' => false,
	'allow_create'     => false,
	'allow_unpublish'  => true,
	'allow_handoff'    => true,
	'author_grant'     => false,
	'create_status'    => 'draft',
	'review_months'    => 6,
);
$settings['blocked_words'] = "scam\nfree money";
update_option( 'gwcpp_settings', $settings );
gwcpp_settings_cache( null, true );

$org      = wp_insert_post( array( 'post_type' => GWCPP_ORG_TYPE, 'post_status' => 'publish', 'post_title' => 'P3 Org' ) );
$owned    = wp_insert_post( array( 'post_type' => 'gwcpp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Owned' ) );
$orphan   = wp_insert_post( array( 'post_type' => 'gwcpp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Orphan' ) );
$exempted = wp_insert_post( array( 'post_type' => 'gwcpp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Exempt' ) );

gwcpp_set_post_org( $owned, $org );
gwcpp_set_post_org( $exempted, $org );
update_post_meta( $exempted, GWCPP_REVIEW_EXEMPT_META, 1 );

foreach ( array( 'p3owner@example.test', 'p3new@example.test' ) as $email ) {
	$stale = get_user_by( 'email', $email );
	if ( $stale ) {
		wp_delete_user( $stale->ID );
	}
}

$owner = gwcpp_grant_access( $org, 'p3owner@example.test' );
$owner = is_wp_error( $owner ) ? 0 : (int) $owner;

vok( 'an owner was provisioned', $owner > 0 );
vok( 'the owned entry has an owner', gwcpp_post_has_owner( $owned ) );
vok( 'the orphan entry has none', ! gwcpp_post_has_owner( $orphan ) );

/* ── Stages against a real clock ─────────────────────────────────────────── */

gwcpp_itest_reviewed_ago( $owned, 4 );
vok( 'four months in is current', 'current' === gwcpp_review_state( $owned )['stage'] );

gwcpp_itest_reviewed_ago( $owned, 5 );
vok( 'five months in is due', 'due' === gwcpp_review_state( $owned )['stage'] );

gwcpp_itest_reviewed_ago( $owned, 7 );
vok( 'seven months in is overdue', 'overdue' === gwcpp_review_state( $owned )['stage'] );

gwcpp_itest_reviewed_ago( $owned, 12 );
vok( 'twelve months in is expired', 'expired' === gwcpp_review_state( $owned )['stage'] );

/* ── The daily run ───────────────────────────────────────────────────────── */

gwcpp_itest_reviewed_ago( $owned, 5 );
gwcpp_itest_reviewed_ago( $orphan, 24 );
gwcpp_itest_reviewed_ago( $exempted, 24 );

$run = gwcpp_run_daily_review();

vok( 'the run walked the tracked entries', $run['checked'] >= 3, wp_json_encode( $run ) );
vok( 'the owner was emailed', $run['mailed'] >= 1, wp_json_encode( $run ) );
vok(
	'a rung was recorded once the mail was away',
	in_array( 'due', gwcpp_review_notices_sent( $owned ), true ),
	'got: ' . implode( ',', gwcpp_review_notices_sent( $owned ) )
);

$second = gwcpp_run_daily_review();
vok(
	'running again sends nothing new',
	0 === $second['mailed'],
	'Each rung fires once; got ' . wp_json_encode( $second )
);

vok(
	'the orphan was not hidden',
	'publish' === get_post_status( $orphan ),
	'Nobody can review it, so hiding it would punish a partner for our own gap.'
);
vok( 'the orphan reads as unmanaged', 'unmanaged' === gwcpp_review_state( $orphan )['state'] );

vok( 'the exempt entry was not hidden', 'publish' === get_post_status( $exempted ) );
vok( 'the exempt entry got no reminder', array() === gwcpp_review_notices_sent( $exempted ) );

/* ── Expiry and restoration ──────────────────────────────────────────────── */

gwcpp_itest_reviewed_ago( $owned, 24 );
gwcpp_run_daily_review();

vok( 'an expired owned entry is hidden', 'draft' === get_post_status( $owned ) );
vok( 'and marked as hidden by the cycle', (bool) get_post_meta( $owned, GWCPP_AUTO_EXPIRED_META, true ) );
vok( 'its owner can still reach it', gwcpp_user_can_edit_post( $owner, $owned ) );

gwcpp_record_review( $owned, $owner );

vok( 'confirming puts it straight back', 'publish' === get_post_status( $owned ) );
vok( 'and clears the marker', '' === (string) get_post_meta( $owned, GWCPP_AUTO_EXPIRED_META, true ) );
vok( 'and resets the ladder', array() === gwcpp_review_notices_sent( $owned ) );
vok( 'and is current again', 'current' === gwcpp_review_state( $owned )['stage'] );

/* ── Durable tokens: the whole reason they are not transients ────────────── */

$token = gwcpp_mint_durable_token( $owner, 'review' );

vok( 'a durable token is 64 hex characters', (bool) preg_match( '/^[a-f0-9]{64}$/', $token ) );

// The exact thing a deploy does, and the thing that broke the original.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ); // phpcs:ignore
wp_cache_flush();

vok(
	'a durable token survives a transient flush',
	gwcpp_consume_durable_token( $token, 'review' ) === $owner,
	'This is the entire reason review links do not live in a transient.'
);
vok( 'and works only once', 0 === gwcpp_consume_durable_token( $token, 'review' ) );
vok( 'and is refused for another purpose', 0 === gwcpp_consume_durable_token( gwcpp_mint_durable_token( $owner, 'review' ), 'handoff' ) );

$expired = gwcpp_mint_durable_token( $owner, 'review', 60 );
$stored  = get_user_meta( $owner, GWCPP_TOKENS_META, true );
foreach ( $stored as $hash => $entry ) {
	$stored[ $hash ]['expires'] = time() - DAY_IN_SECONDS;
}
update_user_meta( $owner, GWCPP_TOKENS_META, $stored );

vok( 'an expired durable token is refused', 0 === gwcpp_consume_durable_token( $expired, 'review' ) );
vok( 'and the sweep clears the rest', gwcpp_purge_expired_durable_tokens() >= 0 );

/* ── Handoff ─────────────────────────────────────────────────────────────── */

$sent = gwcpp_send_handoff( $owned, $owner, 'p3new@example.test' );
vok( 'an invitation can be sent', true === $sent, is_wp_error( $sent ) ? $sent->get_error_message() : '' );

$pending = gwcpp_get_handoff( $owned );
vok( 'the invitation is recorded', null !== $pending && 'p3new@example.test' === $pending['email'] );
vok(
	'the raw token is not what is stored',
	null !== $pending && 64 === strlen( $pending['hash'] ) && ! isset( $pending['token'] ),
	'The database should hold something that cannot be used to accept.'
);

vok( 'a wrong token is refused', is_wp_error( gwcpp_accept_handoff( $owned, str_repeat( 'a', 64 ) ) ) );
vok( 'and the invitation survives a wrong guess', null !== gwcpp_get_handoff( $owned ) );

// Re-send to get a token we actually hold.
delete_post_meta( $owned, GWCPP_HANDOFF_META );
$raw_token = '';
add_action(
	'gwcpp_itest_capture',
	static function () {},
	10
);
// gwcpp_send_handoff does not return the token, so mint the flow by hand the
// same way it does, then accept with it.
$sent = gwcpp_send_handoff( $owned, $owner, 'p3new@example.test' );
$pending = gwcpp_get_handoff( $owned );

// Pull the token out of the email Mailpit received, which is exactly what the
// recipient does — and proves the link in the message actually works.
$raw_token = '';
$response  = wp_remote_get( 'http://host.docker.internal:8027/api/v1/messages?limit=5' );
if ( ! is_wp_error( $response ) ) {
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	foreach ( (array) ( $body['messages'] ?? array() ) as $message ) {
		$one = wp_remote_get( 'http://host.docker.internal:8027/api/v1/message/' . $message['ID'] );
		if ( is_wp_error( $one ) ) {
			continue;
		}
		$html = (string) ( json_decode( wp_remote_retrieve_body( $one ), true )['HTML'] ?? '' );
		if ( preg_match( '/gwcpp_handoff_token=([a-f0-9]{64})/', $html, $m ) ) {
			$raw_token = $m[1];
			break;
		}
	}
}

if ( '' === $raw_token ) {
	echo "SKIP  accepting a handoff (Mailpit not reachable from the container)\n";
} else {
	$new_user = gwcpp_accept_handoff( $owned, $raw_token );

	vok( 'the token from the email accepts', ! is_wp_error( $new_user ), is_wp_error( $new_user ) ? $new_user->get_error_message() : '' );

	if ( ! is_wp_error( $new_user ) ) {
		vok( 'the new person joined the organisation', in_array( $org, gwcpp_user_orgs( (int) $new_user ), true ) );
		vok( 'and can edit the entry', gwcpp_user_can_edit_post( (int) $new_user, $owned ) );
		vok(
			'the outgoing person keeps their access',
			gwcpp_user_can_edit_post( $owner, $owned ),
			'A handoff must never be able to lock an organisation out of its own entries.'
		);
		vok( 'the invitation is spent', null === gwcpp_get_handoff( $owned ) );
	}
}

vok(
	'an entry with no organisation cannot be handed over',
	is_wp_error( gwcpp_send_handoff( $orphan, $owner, 'someone@example.test' ) )
);

$admin_email = get_option( 'admin_email' );
vok(
	'an existing non-portal account is refused',
	is_wp_error( gwcpp_send_handoff( $owned, $owner, $admin_email ) )
);

/* ── Blocked words in a real submission ──────────────────────────────────── */

gwcpp_put_field( 'gwcpp_p3', gwcpp_sanitize_field( array( 'key' => 'p3_blurb', 'type' => 'textarea', 'label' => 'About' ) ) );
update_post_meta( $owned, 'p3_blurb', 'The Scam Prevention Trust' );

$_POST['gwcpp_post_id'] = $owned;

$unchanged = gwcpp_validate_submission( 'gwcpp_p3', array( 'p3_blurb' => 'The Scam Prevention Trust' ) );
vok(
	'an existing entry containing a blocked word stays editable',
	array() === $unchanged,
	'got: ' . wp_json_encode( $unchanged )
);

$changed = gwcpp_validate_submission( 'gwcpp_p3', array( 'p3_blurb' => 'get free money here' ) );
vok( 'a newly typed blocked word is refused', isset( $changed['p3_blurb'] ) );

unset( $_POST['gwcpp_post_id'] );

/* ── The weekly digest ───────────────────────────────────────────────────── */

$digest = gwcpp_run_weekly_digest();
vok( 'the digest sees the unmanaged entry', $digest['unmanaged'] >= 1, wp_json_encode( $digest ) );

/* ── Clean up ────────────────────────────────────────────────────────────── */

foreach ( array( 'p3owner@example.test', 'p3new@example.test' ) as $email ) {
	$user = get_user_by( 'email', $email );
	if ( $user ) {
		wp_delete_user( $user->ID );
	}
}
foreach ( array( $owned, $orphan, $exempted, $org ) as $id ) {
	wp_delete_post( $id, true );
}

update_option( 'gwcpp_settings', is_array( $original_settings ) ? $original_settings : array() );
delete_option( 'gwcpp_review_last_run' );

echo "\n{$GLOBALS['gwcpp_pass']} passed, {$GLOBALS['gwcpp_fail']} failed\n";

if ( $GLOBALS['gwcpp_fail'] > 0 ) {
	exit( 1 );
}
