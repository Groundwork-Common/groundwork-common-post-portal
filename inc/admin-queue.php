<?php
/**
 * The approval queue: what is waiting, and the two buttons that resolve it.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_gwcpp_approve', 'gwcpp_handle_approve' );
add_action( 'admin_post_gwcpp_reject', 'gwcpp_handle_reject' );
add_action( 'add_meta_boxes', 'gwcpp_add_pending_meta_box', 20 );

/**
 * The queue screen.
 */
function gwcpp_queue_screen(): void {
	gwcpp_require_admin_caps();

	$pending = gwcpp_pending_post_ids();

	echo '<div class="wrap gwcpp-admin">';
	printf( '<h1>%s</h1>', esc_html__( 'Pending Changes', 'groundwork-common-post-portal' ) );

	gwcpp_render_admin_notice();

	if ( ! $pending ) {
		printf(
			'<div class="gwcpp-empty-state"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'Nothing is waiting.', 'groundwork-common-post-portal' ),
			esc_html__( 'Changes submitted from the portal appear here, and the live entry keeps showing what it showed before until you approve them.', 'groundwork-common-post-portal' )
		);
		echo '</div>';
		return;
	}

	foreach ( $pending as $post_id ) {
		gwcpp_render_queue_item( (int) $post_id );
	}

	echo '</div>';
}

/**
 * One waiting change.
 *
 * @param int $post_id Post ID.
 */
function gwcpp_render_queue_item( int $post_id ): void {
	$changeset = gwcpp_get_changeset( $post_id );
	$post      = get_post( $post_id );

	if ( null === $changeset || ! $post instanceof WP_Post ) {
		return;
	}

	$diff = gwcpp_changeset_diff( $post_id );
	$who  = get_userdata( $changeset['user'] );

	echo '<div class="postbox gwcpp-queue-item"><div class="inside">';

	printf(
		'<h2 class="gwcpp-queue-item__title"><a href="%s">%s</a></h2>',
		esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ),
		esc_html( '' !== trim( (string) $post->post_title ) ? $post->post_title : __( '(no title)', 'groundwork-common-post-portal' ) )
	);

	printf(
		'<p class="description">%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: an email address, 2: a length of time, e.g. "2 hours". */
				__( 'Submitted by %1$s, %2$s ago.', 'groundwork-common-post-portal' ),
				$who ? $who->user_email : __( 'somebody', 'groundwork-common-post-portal' ),
				human_time_diff( $changeset['time'] )
			)
		)
	);

	if ( ! $diff ) {
		/* The submitted values match what is stored. That happens when staff
		 * made the same edit by hand while this was waiting — so say so, rather
		 * than showing an empty table and leaving somebody to work out whether
		 * the queue is broken. */
		printf(
			'<p class="gwcpp-queue-item__same">%s</p>',
			esc_html__( 'Nothing here differs from the entry any more — somebody has already made these changes. Approving will simply clear it.', 'groundwork-common-post-portal' )
		);
	} else {
		gwcpp_render_diff_table( $diff );
	}

	gwcpp_render_queue_actions( $post_id );

	echo '</div></div>';
}

/**
 * The old-against-new table.
 *
 * @param array $diff From gwcpp_changeset_diff().
 */
function gwcpp_render_diff_table( array $diff ): void {
	echo '<table class="widefat striped gwcpp-diff"><thead><tr>';
	printf( '<th scope="col">%s</th>', esc_html__( 'Field', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Now', 'groundwork-common-post-portal' ) );
	printf( '<th scope="col">%s</th>', esc_html__( 'Proposed', 'groundwork-common-post-portal' ) );
	echo '</tr></thead><tbody>';

	foreach ( $diff as $row ) {
		$old = '' !== trim( (string) $row['old'] ) ? (string) $row['old'] : '—';
		$new = '' !== trim( (string) $row['new'] ) ? (string) $row['new'] : '—';

		printf(
			'<tr><th scope="row">%s</th><td class="gwcpp-diff__old">%s</td><td class="gwcpp-diff__new">%s</td></tr>',
			esc_html( (string) $row['label'] ),
			esc_html( $old ),
			esc_html( $new )
		);
	}

	echo '</tbody></table>';
}

/**
 * Approve and reject, as two separate forms.
 *
 * Two forms rather than two buttons in one, so that a stray Enter in the note
 * field cannot submit the approval. The note belongs to reject, and the
 * keyboard should agree.
 *
 * @param int $post_id Post ID.
 */
function gwcpp_render_queue_actions( int $post_id ): void {
	echo '<div class="gwcpp-queue-actions">';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'gwcpp_approve_' . $post_id );
	echo '<input type="hidden" name="action" value="gwcpp_approve" />';
	printf( '<input type="hidden" name="post" value="%d" />', $post_id );
	printf(
		'<button type="submit" class="button button-primary">%s</button>',
		esc_html__( 'Approve and publish', 'groundwork-common-post-portal' )
	);
	echo '</form>';

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gwcpp-queue-reject">';
	wp_nonce_field( 'gwcpp_reject_' . $post_id );
	echo '<input type="hidden" name="action" value="gwcpp_reject" />';
	printf( '<input type="hidden" name="post" value="%d" />', $post_id );
	printf(
		'<label class="screen-reader-text" for="gwcpp-note-%1$d">%2$s</label><input type="text" id="gwcpp-note-%1$d" name="note" class="regular-text" placeholder="%3$s" />',
		$post_id,
		esc_html__( 'Why not', 'groundwork-common-post-portal' ),
		esc_attr__( 'Why not — they will be sent this', 'groundwork-common-post-portal' )
	);
	printf(
		' <button type="submit" class="button gwcpp-button-reject">%s</button>',
		esc_html__( 'Reject', 'groundwork-common-post-portal' )
	);
	echo '</form>';

	echo '</div>';
}

/* ── Handlers ────────────────────────────────────────────────────────────── */

/**
 * The post ID a queue handler was given, validated against the nonce.
 *
 * @param string $action Nonce action prefix.
 * @return int
 */
function gwcpp_queue_guard( string $action ): int {
	gwcpp_require_admin_caps();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below against this same value.
	$post_id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;

	check_admin_referer( $action . $post_id );

	/* manage_options got them onto the screen; edit_post is what says they may
	 * change THIS post. On a site using per-post permissions those are not the
	 * same question, and approving is an edit. */
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die(
			esc_html__( 'You cannot change that.', 'groundwork-common-post-portal' ),
			'',
			array( 'response' => 403 )
		);
	}

	return $post_id;
}

