<?php
/**
 * The list a signed-in portal user lands on.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/** How many entries appear before the list pages. */
const GWCPP_PER_PAGE = 20;

/**
 * The list of everything this user may edit.
 *
 * @param int $user_id User ID.
 */
function gwcpp_render_post_list( int $user_id ): void {
	$ids = gwcpp_editable_post_ids( $user_id );

	gwcpp_render_create_buttons( $user_id );

	if ( ! $ids ) {
		printf(
			'<div class="gwcpp-empty"><p>%s</p><p>%s</p></div>',
			esc_html__( 'There is nothing here for you to edit yet.', 'groundwork-common-post-portal' ),
			esc_html__( 'If you were expecting something, whoever invited you can check that it has been assigned to your organisation.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$page  = gwcpp_current_page();
	$pages = (int) ceil( count( $ids ) / GWCPP_PER_PAGE );
	$page  = max( 1, min( $pages, $page ) );

	/*
	 * Sorted by title rather than by date. A portal user is looking for one
	 * specific record they already know the name of, which is a different task
	 * from browsing a blog, and "most recently modified first" reorders the
	 * list under them every time they save something.
	 */
	$posts = array_filter( array_map( 'get_post', $ids ) );
	usort(
		$posts,
		static function ( $a, $b ) {
			return strcasecmp( (string) $a->post_title, (string) $b->post_title );
		}
	);

	$posts = array_slice( $posts, ( $page - 1 ) * GWCPP_PER_PAGE, GWCPP_PER_PAGE );

	echo '<ul class="gwcpp-list">';
	foreach ( $posts as $post ) {
		gwcpp_render_list_row( $post );
	}
	echo '</ul>';

	gwcpp_render_pagination( $page, $pages );
}

/**
 * One row.
 *
 * @param WP_Post $post The post.
 */
function gwcpp_render_list_row( WP_Post $post ): void {
	$object = get_post_type_object( $post->post_type );
	$title  = (string) $post->post_title;

	if ( '' === trim( $title ) ) {
		$title = __( '(no title yet)', 'groundwork-common-post-portal' );
	}

	$edit = gwcpp_portal_url(
		array(
			'gwcpp_view' => 'edit',
			'gwcpp_post' => $post->ID,
		)
	);

	echo '<li class="gwcpp-list__item">';
	printf(
		'<a class="gwcpp-list__link" href="%s"><span class="gwcpp-list__title">%s</span></a>',
		esc_url( $edit ),
		esc_html( $title )
	);

	echo '<span class="gwcpp-list__meta">';

	if ( $object && count( gwcpp_post_types() ) > 1 ) {
		// Only worth showing when the list can hold more than one kind of
		// thing. On a single-type portal it is the same word on every row.
		printf(
			'<span class="gwcpp-list__type">%s</span>',
			esc_html( $object->labels->singular_name )
		);
	}

	printf(
		'<span class="gwcpp-badge gwcpp-badge--%s">%s</span>',
		esc_attr( $post->post_status ),
		esc_html( gwcpp_status_label( $post->post_status ) )
	);

	echo '</span></li>';
}

/**
 * The Add buttons, for post types that allow creating.
 *
 * @param int $user_id User ID.
 */
function gwcpp_render_create_buttons( int $user_id ): void {
	$creatable = array();

	foreach ( gwcpp_post_types() as $post_type ) {
		if ( gwcpp_type_setting( $post_type, 'allow_create' ) ) {
			$creatable[] = $post_type;
		}
	}

	/*
	 * Creating needs somewhere to put it. A user with no organisation and no
	 * way to be granted one would create posts only they can see, which looks
	 * like the feature working right up until somebody asks where it went.
	 */
	if ( ! $creatable || ! gwcpp_user_orgs( $user_id ) ) {
		return;
	}

	echo '<div class="gwcpp-actions gwcpp-actions--top">';
	foreach ( $creatable as $post_type ) {
		$object = get_post_type_object( $post_type );
		if ( ! $object ) {
			continue;
		}
		printf(
			'<a class="gwcpp-button" href="%s">%s</a>',
			esc_url(
				gwcpp_portal_url(
					array(
						'gwcpp_view' => 'new',
						'gwcpp_type' => $post_type,
					)
				)
			),
			esc_html(
				sprintf(
					/* translators: %s: a post type's singular name, e.g. "Location". */
					__( 'Add a %s', 'groundwork-common-post-portal' ),
					$object->labels->singular_name
				)
			)
		);
	}
	echo '</div>';
}

/**
 * The current page number from the URL.
 *
 * @return int
 */
function gwcpp_current_page(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination on a GET request.
	$page = isset( $_GET['gwcpp_page'] ) ? (int) $_GET['gwcpp_page'] : 1;

	return max( 1, $page );
}

/**
 * Previous / next links.
 *
 * @param int $page  Current page.
 * @param int $pages Total pages.
 */
function gwcpp_render_pagination( int $page, int $pages ): void {
	if ( $pages < 2 ) {
		return;
	}

	echo '<nav class="gwcpp-pagination" aria-label="' . esc_attr__( 'Pages', 'groundwork-common-post-portal' ) . '">';

	if ( $page > 1 ) {
		printf(
			'<a class="gwcpp-button gwcpp-button--quiet" href="%s">%s</a>',
			esc_url( gwcpp_portal_url( array( 'gwcpp_page' => $page - 1 ) ) ),
			esc_html__( 'Previous', 'groundwork-common-post-portal' )
		);
	}

	printf(
		'<span class="gwcpp-pagination__where">%s</span>',
		esc_html(
			sprintf(
				/* translators: 1: current page number, 2: total pages. */
				__( 'Page %1$d of %2$d', 'groundwork-common-post-portal' ),
				$page,
				$pages
			)
		)
	);

	if ( $page < $pages ) {
		printf(
			'<a class="gwcpp-button gwcpp-button--quiet" href="%s">%s</a>',
			esc_url( gwcpp_portal_url( array( 'gwcpp_page' => $page + 1 ) ) ),
			esc_html__( 'Next', 'groundwork-common-post-portal' )
		);
	}

	echo '</nav>';
}
