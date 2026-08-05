<?php
/**
 * The orphan-upload sweep: which files it deletes, and which end it starts from.
 *
 * ── The bug the ordering tests are here for ──────────────────────────────────
 * The candidate query took a hundred flagged attachments with no `orderby`, so
 * it got WordPress's default — newest first. Only attachments past
 * GWCPP_ORPHAN_AGE are ever deleted, and the ones a pending changeset still
 * claims are skipped while still taking up a slot. So on any site holding a
 * hundred flagged uploads newer than thirty days, the sweep re-read the same
 * recent files every night and never reached the orphans behind them. It ran
 * daily, deleted nothing, and reported nothing wrong.
 *
 * This is the third instance of that one bug. gwcpp_every_pending_post_id() and
 * gwcpp_reviewable_post_ids() both carry long comments about a newest-first cap
 * starving the oldest rows; this query was the one left over. The ordering
 * assertions below are deliberately about the ARGUMENTS rather than the result,
 * because that is where the defect lived — a test that only checked which files
 * got deleted passed happily against the broken version.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class ReaperTest extends TestCase {

	private const OLD_ORPHAN  = 700;
	private const OLD_CLAIMED = 701;
	private const YOUNG       = 702;
	private const UNFLAGGED   = 703;

	protected function setUp(): void {
		gwcpp_test_reset();

		foreach ( array( self::OLD_ORPHAN, self::OLD_CLAIMED, self::YOUNG, self::UNFLAGGED ) as $id ) {
			gwcpp_test_post( $id, 'attachment', 'inherit' );
		}

		$long_ago = time() - GWCPP_ORPHAN_AGE - DAY_IN_SECONDS;

		update_post_meta( self::OLD_ORPHAN, GWCPP_PENDING_ATTACHMENT_META, $long_ago );
		update_post_meta( self::OLD_CLAIMED, GWCPP_PENDING_ATTACHMENT_META, $long_ago );
		update_post_meta( self::YOUNG, GWCPP_PENDING_ATTACHMENT_META, time() - HOUR_IN_SECONDS );
		// UNFLAGGED deliberately carries no pending meta.
	}

	/* ── The ordering, which is the actual fix ───────────────────────────── */

	public function test_the_sweep_asks_for_the_oldest_uploads_first(): void {
		gwcpp_reap_orphan_uploads();

		$queries = gwcpp_test_queries_for( 'attachment' );
		$this->assertCount( 1, $queries, 'The sweep should issue exactly one candidate query.' );

		$this->assertSame(
			'ID',
			$queries[0]['orderby'] ?? null,
			'Without an explicit orderby the query gets newest-first, and the cap then hides every real orphan behind the recent uploads.'
		);
		$this->assertSame( 'ASC', $queries[0]['order'] ?? null );
	}

	public function test_the_sweep_is_still_bounded(): void {
		gwcpp_reap_orphan_uploads();

		$queries = gwcpp_test_queries_for( 'attachment' );

		// Ordering oldest-first is only safe *because* the cap stays: the point
		// was never to read the whole media library in one cron run.
		$this->assertSame( 100, $queries[0]['posts_per_page'] ?? null );
		$this->assertSame( 'EXISTS', $queries[0]['meta_compare'] ?? null );
		$this->assertSame( GWCPP_PENDING_ATTACHMENT_META, $queries[0]['meta_key'] ?? null );
	}

	/* ── What it actually deletes ────────────────────────────────────────── */

	public function test_an_aged_unclaimed_upload_is_deleted(): void {
		gwcpp_test_queue_posts( 'attachment', array( self::OLD_ORPHAN ) );

		$this->assertSame( 1, gwcpp_reap_orphan_uploads() );
		$this->assertSame( array( self::OLD_ORPHAN ), $GLOBALS['gwcpp_test']['deleted_attachments'] );
	}

	public function test_an_upload_a_pending_changeset_still_names_is_kept(): void {
		$post = 60;
		gwcpp_test_post( $post, 'clinic', 'publish' );
		update_option( 'gwcpp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwcpp_settings_cache( null, true );

		update_post_meta(
			$post,
			GWCPP_PENDING_META,
			array(
				'user'        => 7,
				'time'        => time(),
				'values'      => array( 'photo' => self::OLD_CLAIMED ),
				'attachments' => array( self::OLD_CLAIMED ),
			)
		);

		gwcpp_test_queue_posts( 'attachment', array( self::OLD_CLAIMED ) );
		gwcpp_test_queue_posts( 'clinic', array( $post ) );

		$this->assertSame( 0, gwcpp_reap_orphan_uploads() );
		$this->assertSame( array(), $GLOBALS['gwcpp_test']['deleted_attachments'] );
	}

	public function test_a_recent_upload_is_left_alone(): void {
		gwcpp_test_queue_posts( 'attachment', array( self::YOUNG ) );

		$this->assertSame( 0, gwcpp_reap_orphan_uploads() );
		$this->assertSame( array(), $GLOBALS['gwcpp_test']['deleted_attachments'] );
	}

	public function test_an_upload_with_no_pending_flag_is_never_touched(): void {
		// The flag is what marks a file as this plugin's to delete. Anything the
		// query hands back without one belongs to somebody else.
		gwcpp_test_queue_posts( 'attachment', array( self::UNFLAGGED ) );

		$this->assertSame( 0, gwcpp_reap_orphan_uploads() );
		$this->assertSame( array(), $GLOBALS['gwcpp_test']['deleted_attachments'] );
	}
}
