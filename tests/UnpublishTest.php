<?php
/**
 * Taking an entry down, and putting it back.
 *
 * ── The rule these are guarding ──────────────────────────────────────────────
 * A handler may not depend on the renderer having declined to draw a control.
 * Nonces last up to a day, so a form that was legitimately on screen this
 * morning can be submitted this evening into a site whose settings have since
 * changed. Both halves of unpublish/republish therefore ask the same questions
 * the renderer asks, rather than trusting that they were already asked.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class UnpublishTest extends TestCase {

	private const POST = 80;
	private const USER = 6;

	protected function setUp(): void {
		gwcpp_test_reset();
		$GLOBALS['gwcpp_test']['types'][] = 'clinic';

		gwcpp_test_post( self::POST, 'clinic', 'publish', 0, 'Main Street Clinic' );
		gwcpp_test_user( self::USER );

		$this->allow_unpublish( true );
	}

	private function allow_unpublish( bool $on ): void {
		update_option(
			'gwcpp_settings',
			array(
				'post_types' => array( 'clinic' ),
				'types'      => array( 'clinic' => array( 'allow_unpublish' => $on ) ),
			)
		);
		gwcpp_settings_cache( null, true );
	}

	/* ── Taking it down ──────────────────────────────────────────────────── */

	public function test_unpublishing_moves_it_to_draft_and_records_who(): void {
		$this->assertTrue( gwcpp_unpublish_post( self::POST, self::USER ) );

		$this->assertSame( 'draft', get_post( self::POST )->post_status );
		$this->assertSame(
			self::USER,
			(int) get_post_meta( self::POST, '_gwcpp_unpublished_by', true ),
			'Six months later somebody will ask why a listing vanished.'
		);
	}

	public function test_unpublishing_is_refused_when_the_setting_is_off(): void {
		$this->allow_unpublish( false );

		$this->assertFalse( gwcpp_unpublish_post( self::POST, self::USER ) );
		$this->assertSame( 'publish', get_post( self::POST )->post_status );
	}

	/* ── Putting it back ─────────────────────────────────────────────────────
	 * gwcpp_republish_post() used to check only that the post was a draft, so a
	 * stored form could republish any draft the submitter could reach — long
	 * after staff switched the feature off, and including drafts that a portal
	 * user never took down in the first place.
	 * ─────────────────────────────────────────────────────────────────────── */

	public function test_republishing_puts_it_back_and_clears_the_marker(): void {
		gwcpp_unpublish_post( self::POST, self::USER );

		$this->assertTrue( gwcpp_republish_post( self::POST ) );
		$this->assertSame( 'publish', get_post( self::POST )->post_status );
		$this->assertSame( '', get_post_meta( self::POST, '_gwcpp_unpublished_by', true ) );
	}

	public function test_republishing_is_refused_once_the_setting_is_switched_off(): void {
		gwcpp_unpublish_post( self::POST, self::USER );

		// Staff decide the entry should stay down.
		$this->allow_unpublish( false );

		$this->assertFalse(
			gwcpp_republish_post( self::POST ),
			'A nonce minted while the button was on screen outlives the setting that put it there.'
		);
		$this->assertSame( 'draft', get_post( self::POST )->post_status );
	}

	public function test_a_draft_no_portal_user_took_down_cannot_be_republished(): void {
		gwcpp_test_post( 81, 'clinic', 'draft', 0, 'Never Published' );

		$this->assertFalse(
			gwcpp_republish_post( 81 ),
			'Only an entry a portal user unpublished may be put back by one; everything else is staff\'s to publish.'
		);
		$this->assertSame( 'draft', get_post( 81 )->post_status );
	}

	public function test_a_draft_staff_re_drafted_by_hand_cannot_be_republished(): void {
		gwcpp_unpublish_post( self::POST, self::USER );

		// Staff clear the marker, which is what republishing from wp-admin does.
		delete_post_meta( self::POST, '_gwcpp_unpublished_by' );

		$this->assertFalse( gwcpp_republish_post( self::POST ) );
	}

	public function test_a_published_post_is_not_republished_again(): void {
		$this->assertFalse( gwcpp_republish_post( self::POST ) );
	}
}
