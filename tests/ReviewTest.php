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
		gwcpp_test_reset();
		$GLOBALS['gwcpp_test']['types'][] = 'clinic';

		gwcpp_test_post( self::ORG, 'gwcpp_org', 'publish', 0, 'An Org' );
		gwcpp_test_post( self::POST, 'clinic', 'publish', 0, 'A Clinic' );
		gwcpp_test_user( 8 );

		update_option(
			'gwcpp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 6 ) ),
			)
		);
		gwcpp_settings_cache( null, true );

		// Somebody who could review it, or every test lands on "unmanaged".
		gwcpp_set_post_org( self::POST, self::ORG );
		gwcpp_add_post_editor( 8, self::POST );
	}

	/** Set the last review to N months ago. */
	private function reviewed_months_ago( int $months ): void {
		update_post_meta(
			self::POST,
			GWCPP_REVIEWED_META,
			gwcpp_review_today()->modify( '-' . $months . ' months' )->format( 'Y-m-d' )
		);
	}

	/* ── Cadence ─────────────────────────────────────────────────────────── */

	public function test_the_cycle_is_off_by_default(): void {
		update_option( 'gwcpp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwcpp_settings_cache( null, true );

		$this->assertSame( 0, gwcpp_review_cadence( 'clinic' ) );
		$this->assertFalse( gwcpp_review_enabled( 'clinic' ) );
		$this->assertSame( 'off', gwcpp_review_state( self::POST )['state'] );
	}

	public function test_a_nonsense_cadence_is_clamped(): void {
		foreach ( array( -5 => 0, 0 => 0, 1 => 1, 6 => 6, 9999 => 120 ) as $set => $expected ) {
			update_option(
				'gwcpp_settings',
				array(
					'post_types' => array( 'clinic' ),
					'types'      => array( 'clinic' => array( 'review_months' => $set ) ),
				)
			);
			gwcpp_settings_cache( null, true );

			$this->assertSame( $expected, gwcpp_review_cadence( 'clinic' ), 'cadence ' . $set );
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

		$this->assertSame( $expected, gwcpp_review_state( self::POST )['stage'] );
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
			'gwcpp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 12 ) ),
			)
		);
		gwcpp_settings_cache( null, true );

		$this->reviewed_months_ago( 6 );
		$this->assertSame( 'current', gwcpp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 11 );
		$this->assertSame( 'due', gwcpp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 13 );
		$this->assertSame( 'overdue', gwcpp_review_state( self::POST )['stage'] );

		$this->reviewed_months_ago( 24 );
		$this->assertSame( 'expired', gwcpp_review_state( self::POST )['stage'] );
	}

	public function test_a_cadence_of_one_still_has_a_nudge_before_the_named_date(): void {
		update_option(
			'gwcpp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'review_months' => 1 ) ),
			)
		);
		gwcpp_settings_cache( null, true );

		// cadence - 1 would be zero months, which would make "due" mean "today".
		$this->reviewed_months_ago( 0 );
		$this->assertSame(
			'current',
			gwcpp_review_state( self::POST )['stage'],
			'A cadence of one must not report a brand-new entry as already due.'
		);
	}

	public function test_an_entry_never_reviewed_counts_from_its_publish_date(): void {
		$state = gwcpp_review_state( self::POST );

		$this->assertSame( '', $state['reviewed_at'] );
		$this->assertNotSame( '', $state['basis'], 'It has to count from something.' );
	}

	/* ── What can never expire ───────────────────────────────────────────── */

	public function test_an_exempt_entry_is_never_chased(): void {
		$this->reviewed_months_ago( 24 );
		update_post_meta( self::POST, GWCPP_REVIEW_EXEMPT_META, 1 );

		$state = gwcpp_review_state( self::POST );

		$this->assertSame( 'expired', $state['stage'], 'The date maths is unchanged...' );
		$this->assertSame( 'exempt', $state['state'], '...but nobody is told and nothing happens.' );
		$this->assertSame( '', gwcpp_review_due_rung( $state, array() ) );
	}

	public function test_an_entry_nobody_can_review_is_never_hidden(): void {
		gwcpp_remove_post_editor( 8, self::POST );
		gwcpp_set_post_org( self::POST, 0 );
		$this->reviewed_months_ago( 24 );

		$state = gwcpp_review_state( self::POST );

		$this->assertFalse( $state['managed'] );
		$this->assertSame(
			'unmanaged',
			$state['state'],
			'Hiding it would punish a partner for a gap on the site\'s own side.'
		);
	}

	public function test_an_org_with_members_counts_as_managed(): void {
		gwcpp_remove_post_editor( 8, self::POST );
		gwcpp_add_user_to_org( 8, self::ORG );

		$this->assertTrue( gwcpp_post_has_owner( self::POST ) );
		$this->assertSame( array( 8 ), gwcpp_post_owners( self::POST ) );
	}

	public function test_an_empty_org_does_not_count_as_managed(): void {
		gwcpp_remove_post_editor( 8, self::POST );

		$this->assertFalse( gwcpp_post_has_owner( self::POST ) );
	}

	/* ── The notice ladder ───────────────────────────────────────────────── */

	public function test_an_entry_deep_in_the_ladder_gets_one_email_not_six(): void {
		// The situation on the day a site switches the cycle on.
		$this->reviewed_months_ago( 24 );

		$rung = gwcpp_review_due_rung( gwcpp_review_state( self::POST ), array() );

		$this->assertSame( 'expired', $rung, 'The last rung passed, not the first.' );
	}

	public function test_each_rung_fires_once(): void {
		$this->reviewed_months_ago( 5 );

		$state = gwcpp_review_state( self::POST );
		$rung  = gwcpp_review_due_rung( $state, array() );

		$this->assertSame( 'due', $rung );
		$this->assertSame(
			'',
			gwcpp_review_due_rung( $state, array( 'due' ) ),
			'Already delivered, so there is nothing to send.'
		);
	}

	public function test_the_ladder_advances_as_time_passes(): void {
		$sent = array();

		foreach ( array( 5 => 'due', 6 => 'named', 7 => 'overdue' ) as $months => $expected ) {
			$this->reviewed_months_ago( $months );
			$rung = gwcpp_review_due_rung( gwcpp_review_state( self::POST ), $sent );

			$this->assertSame( $expected, $rung, 'at ' . $months . ' months' );
			$sent[] = $rung;
		}
	}

	public function test_confirming_resets_the_ladder(): void {
		$this->reviewed_months_ago( 11 );
		update_post_meta( self::POST, GWCPP_NOTICES_META, array( 'due', 'named', 'overdue' ) );

		gwcpp_record_review( self::POST, 8 );

		$this->assertSame(
			array(),
			gwcpp_review_notices_sent( self::POST ),
			'Without this, an entry confirmed late goes silent for its whole next cycle.'
		);
		$this->assertSame( 'current', gwcpp_review_state( self::POST )['stage'] );
	}

	public function test_the_staff_rung_is_not_an_owner_rung(): void {
		$this->assertNotContains(
			'staff_30',
			GWCPP_OWNER_RUNGS,
			'The 30-day warning goes to the site, not to a partner.'
		);
	}

	public function test_recording_a_rung_keeps_the_earlier_ones(): void {
		gwcpp_review_record_notices( self::POST, array( 'due' ), array( 'named' ) );

		$this->assertSame( array( 'due', 'named' ), gwcpp_review_notices_sent( self::POST ) );
	}

	public function test_recording_nothing_is_a_no_op(): void {
		gwcpp_review_record_notices( self::POST, array( 'due' ), array() );

		$this->assertSame( array(), gwcpp_review_notices_sent( self::POST ) );
	}

	/* ── Hiding and restoring ────────────────────────────────────────────── */

	public function test_expiring_drafts_the_post_and_marks_why(): void {
		$this->assertTrue( gwcpp_review_expire( self::POST ) );

		$this->assertSame( 'draft', get_post( self::POST )->post_status );
		$this->assertNotEmpty( get_post_meta( self::POST, GWCPP_AUTO_EXPIRED_META, true ) );
	}

	public function test_confirming_puts_a_hidden_entry_back(): void {
		gwcpp_review_expire( self::POST );
		gwcpp_record_review( self::POST, 8 );

		$this->assertSame( 'publish', get_post( self::POST )->post_status );
		$this->assertSame( '', (string) get_post_meta( self::POST, GWCPP_AUTO_EXPIRED_META, true ) );
	}

	public function test_republish_refuses_a_post_the_cycle_did_not_hide(): void {
		$GLOBALS['gwcpp_test']['posts'][ self::POST ]->post_status = 'draft';

		$this->assertFalse(
			gwcpp_review_republish( self::POST ),
			'A post staff drafted by hand must stay exactly where they put it.'
		);
		$this->assertSame( 'draft', get_post( self::POST )->post_status );
	}

	public function test_expiring_a_draft_is_a_no_op(): void {
		$GLOBALS['gwcpp_test']['posts'][ self::POST ]->post_status = 'draft';

		$this->assertFalse( gwcpp_review_expire( self::POST ) );
	}
}
