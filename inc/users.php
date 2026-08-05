<?php
/**
 * Provisioning: turning an email address into a portal account.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/** User meta, single: marks an account this plugin created. */
const GWCPP_PROVISIONED_META = '_gwcpp_provisioned';

/**
 * Give somebody access to an organisation, creating their account if needed.
 *
 * ── The rule this function exists to enforce ─────────────────────────────────
 * It will not bind an account it did not create. If the address already belongs
 * to a user who is not a portal user, it refuses and says so.
 *
 * That refusal is the whole point. Staff typing an address into an invite box
 * are thinking about the person, not about the site's user table, and a
 * surprising number of the addresses they type belong to somebody who already
 * has an account — a subscriber from a newsletter form, an editor, the site's
 * own administrator. Silently adding the portal role to an administrator would
 * hand them the wp-admin lockout and lock them out of their own site; silently
 * adding an organisation to an existing editor would grant them portal access
 * nobody reviewed.
 *
 * The safe resolutions all belong to a human: use a different address, or
 * decide deliberately that this existing account should be a portal user.
 *
 * @param int    $org_id Organisation post ID.
 * @param string $email  Email address.
 * @return int|WP_Error User ID, or an error whose message is safe to show staff.
 */
function gwcpp_grant_access( int $org_id, string $email ) {
	$email = sanitize_email( trim( $email ) );

	if ( ! is_email( $email ) ) {
		return new WP_Error( 'gwcpp_bad_email', __( 'That does not look like an email address.', 'groundwork-common-post-portal' ) );
	}

	if ( $org_id <= 0 || GWCPP_ORG_TYPE !== get_post_type( $org_id ) ) {
		return new WP_Error( 'gwcpp_bad_org', __( 'That organisation no longer exists.', 'groundwork-common-post-portal' ) );
	}

	$existing = get_user_by( 'email', $email );

	if ( $existing instanceof WP_User ) {
		if ( ! gwcpp_user_is_portal_user( $existing->ID ) ) {
			return new WP_Error(
				'gwcpp_existing_user',
				sprintf(
					/* translators: %s: an email address. */
					__( '%s already has an account on this site that is not a portal account. Adding portal access to it could change what that person can do elsewhere, so it has to be done deliberately — either invite a different address, or change that account\'s role yourself first.', 'groundwork-common-post-portal' ),
					$email
				)
			);
		}

		gwcpp_add_user_to_org( $existing->ID, $org_id );

		return $existing->ID;
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => gwcpp_unique_login( $email ),
			'user_email'   => $email,
			// Never shown to anybody, never emailed, and never usable: sign-in
			// is a link, and wp_generate_password() at this length is not
			// something anyone guesses. It exists because WordPress requires a
			// password field, not because it is a credential.
			//
			// Note this is deliberately NOT an unusable hash. A site that turns
			// on password sign-in later needs these accounts to be able to hold
			// a real password once somebody sets one.
			'user_pass'    => wp_generate_password( 64, true, true ),
			'display_name' => gwcpp_display_name_from_email( $email ),
			'role'         => GWCPP_ROLE,
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	update_user_meta( $user_id, GWCPP_PROVISIONED_META, 1 );
	gwcpp_add_user_to_org( (int) $user_id, $org_id );

	return (int) $user_id;
}

/**
 * A login nobody will ever type, derived from the address.
 *
 * Derived rather than random so the users list in wp-admin is readable — a
 * table of `portal_a91f3c` tells staff nothing. Uniquified with a counter
 * because two organisations can legitimately have a `info@` contact.
 *
 * @param string $email Email address.
 * @return string
 */
function gwcpp_unique_login( string $email ): string {
	$local = strstr( $email, '@', true );
	$base  = sanitize_user( is_string( $local ) ? $local : $email, true );
	$base  = strtolower( trim( $base, '.-_' ) );

	if ( '' === $base ) {
		$base = 'portal';
	}
	// Leave room for the suffix inside WordPress's 60-character column.
	$base = substr( $base, 0, 50 );

	$login = $base;
	$n     = 2;
	while ( username_exists( $login ) ) {
		$login = $base . '-' . $n;
		++$n;

		/*
		 * A runaway here would be an infinite loop on a page load. Fifty
		 * collisions on one local part means something is wrong that a counter
		 * will not fix, so fall back to something that cannot collide.
		 */
		if ( $n > 50 ) {
			$login = $base . '-' . wp_generate_password( 8, false, false );
			break;
		}
	}

	return $login;
}

/**
 * Something better than the raw address to show in a list.
 *
 * "jane.doe@shelter.org" becomes "Jane Doe". Wrong sometimes — "info@" becomes
 * "Info" — and right often enough to beat showing an address in a column headed
 * Name. The person can change it on their account page.
 *
 * @param string $email Email address.
 * @return string
 */
function gwcpp_display_name_from_email( string $email ): string {
	$local = strstr( $email, '@', true );
	if ( ! is_string( $local ) || '' === $local ) {
		return $email;
	}

	$name = str_replace( array( '.', '_', '-', '+' ), ' ', $local );
	$name = preg_replace( '/\s+/', ' ', $name );
	$name = trim( (string) $name );

	if ( '' === $name ) {
		return $email;
	}

	// ucwords, not ucfirst: "jane doe" should not become "Jane doe".
	return ucwords( $name );
}

/**
 * Take somebody out of an organisation.
 *
 * Does not delete the account, and does not touch any direct grants they hold
 * on individual posts — those are a separate decision made in a separate place,
 * and silently withdrawing them here would mean the Members box on one screen
 * quietly changed what another screen shows.
 *
 * @param int  $user_id          User ID.
 * @param int  $org_id           Organisation post ID.
 * @param bool $destroy_sessions End their signed-in sessions immediately.
 * @return bool
 */
function gwcpp_revoke_access( int $user_id, int $org_id, bool $destroy_sessions = true ): bool {
	$removed = gwcpp_remove_user_from_org( $user_id, $org_id );

	if ( $removed && $destroy_sessions ) {
		/*
		 * Without this the person keeps a working session for up to the
		 * configured session length. The access check would refuse every post
		 * on the next request, so the exposure is an empty list rather than
		 * anything readable — but "revoked" should mean signed out, because
		 * that is what the person clicking Remove believes they did.
		 */
		$sessions = WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}

	return $removed;
}

/**
 * True when this plugin created the account.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function gwcpp_user_was_provisioned( int $user_id ): bool {
	return (bool) get_user_meta( $user_id, GWCPP_PROVISIONED_META, true );
}

/**
 * Every organisation a portal user could be reached through, for staff screens.
 *
 * @param int $user_id User ID.
 * @return string
 */
function gwcpp_user_org_names( int $user_id ): string {
	$names = array();
	foreach ( gwcpp_user_orgs( $user_id ) as $org_id ) {
		$title = get_the_title( $org_id );
		if ( '' !== $title ) {
			$names[] = $title;
		}
	}

	return implode( ', ', $names );
}
