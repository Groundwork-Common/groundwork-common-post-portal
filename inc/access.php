<?php
/**
 * The portal role, and the one function that decides who may edit what.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── The role holds no real capabilities, on purpose ──────────────────────────
 * `read`, and one marker capability that nothing ever checks for authorization.
 *
 * The temptation is to give the role edit_posts, or a custom capability_type's
 * edit_ caps, and let WordPress do the work. That is wrong here for a reason
 * specific to what this plugin is: the post types it covers are the site's
 * existing ones, and a post type registered with the default
 * capability_type => 'post' maps its edit caps onto edit_posts / edit_others_posts.
 * Granting those to reach one directory listing grants them for every article
 * on the site.
 *
 * A custom capability_type would fix that, and would require the site to
 * re-register post types it did not write — a theme's, another plugin's, the
 * core `post` type — which is exactly the "you must fork it" outcome this
 * plugin exists to avoid.
 *
 * So authorization is not capabilities. It is gwcpp_user_can_edit_post(), and
 * the consequence to keep in mind while reading anything downstream: there is
 * nothing underneath these checks. A handler that forgets one is not caught by
 * a capability check further down, because there is no capability check further
 * down. That is why the guards in portal.php end the request rather than
 * returning a value a caller has to remember to test.
 * ───────────────────────────────────────────────────────────────────────────
 */

const GWCPP_ROLE = 'gwcpp_portal_user';

/** Held by the role, checked by nothing. It exists so `user_can( $u, … )` has a
 *  truthful answer for other plugins, and so the role is not capability-less in
 *  a way that some admin screens render as broken.
 */
const GWCPP_MARKER_CAP = 'gwcpp_use_portal';

/** Non-persistent, and the comment below is the reason. */
const GWCPP_CACHE_GROUP = 'gwcpp_access';

add_action( 'init', 'gwcpp_ensure_role', 5 );

/**
 * Create the portal role if it is missing.
 *
 * On every init rather than on activation. An activation hook runs once, and a
 * site that loses the role — a migration, a security plugin that rebuilds
 * roles, a restore from a backup taken before install — would have no way back
 * short of deactivate/reactivate, with every portal user locked out meanwhile.
 * get_role() on an already-present role is an array lookup against an option
 * WordPress has loaded anyway.
 */
function gwcpp_ensure_role(): void {
	if ( get_role( GWCPP_ROLE ) ) {
		return;
	}

	add_role(
		GWCPP_ROLE,
		__( 'Portal User', 'groundwork-common-post-portal' ),
		array(
			'read'           => true,
			GWCPP_MARKER_CAP => true,
		)
	);
}

/**
 * True when a user holds the portal role.
 *
 * Checks the role rather than the marker capability. An administrator can be
 * granted any capability by any plugin, and "is this account one of the ones
 * the portal provisioned" is a different question from "may this account do
 * something" — conflating them is how an admin ends up redirected out of
 * wp-admin by the lockout below.
 *
 * @param int $user_id User ID, or 0 for the current user.
 * @return bool
 */
function gwcpp_user_is_portal_user( int $user_id = 0 ): bool {
	$user = $user_id > 0 ? get_userdata( $user_id ) : wp_get_current_user();

	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	return in_array( GWCPP_ROLE, (array) $user->roles, true );
}

/**
 * May this user edit this post through the portal?
 *
 * The only function in the plugin that answers this. Everything else — the
 * list query, the edit view, every save handler, the meta box — routes through
 * it or through a helper that calls it.
 *
 * Order matters only for cost, not for correctness: the two cheap checks that
 * need no extra query come first.
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_user_can_edit_post( int $user_id, int $post_id ): bool {
	if ( $user_id <= 0 || $post_id <= 0 ) {
		return false;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	/*
	 * The post type gate is first and is not negotiable. Everything below grants
	 * access to a specific post; this is what stops any of it from applying to
	 * a post type nobody switched on. A direct grant left on a post whose type
	 * was later disabled must stop working the moment it was disabled.
	 */
	if ( ! gwcpp_type_enabled( $post->post_type ) ) {
		return false;
	}

	/*
	 * Trashed and auto-draft are excluded for different reasons. A trashed post
	 * is on its way out and staff own that decision; an auto-draft is a row
	 * WordPress created when somebody clicked Add New and is not a record of
	 * anything. Neither should appear in a portal list or be editable from one.
	 */
	if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return false;
	}

	if ( in_array( $user_id, gwcpp_post_editors( $post_id ), true ) ) {
		return true;
	}

	$org = gwcpp_post_org( $post_id );
	if ( $org > 0 && in_array( $org, gwcpp_user_orgs( $user_id ), true ) ) {
		return true;
	}

	if ( gwcpp_type_setting( $post->post_type, 'author_grant' ) && (int) $post->post_author === $user_id ) {
		return true;
	}

	return false;
}

