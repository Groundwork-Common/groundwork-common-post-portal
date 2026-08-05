<?php
/**
 * Review state where staff actually look: the post list table.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'gwcpp_add_review_columns' );
add_action( 'restrict_manage_posts', 'gwcpp_review_filter_dropdown' );
add_action( 'pre_get_posts', 'gwcpp_review_filter_query' );
add_action( 'add_meta_boxes', 'gwcpp_add_review_meta_box', 30 );
add_action( 'save_post', 'gwcpp_save_review_meta', 10, 2 );

/**
 * Display strings for each state.
 *
 * One place, so the column, the meta box and the portal banner cannot end up
 * calling the same situation two different things.
 *
 * @return array<string, string>
 */
function gwcpp_review_labels(): array {
	static $labels = null;
	if ( null !== $labels ) {
		return $labels;
	}

	$labels = array(
		'current'   => __( 'Up to date', 'groundwork-common-post-portal' ),
		'due'       => __( 'Due soon', 'groundwork-common-post-portal' ),
		'overdue'   => __( 'Overdue', 'groundwork-common-post-portal' ),
		'expired'   => __( 'Not shown', 'groundwork-common-post-portal' ),
		'exempt'    => __( 'Never asked', 'groundwork-common-post-portal' ),
		'unmanaged' => __( 'Nobody to ask', 'groundwork-common-post-portal' ),
		'off'       => __( '—', 'groundwork-common-post-portal' ),
	);

	return $labels;
}

/**
 * Add the column to every post type on the cycle.
 */
function gwcpp_add_review_columns(): void {
	foreach ( gwcpp_post_types() as $post_type ) {
		if ( ! gwcpp_review_enabled( $post_type ) ) {
			continue;
		}

		add_filter( 'manage_' . $post_type . '_posts_columns', 'gwcpp_review_column' );
		add_action( 'manage_' . $post_type . '_posts_custom_column', 'gwcpp_review_column_value', 10, 2 );
	}
}

/**
 * Register the column.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function gwcpp_review_column( $columns ) {
	if ( ! is_array( $columns ) ) {
		return $columns;
	}

	/*
	 * Inserted before the date column rather than appended, because appended
	 * puts it after Date where nobody scanning a list looks.
	 */
	$out = array();
	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$out['gwcpp_review'] = __( 'Reviewed', 'groundwork-common-post-portal' );
		}
		$out[ $key ] = $label;
	}

	if ( ! isset( $out['gwcpp_review'] ) ) {
		$out['gwcpp_review'] = __( 'Reviewed', 'groundwork-common-post-portal' );
	}

	return $out;
}

/**
 * Print one cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function gwcpp_review_column_value( $column, $post_id ): void {
	if ( 'gwcpp_review' !== $column ) {
		return;
	}

	$state  = gwcpp_review_state( (int) $post_id );
	$labels = gwcpp_review_labels();

	printf(
		'<span class="gwcpp-state gwcpp-state--%s">%s</span>',
		esc_attr( (string) $state['state'] ),
		esc_html( $labels[ $state['state'] ] ?? (string) $state['state'] )
	);

	if ( empty( $state['enabled'] ) ) {
		return;
	}

	printf(
		'<br /><span class="gwcpp-state__when">%s</span>',
		esc_html(
			'' !== (string) $state['reviewed_at']
				? sprintf(
					/* translators: %s: a date. */
					__( 'last %s', 'groundwork-common-post-portal' ),
					(string) $state['reviewed_at']
				)
				: __( 'never confirmed', 'groundwork-common-post-portal' )
		)
	);
}

/**
 * The filter dropdown above the list.
 */
