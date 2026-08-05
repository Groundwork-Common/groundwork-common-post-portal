<?php
/**
 * Pending changes: held off the live post until somebody approves them.
 *
 * @package PostPortal
 */

defined( 'ABSPATH' ) || exit;

/*
 * ── The live post is never touched ──────────────────────────────────────────
 * A submission under approval is stored whole, in one post meta row, and the
 * published post carries on showing exactly what it showed before.
 *
 * The alternative — the one the original portal's successor was going to use —
 * is to flip post_status to 'pending'. That is a line of code and it takes a
 * live directory listing off the public site the moment somebody corrects a
 * typo in it, for however long staff take to notice. For a listing families
 * rely on to find a food bank, "your entry disappears while we review your
 * phone number" is not a trade anyone would agree to if asked.
 *
 * ── One changeset per post, not a queue ─────────────────────────────────────
 * Submitting again replaces what was there. A queue sounds more careful and is
 * worse: staff would approve edit 1, then approve edit 2 written against the
 * pre-edit-1 values, silently reverting the first. Since both submissions come
 * from the same small group of people looking at the same form, "the latest
 * thing they sent is what they mean" is both simpler and truer.
 *
 * ── The diff is computed, never stored ──────────────────────────────────────
 * gwcpp_changeset_diff() reads the current values at the moment it is called.
 * If staff edit the post in wp-admin while a changeset is pending, the old side
 * of the comparison updates to match, so what they approve is what they were
 * shown. A diff frozen at submission time would quietly describe a post that no
 * longer exists.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** Post meta, single: the pending changeset. */
const GWCPP_PENDING_META = '_gwcpp_pending';

/** The approval queue's admin page. Declared here rather than in
 *  admin-queue.php because the notification emails link to it, and email is
 *  built on requests where no admin screen has loaded.
 */
const GWCPP_QUEUE_SLUG = 'gwcpp-pending';

/** Post meta, single: the last few applied changes, for staff. */
const GWCPP_LOG_META = '_gwcpp_change_log';

/** Transient: the queue count for the menu bubble. See gwcpp_pending_count(). */
const GWCPP_PENDING_COUNT_TRANSIENT = 'gwcpp_pending_count';

/** How many entries the log keeps. */
const GWCPP_LOG_LENGTH = 10;

/**
 * Store a submission for review, replacing any earlier one.
 *
 * @param int   $post_id     Post ID.
 * @param int   $user_id     Who submitted it.
 * @param array $values      Sanitized values, keyed by field key.
 * @param array $attachments Attachment IDs uploaded with this submission.
 * @return bool
 */
function gwcpp_store_changeset( int $post_id, int $user_id, array $values, array $attachments = array() ): bool {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	/*
	 * Anything uploaded for a changeset that is now being replaced has nothing
	 * left pointing at it. Cleaned up here rather than left for the cron reaper
	 * so that somebody who uploads the wrong photo three times does not leave
	 * three orphans in the media library for a month.
	 */
	$previous = gwcpp_get_changeset( $post_id );
	if ( null !== $previous ) {
		gwcpp_discard_attachments( array_diff( $previous['attachments'], $attachments ) );
	}

	$stored = update_post_meta(
		$post_id,
		GWCPP_PENDING_META,
		wp_slash(
			array(
				'user'        => $user_id,
				'time'        => time(),
				'values'      => $values,
				'attachments' => array_values( array_map( 'intval', $attachments ) ),
			)
		)
	);

	/**
	 * Fires when a submission is stored for review.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $user_id Who submitted it.
	 * @param array $values  The submitted values.
	 */
	do_action( 'gwcpp_changeset_stored', $post_id, $user_id, $values );

	return (bool) $stored;
}

/**
 * The pending changeset on a post, structurally complete, or null.
 *
 * @param int $post_id Post ID.
 * @return array{user:int,time:int,values:array,attachments:array}|null
 */
function gwcpp_get_changeset( int $post_id ): ?array {
	$stored = get_post_meta( $post_id, GWCPP_PENDING_META, true );

	if ( ! is_array( $stored ) || ! isset( $stored['values'] ) || ! is_array( $stored['values'] ) ) {
		return null;
	}

	return array(
		'user'        => (int) ( $stored['user'] ?? 0 ),
		'time'        => (int) ( $stored['time'] ?? 0 ),
		'values'      => (array) $stored['values'],
		'attachments' => isset( $stored['attachments'] ) ? array_map( 'intval', (array) $stored['attachments'] ) : array(),
	);
}

