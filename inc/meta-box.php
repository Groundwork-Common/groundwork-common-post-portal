<?php
/**
 * The wp-admin side of access: who reaches this post, and who is in this
 * organisation.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * User meta, single: when this account last signed in, for the People table.
 *
 * Named here rather than written as a literal in the two places that use it,
 * like every other meta key in the plugin. Deliberately without the leading
 * underscore the post meta keys carry: that convention marks meta as protected
 * from the post editor's Custom Fields panel, which is a post-meta concept and
 * does nothing on a user.
 */
const GWCPP_LAST_LOGIN_META = 'gwcpp_last_login';

add_action( 'add_meta_boxes', 'gwcpp_add_meta_boxes' );
add_action( 'save_post', 'gwcpp_save_access_meta', 10, 2 );
add_action( 'admin_post_gwcpp_invite', 'gwcpp_handle_invite' );
add_action( 'admin_post_gwcpp_remove_member', 'gwcpp_handle_remove_member' );
add_action( 'admin_post_gwcpp_remove_editor', 'gwcpp_handle_remove_editor' );

/**
 * Register the boxes.
 */
function gwcpp_add_meta_boxes(): void {
	foreach ( gwcpp_post_types() as $post_type ) {
		add_meta_box(
			'gwcpp-access',
			__( 'Portal access', 'groundwork-common-post-portal' ),
			'gwcpp_render_access_meta_box',
			$post_type,
			'side',
			'default'
		);
	}

	add_meta_box(
		'gwcpp-members',
		__( 'People', 'groundwork-common-post-portal' ),
		'gwcpp_render_members_meta_box',
		GWCPP_ORG_TYPE,
		'normal',
		'high'
	);

	add_meta_box(
		'gwcpp-org-posts',
		__( 'What this organisation can edit', 'groundwork-common-post-portal' ),
		'gwcpp_render_org_posts_meta_box',
		GWCPP_ORG_TYPE,
		'normal',
		'default'
	);
}

/**
 * Who can edit this post.
 *
 * @param WP_Post $post The post.
 */
function gwcpp_render_access_meta_box( WP_Post $post ): void {
	wp_nonce_field( 'gwcpp_access_' . $post->ID, 'gwcpp_access_nonce' );

	$orgs = gwcpp_all_orgs();

	printf( '<p><label for="gwcpp-org"><strong>%s</strong></label></p>', esc_html__( 'Organisation', 'groundwork-common-post-portal' ) );

	if ( ! $orgs ) {
		printf(
			'<p class="description">%s <a href="%s">%s</a></p>',
			esc_html__( 'No organisations exist yet.', 'groundwork-common-post-portal' ),
			esc_url( admin_url( 'post-new.php?post_type=' . GWCPP_ORG_TYPE ) ),
			esc_html__( 'Add one', 'groundwork-common-post-portal' )
		);
	} else {
		$current = gwcpp_post_org( $post->ID );

		/*
		 * Read-only for anybody who cannot save it — see gwcpp_can_assign_org().
		 * Shown rather than hidden, because which organisation owns a post is
		 * worth knowing even to somebody who may not change it, and a box that
		 * simply disappears reads as a bug.
		 */
		if ( ! gwcpp_can_assign_org() ) {
			$org_post = $current > 0 ? get_post( $current ) : null;

			printf(
				'<p>%s</p>',
				esc_html(
					$org_post instanceof WP_Post
						? get_the_title( $org_post )
						: __( '— none —', 'groundwork-common-post-portal' )
				)
			);
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Only an administrator can change which organisation this belongs to.', 'groundwork-common-post-portal' )
			);
		} else {
			echo '<select id="gwcpp-org" name="gwcpp_org" class="widefat">';
			printf( '<option value="0">%s</option>', esc_html__( '— none —', 'groundwork-common-post-portal' ) );
			foreach ( $orgs as $org ) {
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $org->ID,
					selected( $current, (int) $org->ID, false ),
					esc_html( get_the_title( $org ) )
				);
			}
			echo '</select>';
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Everybody in this organisation can edit this from the portal.', 'groundwork-common-post-portal' )
			);
		}
	}

	$editors = gwcpp_post_editors( $post->ID );

	printf( '<p><strong>%s</strong></p>', esc_html__( 'Also, individually', 'groundwork-common-post-portal' ) );

	if ( ! $editors ) {
		printf( '<p class="description">%s</p>', esc_html__( 'Nobody.', 'groundwork-common-post-portal' ) );
	} else {
		echo '<ul class="gwcpp-people">';
		foreach ( $editors as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			echo '<li>';
			printf( '<span>%s</span>', esc_html( $user->user_email ) );
			printf(
				'<a class="gwcpp-remove" href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=gwcpp_remove_editor&post=' . $post->ID . '&user=' . $user_id ),
						'gwcpp_remove_editor_' . $post->ID . '_' . $user_id
					)
				),
				esc_html__( 'Remove', 'groundwork-common-post-portal' )
			);
			echo '</li>';
		}
		echo '</ul>';
	}

	if ( gwcpp_type_setting( $post->post_type, 'author_grant' ) ) {
		$author = get_userdata( (int) $post->post_author );
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a user's display name. */
					__( 'The author (%s) can also edit this, because that is switched on for this post type.', 'groundwork-common-post-portal' ),
					$author ? $author->display_name : __( 'unknown', 'groundwork-common-post-portal' )
				)
			)
		);
	}
}

