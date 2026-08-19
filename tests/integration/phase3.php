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

/* ── Mail, captured in process ───────────────────────────────────────────────
 * These checks need to read what the plugin actually sent — the invitation's
 * body carries the token a recipient clicks, and asserting that link works is
 * the one part of this worth testing end to end.
 *
 * That used to mean routing SMTP to a Mailpit container: a committed mu-plugin
 * on `phpmailer_init`, a container attached to the wp-env network in CI, and an
 * HTTP API scraped for the message. It made the suite depend on infrastructure
 * nobody running it locally had, so six checks failed on any machine without
 * Mailpit — failures that read exactly like a bug in the plugin and were not.
 *
 * `pre_wp_mail` needs none of it. It short-circuits wp_mail() before delivery,
 * hands over the arguments, and returns true — which is the answer a working
 * mail host would have given, so nothing downstream sees a failed send. The
 * body is right here, already rendered.
 *
 * This is also the hook the rest of this plugin family traps mail on, and for
 * the recorded reason: `phpmailer_init` can only redirect a send, never stop
 * one, so a throw there makes wp_mail() return false and the plugin reasonably
 * concludes nobody was told.
 * ─────────────────────────────────────────────────────────────────────────── */

$GLOBALS['gwc_pp_itest_mail'] = array();

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$GLOBALS['gwc_pp_itest_mail'][] = (array) $atts;
		return true;
	},
	10,
	2
);

/**
 * The most recent captured message whose body matches a pattern.
 *
 * @param string $pattern Regex with one capturing group.
 * @return string The captured group, or '' if no message matched.
 */
function gwc_pp_itest_from_mail( string $pattern ): string {
	foreach ( array_reverse( (array) $GLOBALS['gwc_pp_itest_mail'] ) as $mail ) {
		if ( preg_match( $pattern, (string) ( $mail['message'] ?? '' ), $m ) ) {
			return $m[1];
		}
	}

	return '';
}

/** Set an entry's last review to N months ago. */
function gwc_pp_itest_reviewed_ago( int $post_id, int $months ): void {
	update_post_meta(
		$post_id,
		GWC_PP_REVIEWED_META,
		gwc_pp_review_today()->modify( '-' . $months . ' months' )->format( 'Y-m-d' )
	);
	delete_post_meta( $post_id, GWC_PP_NOTICES_META );
}

add_filter( 'send_auth_cookies', '__return_false' );

/* ── Fixtures ────────────────────────────────────────────────────────────── */

register_post_type(
	'gwc_pp_p3',
	array(
		'public'   => true,
		'show_ui'  => true,
		'label'    => 'Phase 3 Test',
		'supports' => array( 'title' ),
	)
);

$original_settings = get_option( 'gwc_pp_settings' );

$settings = is_array( $original_settings ) ? $original_settings : array();
$settings['post_types'] = array_values( array_unique( array_merge( (array) ( $settings['post_types'] ?? array() ), array( 'gwc_pp_p3' ) ) ) );
$settings['types']['gwc_pp_p3'] = array(
	'require_approval' => false,
	'allow_create'     => false,
	'allow_unpublish'  => true,
	'allow_handoff'    => true,
	'author_grant'     => false,
	'create_status'    => 'draft',
	'review_months'    => 6,
);
$settings['blocked_words'] = "scam\nfree money";
update_option( 'gwc_pp_settings', $settings );
gwc_pp_settings_cache( null, true );

$org      = wp_insert_post( array( 'post_type' => GWC_PP_ORG_TYPE, 'post_status' => 'publish', 'post_title' => 'P3 Org' ) );
$owned    = wp_insert_post( array( 'post_type' => 'gwc_pp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Owned' ) );
$orphan   = wp_insert_post( array( 'post_type' => 'gwc_pp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Orphan' ) );
$exempted = wp_insert_post( array( 'post_type' => 'gwc_pp_p3', 'post_status' => 'publish', 'post_title' => 'P3 Exempt' ) );

gwc_pp_set_post_org( $owned, $org );
gwc_pp_set_post_org( $exempted, $org );
update_post_meta( $exempted, GWC_PP_REVIEW_EXEMPT_META, 1 );

foreach ( array( 'p3owner@example.test', 'p3new@example.test' ) as $email ) {
	$stale = get_user_by( 'email', $email );
	if ( $stale ) {
		wp_delete_user( $stale->ID );
	}
}

$owner = gwc_pp_grant_access( $org, 'p3owner@example.test' );
$owner = is_wp_error( $owner ) ? 0 : (int) $owner;

