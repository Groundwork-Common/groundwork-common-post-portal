<?php
/**
 * Outbound email: the shell, and the guard that keeps staging quiet.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── The guard, and why it is on wp_mail ─────────────────────────────────────
 * A staging copy of a site is a full copy: the same partners, the same
 * addresses, the same cron. Point it at a real mail server and the first time
 * somebody tests the review reminders, two hundred real organisations get an
 * email telling them their listing is about to be hidden.
 *
 * So outbound mail has three modes, set by a constant in wp-config.php:
 *
 *   define( 'GWC_PP_MAIL_MODE', 'live' );          // live | restricted | off
 *   define( 'GWC_PP_MAIL_ALLOW', 'example.com' );  // domain or address, comma separated
 *
 * ── The default is `live`, and that is a decision, not an oversight ──────────
 * A site that defines neither constant sends normally. There is no host
 * detection here and there is not going to be: this plugin cannot tell that a
 * given hostname is somebody's staging server, and a guess that gets it wrong
 * either silently swallows a production site's mail or lulls a staging site
 * into thinking it is protected. Both are worse than doing nothing.
 *
 * So protecting a staging copy is one line in its wp-config.php, and it is a
 * line somebody has to write. Setting `GWC_PP_MAIL_MODE` to `restricted` or
 * `off` is the first thing to do when cloning a site that has real partner
 * addresses in it.
 *
 * ── Why the filter is on wp_mail and not on this plugin's own send ───────────
 * Hooking our own function would guard our own mail and let a stray wp_mail()
 * from a handler, a plugin, or a copied snippet straight through. The point of
 * a guard is that it cannot be walked around by accident.
 *
 * The consequence is worth being explicit about, because it is broader than
 * this plugin: in `restricted` or `off` mode this drops or narrows EVERY
 * outgoing email on the site, including password resets and other plugins'
 * notifications. That is the intent — a staging clone should be quiet, not
 * selectively quiet — and it is why `live` passes straight through with no
 * processing at all.
 * ───────────────────────────────────────────────────────────────────────────
 */

add_filter( 'wp_mail', 'gwc_pp_guard_outbound_mail', 1 );

/**
 * Drop or narrow outbound mail according to the configured mode.
 *
 * @param array $args wp_mail() arguments.
 * @return array
 */
function gwc_pp_guard_outbound_mail( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	// Defaults to live. See the note above for why there is no host sniffing.
	$mode = defined( 'GWC_PP_MAIL_MODE' ) ? (string) GWC_PP_MAIL_MODE : 'live';

	if ( 'live' === $mode ) {
		return $args;
	}

	if ( 'off' === $mode ) {
		// An empty recipient makes wp_mail return false without sending. There
		// is no "cancel" filter, and this is the documented way to do it.
		$args['to'] = array();
		return $args;
	}

	$allow = defined( 'GWC_PP_MAIL_ALLOW' ) ? (string) GWC_PP_MAIL_ALLOW : '';
	$allow = array_filter( array_map( 'trim', explode( ',', strtolower( $allow ) ) ) );

	$to   = is_array( $args['to'] ) ? $args['to'] : array_map( 'trim', explode( ',', (string) $args['to'] ) );
	$kept = array();

	foreach ( $to as $address ) {
		$address = strtolower( trim( (string) $address ) );
		if ( '' === $address ) {
			continue;
		}
		$domain = (string) substr( (string) strrchr( $address, '@' ), 1 );
		if ( in_array( $address, $allow, true ) || ( '' !== $domain && in_array( $domain, $allow, true ) ) ) {
			$kept[] = $address;
		}
	}

	$args['to'] = $kept;

	/*
	 * Cc and Bcc are stripped rather than filtered. They arrive as headers in a
	 * shape that varies — a string, an array, folded lines — and a guard that
	 * parses them almost correctly is a guard that lets one through. Nothing in
	 * this plugin sets either, so removing them costs nothing here and closes
	 * the hole for anything that does.
	 */
	if ( ! empty( $args['headers'] ) ) {
		$headers         = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", (string) $args['headers'] );
		$args['headers'] = array_values(
			array_filter(
				$headers,
				static function ( $header ) {
					return ! preg_match( '/^\s*(cc|bcc)\s*:/i', (string) $header );
				}
			)
		);
	}

	return $args;
}

/**
 * Send one email from the portal.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject line.
 * @param string $body    HTML body, already assembled by gwc_pp_email_shell().
 * @return bool
 */
