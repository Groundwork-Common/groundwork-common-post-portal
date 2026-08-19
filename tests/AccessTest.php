<?php
/**
 * The access decision — the one function everything else routes through.
 *
 * Every grant path is tested with its negative beside it. A test suite for an
 * authorization function that only proves it says yes is a suite that would
 * pass against `return true`.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessTest extends TestCase {

	protected function setUp(): void {
		gwc_pp_test_reset();

		gwc_pp_test_post( 500, 'gwc_pp_org', 'publish', 0, 'Shelter of Hope' );
		gwc_pp_test_post( 501, 'gwc_pp_org', 'publish', 0, 'Eastside Pantry' );
		gwc_pp_test_post( 10, 'clinic', 'publish', 99, 'Main Street Clinic' );

		gwc_pp_test_user( 1 );
		gwc_pp_test_user( 2 );

		update_option( 'gwc_pp_settings', array( 'post_types' => array( 'clinic' ) ) );
		gwc_pp_settings_cache( null, true );
	}

	/* ── The post type gate ──────────────────────────────────────────────── */

	public function test_a_post_type_that_is_not_enabled_grants_nothing(): void {
		gwc_pp_add_post_editor( 1, 10 );
		$this->assertTrue( gwc_pp_user_can_edit_post( 1, 10 ) );

		update_option( 'gwc_pp_settings', array( 'post_types' => array() ) );
		gwc_pp_settings_cache( null, true );

		$this->assertFalse(
			gwc_pp_user_can_edit_post( 1, 10 ),
			'Switching a post type off must withdraw a direct grant immediately.'
		);
	}

	public function test_a_post_type_that_no_longer_exists_grants_nothing(): void {
		gwc_pp_add_post_editor( 1, 10 );

		// The type is still in the settings, but nothing registers it any more.
		$GLOBALS['gwc_pp_test']['types'] = array( 'post', 'page', 'gwc_pp_org' );
		gwc_pp_settings_cache( null, true );

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	/* ── Direct grants ───────────────────────────────────────────────────── */

	public function test_a_direct_grant_lets_that_user_in(): void {
		gwc_pp_add_post_editor( 1, 10 );

		$this->assertTrue( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_a_direct_grant_does_not_let_anybody_else_in(): void {
		gwc_pp_add_post_editor( 1, 10 );

		$this->assertFalse( gwc_pp_user_can_edit_post( 2, 10 ) );
	}

	public function test_a_withdrawn_grant_stops_working(): void {
		gwc_pp_add_post_editor( 1, 10 );
		gwc_pp_remove_post_editor( 1, 10 );

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_granting_twice_does_not_create_a_second_row(): void {
		gwc_pp_add_post_editor( 1, 10 );
		gwc_pp_add_post_editor( 1, 10 );

		$this->assertSame( array( 1 ), gwc_pp_post_editors( 10 ) );
	}

	/* ── Organisations ───────────────────────────────────────────────────── */

	public function test_membership_of_the_owning_organisation_lets_a_user_in(): void {
		gwc_pp_set_post_org( 10, 500 );
		gwc_pp_add_user_to_org( 1, 500 );

		$this->assertTrue( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_two_people_in_one_organisation_can_both_edit_it(): void {
		gwc_pp_set_post_org( 10, 500 );
		gwc_pp_add_user_to_org( 1, 500 );
		gwc_pp_add_user_to_org( 2, 500 );

		$this->assertTrue( gwc_pp_user_can_edit_post( 1, 10 ) );
		$this->assertTrue( gwc_pp_user_can_edit_post( 2, 10 ) );
	}

	public function test_membership_of_a_different_organisation_grants_nothing(): void {
		gwc_pp_set_post_org( 10, 500 );
		gwc_pp_add_user_to_org( 2, 501 );

		$this->assertFalse( gwc_pp_user_can_edit_post( 2, 10 ) );
	}

	public function test_removing_somebody_from_an_organisation_withdraws_access(): void {
		gwc_pp_set_post_org( 10, 500 );
		gwc_pp_add_user_to_org( 1, 500 );
		gwc_pp_remove_user_from_org( 1, 500 );

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_a_deleted_organisation_does_not_leave_a_dangling_grant(): void {
		gwc_pp_set_post_org( 10, 500 );
		gwc_pp_add_user_to_org( 1, 500 );
		$this->assertTrue( gwc_pp_user_can_edit_post( 1, 10 ) );

		// The organisation post goes away; both meta rows still name its ID.
		unset( $GLOBALS['gwc_pp_test']['posts'][500] );

		$this->assertFalse(
			gwc_pp_user_can_edit_post( 1, 10 ),
			'A user must not reach a post through an organisation that no longer exists.'
		);
	}

	public function test_a_post_with_no_organisation_is_not_reachable_by_membership(): void {
		gwc_pp_add_user_to_org( 1, 500 );

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	/* ── Authorship ──────────────────────────────────────────────────────── */

	public function test_the_author_cannot_edit_by_default(): void {
		gwc_pp_test_user( 99 );

		$this->assertFalse(
			gwc_pp_user_can_edit_post( 99, 10 ),
			'author_grant defaults to off, so authorship alone must grant nothing.'
		);
	}

	public function test_the_author_can_edit_when_the_post_type_says_so(): void {
		gwc_pp_test_user( 99 );
		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'author_grant' => true ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		$this->assertTrue( gwc_pp_user_can_edit_post( 99, 10 ) );
		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ), 'Only the author, not everybody.' );
	}

	/* ── Statuses and nonsense input ─────────────────────────────────────── */

	public function test_a_trashed_post_is_not_editable(): void {
		gwc_pp_add_post_editor( 1, 10 );
		$GLOBALS['gwc_pp_test']['posts'][10]->post_status = 'trash';

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_an_auto_draft_is_not_editable(): void {
		gwc_pp_add_post_editor( 1, 10 );
		$GLOBALS['gwc_pp_test']['posts'][10]->post_status = 'auto-draft';

		$this->assertFalse( gwc_pp_user_can_edit_post( 1, 10 ) );
	}

	public function test_a_draft_is_still_editable(): void {
		gwc_pp_add_post_editor( 1, 10 );
		$GLOBALS['gwc_pp_test']['posts'][10]->post_status = 'draft';

		$this->assertTrue(
			gwc_pp_user_can_edit_post( 1, 10 ),
			'Somebody must be able to fix and republish a post that was taken down.'
		);
	}

	/**
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID.
	 */
	#[DataProvider( 'nonsense' )]
	public function test_nonsense_input_is_refused( int $user_id, int $post_id ): void {
		gwc_pp_add_post_editor( 1, 10 );

		$this->assertFalse( gwc_pp_user_can_edit_post( $user_id, $post_id ) );
	}

	/**
	 * @return array<string, array{0:int,1:int}>
	 */
	public static function nonsense(): array {
		return array(
			'no user'        => array( 0, 10 ),
			'no post'        => array( 1, 0 ),
			'negative user'  => array( -1, 10 ),
			'negative post'  => array( 1, -1 ),
			'unknown post'   => array( 1, 999999 ),
			'both missing'   => array( 0, 0 ),
		);
	}

	/* ── The role check ──────────────────────────────────────────────────── */

	public function test_role_membership_is_read_from_the_role_not_a_capability(): void {
		gwc_pp_test_user( 3, array( 'administrator' ) );

		$this->assertFalse(
			gwc_pp_user_is_portal_user( 3 ),
			'An administrator must never be treated as a portal user, or the wp-admin lockout would fire on them.'
		);
		$this->assertTrue( gwc_pp_user_is_portal_user( 1 ) );
	}

	public function test_a_user_who_does_not_exist_is_not_a_portal_user(): void {
		$this->assertFalse( gwc_pp_user_is_portal_user( 424242 ) );
	}
}
