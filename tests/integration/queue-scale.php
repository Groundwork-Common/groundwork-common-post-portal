<?php
/**
 * Integration checks for the queue and the review cycle at scale.
 *
 *   npx @wordpress/env run cli wp eval-file \
 *     wp-content/plugins/groundwork-common-post-portal/tests/integration/queue-scale.php
 *
 * These cannot be unit tests. tests/bootstrap.php stubs get_posts() to return
 * an empty array, so a suite run against it proves nothing whatsoever about
 * paging, ordering or a meta_query — which is exactly where these bugs lived:
 *
 *  - The queue read the first 200 rows and the reaper asked it whether an
 *    upload was still needed. Past 200 waiting changesets the answer became
 *    "no" for the oldest of them, and the file was force-deleted while somebody
 *    was still waiting for it to be approved.
 *  - The review cycle read the first 500 posts in date order, so on a larger
 *    directory the oldest entries — the ones most likely to be stale — were
 *    never chased, never hidden and never reported.
 *
 * Both are invisible below the threshold, so this builds a queue past it.
 *
 * @package PostPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['gwcpp_pass'] = 0;
$GLOBALS['gwcpp_fail'] = 0;

/**
 * Report one check.
 *
 * @param string $label  What was checked.
 * @param bool   $ok     Whether it held.
 * @param string $detail Extra context, shown on failure.
 */
function vok( string $label, bool $ok, string $detail = '' ): void {
	if ( $ok ) {
		++$GLOBALS['gwcpp_pass'];
		echo "PASS  {$label}\n";
		return;
	}

	++$GLOBALS['gwcpp_fail'];
	echo "FAIL  {$label}";
	echo '' !== $detail ? "  — {$detail}\n" : "\n";
}

/* ── Setup ───────────────────────────────────────────────────────────────── */

update_option(
	'gwcpp_settings',
	array(
		'post_types' => array( 'post' ),
		'types'      => array( 'post' => array( 'review_months' => 6 ) ),
	)
);
gwcpp_settings_cache( null, true );

/* One page and a bit. Below the page size every one of these assertions passes
 * against the broken code, which is the whole point of the threshold. */
$total = GWCPP_QUEUE_PAGE_SIZE + 5;
$made  = array();

echo "Creating {$total} posts, each with a pending changeset…\n";

for ( $i = 0; $i < $total; $i++ ) {
	/* Staggered on purpose. Inserted in one go they all share a post_modified
	 * to the second, ORDER BY post_modified has nothing to break the tie with,
	 * and which of them lands on page one is up to MySQL — so the assertion
	 * below about the oldest one falling off the page would be testing the
	 * storage engine's mood rather than the fix. */
	$when = gmdate( 'Y-m-d H:i:s', time() - ( ( $total - $i ) * MINUTE_IN_SECONDS ) );

	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => 'Queue scale ' . $i,
			'post_date_gmt' => $when,
			'post_date'     => $when,
		)
	);

	if ( ! $post_id ) {
		continue;
	}

	$made[] = (int) $post_id;

	/* The attachment ID is a stand-in: nothing here reads the attachment, only
	 * whether the queue still names it. */
	gwcpp_store_changeset( (int) $post_id, 1, array( 'title' => 'changed ' . $i ), array( 900000 + $i ) );
}

$made_count = count( $made );
vok( 'every post was created', $made_count === $total, "made {$made_count} of {$total}" );

/* The first one created is the least recently modified, so under the queue's
 * newest-first ordering it is the one that falls off the end of page one. */
$oldest            = $made[0];
$oldest_attachment = 900000;

/* ── The queue ───────────────────────────────────────────────────────────── */

$page = gwcpp_pending_post_ids();
$all  = gwcpp_every_pending_post_id();

vok(
	'the screen draws one page and no more',
	count( $page ) === GWCPP_QUEUE_PAGE_SIZE,
	'got ' . count( $page )
);

vok(
	'the complete walk returns every waiting changeset',
	count( $all ) >= $total,
	'got ' . count( $all ) . ', expected at least ' . $total
);

vok(
	'the count is the real one, not the page size',
	gwcpp_pending_count() >= $total,
	'bubble said ' . gwcpp_pending_count()
);

vok(
	'the oldest-waiting changeset is past the first page',
	! in_array( $oldest, $page, true ),
	'it was on page one, so this run proves nothing about the fix'
);

vok(
	'…and the complete walk still finds it',
	in_array( $oldest, $all, true )
);

/* ── The bug this is really about ────────────────────────────────────────── */

vok(
	'an upload on a changeset past the first page is not treated as an orphan',
	gwcpp_attachment_is_claimed( $oldest_attachment ),
	'the reaper would have deleted a file somebody was still waiting on'
);

$claimed = gwcpp_claimed_attachment_ids();
vok(
	'every waiting upload is accounted for',
	count( $claimed ) >= $total,
	'claimed ' . count( $claimed ) . ' of ' . $total
);

/* ── The review cycle ────────────────────────────────────────────────────── */

$reviewable = gwcpp_reviewable_post_ids();

vok(
	'the cycle walks past its own page size',
	count( $reviewable ) >= $total,
	'got ' . count( $reviewable ) . ', expected at least ' . $total
);

vok(
	'no entry is dropped from the walk',
	array() === array_diff( $made, $reviewable ),
	count( array_diff( $made, $reviewable ) ) . ' entries the cycle would never chase'
);

/* ── Cleanup ─────────────────────────────────────────────────────────────── */

foreach ( $made as $post_id ) {
	wp_delete_post( $post_id, true );
}

delete_option( 'gwcpp_settings' );
gwcpp_settings_cache( null, true );

echo "\n{$GLOBALS['gwcpp_pass']} passed, {$GLOBALS['gwcpp_fail']} failed\n";

if ( $GLOBALS['gwcpp_fail'] > 0 ) {
	exit( 1 );
}