function gwcpp_review_filter_dropdown(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'edit' !== $screen->base || ! gwcpp_review_enabled( (string) $screen->post_type ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering on a GET request.
	$current = isset( $_GET['gwcpp_review_filter'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_review_filter'] ) ) : '';

	$options = array(
		'needs'     => __( 'Needs confirming', 'groundwork-common-post-portal' ),
		'expired'   => __( 'Not shown', 'groundwork-common-post-portal' ),
		'unmanaged' => __( 'Nobody to ask', 'groundwork-common-post-portal' ),
		'exempt'    => __( 'Never asked', 'groundwork-common-post-portal' ),
	);

	echo '<select name="gwcpp_review_filter">';
	printf( '<option value="">%s</option>', esc_html__( 'Any review state', 'groundwork-common-post-portal' ) );
	foreach ( $options as $value => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
}

/**
 * Apply the filter.
 *
 * ── Why this is a meta_query and not a PHP filter ───────────────────────────
 * Filtering the rows after WP_Query has run is far easier and produces a list
 * whose paging and counts are lies: "showing 20 of 340" when eleven survived the
 * filter, page two showing the same rows again. Every state below therefore has
 * to be expressible as a date comparison against the stored review date.
 *
 * Two of them are not, quite. `unmanaged` depends on whether anybody holds
 * access, which lives in different meta on different objects — so it is narrowed
 * here to "past expiry" and left exact in the column, which is the honest
 * trade: the list is a superset a human then reads.
 *
 * @param WP_Query $query The query.
 */
function gwcpp_review_filter_query( $query ): void {
	if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
		return;
	}

	/*
	 * Checked for an array rather than cast straight to string. A post list
	 * screen sets one post type, but 'post_type' can hold an array on any query
	 * this filter is handed, and (string) on an array is the notice "Array to
	 * string conversion" followed by the literal filter running against a post
	 * type named "Array".
	 */
	$post_type = $query->get( 'post_type' );
	if ( ! is_string( $post_type ) || '' === $post_type || ! gwcpp_review_enabled( $post_type ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filtering.
	$filter = isset( $_GET['gwcpp_review_filter'] ) ? sanitize_key( wp_unslash( $_GET['gwcpp_review_filter'] ) ) : '';
	if ( '' === $filter ) {
		return;
	}

	$cadence = gwcpp_review_cadence( $post_type );
	$today   = gwcpp_review_today();

	$meta = array( 'relation' => 'AND' );

	if ( 'exempt' === $filter ) {
		$meta[] = array(
			'key'     => GWCPP_REVIEW_EXEMPT_META,
			'compare' => 'EXISTS',
		);
		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- An admin list filter is unavoidably a meta query.
		return;
	}

	$meta[] = array(
		'key'     => GWCPP_REVIEW_EXEMPT_META,
		'compare' => 'NOT EXISTS',
	);

	if ( 'expired' === $filter ) {
		$meta[] = array(
			'key'     => GWCPP_AUTO_EXPIRED_META,
			'compare' => 'EXISTS',
		);
		$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- As above.
		return;
	}

	/*
	 * "Needs confirming" is everything whose review date is older than the first
	 * reminder threshold, PLUS everything that has never been confirmed at all —
	 * and the second group is the one a naive date comparison silently drops,
	 * because a row that does not exist does not compare less than anything.
	 */
	$months = 'unmanaged' === $filter ? $cadence * 2 : max( 1, $cadence - 1 );
	$cutoff = $today->modify( '-' . $months . ' months' )->format( 'Y-m-d' );

	$meta[] = array(
		'relation' => 'OR',
		array(
			'key'     => GWCPP_REVIEWED_META,
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => GWCPP_REVIEWED_META,
			'value'   => $cutoff,
			'compare' => '<=',
			'type'    => 'DATE',
		),
	);

	$query->set( 'meta_query', $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- As above.
}

/* ── On the post itself ──────────────────────────────────────────────────── */

/**
 * Register the review box.
 */
function gwcpp_add_review_meta_box(): void {
	foreach ( gwcpp_post_types() as $post_type ) {
		if ( ! gwcpp_review_enabled( $post_type ) ) {
			continue;
		}

		add_meta_box(
			'gwcpp-review',
			__( 'Review', 'groundwork-common-post-portal' ),
			'gwcpp_render_review_meta_box',
			$post_type,
			'side',
			'default'
		);
	}
}

/**
 * The review box.
 *
 * @param WP_Post $post The post.
 */
function gwcpp_render_review_meta_box( WP_Post $post ): void {
	$state  = gwcpp_review_state( $post->ID );
	$labels = gwcpp_review_labels();

	wp_nonce_field( 'gwcpp_review_' . $post->ID, 'gwcpp_review_nonce' );

	printf(
		'<p><strong>%s</strong></p>',
		esc_html( $labels[ $state['state'] ] ?? '' )
	);

	if ( ! empty( $state['enabled'] ) ) {
		printf(
			'<p class="description">%s<br />%s</p>',
			esc_html(
				'' !== (string) $state['reviewed_at']
					? sprintf(
						/* translators: %s: a date. */
						__( 'Last confirmed %s.', 'groundwork-common-post-portal' ),
						(string) $state['reviewed_at']
					)
					: __( 'Never confirmed. Counting from when it was published.', 'groundwork-common-post-portal' )
			),
			esc_html(
				sprintf(
					/* translators: %s: a date. */
					__( 'Stops being shown on %s.', 'groundwork-common-post-portal' ),
					(string) $state['expires_on']
				)
			)
		);

		if ( empty( $state['managed'] ) ) {
			printf(
				'<p class="description" style="color:#b32d2e;">%s</p>',
				esc_html__( 'Nobody has access to this, so nobody can confirm it — and it will never be hidden automatically. Invite somebody, or mark it below.', 'groundwork-common-post-portal' )
			);
		}
	}

	printf(
		'<p><label><input type="checkbox" name="gwcpp_review_exempt" value="1"%s /> %s</label></p>',
		checked( ! empty( $state['exempt'] ), true, false ),
		esc_html__( 'Never ask about this one', 'groundwork-common-post-portal' )
	);

	if ( ! empty( $state['hidden'] ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'This was taken off the site by the review cycle. Publishing it again by hand also clears that.', 'groundwork-common-post-portal' )
		);
	}

	printf(
		'<p><label><input type="checkbox" name="gwcpp_review_now" value="1" /> %s</label></p>',
		esc_html__( 'Mark as confirmed today', 'groundwork-common-post-portal' )
	);
}

/**
 * Save the review box.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    The post.
 */
function gwcpp_save_review_meta( $post_id, $post ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! $post instanceof WP_Post || ! gwcpp_review_enabled( $post->post_type ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if (
		! isset( $_POST['gwcpp_review_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gwcpp_review_nonce'] ) ), 'gwcpp_review_' . $post_id )
	) {
		return;
	}

	if ( empty( $_POST['gwcpp_review_exempt'] ) ) {
		delete_post_meta( (int) $post_id, GWCPP_REVIEW_EXEMPT_META );
	} else {
		update_post_meta( (int) $post_id, GWCPP_REVIEW_EXEMPT_META, 1 );
	}

	if ( ! empty( $_POST['gwcpp_review_now'] ) ) {
		gwcpp_record_review( (int) $post_id, get_current_user_id() );
	}

	/*
	 * Publishing an auto-expired entry by hand clears the marker, so the cycle
	 * does not later "restore" something staff had already restored — and so the
	 * digest stops listing it as hidden the moment it stops being hidden.
	 */
	if ( 'publish' === $post->post_status && get_post_meta( $post_id, GWCPP_AUTO_EXPIRED_META, true ) ) {
		delete_post_meta( (int) $post_id, GWCPP_AUTO_EXPIRED_META );
	}
}