/**
 * Approve a change.
 */
function gwcpp_handle_approve(): void {
	$post_id   = gwcpp_queue_guard( 'gwcpp_approve_' );
	$changeset = gwcpp_get_changeset( $post_id );

	if ( null === $changeset ) {
		gwcpp_queue_redirect( 'queue_gone' );
	}

	gwcpp_apply_changeset( $post_id, get_current_user_id() );
	gwcpp_notify_submitter_approved( $post_id, $changeset['user'] );

	gwcpp_queue_redirect( 'approved' );
}

/**
 * Reject a change.
 */
function gwcpp_handle_reject(): void {
	$post_id   = gwcpp_queue_guard( 'gwcpp_reject_' );
	$changeset = gwcpp_get_changeset( $post_id );

	if ( null === $changeset ) {
		gwcpp_queue_redirect( 'queue_gone' );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in the guard above.
	$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

	gwcpp_reject_changeset( $post_id, get_current_user_id(), $note );
	gwcpp_notify_submitter_rejected( $post_id, $changeset['user'], $note );

	gwcpp_queue_redirect( 'rejected' );
}

/**
 * Back to the queue with a message code.
 *
 * @param string $code Message code.
 */
function gwcpp_queue_redirect( string $code ): void {
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'         => GWCPP_QUEUE_SLUG,
				'gwcpp_notice' => $code,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/* ── On the post itself ──────────────────────────────────────────────────── */

/**
 * Register the pending box on posts that have one.
 */
function gwcpp_add_pending_meta_box(): void {
	foreach ( gwcpp_post_types() as $post_type ) {
		add_meta_box(
			'gwcpp-pending',
			__( 'Waiting for review', 'groundwork-common-post-portal' ),
			'gwcpp_render_pending_meta_box',
			$post_type,
			'normal',
			'high'
		);
	}
}

/**
 * The pending box.
 *
 * Registered for every enabled post type and renders nothing when there is
 * nothing waiting — add_meta_box has no "only if" argument, and hiding an empty
 * box with CSS would still leave it in Screen Options as a permanent panel that
 * is usually blank.
 *
 * @param WP_Post $post The post.
 */
function gwcpp_render_pending_meta_box( WP_Post $post ): void {
	$changeset = gwcpp_get_changeset( $post->ID );

	if ( null === $changeset ) {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Nothing is waiting on this one.', 'groundwork-common-post-portal' )
		);
		return;
	}

	$who = get_userdata( $changeset['user'] );

	printf(
		'<p><strong>%s</strong></p>',
		esc_html(
			sprintf(
				/* translators: 1: an email address, 2: a length of time. */
				__( '%1$s submitted changes %2$s ago. They are not on the site.', 'groundwork-common-post-portal' ),
				$who ? $who->user_email : __( 'Somebody', 'groundwork-common-post-portal' ),
				human_time_diff( $changeset['time'] )
			)
		)
	);

	$diff = gwcpp_changeset_diff( $post->ID );
	if ( $diff ) {
		gwcpp_render_diff_table( $diff );
	}

	gwcpp_render_queue_actions( $post->ID );

	/* Worth saying out loud on this screen in particular: staff editing the
	 * fields above while a changeset is pending is fine, and approving
	 * afterwards will overwrite whatever they typed with what the portal user
	 * sent. The diff is computed live, so it will show that — but only to
	 * somebody who re-reads it. */
	printf(
		'<p class="description">%s</p>',
		esc_html__( 'If you edit these fields yourself and then approve, the submitted values win. Reject instead if you have already made the change.', 'groundwork-common-post-portal' )
	);
}
