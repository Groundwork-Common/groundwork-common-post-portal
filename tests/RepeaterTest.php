<?php
/**
 * Repeating rows: parsing the column definition, and sanitizing what comes back.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class RepeaterTest extends TestCase {

	protected function setUp(): void {
		gwcpp_test_reset();
	}

	/**
	 * A repeater with two text columns and one select.
	 *
	 * @param array $extra Extra settings.
	 * @return array
	 */
	private function field( array $extra = array() ): array {
		return array(
			'key'      => 'hours',
			'type'     => 'repeater',
			'label'    => 'Opening hours',
			'settings' => array_merge(
				array( 'subfields_raw' => "day|Day|select|mon=Monday;tue=Tuesday\nopens|Opens|text\ncloses|Closes|text" ),
				$extra
			),
		);
	}

	/* ── The column definition ───────────────────────────────────────────── */

	public function test_columns_parse_with_their_types(): void {
		$subs = gwcpp_repeater_subfields( $this->field() );

		$this->assertSame( array( 'day', 'opens', 'closes' ), array_column( $subs, 'key' ) );
		$this->assertSame( array( 'select', 'text', 'text' ), array_column( $subs, 'type' ) );
		$this->assertSame( 'Day', $subs[0]['label'] );
	}

	public function test_select_options_parse(): void {
		$subs = gwcpp_repeater_subfields( $this->field() );

		$this->assertSame(
			array( 'mon', 'tue' ),
			array_column( $subs[0]['settings']['options'], 'value' )
		);
		$this->assertSame( 'Monday', $subs[0]['settings']['options'][0]['label'] );
	}

	public function test_an_unknown_column_type_falls_back_to_text(): void {
		$field = $this->field( array( 'subfields_raw' => 'thing|Thing|repeater' ) );

		$this->assertSame(
			'text',
			gwcpp_repeater_subfields( $field )[0]['type'],
			'A repeater of repeaters is a data model that wants its own post type.'
		);
	}

	public function test_a_media_column_is_refused(): void {
		$field = $this->field( array( 'subfields_raw' => 'photo|Photo|media' ) );

		$this->assertSame( 'text', gwcpp_repeater_subfields( $field )[0]['type'] );
	}

	public function test_duplicate_column_keys_are_dropped(): void {
		$field = $this->field( array( 'subfields_raw' => "a|First|text\na|Second|text\nb|Third|text" ) );

		$this->assertSame( array( 'a', 'b' ), array_column( gwcpp_repeater_subfields( $field ), 'key' ) );
	}

	public function test_no_columns_means_no_rows_and_a_message(): void {
		$field = $this->field( array( 'subfields_raw' => '' ) );

		$this->assertSame( array(), gwcpp_repeater_subfields( $field ) );
		$this->assertSame( array(), gwcpp_sanitize_repeater( array( 'rows' => array( array( 'x' => 'y' ) ) ), $field ) );
		$this->assertNotSame( '', gwcpp_validate_repeater( array(), $field ) );
	}

	/* ── Sanitizing rows ─────────────────────────────────────────────────── */

	public function test_rows_are_sanitized_by_their_own_column_types(): void {
		$rows = gwcpp_sanitize_repeater(
			array(
				'rows' => array(
					array(
						'day'    => 'mon',
						'opens'  => '<b>9am</b>',
						'closes' => '5pm',
					),
				),
			),
			$this->field()
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'mon', $rows[0]['day'] );
		$this->assertSame( '9am', $rows[0]['opens'], 'The text column strips tags.' );
	}

	public function test_a_choice_not_on_the_column_list_is_refused(): void {
		$rows = gwcpp_sanitize_repeater(
			array( 'rows' => array( array( 'day' => 'funday', 'opens' => '9am' ) ) ),
			$this->field()
		);

		$this->assertSame( '', $rows[0]['day'] );
	}

	public function test_a_completely_blank_row_is_dropped(): void {
		$rows = gwcpp_sanitize_repeater(
			array(
				'rows' => array(
					array( 'day' => 'mon', 'opens' => '9am', 'closes' => '5pm' ),
					array( 'day' => '', 'opens' => '', 'closes' => '' ),
				),
			),
			$this->field()
		);

		$this->assertCount(
			1,
			$rows,
			'People press Add, change their mind, and leave the empty row sitting there.'
		);
	}

	public function test_the_template_row_is_never_stored(): void {
		$rows = gwcpp_sanitize_repeater(
			array(
				'rows' => array(
					'__i__' => array( 'day' => 'mon', 'opens' => '9am' ),
					'0'     => array( 'day' => 'tue', 'opens' => '10am' ),
				),
			),
			$this->field()
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'tue', $rows[0]['day'] );
	}

	public function test_rows_are_reindexed_so_gaps_do_not_survive(): void {
		$rows = gwcpp_sanitize_repeater(
			array(
				'rows' => array(
					'0' => array( 'day' => 'mon', 'opens' => '9am' ),
					// The browser removed row 1; the indexes it sends have a hole.
					'2' => array( 'day' => 'tue', 'opens' => '10am' ),
				),
			),
			$this->field()
		);

		$this->assertSame( array( 0, 1 ), array_keys( $rows ) );
	}

	public function test_the_row_limit_is_enforced(): void {
		$rows = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = array( 'day' => 'mon', 'opens' => (string) $i );
		}

		$sanitized = gwcpp_sanitize_repeater(
			array( 'rows' => $rows ),
			$this->field( array( 'max_rows' => 3 ) )
		);

		$this->assertCount( 3, $sanitized );
	}

	public function test_the_hard_ceiling_applies_even_without_a_setting(): void {
		$this->assertSame( GWCPP_REPEATER_MAX, gwcpp_repeater_max( $this->field() ) );
		$this->assertSame(
			GWCPP_REPEATER_MAX,
			gwcpp_repeater_max( $this->field( array( 'max_rows' => 9999 ) ) ),
			'A setting must not be able to raise the ceiling.'
		);
	}

	public function test_nonsense_input_yields_no_rows(): void {
		$this->assertSame( array(), gwcpp_sanitize_repeater( 'not an array', $this->field() ) );
		$this->assertSame( array(), gwcpp_sanitize_repeater( array(), $this->field() ) );
		$this->assertSame( array(), gwcpp_sanitize_repeater( array( 'rows' => 'nope' ), $this->field() ) );
	}

	/* ── Display ─────────────────────────────────────────────────────────── */

	public function test_display_reads_as_one_line_per_row(): void {
		$value = array(
			array( 'day' => 'mon', 'opens' => '9am', 'closes' => '5pm' ),
			array( 'day' => 'tue', 'opens' => '10am', 'closes' => '4pm' ),
		);

		$this->assertSame(
			'Monday 9am 5pm; Tuesday 10am 4pm',
			gwcpp_display_repeater( $value, $this->field() ),
			'Labels, not stored values, or the approval diff is unreadable.'
		);
	}

	public function test_display_of_nothing_is_empty(): void {
		$this->assertSame( '', gwcpp_display_repeater( array(), $this->field() ) );
		$this->assertSame( '', gwcpp_display_repeater( 'nope', $this->field() ) );
	}

	public function test_emptiness_is_the_shared_array_check(): void {
		$this->assertTrue( gwcpp_field_call( $this->field(), 'is_empty', array( array(), $this->field() ) ) );
		$this->assertFalse(
			gwcpp_field_call( $this->field(), 'is_empty', array( array( array( 'day' => 'mon' ) ), $this->field() ) )
		);
	}
}