/**
 * Every post this user may edit.
 *
 * Built from up to three queries rather than one, because WP_Query cannot
 * express "author = X OR meta matches" — `author` and `meta_query` are ANDed,
 * always. Three indexed queries and an array merge is both simpler and faster
 * than the alternative of a posts_where filter injecting hand-written SQL.
 *
 * Every ID that comes back is run through gwcpp_user_can_edit_post() before
 * being returned. That is redundant by construction and is kept deliberately:
 * it means this function cannot grant anything the single choke-point would
 * refuse, even if a query above it is later widened by mistake or by a filter.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function gwcpp_editable_post_ids( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}

	$cached = wp_cache_get( 'editable_' . $user_id, GWCPP_CACHE_GROUP );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$types = gwcpp_post_types();
	if ( ! $types ) {
		return array();
	}

	$base = array(
		'post_type'              => $types,
		'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- The cap on what one portal user may reach. Deliberately finite: the list is paginated at twenty, and an unbounded query here is reachable by anybody signed in.
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'ignore_sticky_posts'    => true,
		'suppress_filters'       => false,
	);

	$ids = array();

	// Direct grants.
	$ids = array_merge(
		$ids,
		get_posts(
			$base + array(
				'meta_key'   => GWCPP_POST_EDITOR_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact match on an indexed meta row; see the note in org-cpt.php.
				'meta_value' => (string) $user_id,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		)
	);

	// Organisation membership.
	$orgs = gwcpp_user_orgs( $user_id );
	if ( $orgs ) {
		$ids = array_merge(
			$ids,
			get_posts(
				$base + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One IN() against an indexed meta row; the alternative is loading every post and filtering in PHP.
						array(
							'key'     => GWCPP_POST_ORG_META,
							'value'   => array_map( 'strval', $orgs ),
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	// Authorship, for the post types where it grants anything.
	$author_types = array_values(
		array_filter(
			$types,
			static function ( $type ) {
				return (bool) gwcpp_type_setting( $type, 'author_grant' );
			}
		)
	);

	if ( $author_types ) {
		$ids = array_merge(
			$ids,
			get_posts(
				array_merge(
					$base,
					array(
						'post_type' => $author_types,
						'author'    => $user_id,
					)
				)
			)
		);
	}

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

	/*
	 * ── Prime once, before the re-check walks the whole list ────────────────
	 * The three queries above ask for `fields => 'ids'` with the meta cache
	 * off, which is right for the queries and leaves nothing in the cache. The
	 * re-check below then calls gwcpp_user_can_edit_post() per ID, and each of
	 * those does a get_post(), two get_post_meta() reads and a get_post_type()
	 * on the organisation — so an organisation with three hundred entries meant
	 * something like fifteen hundred individual round trips to render a list of
	 * twenty, and the renderer then called get_post() on all of them again.
	 *
	 * Two queries here make every one of those a cache hit. The re-check itself
	 * stays exactly as it was: it is the choke point, and it is deliberately
	 * redundant with the queries that produced this list. It was never the
	 * problem — paying full price for uncached objects was.
	 */
	if ( $ids ) {
		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );
	}

	// The redundant re-check described above.
	$ids = array_values(
		array_filter(
			$ids,
			static function ( $post_id ) use ( $user_id ) {
				return gwcpp_user_can_edit_post( $user_id, $post_id );
			}
		)
	);

	/**
	 * The posts a user may edit.
	 *
	 * Filtering this can only ever narrow the list in practice — anything added
	 * here is dropped by the choke-point re-check above unless the same user
	 * genuinely has access by one of the three paths.
	 *
	 * @param int[] $ids     Post IDs.
	 * @param int   $user_id User ID.
	 */
	$ids = (array) apply_filters( 'gwcpp_editable_posts', $ids, $user_id );
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

	wp_cache_set( 'editable_' . $user_id, $ids, GWCPP_CACHE_GROUP );

	return $ids;
}

/*
 * ── Cache invalidation ──────────────────────────────────────────────────────
 * The group is registered non-persistent, which on a site with Redis or
 * Memcached means these entries live for one request and never reach the shared
 * store. That is the point. Every entry here is the answer to an access-control
 * question, and the failure mode of a stale one is not a wrong number on a
 * screen — it is somebody editing a post after their access was withdrawn,
 * for as long as the cache lives, on whichever web node happens to hold it.
 *
 * A per-request memo is worth having anyway: the list view calls this once and
 * the nav calls it again, and neither should pay for three queries twice.
 *
 * The hooks below then handle the case that actually bites during a single
 * request — staff revoking access and immediately reloading the page.
 * ───────────────────────────────────────────────────────────────────────────
 */