/**
 * The organisation's members, and the invite box.
 *
 * @param WP_Post $post The organisation.
 */
function gwcpp_render_members_meta_box( WP_Post $post ): void {
	$members = gwcpp_org_members( $post->ID );

	if ( 'auto-draft' === $post->post_status ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Save this organisation first, then you can invite people to it.', 'groundwork-common-post-portal' )
		);
		return;
	}

	if ( ! $members ) {
		printf( '<p class="description">%s</p>', esc_html__( 'Nobody has been invited yet.', 'groundwork-common-post-portal' ) );
	} else {
		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'Name', 'groundwork-common-post-portal' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Email', 'groundwork-common-post-portal' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Last signed in', 'groundwork-common-post-portal' ) );
		echo '<th scope="col"></th></tr></thead><tbody>';

		foreach ( $members as $user ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $user->display_name ) );
			printf( '<td>%s</td>', esc_html( $user->user_email ) );

			$last = (int) get_user_meta( $user->ID, GWCPP_LAST_LOGIN_META, true );
			printf(
				'<td>%s</td>',
				esc_html(
					$last > 0
						? sprintf(
							/* translators: %s: a human-readable time difference, e.g. "3 days". */
							__( '%s ago', 'groundwork-common-post-portal' ),
							human_time_diff( $last )
						)
						: __( 'never', 'groundwork-common-post-portal' )
				)
			);

			echo '<td>';
			printf(
				'<a class="gwcpp-remove" href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=gwcpp_remove_member&org=' . $post->ID . '&user=' . $user->ID ),
						'gwcpp_remove_member_' . $post->ID . '_' . $user->ID
					)
				),
				esc_html__( 'Remove', 'groundwork-common-post-portal' )
			);
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/*
	 * A separate form posting to admin-post.php, not fields inside the post
	 * edit form. Inviting somebody creates a user account and sends an email —
	 * that must happen when the button marked Invite is pressed, not as a side
	 * effect of pressing Update with something left in a box.
	 */
	echo '<form class="gwcpp-invite" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'gwcpp_invite_' . $post->ID );
	echo '<input type="hidden" name="action" value="gwcpp_invite" />';
	printf( '<input type="hidden" name="org" value="%d" />', (int) $post->ID );

	printf( '<label for="gwcpp-invite-email"><strong>%s</strong></label> ', esc_html__( 'Invite somebody', 'groundwork-common-post-portal' ) );
	echo '<input type="email" id="gwcpp-invite-email" name="email" class="regular-text" required /> ';
	printf( '<button type="submit" class="button button-primary">%s</button>', esc_html__( 'Invite', 'groundwork-common-post-portal' ) );

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Creates a portal account and emails them a sign-in link. If the address already belongs to somebody with another role on this site, nothing happens and you will be told.', 'groundwork-common-post-portal' )
	);

	echo '</form>';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of an error the invite handler stored for this admin.
	gwcpp_render_invite_error( $post->ID );
}

