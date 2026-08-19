<?php
/**
 * Pending changes: storage, superseding, the diff, and applying.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class ChangesetTest extends TestCase {

	private const POST = 60;

	protected function setUp(): void {
		gwc_pp_test_reset();
		$GLOBALS['gwc_pp_test']['types'][] = 'clinic';

		gwc_pp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );
		gwc_pp_test_user( 7 );

		update_option( 'gwc_pp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwc_pp_settings_cache( null, true );

		gwc_pp_save_schema(
			array(
				'types' => array(
					'clinic' => array(
						'fields'  => array(
							array(
								'key'   => '__title',
								'type'  => 'text',
								'label' => 'Name',
							),
							array(
								'key'   => 'phone',
								'type'  => 'phone',
								'label' => 'Phone',
							),
							array(
								'key'   => 'website',
								'type'  => 'url',
								'label' => 'Website',
							),
						),
						'order'   => array( '__title', 'phone', 'website' ),
						'retired' => array(),
					),
				),
			)
		);

		update_post_meta( self::POST, 'phone', '205 555 0100' );
	}

	/* ── Storage ─────────────────────────────────────────────────────────── */

	public function test_a_post_starts_with_nothing_pending(): void {
		$this->assertNull( gwc_pp_get_changeset( self::POST ) );
		$this->assertFalse( gwc_pp_has_changeset( self::POST ) );
	}

	public function test_a_changeset_round_trips(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );

		$stored = gwc_pp_get_changeset( self::POST );

		$this->assertNotNull( $stored );
		$this->assertSame( 7, $stored['user'] );
		$this->assertSame( array( 'phone' => '205 555 0199' ), $stored['values'] );
		$this->assertGreaterThan( 0, $stored['time'] );
	}

	public function test_submitting_again_replaces_rather_than_queues(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '111 111 1111' ) );
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '222 222 2222' ) );

		$stored = gwc_pp_get_changeset( self::POST );

		$this->assertSame(
			'222 222 2222',
			$stored['values']['phone'],
			'A queue would let staff approve the older edit and silently revert the newer one.'
		);
	}

	public function test_a_garbage_meta_row_reads_as_nothing_pending(): void {
		update_post_meta( self::POST, '_gwc_pp_pending', 'not an array' );

		$this->assertNull( gwc_pp_get_changeset( self::POST ) );
	}

	/* ── The live post is untouched ──────────────────────────────────────── */

	public function test_storing_a_changeset_does_not_touch_the_post(): void {
		gwc_pp_store_changeset(
			self::POST,
			7,
			array(
				'__title' => 'Renamed',
				'phone'   => '205 555 0199',
			)
		);

		$this->assertSame( 'Main Street Clinic', get_post( self::POST )->post_title );
		$this->assertSame(
			'205 555 0100',
			get_post_meta( self::POST, 'phone', true ),
			'This is the whole point: the public keeps seeing the old value until somebody approves.'
		);
	}

	/* ── The diff ────────────────────────────────────────────────────────── */

	public function test_the_diff_reports_only_what_differs(): void {
		gwc_pp_store_changeset(
			self::POST,
			7,
			array(
				// Unchanged.
				'phone'   => '205 555 0100',
				// Changed.
				'__title' => 'Main Street Clinic (Bessemer)',
			)
		);

		$diff = gwc_pp_changeset_diff( self::POST );

		$this->assertSame( array( '__title' ), array_column( $diff, 'key' ) );
		$this->assertSame( 'Main Street Clinic', $diff[0]['old'] );
		$this->assertSame( 'Main Street Clinic (Bessemer)', $diff[0]['new'] );
	}

	public function test_the_diff_labels_rows_for_a_human(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );

		$diff = gwc_pp_changeset_diff( self::POST );

		$this->assertSame( 'Phone', $diff[0]['label'] );
	}

	public function test_the_diff_follows_the_post_when_staff_edit_underneath_it(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );
		$this->assertSame( '205 555 0100', gwc_pp_changeset_diff( self::POST )[0]['old'] );

		// Staff change it by hand while the submission waits.
		update_post_meta( self::POST, 'phone', '205 555 0123' );

		$this->assertSame(
			'205 555 0123',
			gwc_pp_changeset_diff( self::POST )[0]['old'],
			'A frozen diff would describe a post that no longer exists.'
		);
	}

	public function test_the_diff_is_empty_when_staff_already_made_the_change(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );
		update_post_meta( self::POST, 'phone', '205 555 0199' );

		$this->assertSame( array(), gwc_pp_changeset_diff( self::POST ) );
	}

	public function test_a_field_retired_after_submission_drops_out_of_the_diff(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );
		gwc_pp_retire_field( 'clinic', 'phone' );

		$this->assertSame( array(), gwc_pp_changeset_diff( self::POST ) );
	}

	/* ── Applying ────────────────────────────────────────────────────────── */

	public function test_approving_writes_the_values(): void {
		gwc_pp_store_changeset(
			self::POST,
			7,
			array(
				'__title' => 'Renamed',
				'phone'   => '205 555 0199',
			)
		);

		$this->assertTrue( gwc_pp_apply_changeset( self::POST, 2 ) );

		$this->assertSame( 'Renamed', get_post( self::POST )->post_title );
		$this->assertSame( '205 555 0199', get_post_meta( self::POST, 'phone', true ) );
		$this->assertNull( gwc_pp_get_changeset( self::POST ), 'It should be gone once applied.' );
	}

	public function test_approving_cannot_write_a_field_that_was_retired_meanwhile(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );
		gwc_pp_retire_field( 'clinic', 'phone' );

		gwc_pp_apply_changeset( self::POST, 2 );

		$this->assertSame(
			'205 555 0100',
			get_post_meta( self::POST, 'phone', true ),
			'gwc_pp_save_fields iterates the schema, so a retired field is never written.'
		);
	}

	public function test_approving_nothing_reports_failure_rather_than_pretending(): void {
		$this->assertFalse( gwc_pp_apply_changeset( self::POST, 2 ) );
	}

	public function test_rejecting_leaves_the_post_alone_and_clears_the_queue(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );

		$this->assertTrue( gwc_pp_reject_changeset( self::POST, 2, 'Please include the area code.' ) );

		$this->assertSame( '205 555 0100', get_post_meta( self::POST, 'phone', true ) );
		$this->assertNull( gwc_pp_get_changeset( self::POST ) );
	}

	public function test_rejecting_nothing_reports_failure(): void {
		$this->assertFalse( gwc_pp_reject_changeset( self::POST, 2 ) );
	}

	/* ── The change log ──────────────────────────────────────────────────── */

	public function test_applying_records_what_changed(): void {
		gwc_pp_store_changeset( self::POST, 7, array( 'phone' => '205 555 0199' ) );
		gwc_pp_apply_changeset( self::POST, 2 );

		$log = gwc_pp_change_log( self::POST );

		$this->assertCount( 1, $log );
		$this->assertSame( 7, $log[0]['user'] );
		$this->assertSame( 2, $log[0]['approved_by'] );
		$this->assertSame( 'phone', $log[0]['diff'][0]['key'] );
	}

	public function test_the_log_is_bounded(): void {
		for ( $i = 0; $i < 15; $i++ ) {
			gwc_pp_log_change(
				self::POST,
				7,
				2,
				array(
					array(
						'key'   => 'phone',
						'label' => 'Phone',
						'old'   => (string) $i,
						'new'   => (string) ( $i + 1 ),
					),
				)
			);
		}

		$log = gwc_pp_change_log( self::POST );

		$this->assertCount( 10, $log );
		$this->assertSame(
			'15',
			$log[0]['diff'][0]['new'],
			'Newest first, so the oldest are what fall off.'
		);
	}

	public function test_a_change_that_changed_nothing_is_not_logged(): void {
		gwc_pp_log_change( self::POST, 7, 2, array() );

		$this->assertSame( array(), gwc_pp_change_log( self::POST ) );
	}
}