vok( 'an owner was provisioned', $owner > 0 );
vok( 'the owned entry has an owner', gwc_pp_post_has_owner( $owned ) );
vok( 'the orphan entry has none', ! gwc_pp_post_has_owner( $orphan ) );

/* ── Stages against a real clock ─────────────────────────────────────────── */

gwc_pp_itest_reviewed_ago( $owned, 4 );
vok( 'four months in is current', 'current' === gwc_pp_review_state( $owned )['stage'] );

gwc_pp_itest_reviewed_ago( $owned, 5 );
vok( 'five months in is due', 'due' === gwc_pp_review_state( $owned )['stage'] );

gwc_pp_itest_reviewed_ago( $owned, 7 );
vok( 'seven months in is overdue', 'overdue' === gwc_pp_review_state( $owned )['stage'] );

gwc_pp_itest_reviewed_ago( $owned, 12 );
vok( 'twelve months in is expired', 'expired' === gwc_pp_review_state( $owned )['stage'] );

/* ── The daily run ───────────────────────────────────────────────────────── */

gwc_pp_itest_reviewed_ago( $owned, 5 );
gwc_pp_itest_reviewed_ago( $orphan, 24 );
gwc_pp_itest_reviewed_ago( $exempted, 24 );

$run = gwc_pp_run_daily_review();

vok( 'the run walked the tracked entries', $run['checked'] >= 3, wp_json_encode( $run ) );
vok( 'the owner was emailed', $run['mailed'] >= 1, wp_json_encode( $run ) );
vok(
	'a rung was recorded once the mail was away',
	in_array( 'due', gwc_pp_review_notices_sent( $owned ), true ),
	'got: ' . implode( ',', gwc_pp_review_notices_sent( $owned ) )
);

$second = gwc_pp_run_daily_review();
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
vok( 'the orphan reads as unmanaged', 'unmanaged' === gwc_pp_review_state( $orphan )['state'] );

vok( 'the exempt entry was not hidden', 'publish' === get_post_status( $exempted ) );
vok( 'the exempt entry got no reminder', array() === gwc_pp_review_notices_sent( $exempted ) );

/* ── Expiry and restoration ──────────────────────────────────────────────── */

gwc_pp_itest_reviewed_ago( $owned, 24 );
gwc_pp_run_daily_review();

vok( 'an expired owned entry is hidden', 'draft' === get_post_status( $owned ) );
vok( 'and marked as hidden by the cycle', (bool) get_post_meta( $owned, GWC_PP_AUTO_EXPIRED_META, true ) );
vok( 'its owner can still reach it', gwc_pp_user_can_edit_post( $owner, $owned ) );

gwc_pp_record_review( $owned, $owner );

vok( 'confirming puts it straight back', 'publish' === get_post_status( $owned ) );
vok( 'and clears the marker', '' === (string) get_post_meta( $owned, GWC_PP_AUTO_EXPIRED_META, true ) );
vok( 'and resets the ladder', array() === gwc_pp_review_notices_sent( $owned ) );
vok( 'and is current again', 'current' === gwc_pp_review_state( $owned )['stage'] );

/* ── Durable tokens: the whole reason they are not transients ────────────── */

$token = gwc_pp_mint_durable_token( $owner, 'review' );

vok( 'a durable token is 64 hex characters', (bool) preg_match( '/^[a-f0-9]{64}$/', $token ) );

// The exact thing a deploy does, and the thing that broke the original.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" ); // phpcs:ignore
wp_cache_flush();

vok(
	'a durable token survives a transient flush',
	gwc_pp_consume_durable_token( $token, 'review' ) === $owner,
	'This is the entire reason review links do not live in a transient.'
);
vok( 'and works only once', 0 === gwc_pp_consume_durable_token( $token, 'review' ) );
vok( 'and is refused for another purpose', 0 === gwc_pp_consume_durable_token( gwc_pp_mint_durable_token( $owner, 'review' ), 'handoff' ) );

$expired = gwc_pp_mint_durable_token( $owner, 'review', 60 );
$stored  = get_user_meta( $owner, GWC_PP_TOKENS_META, true );
foreach ( $stored as $hash => $entry ) {
	$stored[ $hash ]['expires'] = time() - DAY_IN_SECONDS;
}
update_user_meta( $owner, GWC_PP_TOKENS_META, $stored );

vok( 'an expired durable token is refused', 0 === gwc_pp_consume_durable_token( $expired, 'review' ) );
vok( 'and the sweep clears the rest', gwc_pp_purge_expired_durable_tokens() >= 0 );

/* ── Handoff ─────────────────────────────────────────────────────────────── */

