<?php
/**
 * The colophon's snooze.
 *
 * Split out of the renderer precisely so it can be tested without WordPress —
 * the interesting part is the arithmetic and its two edge cases, not the markup.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class ColophonTest extends TestCase {

	public function test_never_collapsed_shows(): void {
		$this->assertFalse( gwcpp_colophon_snoozed( 0, 1_800_000_000 ) );
	}

	public function test_collapsed_just_now_stays_folded(): void {
		$now = 1_800_000_000;

		$this->assertTrue( gwcpp_colophon_snoozed( $now, $now ) );
		$this->assertTrue( gwcpp_colophon_snoozed( $now - DAY_IN_SECONDS, $now ) );
	}

	public function test_it_comes_back_after_thirty_days(): void {
		$now = 1_800_000_000;

		$this->assertTrue( gwcpp_colophon_snoozed( $now - ( 29 * DAY_IN_SECONDS ), $now ) );
		$this->assertFalse( gwcpp_colophon_snoozed( $now - ( 30 * DAY_IN_SECONDS ), $now ) );
		$this->assertFalse( gwcpp_colophon_snoozed( $now - ( 400 * DAY_IN_SECONDS ), $now ) );
	}

	public function test_a_timestamp_from_the_future_does_not_hide_it_for_years(): void {
		$now = 1_800_000_000;

		$this->assertFalse(
			gwcpp_colophon_snoozed( $now + ( 365 * DAY_IN_SECONDS ), $now ),
			'A clock change or a database move must not fold the panel away indefinitely.'
		);
	}

	public function test_a_negative_timestamp_shows(): void {
		$this->assertFalse( gwcpp_colophon_snoozed( -1, 1_800_000_000 ) );
	}
}
