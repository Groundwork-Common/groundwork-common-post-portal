<?php
/**
 * The sign-in rate limiter, and the settings clamps.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase {

	protected function setUp(): void {
		gwcpp_test_reset();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	}

	public function test_the_first_attempts_are_allowed_and_the_fourth_is_not(): void {
		// The email window allows three per hour.
		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
		$this->assertTrue( gwcpp_rate_limited( 'jane@example.org' ) );
	}

	public function test_a_refused_attempt_still_counts(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			gwcpp_rate_limited( 'jane@example.org' );
		}

		$this->assertTrue(
			gwcpp_rate_limited( 'jane@example.org' ),
			'Ignoring the refusal must not reset the counter, or the limit is free to walk past.'
		);
	}

	public function test_one_address_does_not_use_up_another_addresses_allowance(): void {
		gwcpp_rate_limited( 'jane@example.org' );
		gwcpp_rate_limited( 'jane@example.org' );
		gwcpp_rate_limited( 'jane@example.org' );

		$this->assertFalse( gwcpp_rate_limited( 'mark@example.org' ) );
	}

	public function test_the_address_itself_is_never_stored(): void {
		gwcpp_rate_limited( 'jane@example.org' );

		$stored = wp_json_encode( get_option( 'gwcpp_rate_limits' ) );

		$this->assertStringNotContainsString(
			'jane@example.org',
			(string) $stored,
			'A list of every address that ever tried to sign in is a list worth not keeping.'
		);
	}

	public function test_the_window_resets_once_it_has_passed(): void {
		gwcpp_rate_limited( 'jane@example.org' );
		gwcpp_rate_limited( 'jane@example.org' );
		gwcpp_rate_limited( 'jane@example.org' );
		$this->assertTrue( gwcpp_rate_limited( 'jane@example.org' ) );

		// Age every window past its expiry, as the clock would.
		$state = get_option( 'gwcpp_rate_limits' );
		foreach ( $state as $scope => $entries ) {
			foreach ( $entries as $key => $entry ) {
				$state[ $scope ][ $key ]['start'] = time() - ( 2 * DAY_IN_SECONDS );
			}
		}
		update_option( 'gwcpp_rate_limits', $state );

		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
	}

	public function test_the_site_wide_window_catches_a_spread_out_attempt(): void {
		// Thirty per hour site-wide, one attempt each from thirty addresses.
		for ( $i = 0; $i < 30; $i++ ) {
			gwcpp_rate_limited( 'person' . $i . '@example.org' );
		}

		$this->assertTrue(
			gwcpp_rate_limited( 'person-new@example.org' ),
			'A fresh address must still be caught by the site-wide backstop.'
		);
	}

	public function test_expired_counters_are_pruned_rather_than_kept_forever(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			gwcpp_rate_limited( 'person' . $i . '@example.org' );
		}

		$state = get_option( 'gwcpp_rate_limits' );
		$this->assertGreaterThan( 1, count( $state['signin:email'] ) );

		foreach ( $state['signin:email'] as $key => $entry ) {
			$state['signin:email'][ $key ]['start'] = time() - ( 2 * DAY_IN_SECONDS );
		}
		update_option( 'gwcpp_rate_limits', $state );

		gwcpp_rate_limited( 'someone-else@example.org' );

		$this->assertCount(
			1,
			get_option( 'gwcpp_rate_limits' )['signin:email'],
			'Only the fresh counter should be left; the option must not grow without bound.'
		);
	}

	/**
	 * Counters written by the older unprefixed shape must age out on their own.
	 *
	 * Scopes used to be stored as `email` / `ip` / `global`; they are now
	 * `signin:email` and so on, so that the password form and handoff can have
	 * windows of their own without sharing a budget with sign-in. There is no
	 * migration — the pruner does not recognise the old keys, falls back to an
	 * hour, and clears them. This asserts that rather than leaving it to be
	 * rediscovered.
	 */
	public function test_counters_from_the_old_unprefixed_shape_are_cleared(): void {
		update_option(
			'gwcpp_rate_limits',
			array(
				'email' => array(
					'deadbeef' => array(
						'start' => time() - ( 2 * DAY_IN_SECONDS ),
						'count' => 99,
					),
				),
			)
		);

		gwcpp_rate_limited( 'jane@example.org' );

		$this->assertArrayNotHasKey( 'email', get_option( 'gwcpp_rate_limits' ) );
	}

	public function test_an_unparseable_client_address_still_gets_a_counter(): void {
		$_SERVER['REMOTE_ADDR'] = 'not an ip';

		$this->assertSame( 'unknown', gwcpp_client_ip() );
		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
	}

	/* ── What is worth counting ──────────────────────────────────────────────
	 * The limiter counts before it reports, so anything that reaches it spends
	 * a slot whether or not it could ever have produced an email. The site-wide
	 * window is thirty an hour and a logged-out nonce is the same nonce for
	 * every logged-out visitor, so a handler that counted first and validated
	 * second let any passer-by lock the whole site out of sign-in for an hour
	 * with thirty-one junk POSTs — silently, because the response is identical
	 * either way.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	public function test_junk_and_bots_are_not_worth_counting(): void {
		$this->assertFalse( gwcpp_signin_worth_counting( '', '' ), 'An empty form sends nothing.' );
		$this->assertFalse( gwcpp_signin_worth_counting( '', 'not-an-address' ), 'A malformed address sends nothing.' );
		$this->assertFalse( gwcpp_signin_worth_counting( '', 'jane@' ), 'Nor does a half-typed one.' );
		$this->assertFalse(
			gwcpp_signin_worth_counting( 'http://spam.example', 'jane@example.org' ),
			'The honeypot caught this before the limiter was ever the right question.'
		);
	}

	public function test_a_real_request_is_worth_counting(): void {
		$this->assertTrue( gwcpp_signin_worth_counting( '', 'jane@example.org' ) );
	}

	/**
	 * The regression itself: junk must not exhaust the site-wide backstop.
	 *
	 * Written against the same predicate the handler uses rather than against
	 * the handler, which redirects and exits. If the handler stops consulting
	 * this before gwcpp_rate_limited(), the bug is back and this test will not
	 * see it — which is why the predicate is a named function with the reasoning
	 * on it rather than an inline condition.
	 */
	public function test_junk_submissions_do_not_exhaust_the_site_wide_window(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			$honeypot = 0 === $i % 2 ? '' : 'http://spam.example';
			$email    = 0 === $i % 2 ? 'garbage-' . $i : 'jane@example.org';

			if ( gwcpp_signin_worth_counting( $honeypot, $email ) ) {
				gwcpp_rate_limited( $email );
			}
		}

		$this->assertFalse(
			gwcpp_rate_limited( 'a-real-partner@example.org' ),
			'Sixty junk submissions must leave a real partner able to ask for a link.'
		);
	}

	/* ── Separate budgets ────────────────────────────────────────────────────
	 * The password form and handoff each got their own windows rather than
	 * borrowing sign-in's. Sharing would have put the S1 lockout back through a
	 * different door: thirty-one guesses at a plausible username would spend the
	 * site-wide sign-in backstop and leave every partner unable to request a
	 * magic link. Two throttles protecting different things must not share a
	 * budget, and these tests are what says so.
	 * ───────────────────────────────────────────────────────────────────────
	 */

	public function test_password_attempts_do_not_lock_out_magic_links(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			gwcpp_login_rate_limited( 'someuser' );
		}

		$this->assertFalse(
			gwcpp_rate_limited( 'jane@example.org' ),
			'Guessing at passwords must not cost anybody else a sign-in link.'
		);
	}

	public function test_magic_link_requests_do_not_lock_out_the_password_form(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			gwcpp_rate_limited( 'person' . $i . '@example.org' );
		}

		$this->assertFalse( gwcpp_login_rate_limited( 'someuser' ) );
	}

	public function test_handoff_has_its_own_budget_too(): void {
		for ( $i = 0; $i < 40; $i++ ) {
			gwcpp_rate_limited( 'person' . $i . '@example.org' );
		}

		$this->assertFalse( gwcpp_handoff_rate_limited( 7 ) );
	}

	/* ── The password form ───────────────────────────────────────────────── */

	public function test_password_guessing_is_refused_after_five_tries(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( gwcpp_login_rate_limited( 'someuser' ) );
		}

		$this->assertTrue( gwcpp_login_rate_limited( 'someuser' ) );
	}

	public function test_one_accounts_guesses_do_not_use_up_anothers(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			gwcpp_login_rate_limited( 'someuser' );
		}

		$this->assertFalse(
			gwcpp_login_rate_limited( 'anotheruser' ),
			'The per-account window must be per account, or one target locks out the site.'
		);
	}

	public function test_the_username_is_never_stored(): void {
		gwcpp_login_rate_limited( 'jane.the.director' );

		$this->assertStringNotContainsString(
			'jane.the.director',
			(string) wp_json_encode( get_option( 'gwcpp_rate_limits' ) )
		);
	}

	public function test_username_case_does_not_buy_a_fresh_allowance(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			gwcpp_login_rate_limited( 'someuser' );
		}

		$this->assertTrue(
			gwcpp_login_rate_limited( 'SomeUser' ),
			'WordPress logins are case-insensitive, so the counter must be too.'
		);
	}

	/* ── Handoff invitations ─────────────────────────────────────────────── */

	public function test_handoff_invitations_are_capped_per_user(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertFalse( gwcpp_handoff_rate_limited( 7 ) );
		}

		$this->assertTrue( gwcpp_handoff_rate_limited( 7 ) );
	}

	public function test_a_different_user_gets_their_own_handoff_allowance(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			gwcpp_handoff_rate_limited( 7 );
		}

		$this->assertFalse( gwcpp_handoff_rate_limited( 8 ) );
	}

	/* ── Session length ──────────────────────────────────────────────────── */

	public function test_the_session_length_is_clamped_at_both_ends(): void {
		update_option( 'gwcpp_settings', array( 'session_hours' => 0 ) );
		gwcpp_settings_cache( null, true );
		$this->assertSame(
			HOUR_IN_SECONDS,
			gwcpp_session_seconds(),
			'Zero would expire the cookie as it was set, locking everybody out with no error.'
		);

		update_option( 'gwcpp_settings', array( 'session_hours' => -5 ) );
		gwcpp_settings_cache( null, true );
		$this->assertSame( HOUR_IN_SECONDS, gwcpp_session_seconds() );

		update_option( 'gwcpp_settings', array( 'session_hours' => 99999 ) );
		gwcpp_settings_cache( null, true );
		$this->assertSame( 720 * HOUR_IN_SECONDS, gwcpp_session_seconds() );
	}

	public function test_the_default_session_is_short(): void {
		$this->assertSame( 3 * HOUR_IN_SECONDS, gwcpp_session_seconds() );
	}

	/* ── Type settings ───────────────────────────────────────────────────── */

	public function test_an_unconfigured_post_type_gets_the_conservative_defaults(): void {
		$this->assertTrue( gwcpp_type_setting( 'anything', 'require_approval' ) );
		$this->assertFalse( gwcpp_type_setting( 'anything', 'allow_create' ) );
		$this->assertFalse( gwcpp_type_setting( 'anything', 'author_grant' ) );
	}

	public function test_a_partially_configured_post_type_still_gets_the_defaults(): void {
		update_option(
			'gwcpp_settings',
			array( 'types' => array( 'clinic' => array( 'allow_create' => true ) ) )
		);
		gwcpp_settings_cache( null, true );

		$this->assertTrue( gwcpp_type_setting( 'clinic', 'allow_create' ) );
		$this->assertTrue(
			gwcpp_type_setting( 'clinic', 'require_approval' ),
			'A flag added after this row was written must read as its default, not as null.'
		);
	}
}