/**
 * True when a post is waiting for review.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function gwcpp_has_changeset( int $post_id ): bool {
	return null !== gwcpp_get_changeset( $post_id );
}

/**
 * What a pending changeset would change, old against new.
 *
 * Only fields that actually differ come back. A submission where somebody
 * corrected one phone number should show one row, not twenty rows of which
 * nineteen say the same thing twice — that is the difference between a diff
 * somebody reads and a diff somebody approves without reading.
 *
 * @param int $post_id Post ID.
 * @return array<int, array{key:string,label:string,old:string,new:string}>
 */
function gwcpp_changeset_diff( int $post_id ): array {
	$changeset = gwcpp_get_changeset( $post_id );

	if ( null === $changeset ) {
		return array();
	}

	return gwcpp_diff_values( $post_id, $changeset['values'] );
}

/**
 * What a set of submitted values would change on a post.
 *
 * Split out from gwcpp_changeset_diff() because the immediate-save path needs
 * exactly the same comparison and has no changeset to read it from — and
 * because it has to run BEFORE the values are written, when a diff computed
 * afterwards would correctly report that nothing had changed.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Sanitized values, keyed by field key.
 * @return array<int, array{key:string,label:string,old:string,new:string}>
 */
function gwcpp_diff_values( int $post_id, array $values ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$current = gwcpp_current_values( $post_id, $post->post_type );
	$diff    = array();

	foreach ( gwcpp_type_fields( $post->post_type ) as $field ) {
		$key = (string) $field['key'];

		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}

		$old = $current[ $key ] ?? '';
		$new = $values[ $key ];

		/*
		 * Compared as their displayed text rather than as raw values. Two
		 * values that render identically are not a change worth showing anybody
		 * — and comparing raw would report '1' against 1, or a reordered array
		 * against itself, as edits nobody made.
		 */
		$old_text = (string) gwcpp_field_call( $field, 'to_display', array( $old, $field ) );
		$new_text = (string) gwcpp_field_call( $field, 'to_display', array( $new, $field ) );

		if ( $old_text === $new_text ) {
			continue;
		}

		$diff[] = array(
			'key'   => $key,
			'label' => gwcpp_field_label( $field ),
			'old'   => $old_text,
			'new'   => $new_text,
		);
	}

	return $diff;
}

/**
 * Apply a pending changeset to its post.
 *
 * Replays through gwcpp_save_fields(), the same path an immediate save uses, so
 * approving cannot write anything a direct save could not. In particular a
 * field retired from the schema between submission and approval is simply not
 * written, because that function iterates the schema rather than the values.
 *
 * @param int $post_id     Post ID.
 * @param int $approved_by Who approved it.
 * @return bool True when something was applied.
 */
function gwcpp_apply_changeset( int $post_id, int $approved_by = 0 ): bool {
	$changeset = gwcpp_get_changeset( $post_id );
	if ( null === $changeset ) {
		return false;
	}

	/*
	 * Computed before anything is written. Afterwards the stored values ARE the
	 * current values, and the diff would correctly say nothing changed.
	 */
	$diff = gwcpp_changeset_diff( $post_id );

	gwcpp_attach_uploads( $changeset['attachments'], $post_id );

	$changed = gwcpp_save_fields( $post_id, $changeset['values'] );

	delete_post_meta( $post_id, GWCPP_PENDING_META );

	gwcpp_log_change( $post_id, $changeset['user'], $approved_by, $diff );

	/**
	 * Fires after a changeset is applied.
	 *
	 * @param int   $post_id     Post ID.
	 * @param array $changeset   What was applied.
	 * @param int   $approved_by Who approved it.
	 */
	do_action( 'gwcpp_changeset_applied', $post_id, $changeset, $approved_by );

	return $changed;
}

/**
 * Throw a pending changeset away.
 *
 * @param int    $post_id     Post ID.
 * @param int    $rejected_by Who rejected it.
 * @param string $note        Optional message for the submitter.
 * @return bool
 */
function gwcpp_reject_changeset( int $post_id, int $rejected_by = 0, string $note = '' ): bool {
	$changeset = gwcpp_get_changeset( $post_id );
	if ( null === $changeset ) {
		return false;
	}

	/*
	 * Uploads that only ever existed for this submission go with it. Anything
	 * already attached to the post is left alone — an attachment can be
	 * referenced from somewhere this function cannot see.
	 */
	gwcpp_discard_attachments( $changeset['attachments'] );

	delete_post_meta( $post_id, GWCPP_PENDING_META );

	/**
	 * Fires after a changeset is rejected.
	 *
	 * @param int    $post_id     Post ID.
	 * @param array  $changeset   What was rejected.
	 * @param int    $rejected_by Who rejected it.
	 * @param string $note        The message sent to the submitter.
	 */
	do_action( 'gwcpp_changeset_rejected', $post_id, $changeset, $rejected_by, $note );

	return true;
}

