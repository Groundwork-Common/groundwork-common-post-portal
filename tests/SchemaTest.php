<?php
/**
 * The schema: keys, ordering, retirement, and the guarantees readers rely on.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	protected function setUp(): void {
		gwcpp_test_reset();
		$GLOBALS['gwcpp_test']['types'][] = 'clinic';
	}

	/** Add a plain text field. */
	private function put( string $key, string $label = 'X' ): void {
		gwcpp_put_field(
			'clinic',
			array(
				'key'   => $key,
				'type'  => 'text',
				'label' => $label,
			)
		);
	}

	/* ── Keys ────────────────────────────────────────────────────────────── */

	public function test_a_leading_underscore_is_stripped(): void {
		$this->assertSame(
			'phone',
			gwcpp_sanitize_field_key( '_phone' ),
			'A leading underscore marks meta as protected, and would produce a key the meta box cannot show.'
		);
	}

	public function test_a_user_key_can_never_collide_with_a_synthetic_one(): void {
		$this->assertSame( 'title', gwcpp_sanitize_field_key( '__title' ) );
		$this->assertNotSame( '__title', gwcpp_sanitize_field_key( '__title' ) );
	}

	public function test_keys_are_normalised(): void {
		$this->assertSame( 'contact_phone', gwcpp_sanitize_field_key( 'Contact Phone' ) );
		$this->assertSame( 'contact_phone', gwcpp_sanitize_field_key( 'contact--phone' ) );
		$this->assertSame( 'a_b', gwcpp_sanitize_field_key( 'a!!!b' ) );
		$this->assertSame( '', gwcpp_sanitize_field_key( '___' ) );
		$this->assertSame( '', gwcpp_sanitize_field_key( '   ' ) );
	}

	/* ── Field definitions ───────────────────────────────────────────────── */

	public function test_a_definition_with_no_key_is_refused(): void {
		$this->assertNull( gwcpp_sanitize_field( array( 'type' => 'text' ) ) );
	}

	public function test_a_definition_with_an_unknown_type_is_refused(): void {
		$this->assertNull(
			gwcpp_sanitize_field(
				array(
					'key'  => 'phone',
					'type' => 'a_type_from_another_plugin',
				)
			)
		);
	}

	public function test_settings_are_allow_listed(): void {
		$field = gwcpp_sanitize_field(
			array(
				'key'      => 'phone',
				'type'     => 'text',
				'settings' => array(
					'placeholder' => 'e.g. 205 555 0199',
					'maxlength'   => '30',
					'evil'        => 'rm -rf',
				),
			)
		);

		$this->assertSame( 'e.g. 205 555 0199', $field['settings']['placeholder'] );
		$this->assertSame( 30, $field['settings']['maxlength'] );
		$this->assertArrayNotHasKey( 'evil', $field['settings'] );
	}

	public function test_a_fractional_step_is_not_destroyed_by_an_int_cast(): void {
		$field = gwcpp_sanitize_field(
			array(
				'key'      => 'rating',
				'type'     => 'number',
				'settings' => array(
					'step' => '0.5',
					'min'  => '-3',
				),
			)
		);

		$this->assertSame( '0.5', $field['settings']['step'] );
		$this->assertSame( '-3', $field['settings']['min'] );
	}

	public function test_the_choice_textarea_is_parsed_at_sanitize_time(): void {
		$field = gwcpp_sanitize_field(
			array(
				'key'      => 'state',
				'type'     => 'select',
				'settings' => array( 'options_raw' => "al|Alabama\nak|Alaska" ),
			)
		);

		$this->assertArrayNotHasKey( 'options_raw', $field['settings'] );
		$this->assertSame( array( 'al', 'ak' ), array_column( $field['settings']['options'], 'value' ) );
	}

	/* ── Synthetic fields ────────────────────────────────────────────────── */

	public function test_a_synthetic_field_cannot_be_pointed_at_another_column(): void {
		$field = gwcpp_sanitize_field(
			array(
				'key'    => '__title',
				'type'   => 'textarea',
				'label'  => 'Name',
				'column' => 'post_content',
			)
		);

		$this->assertSame( 'post_title', $field['column'] );
		$this->assertSame( 'text', $field['type'], 'A synthetic field\'s type is fixed.' );
		$this->assertSame( 'Name', $field['label'], 'Its label is not.' );
	}

	public function test_the_title_is_required_whatever_the_schema_says(): void {
		$field = gwcpp_sanitize_field(
			array(
				'key'      => '__title',
				'type'     => 'text',
				'required' => false,
			)
		);

		$this->assertTrue( $field['required'] );
	}

	/* ── Ordering ────────────────────────────────────────────────────────── */

	public function test_fields_come_back_in_the_saved_order(): void {
		$this->put( 'a' );
		$this->put( 'b' );
		$this->put( 'c' );

		gwcpp_set_field_order( 'clinic', array( 'c', 'a', 'b' ) );

		$this->assertSame( array( 'c', 'a', 'b' ), array_column( gwcpp_type_fields( 'clinic' ), 'key' ) );
	}

	public function test_an_order_naming_a_field_that_no_longer_exists_is_ignored(): void {
		$this->put( 'a' );
		$this->put( 'b' );

		// Hand-write an order containing a stale key, as a restored option might.
		$schema = gwcpp_get_schema();
		$schema['types']['clinic']['order'] = array( 'ghost', 'b', 'a' );
		gwcpp_save_schema( $schema );

		$this->assertSame( array( 'b', 'a' ), array_column( gwcpp_type_fields( 'clinic' ), 'key' ) );
	}

	public function test_a_field_missing_from_the_order_is_appended_not_lost(): void {
		$this->put( 'a' );
		$this->put( 'b' );

		$schema = gwcpp_get_schema();
		$schema['types']['clinic']['order'] = array( 'b' );
		gwcpp_save_schema( $schema );

		$this->assertSame(
			array( 'b', 'a' ),
			array_column( gwcpp_type_fields( 'clinic' ), 'key' ),
			'Editing the order in another tab must never drop a field off the form.'
		);
	}

	public function test_reordering_drops_unknown_keys_and_keeps_forgotten_ones(): void {
		$this->put( 'a' );
		$this->put( 'b' );

		gwcpp_set_field_order( 'clinic', array( 'b', 'ghost' ) );

		$this->assertSame( array( 'b', 'a' ), array_column( gwcpp_type_fields( 'clinic' ), 'key' ) );
	}

	/* ── Retirement ──────────────────────────────────────────────────────── */

	public function test_retiring_a_field_keeps_its_definition(): void {
		$this->put( 'phone', 'Phone' );
		gwcpp_retire_field( 'clinic', 'phone' );

		$this->assertNull( gwcpp_find_field( 'clinic', 'phone' ) );

		$entry = gwcpp_type_schema( 'clinic' );
		$this->assertSame( array( 'phone' ), array_column( $entry['retired'], 'key' ) );
		$this->assertNotContains( 'phone', $entry['order'] );
	}

	public function test_re_adding_a_retired_field_takes_it_off_the_retired_list(): void {
		$this->put( 'phone', 'Phone' );
		gwcpp_retire_field( 'clinic', 'phone' );
		$this->put( 'phone', 'Phone again' );

		$entry = gwcpp_type_schema( 'clinic' );
		$this->assertSame( array(), $entry['retired'] );
		$this->assertSame( 'Phone again', gwcpp_find_field( 'clinic', 'phone' )['label'] );
	}

	public function test_retiring_something_that_is_not_there_reports_failure(): void {
		$this->assertFalse( gwcpp_retire_field( 'clinic', 'nothing' ) );
	}

	/* ── Structural guarantees ───────────────────────────────────────────── */

	public function test_a_missing_option_still_yields_a_usable_schema(): void {
		$schema = gwcpp_get_schema();

		$this->assertArrayHasKey( 'types', $schema );
		$this->assertIsArray( $schema['types'] );
		$this->assertSame( array(), gwcpp_type_fields( 'anything' ) );
	}

	public function test_a_garbage_option_does_not_fatal(): void {
		update_option( 'gwcpp_schema', 'this is not an array' );
		gwcpp_schema_cache( null, true );

		$schema = gwcpp_get_schema();
		$this->assertIsArray( $schema['types'] );
	}

	public function test_a_half_written_type_entry_is_completed(): void {
		update_option( 'gwcpp_schema', array( 'types' => array( 'clinic' => array( 'fields' => array() ) ) ) );
		gwcpp_schema_cache( null, true );

		$entry = gwcpp_type_schema( 'clinic' );

		$this->assertSame( array(), $entry['order'] );
		$this->assertSame( array(), $entry['retired'] );
	}

	public function test_writing_the_schema_invalidates_the_read_cache(): void {
		$this->put( 'a' );
		$this->assertCount( 1, gwcpp_type_fields( 'clinic' ) );

		$this->put( 'b' );
		$this->assertCount(
			2,
			gwcpp_type_fields( 'clinic' ),
			'A writer and a reader in the same request must not disagree.'
		);
	}

	public function test_a_field_whose_type_vanished_is_dropped_from_the_form(): void {
		update_option(
			'gwcpp_schema',
			array(
				'types' => array(
					'clinic' => array(
						'fields' => array(
							array(
								'key'  => 'ghost',
								'type' => 'a_type_from_another_plugin',
							),
							array(
								'key'  => 'phone',
								'type' => 'text',
							),
						),
						'order'  => array( 'ghost', 'phone' ),
					),
				),
			)
		);
		gwcpp_schema_cache( null, true );

		$this->assertSame( array( 'phone' ), array_column( gwcpp_type_fields( 'clinic' ), 'key' ) );
	}
}