/**
 * Show why the last invite on this organisation failed.
 *
 * Stored in a transient keyed by both the admin and the organisation rather
 * than passed through the URL, because the message names an email address and a
 * URL carrying one ends up in server logs, browser history and any Referer the
 * page sends.
 *
 * @param int $org_id Organisation post ID.
 */
function gwcpp_render_invite_error( int $org_id ): void {
	$key   = 'gwcpp_invite_err_' . get_current_user_id() . '_' . $org_id;
	$error = get_transient( $key );
	delete_transient( $key );

	if ( ! is_string( $error ) || '' === $error ) {
		return;
	}

	printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $error ) );
}

/**
 * The posts this organisation reaches.
 *
 * @param WP_Post $post The organisation.
 */
function gwcpp_render_org_posts_meta_box( WP_Post $post ): void {
	$types = gwcpp_post_types();

	if ( ! $types ) {
		printf( '<p class="description">%s</p>', esc_html__( 'No post types are switched on for the portal yet.', 'groundwork-common-post-portal' ) );
		return;
	}

	$posts = get_posts(
		array(
			'post_type'              => $types,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A deliberate ceiling on an admin meta box listing one organisation's posts; the alternative is an unbounded query on a screen nobody paginates.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_key'               => GWCPP_POST_ORG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact match on an indexed meta row.
			'meta_value'             => (string) $post->ID,  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			'orderby'                => 'title',
			'order'                  => 'ASC',
		)
	);

	if ( ! $posts ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Nothing is assigned to this organisation yet. Open a post and choose this organisation in its Portal access box.', 'groundwork-common-post-portal' )
		);
		return;
	}

	echo '<ul class="gwcpp-org-posts">';
	foreach ( $posts as $one ) {
		printf(
			'<li><a href="%s">%s</a> <span class="gwcpp-pill">%s</span></li>',
			esc_url( (string) get_edit_post_link( $one->ID ) ),
			esc_html( get_the_title( $one ) ),
			esc_html( gwcpp_status_label( $one->post_status ) )
		);
	}
	echo '</ul>';
}

/**
 * Save the organisation chosen on a post.
 *
 * ── Why this needs more than edit_post ───────────────────────────────────────
 * Assigning a post to an organisation is not editing content; it is granting
 * every portal user in that organisation the right to edit this post. It used to
 * require only `edit_post`, which meant an Author on an enabled post type could
 * hand their own post to any organisation on the site and give strangers editing
 * rights to it.
 *
 * Every other write in this file — inviting, removing a member, removing an
 * editor — already requires `manage_options`, because each of them changes who
 * can reach what. This is the same kind of change and now asks the same
 * question. The renderer asks it too, so nobody is shown a control that would
 * silently discard their choice.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post.
 */
function gwcpp_save_access_meta( $post_id, $post ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post || ! gwcpp_type_enabled( $post->post_type ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) || ! gwcpp_can_assign_org() ) {
		return;
	}
	if (
		! isset( $_POST['gwcpp_access_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_access_nonce'] ) ), 'gwcpp_access_' . $post_id )
	) {
		return;
	}

	/*
	 * Absent means "the form did not offer this", not "clear it". Anybody who
	 * gets this far can see the selector, so in practice the two are the same —
	 * but writing 0 on a missing field is how a post quietly loses its
	 * organisation the first time some other plugin posts to this screen, and
	 * that failure is silent and hard to trace back.
	 */
	if ( ! isset( $_POST['gwcpp_org'] ) ) {
		return;
	}

	gwcpp_set_post_org( (int) $post_id, (int) $_POST['gwcpp_org'] );
}

/**
 * Whether the current user may decide which organisation owns a post.
 *
 * One function so the meta box and the save path cannot drift apart — a control
 * that saves for some of the people who can see it is worse than one that is
 * hidden.
 *
 * @return bool
 */
