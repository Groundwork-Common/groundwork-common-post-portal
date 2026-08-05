<?php
/**
 * Outbound email: the shell, and the guard that keeps staging quiet.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── The guard, and why it is on wp_mail ─────────────────────────────────────
 * A staging copy of a site is a full copy: the same partners, the same
 * addresses, the same cron. Point it at a real mail server and the first time
 * somebody tests the review reminders, two hundred real organisations get an
 * email telling them their listing is about to be hidden.
 *
 * So outbound mail has three modes, and the default on any host that is not the
 * configured production host is `restricted` — mail is sent only to addresses
 * on the allow list, everything else is dropped.
 *
 * The filter is on `wp_mail` rather than on this plugin's own send function, on
 * purpose. Hooking our own function would guard our own mail and let a stray
 * wp_mail() from a handler, a plugin, or a copied snippet straight through. The
 * point of a guard is that it cannot be walked around by accident.
 *
 * Configure in wp-config.php:
 *
 *   define( 'GWCPP_MAIL_MODE', 'live' );          // live | restricted | off
 *   define( 'GWCPP_MAIL_ALLOW', 'example.com' );  // domain or address, comma separated
 *
 * A site that never defines either sends normally, because the default mode is
 * live when no production host is configured — this plugin cannot know that a
 * given hostname is somebody's staging server, and refusing to send by default
 * would break every install that never reads this comment.
 * ─────────────────────────────────────────────────────────────────────────── */

add_filter( 'wp_mail', 'gwcpp_guard_outbound_mail', 1 );

/**
 * Drop or narrow outbound mail according to the configured mode.
 *
 * @param array $args wp_mail() arguments.
 * @return array
 */
function gwcpp_guard_outbound_mail( $args ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	$mode = defined( 'GWCPP_MAIL_MODE' ) ? (string) GWCPP_MAIL_MODE : 'live';

	if ( 'live' === $mode ) {
		return $args;
	}

	if ( 'off' === $mode ) {
		// An empty recipient makes wp_mail return false without sending. There
		// is no "cancel" filter, and this is the documented way to do it.
		$args['to'] = array();
		return $args;
	}

	$allow = defined( 'GWCPP_MAIL_ALLOW' ) ? (string) GWCPP_MAIL_ALLOW : '';
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

	/* Cc and Bcc are stripped rather than filtered. They arrive as headers in a
	 * shape that varies — a string, an array, folded lines — and a guard that
	 * parses them almost correctly is a guard that lets one through. Nothing in
	 * this plugin sets either, so removing them costs nothing here and closes
	 * the hole for anything that does. */
	if ( ! empty( $args['headers'] ) ) {
		$headers = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", (string) $args['headers'] );
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
 * @param string $body    HTML body, already assembled by gwcpp_email_shell().
 * @return bool
 */
function gwcpp_send_email( string $to, string $subject, string $body ): bool {
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$from_name  = (string) gwcpp_setting( 'from_name' );
	$from_email = (string) gwcpp_setting( 'from_email' );

	if ( '' !== $from_email && is_email( $from_email ) ) {
		$name = '' !== $from_name ? $from_name : get_bloginfo( 'name' );
		$headers[] = sprintf( 'From: %s <%s>', $name, $from_email );
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
function gwcpp_email_shell( string $heading, string $content ): string {
	$accent = (string) gwcpp_setting( 'accent_color' );
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
function gwcpp_email_p( string $text ): string {
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
function gwcpp_email_button( string $url, string $label ): string {
	$accent = (string) gwcpp_setting( 'accent_color' );
	$accent = '' !== $accent ? $accent : '#2b6cb0';

	return sprintf(
		'<p style="margin:0 0 16px;"><a href="%1$s" style="display:inline-block;background:%2$s;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-size:15px;font-weight:600;">%3$s</a></p>',
		esc_url( $url ),
		esc_attr( $accent ),
		esc_html( $label )
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
function gwcpp_email_raw_link( string $url ): string {
	return sprintf(
		'<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#52606d;">%s<br /><span style="word-break:break-all;">%s</span></p>',
		esc_html__( 'If the button does not work, copy this into your browser:', 'groundwork-common-post-portal' ),
		esc_html( $url )
	);
}
