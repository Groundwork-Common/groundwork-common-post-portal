<?php
/**
 * Writing values out, and in particular clearing them.
 *
 * ── Why the multi-value cases are worth their own file ───────────────────────
 * gwcpp_save_fields() decides whether a field that has gone empty needs its meta
 * row deleted, and to do that it has to look at what is stored. For most types
 * that is a string. For multiselect, checkbox and repeater it is an array, and
 * `(string) $array` is a PHP warning rather than a comparison.
 *
 * The guard for that was present but on the wrong side of an `||`, where
 * short-circuit evaluation could never reach it, so every save that cleared one
 * of those fields emitted "Array to string conversion" — into the log, or into
 * the middle of the page on a host with display_errors on. Nothing caught it
 * because no test cleared an array-valued field: phpunit.xml.dist sets
 * failOnWarning="true", so one that did would have failed the build.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class SaveFieldsTest extends TestCase {

	private const POST = 80;

	protected function setUp(): void {
		gwcpp_test_reset();
		$GLOBALS['gwcpp_test']['types'][] = 'clinic';

		gwcpp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );

		update_option( 'gwcpp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwcpp_settings_cache( null, true );

		gwcpp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array(
							array(
								'key'      => 'services',
								'type'     => 'multiselect',
								'label'    => 'Services',
								'settings' => array( 'choices' => "food\nclothing\nadvice" ),
							),
							array(
								'key'   => 'phone',
								'type'  => 'phone',
								'label' => 'Phone',
							),
						),
						'order'   => array( 'services', 'phone' ),
						'retired' => array(),
					),
				),
			)
		);
	}

	/* ── Clearing a field whose stored value is an array ─────────────────── */

	public function test_clearing_a_multi_value_field_removes_the_row(): void {
		update_post_meta( self::POST, 'services', array( 'food', 'clothing' ) );

		$changed = gwcpp_save_fields( self::POST, array( 'services' => array() ) );

		$this->assertTrue( $changed, 'Unticking every box is a change.' );
		$this->assertSame( '', get_post_meta( self::POST, 'services', true ) );
	}

	public function test_clearing_a_multi_value_field_emits_no_warning(): void {
		/*
		 * The assertion above passed even before the fix — the row really was
		 * deleted, it was just deleted noisily. This is the test that fails on
		 * the broken version, and it does so through failOnWarning rather than
		 * through anything written here, which is why it asserts something mild.
		 */
		update_post_meta( self::POST, 'services', array( 'food', 'clothing', 'advice' ) );

		gwcpp_save_fields( self::POST, array( 'services' => array() ) );

		$this->assertSame( '', get_post_meta( self::POST, 'services', true ) );
	}

	public function test_a_multi_value_field_that_was_already_empty_is_not_a_change(): void {
		$this->assertFalse( gwcpp_save_fields( self::POST, array( 'services' => array() ) ) );
	}

	/* ── The scalar cases, which always worked ───────────────────────────── */

	public function test_clearing_a_scalar_field_removes_the_row(): void {
		update_post_meta( self::POST, 'phone', '205 555 0100' );

		$this->assertTrue( gwcpp_save_fields( self::POST, array( 'phone' => '' ) ) );
		$this->assertSame( '', get_post_meta( self::POST, 'phone', true ) );
	}

	public function test_a_scalar_field_that_was_already_empty_is_not_a_change(): void {
		$this->assertFalse( gwcpp_save_fields( self::POST, array( 'phone' => '' ) ) );
	}

	public function test_setting_a_multi_value_field_stores_the_array(): void {
		gwcpp_save_fields( self::POST, array( 'services' => array( 'food', 'advice' ) ) );

		$this->assertSame( array( 'food', 'advice' ), get_post_meta( self::POST, 'services', true ) );
	}

	/* ── The allow-list is structural ────────────────────────────────────── */

	public function test_a_key_outside_the_schema_is_never_written(): void {
		gwcpp_save_fields( self::POST, array( '_gwcpp_internal_note' => 'nice try' ) );

		$this->assertSame( '', get_post_meta( self::POST, '_gwcpp_internal_note', true ) );
	}
}
