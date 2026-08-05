<?php
/**
 * Plugin Name: Dev mail routing (Mailpit)
 * Description: Local development only. Routes outbound mail to a Mailpit sink so sign-in links can be read in a browser. Never ships: tests/ is excluded by .distignore. See README.md, "Seeing the emails".
 *
 *   docker run -d --name gwcpp-mailpit -p 8027:8025 -p 1027:1025 axllent/mailpit
 *
 * Inbox at http://localhost:8027. Ports 8027/1027 rather than Mailpit's own,
 * because ddev-router already holds 8025 on this machine.
 */

defined( 'ABSPATH' ) || exit;

/* wp-env's default From is wordpress@localhost, which PHPMailer refuses because
 * it has no TLD — wp_mail() returns false before a byte reaches SMTP. That is
 * the real reason mail looks broken under wp-env. */
add_filter( 'wp_mail_from', static fn() => 'portal@example.test' );
add_filter( 'wp_mail_from_name', static fn() => 'Post Portal (dev)' );

add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$phpmailer->isSMTP();
		// Not localhost: this runs inside the container, where localhost is the
		// container itself.
		$phpmailer->Host        = 'host.docker.internal';
		$phpmailer->Port        = 1027;
		$phpmailer->SMTPAuth    = false;
		// Mailpit speaks plain SMTP; SMTPAutoTLS would try STARTTLS and fail.
		$phpmailer->SMTPAutoTLS = false;
		$phpmailer->SMTPSecure  = '';
	}
);
