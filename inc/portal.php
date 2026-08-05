<?php
/**
 * The portal: request dispatch, the handler guards, and the views.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/* ── Why POSTs are dispatched from template_redirect ─────────────────────────
 * The obvious homes for a form handler are admin-post.php and admin-ajax.php.
 * Both live under /wp-admin/, and /wp-admin/ is exactly what the portal role is
 * redirected away from by gwcpp_block_admin_access(). Using either would mean
 * carving an exception into the lockout, and an exception in a lockout is the
 * thing the lockout is protecting.
 *
 * template_redirect runs on the front end, after the query is resolved and
 * before anything is rendered, which is precisely when a handler wants to run:
 * late enough to know which page this is, early enough to redirect without
 * headers already sent.
 * ─────────────────────────────────────────────────────────────────────────── */

add_action( 'template_redirect', 'gwcpp_dispatch' );

/**
 * Route a request on the portal page.
 */
function gwcpp_dispatch(): void {
	if ( ! gwcpp_is_portal() ) {
		return;
	}

	gwcpp_send_no_cache_headers();

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	if ( 'GET' === $method && isset( $_GET['gwcpp_token'] ) ) {
		gwcpp_handle_magic_link();
		return;
	}

	if ( 'POST' !== $method ) {
		return;
	}

	/* Branching on the submit button's name rather than on a hidden action
	 * field. A hidden field can be edited to name any handler while the nonce
	 * still matches the form it came from; a submit button's name is only sent
	 * when that button is the one that submitted the form. It is not a security
	 * boundary — the guards below are — but it means the shape of the request
	 * matches the shape of the page. */
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Each handler verifies its own nonce as its first act; this only chooses which one runs.
	if ( isset( $_POST['gwcpp_request_link'] ) ) {
		gwcpp_handle_link_request();
	} elseif ( isset( $_POST['gwcpp_login'] ) ) {
		gwcpp_handle_password_login();
	} elseif ( isset( $_POST['gwcpp_logout'] ) ) {
		gwcpp_handle_logout();
	} elseif ( isset( $_POST['gwcpp_save'] ) ) {
		gwcpp_handle_save();
	} elseif ( isset( $_POST['gwcpp_create'] ) ) {
		gwcpp_handle_create();
	} elseif ( isset( $_POST['gwcpp_unpublish'] ) ) {
		gwcpp_handle_unpublish();
	} elseif ( isset( $_POST['gwcpp_republish'] ) ) {
		gwcpp_handle_republish();
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing
}

/**
 * Tell everything downstream not to store this page.
 *
 * A cache or CDN serving the portal to the wrong visitor hands one
 * organisation's records to another. Worth being precise about what this can
 * and cannot do: all of it runs on template_redirect, which by definition does
 * not execute when a full-page cache serves a hit — so none of it can rescue a
 * page that is already wrongly cached. What it does is make sure the response
 * that POPULATES such a cache says not to.
 *
 * nocache_headers() alone does not say enough for that. It sends no-cache and
 * must-revalidate, which several CDNs read as "store it, just revalidate", so
 * no-store and private are set explicitly.
 *
 * If a page cache is ever added to a site running this, the portal page needs
 * an exclusion rule there too. That requirement lives outside PHP and nothing
 * here can enforce it — it is in README.md for that reason.
 */
function gwcpp_send_no_cache_headers(): void {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	nocache_headers();

	if ( ! headers_sent() ) {
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
	}
}

/* ── The handler gate ────────────────────────────────────────────────────────
 * Every mutating handler starts the same way: a signed-in portal user, a post
 * that user actually has access to, and a nonce minted for that exact post.
 *
 * It matters more here than the usual don't-repeat-yourself argument would
 * suggest, because this IS the authorization model. The portal role holds no
 * real capabilities (see inc/access.php), so there is nothing underneath these
 * checks that would catch a handler which skipped one.
 *
 * Both guards either return a validated value or end the request. Neither ever
 * hands back something the caller still has to remember to check.
 * ─────────────────────────────────────────────────────────────────────────── */

/**
 * End the request at the portal.
 *
 * Access failures are silent by default. A post ID that does not exist, one
 * that belongs to somebody else, and one whose type was switched off all end
 * the same way, because any difference between them tells a prober which IDs
 * are real.
 *
 * @param string $url  Where to go.
 * @param string $text Message to show, or '' for silence.
 * @param string $type Flash type.
 */
function gwcpp_bail( string $url = '', string $text = '', string $type = 'warn' ): void {
	$url = '' !== $url ? $url : gwcpp_portal_url();

	wp_safe_redirect( '' === $text ? $url : gwcpp_flash_url( $url, $type, $text ) );
	exit;
}

/**
 * The submitted post ID, validated against this session's access and a nonce
 * minted for that same ID.
 *
 * Access is always resolved fresh from the session, never from the submission.
 * Which post is being acted on can only come from the submitted field — one
 * login may reach several — which is exactly why it is re-checked here rather
 * than trusted. A crafted ID can name a post but can never widen access to one.
 *
 * @param string $nonce_field POST key holding the nonce.
 * @param string $action      Nonce action, without the post ID.
 * @return int
 */
function gwcpp_guard_post( string $nonce_field, string $action ): int {
	if ( ! is_user_logged_in() ) {
		gwcpp_bail();
	}

	$user_id = get_current_user_id();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified below, against this same value.
	$post_id = isset( $_POST['gwcpp_post_id'] ) ? (int) $_POST['gwcpp_post_id'] : 0;

	if ( ! gwcpp_user_can_edit_post( $user_id, $post_id ) ) {
		gwcpp_bail();
	}

	if (
		! isset( $_POST[ $nonce_field ] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $action . $post_id )
	) {
		/* Back to the post they were on rather than the portal root. By this
		 * point the ID is known to be theirs, so naming it gives nothing away —
		 * and dropping somebody at the top of the portal after a long edit,
		 * with a message about a form, is how the message goes unread. */
		gwcpp_bail(
			gwcpp_portal_url(
				array(
					'gwcpp_view' => 'edit',
					'gwcpp_post' => $post_id,
				)
			),
			GWCPP_STALE_FORM
		);
	}

	return $post_id;
}

/* ── Handlers ────────────────────────────────────────────────────────────── */

/**
 * Save an edit.
 */
function gwcpp_handle_save(): void {
	$post_id = gwcpp_guard_post( 'gwcpp_save_nonce', 'gwcpp_save_' );
	$user_id = get_current_user_id();
	$post    = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		gwcpp_bail();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce verified in the guard above; every value is sanitized by its field type inside gwcpp_collect_submission().
	$raw     = isset( $_POST[ GWCPP_FIELD_PARAM ] ) && is_array( $_POST[ GWCPP_FIELD_PARAM ] ) ? $_POST[ GWCPP_FIELD_PARAM ] : array();
	$values  = gwcpp_collect_submission( $post->post_type, $raw );
	$dropped = gwcpp_dropped_fields( $post->post_type, $raw, $values );
	$errors  = gwcpp_validate_submission( $post->post_type, $values, $dropped );

	$edit_url = gwcpp_portal_url(
		array(
			'gwcpp_view' => 'edit',
			'gwcpp_post' => $post_id,
		)
	);

	if ( $errors ) {
		gwcpp_stash_submission( $user_id, $post_id, $values, $errors, $dropped );
		// No flash message: the form redraws with a summary at the top and
		// every message beside its own field, which says all of it better.
		wp_safe_redirect( $edit_url . '#gwcpp-errors' );
		exit;
	}

	/* Phase 1 writes straight through. The require_approval flag is already
	 * read by the button label and by the settings screen, and Phase 2 routes
	 * this call into a changeset instead. Until then a site with approval on
	 * would see a button promising review and no review, so the label asks
	 * gwcpp_save_button_label() which asks the same flag — keeping the two in
	 * step is the whole reason that is a function rather than a string here. */
	$changed = gwcpp_save_fields( $post_id, $values );

	gwcpp_bail(
		$edit_url,
		$changed
			? __( 'Saved. Thank you.', 'groundwork-common-post-portal' )
			: __( 'Nothing had changed, so there was nothing to save.', 'groundwork-common-post-portal' ),
		'ok'
	);
}

/**
 * Create a post.
 *
 * Guarded differently from the others, because there is no post ID yet to check
 * access against. What is checked instead: the post type is enabled, it allows
 * creating, and the user belongs to an organisation to create it into.
 */
function gwcpp_handle_create(): void {
	if ( ! is_user_logged_in() ) {
		gwcpp_bail();
	}

	$user_id = get_current_user_id();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below against this same value.
	$post_type = isset( $_POST['gwcpp_post_type'] ) ? sanitize_key( wp_unslash( $_POST['gwcpp_post_type'] ) ) : '';

	if (
		! isset( $_POST['gwcpp_create_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_create_nonce'] ) ), 'gwcpp_create_' . $post_type )
	) {
		gwcpp_bail( gwcpp_portal_url(), GWCPP_STALE_FORM );
	}

	if ( ! gwcpp_type_enabled( $post_type ) || ! gwcpp_type_setting( $post_type, 'allow_create' ) ) {
		gwcpp_bail();
	}

	$orgs = gwcpp_user_orgs( $user_id );
	if ( ! $orgs ) {
		gwcpp_bail();
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce verified above; every value is sanitized by its field type inside gwcpp_collect_submission().
	$raw     = isset( $_POST[ GWCPP_FIELD_PARAM ] ) && is_array( $_POST[ GWCPP_FIELD_PARAM ] ) ? $_POST[ GWCPP_FIELD_PARAM ] : array();
	$values  = gwcpp_collect_submission( $post_type, $raw );
	$dropped = gwcpp_dropped_fields( $post_type, $raw, $values );
	$errors  = gwcpp_validate_submission( $post_type, $values, $dropped );

	if ( $errors ) {
		gwcpp_stash_submission( $user_id, 0, $values, $errors, $dropped );
		wp_safe_redirect(
			gwcpp_portal_url(
				array(
					'gwcpp_view' => 'new',
					'gwcpp_type' => $post_type,
				)
			) . '#gwcpp-errors'
		);
		exit;
	}

	/* The first organisation, when somebody belongs to several. A picker is the
	 * right answer and it is a decision the create form should present, not one
	 * to guess at silently — noted here rather than solved, because the common
	 * case is exactly one organisation and a picker showing one option is
	 * worse than no picker. */
	$post_id = gwcpp_create_post( $post_type, $values, $user_id, (int) $orgs[0] );

	if ( is_wp_error( $post_id ) ) {
		gwcpp_bail( gwcpp_portal_url(), $post_id->get_error_message(), 'error' );
	}

	gwcpp_bail(
		gwcpp_portal_url(
			array(
				'gwcpp_view' => 'edit',
				'gwcpp_post' => $post_id,
			)
		),
		__( 'Added. It is not on the public site yet — somebody will review it first.', 'groundwork-common-post-portal' ),
		'ok'
	);
}

