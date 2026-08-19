<?php
/**
 * Handoff: passing an entry to whoever does the job next.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── The problem this solves ─────────────────────────────────────────────────
 * People leave. The person who set up a partner's listing moves on, and the
 * address on their portal account is a mailbox nobody reads any more. Every
 * reminder the review cycle sends goes into it, the entry expires, and the
 * organisation finds out when somebody asks why they have vanished from the
 * directory.
 *
 * Staff can fix that by inviting the replacement themselves — but only if they
 * are told, and being told is exactly the step that does not happen. So the
 * outgoing person can do it directly: they name their replacement, that person
 * gets a link, and accepting joins them to the organisation.
 *
 * ── Why accepting is not a signed-in action ─────────────────────────────────
 * Whoever accepts is a different person from whoever sent it, and usually has
 * no account at all until the moment they click. So the accept handler runs
 * before every signed-in branch of the dispatcher, and the token is what
 * authenticates — which is why it is single use, short lived, and bound to the
 * exact address it was sent to.
 *
 * ── What a handoff cannot do ────────────────────────────────────────────────
 * It cannot remove the outgoing person. Somebody mistyping an address, or
 * being talked into "confirming" a handoff by a stranger, must not be able to
 * lock their own organisation out of its own entries. Accepting ADDS a member;
 * removing one stays a staff action in wp-admin, where somebody can see who
 * they are removing.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** Post meta, single: a handoff invitation in flight. */
const GWC_PP_HANDOFF_META = '_gwc_pp_handoff';

/** How long an invitation lasts. Longer than a sign-in link, because the
 *  recipient is not sitting waiting for it, and shorter than a review link,
 *  because it grants access rather than merely restoring it.
 */
const GWC_PP_HANDOFF_TTL = 3 * DAY_IN_SECONDS;

/**
 * The invitation waiting on a post, or null.
 *
 * @param int $post_id Post ID.
 * @return array{email:string,hash:string,by:int,time:int,expires:int}|null
 */
function gwc_pp_get_handoff( int $post_id ): ?array {
	$stored = get_post_meta( $post_id, GWC_PP_HANDOFF_META, true );

	if ( ! is_array( $stored ) || empty( $stored['hash'] ) || empty( $stored['email'] ) ) {
		return null;
	}

	if ( (int) ( $stored['expires'] ?? 0 ) < time() ) {
		return null;
	}

	return array(
		'email'   => (string) $stored['email'],
		'hash'    => (string) $stored['hash'],
		'by'      => (int) ( $stored['by'] ?? 0 ),
		'time'    => (int) ( $stored['time'] ?? 0 ),
		'expires' => (int) ( $stored['expires'] ?? 0 ),
	);
}

/**
 * Invite somebody to take over.
 *
 * @param int    $post_id Post ID.
 * @param int    $by      Who is handing over.
 * @param string $email   Their replacement's address.
 * @return true|WP_Error
 */