add_action(
	'init',
	static function (): void {
		wp_cache_add_non_persistent_groups( array( GWCPP_CACHE_GROUP ) );
	},
	1
);

add_action( 'added_post_meta', 'gwcpp_flush_access_cache_meta', 10, 3 );
add_action( 'updated_post_meta', 'gwcpp_flush_access_cache_meta', 10, 3 );
add_action( 'deleted_post_meta', 'gwcpp_flush_access_cache_meta', 10, 3 );
add_action( 'added_user_meta', 'gwcpp_flush_access_cache_user_meta', 10, 3 );
add_action( 'deleted_user_meta', 'gwcpp_flush_access_cache_user_meta', 10, 3 );
add_action( 'transition_post_status', 'gwcpp_flush_access_cache', 10, 0 );
add_action( 'update_option_' . GWCPP_SETTINGS_OPTION, 'gwcpp_flush_access_cache' );

/**
 * Flush when one of the two post meta keys the model reads changes.
 *
 * @param int    $meta_id  Meta row ID.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Meta key.
 */
function gwcpp_flush_access_cache_meta( $meta_id, $post_id, $meta_key ): void {
	unset( $meta_id, $post_id );
	if ( GWCPP_POST_ORG_META === $meta_key || GWCPP_POST_EDITOR_META === $meta_key ) {
		gwcpp_flush_access_cache();
	}
}

/**
 * Flush when a user's organisation membership changes.
 *
 * @param int    $meta_id  Meta row ID.
 * @param int    $user_id  User ID.
 * @param string $meta_key Meta key.
 */
function gwcpp_flush_access_cache_user_meta( $meta_id, $user_id, $meta_key ): void {
	unset( $meta_id, $user_id );
	if ( GWCPP_USER_ORG_META === $meta_key ) {
		gwcpp_flush_access_cache();
	}
}

/**
 * Drop the whole group.
 *
 * Whole-group rather than per-user, because the events above do not always name
 * the users affected: assigning a post to an organisation changes what every
 * member of that organisation may edit, and working out who they are costs a
 * query to save a cache that only lives for this request anyway.
 */
function gwcpp_flush_access_cache(): void {
	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( GWCPP_CACHE_GROUP );
		return;
	}

	/*
	 * No wp_cache_flush_group before WP 6.1, and wp_cache_flush() would clear
	 * every group on the site including core's. Bumping a salt is the standard
	 * workaround; here the group is non-persistent and single-request, so the
	 * honest cheap option is to leave it and let the request end. The one case
	 * this misses — a revoke and a re-read inside one request on WP 6.0 — is
	 * narrow enough to accept rather than flush somebody's whole object cache.
	 */
	unset( $GLOBALS['gwcpp_noop'] );
}

/* ── wp-admin lockout ────────────────────────────────────────────────────── */

add_action( 'admin_init', 'gwcpp_block_admin_access' );
add_filter( 'show_admin_bar', 'gwcpp_hide_admin_bar' );
add_filter( 'wp_is_application_passwords_available_for_user', 'gwcpp_no_application_passwords', 10, 2 );

/**
 * Send portal users back to the portal if they reach wp-admin.
 *
 * AJAX is skipped because admin-ajax.php runs under admin_init and is used by
 * the front end. Redirecting it would break any AJAX the theme does for a
 * signed-in portal user, and admin-ajax.php actions do their own capability
 * checks.
 */
function gwcpp_block_admin_access(): void {
	if ( wp_doing_ajax() || ! gwcpp_user_is_portal_user() ) {
		return;
	}

	wp_safe_redirect( gwcpp_portal_url() );
	exit;
}

/**
 * No admin bar for portal users.
 *
 * @param bool $show Whether to show it.
 * @return bool
 */
function gwcpp_hide_admin_bar( $show ) {
	return gwcpp_user_is_portal_user() ? false : $show;
}

/**
 * No application passwords for portal users.
 *
 * An application password is a permanent credential that bypasses the session
 * length, the magic-link expiry, and the wp-admin redirect in one step. The
 * accounts this plugin provisions have no password at all by design; handing
 * them a way to mint one undoes that.
 *
 * @param bool    $available Whether they are available.
 * @param WP_User $user      The user.
 * @return bool
 */
function gwcpp_no_application_passwords( $available, $user ) {
	if ( $user instanceof WP_User && gwcpp_user_is_portal_user( $user->ID ) ) {
		return false;
	}
	return $available;
}
