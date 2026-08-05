<?php
/**
 * Blocked words: matching, and the rule that keeps existing entries editable.
 *
 * @package PostPortal
 */

use PHPUnit\Framework\TestCase;

final class BlockedWordsTest extends TestCase {

	private const POST = 80;

	protected function setUp(): void {
		gwcpp_test_reset();
		$GLOBALS['gwcpp_test']['types'][] = 'clinic';
		gwcpp_test_post( self::POST, 'clinic', 'publish', 0, 'A Clinic' );

		update_option(
			'gwcpp_settings',
			array(
				'post_types'    => array( 'clinic' ),
				'blocked_words' => "scam\nmiracle cure\nfree money",
			)
		);
		gwcpp_settings_cache( null, true );

		gwcpp_save_schema(
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
								'key'   => 'blurb',
								'type'  => 'textarea',
								'label' => 'About',
							),
						),
						'order'   => array( '__title', 'blurb' ),
						'retired' => array(),
					),
				),
			)
		);
	}

	/* ── The list ────────────────────────────────────────────────────────── */

	public function test_the_list_is_empty_by_default(): void {
		update_option( 'gwcpp_settings', array() );
		gwcpp_settings_cache( null, true );

		$this->assertSame( array(), gwcpp_blocked_words() );
	}

	public function test_the_list_parses_lines_and_commas(): void {
		$this->assertSame(
			array( 'scam', 'miracle cure', 'free money' ),
			gwcpp_blocked_words()
		);
	}

	public function test_short_entries_are_dropped(): void {
		update_option(
			'gwcpp_settings',
			array( 'blocked_words' => "hi\nno\nscam" )
		);
		gwcpp_settings_cache( null, true );

		$this->assertSame(
			array( 'scam' ),
			gwcpp_blocked_words(),
			'A two-letter entry matches inside far more than anybody intends.'
		);
	}

	/* ── Matching ────────────────────────────────────────────────────────── */

	public function test_a_blocked_word_is_found(): void {
		$this->assertSame( 'scam', gwcpp_blocked_word_in( 'this is a scam' ) );
	}

	public function test_matching_ignores_case(): void {
		$this->assertSame( 'scam', gwcpp_blocked_word_in( 'This Is A SCAM' ) );
	}

	public function test_obvious_inflections_are_caught(): void {
		foreach ( array( 'scams', 'scammer', 'scammers', 'scamming', 'scammed' ) as $variant ) {
			$this->assertNotSame( '', gwcpp_blocked_word_in( 'beware of ' . $variant ), $variant );
		}
	}

	public function test_a_word_that_merely_contains_one_is_not_caught(): void {
		/* The failure people actually notice. An unanchored str_contains blocks
		 * "scampi" on a restaurant listing, and nobody can work out why. */
		$this->assertSame( '', gwcpp_blocked_word_in( 'we serve scampi on Fridays' ) );
		$this->assertSame( '', gwcpp_blocked_word_in( 'Scandinavian food' ) );
		$this->assertSame( '', gwcpp_blocked_word_in( 'a cheeky scamp' ) );
	}

	public function test_a_word_ending_in_a_vowel_does_not_double(): void {
		update_option( 'gwcpp_settings', array( 'blocked_words' => 'casino' ) );
		gwcpp_settings_cache( null, true );

		$this->assertNotSame( '', gwcpp_blocked_word_in( 'casinos' ) );
		$this->assertSame( '', gwcpp_blocked_word_in( 'casinoo' ) );
	}

	public function test_multi_word_entries_match(): void {
		$this->assertSame( 'miracle cure', gwcpp_blocked_word_in( 'a MIRACLE CURE for everything' ) );
		$this->assertSame( '', gwcpp_blocked_word_in( 'a miracle happened, and a cure was found' ) );
	}

	public function test_empty_text_and_empty_lists_match_nothing(): void {
		$this->assertSame( '', gwcpp_blocked_word_in( '' ) );
		$this->assertSame( '', gwcpp_blocked_word_in( 'scam', array() ) );
		$this->assertSame( '', gwcpp_blocked_word_in( '   ' ) );
	}

	/* ── The rule that makes it usable ───────────────────────────────────── */

	public function test_a_changed_field_containing_a_blocked_word_is_refused(): void {
		$_POST['gwcpp_post_id'] = self::POST;

		$errors = gwcpp_validate_submission(
			'clinic',
			array( 'blurb' => 'this is a scam' )
		);

		$this->assertArrayHasKey( 'blurb', $errors );
		$this->assertStringContainsString( 'scam', $errors['blurb'] );

		unset( $_POST['gwcpp_post_id'] );
	}

	public function test_an_unchanged_field_containing_a_blocked_word_is_left_alone(): void {
		/* The case the original portal hit: an organisation whose real name
		 * matched a word somebody added to the list later. Without this rule
		 * the owner cannot save a phone-number change, and the message blames
		 * them for a field they never touched. */
		update_post_meta( self::POST, 'blurb', 'The Scam Prevention Trust' );
		$_POST['gwcpp_post_id'] = self::POST;

		$errors = gwcpp_validate_submission(
			'clinic',
			array( 'blurb' => 'The Scam Prevention Trust' )
		);

		$this->assertSame( array(), $errors );

		unset( $_POST['gwcpp_post_id'] );
	}

	public function test_editing_a_grandfathered_field_does_screen_it(): void {
		update_post_meta( self::POST, 'blurb', 'The Scam Prevention Trust' );
		$_POST['gwcpp_post_id'] = self::POST;

		$errors = gwcpp_validate_submission(
			'clinic',
			array( 'blurb' => 'The Scam Prevention Trust, now with free money' )
		);

		$this->assertArrayHasKey( 'blurb', $errors );

		unset( $_POST['gwcpp_post_id'] );
	}

	public function test_a_new_entry_screens_everything(): void {
		// No post ID, so there is nothing to compare against.
		$errors = gwcpp_validate_submission(
			'clinic',
			array(
				'__title' => 'Free Money Clinic',
				'blurb'   => 'ordinary text',
			)
		);

		$this->assertArrayHasKey( '__title', $errors );
		$this->assertArrayNotHasKey( 'blurb', $errors );
	}

	public function test_screening_does_not_overwrite_an_earlier_error(): void {
		gwcpp_put_field(
			'clinic',
			array(
				'key'      => 'blurb',
				'type'     => 'textarea',
				'label'    => 'About',
				'required' => true,
			)
		);

		$errors = gwcpp_validate_submission( 'clinic', array( 'blurb' => '' ) );

		$this->assertArrayHasKey( 'blurb', $errors );
		$this->assertStringContainsString(
			'fill in',
			$errors['blurb'],
			'The required message is the more useful one and was there first.'
		);
	}

	public function test_no_list_means_no_screening(): void {
		update_option(
			'gwcpp_settings',
			array( 'post_types' => array( 'clinic' ) )
		);
		gwcpp_settings_cache( null, true );

		$this->assertSame(
			array(),
			gwcpp_validate_submission( 'clinic', array( 'blurb' => 'this is a scam' ) )
		);
	}
}