/**
 * Take a post off the public site.
 */
function gwcpp_handle_unpublish(): void {
	$post_id = gwcpp_guard_post( 'gwcpp_unpublish_nonce', 'gwcpp_unpublish_' );

	$done = gwcpp_unpublish_post( $post_id, get_current_user_id() );

	gwcpp_bail(
		gwcpp_portal_url(
			array(
				'gwcpp_view' => 'edit',
				'gwcpp_post' => $post_id,
			)
		),
		$done
			? __( 'That is now hidden from the public. Nothing has been deleted, and you can put it back.', 'groundwork-common-post-portal' )
			: __( 'That could not be changed.', 'groundwork-common-post-portal' ),
		$done ? 'ok' : 'warn'
	);
}

/**
 * Put an unpublished post back.
 */
function gwcpp_handle_republish(): void {
	$post_id = gwcpp_guard_post( 'gwcpp_republish_nonce', 'gwcpp_republish_' );

	$done = gwcpp_republish_post( $post_id );

	gwcpp_bail(
		gwcpp_portal_url(
			array(
				'gwcpp_view' => 'edit',
				'gwcpp_post' => $post_id,
			)
		),
		$done
			? __( 'That is back on the site.', 'groundwork-common-post-portal' )
			: __( 'That could not be changed.', 'groundwork-common-post-portal' ),
		$done ? 'ok' : 'warn'
	);
}