function gwc_pp_send_handoff( int $post_id, int $by, string $email ) {
	$email = sanitize_email( trim( $email ) );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'gwc_pp_handoff_email', __( 'That does not look like an email address.', 'groundwork-common-post-portal' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'gwc_pp_handoff_post', __( 'That entry no longer exists.', 'groundwork-common-post-portal' ) );
	}

	/*
	 * Re-checked here rather than left to gwc_pp_render_handoff_panel(), which is
	 * the only other place it is asked. A handler that relies on the renderer
	 * having declined to draw a control can be replayed from a form that was open
	 * when the setting was still on — and of everything a portal user can do,
	 * this is the one that ends with a WordPress account being created.
	 */
	if ( ! gwc_pp_type_setting( $post->post_type, 'allow_handoff' ) ) {
		return new WP_Error(
			'gwc_pp_handoff_off',
			__( 'Handing entries over is not available here.', 'groundwork-common-post-portal' )
		);
	}

	$org = gwc_pp_post_org( $post_id );
	if ( $org <= 0 ) {
		return new WP_Error(
			'gwc_pp_handoff_org',
			__( 'This entry is not part of an organisation yet, so there is nothing to hand over. Please ask us to sort that out first.', 'groundwork-common-post-portal' )
		);
	}

	/*
	 * ── Only a member of the organisation may invite into it ────────────────
	 * Accepting an invitation calls gwc_pp_grant_access( $org, … ), which joins
	 * the new account to the whole organisation — every post it owns, not the
	 * one post the invitation was sent from.
	 *
	 * Access to that one post, though, can come from three places: membership of
	 * the organisation, a direct grant on the post, or being its author. The
	 * last two say nothing about the organisation. Without this check, somebody
	 * given one post to edit could add an account of their choosing to an
	 * organisation holding hundreds — a wider grant than the person making it
	 * has themselves, which is the shape of a privilege escalation whatever the
	 * intent behind it.
	 *
	 * So the two questions are separated: gwc_pp_guard_post() decided they may
	 * edit this post, and this decides they may speak for its organisation.
	 * Staff hand over on somebody's behalf from the organisation screen in
	 * wp-admin, which is unaffected.
	 */
	if ( ! in_array( $org, gwc_pp_user_orgs( $by ), true ) ) {
		return new WP_Error(
			'gwc_pp_handoff_member',
			__( 'Only somebody who belongs to this organisation can hand its entries over. Please ask us to do it for you.', 'groundwork-common-post-portal' )
		);
	}

	/*
	 * Refused for the same reason gwc_pp_grant_access() refuses it: an address
	 * that already belongs to somebody with another role on this site must not
	 * be quietly turned into a portal account by a person who is not staff.
	 */
	$existing = get_user_by( 'email', $email );
	if ( $existing instanceof WP_User && ! gwc_pp_user_is_portal_user( $existing->ID ) ) {
		return new WP_Error(
			'gwc_pp_handoff_existing',
			__( 'That address already has an account here that we cannot add automatically. Please ask us to set it up instead.', 'groundwork-common-post-portal' )
		);
	}

	$token = bin2hex( random_bytes( 32 ) );

	update_post_meta(
		$post_id,
		GWC_PP_HANDOFF_META,
		wp_slash(
			array(
				'email'   => $email,
				// Hashed, like every other token in this plugin: the database
				// should hold something that cannot be used to accept.
				'hash'    => hash( 'sha256', $token ),
				'by'      => $by,
				'time'    => time(),
				'expires' => time() + GWC_PP_HANDOFF_TTL,
			)
		)
	);

	$sent = gwc_pp_mail_handoff_invite( $post_id, $by, $email, $token );

	if ( ! $sent ) {
		delete_post_meta( $post_id, GWC_PP_HANDOFF_META );
		return new WP_Error(
			'gwc_pp_handoff_mail',
			__( 'We could not send that invitation. Please try again, or let us know.', 'groundwork-common-post-portal' )
		);
	}

	gwc_pp_mail_handoff_staff( $post_id, $by, $email );

	return true;
}

/**
 * Accept an invitation.
 *
 * @param int    $post_id Post ID.
 * @param string $token   Token from the URL.
 * @return int|WP_Error The new user's ID.
 */
function gwc_pp_accept_handoff( int $post_id, string $token ) {
	$handoff = gwc_pp_get_handoff( $post_id );

	if ( null === $handoff ) {
		return new WP_Error( 'gwc_pp_handoff_gone', __( 'That invitation has expired or has already been used.', 'groundwork-common-post-portal' ) );
	}

	/*
	 * hash_equals, not ===. This compares a secret against attacker-supplied
	 * input, and a timing-variable comparison on a hash is the textbook place
	 * to leak it one byte at a time.
	 */
	if ( ! hash_equals( $handoff['hash'], hash( 'sha256', $token ) ) ) {
		return new WP_Error( 'gwc_pp_handoff_gone', __( 'That invitation has expired or has already been used.', 'groundwork-common-post-portal' ) );
	}

	$org = gwc_pp_post_org( $post_id );
	if ( $org <= 0 ) {
		delete_post_meta( $post_id, GWC_PP_HANDOFF_META );
		return new WP_Error( 'gwc_pp_handoff_org', __( 'That invitation is no longer valid.', 'groundwork-common-post-portal' ) );
	}

	// Single use, before anything else can fail.
	delete_post_meta( $post_id, GWC_PP_HANDOFF_META );

	$user_id = gwc_pp_grant_access( $org, $handoff['email'] );

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	gwc_pp_mail_handoff_done( $post_id, $handoff['by'], (int) $user_id );

	/**
	 * Fires when somebody accepts a handoff.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id The new member.
	 * @param int $by      Who invited them.
	 */
	do_action( 'gwc_pp_handoff_accepted', $post_id, (int) $user_id, $handoff['by'] );

	return (int) $user_id;
}