/** How many waiting changes the queue screen draws before it stops. */
const GWCPP_QUEUE_PAGE_SIZE = 200;

/**
 * The posts waiting for review, most recently touched first.
 *
 * Bounded, because the caller is a screen that renders a diff table per item.
 * Anything that has to be *right* rather than merely readable — the count, the
 * reaper — wants gwcpp_every_pending_post_id() instead.
 *
 * @param int $limit Most to return.
 * @return int[]
 */
function gwcpp_pending_post_ids( int $limit = GWCPP_QUEUE_PAGE_SIZE ): array {
	$types = gwcpp_post_types();
	if ( ! $types || $limit < 1 ) {
		return array();
	}

	return array_map( 'intval', gwcpp_pending_query( $limit, 1, 'modified' ) );
}

/**
 * Every post waiting for review, without exception.
 *
 * ── Why this is not just the function above with a bigger number ─────────────
 * gwcpp_attachment_is_claimed() asks "is any pending changeset still using this
 * file?" immediately before the reaper force-deletes it. Asked against a capped
 * list, that question silently becomes "is any of the FIRST 200 still using
 * it?", and the ordering made it worse: the queue is newest-touched first, so
 * the changesets that fell off the end were the oldest-waiting ones — exactly
 * the ones whose uploads had aged past the thirty-day threshold. A site with a
 * long queue would have had staff approve a change whose photo had been deleted
 * out from under it a fortnight earlier.
 *
 * Walked oldest ID first rather than by modified date, because a paged query
 * ordered by something a concurrent request can change will skip rows between
 * pages — and a skipped row here means a deleted file.
 *
 * @return int[]
 */
function gwcpp_every_pending_post_id(): array {
	$types = gwcpp_post_types();
	if ( ! $types ) {
		return array();
	}

	$ids  = array();
	$page = 1;

	do {
		$found = gwcpp_pending_query( GWCPP_QUEUE_PAGE_SIZE, $page, 'ID' );
		$count = count( $found );
		$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		++$page;
		// A short page means that was the last one.
	} while ( GWCPP_QUEUE_PAGE_SIZE === $count );

	return $ids;
}

/**
 * One page of the pending-changeset query.
 *
 * @param int    $per_page How many.
 * @param int    $page     Which page, from 1.
 * @param string $orderby  'modified' or 'ID'.
 * @return int[]
 */
function gwcpp_pending_query( int $per_page, int $page, string $orderby ): array {
	$posts = get_posts(
		array(
			'post_type'              => gwcpp_post_types(),
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'orderby'                => $orderby,
			'order'                  => 'ID' === $orderby ? 'ASC' : 'DESC',
			'meta_key'               => GWCPP_PENDING_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- EXISTS on an indexed meta key; the queue is unavoidably a meta lookup.
			'meta_compare'           => 'EXISTS',
		)
	);

	return is_array( $posts ) ? $posts : array();
}

/**
 * How many posts are waiting, for the menu bubble.
 *
 * Counts all of them. A bubble that stops at the page size tells somebody with
 * a backlog that they have exactly as much waiting as they had yesterday.
 *
 * ── And why the answer is cached ─────────────────────────────────────────────
 * Because of where it is called from. gwcpp_admin_menu() runs on `admin_menu`,
 * which fires on every single wp-admin request — the Dashboard, Media, Users,
 * somebody else's plugin's settings screen — and all it wants is a number for
 * the bubble. Uncached, that put a filesort over a meta join on every admin
 * page load on the site to draw a digit that is usually zero, and counting
 * *every* pending post rather than the first page makes it a walk of the whole
 * queue rather than one query. The fix above and this one need each other.
 *
 * The queue screen still calls the query directly and still sees the truth,
 * because a stale list there would be somebody approving a change that is not
 * there any more.
 *
 * A minute is short enough that the bubble is never meaningfully wrong, and the
 * three changeset actions clear it immediately anyway — so the only way to see
 * a stale count is for a changeset to appear through some path this plugin does
 * not know about, and then only until the minute is up.
 *
 * @return int
 */
