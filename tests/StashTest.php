<?php
/**
 * Repopulating a form that was refused.
 *
 * A submission that fails validation is stashed so the form can be redrawn with
 * what the person typed still in it. The stash is bounded on the way in, and
 * the bounding is where this went wrong once: it kept only scalars, so every
 * field whose value is a list of ROWS came back empty and the loss was silent.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class StashTest extends TestCase {

	protected function setUp(): void {
		gwc_pp_test_reset();
	}

	/**
	 * Stash one set of values and read them straight back.
	 *
	 * @param array $values Values to stash.
	 * @return array
	 */
	private function round_trip( array $values ): array {
		gwc_pp_stash_submission( 7, 42, $values, array( 'somefield' => 'nope' ) );
		$taken = gwc_pp_take_stash( 7, 42 );

		$this->assertNotNull( $taken, 'The stash should come back.' );

		return $taken['values'];
	}

	/* ── The regression ──────────────────────────────────────────────────── */

	/**
	 * The bug this file exists for.
	 *
	 * A repeater's value is a list of rows and each row is an array. The old
	 * bound filtered the list with is_scalar, which discarded every row. On the
	 * site this looked like: pick a file the media field refuses, get the form
	 * back with an error, and every opening-hours row you had is gone — then
	 * saving from that form stores the emptiness.
	 */
	public function test_a_repeater_survives_the_stash(): void {
		$hours = array(
			array(
				'day'    => 'mon',
				'opens'  => '9am',
				'closes' => '5pm',
			),
			array(
				'day'    => 'wed',
				'opens'  => '1pm',
				'closes' => '6pm',
			),
		);

		$values = $this->round_trip( array( 'hours' => $hours ) );

		$this->assertSame( $hours, $values['hours'], 'Both rows, with every cell.' );
	}

	public function test_a_repeater_of_one_row_survives(): void {
		$values = $this->round_trip( array( 'hours' => array( array( 'day' => 'fri' ) ) ) );

		$this->assertSame( array( array( 'day' => 'fri' ) ), $values['hours'] );
	}

	/* ── The shapes that already worked, so they keep working ────────────── */

	public function test_a_checkbox_group_survives_the_stash(): void {
		$values = $this->round_trip( array( 'services' => array( 'food', 'advice' ) ) );

		$this->assertSame( array( 'food', 'advice' ), $values['services'] );
	}

	public function test_a_scalar_survives_the_stash(): void {
		$values = $this->round_trip( array( 'phone' => '(205) 555-0142' ) );

		$this->assertSame( '(205) 555-0142', $values['phone'] );
	}

	public function test_taking_the_stash_clears_it(): void {
		$this->round_trip( array( 'phone' => '1' ) );

		$this->assertNull( gwc_pp_take_stash( 7, 42 ), 'A stash is read once.' );
	}

	public function test_one_persons_stash_is_not_anothers(): void {
		gwc_pp_stash_submission( 7, 42, array( 'phone' => 'mine' ), array() );

		$this->assertNull( gwc_pp_take_stash( 8, 42 ), 'Keyed by user.' );
		$this->assertNull( gwc_pp_take_stash( 7, 43 ), 'And by post.' );
	}

	/* ── Still bounded in both directions ────────────────────────────────── */

	public function test_a_long_scalar_is_capped(): void {
		$values = $this->round_trip( array( 'note' => str_repeat( 'a', GWC_PP_PENDING_MAX + 500 ) ) );

		$this->assertSame( GWC_PP_PENDING_MAX, strlen( $values['note'] ) );
	}

	public function test_a_long_scalar_inside_a_row_is_capped(): void {
		$values = $this->round_trip(
			array(
				'hours' => array( array( 'opens' => str_repeat( 'b', GWC_PP_PENDING_MAX + 500 ) ) ),
			)
		);

		$this->assertArrayHasKey( 0, $values['hours'], 'The row survived the stash.' );
		$this->assertSame( GWC_PP_PENDING_MAX, strlen( $values['hours'][0]['opens'] ) );
	}

	public function test_the_number_of_entries_is_bounded(): void {
		$values = $this->round_trip( array( 'hours' => array_fill( 0, GWC_PP_STASH_ENTRIES + 50, array( 'day' => 'mon' ) ) ) );

		$this->assertCount( GWC_PP_STASH_ENTRIES, $values['hours'] );
	}

	public function test_the_number_of_cells_in_a_row_is_bounded(): void {
		$row = array();
		for ( $i = 0; $i < GWC_PP_STASH_CELLS + 20; $i++ ) {
			$row[ 'c' . $i ] = 'x';
		}

		$values = $this->round_trip( array( 'hours' => array( $row ) ) );

		$this->assertArrayHasKey( 0, $values['hours'], 'The row survived the stash.' );
		$this->assertCount( GWC_PP_STASH_CELLS, $values['hours'][0] );
	}

	/**
	 * Two levels deep, and no further.
	 *
	 * Nothing this plugin renders nests deeper, and a bound that recurses is a
	 * depth a crafted submission gets to choose.
	 */
	public function test_a_third_level_is_dropped(): void {
		$values = $this->round_trip(
			array(
				'hours' => array(
					array(
						'day'    => 'mon',
						'nested' => array( 'deeper' => array( 'deeper still' ) ),
					),
				),
			)
		);

		$this->assertArrayHasKey( 0, $values['hours'], 'The row survived the stash.' );
		$this->assertSame( array( 'day' => 'mon' ), $values['hours'][0] );
	}

	public function test_an_object_in_a_row_is_dropped(): void {
		$values = $this->round_trip(
			array(
				'hours' => array(
					array(
						'day'  => 'mon',
						'evil' => new stdClass(),
					),
				),
			)
		);

		$this->assertArrayHasKey( 0, $values['hours'], 'The row survived the stash.' );
		$this->assertSame( array( 'day' => 'mon' ), $values['hours'][0] );
	}
}