/* ── Handlers ────────────────────────────────────────────────────────────── */

/**
 * Send an invitation from the portal.
 */
function gwc_pp_handle_handoff_request(): void {
	$post_id = gwc_pp_guard_post( 'gwc_pp_handoff_nonce', 'gwc_pp_handoff_' );
	$user_id = get_current_user_id();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the guard above.
	$email = isset( $_POST['gwc_pp_handoff_email'] ) ? sanitize_email( wp_unslash( $_POST['gwc_pp_handoff_email'] ) ) : '';

	/*
	 * Two emails go out per submission, one of them to an address the submitter
	 * typed. Unthrottled that is a way to send mail from this site's domain to
	 * anybody, repeatedly, which costs the site its sending reputation and
	 * whoever is on the receiving end their patience.
	 *
	 * Counted against the person, not the address they typed: changing the
	 * address is free, and being somebody else is not.
	 */
	if ( gwc_pp_handoff_rate_limited( $user_id ) ) {
		gwc_pp_bail(
			gwc_pp_portal_url(
				array(
					'gwc_pp_view' => 'edit',
					'gwc_pp_post' => $post_id,
				)
			),
			__( 'That is several invitations in a short time. Please give it a little while, or let us know if something is stuck.', 'groundwork-common-post-portal' ),
			'warn'
		);
	}

	$result = gwc_pp_send_handoff( $post_id, $user_id, $email );

	$url = gwc_pp_portal_url(
		array(
			'gwc_pp_view' => 'edit',
			'gwc_pp_post' => $post_id,
		)
	);

	if ( is_wp_error( $result ) ) {
		gwc_pp_bail( $url, $result->get_error_message(), 'error' );
	}

	gwc_pp_bail(
		$url,
		__( 'Invitation sent. They have three days to accept it, and you will keep your own access either way.', 'groundwork-common-post-portal' ),
		'ok'
	);
}

/**
 * Withdraw an invitation.
 */
function gwc_pp_handle_handoff_cancel(): void {
	$post_id = gwc_pp_guard_post( 'gwc_pp_handoff_cancel_nonce', 'gwc_pp_handoff_cancel_' );

	delete_post_meta( $post_id, GWC_PP_HANDOFF_META );

	gwc_pp_bail(
		gwc_pp_portal_url(
			array(
				'gwc_pp_view' => 'edit',
				'gwc_pp_post' => $post_id,
			)
		),
		__( 'That invitation has been withdrawn.', 'groundwork-common-post-portal' ),
		'ok'
	);
}

/**
 * Handle an invitation link arriving.
 *
 * Runs before every signed-in check in the dispatcher, because whoever is
 * clicking usually has no account yet.
 */
function gwc_pp_handle_handoff_link(): void {
	if ( gwc_pp_request_is_automated() ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A single-use token in the URL is the authentication here; whoever accepts a handoff has no account yet, let alone a session to mint a nonce against.
	$token   = isset( $_GET['gwc_pp_handoff_token'] ) ? sanitize_text_field( wp_unslash( $_GET['gwc_pp_handoff_token'] ) ) : '';
	$post_id = isset( $_GET['gwc_pp_post'] ) ? (int) $_GET['gwc_pp_post'] : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) || $post_id <= 0 ) {
		gwc_pp_bail( gwc_pp_portal_url(), __( 'That invitation link is not valid.', 'groundwork-common-post-portal' ), 'warn' );
	}

	$user_id = gwc_pp_accept_handoff( $post_id, $token );

	if ( is_wp_error( $user_id ) ) {
		gwc_pp_bail( gwc_pp_portal_url(), $user_id->get_error_message(), 'warn' );
	}

	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		gwc_pp_bail();
	}

	/*
	 * Signed straight in. They have just proved they hold a token sent to their
	 * address, which is the same proof a magic link gives — asking them to now
	 * request a sign-in link would be asking for the same thing twice.
	 */
	wp_set_auth_cookie( (int) $user_id, false );
	wp_set_current_user( (int) $user_id );
	do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, fired on purpose: see the note above.

	wp_safe_redirect(
		gwc_pp_flash_url(
			gwc_pp_portal_url(),
			'ok',
			__( 'Welcome. You now have access to this organisation\'s entries.', 'groundwork-common-post-portal' )
		)
	);
	exit;
}