/* ── Views ───────────────────────────────────────────────────────────────── */

/**
 * Which view the URL is asking for.
 *
 * Whitelisted, because the value picks a render function.
 *
 * @return string
 */
function gwcpp_current_view(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection on a GET request.
	$view = isset( $_GET['gwcpp_view'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_view'] ) ) : 'list';

	return in_array( $view, GWCPP_PORTAL_VIEWS, true ) ? $view : 'list';
}

/**
 * The whole portal. This is what the block and the shortcode call.
 *
 * @return string
 */
function gwcpp_render_portal(): string {
	// Assets are enqueued from here as well as from the cheap has_block() guess
	// in enqueue.php, because that guess cannot see a shortcode inside a
	// widget, a template part, or another block's content.
	gwcpp_enqueue_portal_assets();

	ob_start();

	echo '<div class="gwcpp">';

	gwcpp_render_flash();

	if ( ! is_user_logged_in() || ! gwcpp_user_is_portal_user() ) {
		gwcpp_render_signin();
	} else {
		gwcpp_render_signed_in();
	}

	echo '</div>';

	return (string) ob_get_clean();
}

/**
 * The one-time message left by the last redirect.
 */
function gwcpp_render_flash(): void {
	$flash = gwcpp_flash();
	if ( null === $flash ) {
		return;
	}

	printf(
		'<div class="gwcpp-notice gwcpp-notice--%s" role="status" tabindex="-1"><p>%s</p></div>',
		esc_attr( $flash['type'] ),
		esc_html( $flash['text'] )
	);
}

/**
 * The signed-out view.
 */
function gwcpp_render_signin(): void {
	$magic    = (bool) gwcpp_setting( 'signin_magic' );
	$password = (bool) gwcpp_setting( 'signin_password' );

	/* Neither switched on is a configuration mistake rather than a state to
	 * render a form for. Saying so plainly beats an empty box that looks like
	 * the page failed to load. */
	if ( ! $magic && ! $password ) {
		printf(
			'<div class="gwcpp-empty"><p>%s</p></div>',
			esc_html__( 'Signing in is not set up yet. Please let whoever runs this site know.', 'groundwork-common-post-portal' )
		);
		return;
	}

	echo '<div class="gwcpp-signin">';

	if ( $magic ) {
		printf( '<h2 class="gwcpp-signin__title">%s</h2>', esc_html__( 'Sign in', 'groundwork-common-post-portal' ) );
		printf( '<p>%s</p>', esc_html__( 'Enter your email address and we will send you a link. There is no password to remember.', 'groundwork-common-post-portal' ) );

		echo '<form class="gwcpp-form" method="post" action="' . esc_url( gwcpp_portal_url() ) . '">';
		wp_nonce_field( 'gwcpp_signin', 'gwcpp_signin_nonce' );

		printf(
			'<div class="gwcpp-field"><label class="gwcpp-label" for="gwcpp-email">%s</label><input class="gwcpp-input" type="email" id="gwcpp-email" name="gwcpp_email" autocomplete="email" required /></div>',
			esc_html__( 'Email address', 'groundwork-common-post-portal' )
		);

		/* The honeypot. Hidden from people with CSS and from screen readers with
		 * aria-hidden, and taken out of the tab order — a field that is merely
		 * offscreen is a field a keyboard user tabs into and fills in, which
		 * silently discards their sign-in attempt with no way to find out why.
		 * autocomplete="off" stops a password manager doing the same. */
		echo '<div class="gwcpp-hp" aria-hidden="true"><label for="gwcpp-website">Website</label><input type="text" id="gwcpp-website" name="gwcpp_website" tabindex="-1" autocomplete="off" /></div>';

		printf(
			'<div class="gwcpp-actions"><button type="submit" name="gwcpp_request_link" value="1" class="gwcpp-button gwcpp-button--primary">%s</button></div>',
			esc_html__( 'Email me a link', 'groundwork-common-post-portal' )
		);

		echo '</form>';
	}

	if ( $password ) {
		if ( $magic ) {
			printf( '<p class="gwcpp-or">%s</p>', esc_html__( 'Or sign in with a password', 'groundwork-common-post-portal' ) );
		} else {
			printf( '<h2 class="gwcpp-signin__title">%s</h2>', esc_html__( 'Sign in', 'groundwork-common-post-portal' ) );
		}

		echo '<form class="gwcpp-form" method="post" action="' . esc_url( gwcpp_portal_url() ) . '">';
		wp_nonce_field( 'gwcpp_login', 'gwcpp_login_nonce' );

		printf(
			'<div class="gwcpp-field"><label class="gwcpp-label" for="gwcpp-user">%s</label><input class="gwcpp-input" type="text" id="gwcpp-user" name="gwcpp_user" autocomplete="username" required /></div>',
			esc_html__( 'Username or email', 'groundwork-common-post-portal' )
		);
		printf(
			'<div class="gwcpp-field"><label class="gwcpp-label" for="gwcpp-pass">%s</label><input class="gwcpp-input" type="password" id="gwcpp-pass" name="gwcpp_pass" autocomplete="current-password" required /></div>',
			esc_html__( 'Password', 'groundwork-common-post-portal' )
		);
		printf(
			'<div class="gwcpp-actions"><button type="submit" name="gwcpp_login" value="1" class="gwcpp-button gwcpp-button--primary">%s</button></div>',
			esc_html__( 'Sign in', 'groundwork-common-post-portal' )
		);

		echo '</form>';
	}

	echo '</div>';
}

/**
 * The signed-in views.
 */
function gwcpp_render_signed_in(): void {
	$user_id = get_current_user_id();

	gwcpp_render_portal_header( $user_id );

	switch ( gwcpp_current_view() ) {
		case 'edit':
			gwcpp_render_edit_view( $user_id );
			break;

		case 'new':
			gwcpp_render_new_view( $user_id );
			break;

		default:
			gwcpp_render_post_list( $user_id );
	}
}

/**
 * Who you are, and the way out.
 *
 * @param int $user_id User ID.
 */
function gwcpp_render_portal_header( int $user_id ): void {
	$user = get_userdata( $user_id );
	$orgs = gwcpp_user_org_names( $user_id );

	echo '<header class="gwcpp-header">';

	echo '<div class="gwcpp-header__who">';
	printf(
		'<span class="gwcpp-header__name">%s</span>',
		esc_html( $user ? $user->display_name : '' )
	);
	if ( '' !== $orgs ) {
		printf( '<span class="gwcpp-header__org">%s</span>', esc_html( $orgs ) );
	}
	echo '</div>';

	echo '<form class="gwcpp-header__out" method="post" action="' . esc_url( gwcpp_portal_url() ) . '">';
	wp_nonce_field( 'gwcpp_logout', 'gwcpp_logout_nonce' );
	printf(
		'<button type="submit" name="gwcpp_logout" value="1" class="gwcpp-button gwcpp-button--quiet">%s</button>',
		esc_html__( 'Sign out', 'groundwork-common-post-portal' )
	);
	echo '</form>';

	echo '</header>';

	if ( 'list' !== gwcpp_current_view() ) {
		printf(
			'<p class="gwcpp-back"><a href="%s">%s</a></p>',
			esc_url( gwcpp_portal_url() ),
			esc_html__( '← Back to everything', 'groundwork-common-post-portal' )
		);
	}
}

/**
 * The edit view.
 *
 * @param int $user_id User ID.
 */
function gwcpp_render_edit_view( int $user_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; access is checked immediately below.
	$post_id = isset( $_GET['gwcpp_post'] ) ? (int) $_GET['gwcpp_post'] : 0;

	if ( ! gwcpp_user_can_edit_post( $user_id, $post_id ) ) {
		/* The same words for "no such post" and "not yours". A view that said
		 * "you do not have access to this" would confirm the post exists. */
		printf(
			'<div class="gwcpp-empty"><p>%s</p></div>',
			esc_html__( 'That is not something you can edit.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$stash  = gwcpp_take_stash( $user_id, $post_id );
	$values = null !== $stash
		? array_merge( gwcpp_current_values( $post_id, $post->post_type ), $stash['values'] )
		: gwcpp_current_values( $post_id, $post->post_type );
	$errors = null !== $stash ? $stash['errors'] : array();

	printf(
		'<h1 class="gwcpp-title">%s</h1>',
		esc_html( '' !== trim( (string) $post->post_title ) ? $post->post_title : __( '(no title yet)', 'groundwork-common-post-portal' ) )
	);

	gwcpp_render_edit_form( $post, $values, $errors );
}

/**
 * The create view.
 *
 * @param int $user_id User ID.
 */
function gwcpp_render_new_view( int $user_id ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; permission is checked immediately below.
	$post_type = isset( $_GET['gwcpp_type'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_type'] ) ) : '';

	if (
		! gwcpp_type_enabled( $post_type )
		|| ! gwcpp_type_setting( $post_type, 'allow_create' )
		|| ! gwcpp_user_orgs( $user_id )
	) {
		printf(
			'<div class="gwcpp-empty"><p>%s</p></div>',
			esc_html__( 'That is not something you can add.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$object = get_post_type_object( $post_type );
	$stash  = gwcpp_take_stash( $user_id, 0 );

	printf(
		'<h1 class="gwcpp-title">%s</h1>',
		esc_html(
			sprintf(
				/* translators: %s: a post type's singular name. */
				__( 'Add a %s', 'groundwork-common-post-portal' ),
				$object ? $object->labels->singular_name : $post_type
			)
		)
	);

	gwcpp_render_create_form(
		$post_type,
		null !== $stash ? $stash['values'] : array(),
		null !== $stash ? $stash['errors'] : array()
	);
}