function gwcpp_can_assign_org(): bool {
	/**
	 * Who may assign a post to an organisation.
	 *
	 * Defaults to the capability every other access change in this plugin
	 * requires. Widen it only with the consequence in mind: assigning a post to
	 * an organisation grants that organisation's portal users the right to edit
	 * it.
	 *
	 * @param bool $can Whether the current user may assign.
	 */
	return (bool) apply_filters( 'gwcpp_can_assign_org', current_user_can( 'manage_options' ) );
}

/**
 * Invite somebody to an organisation.
 */
function gwcpp_handle_invite(): void {
	gwcpp_require_admin_caps();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below against this same value.
	$org_id = isset( $_POST['org'] ) ? (int) $_POST['org'] : 0;

	check_admin_referer( 'gwcpp_invite_' . $org_id );

	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	$user_id = gwcpp_grant_access( $org_id, $email );

	if ( is_wp_error( $user_id ) ) {
		set_transient(
			'gwcpp_invite_err_' . get_current_user_id() . '_' . $org_id,
			$user_id->get_error_message(),
			60
		);
		gwcpp_org_redirect( $org_id, 'invite_failed' );
	}

	$user = get_userdata( (int) $user_id );
	if ( $user instanceof WP_User && gwcpp_setting( 'signin_magic' ) ) {
		gwcpp_send_magic_link( $user );
	}

	gwcpp_org_redirect( $org_id, 'invited' );
}

/**
 * Take somebody out of an organisation.
 */
function gwcpp_handle_remove_member(): void {
	gwcpp_require_admin_caps();

	// phpcs:ignore WordPress.Security.NonceVerification -- Verified immediately below against these same values.
	$org_id = isset( $_GET['org'] ) ? (int) $_GET['org'] : 0;
	// phpcs:ignore WordPress.Security.NonceVerification -- As above.
	$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;

	check_admin_referer( 'gwcpp_remove_member_' . $org_id . '_' . $user_id );

	gwcpp_revoke_access( $user_id, $org_id );
	gwcpp_org_redirect( $org_id, 'removed' );
}

/**
 * Withdraw a direct grant on one post.
 */
function gwcpp_handle_remove_editor(): void {
	gwcpp_require_admin_caps();

	// phpcs:ignore WordPress.Security.NonceVerification -- Verified immediately below against these same values.
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	// phpcs:ignore WordPress.Security.NonceVerification -- As above.
	$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;

	check_admin_referer( 'gwcpp_remove_editor_' . $post_id . '_' . $user_id );

	gwcpp_remove_post_editor( $user_id, $post_id );

	wp_safe_redirect( add_query_arg( 'gwcpp_notice', 'removed', (string) get_edit_post_link( $post_id, 'raw' ) ) );
	exit;
}

/**
 * Back to an organisation's edit screen with a message code.
 *
 * @param int    $org_id Organisation post ID.
 * @param string $code   Message code.
 */
function gwcpp_org_redirect( int $org_id, string $code ): void {
	wp_safe_redirect(
		add_query_arg(
			'gwcpp_notice',
			$code,
			(string) get_edit_post_link( $org_id, 'raw' )
		)
	);
	exit;
}

/*
 * Record when somebody signed in, so the Members table can say. Cheap, and it
 * is the first question staff ask about a partner who says the portal is not
 * working: have they ever actually got in.
 */
add_action(
	'wp_login',
	static function ( $login, $user ): void {
		unset( $login );
		if ( $user instanceof WP_User && gwcpp_user_is_portal_user( $user->ID ) ) {
			update_user_meta( $user->ID, GWCPP_LAST_LOGIN_META, time() );
		}
	},
	10,
	2
);

/*
 * The message codes shown on the organisation and post edit screens. Admin
 * notices there are core's, not ours, so this hooks the generic notice action
 * rather than being printed by a screen we render.
 */
add_action(
	'admin_notices',
	static function (): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		gwcpp_render_admin_notice();
	}
);