/* ── The panel ───────────────────────────────────────────────────────────── */

/**
 * "Somebody else is taking this over" on the edit view.
 *
 * @param WP_Post $post    The post.
 * @param int     $user_id The signed-in user.
 */
function gwc_pp_render_handoff_panel( WP_Post $post, int $user_id ): void {
	if ( ! gwc_pp_type_setting( $post->post_type, 'allow_handoff' ) ) {
		return;
	}

	$org = gwc_pp_post_org( $post->ID );
	if ( $org <= 0 ) {
		return;
	}

	/*
	 * Not shown to somebody who reached this post by a direct grant or by
	 * authorship rather than through the organisation — gwc_pp_send_handoff()
	 * would refuse them, and a form that always fails is worse than no form.
	 * The refusal there is still the thing that decides it; this only keeps the
	 * page honest.
	 */
	if ( ! in_array( $org, gwc_pp_user_orgs( $user_id ), true ) ) {
		return;
	}

	$pending = gwc_pp_get_handoff( $post->ID );

	echo '<div class="gwcpp-handoff">';
	printf( '<h2 class="gwcpp-handoff__title">%s</h2>', esc_html__( 'Somebody else looking after this?', 'groundwork-common-post-portal' ) );

	if ( null !== $pending ) {
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: an email address, 2: a length of time. */
					__( 'An invitation is waiting for %1$s. It expires in %2$s.', 'groundwork-common-post-portal' ),
					$pending['email'],
					human_time_diff( time(), $pending['expires'] )
				)
			)
		);

		echo '<form method="post" action="' . esc_url( gwc_pp_portal_url() ) . '">';
		wp_nonce_field( 'gwc_pp_handoff_cancel_' . $post->ID, 'gwc_pp_handoff_cancel_nonce' );
		printf( '<input type="hidden" name="gwc_pp_post_id" value="%d" />', (int) $post->ID );
		printf(
			'<button type="submit" name="gwc_pp_handoff_cancel" value="1" class="gwcpp-button gwcpp-button--quiet">%s</button>',
			esc_html__( 'Withdraw it', 'groundwork-common-post-portal' )
		);
		echo '</form></div>';
		return;
	}

	printf(
		'<p>%s</p>',
		esc_html__( 'If somebody else is taking over, send them an invitation and they will be able to sign in and keep this up to date. You keep your own access, so nothing is lost if they never accept.', 'groundwork-common-post-portal' )
	);

	echo '<form method="post" action="' . esc_url( gwc_pp_portal_url() ) . '">';
	wp_nonce_field( 'gwc_pp_handoff_' . $post->ID, 'gwc_pp_handoff_nonce' );
	printf( '<input type="hidden" name="gwc_pp_post_id" value="%d" />', (int) $post->ID );

	printf(
		'<div class="gwcpp-field"><label class="gwcpp-label" for="gwcpp-handoff-%1$d">%2$s</label><input class="gwcpp-input" type="email" id="gwcpp-handoff-%1$d" name="gwc_pp_handoff_email" required /></div>',
		(int) $post->ID,
		esc_html__( 'Their email address', 'groundwork-common-post-portal' )
	);

	printf(
		'<button type="submit" name="gwc_pp_handoff" value="1" class="gwcpp-button">%s</button>',
		esc_html__( 'Send them an invitation', 'groundwork-common-post-portal' )
	);

	echo '</form></div>';
}

/* ── Emails ──────────────────────────────────────────────────────────────── */

/**
 * The invitation itself.
 *
 * @param int    $post_id Post ID.
 * @param int    $by      Who is handing over.
 * @param string $email   Recipient.
 * @param string $token   The raw token.
 * @return bool
 */
