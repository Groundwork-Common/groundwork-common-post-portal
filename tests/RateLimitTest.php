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
		$this->assertGreaterThan( 1, count( $state['email'] ) );

		foreach ( $state['email'] as $key => $entry ) {
			$state['email'][ $key ]['start'] = time() - ( 2 * DAY_IN_SECONDS );
		}
		update_option( 'gwcpp_rate_limits', $state );

		gwcpp_rate_limited( 'someone-else@example.org' );

		$this->assertCount(
			1,
			get_option( 'gwcpp_rate_limits' )['email'],
			'Only the fresh counter should be left; the option must not grow without bound.'
		);
	}

	public function test_an_unparseable_client_address_still_gets_a_counter(): void {
		$_SERVER['REMOTE_ADDR'] = 'not an ip';

		$this->assertSame( 'unknown', gwcpp_client_ip() );
		$this->assertFalse( gwcpp_rate_limited( 'jane@example.org' ) );
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