function gwc_pp_send_email( string $to, string $subject, string $body ): bool {
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$from_name  = (string) gwc_pp_setting( 'from_name' );
	$from_email = (string) gwc_pp_setting( 'from_email' );

	if ( '' !== $from_email && is_email( $from_email ) ) {
		$name = '' !== $from_name ? $from_name : get_bloginfo( 'name' );

		/*
		 * Quoted, with any quote of its own removed. CRLF and angle brackets are
		 * already gone — the setting is sanitized with sanitize_text_field when
		 * it is saved, so header injection is closed before this runs — but a
		 * perfectly ordinary name containing a comma ("Smith, Jones & Co") is a
		 * malformed From header unquoted, and some receivers drop the message
		 * rather than guess.
		 */
		$headers[] = sprintf( 'From: "%s" <%s>', str_replace( '"', '', $name ), $from_email );
	}

	return wp_mail( $to, $subject, $body, $headers );
}

/**
 * Wrap body content in the email shell.
 *
 * Tables and inline styles, because that is what mail clients render
 * predictably. This is not a place to be modern.
 *
 * @param string $heading Top-line heading.
 * @param string $content Body HTML, produced by the helpers below.
 * @return string
 */
function gwc_pp_email_shell( string $heading, string $content ): string {
	$accent = (string) gwc_pp_setting( 'accent_color' );
	$accent = '' !== $accent ? $accent : '#2b6cb0';

	return sprintf(
		'<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f5;">
			<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
				<tr><td align="center">
					<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;padding:32px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2933;">
						<tr><td>
							<h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:%1$s;">%2$s</h1>
							%3$s
							<p style="margin:32px 0 0;font-size:13px;color:#7b8794;">%4$s</p>
						</td></tr>
					</table>
				</td></tr>
			</table>
		</body></html>',
		esc_attr( $accent ),
		esc_html( $heading ),
		$content,
		esc_html( get_bloginfo( 'name' ) )
	);
}

/**
 * A paragraph.
 *
 * @param string $text Plain text.
 * @return string
 */
function gwc_pp_email_p( string $text ): string {
	return sprintf(
		'<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">%s</p>',
		esc_html( $text )
	);
}

/**
 * A button.
 *
 * @param string $url   Destination.
 * @param string $label Button text.
 * @return string
 */
function gwc_pp_email_button( string $url, string $label ): string {
	$accent = (string) gwc_pp_setting( 'accent_color' );
	$accent = '' !== $accent ? $accent : '#2b6cb0';

	return sprintf(
		'<p style="margin:0 0 16px;"><a href="%1$s" style="display:inline-block;background:%2$s;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:600;">%3$s</a></p>',
		esc_url( $url ),
		esc_attr( $accent ),
		esc_html( $label )
	);
}

/**
 * An old-against-new table.
 *
 * Both columns are shown even when the old value was empty, with a dash
 * standing in. "Phone: 205 555 0142" tells staff a phone number was set;
 * "Phone: — → 205 555 0142" tells them it was not there before, which is the
 * difference between a correction and a gap being filled.
 *
 * @param array $diff From gwc_pp_changeset_diff().
 * @return string
 */
function gwc_pp_email_diff( array $diff ): string {
	if ( ! $diff ) {
		return gwc_pp_email_p( __( 'Nothing actually changed.', 'groundwork-common-post-portal' ) );
	}

	$rows = '';
	foreach ( $diff as $row ) {
		$old = '' !== trim( (string) $row['old'] ) ? (string) $row['old'] : '—';
		$new = '' !== trim( (string) $row['new'] ) ? (string) $row['new'] : '—';

		$rows .= sprintf(
			'<tr>
				<td style="padding:8px 12px 8px 0;vertical-align:top;font-size:14px;font-weight:600;white-space:nowrap;">%1$s</td>
				<td style="padding:8px 12px 8px 0;vertical-align:top;font-size:14px;color:#7b8794;text-decoration:line-through;">%2$s</td>
				<td style="padding:8px 0;vertical-align:top;font-size:14px;font-weight:600;">%3$s</td>
			</tr>',
			esc_html( (string) $row['label'] ),
			esc_html( $old ),
			esc_html( $new )
		);
	}

	return sprintf(
		'<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%%;border-top:1px solid #e4e7eb;border-bottom:1px solid #e4e7eb;margin:0 0 16px;">%s</table>',
		$rows
	);
}