function gwc_pp_mail_handoff_invite( int $post_id, int $by, string $email, string $token ): bool {
	$from  = get_userdata( $by );
	$title = get_the_title( $post_id );

	$url = gwc_pp_portal_url(
		array(
			'gwc_pp_handoff_token' => $token,
			'gwc_pp_post'          => $post_id,
		)
	);

	$body = gwc_pp_email_p(
		sprintf(
			/* translators: 1: an email address, 2: a post title, 3: the site name. */
			__( '%1$s has asked us to give you access to %2$s on %3$s, so you can keep its details up to date.', 'groundwork-common-post-portal' ),
			$from ? $from->user_email : __( 'Somebody', 'groundwork-common-post-portal' ),
			'' !== trim( (string) $title ) ? $title : __( 'an entry', 'groundwork-common-post-portal' ),
			get_bloginfo( 'name' )
		)
	)
		. gwc_pp_email_button( $url, __( 'Accept and sign in', 'groundwork-common-post-portal' ) )
		. gwc_pp_email_raw_link( $url )
		. gwc_pp_email_p( __( 'The link works once and expires in three days. If you were not expecting this, you can ignore it — nothing happens unless you accept.', 'groundwork-common-post-portal' ) );

	return gwc_pp_send_email(
		$email,
		sprintf(
			/* translators: %s: the site name. */
			__( 'You have been asked to look after an entry on %s', 'groundwork-common-post-portal' ),
			get_bloginfo( 'name' )
		),
		gwc_pp_email_shell( __( 'An invitation', 'groundwork-common-post-portal' ), $body )
	);
}

/**
 * Tell staff an invitation went out.
 *
 * Worth sending even though nothing has happened yet: this is the one action in
 * the plugin where a portal user causes an account to be created, and staff
 * finding out only afterwards is how it becomes a surprise.
 *
 * @param int    $post_id Post ID.
 * @param int    $by      Who sent it.
 * @param string $email   Who it went to.
 * @return bool
 */
function gwc_pp_mail_handoff_staff( int $post_id, int $by, string $email ): bool {
	$from = get_userdata( $by );

	return gwc_pp_send_email(
		gwc_pp_staff_email(),
		__( 'Somebody invited a replacement', 'groundwork-common-post-portal' ),
		gwc_pp_email_shell(
			__( 'A handover is in progress', 'groundwork-common-post-portal' ),
			gwc_pp_email_p(
				sprintf(
					/* translators: 1: an email address, 2: another email address, 3: a post title. */
					__( '%1$s has invited %2$s to take over %3$s. If they accept, they will join the same organisation. Nobody has been removed.', 'groundwork-common-post-portal' ),
					$from ? $from->user_email : __( 'Somebody', 'groundwork-common-post-portal' ),
					$email,
					get_the_title( $post_id )
				)
			)
			. gwc_pp_email_button( (string) get_edit_post_link( $post_id, 'raw' ), __( 'Open the entry', 'groundwork-common-post-portal' ) )
		)
	);
}

/**
 * Tell the outgoing person it was accepted.
 *
 * @param int $post_id Post ID.
 * @param int $by      Who handed over.
 * @param int $user_id Who accepted.
 * @return bool
 */
function gwc_pp_mail_handoff_done( int $post_id, int $by, int $user_id ): bool {
	$from = get_userdata( $by );
	$new  = get_userdata( $user_id );

	gwc_pp_send_email(
		gwc_pp_staff_email(),
		__( 'A handover was accepted', 'groundwork-common-post-portal' ),
		gwc_pp_email_shell(
			__( 'A handover was accepted', 'groundwork-common-post-portal' ),
			gwc_pp_email_p(
				sprintf(
					/* translators: 1: an email address, 2: a post title. */
					__( '%1$s now has access to the organisation that owns %2$s. Whoever invited them still has access too — remove them yourself if that is the intention.', 'groundwork-common-post-portal' ),
					$new ? $new->user_email : __( 'Somebody', 'groundwork-common-post-portal' ),
					get_the_title( $post_id )
				)
			)
		)
	);

	if ( ! $from ) {
		return false;
	}

	return gwc_pp_send_email(
		$from->user_email,
		__( 'Your handover was accepted', 'groundwork-common-post-portal' ),
		gwc_pp_email_shell(
			__( 'Thank you', 'groundwork-common-post-portal' ),
			gwc_pp_email_p(
				sprintf(
					/* translators: %s: an email address. */
					__( '%s has accepted your invitation and can now keep things up to date. Thank you for handing it on rather than letting it go stale.', 'groundwork-common-post-portal' ),
					$new ? $new->user_email : __( 'They', 'groundwork-common-post-portal' )
				)
			)
			. gwc_pp_email_p( __( 'You still have access yourself. If you would rather not, let us know and we will remove it.', 'groundwork-common-post-portal' ) )
		)
	);
}
