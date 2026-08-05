<?php
/**
 * Organisations: the post type, and the meta that links posts and people to it.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── Why an organisation is a post type ──────────────────────────────────────
 * The alternative considered was a private taxonomy, which is lighter and is
 * what "group some posts together" usually wants. It was rejected because an
 * organisation is not a label, it is a record: it has a contact address, it has
 * people, it outlives any one of its posts, and staff need to open it and read
 * it. A term has a name and a description and no natural place to put anything
 * else, and every site that starts with a term ends up with a parallel option
 * array holding what the term could not.
 *
 * The cost is a post type in the admin menu and one more join in the access
 * check. Both are cheap. The benefit is that Phase 2's approval queue and
 * Phase 3's review reminders have somewhere to hang per-organisation state
 * without inventing a second home for it.
 * ───────────────────────────────────────────────────────────────────────────
 */

const GWCPP_ORG_TYPE = 'gwcpp_org';

/** The plugin's top-level admin menu. Named here because the org post type is
 *  the first thing that has to sit under it.
 */
const GWCPP_MENU_SLUG = 'gwcpp-portal';

/*
 * ── The three meta keys the access model runs on ─────────────────────────────
 * All three are REPEATING meta rows holding one integer each, never a
 * serialized array, and that shape is the whole reason the access check can be
 * a query rather than a scan.
 *
 * A serialized array of user IDs in one row cannot be searched. Finding every
 * post a user may edit would mean loading every post of every enabled type and
 * unserializing, which is fine for the forty locations this grew out of and
 * falls over at four thousand. Worse, the only way to query it at all is
 * meta_value LIKE '%"17"%', which matches user 17, user 170, and user 1700.
 *
 * One row per relationship is indexed, exactly matched, and lets WP_Query do
 * the work.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** Post meta, single: the organisation that owns this post. */
const GWCPP_POST_ORG_META = '_gwcpp_org';

/** Post meta, repeating: a user granted access to this one post directly. */
const GWCPP_POST_EDITOR_META = '_gwcpp_editor';

/** User meta, repeating: an organisation this user belongs to. */
const GWCPP_USER_ORG_META = '_gwcpp_org';

add_action( 'init', 'gwcpp_register_org_type', 10 );

/**
 * Register the organisation post type.
 */
function gwcpp_register_org_type(): void {
	$labels = array(
		'name'               => __( 'Organisations', 'groundwork-common-post-portal' ),
		'singular_name'      => __( 'Organisation', 'groundwork-common-post-portal' ),
		'add_new_item'       => __( 'Add Organisation', 'groundwork-common-post-portal' ),
		'edit_item'          => __( 'Edit Organisation', 'groundwork-common-post-portal' ),
		'new_item'           => __( 'New Organisation', 'groundwork-common-post-portal' ),
		'view_item'          => __( 'View Organisation', 'groundwork-common-post-portal' ),
		'search_items'       => __( 'Search Organisations', 'groundwork-common-post-portal' ),
		'not_found'          => __( 'No organisations yet.', 'groundwork-common-post-portal' ),
		'not_found_in_trash' => __( 'No organisations in the trash.', 'groundwork-common-post-portal' ),
		'all_items'          => __( 'Organisations', 'groundwork-common-post-portal' ),
		'menu_name'          => __( 'Organisations', 'groundwork-common-post-portal' ),
	);

	$args = array(
		'labels'              => $labels,
		// Not public, and every one of these follows from that rather than
		// being an independent choice. An organisation record holds a contact
		// person's email; it has no front-end page, no archive, no feed, and no
		// business being in search results or in the REST API's public
		// responses.
		'public'              => false,
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'show_ui'             => true,
		'show_in_menu'        => GWCPP_MENU_SLUG,
		'show_in_rest'        => false,
		'has_archive'         => false,
		'rewrite'             => false,
		'query_var'           => false,
		'hierarchical'        => false,
		'supports'            => array( 'title' ),
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
		'menu_icon'           => 'dashicons-groups',
	);

	/**
	 * Arguments for the organisation post type.
	 *
	 * @param array $args register_post_type() arguments.
	 */
	$args = (array) apply_filters( 'gwcpp_org_type_args', $args );

	register_post_type( GWCPP_ORG_TYPE, $args );
}

/**
 * The organisation that owns a post, or 0.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function gwcpp_post_org( int $post_id ): int {
	$org = (int) get_post_meta( $post_id, GWCPP_POST_ORG_META, true );

	/*
	 * An organisation that was deleted leaves its ID behind on every post that
	 * pointed at it. Returning it would let a user who is still a member of the
	 * dead organisation's ID edit those posts, which is access granted by a
	 * dangling reference. Checked here, once, rather than at each call site.
	 */
	if ( $org > 0 && GWCPP_ORG_TYPE !== get_post_type( $org ) ) {
		return 0;
	}

	return $org;
}

