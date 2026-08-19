<?php
/**
 * Handing an entry over: who may do it, and when.
 *
 * ── What is covered here and what is not ─────────────────────────────────────
 * The guards, which all return before any mail is attempted — inc/emails.php is
 * not part of the unit bootstrap, and deliberately so. The accepting half, the
 * token comparison and the mail itself need a running WordPress and belong in
 * tests/integration/.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class HandoffTest extends TestCase {

	private const POST   = 70;
	private const ORG    = 71;
	private const MEMBER = 8;
	private const GRANTEE = 9;

	protected function setUp(): void {
		gwc_pp_test_reset();
		$GLOBALS['gwc_pp_test']['types'][] = 'clinic';

		gwc_pp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );
		gwc_pp_test_post( self::ORG, GWC_PP_ORG_TYPE, 'publish', 0, 'Main Street Trust' );

		gwc_pp_test_user( self::MEMBER );
		gwc_pp_test_user( self::GRANTEE );

		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'allow_handoff' => true ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		// The post belongs to the organisation.
		gwc_pp_set_post_org( self::POST, self::ORG );

		// One user belongs to the organisation; the other was given this single
		// post directly and belongs to nothing.
		add_user_meta( self::MEMBER, GWC_PP_USER_ORG_META, self::ORG );
		gwc_pp_add_post_editor( self::GRANTEE, self::POST );
	}

	/* ── The feature flag, re-checked in the handler ─────────────────────────
	 * The renderer checks allow_handoff before it draws the panel, but a nonce
	 * minted while the panel was on screen stays valid for up to a day. Of
	 * everything a portal user can do, this is the one that ends with a
	 * WordPress account being created, so the handler asks again.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	public function test_handoff_is_refused_when_the_post_type_has_it_switched_off(): void {
		update_option(
			'gwc_pp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'allow_handoff' => false ) ),
			)
		);
		gwc_pp_settings_cache( null, true );

		$result = gwc_pp_send_handoff( self::POST, self::MEMBER, 'new@example.org' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'gwc_pp_handoff_off', $result->get_error_code() );
	}

	/* ── Membership ──────────────────────────────────────────────────────────
	 * Accepting joins the new account to the whole organisation, so the person
	 * doing the inviting has to belong to it. Reaching the post by a direct
	 * grant is not the same thing, and letting it count would hand somebody a
	 * wider grant than they hold themselves.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	/**
	 * A member gets past the membership check.
	 *
	 * Asserted by aiming at the *next* guard rather than at success: actually
	 * succeeding would send mail, and inc/emails.php is not part of the unit
	 * bootstrap. Inviting an address that already belongs to a non-portal
	 * account stops at gwc_pp_handoff_existing, which is only reachable once
	 * membership has passed.
	 */
	public function test_a_member_of_the_organisation_may_hand_over(): void {
		gwc_pp_test_user( 11, array( 'editor' ) );

		$result = gwc_pp_send_handoff( self::POST, self::MEMBER, 'user11@example.test' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame(
			'gwc_pp_handoff_existing',
			$result->get_error_code(),
			'A member must reach the guards beyond the membership check.'
		);
	}

	public function test_somebody_with_only_a_direct_grant_may_not_hand_over(): void {
		$result = gwc_pp_send_handoff( self::POST, self::GRANTEE, 'new@example.org' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame(
			'gwc_pp_handoff_member',
			$result->get_error_code(),
			'A grant on one post must not let somebody add an account to the whole organisation.'
		);
	}

	public function test_a_stranger_may_not_hand_over(): void {
		gwc_pp_test_user( 99 );

		$result = gwc_pp_send_handoff( self::POST, 99, 'new@example.org' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'gwc_pp_handoff_member', $result->get_error_code() );
	}

	public function test_membership_of_a_different_organisation_does_not_count(): void {
		gwc_pp_test_post( 72, GWC_PP_ORG_TYPE, 'publish', 0, 'Some Other Trust' );
		gwc_pp_test_user( 10 );
		add_user_meta( 10, GWC_PP_USER_ORG_META, 72 );

		$result = gwc_pp_send_handoff( self::POST, 10, 'new@example.org' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'gwc_pp_handoff_member', $result->get_error_code() );
	}

	/* ── The earlier guards still hold ───────────────────────────────────── */

	public function test_a_malformed_address_is_refused_first(): void {
		$result = gwc_pp_send_handoff( self::POST, self::MEMBER, 'not-an-address' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'gwc_pp_handoff_email', $result->get_error_code() );
	}

	public function test_a_post_with_no_organisation_has_nothing_to_hand_over(): void {
		gwc_pp_test_post( 73, 'clinic', 'publish', 0, 'Unassigned Clinic' );

		$result = gwc_pp_send_handoff( 73, self::MEMBER, 'new@example.org' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'gwc_pp_handoff_org', $result->get_error_code() );
	}
}