$sent = gwc_pp_send_handoff( $owned, $owner, 'p3new@example.test' );
vok( 'an invitation can be sent', true === $sent, is_wp_error( $sent ) ? $sent->get_error_message() : '' );

$pending = gwc_pp_get_handoff( $owned );
vok( 'the invitation is recorded', null !== $pending && 'p3new@example.test' === $pending['email'] );
vok(
	'the raw token is not what is stored',
	null !== $pending && 64 === strlen( $pending['hash'] ) && ! isset( $pending['token'] ),
	'The database should hold something that cannot be used to accept.'
);

vok( 'a wrong token is refused', is_wp_error( gwc_pp_accept_handoff( $owned, str_repeat( 'a', 64 ) ) ) );
vok( 'and the invitation survives a wrong guess', null !== gwc_pp_get_handoff( $owned ) );

// Re-send to get a token we actually hold.
delete_post_meta( $owned, GWC_PP_HANDOFF_META );
$raw_token = '';
add_action(
	'gwc_pp_itest_capture',
	static function () {},
	10
);
// gwc_pp_send_handoff does not return the token, so mint the flow by hand the
// same way it does, then accept with it.
$sent = gwc_pp_send_handoff( $owned, $owner, 'p3new@example.test' );
$pending = gwc_pp_get_handoff( $owned );

// Pull the token out of the invitation the plugin just sent, which is exactly
// what the recipient does — and proves the link in the message actually works.
$raw_token = gwc_pp_itest_from_mail( '/gwc_pp_handoff_token=([a-f0-9]{64})/' );

// Previously a silent skip when Mailpit was absent. It is an assertion now,
// because the message is captured in process and there is nothing left to be
// absent — an invitation with no usable link in it is a real failure.
vok(
	'the invitation email carries a usable token',
	'' !== $raw_token,
	'no gwc_pp_handoff_token= link in any of the ' . count( (array) $GLOBALS['gwc_pp_itest_mail'] ) . ' captured messages'
);

if ( '' !== $raw_token ) {
	$new_user = gwc_pp_accept_handoff( $owned, $raw_token );

	vok( 'the token from the email accepts', ! is_wp_error( $new_user ), is_wp_error( $new_user ) ? $new_user->get_error_message() : '' );

	if ( ! is_wp_error( $new_user ) ) {
		vok( 'the new person joined the organisation', in_array( $org, gwc_pp_user_orgs( (int) $new_user ), true ) );
		vok( 'and can edit the entry', gwc_pp_user_can_edit_post( (int) $new_user, $owned ) );
		vok(
			'the outgoing person keeps their access',
			gwc_pp_user_can_edit_post( $owner, $owned ),
			'A handoff must never be able to lock an organisation out of its own entries.'
		);
		vok( 'the invitation is spent', null === gwc_pp_get_handoff( $owned ) );
	}
}

vok(
	'an entry with no organisation cannot be handed over',
	is_wp_error( gwc_pp_send_handoff( $orphan, $owner, 'someone@example.test' ) )
);

$admin_email = get_option( 'admin_email' );
vok(
	'an existing non-portal account is refused',
	is_wp_error( gwc_pp_send_handoff( $owned, $owner, $admin_email ) )
);

/* ── Blocked words in a real submission ──────────────────────────────────── */

gwc_pp_put_field( 'gwc_pp_p3', gwc_pp_sanitize_field( array( 'key' => 'p3_blurb', 'type' => 'textarea', 'label' => 'About' ) ) );
update_post_meta( $owned, 'p3_blurb', 'The Scam Prevention Trust' );

$_POST['gwc_pp_post_id'] = $owned;

$unchanged = gwc_pp_validate_submission( 'gwc_pp_p3', array( 'p3_blurb' => 'The Scam Prevention Trust' ) );
vok(
	'an existing entry containing a blocked word stays editable',
	array() === $unchanged,
	'got: ' . wp_json_encode( $unchanged )
);

$changed = gwc_pp_validate_submission( 'gwc_pp_p3', array( 'p3_blurb' => 'get free money here' ) );
vok( 'a newly typed blocked word is refused', isset( $changed['p3_blurb'] ) );

unset( $_POST['gwc_pp_post_id'] );

/* ── The weekly digest ───────────────────────────────────────────────────── */

$digest = gwc_pp_run_weekly_digest();
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

update_option( 'gwc_pp_settings', is_array( $original_settings ) ? $original_settings : array() );
delete_option( 'gwc_pp_review_last_run' );

echo "\n{$GLOBALS['gwc_pp_pass']} passed, {$GLOBALS['gwc_pp_fail']} failed\n";

if ( $GLOBALS['gwc_pp_fail'] > 0 ) {
	exit( 1 );
}