/**
 * The users granted access to one post directly.
 *
 * @param int $post_id Post ID.
 * @return int[]
 */
function gwcpp_post_editors( int $post_id ): array {
	$ids = get_post_meta( $post_id, GWCPP_POST_EDITOR_META, false );
	if ( ! is_array( $ids ) ) {
		return array();
	}

	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

/**
 * The organisations a user belongs to.
 *
 * @param int $user_id User ID.
 * @return int[]
 */
function gwcpp_user_orgs( int $user_id ): array {
	if ( $user_id <= 0 ) {
		return array();
	}

	$ids = get_user_meta( $user_id, GWCPP_USER_ORG_META, false );
	if ( ! is_array( $ids ) ) {
		return array();
	}

	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

	// Same dangling-reference problem as gwcpp_post_org(), from the other side.
	return array_values(
		array_filter(
			$ids,
			static function ( $org_id ) {
				return GWCPP_ORG_TYPE === get_post_type( $org_id );
			}
		)
	);
}

/**
 * The users belonging to an organisation.
 *
 * @param int $org_id Organisation post ID.
 * @return WP_User[]
 */
function gwcpp_org_members( int $org_id ): array {
	if ( $org_id <= 0 ) {
		return array();
	}

	return get_users(
		array(
			'meta_key'   => GWCPP_USER_ORG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact match on an indexed meta row; this is the intended shape, see the note on meta keys above.
			'meta_value' => (string) $org_id,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'number'     => 500,
		)
	);
}

/**
 * Add a user to an organisation.
 *
 * Idempotent: adding somebody twice is a no-op rather than a second meta row,
 * because the obvious way to re-invite an existing member is to type their
 * address into the invite box again.
 *
 * @param int $user_id User ID.
 * @param int $org_id  Organisation post ID.
 * @return bool True when membership exists after the call.
 */
function gwcpp_add_user_to_org( int $user_id, int $org_id ): bool {
	if ( $user_id <= 0 || $org_id <= 0 || GWCPP_ORG_TYPE !== get_post_type( $org_id ) ) {
		return false;
	}

	if ( in_array( $org_id, gwcpp_user_orgs( $user_id ), true ) ) {
		return true;
	}

	return (bool) add_user_meta( $user_id, GWCPP_USER_ORG_META, $org_id, false );
}

/**
 * Remove a user from an organisation.
 *
 * Removes membership and nothing else. The user account survives, because it
 * may hold membership of other organisations and because deleting a WordPress
 * user reassigns or destroys everything they authored.
 *
 * @param int $user_id User ID.
 * @param int $org_id  Organisation post ID.
 * @return bool
 */
function gwcpp_remove_user_from_org( int $user_id, int $org_id ): bool {
	if ( $user_id <= 0 || $org_id <= 0 ) {
		return false;
	}

	return (bool) delete_user_meta( $user_id, GWCPP_USER_ORG_META, $org_id );
}

/**
 * Grant one user access to one post, without an organisation.
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_add_post_editor( int $user_id, int $post_id ): bool {
	if ( $user_id <= 0 || $post_id <= 0 ) {
		return false;
	}

	if ( in_array( $user_id, gwcpp_post_editors( $post_id ), true ) ) {
		return true;
	}

	return (bool) add_post_meta( $post_id, GWCPP_POST_EDITOR_META, $user_id, false );
}

/**
 * Withdraw a direct grant.
 *
 * @param int $user_id User ID.
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_remove_post_editor( int $user_id, int $post_id ): bool {
	if ( $user_id <= 0 || $post_id <= 0 ) {
		return false;
	}

	return (bool) delete_post_meta( $post_id, GWCPP_POST_EDITOR_META, $user_id );
}

/**
 * Assign a post to an organisation, or to none.
 *
 * @param int $post_id Post ID.
 * @param int $org_id  Organisation post ID, or 0 to clear.
 * @return bool
 */
function gwcpp_set_post_org( int $post_id, int $org_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}

	if ( $org_id <= 0 ) {
		return (bool) delete_post_meta( $post_id, GWCPP_POST_ORG_META );
	}

	if ( GWCPP_ORG_TYPE !== get_post_type( $org_id ) ) {
		return false;
	}

	return (bool) update_post_meta( $post_id, GWCPP_POST_ORG_META, $org_id );
}

/**
 * Every organisation, for a dropdown.
 *
 * @return WP_Post[]
 */
function gwcpp_all_orgs(): array {
	return get_posts(
		array(
			'post_type'        => GWCPP_ORG_TYPE,
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'numberposts'      => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Bounded on purpose. An organisation with more entries than this is past what this screen is for, and an unbounded query would be worse.
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		)
	);
}