function gwcpp_pending_count(): int {
	$cached = get_transient( GWCPP_PENDING_COUNT_TRANSIENT );

	if ( false !== $cached ) {
		return (int) $cached;
	}

	$count = count( gwcpp_every_pending_post_id() );

	set_transient( GWCPP_PENDING_COUNT_TRANSIENT, $count, MINUTE_IN_SECONDS );

	return $count;
}

/**
 * Forget the cached count.
 *
 * Hooked to the three actions this plugin fires when the queue changes, so the
 * bubble is right the instant staff approve something rather than up to a
 * minute later.
 */
function gwcpp_flush_pending_count(): void {
	delete_transient( GWCPP_PENDING_COUNT_TRANSIENT );
}

add_action( 'gwcpp_changeset_stored', 'gwcpp_flush_pending_count' );
add_action( 'gwcpp_changeset_applied', 'gwcpp_flush_pending_count' );
add_action( 'gwcpp_changeset_rejected', 'gwcpp_flush_pending_count' );

/*
 * ── The change log ──────────────────────────────────────────────────────────
 * A short history on the post itself, because six months later somebody asks
 * why a phone number is wrong and the answer is either here or nowhere.
 * Revisions do not record post meta, so nothing in WordPress would otherwise
 * remember that this field had this value until this person changed it.
 *
 * Bounded at ten. It is a convenience, not an audit trail, and an unbounded
 * array in post meta on a busy directory is a row that grows until somebody
 * notices it in a slow query log.
 * ───────────────────────────────────────────────────────────────────────────
 */

/**
 * Record an applied change.
 *
 * @param int   $post_id     Post ID.
 * @param int   $user_id     Who submitted it.
 * @param int   $approved_by Who approved it, or 0 when it went straight through.
 * @param array $diff        The change, from gwcpp_changeset_diff().
 */
function gwcpp_log_change( int $post_id, int $user_id, int $approved_by, array $diff ): void {
	if ( ! $diff ) {
		return;
	}

	$log = get_post_meta( $post_id, GWCPP_LOG_META, true );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'time'        => time(),
			'user'        => $user_id,
			'approved_by' => $approved_by,
			'diff'        => $diff,
		)
	);

	update_post_meta( $post_id, GWCPP_LOG_META, wp_slash( array_slice( $log, 0, GWCPP_LOG_LENGTH ) ) );
}

/**
 * The change log on a post.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function gwcpp_change_log( int $post_id ): array {
	$log = get_post_meta( $post_id, GWCPP_LOG_META, true );

	return is_array( $log ) ? $log : array();
}

/*
 * ── Attachments belonging to a changeset ────────────────────────────────────
 * Uploads have to exist as real attachments before anybody approves them —
 * there is nowhere else to put a file — so they are created immediately and
 * flagged with the post they are waiting for. Approving clears the flag and
 * attaches them; rejecting deletes them; and the cron in field-media.php
 * sweeps up any whose changeset vanished by some route neither of those covers.
 * ───────────────────────────────────────────────────────────────────────────
 */

/** Attachment meta, single: when this upload was flagged as waiting, as a Unix
 *  timestamp. Its presence is what marks the file as a changeset's to delete;
 *  the value is what the reaper ages against. Deliberately not the post ID —
 *  which the name suggests and which nothing has ever stored here.
 */
const GWCPP_PENDING_ATTACHMENT_META = '_gwcpp_pending_for';

/**
 * Attach approved uploads to their post.
 *
 * @param int[] $attachment_ids Attachment IDs.
 * @param int   $post_id        Post to attach them to.
 */
function gwcpp_attach_uploads( array $attachment_ids, int $post_id ): void {
	foreach ( $attachment_ids as $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			continue;
		}

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			)
		);
		delete_post_meta( $attachment_id, GWCPP_PENDING_ATTACHMENT_META );
	}
}

/**
 * Delete uploads that were only ever waiting on a changeset.
 *
 * Refuses to touch anything without the pending flag. An attachment that has
 * been approved, or that was in the media library already and merely chosen,
 * is somebody else's file — and this function is reached from a reject button
 * and from cron, neither of which is a place to be deleting media on a guess.
 *
 * @param int[] $attachment_ids Attachment IDs.
 * @return int How many were deleted.
 */
function gwcpp_discard_attachments( array $attachment_ids ): int {
	$deleted = 0;

	foreach ( $attachment_ids as $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			continue;
		}

		if ( ! get_post_meta( $attachment_id, GWCPP_PENDING_ATTACHMENT_META, true ) ) {
			continue;
		}

		wp_delete_attachment( $attachment_id, true );
		++$deleted;
	}

	return $deleted;
}
