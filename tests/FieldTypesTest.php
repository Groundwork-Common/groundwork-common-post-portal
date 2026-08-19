<?php
/**
 * The field type registry and its sanitizers.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class FieldTypesTest extends TestCase {

	protected function setUp(): void {
		gwc_pp_test_reset();
	}

	/* ── The contract ────────────────────────────────────────────────────── */

	public function test_every_registered_type_satisfies_the_contract(): void {
		foreach ( array_keys( gwc_pp_field_types() ) as $slug ) {
			$this->assertNotNull(
				gwc_pp_field_type( $slug ),
				$slug . ' is missing one of the callables in GWC_PP_TYPE_CONTRACT, so it would be dropped at runtime.'
			);
		}
	}

	public function test_a_type_missing_a_callable_is_dropped_rather_than_half_used(): void {
		$types = gwc_pp_field_types();

		$this->assertArrayHasKey( 'text', $types );
		$this->assertNotNull( gwc_pp_field_type( 'text' ) );
		$this->assertNull( gwc_pp_field_type( 'not_a_real_type' ) );
	}

	public function test_an_unknown_type_fails_closed(): void {
		$field = array(
			'key'  => 'x',
			'type' => 'not_a_real_type',
		);

		$this->assertSame( '', gwc_pp_field_call( $field, 'sanitize', array( '<b>hi</b>', $field ) ) );
		$this->assertTrue( gwc_pp_field_call( $field, 'is_empty', array( 'anything', $field ) ) );
		$this->assertSame( '', gwc_pp_field_call( $field, 'to_display', array( 'anything', $field ) ) );
	}

	/* ── Scalars ─────────────────────────────────────────────────────────── */

	public function test_text_is_stripped_of_tags(): void {
		$this->assertSame( 'Hello there', gwc_pp_sanitize_text( '<b>Hello</b> there' ) );
	}

	public function test_a_script_element_is_removed_contents_and_all(): void {
		$this->assertSame(
			'',
			gwc_pp_sanitize_text( '<script>alert(1)</script>' ),
			'Stripping the tags but keeping alert(1) as text would be the weaker behaviour.'
		);
	}

	public function test_an_array_submitted_where_a_scalar_belongs_becomes_empty(): void {
		// What arrives when somebody renames a control to phone[] in devtools.
		$this->assertSame( '', gwc_pp_sanitize_text( array( 'a', 'b' ) ) );
		$this->assertSame( '', gwc_pp_sanitize_number( array( 1 ) ) );
		$this->assertSame( '', gwc_pp_sanitize_phone( array( '555' ) ) );
	}

	public function test_a_maxlength_is_applied(): void {
		$field = array( 'settings' => array( 'maxlength' => 5 ) );

		$this->assertSame( 'abcde', gwc_pp_sanitize_text( 'abcdefghij', $field ) );
	}

	public function test_zero_is_not_treated_as_empty(): void {
		$this->assertFalse(
			gwc_pp_empty_scalar( '0' ),
			'A number field storing zero must not be deleted as though it were blank.'
		);
		$this->assertTrue( gwc_pp_empty_scalar( '' ) );
		$this->assertTrue( gwc_pp_empty_scalar( '   ' ) );
	}

	public function test_numbers_are_normalised_so_the_diff_does_not_lie(): void {
		$this->assertSame( '7', gwc_pp_sanitize_number( '007' ) );
		$this->assertSame( '7', gwc_pp_sanitize_number( '7.0' ) );
		$this->assertSame( '', gwc_pp_sanitize_number( 'seven' ) );
		$this->assertSame( '-2.5', gwc_pp_sanitize_number( '-2.5' ) );
	}

	public function test_number_bounds_are_checked(): void {
		$field = array(
			'settings' => array(
				'min' => '1',
				'max' => '10',
			),
		);

		$this->assertSame( '', gwc_pp_validate_number( '5', $field ) );
		$this->assertNotSame( '', gwc_pp_validate_number( '0', $field ) );
		$this->assertNotSame( '', gwc_pp_validate_number( '11', $field ) );
	}

	/* ── URLs ────────────────────────────────────────────────────────────── */

	public function test_a_bare_domain_gets_https_not_http(): void {
		$this->assertSame( 'https://example.org', gwc_pp_sanitize_url( 'example.org' ) );
	}

	public function test_a_javascript_url_is_refused(): void {
		$this->assertSame( '', gwc_pp_sanitize_url( 'javascript:alert(1)' ) );
	}

	public function test_a_data_url_is_refused(): void {
		$this->assertSame( '', gwc_pp_sanitize_url( 'data:text/html;base64,PHNjcmlwdD4=' ) );
	}

	public function test_an_existing_scheme_is_left_alone(): void {
		$this->assertSame( 'http://example.org/x', gwc_pp_sanitize_url( 'http://example.org/x' ) );
	}

	public function test_prose_is_refused_rather_than_percent_encoded_into_a_url(): void {
		/* Found by typing this into a real form. Prefixing a scheme and letting
		 * esc_url_raw encode the spaces produced https://not%20a%20website,
		 * which was then shown back in the field as though the person had
		 * typed it.
		 */
		$this->assertSame( '', gwc_pp_sanitize_url( 'not a website' ) );
		$this->assertSame( '', gwc_pp_sanitize_url( 'ask us for our website' ) );
	}

	public function test_a_typo_that_is_not_a_host_is_refused(): void {
		$this->assertSame( '', gwc_pp_sanitize_url( 'wwwexampleorg' ) );
		$this->assertSame( '', gwc_pp_sanitize_url( 'n/a' ) );
		$this->assertSame( '', gwc_pp_sanitize_url( 'none' ) );
	}

	public function test_the_website_control_does_not_block_a_bare_domain_client_side(): void {
		/* type="url" makes the browser refuse "shelterofhope.org" outright, so
		 * the bare-domain upgrade below can never run. Found by typing a real
		 * domain into a real form and watching the submission never happen.
		 */
		ob_start();
		gwc_pp_render_input(
			array(
				'key'  => 'website',
				'type' => 'url',
			),
			'',
			'gwc_pp_f[website]'
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertStringNotContainsString( 'type="url"', $html );
		$this->assertStringContainsString( 'inputmode="url"', $html, 'The phone keyboard is the part worth keeping.' );
	}

	public function test_a_real_bare_host_still_works(): void {
		$this->assertSame( 'https://example.org', gwc_pp_sanitize_url( 'example.org' ) );
		$this->assertSame( 'https://www.example.co.uk/a/b', gwc_pp_sanitize_url( 'www.example.co.uk/a/b' ) );
		$this->assertSame( 'https://example.org?a=1', gwc_pp_sanitize_url( 'example.org?a=1' ) );
	}

	/* ── Dates ───────────────────────────────────────────────────────────── */

	public function test_a_real_date_survives(): void {
		$this->assertSame( '2026-02-28', gwc_pp_sanitize_date( '2026-02-28' ) );
	}

	public function test_an_impossible_date_is_refused_rather_than_shifted(): void {
		$this->assertSame(
			'',
			gwc_pp_sanitize_date( '2026-02-31' ),
			'strtotime would read this as 3 March; silently moving somebody\'s date is worse than asking again.'
		);
	}

	public function test_a_date_in_another_format_is_refused(): void {
		$this->assertSame( '', gwc_pp_sanitize_date( '28/02/2026' ) );
		$this->assertSame( '', gwc_pp_sanitize_date( 'tomorrow' ) );
	}

	/* ── Phone ───────────────────────────────────────────────────────────── */

	public function test_a_phone_number_keeps_its_formatting_and_extension(): void {
		$this->assertSame( '(205) 555-0199 ext 4', gwc_pp_sanitize_phone( '(205) 555-0199 ext 4' ) );
		$this->assertSame( '+44 20 7946 0958', gwc_pp_sanitize_phone( '+44 20 7946 0958' ) );
	}

	public function test_a_phone_number_needs_seven_digits(): void {
		$this->assertNotSame( '', gwc_pp_validate_phone( '555' ) );
		$this->assertSame( '', gwc_pp_validate_phone( '5550199' ) );
		$this->assertSame( '', gwc_pp_validate_phone( '+44 20 7946 0958' ) );
	}

	/* ── Choices ─────────────────────────────────────────────────────────── */

	public function test_a_choice_not_on_the_list_is_refused(): void {
		$field = array(
			'type'     => 'select',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'a',
						'label' => 'A',
					),
				),
			),
		);

		$this->assertSame( 'a', gwc_pp_sanitize_choice( 'a', $field ) );
		$this->assertSame( '', gwc_pp_sanitize_choice( 'administrator', $field ) );
	}

	public function test_multi_choice_drops_unknown_values_and_deduplicates(): void {
		$field = array(
			'type'     => 'multiselect',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'a',
						'label' => 'A',
					),
					array(
						'value' => 'b',
						'label' => 'B',
					),
				),
			),
		);

		$this->assertSame(
			array( 'a', 'b' ),
			gwc_pp_sanitize_multiselect( array( 'b', 'a', 'a', 'nope' ), $field )
		);
	}

	public function test_multi_choice_is_stored_in_schema_order_so_the_diff_is_stable(): void {
		$field = array(
			'type'     => 'multiselect',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'a',
						'label' => 'A',
					),
					array(
						'value' => 'b',
						'label' => 'B',
					),
					array(
						'value' => 'c',
						'label' => 'C',
					),
				),
			),
		);

		$this->assertSame(
			gwc_pp_sanitize_multiselect( array( 'c', 'a' ), $field ),
			gwc_pp_sanitize_multiselect( array( 'a', 'c' ), $field ),
			'Two people ticking the same boxes in a different order must produce the same stored value.'
		);
	}

	public function test_a_choice_submitted_through_the_marker_wrapper_is_unwrapped(): void {
		$field = array(
			'type'     => 'radio',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'a',
						'label' => 'A',
					),
				),
			),
		);

		$this->assertSame(
			'a',
			gwc_pp_sanitize_choice(
				array(
					'__present' => '1',
					'value'     => 'a',
				),
				$field
			)
		);
	}

	/* ── Booleans ────────────────────────────────────────────────────────── */

	public function test_an_unticked_checkbox_reads_as_off(): void {
		// What arrives when the marker is submitted and the box was not ticked.
		$this->assertSame( '', gwc_pp_sanitize_boolean( array( '__present' => '1' ) ) );
		$this->assertSame( '1', gwc_pp_sanitize_boolean( array( '__present' => '1', 'value' => '1' ) ) );
	}

	/* ── The choice editor's format ──────────────────────────────────────── */

	public function test_options_parse_from_the_textarea_format(): void {
		$parsed = gwc_pp_parse_options( "al|Alabama\nak|Alaska\nArizona" );

		$this->assertSame(
			array(
				array(
					'value' => 'al',
					'label' => 'Alabama',
				),
				array(
					'value' => 'ak',
					'label' => 'Alaska',
				),
				array(
					'value' => 'arizona',
					'label' => 'Arizona',
				),
			),
			$parsed
		);
	}

	public function test_duplicate_and_blank_option_lines_are_dropped_not_fatal(): void {
		$parsed = gwc_pp_parse_options( "a|A\n\n   \na|Again\nb|B" );

		$this->assertSame( array( 'a', 'b' ), array_column( $parsed, 'value' ) );
		$this->assertSame( 'A', $parsed[0]['label'], 'The first definition wins.' );
	}

	public function test_options_accept_a_flat_map_from_code(): void {
		$field = array( 'settings' => array( 'options' => array( 'al' => 'Alabama' ) ) );

		$this->assertSame(
			array(
				array(
					'value' => 'al',
					'label' => 'Alabama',
				),
			),
			gwc_pp_field_options( $field )
		);
	}

	/* ── Display ─────────────────────────────────────────────────────────── */

	public function test_display_uses_labels_not_stored_values(): void {
		$field = array(
			'type'     => 'select',
			'settings' => array(
				'options' => array(
					array(
						'value' => 'al',
						'label' => 'Alabama',
					),
				),
			),
		);

		$this->assertSame( 'Alabama', gwc_pp_display_choice( 'al', $field ) );
	}

	public function test_display_falls_back_to_the_value_for_a_choice_no_longer_offered(): void {
		$field = array(
			'type'     => 'select',
			'settings' => array( 'options' => array() ),
		);

		$this->assertSame(
			'al',
			gwc_pp_display_choice( 'al', $field ),
			'A value stored before an option was removed should still show as something.'
		);
	}
}
