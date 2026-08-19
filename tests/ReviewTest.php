<?php
/**
 * The review cycle: stage boundaries, the ladder, and what never expires.
 *
 * The clock is moved by writing a review date in the past, which is exactly how
 * a real site arrives at each of these states. Nothing here mocks time.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewTest extends TestCase {

	private const POST = 70;
	private const ORG  = 71;

	protected function setUp(): void {
		gwc_pp_test_reset();
		$GLOBALS['gwc_pp_test']['types'][] = 'clinic';

		gwc_pp_test_post( self::ORG, 'gwc_pp_org', 'publish', 0, 'An Org' );
		gwc_pp_test_post( self::POST, 'clinic', 'publish', 0, 'A Clinic' );
		gwc_pp_test_user( 8 );

		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 6 ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		// Somebody who could review it, or every test lands on "unmanaged".
		gwc_pp_set_post_org( self::POST, self::ORG );
		gwc_pp_add_post_editor( 8, self::POST );
	}

	/** Set the last review to N months ago. */
	private function reviewed_months_ago( int $months ): void {
		update_post_meta(
			self::POST,
			GWC_PP_REVIEWED_META,
			gwc_pp_review_today()->modify( '-' . $months . ' months' )->format( 'Y-m-d' )
		);
	}

	/* ── Cadence ─────────────────────────────────────────────────────────── */

	public function test_the_cycle_is_off_by_default(): void {
		update_option( 'gwc_pp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwc_pp_settings_cache( null, true );

		$this->assertSame( 0, gwc_pp_review_cadence( 'clinic' ) );
		$this->assertFalse( gwc_pp_review_enabled( 'clinic' ) );
		$this->assertSame( 'off', gwc_pp_review_state( self::POST )['state'] );
	}

	public function test_a_nonsense_cadence_is_clamped(): void {
		foreach ( array( -5 => 0, 0 => 0, 1 => 1, 6 => 6, 9999 => 120 ) as $set => $expected ) {
			update_option(
				'gwc_pp_settings',
				array(
					'post_types' => array( 'clinic' ),
					'types'      => array( 'clinic' => array( 'review_months' => $set ) ),
				)
			);
			gwc_pp_settings_cache( null, true );

			$this->assertSame( $expected, gwc_pp_review_cadence( 'clinic' ), 'cadence ' . $set );
		}
	}

	/* ── Stage boundaries ────────────────────────────────────────────────── */

	/**
	 * @param int    $months   How long ago it was reviewed.
	 * @param string $expected The stage that should produce.
	 */
	#[DataProvider( 'stages' )]
	public function test_stage_boundaries( int $months, string $expected ): void {
		$this->reviewed_months_ago( $months );

		$this->assertSame( $expected, gwc_pp_review_state( self::POST )['stage'] );
	}

	/**
	 * On a six-month cadence: nudge at 5, named at 6, hard at 7, hidden at 12.
	 *
	 * @return array<string, array{0:int,1:string}>
	 */
	public static function stages(): array {
		return array(
			'brand new'      => array( 0, 'current' ),
			'four months'    => array( 4, 'current' ),
			'just before'    => array( 4, 'current' ),
			'five months'    => array( 5, 'due' ),
			'six months'     => array( 6, 'due' ),
			'seven months'   => array( 7, 'overdue' ),
			'eleven months'  => array( 11, 'overdue' ),
			'twelve months'  => array( 12, 'expired' ),
			'two years'      => array( 24, 'expired' ),
		);
	}

	public function test_a_different_cadence_moves_every_threshold(): void {
		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 12 ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		$this->reviewed_months_ago( 6 );
		$this->assertSame( 'current', gwc_pp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 11 );
		$this->assertSame( 'due', gwc_pp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 13 );
		$this->assertSame( 'overdue', gwc_pp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 24 );
		$this->assertSame( 'expired', gwc_pp_review_state( self::POST )['stage'] );
	}

	public function test_a_cadence_of_one_still_has_a_nudge_before_the_named_date(): void {
		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 1 ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		// cadence - 1 would be zero months, which would make "due" mean "today".
		$this->reviewed_months_ago( 0 );
		$this->assertSame(
			'current',
			gwc_pp_review_state( self::POST )['stage'],
			'A cadence of one must not report a brand-new entry as already due.'
		);
	}

	public function test_an_entry_never_reviewed_counts_from_its_publish_date(): void {
		$state = gwc_pp_review_state( self::POST );

		$this->assertSame( '', $state['reviewed_at'] );
		$this->assertNotSame( '', $state['basis'], 'It has to count from something.' );
	}

	/* ── What can never expire ───────────────────────────────────────────── */

	public function test_an_exempt_entry_is_never_chased(): void {
		$this->reviewed_months_ago( 24 );
		update_post_meta( self::POST, GWC_PP_REVIEW_EXEMPT_META, 1 );

		$state = gwc_pp_review_state( self::POST );

		$this->assertSame( 'expired', $state['stage'], 'The date maths is unchanged...' );
		$this->assertSame( 'exempt', $state['state'], '...but nobody is told and nothing happens.' );
		$this->assertSame( '', gwc_pp_review_due_rung( $state, array() ) );
	}

	public function test_an_entry_nobody_can_review_is_never_hidden(): void {
		gwc_pp_remove_post_editor( 8, self::POST );
		gwc_pp_set_post_org( self::POST, 0 );
		$this->reviewed_months_ago( 24 );

		$state = gwc_pp_review_state( self::POST );

		$this->assertFalse( $state['managed'] );
		$this->assertSame(
			'unmanaged',
			$state['state'],
			'Hiding it would punish a partner for a gap on the site\'s own side.'
		);
	}

	public function test_an_org_with_members_counts_as_managed(): void {
		gwc_pp_remove_post_editor( 8, self::POST );
		gwc_pp_add_user_to_org( 8, self::ORG );

		$this->assertTrue( gwc_pp_post_has_owner( self::POST ) );
		$this->assertSame( array( 8 ), gwc_pp_post_owners( self::POST ) );
	}

	public function test_an_empty_org_does_not_count_as_managed(): void {
		gwc_pp_remove_post_editor( 8, self::POST );

		$this->assertFalse( gwc_pp_post_has_owner( self::POST ) );
	}

	/* ── The notice ladder ───────────────────────────────────────────────── */

	public function test_an_entry_deep_in_the_ladder_gets_one_email_not_six(): void {
		// The situation on the day a site switches the cycle on.
		$this->reviewed_months_ago( 24 );

		$rung = gwc_pp_review_due_rung( gwc_pp_review_state( self::POST ), array() );

		$this->assertSame( 'expired', $rung, 'The last rung passed, not the first.' );
	}

	public function test_each_rung_fires_once(): void {
		$this->reviewed_months_ago( 5 );

		$state = gwc_pp_review_state( self::POST );
		$rung  = gwc_pp_review_due_rung( $state, array() );

		$this->assertSame( 'due', $rung );
		$this->assertSame(
			'',
			gwc_pp_review_due_rung( $state, array( 'due' ) ),
			'Already delivered, so there is nothing to send.'
		);
	}

	public function test_the_ladder_advances_as_time_passes(): void {
		$sent = array();

		foreach ( array( 5 => 'due', 6 => 'named', 7 => 'overdue' ) as $months => $expected ) {
			$this->reviewed_months_ago( $months );
			$rung = gwc_pp_review_due_rung( gwc_pp_review_state( self::POST ), $sent );

			$this->assertSame( $expected, $rung, 'at ' . $months . ' months' );
			$sent[] = $rung;
		}
	}

	public function test_confirming_resets_the_ladder(): void {
		$this->reviewed_months_ago( 11 );
		update_post_meta( self::POST, GWC_PP_NOTICES_META, array( 'due', 'named', 'overdue' ) );

		gwc_pp_record_review( self::POST, 8 );

		$this->assertSame(
			array(),
			gwc_pp_review_notices_sent( self::POST ),
			'Without this, an entry confirmed late goes silent for its whole next cycle.'
		);
		$this->assertSame( 'current', gwc_pp_review_state( self::POST )['stage'] );
	}

	/* ── Rung ordering ───────────────────────────────────────────────────────
	 * Four rungs are counted forward from the basis in months, two backward
	 * from expiry in days. Which lands first depends on the cadence, and the
	 * runner takes the last rung in the array that has passed — so the array
	 * order has to BE the date order or it sends the wrong message.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	/**
	 * @param int $cadence Months between reviews.
	 */
	#[DataProvider( 'cadences' )]
	public function test_the_ladder_is_ordered_soonest_first( int $cadence ): void {
		$dates = array_values( gwc_pp_review_ladder( '2026-01-01', $cadence ) );

		$sorted = $dates;
		usort( $sorted, static fn( $a, $b ) => $a <=> $b );

		$this->assertEquals( $sorted, $dates, 'at a cadence of ' . $cadence );
	}

	/**
	 * @return array<string, array{0:int}>
	 */
	public static function cadences(): array {
		return array(
			'monthly'    => array( 1 ),
			'two months' => array( 2 ),
			'quarterly'  => array( 3 ),
			'biannual'   => array( 6 ),
			'annual'     => array( 12 ),
		);
	}

	public function test_a_staff_rung_loses_a_day_it_shares_with_an_owner_rung(): void {
		// At a cadence of two, expiry minus 30 days and the overdue date are the
		// same day. Whichever sorts last is the one the owner does or does not get.
		$ladder = gwc_pp_review_ladder( '2026-01-01', 2 );

		$this->assertSame(
			$ladder['overdue']->format( 'Y-m-d' ),
			$ladder['staff_30']->format( 'Y-m-d' ),
			'The collision this is about.'
		);

		$keys = array_keys( $ladder );

		$this->assertLessThan(
			array_search( 'overdue', $keys, true ),
			array_search( 'staff_30', $keys, true ),
			'staff_30 must sort first so "last rung passed" picks the message that goes to somebody who can act on it.'
		);
	}

	/**
	 * Walk a whole cycle a day at a time and collect what the owner is actually
	 * sent. The bug this covers: at short cadences a staff-only rung sorted last,
	 * won every day it was standing, was recorded as delivered and so never came
	 * round again — and the owner's entry was hidden having been told nothing.
	 *
	 * @param int $cadence Months between reviews.
	 */
	#[DataProvider( 'cadences' )]
	public function test_an_owner_is_warned_before_their_entry_is_hidden( int $cadence ): void {
		$basis = '2026-01-01';
		$state = array(
			'enabled' => true,
			'cadence' => $cadence,
			'basis'   => $basis,
		);

		$day  = gwc_pp_review_date( $basis );
		$end  = gwc_pp_review_ladder( $basis, $cadence )['expired'];
		$sent = array();

		while ( $day <= $end ) {
			$rung = gwc_pp_review_due_rung( $state, $sent, $day );

			if ( '' !== $rung ) {
				$sent[] = $rung;
			}

			$day = $day->modify( '+1 day' );
		}

		$owner_rungs = array_values( array_intersect( $sent, GWC_PP_OWNER_RUNGS ) );

		$this->assertNotEmpty(
			$owner_rungs,
			'Nobody may lose an entry having been sent nothing at all, at any cadence.'
		);
		$this->assertContains( 'expired', $sent, 'The entry is hidden, so its owner is told that.' );
		$this->assertSame( 'expired', end( $sent ), 'And that is the last thing they hear in the cycle.' );
		/* The rung that was actually being lost. Without this the assertions above
		 * are satisfied by final_15 and expired alone — an owner whose first word
		 * on the subject is that their entry goes in a fortnight, which is the
		 * opposite of nudging them early enough to act.
		 */
		$this->assertNotEmpty(
			array_intersect( $sent, array( 'due', 'named' ) ),
			'The owner is nudged well before the final warning, at every cadence.'
		);
	}

	public function test_the_staff_rung_is_not_an_owner_rung(): void {
		$this->assertNotContains(
			'staff_30',
			GWC_PP_OWNER_RUNGS,
			'The 30-day warning goes to the site, not to a partner.'
		);
	}

	public function test_recording_a_rung_keeps_the_earlier_ones(): void {
		gwc_pp_review_record_notices( self::POST, array( 'due' ), array( 'named' ) );

		$this->assertSame( array( 'due', 'named' ), gwc_pp_review_notices_sent( self::POST ) );
	}

	public function test_recording_nothing_is_a_no_op(): void {
		gwc_pp_review_record_notices( self::POST, array( 'due' ), array() );

		$this->assertSame( array(), gwc_pp_review_notices_sent( self::POST ) );
	}

	/* ── Hiding and restoring ────────────────────────────────────────────── */

	public function test_expiring_drafts_the_post_and_marks_why(): void {
		$this->assertTrue( gwc_pp_review_expire( self::POST ) );

		$this->assertSame( 'draft', get_post( self::POST )->post_status );
		$this->assertNotEmpty( get_post_meta( self::POST, GWC_PP_AUTO_EXPIRED_META, true ) );
	}

	public function test_confirming_puts_a_hidden_entry_back(): void {
		gwc_pp_review_expire( self::POST );
		gwc_pp_record_review( self::POST, 8 );

		$this->assertSame( 'publish', get_post( self::POST )->post_status );
		$this->assertSame( '', (string) get_post_meta( self::POST, GWC_PP_AUTO_EXPIRED_META, true ) );
	}

	public function test_republish_refuses_a_post_the_cycle_did_not_hide(): void {
		$GLOBALS['gwc_pp_test']['posts'][ self::POST ]->post_status = 'draft';

		$this->assertFalse(
			gwc_pp_review_republish( self::POST ),
			'A post staff drafted by hand must stay exactly where they put it.'
		);
		$this->assertSame( 'draft', get_post( self::POST )->post_status );
	}

	public function test_expiring_a_draft_is_a_no_op(): void {
		$GLOBALS['gwc_pp_test']['posts'][ self::POST ]->post_status = 'draft';

		$this->assertFalse( gwc_pp_review_expire( self::POST ) );
	}

	/* ── The run lock ────────────────────────────────────────────────────────
	 * Two things start the daily run — the cron event and the admin_init
	 * catch-up — and both can read gwc_pp_review_last_run before either writes
	 * it. Without a lock that is two walks over the same entries, each deciding
	 * the same owners are due, each sending them the same email.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	public function test_the_first_run_takes_the_lock(): void {
		$this->assertTrue( gwc_pp_review_claim_lock() );
	}

	public function test_a_second_run_is_refused_while_the_first_holds_it(): void {
		gwc_pp_review_claim_lock();

		$this->assertFalse(
			gwc_pp_review_claim_lock(),
			'An overlapping run must decline rather than send everybody a second copy.'
		);
	}

	public function test_releasing_lets_the_next_run_in(): void {
		gwc_pp_review_claim_lock();
		gwc_pp_review_release_lock();

		$this->assertTrue( gwc_pp_review_claim_lock() );
	}

	public function test_a_lock_left_behind_by_a_killed_run_is_broken(): void {
		gwc_pp_review_claim_lock();

		// An FPM timeout or a fatal took the previous run out before it could
		// release. Nothing else is ever going to clear this.
		update_option( GWC_PP_REVIEW_LOCK_OPTION, time() - ( GWC_PP_REVIEW_LOCK_TTL + 60 ) );

		$this->assertTrue(
			gwc_pp_review_claim_lock(),
			'A stale lock must not stop the reminders for good.'
		);
	}

	public function test_the_daily_run_declines_when_the_lock_is_held(): void {
		gwc_pp_review_claim_lock();

		$this->assertSame(
			array(
				'checked' => 0,
				'mailed'  => 0,
				'expired' => 0,
			),
			gwc_pp_run_daily_review()
		);
	}

	public function test_the_daily_run_releases_the_lock_when_it_finishes(): void {
		gwc_pp_run_daily_review();

		$this->assertTrue(
			gwc_pp_review_claim_lock(),
			'A completed run must leave the lock free for the next one.'
		);
	}
}
