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

/*
 * Host and port come from constants so one committed file serves both
 * environments. Locally Mailpit runs on the host and is reached through
 * host.docker.internal; in CI it joins wp-env's own Docker network and answers
 * to the alias `mailpit` on the standard port. Before this, CI generated a
 * second copy of this file with different values — two things to keep in step,
 * and the CI one was invisible to anybody reading the repository.
 *
 * wp-env writes these into wp-config.php from the `config` key.
 */
add_action(
	'phpmailer_init',
	static function ( $phpmailer ): void {
		$phpmailer->isSMTP();
		// Not localhost: this runs inside the container, where localhost is the
		// container itself.
		$phpmailer->Host        = defined( 'GWCPP_MAILPIT_HOST' ) ? GWCPP_MAILPIT_HOST : 'host.docker.internal';
		$phpmailer->Port        = defined( 'GWCPP_MAILPIT_PORT' ) ? (int) GWCPP_MAILPIT_PORT : 1027;
		$phpmailer->SMTPAuth    = false;
		// Mailpit speaks plain SMTP; SMTPAutoTLS would try STARTTLS and fail.
		$phpmailer->SMTPAutoTLS = false;
		$phpmailer->SMTPSecure  = '';
	}
);
