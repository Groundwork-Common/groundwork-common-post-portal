<?php
/**
 * The validator.
 *
 * ── Why these assert on the key set ──────────────────────────────────────────
 * The portal this was generalised from had a validator with a leftover early
 * return in it: when one particular branch fired, it returned a two-element
 * associative array instead of the accumulated error list, silently discarding
 * every other error in the submission. Its own test filtered the result for a
 * substring and passed either way.
 *
 * So the tests here assert on the exact keys returned, and there is one below
 * that submits three broken fields at once specifically to catch a return that
 * stops at the first. A substring assertion would not.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class ValidateTest extends TestCase {

	protected function setUp(): void {
		gwc_pp_test_reset();
		$GLOBALS['gwc_pp_test']['types'][] = 'clinic';

		gwc_pp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array(
							array(
								'key'      => '__title',
								'type'     => 'text',
								'label'    => 'Name',
								'required' => true,
							),
							array(
								'key'      => 'phone',
								'type'     => 'phone',
								'label'    => 'Phone number',
								'required' => true,
							),
							array(
								'key'   => 'website',
								'type'  => 'url',
								'label' => 'Website',
							),
							array(
								'key'   => 'contact',
								'type'  => 'email',
								'label' => 'Contact email',
							),
							array(
								'key'      => 'services',
								'type'     => 'multiselect',
								'label'    => 'Services',
								'settings' => array(
									'options'     => array(
										array(
											'value' => 'a',
											'label' => 'A',
										),
										array(
											'value' => 'b',
											'label' => 'B',
										),
									),
									'max_choices' => 1,
								),
							),
						),
						'order'   => array( '__title', 'phone', 'website', 'contact', 'services' ),
						'retired' => array(),
					),
				),
			)
		);
	}

	public function test_a_good_submission_has_no_errors(): void {
		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title' => 'Main Street Clinic',
				'phone'   => '205 555 0199',
			)
		);

		$this->assertSame( array(), $errors );
	}

	public function test_every_broken_field_is_reported_not_just_the_first(): void {
		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title'  => '',
				'phone'    => '12',
				'website'  => 'not a url at all',
				'contact'  => 'also not an email',
				'services' => array( 'a', 'b' ),
			)
		);

		/* The exact key set, sorted, so this fails on both an early return that
		 * drops later errors and on a spurious extra one.
		 */
		$keys = array_keys( $errors );
		sort( $keys );

		$this->assertSame(
			array( '__title', 'contact', 'phone', 'services', 'website' ),
			$keys
		);
	}

	public function test_every_message_is_a_non_empty_string(): void {
		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title' => '',
				'phone'   => '1',
			)
		);

		foreach ( $errors as $key => $message ) {
			$this->assertIsString( $message, $key . ' should have a string message' );
			$this->assertNotSame( '', trim( $message ), $key . ' should not have an empty message' );
		}
	}

	public function test_a_field_the_form_did_not_submit_is_not_validated(): void {
		// `phone` is required, and absent — because this form never showed it.
		$errors = gwc_pp_validate_submission( 'clinic', array( '__title' => 'A clinic' ) );

		$this->assertSame(
			array(),
			$errors,
			'A required field added to the schema after a form was opened must not make that form unsubmittable.'
		);
	}

	public function test_an_empty_optional_field_is_not_validated(): void {
		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title' => 'A clinic',
				'phone'   => '205 555 0199',
				'website' => '',
				'contact' => '',
			)
		);

		$this->assertSame(
			array(),
			$errors,
			'A blank optional field must not attract "that is not a valid web address".'
		);
	}

	public function test_a_required_field_left_blank_is_reported(): void {
		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title' => '   ',
				'phone'   => '205 555 0199',
			)
		);

		$this->assertArrayHasKey( '__title', $errors );
		$this->assertStringContainsString( 'Name', $errors['__title'], 'The message should name the field.' );
	}

	public function test_a_required_multi_choice_left_empty_is_reported(): void {
		gwc_pp_put_field(
			'clinic',
			array(
				'key'      => 'services',
				'type'     => 'multiselect',
				'label'    => 'Services',
				'required' => true,
				'settings' => array(
					'options' => array(
						array(
							'value' => 'a',
							'label' => 'A',
						),
					),
				),
			)
		);

		$errors = gwc_pp_validate_submission(
			'clinic',
			array(
				'__title'  => 'A clinic',
				'phone'    => '205 555 0199',
				'services' => array(),
			)
		);

		$this->assertArrayHasKey( 'services', $errors );
		$this->assertStringContainsString( 'choose', strtolower( $errors['services'] ) );
	}

	public function test_a_field_whose_type_no_longer_exists_is_skipped_not_fatal(): void {
		gwc_pp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array(
							array(
								'key'      => 'ghost',
								'type'     => 'a_type_from_another_plugin',
								'label'    => 'Ghost',
								'required' => true,
							),
						),
						'order'   => array( 'ghost' ),
						'retired' => array(),
					),
				),
			)
		);

		$this->assertSame( array(), gwc_pp_validate_submission( 'clinic', array( 'ghost' => '' ) ) );
	}

	/* ── Values that sanitize away to nothing ────────────────────────────────
	 * url, email and date all turn an unusable value into ''. Found by typing
	 * "not a website" into a real form: the field silently emptied, and because
	 * it was optional there was no error at all — the input simply vanished
	 * between the browser and the page that came back.
	 */

	public function test_an_optional_field_that_sanitized_away_is_reported_not_silently_dropped(): void {
		$raw = array( 'website' => 'not a website' );

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );
		$errors  = gwc_pp_validate_submission( 'clinic', $values, $dropped );

		$this->assertSame( '', $values['website'], 'It should not be stored.' );
		$this->assertSame( array( 'website' => 'not a website' ), $dropped );
		$this->assertArrayHasKey(
			'website',
			$errors,
			'Typing something wrong into an optional field must not make it disappear in silence.'
		);
	}

	public function test_the_message_for_a_dropped_value_comes_from_its_own_type(): void {
		$raw = array( 'website' => 'not a website' );

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );
		$errors  = gwc_pp_validate_submission( 'clinic', $values, $dropped );

		$this->assertStringContainsString( 'web address', $errors['website'] );
	}

	public function test_a_required_field_that_sanitized_away_does_not_say_please_fill_it_in(): void {
		gwc_pp_put_field(
			'clinic',
			array(
				'key'      => 'contact',
				'type'     => 'email',
				'label'    => 'Contact email',
				'required' => true,
			)
		);

		$raw = array(
			'__title' => 'A clinic',
			'phone'   => '205 555 0199',
			'contact' => 'jane at shelter dot org',
		);

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );
		$errors  = gwc_pp_validate_submission( 'clinic', $values, $dropped );

		$this->assertArrayHasKey( 'contact', $errors );
		$this->assertStringNotContainsString(
			'fill in',
			$errors['contact'],
			'They did fill it in. Telling them otherwise sends them looking for a field they already completed.'
		);
	}

	public function test_a_genuinely_blank_field_is_not_reported_as_dropped(): void {
		$raw = array( 'website' => '   ' );

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );

		$this->assertSame( array(), $dropped );
		$this->assertSame( array(), gwc_pp_validate_submission( 'clinic', $values, $dropped ) );
	}

	public function test_an_untouched_checkbox_group_is_not_reported_as_dropped(): void {
		// Just the hidden marker, which is what an emptied group submits.
		$raw = array( 'services' => array( '__present' => '1' ) );

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );

		$this->assertSame(
			array(),
			$dropped,
			'Clearing every box is a real edit, not a value that failed to sanitize.'
		);
	}

	public function test_a_bad_date_is_reported_rather_than_shifted_or_dropped(): void {
		gwc_pp_put_field(
			'clinic',
			array(
				'key'   => 'opened',
				'type'  => 'date',
				'label' => 'Opening date',
			)
		);

		$raw = array( 'opened' => '28/02/2026' );

		$values  = gwc_pp_collect_submission( 'clinic', $raw );
		$dropped = gwc_pp_dropped_fields( 'clinic', $raw, $values );
		$errors  = gwc_pp_validate_submission( 'clinic', $values, $dropped );

		$this->assertArrayHasKey( 'opened', $errors );
	}

	/* ── The summary ─────────────────────────────────────────────────────── */

	public function test_the_summary_counts_fields_not_array_entries(): void {
		$this->assertSame( '', gwc_pp_error_summary( array() ) );

		$one = gwc_pp_error_summary( array( 'a' => 'x' ) );
		$this->assertStringContainsString( '1 thing needs', $one );

		$three = gwc_pp_error_summary(
			array(
				'a' => 'x',
				'b' => 'y',
				'c' => 'z',
			)
		);
		$this->assertStringContainsString( '3 things', $three );
	}

	public function test_the_summary_does_not_repeat_the_messages(): void {
		$summary = gwc_pp_error_summary( array( 'phone' => 'Please add an area code.' ) );

		$this->assertStringNotContainsString(
			'area code',
			$summary,
			'The summary counts; the messages live beside their own fields.'
		);
	}
}