/**
 * The same URL again as selectable text.
 *
 * Every one of these emails is a button whose entire purpose is the link
 * behind it, and a meaningful number of recipients read mail somewhere the
 * button does not render or does not click through — a text-only client, a
 * corporate gateway that rewrites HTML, a forwarded copy. Printing the URL
 * costs three lines and is the difference between "it does not work" and "I
 * pasted the link".
 *
 * @param string $url Destination.
 * @return string
 */
function gwc_pp_email_raw_link( string $url ): string {
	return sprintf(
		'<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#52606d;">%s<br /><span style="word-break:break-all;">%s</span></p>',
		esc_html__( 'If the button does not work, copy this into your browser:', 'groundwork-common-post-portal' ),
		esc_html( $url )
	);
}

/*
 * ── Notifications about changes ─────────────────────────────────────────────
 * Three messages, and the split between them is deliberate. Staff hear about
 * every submission; the submitter hears only about a decision. Telling somebody
 * "we received your change" and then, a day later, "we applied your change" is
 * two emails for one event, and the first is the one people learn to ignore.
 * ───────────────────────────────────────────────────────────────────────────
 */

/*
 * ── Why the staff notification's return value is not discarded ──────────────
 * Every other failure in this plugin is visible to somebody. This one is not:
 * the person who submitted the change is told it went for review — which is
 * true, it did — and staff are told nothing, because the telling is the part
 * that failed. A site with a broken SMTP configuration therefore has a review
 * queue quietly filling up that nobody has been asked to look at, and the first
 * report of it is a partner asking why their change is still not live weeks
 * later.
 *
 * So a failure is recorded rather than dropped. gwc_pp_note_staff_notification()
 * is what the handlers call; it keeps a flag the plugin's own screens can show,
 * and fires an action for sites that would rather send this somewhere real.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** Transient: set when the last attempt to notify staff failed. */
const GWC_PP_MAIL_TROUBLE_TRANSIENT = 'gwc_pp_staff_mail_failed';

/**
 * Record whether staff were successfully told about a change.
 *
 * @param bool $sent What gwc_pp_notify_staff_change() returned.
 */
function gwc_pp_note_staff_notification( bool $sent ): void {
	if ( $sent ) {
		delete_transient( GWC_PP_MAIL_TROUBLE_TRANSIENT );
		return;
	}

	/*
	 * A week, not forever. If mail starts working again the flag clears on the
	 * next successful send, and if nothing is ever submitted again there is
	 * nothing left to warn about anyway.
	 */
	set_transient( GWC_PP_MAIL_TROUBLE_TRANSIENT, time(), WEEK_IN_SECONDS );

	/**
	 * Fires when this site could not tell staff about a submitted change.
	 *
	 * Worth hooking on any site where the review queue matters — wp_mail()
	 * returning false means nobody has been asked to look at it.
	 */
	do_action( 'gwc_pp_staff_notification_failed' );
}

/**
 * True when the last attempt to notify staff failed.
 *
 * @return bool
 */
function gwc_pp_staff_mail_in_trouble(): bool {
	return false !== get_transient( GWC_PP_MAIL_TROUBLE_TRANSIENT );
}

/**
 * Tell staff about a submission.
 *
 * Callers pass the result to gwc_pp_note_staff_notification() rather than
 * dropping it — see the note above.
 *
 * @param int   $post_id Post ID.
 * @param int   $user_id Who submitted it.
 * @param array $diff    From gwc_pp_changeset_diff().
 * @param bool  $pending Whether it is waiting for approval or already live.
 * @return bool
 */
function gwc_pp_notify_staff_change( int $post_id, int $user_id, array $diff, bool $pending ): bool {
	if ( ! $diff ) {
		return false;
	}

	$post = get_post( $post_id );
	$who  = get_userdata( $user_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	$name  = $who ? $who->user_email : __( 'somebody', 'groundwork-common-post-portal' );
	$title = '' !== trim( (string) $post->post_title ) ? $post->post_title : __( '(no title)', 'groundwork-common-post-portal' );

	$heading = $pending
		? __( 'A change is waiting for you', 'groundwork-common-post-portal' )
		: __( 'A change was made', 'groundwork-common-post-portal' );

	$intro = $pending
		/* translators: 1: an email address, 2: a post title. */
		? __( '%1$s submitted a change to %2$s. It is not on the site yet.', 'groundwork-common-post-portal' )
		/* translators: 1: an email address, 2: a post title. */
		: __( '%1$s changed %2$s. It is live now.', 'groundwork-common-post-portal' );

	$body = gwc_pp_email_p( sprintf( $intro, $name, $title ) )
		. gwc_pp_email_diff( $diff );

	if ( $pending ) {
		$url   = admin_url( 'admin.php?page=' . GWC_PP_QUEUE_SLUG );
		$body .= gwc_pp_email_button( $url, __( 'Review it', 'groundwork-common-post-portal' ) )
			. gwc_pp_email_raw_link( $url );
	} else {
		$edit  = (string) get_edit_post_link( $post_id, 'raw' );
		$body .= gwc_pp_email_button( $edit, __( 'Open it', 'groundwork-common-post-portal' ) );
	}

	return gwc_pp_send_email(
		gwc_pp_staff_email(),
		sprintf(
			$pending
				/* translators: %s: a post title. */
				? __( 'Waiting for review: %s', 'groundwork-common-post-portal' )
				/* translators: %s: a post title. */
				: __( 'Changed: %s', 'groundwork-common-post-portal' ),
			$title
		),
		gwc_pp_email_shell( $heading, $body )
	);
}

/**
 * Tell the submitter their change went live.
 *
 * @param int $post_id Post ID.
 * @param int $user_id Who submitted it.
 * @return bool
 */
function gwc_pp_notify_submitter_approved( int $post_id, int $user_id ): bool {
	$who  = get_userdata( $user_id );
	$post = get_post( $post_id );
	if ( ! $who || ! $post instanceof WP_Post ) {
		return false;
	}

	$title = '' !== trim( (string) $post->post_title ) ? $post->post_title : __( 'your entry', 'groundwork-common-post-portal' );

	return gwc_pp_send_email(
		$who->user_email,
		/* translators: %s: a post title. */
		sprintf( __( 'Your changes to %s are live', 'groundwork-common-post-portal' ), $title ),
		gwc_pp_email_shell(
			__( 'Your changes are live', 'groundwork-common-post-portal' ),
			gwc_pp_email_p(
				sprintf(
					/* translators: %s: a post title. */
					__( 'The changes you submitted to %s have been approved and are on the site now. Thank you.', 'groundwork-common-post-portal' ),
					$title
				)
			)
			. gwc_pp_email_button( gwc_pp_portal_url(), __( 'Open the portal', 'groundwork-common-post-portal' ) )
		)
	);
}

/**
 * Tell the submitter their change was not applied.
 *
 * The note from staff is the whole point of this email. Without one it says
 * "no" and nothing else, which leaves somebody to guess what to change and
 * resubmit the same thing.
 *
 * @param int    $post_id Post ID.
 * @param int    $user_id Who submitted it.
 * @param string $note    Message from staff.
 * @return bool
 */
function gwc_pp_notify_submitter_rejected( int $post_id, int $user_id, string $note = '' ): bool {
	$who  = get_userdata( $user_id );
	$post = get_post( $post_id );
	if ( ! $who || ! $post instanceof WP_Post ) {
		return false;
	}

	$title = '' !== trim( (string) $post->post_title ) ? $post->post_title : __( 'your entry', 'groundwork-common-post-portal' );

	$body = gwc_pp_email_p(
		sprintf(
			/* translators: %s: a post title. */
			__( 'The changes you submitted to %s have not been applied, and the entry is unchanged.', 'groundwork-common-post-portal' ),
			$title
		)
	);

	if ( '' !== trim( $note ) ) {
		$body .= gwc_pp_email_p( __( 'What we were told:', 'groundwork-common-post-portal' ) )
			. sprintf(
				'<blockquote style="margin:0 0 16px;padding:12px 16px;border-left:3px solid #cbd2d9;background:#f5f7fa;font-size:15px;line-height:1.6;">%s</blockquote>',
				esc_html( $note )
			);
	}

	$body .= gwc_pp_email_p( __( 'You can edit it again in the portal whenever you like.', 'groundwork-common-post-portal' ) )
		. gwc_pp_email_button( gwc_pp_portal_url(), __( 'Open the portal', 'groundwork-common-post-portal' ) );

	return gwc_pp_send_email(
		$who->user_email,
		/* translators: %s: a post title. */
		sprintf( __( 'About your changes to %s', 'groundwork-common-post-portal' ), $title ),
		gwc_pp_email_shell( __( 'Your changes were not applied', 'groundwork-common-post-portal' ), $body )
	);
}
